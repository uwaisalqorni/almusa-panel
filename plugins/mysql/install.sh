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
        echo "[INFO] Updating package lists..."
        apt-get update -y
        echo "[INFO] Installing MariaDB Server, Client and PHP-MySQL driver..."
        DEBIAN_FRONTEND=noninteractive apt-get install -y mariadb-server mariadb-client php-mysql

        systemctl enable mariadb
        systemctl restart mariadb

        # Retrieve or generate MySQL root password
        DB_PASS=$(php -r "
        require_once '${PANEL_ROOT}/app/bootstrap.php';
        echo \App\Core\Database::getSetting('mysql_root_pass', '');
        " 2>/dev/null || echo "")

        if [ -z "$DB_PASS" ]; then
            DB_PASS=$(head /dev/urandom | tr -dc A-Za-z0-9 | head -c 14)
            php -r "
            require_once '${PANEL_ROOT}/app/bootstrap.php';
            \App\Core\Database::setSetting('mysql_root_pass', '${DB_PASS}');
            " 2>/dev/null || true
            echo "[INFO] Generated MySQL root password and saved to Panel Settings."
        fi

        mariadb -e "ALTER USER 'root'@'localhost' IDENTIFIED BY '${DB_PASS}'; FLUSH PRIVILEGES;" 2>/dev/null || true

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
        DB_PASS=$(php -r "
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
