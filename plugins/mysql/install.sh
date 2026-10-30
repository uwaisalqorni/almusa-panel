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

case "$ACTION" in
    install)
        PHP_BIN=$(command -v php8.3 || command -v php || echo "php")
        echo "[INFO] Updating package lists..."
        apt-get update -y || true
        echo "[INFO] Installing MariaDB Server, Client and PHP-MySQL driver..."
        DEBIAN_FRONTEND=noninteractive apt-get install -y mariadb-server mariadb-client php-mysql || true

        # Always ensure PHP 8.3 MySQL extension for panel engine
        DEBIAN_FRONTEND=noninteractive apt-get install -y php8.3-mysql 2>/dev/null || true

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
            DB_PASS=$(head /dev/urandom | tr -dc A-Za-z0-9 | head -c 14)
            "$PHP_BIN" -r "
            require_once '${PANEL_ROOT}/app/bootstrap.php';
            \App\Core\Database::setSetting('mysql_root_pass', '${DB_PASS}');
            " 2>/dev/null || true
            echo "[INFO] Generated MySQL root password and saved to Panel Settings."
        fi

        SQL_CMD="mariadb"
        if ! command -v mariadb >/dev/null 2>&1; then
            SQL_CMD="mysql"
        fi

        # Configure root access for both localhost (socket) and 127.0.0.1 (TCP)
        $SQL_CMD -e "ALTER USER 'root'@'localhost' IDENTIFIED BY '${DB_PASS}'; FLUSH PRIVILEGES;" 2>/dev/null || \
        $SQL_CMD -u root -p"${DB_PASS}" -e "ALTER USER 'root'@'localhost' IDENTIFIED BY '${DB_PASS}'; FLUSH PRIVILEGES;" 2>/dev/null || true

        $SQL_CMD -e "CREATE USER IF NOT EXISTS 'root'@'127.0.0.1' IDENTIFIED BY '${DB_PASS}'; GRANT ALL PRIVILEGES ON *.* TO 'root'@'127.0.0.1' WITH GRANT OPTION; FLUSH PRIVILEGES;" 2>/dev/null || \
        $SQL_CMD -u root -p"${DB_PASS}" -e "CREATE USER IF NOT EXISTS 'root'@'127.0.0.1' IDENTIFIED BY '${DB_PASS}'; GRANT ALL PRIVILEGES ON *.* TO 'root'@'127.0.0.1' WITH GRANT OPTION; FLUSH PRIVILEGES;" 2>/dev/null || true

        $SQL_CMD -e "ALTER USER 'root'@'127.0.0.1' IDENTIFIED BY '${DB_PASS}'; FLUSH PRIVILEGES;" 2>/dev/null || \
        $SQL_CMD -u root -p"${DB_PASS}" -e "ALTER USER 'root'@'127.0.0.1' IDENTIFIED BY '${DB_PASS}'; FLUSH PRIVILEGES;" 2>/dev/null || true

        echo "[SUCCESS] MariaDB installed and active on port 3306!"
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
        PHP_BIN=$(command -v php8.3 || command -v php || echo "php")
        DB_PASS=$("$PHP_BIN" -r "
        require_once '${PANEL_ROOT}/app/bootstrap.php';
        echo \App\Core\Database::getSetting('mysql_root_pass', '');
        " 2>/dev/null || echo "")

        if [ -n "$DB_PASS" ]; then
            mysqldump -u root -p"${DB_PASS}" --single-transaction --quick "$DB_NAME" | gzip > "$FILE"
        else
            mysqldump --single-transaction --quick "$DB_NAME" | gzip > "$FILE"
        fi
        echo "[SUCCESS] Database backup saved to: $FILE"
        ;;

    *)
        echo "Usage: $0 {install|uninstall|backup} [db_name]"
        exit 1
        ;;
esac
