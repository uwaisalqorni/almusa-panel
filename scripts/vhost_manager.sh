#!/usr/bin/env bash
# ==============================================================================
# Almusa Panel - Virtual Host & PHP Switcher Manager
# ==============================================================================

set -e

# Resolve root directory
SOURCE="${BASH_SOURCE[0]}"
while [ -h "$SOURCE" ]; do
  DIR="$( cd -P "$( dirname "$SOURCE" )" >/dev/null 2>&1 && pwd )"
  SOURCE="$(readlink "$SOURCE")"
  [[ $SOURCE != /* ]] && SOURCE="$DIR/$SOURCE"
done
PANEL_ROOT="$( cd -P "$( dirname "$SOURCE" )/.." >/dev/null 2>&1 && pwd )"

ACTION="${1:-}"
DOMAIN="${2:-}"
DOCROOT="${3:-}"
PHP_VER="${4:-8.3}"
REWRITE="${5:-default}"

# Strict domain sanitization
if [ -z "$DOMAIN" ] || ! [[ "$DOMAIN" =~ ^[a-zA-Z0-9.-]+$ ]]; then
    echo "[ERROR] Invalid domain name: '$DOMAIN'"
    exit 1
fi

NGINX_AVAILABLE="/etc/nginx/sites-available"
NGINX_ENABLED="/etc/nginx/sites-enabled"
CONF_FILE="${NGINX_AVAILABLE}/${DOMAIN}.conf"
LINK_FILE="${NGINX_ENABLED}/${DOMAIN}.conf"

case "$ACTION" in
    create)
        echo "[INFO] Creating Virtual Host for domain: $DOMAIN (PHP $PHP_VER, Rewrite: $REWRITE)..."

        # Default docroot if empty
        if [ -z "$DOCROOT" ]; then
            DOCROOT="/www/wwwroot/${DOMAIN}"
        fi

        mkdir -p "$DOCROOT"
        mkdir -p "$NGINX_AVAILABLE" "$NGINX_ENABLED"

        # Default index.php if none exists
        if [ ! -f "${DOCROOT}/index.php" ] && [ ! -f "${DOCROOT}/index.html" ]; then
            cat << 'EOF' > "${DOCROOT}/index.php"
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Website Berhasil Dibuat - Almusa Panel</title>
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; background: #0b0f19; color: #f8fafc; display: flex; align-items: center; justify-content: center; min-height: 100vh; margin: 0; }
        .card { background: #161f30; border: 1px solid rgba(255,255,255,0.1); border-radius: 16px; padding: 40px; text-align: center; max-width: 500px; box-shadow: 0 20px 50px rgba(0,0,0,0.5); }
        h1 { color: #38bdf8; margin-top: 0; font-size: 24px; }
        p { color: #94a3b8; font-size: 14px; line-height: 1.6; }
        .badge { display: inline-block; background: rgba(16,185,129,0.15); color: #10b981; border: 1px solid rgba(16,185,129,0.3); padding: 4px 12px; border-radius: 99px; font-weight: 600; font-size: 12px; margin-bottom: 16px; }
    </style>
</head>
<body>
    <div class="card">
        <span class="badge">Virtual Host Aktif</span>
        <h1>Website Siap Digunakan!</h1>
        <p>Virtual Host Anda telah berhasil dibuat oleh <strong>Almusa Server Control Panel</strong>.</p>
        <p>Silakan upload file proyek web Anda ke direktori document root untuk mulai online.</p>
        <hr style="border: none; border-top: 1px solid rgba(255,255,255,0.08); margin: 24px 0;">
        <div style="font-size: 12px; color: #64748b;">Powered by Almusa Control Panel • Nginx & PHP Multi-FPM</div>
    </div>
</body>
</html>
EOF
            chown -R www-data:www-data "$DOCROOT" 2>/dev/null || true
        fi

        # Determine PHP socket path
        PHP_SOCK="unix:/run/php/php${PHP_VER}-fpm.sock"
        REWRITE_CONF="${PANEL_ROOT}/rewrite/nginx/${REWRITE}.conf"
        if [ ! -f "$REWRITE_CONF" ]; then
            REWRITE_CONF="${PANEL_ROOT}/rewrite/nginx/default.conf"
        fi

        ACCESS_LOG="/var/log/nginx/${DOMAIN}_access.log"
        ERROR_LOG="/var/log/nginx/${DOMAIN}_error.log"

        # Generate Nginx Virtual Host config from template
        TEMPLATE="${PANEL_ROOT}/plugins/nginx/conf/vhost_template.conf"
        sed \
            -e "s|{{DOMAIN}}|${DOMAIN}|g" \
            -e "s|{{ALIASES}}||g" \
            -e "s|{{DOCROOT}}|${DOCROOT}|g" \
            -e "s|{{PHP_SOCK}}|${PHP_SOCK}|g" \
            -e "s|{{REWRITE_CONF}}|${REWRITE_CONF}|g" \
            -e "s|{{ACCESS_LOG}}|${ACCESS_LOG}|g" \
            -e "s|{{ERROR_LOG}}|${ERROR_LOG}|g" \
            "$TEMPLATE" > "$CONF_FILE"

        ln -sf "$CONF_FILE" "$LINK_FILE"

        if command -v nginx >/dev/null 2>&1; then
            echo "[INFO] Testing Nginx configuration..."
            nginx -t
            echo "[INFO] Reloading Nginx..."
            systemctl reload nginx 2>/dev/null || nginx -s reload 2>/dev/null || true
        fi

        echo "[SUCCESS] Virtual Host for $DOMAIN created successfully!"
        ;;

    switch_php)
        echo "[INFO] Switching PHP version for $DOMAIN to PHP $PHP_VER..."
        if [ ! -f "$CONF_FILE" ]; then
            echo "[ERROR] Configuration file $CONF_FILE does not exist."
            exit 1
        fi

        PHP_SOCK="unix:\/run\/php\/php${PHP_VER}-fpm.sock"
        sed -i -E "s|fastcgi_pass unix:/run/php/php[0-9.]+-fpm.sock;|fastcgi_pass ${PHP_SOCK};|g" "$CONF_FILE"

        if command -v nginx >/dev/null 2>&1; then
            echo "[INFO] Testing Nginx configuration..."
            nginx -t
            echo "[INFO] Reloading Nginx..."
            systemctl reload nginx 2>/dev/null || nginx -s reload 2>/dev/null || true
        fi

        echo "[SUCCESS] Successfully switched $DOMAIN to PHP $PHP_VER!"
        ;;

    delete)
        DELETE_FILES="${4:-0}"
        echo "[INFO] Deleting Virtual Host for $DOMAIN..."
        rm -f "$LINK_FILE" "$CONF_FILE"

        if [ "$DELETE_FILES" = "1" ] && [ -n "$DOCROOT" ] && [ -d "$DOCROOT" ]; then
            case "$DOCROOT" in
                /|/root|/home|/www|/www/wwwroot|/etc|/var|/usr|/bin|/sbin)
                    echo "[WARNING] Refusing to delete protected system path: $DOCROOT"
                    ;;
                /www/wwwroot/*)
                    echo "[INFO] Removing website directory: $DOCROOT..."
                    rm -rf "$DOCROOT"
                    ;;
                *)
                    echo "[WARNING] Docroot outside /www/wwwroot, not deleted for safety: $DOCROOT"
                    ;;
            esac
        fi

        # Remove logs
        rm -f "/var/log/nginx/${DOMAIN}_access.log" "/var/log/nginx/${DOMAIN}_error.log"

        if command -v nginx >/dev/null 2>&1; then
            echo "[INFO] Testing Nginx configuration..."
            nginx -t
            echo "[INFO] Reloading Nginx..."
            systemctl reload nginx 2>/dev/null || nginx -s reload 2>/dev/null || true
        fi

        echo "[SUCCESS] Virtual Host for $DOMAIN removed."
        ;;

    *)
        echo "Usage: $0 {create|switch_php|delete} <domain> [docroot] [php_ver] [rewrite]"
        exit 1
        ;;
esac
