#!/usr/bin/env bash
# ==============================================================================
# Almusa Server Control Panel - Master Nginx Global Configuration Manager
# ==============================================================================

set -e

SOURCE="${BASH_SOURCE[0]}"
while [ -h "$SOURCE" ]; do
  DIR="$( cd -P "$( dirname "$SOURCE" )" >/dev/null 2>&1 && pwd )"
  SOURCE="$(readlink "$SOURCE")"
  [[ $SOURCE != /* ]] && SOURCE="$DIR/$SOURCE"
done
PANEL_ROOT="$( cd -P "$( dirname "$SOURCE" )/.." >/dev/null 2>&1 && pwd )"

ACTION="${1:-}"
SRC_FILE="${2:-}"

CONF_FILE="/etc/nginx/nginx.conf"
BACKUP_FILE="/etc/nginx/nginx.conf.almusa_bak"

case "$ACTION" in
    save)
        echo "[INFO] Validating and applying global Nginx configuration..."
        if [ -z "$SRC_FILE" ] || [ ! -f "$SRC_FILE" ]; then
            echo "[ERROR] Source staging file '$SRC_FILE' does not exist."
            exit 1
        fi

        # Create safety backup
        cp "$CONF_FILE" "$BACKUP_FILE" 2>/dev/null || true

        # Stage the new configuration
        cp "$SRC_FILE" "$CONF_FILE"

        # Validate syntax
        echo "[INFO] Testing Nginx syntax (nginx -t)..."
        if nginx -t; then
            echo "[INFO] Syntax is valid! Reloading Nginx service..."
            systemctl reload nginx 2>/dev/null || nginx -s reload 2>/dev/null || true
            rm -f "$SRC_FILE"
            echo "[SUCCESS] Global Nginx configuration successfully updated and reloaded!"
            exit 0
        else
            echo "[ERROR] Nginx syntax test failed! Rolling back to previous configuration..."
            if [ -f "$BACKUP_FILE" ]; then
                cp "$BACKUP_FILE" "$CONF_FILE"
            fi
            rm -f "$SRC_FILE"
            exit 1
        fi
        ;;

    test)
        echo "[INFO] Running Nginx configuration syntax test..."
        if [ -n "$SRC_FILE" ] && [ -f "$SRC_FILE" ]; then
            cp "$CONF_FILE" "$BACKUP_FILE" 2>/dev/null || true
            cp "$SRC_FILE" "$CONF_FILE"
            if nginx -t; then
                echo "[SUCCESS] Syntax test passed successfully."
                cp "$BACKUP_FILE" "$CONF_FILE" 2>/dev/null || true
                exit 0
            else
                echo "[ERROR] Syntax test failed."
                cp "$BACKUP_FILE" "$CONF_FILE" 2>/dev/null || true
                exit 1
            fi
        else
            nginx -t
        fi
        ;;

    service)
        CMD="${2:-reload}"
        case "$CMD" in
            reload|restart|stop|start)
                echo "[INFO] Executing systemctl $CMD nginx..."
                systemctl "$CMD" nginx
                echo "[SUCCESS] Nginx service $CMD executed."
                ;;
            *)
                echo "[ERROR] Unsupported service command: '$CMD'"
                exit 1
                ;;
        esac
        ;;

    *)
        echo "Usage: $0 {save <file>|test [file]|service <reload|restart|stop|start>}"
        exit 1
        ;;
esac
