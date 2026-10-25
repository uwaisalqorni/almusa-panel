#!/usr/bin/env bash
# ==============================================================================
# Almusa Panel - Nginx Service Installer
# ==============================================================================

set -e

ACTION="${1:-install}"

case "$ACTION" in
    install)
        echo "[INFO] Updating package lists..."
        apt-get update -y
        echo "[INFO] Installing Nginx..."
        DEBIAN_FRONTEND=noninteractive apt-get install -y nginx

        mkdir -p /etc/nginx/sites-available /etc/nginx/sites-enabled /www/wwwroot

        # Ensure sites-enabled is included in nginx.conf
        if ! grep -q "sites-enabled" /etc/nginx/nginx.conf; then
            sed -i '/http {/a \    include /etc/nginx/sites-enabled/*;' /etc/nginx/nginx.conf
        fi

        # Remove default apache/nginx placeholder site if needed
        if [ -f /etc/nginx/sites-enabled/default ]; then
            rm -f /etc/nginx/sites-enabled/default
        fi

        systemctl enable nginx
        systemctl restart nginx
        echo "[SUCCESS] Nginx installed and running successfully!"
        ;;

    uninstall)
        echo "[WARNING] Stopping and purging Nginx..."
        systemctl stop nginx || true
        DEBIAN_FRONTEND=noninteractive apt-get purge -y nginx nginx-common nginx-core || true
        apt-get autoremove -y || true
        echo "[SUCCESS] Nginx uninstalled."
        ;;

    reload)
        echo "[INFO] Testing Nginx configuration..."
        nginx -t
        echo "[INFO] Reloading Nginx..."
        systemctl reload nginx
        echo "[SUCCESS] Nginx reloaded."
        ;;

    *)
        echo "Usage: $0 {install|uninstall|reload}"
        exit 1
        ;;
esac
