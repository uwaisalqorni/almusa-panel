#!/usr/bin/env bash
# ==============================================================================
# Almusa Panel - MySQL / MariaDB Service Installer & Tool
# ==============================================================================

set -e

ACTION="${1:-install}"
PARAM="${2:-}"

case "$ACTION" in
    install)
        echo "[INFO] Updating package lists..."
        apt-get update -y
        echo "[INFO] Installing MariaDB Server and Client..."
        DEBIAN_FRONTEND=noninteractive apt-get install -y mariadb-server mariadb-client

        systemctl enable mariadb
        systemctl restart mariadb

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
        mysqldump --single-transaction --quick "$DB_NAME" | gzip > "$FILE"
        echo "[SUCCESS] Database backup saved to: $FILE"
        ;;

    *)
        echo "Usage: $0 {install|uninstall|backup} [db_name]"
        exit 1
        ;;
esac
