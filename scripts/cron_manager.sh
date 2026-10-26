#!/usr/bin/env bash
# ==============================================================================
# Almusa Server Control Panel - Master System Cron Manager
# ==============================================================================
set -e

ACTION="${1:-}"
STAGED_FILE="${2:-}"
CRON_TARGET="/etc/cron.d/almusa-cron"

case "$ACTION" in
    sync)
        echo "[INFO] Synchronizing system cron configuration to $CRON_TARGET..."
        if [ -n "$STAGED_FILE" ] && [ -f "$STAGED_FILE" ]; then
            cp "$STAGED_FILE" "$CRON_TARGET"
            chown root:root "$CRON_TARGET"
            chmod 0644 "$CRON_TARGET"
            # Ensure trailing newline (required by cron)
            sed -i -e '$a\' "$CRON_TARGET" 2>/dev/null || echo "" >> "$CRON_TARGET"
            rm -f "$STAGED_FILE"
            echo "[SUCCESS] Successfully updated $CRON_TARGET."
        else
            cat << 'EOF' > "$CRON_TARGET"
# Almusa Server Control Panel - System Cron Configuration
# (No active cron jobs)
EOF
            chown root:root "$CRON_TARGET"
            chmod 0644 "$CRON_TARGET"
            echo "" >> "$CRON_TARGET"
            echo "[INFO] Cleared active cron jobs in $CRON_TARGET."
        fi

        # Touch directory so cron notices modification immediately
        touch /etc/cron.d
        ;;

    run)
        TARGET_USER="${2:-afandi}"
        shift 2
        CMD="$*"
        echo "[INFO] Executing cron command as user '$TARGET_USER': $CMD"
        if [ "$TARGET_USER" = "root" ]; then
            bash -c "$CMD"
        else
            su -s /bin/bash - "$TARGET_USER" -c "$CMD"
        fi
        ;;

    *)
        echo "Usage: $0 {sync [staged_file]|run <user> <command>}"
        exit 1
        ;;
esac
