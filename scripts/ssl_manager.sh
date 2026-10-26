#!/usr/bin/env bash
# ==============================================================================
# Almusa Server Control Panel - Let's Encrypt / Certbot SSL Manager
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
DOMAIN="${2:-}"
DOCROOT="${3:-}"
EMAIL="${4:-}"
FORCE_HTTPS="${5:-1}"

# Strict domain sanitization
if [ -z "$DOMAIN" ] || ! [[ "$DOMAIN" =~ ^[a-zA-Z0-9.-]+$ ]]; then
    echo "[ERROR] Invalid domain name: '$DOMAIN'"
    exit 1
fi

if [ "$ACTION" = "issue" ] && [[ "$DOMAIN" != *.* ]]; then
    echo "[ERROR] Domain '$DOMAIN' bukan nama domain publik (FQDN). Let's Encrypt membutuhkan domain yang memiliki setidaknya satu titik (contoh: domain.com)."
    exit 1
fi

NGINX_AVAILABLE="/etc/nginx/sites-available"
NGINX_ENABLED="/etc/nginx/sites-enabled"
CONF_FILE="${NGINX_AVAILABLE}/${DOMAIN}.conf"
LINK_FILE="${NGINX_ENABLED}/${DOMAIN}.conf"
DB_FILE="${PANEL_ROOT}/data/panel.db"

ensure_certbot() {
    if ! command -v certbot >/dev/null 2>&1; then
        echo "[INFO] Certbot not found in system. Installing certbot & python3-certbot-nginx via apt-get..."
        apt-get update -y
        DEBIAN_FRONTEND=noninteractive apt-get install -y certbot python3-certbot-nginx
        echo "[SUCCESS] Certbot installed successfully."
    fi
    ensure_autorenew
}

ensure_autorenew() {
    echo "[INFO] Setting up auto-renewal timer and Nginx reload hooks..."
    mkdir -p /etc/letsencrypt/renewal-hooks/deploy
    cat << 'EOF' > /etc/letsencrypt/renewal-hooks/deploy/01-almusa-reload-nginx.sh
#!/usr/bin/env bash
# Almusa Panel - Auto-reload Nginx after certbot certificate renewal
if command -v nginx >/dev/null 2>&1; then
    nginx -t && (systemctl reload nginx 2>/dev/null || nginx -s reload 2>/dev/null || true)
fi
EOF
    chmod +x /etc/letsencrypt/renewal-hooks/deploy/01-almusa-reload-nginx.sh

    if command -v systemctl >/dev/null 2>&1; then
        systemctl enable --now certbot.timer 2>/dev/null || true
    fi
}

fix_cert_permissions() {
    chmod 755 /etc/letsencrypt /etc/letsencrypt/live /etc/letsencrypt/archive 2>/dev/null || true
    chmod -R 755 "/etc/letsencrypt/live/${DOMAIN}" 2>/dev/null || true
    chmod -R 755 "/etc/letsencrypt/archive/${DOMAIN}" 2>/dev/null || true
    chmod 644 "/etc/letsencrypt/live/${DOMAIN}/privkey.pem" 2>/dev/null || true
    chmod 644 "/etc/letsencrypt/archive/${DOMAIN}/privkey"*.pem 2>/dev/null || true
}

