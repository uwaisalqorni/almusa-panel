#!/usr/bin/env bash
# ==============================================================================
# Almusa Panel - MySQL / MariaDB Service Installer & Tool
# ==============================================================================

set -e

SOURCE="${BASH_SOURCE[0]}"
while [ -h "$SOURCE" ]; do
  DIR="$( cd -P "$( dirname "$SOURCE" )" >/dev/null 2>&1 && pwd )"
  SOURCE="$(readlink "$SOURCE")"
  [[ $SOURCE != /* ]] && SOURCE="$DIR/$SOURCE"
done
PANEL_ROOT="$( cd -P "$( dirname "$SOURCE" )/../.." >/dev/null 2>&1 && pwd )"

ACTION="${1:-install}"
PARAM="${2:-}"

PHP_BIN=$(command -v php || find /usr/bin -maxdepth 1 \( -type f -o -type l \) -name "php[0-9]*" 2>/dev/null | sort -V | tail -n 1 || echo "php")

# Helper SQL statement builder that preserves unix_socket + password on MariaDB
build_auth_sql() {
    local target_pass="$1"
    cat << EOF
FLUSH PRIVILEGES;
ALTER USER 'root'@'localhost' IDENTIFIED VIA unix_socket OR mysql_native_password USING PASSWORD('${target_pass}');
CREATE USER IF NOT EXISTS 'root'@'127.0.0.1' IDENTIFIED BY '${target_pass}';
ALTER USER 'root'@'127.0.0.1' IDENTIFIED BY '${target_pass}';
GRANT ALL PRIVILEGES ON *.* TO 'root'@'localhost' WITH GRANT OPTION;
GRANT ALL PRIVILEGES ON *.* TO 'root'@'127.0.0.1' WITH GRANT OPTION;
FLUSH PRIVILEGES;
EOF
}

build_fallback_sql() {
    local target_pass="$1"
    cat << EOF
FLUSH PRIVILEGES;
ALTER USER 'root'@'localhost' IDENTIFIED BY '${target_pass}';
CREATE USER IF NOT EXISTS 'root'@'127.0.0.1' IDENTIFIED BY '${target_pass}';
ALTER USER 'root'@'127.0.0.1' IDENTIFIED BY '${target_pass}';
GRANT ALL PRIVILEGES ON *.* TO 'root'@'localhost' WITH GRANT OPTION;
GRANT ALL PRIVILEGES ON *.* TO 'root'@'127.0.0.1' WITH GRANT OPTION;
FLUSH PRIVILEGES;
EOF
}

apply_root_pass_sync() {
    local target_pass="$1"
    local sql_cmd="mariadb"
    if ! command -v mariadb >/dev/null 2>&1; then
        sql_cmd="mysql"
    fi

    local sql_dual
    sql_dual=$(build_auth_sql "$target_pass")
    local sql_std
    sql_std=$(build_fallback_sql "$target_pass")

    local applied=false

    # Layer 1: Try via debian.cnf
    if [ -f /etc/mysql/debian.cnf ]; then
        if $sql_cmd --defaults-file=/etc/mysql/debian.cnf -e "$sql_dual" 2>/dev/null || \
           $sql_cmd --defaults-file=/etc/mysql/debian.cnf -e "$sql_std" 2>/dev/null; then
            applied=true
        fi
    fi

    # Layer 2: Try via root unix_socket (no password)
    if [ "$applied" != true ]; then
        if $sql_cmd -u root -e "$sql_dual" 2>/dev/null || \
           $sql_cmd -u root -e "$sql_std" 2>/dev/null; then
            applied=true
        fi
    fi

    # Layer 3: Try with previous or current password stored in SQLite
    if [ "$applied" != true ]; then
        local prev_pass
        prev_pass=$("$PHP_BIN" -r "require_once '${PANEL_ROOT}/app/bootstrap.php'; echo \App\Core\Database::getSetting('mysql_root_pass_prev', '');" 2>/dev/null || echo "")
        local curr_pass
        curr_pass=$("$PHP_BIN" -r "require_once '${PANEL_ROOT}/app/bootstrap.php'; echo \App\Core\Database::getSetting('mysql_root_pass', '');" 2>/dev/null || echo "")

        for cand in "$prev_pass" "$curr_pass"; do
            if [ -n "$cand" ] && [ "$applied" != true ]; then
                if $sql_cmd -u root -p"${cand}" -e "$sql_dual" 2>/dev/null || \
                   $sql_cmd -u root -p"${cand}" -e "$sql_std" 2>/dev/null || \
                   $sql_cmd -h 127.0.0.1 -u root -p"${cand}" -e "$sql_dual" 2>/dev/null || \
                   $sql_cmd -h 127.0.0.1 -u root -p"${cand}" -e "$sql_std" 2>/dev/null; then
                    applied=true
                fi
            fi
        done
    fi

    # Layer 4: Auto Self-Healing via temporary systemd override (--skip-grant-tables --skip-networking)
    if [ "$applied" != true ] && [ "$(id -u)" -eq 0 ]; then
        echo "[INFO] Initiating automatic self-healing MySQL root credential sync..."
        local daemon_bin="/usr/sbin/mariadbd"
        [ ! -x "$daemon_bin" ] && daemon_bin=$(command -v mariadbd || command -v mysqld || echo "/usr/sbin/mysqld")

        systemctl stop mariadb mysql 2>/dev/null || true
        mkdir -p /etc/systemd/system/mariadb.service.d
        cat << EOF > /etc/systemd/system/mariadb.service.d/almusa-reset.conf
[Service]
ExecStart=
ExecStart=${daemon_bin} --skip-grant-tables --skip-networking
EOF
        systemctl daemon-reload
        if systemctl start mariadb 2>/dev/null || systemctl start mysql 2>/dev/null; then
            sleep 2
            if $sql_cmd -u root -e "$sql_dual" 2>/dev/null || \
               $sql_cmd -u root -e "$sql_std" 2>/dev/null; then
                applied=true
            fi
        fi
        rm -f /etc/systemd/system/mariadb.service.d/almusa-reset.conf
        rmdir /etc/systemd/system/mariadb.service.d 2>/dev/null || true
        systemctl daemon-reload
        systemctl restart mariadb 2>/dev/null || systemctl restart mysql 2>/dev/null || true
    fi

    # Save synchronized password to Panel Settings
    "$PHP_BIN" -r "
    require_once '${PANEL_ROOT}/app/bootstrap.php';
    \App\Core\Database::setSetting('mysql_root_pass', '${target_pass}');
    \App\Core\Database::setSetting('mysql_root_pass_prev', '${target_pass}');
    " 2>/dev/null || true

    if [ "$applied" = true ]; then
        return 0
    fi
    return 1
}

case "$ACTION" in
    install)
        echo "[INFO] Updating package lists..."
        apt-get update -y || true
        echo "[INFO] Installing MariaDB Server, Client and PHP-MySQL driver..."
        DEBIAN_FRONTEND=noninteractive apt-get install -y mariadb-server mariadb-client php-mysql || true

        # Detect active panel PHP version and ensure php-mysql extension is installed for it
        PANEL_PHP_VER=$("$PHP_BIN" -r "echo PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION;" 2>/dev/null || echo "")
        if [ -n "$PANEL_PHP_VER" ]; then
            echo "[INFO] Ensuring PHP-MySQL extension for active Panel PHP (${PANEL_PHP_VER})..."
            DEBIAN_FRONTEND=noninteractive apt-get install -y "php${PANEL_PHP_VER}-mysql" 2>/dev/null || true
        fi

        # Also install php-mysql for all installed PHP versions on the server
        for v in 7.4 8.0 8.1 8.2 8.3 8.4 8.5; do
            if [ -x "/usr/sbin/php-fpm${v}" ] || [ -d "/etc/php/${v}/fpm" ]; then
                DEBIAN_FRONTEND=noninteractive apt-get install -y "php${v}-mysql" 2>/dev/null || true
                systemctl reload "php${v}-fpm" 2>/dev/null || true
            fi
        done

        # Reload/restart panel web service so newly installed pdo_mysql is loaded immediately
        systemctl restart mypanel-web.service 2>/dev/null || true

        systemctl enable mariadb 2>/dev/null || systemctl enable mysql 2>/dev/null || true
        systemctl restart mariadb 2>/dev/null || systemctl restart mysql 2>/dev/null || true

        # Retrieve or generate MySQL root password
        DB_PASS=$("$PHP_BIN" -r "
        require_once '${PANEL_ROOT}/app/bootstrap.php';
        echo \App\Core\Database::getSetting('mysql_root_pass', '');
        " 2>/dev/null || echo "")

        if [ -z "$DB_PASS" ]; then
            DB_PASS=$(LC_ALL=C tr -dc 'A-Za-z0-9' < /dev/urandom | head -c 14 || openssl rand -hex 7)
            echo "[INFO] Generated MySQL root password and saved to Panel Settings."
        fi

        if apply_root_pass_sync "$DB_PASS"; then
            echo "[SUCCESS] MariaDB installed, dual-auth configured, and active on port 3306!"
        else
            echo "[WARNING] MariaDB installed, please check root credentials in Panel Settings."
        fi
        ;;

    uninstall)
        echo "[WARNING] Stopping MariaDB service..."
        systemctl stop mariadb 2>/dev/null || systemctl stop mysql 2>/dev/null || true

        echo "[INFO] Purging MariaDB packages..."
        DEBIAN_FRONTEND=noninteractive apt-get purge -y mariadb-server mariadb-client mariadb-common mysql-common || true
        apt-get autoremove -y || true
        echo "[SUCCESS] MariaDB uninstalled."
        ;;

    backup)
        DB_NAME="$PARAM"
        if [ -z "$DB_NAME" ]; then
            echo "[ERROR] Database name required for backup."
            exit 1
        fi

        BACKUP_DIR="/www/backup"
        mkdir -p "$BACKUP_DIR"
        TIMESTAMP=$(date +%Y%m%d_%H%M%S)
        FILE="${BACKUP_DIR}/db_${DB_NAME}_${TIMESTAMP}.sql.gz"

        echo "[INFO] Dumping database: $DB_NAME..."
        DB_PASS=$("$PHP_BIN" -r "
        require_once '${PANEL_ROOT}/app/bootstrap.php';
        echo \App\Core\Database::getSetting('mysql_root_pass', '');
        " 2>/dev/null || echo "")

        if mysqldump --single-transaction --quick "$DB_NAME" 2>/dev/null | gzip > "$FILE" && [ -s "$FILE" ]; then
            echo "[SUCCESS] Database backup saved to: $FILE"
        elif [ -n "$DB_PASS" ]; then
            mysqldump -u root -p"${DB_PASS}" --single-transaction --quick "$DB_NAME" | gzip > "$FILE"
            echo "[SUCCESS] Database backup saved to: $FILE"
        else
            echo "[ERROR] Failed to dump database $DB_NAME."
            exit 1
        fi
        ;;

    set_root_pass)
        NEW_PASS="$PARAM"
        if [ -z "$NEW_PASS" ]; then
            echo "[ERROR] Password cannot be empty."
            exit 1
        fi

        echo "[INFO] Updating MySQL root password to new credential..."
        if apply_root_pass_sync "$NEW_PASS"; then
            echo "[SUCCESS] MySQL root password successfully synchronized!"
        else
            echo "[ERROR] Failed to synchronize MySQL root password."
            exit 1
        fi
        ;;

    *)
        echo "Usage: $0 {install|uninstall|backup|set_root_pass} [param]"
        exit 1
        ;;
esac
