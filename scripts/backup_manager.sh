#!/usr/bin/env bash
# ==============================================================================
# Almusa Server Control Panel - Automated Backup & Snapshot Engine
# ==============================================================================
set -e

ACTION="${1:-}"
BACKUP_DIR="/www/backup"
TIMESTAMP=$(date +"%Y%m%d_%H%M%S")

# Ensure backup directory exists with correct permissions
mkdir -p "$BACKUP_DIR"
chown afandi:afandi "$BACKUP_DIR" 2>/dev/null || true
chmod 0755 "$BACKUP_DIR"

# Resolve MySQL root password
MYSQL_ROOT_PASS="${MYSQL_PWD:-}"
if [ -z "$MYSQL_ROOT_PASS" ]; then
    PANEL_ROOT="$( cd -P "$( dirname "${BASH_SOURCE[0]}" )/.." >/dev/null 2>&1 && pwd )"
    if [ -f "$PANEL_ROOT/data/panel.db" ]; then
        MYSQL_ROOT_PASS=$(sqlite3 "$PANEL_ROOT/data/panel.db" "SELECT key_value FROM settings WHERE key_name = 'mysql_root_pass' LIMIT 1;" 2>/dev/null || echo "")
    fi
fi

case "$ACTION" in
    backup_db)
        DB_NAME="${2:-}"
        OUTPUT_FILE="${3:-}"
        if [ -z "$DB_NAME" ]; then
            echo "[ERROR] Database name is required."
            exit 1
        fi

        if [ -z "$OUTPUT_FILE" ]; then
            OUTPUT_FILE="${BACKUP_DIR}/db_${DB_NAME}_${TIMESTAMP}.sql.gz"
        fi

        echo "[INFO] Starting database snapshot: ${DB_NAME} -> ${OUTPUT_FILE}..."
        export MYSQL_PWD="$MYSQL_ROOT_PASS"
        mysqldump -u root \
            --single-transaction \
            --quick \
            --routines \
            --triggers \
            --default-character-set=utf8mb4 \
            "$DB_NAME" | gzip -c > "$OUTPUT_FILE"

        chown afandi:afandi "$OUTPUT_FILE" 2>/dev/null || true
        chmod 0644 "$OUTPUT_FILE"
        SIZE=$(du -h "$OUTPUT_FILE" | cut -f1)
        echo "[SUCCESS] Database snapshot created successfully! Size: ${SIZE} | File: ${OUTPUT_FILE}"
        ;;

    backup_all_db)
        OUTPUT_FILE="${2:-}"
        if [ -z "$OUTPUT_FILE" ]; then
            OUTPUT_FILE="${BACKUP_DIR}/db_all_databases_${TIMESTAMP}.sql.gz"
        fi

        echo "[INFO] Starting full database snapshot (all databases) -> ${OUTPUT_FILE}..."
        export MYSQL_PWD="$MYSQL_ROOT_PASS"
        mysqldump -u root \
            --all-databases \
            --single-transaction \
            --quick \
            --routines \
            --triggers \
            --default-character-set=utf8mb4 | gzip -c > "$OUTPUT_FILE"

        chown afandi:afandi "$OUTPUT_FILE" 2>/dev/null || true
        chmod 0644 "$OUTPUT_FILE"
        SIZE=$(du -h "$OUTPUT_FILE" | cut -f1)
        echo "[SUCCESS] All databases snapshot created successfully! Size: ${SIZE} | File: ${OUTPUT_FILE}"
        ;;

    backup_site)
        DOMAIN="${2:-}"
        DOCROOT="${3:-}"
        OUTPUT_FILE="${4:-}"

        if [ -z "$DOMAIN" ] || [ -z "$DOCROOT" ]; then
            echo "[ERROR] Domain and docroot are required."
            exit 1
        fi

        if [ ! -d "$DOCROOT" ]; then
            echo "[ERROR] Docroot directory does not exist: $DOCROOT"
            exit 1
        fi

        if [ -z "$OUTPUT_FILE" ]; then
            CLEAN_DOMAIN=$(echo "$DOMAIN" | tr '.' '_')
            OUTPUT_FILE="${BACKUP_DIR}/site_${CLEAN_DOMAIN}_${TIMESTAMP}.tar.gz"
        fi

        # If docroot points to public/ inside a project, archive the project root
        TARGET_DIR="$DOCROOT"
        PARENT_DIR=$(dirname "$DOCROOT")
        FOLDER_NAME=$(basename "$DOCROOT")

        if [ "$FOLDER_NAME" = "public" ] && [ -d "$PARENT_DIR" ]; then
            TARGET_DIR="$PARENT_DIR"
            PARENT_DIR=$(dirname "$TARGET_DIR")
            FOLDER_NAME=$(basename "$TARGET_DIR")
        fi

        echo "[INFO] Archiving website files: ${TARGET_DIR} -> ${OUTPUT_FILE}..."
        tar -czf "$OUTPUT_FILE" \
            --exclude=".git" \
            --exclude="node_modules" \
            -C "$PARENT_DIR" "$FOLDER_NAME"

        chown afandi:afandi "$OUTPUT_FILE" 2>/dev/null || true
        chmod 0644 "$OUTPUT_FILE"
        SIZE=$(du -h "$OUTPUT_FILE" | cut -f1)
        echo "[SUCCESS] Website archive created successfully! Size: ${SIZE} | File: ${OUTPUT_FILE}"
        ;;

    backup_all_sites)
        OUTPUT_FILE="${2:-}"
        if [ -z "$OUTPUT_FILE" ]; then
            OUTPUT_FILE="${BACKUP_DIR}/site_all_websites_${TIMESTAMP}.tar.gz"
        fi

        echo "[INFO] Archiving all websites in /www/wwwroot -> ${OUTPUT_FILE}..."
        tar -czf "$OUTPUT_FILE" \
            --exclude=".git" \
            --exclude="node_modules" \
            -C /www wwwroot

        chown afandi:afandi "$OUTPUT_FILE" 2>/dev/null || true
        chmod 0644 "$OUTPUT_FILE"
        SIZE=$(du -h "$OUTPUT_FILE" | cut -f1)
        echo "[SUCCESS] All websites archive created successfully! Size: ${SIZE} | File: ${OUTPUT_FILE}"
        ;;

    prune)
        RETENTION_DAYS="${2:-7}"
        echo "[INFO] Pruning backup files older than ${RETENTION_DAYS} days in ${BACKUP_DIR}..."
        DELETED_COUNT=$(find "$BACKUP_DIR" -type f \( -name "*.sql.gz" -o -name "*.tar.gz" \) -mtime +"$RETENTION_DAYS" | wc -l)
        find "$BACKUP_DIR" -type f \( -name "*.sql.gz" -o -name "*.tar.gz" \) -mtime +"$RETENTION_DAYS" -delete
        echo "[SUCCESS] Pruning complete. Deleted ${DELETED_COUNT} obsolete backup file(s)."
        ;;

    restore_db)
        set -o pipefail
        BACKUP_FILE="${2:-}"
        TARGET_DB="${3:-}"

        if [ -z "$BACKUP_FILE" ] || [ ! -f "$BACKUP_FILE" ]; then
            echo "[ERROR] Backup file not found: $BACKUP_FILE"
            exit 1
        fi

        export MYSQL_PWD="$MYSQL_ROOT_PASS"
        FILENAME=$(basename "$BACKUP_FILE")

        if [ "$TARGET_DB" = "all_databases" ] || [ "$TARGET_DB" = "all" ] || [[ "$FILENAME" == db_all_databases_* ]]; then
            echo "[INFO] Restoring all databases snapshot from '${BACKUP_FILE}'..."
            if gzip -dc "$BACKUP_FILE" | mysql -u root; then
                echo "[SUCCESS] All databases restored successfully from '${BACKUP_FILE}'!"
            else
                echo "[ERROR] Database restore failed with code $?"
                exit 1
            fi
        else
            if [ -z "$TARGET_DB" ]; then
                echo "[ERROR] Target database is required."
                exit 1
            fi
            echo "[INFO] Restoring database '${TARGET_DB}' from '${BACKUP_FILE}'..."
            mysql -u root -e "CREATE DATABASE IF NOT EXISTS \`$TARGET_DB\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
            if gzip -dc "$BACKUP_FILE" | mysql -u root "$TARGET_DB"; then
                echo "[SUCCESS] Database '${TARGET_DB}' restored successfully from '${BACKUP_FILE}'!"
            else
                echo "[ERROR] Database restore failed with code $?"
                exit 1
            fi
        fi
        ;;

    restore_site)
        set -o pipefail
        BACKUP_FILE="${2:-}"
        TARGET_DIR="${3:-}"

        if [ -z "$BACKUP_FILE" ] || [ ! -f "$BACKUP_FILE" ]; then
            echo "[ERROR] Backup file not found: $BACKUP_FILE"
            exit 1
        fi

        if [ -z "$TARGET_DIR" ]; then
            echo "[ERROR] Target directory is required."
            exit 1
        fi

        echo "[INFO] Restoring website files from '${BACKUP_FILE}' to '${TARGET_DIR}'..."
        mkdir -p "$TARGET_DIR"

        FIRST_ENTRY=$(tar -tzf "$BACKUP_FILE" 2>/dev/null | head -n 1 || echo "")
        FILENAME=$(basename "$BACKUP_FILE")

        if [ "$TARGET_DIR" = "/www/wwwroot" ] || [[ "$FILENAME" == site_all_websites_* ]]; then
            tar -xzf "$BACKUP_FILE" -C /www/wwwroot --strip-components=1 2>/dev/null || tar -xzf "$BACKUP_FILE" -C /www
        elif echo "$FIRST_ENTRY" | grep -q "/$"; then
            tar -xzf "$BACKUP_FILE" -C "$TARGET_DIR" --strip-components=1
        else
            tar -xzf "$BACKUP_FILE" -C "$TARGET_DIR"
        fi

        chown -R www-data:www-data "$TARGET_DIR" 2>/dev/null || true
        chmod -R 0755 "$TARGET_DIR"
        [ -d "$TARGET_DIR/storage" ] && chmod -R 0775 "$TARGET_DIR/storage" 2>/dev/null || true
        [ -d "$TARGET_DIR/bootstrap/cache" ] && chmod -R 0775 "$TARGET_DIR/bootstrap/cache" 2>/dev/null || true
        [ -d "$TARGET_DIR/database" ] && chmod -R 0775 "$TARGET_DIR/database" 2>/dev/null || true
        [ -d "$TARGET_DIR/var/cache" ] && chmod -R 0775 "$TARGET_DIR/var/cache" 2>/dev/null || true
        [ -d "$TARGET_DIR/var/log" ] && chmod -R 0775 "$TARGET_DIR/var/log" 2>/dev/null || true
        echo "[SUCCESS] Website files restored successfully into '${TARGET_DIR}'!"
        ;;

    *)
        echo "Usage: $0 {backup_db <name>|backup_all_db|backup_site <domain> <docroot>|backup_all_sites|prune <days>|restore_db <file> <db>|restore_site <file> <dir>}"
        exit 1
        ;;
esac