case "$ACTION" in
    issue)
        echo "[INFO] Starting Let's Encrypt 1-Click SSL issuance for domain: $DOMAIN..."
        ensure_certbot

        # Retrieve site properties from database if not fully passed
        SITE_INFO=$(sqlite3 "$DB_FILE" "SELECT docroot, php_version, rewrite_template, aliases FROM sites WHERE domain = '${DOMAIN}' LIMIT 1;" 2>/dev/null || true)
        if [ -n "$SITE_INFO" ]; then
            IFS='|' read -r DB_DOCROOT DB_PHP DB_REWRITE DB_ALIASES <<< "$SITE_INFO"
            [ -z "$DOCROOT" ] && DOCROOT="$DB_DOCROOT"
            PHP_VER="${DB_PHP:-8.3}"
            REWRITE="${DB_REWRITE:-default}"
            ALIASES="${DB_ALIASES:-}"
        else
            PHP_VER="8.3"
            REWRITE="default"
            ALIASES=""
        fi

        if [ -z "$DOCROOT" ]; then
            DOCROOT="/www/wwwroot/${DOMAIN}"
        fi

        if [ -z "$EMAIL" ]; then
            EMAIL="admin@${DOMAIN}"
        fi

        echo "[INFO] Document root: $DOCROOT"
        echo "[INFO] Contact Email : $EMAIL"

        # Prepare webroot ACME challenge directory
        mkdir -p "${DOCROOT}/.well-known/acme-challenge"
        chown -R www-data:www-data "${DOCROOT}/.well-known" 2>/dev/null || true
        chmod -R 755 "${DOCROOT}/.well-known" 2>/dev/null || true

        # Run Certbot ACME Webroot verification
        echo "[INFO] Executing certbot certonly..."
        certbot certonly --webroot \
            -w "$DOCROOT" \
            -d "$DOMAIN" \
            --non-interactive \
            --agree-tos \
            -m "$EMAIL" \
            --keep-until-expiring

        CERT_FILE="/etc/letsencrypt/live/${DOMAIN}/fullchain.pem"
        KEY_FILE="/etc/letsencrypt/live/${DOMAIN}/privkey.pem"

        if [ ! -f "$CERT_FILE" ] || [ ! -f "$KEY_FILE" ]; then
            echo "[ERROR] Certbot finished, but SSL certificate files are missing at ${CERT_FILE}."
            exit 1
        fi

        fix_cert_permissions

        echo "[INFO] Generating SSL-enabled Nginx configuration..."
        PHP_SOCK="unix:/run/php/php${PHP_VER}-fpm.sock"
        REWRITE_CONF="${PANEL_ROOT}/rewrite/nginx/${REWRITE}.conf"
        if [ ! -f "$REWRITE_CONF" ]; then
            REWRITE_CONF="${PANEL_ROOT}/rewrite/nginx/default.conf"
        fi

        ACCESS_LOG="/var/log/nginx/${DOMAIN}_access.log"
        ERROR_LOG="/var/log/nginx/${DOMAIN}_error.log"

        TEMPLATE="${PANEL_ROOT}/plugins/nginx/conf/vhost_ssl_template.conf"
        TEST_FILE="${CONF_FILE}.ssl_test"
        BACKUP_FILE="${CONF_FILE}.pre_ssl.bak"

        cp "$CONF_FILE" "$BACKUP_FILE" 2>/dev/null || true

        sed \
            -e "s|{{DOMAIN}}|${DOMAIN}|g" \
            -e "s|{{ALIASES}}|${ALIASES}|g" \
            -e "s|{{DOCROOT}}|${DOCROOT}|g" \
            -e "s|{{PHP_SOCK}}|${PHP_SOCK}|g" \
            -e "s|{{REWRITE_CONF}}|${REWRITE_CONF}|g" \
            -e "s|{{ACCESS_LOG}}|${ACCESS_LOG}|g" \
            -e "s|{{ERROR_LOG}}|${ERROR_LOG}|g" \
            -e "s|{{SSL_CERT}}|${CERT_FILE}|g" \
            -e "s|{{SSL_KEY}}|${KEY_FILE}|g" \
            "$TEMPLATE" > "$TEST_FILE"

        ln -sf "$TEST_FILE" "$LINK_FILE"

        if command -v nginx >/dev/null 2>&1; then
            echo "[INFO] Testing Nginx configuration with SSL..."
            if nginx -t; then
                mv -f "$TEST_FILE" "$CONF_FILE"
                ln -sf "$CONF_FILE" "$LINK_FILE"
                rm -f "$BACKUP_FILE"
                echo "[INFO] Reloading Nginx service..."
                systemctl reload nginx 2>/dev/null || nginx -s reload 2>/dev/null || true
            else
                echo "[ERROR] Nginx test failed. Rolling back configuration..."
                rm -f "$TEST_FILE"
                if [ -f "$BACKUP_FILE" ]; then
                    mv -f "$BACKUP_FILE" "$CONF_FILE"
                    ln -sf "$CONF_FILE" "$LINK_FILE"
                fi
                exit 1
            fi
        fi

        # Update SQLite record
        sqlite3 "$DB_FILE" "UPDATE sites SET ssl_enabled = 1 WHERE domain = '${DOMAIN}';"

        # Setup auto-renewal timer
        ensure_autorenew

        echo "[SUCCESS] Let's Encrypt SSL has been successfully issued and activated for ${DOMAIN}!"
        ;;

    disable)
        echo "[INFO] Disabling SSL for domain: $DOMAIN..."
        SITE_INFO=$(sqlite3 "$DB_FILE" "SELECT docroot, php_version, rewrite_template, aliases FROM sites WHERE domain = '${DOMAIN}' LIMIT 1;" 2>/dev/null || true)
        if [ -n "$SITE_INFO" ]; then
            IFS='|' read -r DB_DOCROOT DB_PHP DB_REWRITE DB_ALIASES <<< "$SITE_INFO"
            DOCROOT="${DB_DOCROOT:-/www/wwwroot/${DOMAIN}}"
            PHP_VER="${DB_PHP:-8.3}"
            REWRITE="${DB_REWRITE:-default}"
            ALIASES="${DB_ALIASES:-}"
        else
            DOCROOT="/www/wwwroot/${DOMAIN}"
            PHP_VER="8.3"
            REWRITE="default"
            ALIASES=""
        fi

        PHP_SOCK="unix:/run/php/php${PHP_VER}-fpm.sock"
        REWRITE_CONF="${PANEL_ROOT}/rewrite/nginx/${REWRITE}.conf"
        if [ ! -f "$REWRITE_CONF" ]; then
            REWRITE_CONF="${PANEL_ROOT}/rewrite/nginx/default.conf"
        fi

        ACCESS_LOG="/var/log/nginx/${DOMAIN}_access.log"
        ERROR_LOG="/var/log/nginx/${DOMAIN}_error.log"

        TEMPLATE="${PANEL_ROOT}/plugins/nginx/conf/vhost_template.conf"
        TEST_FILE="${CONF_FILE}.http_test"
        BACKUP_FILE="${CONF_FILE}.ssl_bak"

        cp "$CONF_FILE" "$BACKUP_FILE" 2>/dev/null || true

        sed \
            -e "s|{{DOMAIN}}|${DOMAIN}|g" \
            -e "s|{{ALIASES}}|${ALIASES}|g" \
            -e "s|{{DOCROOT}}|${DOCROOT}|g" \
            -e "s|{{PHP_SOCK}}|${PHP_SOCK}|g" \
            -e "s|{{REWRITE_CONF}}|${REWRITE_CONF}|g" \
            -e "s|{{ACCESS_LOG}}|${ACCESS_LOG}|g" \
            -e "s|{{ERROR_LOG}}|${ERROR_LOG}|g" \
            "$TEMPLATE" > "$TEST_FILE"

        ln -sf "$TEST_FILE" "$LINK_FILE"

        if command -v nginx >/dev/null 2>&1; then
            echo "[INFO] Testing Nginx configuration without SSL..."
            if nginx -t; then
                mv -f "$TEST_FILE" "$CONF_FILE"
                ln -sf "$CONF_FILE" "$LINK_FILE"
                rm -f "$BACKUP_FILE"
                echo "[INFO] Reloading Nginx service..."
                systemctl reload nginx 2>/dev/null || nginx -s reload 2>/dev/null || true
            else
                echo "[ERROR] Nginx test failed. Rolling back..."
                rm -f "$TEST_FILE"
                if [ -f "$BACKUP_FILE" ]; then
                    mv -f "$BACKUP_FILE" "$CONF_FILE"
                    ln -sf "$CONF_FILE" "$LINK_FILE"
                fi
                exit 1
            fi
        fi

        sqlite3 "$DB_FILE" "UPDATE sites SET ssl_enabled = 0 WHERE domain = '${DOMAIN}';"
        echo "[SUCCESS] SSL has been disabled for ${DOMAIN}. Virtual host reverted to HTTP mode."
        ;;

    renew)
        echo "[INFO] Renewing SSL certificate for domain: $DOMAIN..."
        ensure_certbot
        certbot renew --cert-name "$DOMAIN" --non-interactive
        fix_cert_permissions

        if command -v nginx >/dev/null 2>&1; then
            nginx -t && (systemctl reload nginx 2>/dev/null || nginx -s reload 2>/dev/null || true)
        fi
        echo "[SUCCESS] Certificate renewal check finished for ${DOMAIN}."
        ;;

    status)
        CERT_FILE="/etc/letsencrypt/live/${DOMAIN}/fullchain.pem"
        if [ -f "$CERT_FILE" ]; then
            openssl x509 -in "$CERT_FILE" -noout -subject -issuer -dates
        else
            echo "[INFO] No Let's Encrypt certificate found for ${DOMAIN}."
        fi
        ;;

    *)
        echo "Usage: $0 {issue|disable|renew|status} <domain> [docroot] [email] [force_https]"
        exit 1
        ;;
esac
