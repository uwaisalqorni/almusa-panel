#!/usr/bin/env bash
# ==============================================================================
# Almusa Panel - Cloudflare 1-Click Quick Tunnel Runner
# ==============================================================================

set -e

SOURCE="${BASH_SOURCE[0]}"
while [ -h "$SOURCE" ]; do
  DIR="$( cd -P "$( dirname "$SOURCE" )" >/dev/null 2>&1 && pwd )"
  SOURCE="$(readlink "$SOURCE")"
  [[ $SOURCE != /* ]] && SOURCE="$DIR/$SOURCE"
done
PANEL_ROOT="$( cd -P "$( dirname "$SOURCE" )/../.." >/dev/null 2>&1 && pwd )"

ACTION="${1:-}"
SITE_ID="${2:-}"
PARAM="${3:-}"

if [ -z "$ACTION" ] || [ -z "$SITE_ID" ]; then
    echo "Usage: $0 {start|stop} <site_id> [domain|pid]"
    exit 1
fi

LOG_FILE="/tmp/cf_quick_${SITE_ID}.log"

case "$ACTION" in
    start)
        DOMAIN="$PARAM"
        echo "[INFO] Starting 1-Click Quick Tunnel for site #${SITE_ID} (${DOMAIN})..."

        # Ensure cloudflared is installed
        if ! command -v cloudflared >/dev/null 2>&1; then
            echo "[INFO] cloudflared not found in PATH, installing..."
            bash "${PANEL_ROOT}/plugins/cloudflared/install.sh" install
        fi

        # Stop existing tunnel for this site if running
        pkill -f "cloudflared tunnel.*--http-host-header=${DOMAIN}" || true
        rm -f "$LOG_FILE"

        # Spawn tunnel background process
        nohup cloudflared tunnel --url http://127.0.0.1:8088 --http-host-header="${DOMAIN}" > "$LOG_FILE" 2>&1 &
        TUNNEL_PID=$!
        echo "[INFO] Tunnel daemon spawned with PID: $TUNNEL_PID. Awaiting URL generation..."

        # Wait up to 10 seconds for URL
        TUNNEL_URL=""
        for i in {1..20}; do
            sleep 0.5
            if [ -f "$LOG_FILE" ]; then
                EXTRACTED=$(grep -o 'https://[a-zA-Z0-9-]*\.trycloudflare\.com' "$LOG_FILE" | head -n 1 || true)
                if [ -n "$EXTRACTED" ]; then
                    TUNNEL_URL="$EXTRACTED"
                    break
                fi
            fi
        done

        if [ -z "$TUNNEL_URL" ]; then
            echo "[ERROR] Failed to extract trycloudflare.com URL within timeout. Check $LOG_FILE"
            kill "$TUNNEL_PID" 2>/dev/null || true
            exit 1
        fi

        echo "[SUCCESS] Quick Tunnel generated: $TUNNEL_URL"

        # Update SQLite database via PHP
        php -r "
        require_once '${PANEL_ROOT}/app/bootstrap.php';
        \$pdo = \App\Core\Database::getInstance();
        
        // Remove old tunnels for this site
        \$del = \$pdo->prepare('DELETE FROM tunnels WHERE site_id = :sid');
        \$del->execute([':sid' => ${SITE_ID}]);

        // Insert new tunnel record
        \$ins = \$pdo->prepare('
            INSERT INTO tunnels (site_id, tunnel_type, public_hostname, local_service, pid, status)
            VALUES (:sid, \"quick\", :url, \"http://127.0.0.1:8088\", :pid, \"running\")
        ');
        \$ins->execute([
            ':sid' => ${SITE_ID},
            ':url' => '${TUNNEL_URL}',
            ':pid' => ${TUNNEL_PID},
        ]);

        // Update site record
        \$up = \$pdo->prepare('UPDATE sites SET tunnel_mode = \"quick\", tunnel_url = :url WHERE id = :sid');
        \$up->execute([':url' => '${TUNNEL_URL}', ':sid' => ${SITE_ID}]);
        "

        echo "[SUCCESS] Quick Tunnel is live at: $TUNNEL_URL (PID: $TUNNEL_PID)"
        ;;

    stop)
        PID="$PARAM"
        echo "[INFO] Stopping Quick Tunnel for site #${SITE_ID} (PID: ${PID})..."

        if [ -n "$PID" ] && [ "$PID" -gt 0 ]; then
            kill -15 "$PID" 2>/dev/null || kill -9 "$PID" 2>/dev/null || true
        fi
        pkill -f "cloudflared tunnel.*cf_quick_${SITE_ID}.log" || true
        rm -f "$LOG_FILE"

        # Clean database record
        php -r "
        require_once '${PANEL_ROOT}/app/bootstrap.php';
        \$pdo = \App\Core\Database::getInstance();
        \$del = \$pdo->prepare('DELETE FROM tunnels WHERE site_id = :sid');
        \$del->execute([':sid' => ${SITE_ID}]);

        \$up = \$pdo->prepare('UPDATE sites SET tunnel_mode = \"none\", tunnel_url = \"\" WHERE id = :sid');
        \$up->execute([':sid' => ${SITE_ID}]);
        "

        echo "[SUCCESS] Quick Tunnel stopped and cleaned up."
        ;;

    *)
        echo "Usage: $0 {start|stop} <site_id> [domain|pid]"
        exit 1
        ;;
esac
