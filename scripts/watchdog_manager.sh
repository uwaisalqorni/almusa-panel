#!/usr/bin/env bash
# ==============================================================================
# Almusa Server Control Panel - Health Watchdog & Timer Manager
# ==============================================================================
set -e

ACTION="${1:-run}"
PANEL_ROOT="$( cd -P "$( dirname "${BASH_SOURCE[0]}" )/.." >/dev/null 2>&1 && pwd )"
SERVICE_FILE="/etc/systemd/system/almusa-watchdog.service"
TIMER_FILE="/etc/systemd/system/almusa-watchdog.timer"

case "$ACTION" in
    install)
        echo "[INFO] Installing Almusa Watchdog systemd service & timer..."

        cat << EOF > "$SERVICE_FILE"
[Unit]
Description=Almusa Panel Service Watchdog & Auto-Heal Daemon
After=network.target

[Service]
Type=oneshot
User=root
ExecStart=/usr/bin/php ${PANEL_ROOT}/scripts/watchdog.php
StandardOutput=append:${PANEL_ROOT}/data/watchdog.log
StandardError=append:${PANEL_ROOT}/data/watchdog.log
EOF

        cat << EOF > "$TIMER_FILE"
[Unit]
Description=Run Almusa Panel Health Watchdog every 1 minute
PartOf=almusa-watchdog.service

[Timer]
OnBootSec=30s
OnUnitActiveSec=1min
AccuracySec=5s

[Install]
WantedBy=timers.target
EOF

        chmod 0644 "$SERVICE_FILE" "$TIMER_FILE"
        systemctl daemon-reload
        systemctl enable --now almusa-watchdog.timer
        systemctl start almusa-watchdog.service || true
        echo "[SUCCESS] Almusa Watchdog timer installed and activated successfully!"
        systemctl status almusa-watchdog.timer --no-pager
        ;;

    uninstall)
        echo "[INFO] Disabling and removing Almusa Watchdog timer..."
        systemctl stop almusa-watchdog.timer 2>/dev/null || true
        systemctl disable almusa-watchdog.timer 2>/dev/null || true
        rm -f "$SERVICE_FILE" "$TIMER_FILE"
        systemctl daemon-reload
        echo "[SUCCESS] Almusa Watchdog timer removed."
        ;;

    status)
        echo "=== Almusa Watchdog Timer Status ==="
        systemctl status almusa-watchdog.timer --no-pager 2>&1 || true
        echo ""
        echo "=== Recent Watchdog Service Execution ==="
        systemctl status almusa-watchdog.service --no-pager 2>&1 || true
        ;;

    run)
        shift 1 2>/dev/null || true
        /usr/bin/php "${PANEL_ROOT}/scripts/watchdog.php" "$@"
        ;;

    *)
        echo "Usage: $0 {install|uninstall|status|run}"
        exit 1
        ;;
esac
