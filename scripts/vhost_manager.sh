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
# shellcheck source=lib/site_perms.sh
source "${PANEL_ROOT}/scripts/lib/site_perms.sh"

# Pool PHP-FPM per-website (dibuat scripts/site_isolation.sh) untuk domain ini, jika ada
site_pool_for_domain() {
    grep -lsE "^; almusa-domain = ${1//./\\.}\s*$" /etc/php/*/fpm/pool.d/site-*.conf 2>/dev/null | head -1 || true
}

ACTION="${1:-}"
DOMAIN="${2:-}"
DOCROOT="${3:-}"
PHP_VER="${4:-8.3}"
REWRITE="${5:-default}"
LOCAL_PORT="${6:-0}"
APP_TYPE="${7:-php}"

# Strict domain sanitization
if [ "$ACTION" != "fix_permissions" ] && [ "$ACTION" != "chmod_path" ] && [ "$ACTION" != "extract_archive" ] && { [ -z "$DOMAIN" ] || ! [[ "$DOMAIN" =~ ^[a-zA-Z0-9.-]+$ ]]; }; then
    echo "[ERROR] Invalid domain name: '$DOMAIN'"
    exit 1
fi

# Kanonikalisasi path & pastikan berada di bawah /www/wwwroot/ (anti path traversal / symlink).
# Pola `case /www/wwwroot/*` saja TIDAK cukup: cocok dengan /www/wwwroot/../../etc.
canonical_wwwroot() {
    local resolved
    resolved="$(realpath -m -- "$1" 2>/dev/null)" || return 1
    case "$resolved" in
        /www/wwwroot|/www/wwwroot/*) printf '%s' "$resolved" ;;
        *) return 1 ;;
    esac
}

# Tolak segmen '..' pada docroot yang diberikan caller
if [[ "$DOCROOT" =~ (^|/)\.\.(/|$) ]]; then
    echo "[ERROR] Path traversal terdeteksi pada docroot: '$DOCROOT'"
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
        <span class="badge">Virtual Host Aktif • PHP <?= PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION ?></span>
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
            chmod -R g+w "$DOCROOT" 2>/dev/null || true
        fi

        # Determine PHP socket path
        # Determine PHP socket path & ensure PHP-FPM service is running
        mkdir -p /run/php
        chown www-data:www-data /run/php 2>/dev/null || true
        chmod 0755 /run/php 2>/dev/null || true
        if [ -x "/usr/sbin/php-fpm${PHP_VER}" ] || [ -f "/etc/php/${PHP_VER}/fpm/php-fpm.conf" ]; then
            if ! [ -S "/run/php/php${PHP_VER}-fpm.sock" ] || ! systemctl is-active --quiet "php${PHP_VER}-fpm" 2>/dev/null; then
                echo "[INFO] Starting PHP ${PHP_VER}-FPM service..."
                systemctl enable --now "php${PHP_VER}-fpm" 2>/dev/null || systemctl restart "php${PHP_VER}-fpm" 2>/dev/null || true
            fi
        fi
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

        # Dedicated LAN Local Testing Server Block
        if [ -n "$LOCAL_PORT" ] && [ "$LOCAL_PORT" -gt 0 ]; then
            SERVER_LAN_IP=$(ip -4 route get 1.1.1.1 2>/dev/null | awk '{print $7}' || hostname -I 2>/dev/null | awk '{print $1}')
            [ -z "$SERVER_LAN_IP" ] && SERVER_LAN_IP="192.168.0.17"

            cat << EOF >> "$CONF_FILE"

# ==============================================================================
# Port ${LOCAL_PORT}: Dedicated Local LAN Access (${SERVER_LAN_IP}:${LOCAL_PORT})
# ==============================================================================
server {
    listen ${LOCAL_PORT} default_server;
    listen [::]:${LOCAL_PORT} default_server;
    server_name ${SERVER_LAN_IP} localhost "";

    absolute_redirect off;
    port_in_redirect off;

    root ${DOCROOT};
    index index.php index.html index.htm;

    charset utf-8;
    access_log /var/log/nginx/${DOMAIN}_lan_access.log;
    error_log /var/log/nginx/${DOMAIN}_lan_error.log warn;

    add_header X-Frame-Options "SAMEORIGIN" always;
    add_header X-XSS-Protection "0" always;
    add_header X-Content-Type-Options "nosniff" always;
    add_header Referrer-Policy "strict-origin-when-cross-origin" always;

    set \$fcgi_https \$https;
    if (\$http_x_forwarded_proto = "https") {
        set \$fcgi_https "on";
    }

    include ${REWRITE_CONF};

    location ~ \.php$ {
        try_files \$uri =404;
        fastcgi_split_path_info ^(.+\.php)(/.+)$;
        fastcgi_pass ${PHP_SOCK};
        fastcgi_index index.php;
        fastcgi_param SCRIPT_FILENAME \$realpath_root\$fastcgi_script_name;
        include fastcgi_params;
        fastcgi_param HTTPS \$fcgi_https;
        fastcgi_buffers 16 16k;
        fastcgi_buffer_size 32k;
        fastcgi_read_timeout 300;
        fastcgi_hide_header X-Powered-By;
    }

    location ~* \.(jpg|jpeg|gif|png|webp|svg|css|js|ico|woff|woff2|ttf|eot)$ {
        try_files \$uri \$uri/ /index.php?\$query_string;
        expires 30d;
        access_log off;
        add_header Cache-Control "public, no-transform";
    }

    location ~ /\.(?!well-known) {
        deny all;
    }
}
EOF

            # Auto whitelist in UFW Firewall
            if command -v ufw >/dev/null 2>&1; then
                echo "[INFO] Whitelisting LAN port ${LOCAL_PORT} in UFW firewall..."
                ufw allow "${LOCAL_PORT}/tcp" comment "Almusa Site ${DOMAIN} LAN" 2>/dev/null || true
                ufw reload 2>/dev/null || true
            fi
        fi

        # Framework Presetting (Laravel setup)
        if [ "$APP_TYPE" = "laravel" ]; then
            PROJECT_DIR="${DOCROOT%/public}"
            echo "[INFO] Configuring Laravel directories & permissions in ${PROJECT_DIR}..."
            mkdir -p "${PROJECT_DIR}/storage/framework/"{sessions,views,cache} "${PROJECT_DIR}/storage/logs" "${PROJECT_DIR}/bootstrap/cache"
            chown -R www-data:www-data "${PROJECT_DIR}/storage" "${PROJECT_DIR}/bootstrap/cache" 2>/dev/null || true
            chmod -R 775 "${PROJECT_DIR}/storage" "${PROJECT_DIR}/bootstrap/cache" 2>/dev/null || true
            if [ -f "${PROJECT_DIR}/artisan" ]; then
                echo "[INFO] Auto-running storage:link for Laravel..."
                su -s /bin/bash www-data -c "php ${PROJECT_DIR}/artisan storage:link" 2>/dev/null || true
            fi
        fi

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

        mkdir -p /run/php
        chown www-data:www-data /run/php 2>/dev/null || true
        chmod 0755 /run/php 2>/dev/null || true
        if [ -x "/usr/sbin/php-fpm${PHP_VER}" ] || [ -f "/etc/php/${PHP_VER}/fpm/php-fpm.conf" ]; then
            if ! [ -S "/run/php/php${PHP_VER}-fpm.sock" ] || ! systemctl is-active --quiet "php${PHP_VER}-fpm" 2>/dev/null; then
                echo "[INFO] Starting PHP ${PHP_VER}-FPM service..."
                systemctl enable --now "php${PHP_VER}-fpm" 2>/dev/null || systemctl restart "php${PHP_VER}-fpm" 2>/dev/null || true
            fi
        fi
        SITE_POOL="$(site_pool_for_domain "$DOMAIN")"
        if [ -n "$SITE_POOL" ]; then
            # Website terisolasi: socket per-website tetap sama, cukup pindahkan pool ke versi PHP baru
            OLD_VER="$(echo "$SITE_POOL" | cut -d/ -f4)"
            NEW_POOL="/etc/php/${PHP_VER}/fpm/pool.d/$(basename "$SITE_POOL")"
            if [ "$OLD_VER" != "$PHP_VER" ]; then
                [ -d "/etc/php/${PHP_VER}/fpm/pool.d" ] || { echo "[ERROR] PHP-FPM ${PHP_VER} belum terpasang."; exit 1; }
                echo "[INFO] Memindahkan pool $(basename "$SITE_POOL") dari PHP ${OLD_VER} ke ${PHP_VER}..."
                mv -f "$SITE_POOL" "$NEW_POOL"
                if ! "php-fpm${PHP_VER}" -t >/dev/null 2>&1; then
                    mv -f "$NEW_POOL" "$SITE_POOL"
                    echo "[ERROR] Konfigurasi PHP-FPM ${PHP_VER} tidak valid. Dibatalkan."
                    exit 1
                fi
                # Lepas socket dari versi lama dulu, lalu versi baru membuatnya kembali
                systemctl reload "php${OLD_VER}-fpm" 2>/dev/null || true
                systemctl reload "php${PHP_VER}-fpm" 2>/dev/null || systemctl restart "php${PHP_VER}-fpm"
            fi
        else
            PHP_SOCK="unix:\/run\/php\/php${PHP_VER}-fpm.sock"
            sed -i -E "s|fastcgi_pass unix:/run/php/php[0-9.]+-fpm.sock;|fastcgi_pass ${PHP_SOCK};|g" "$CONF_FILE"
        fi

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
        LOCAL_PORT="${5:-0}"
        echo "[INFO] Deleting Virtual Host for $DOMAIN..."
        rm -f "$LINK_FILE" "$CONF_FILE"

        SITE_POOL="$(site_pool_for_domain "$DOMAIN")"
        if [ -n "$SITE_POOL" ]; then
            echo "[INFO] Removing per-site PHP-FPM pool $(basename "$SITE_POOL")..."
            rm -f "$SITE_POOL"
            systemctl reload "php$(echo "$SITE_POOL" | cut -d/ -f4)-fpm" 2>/dev/null || true
        fi

        # Remove UFW firewall rule if LAN port was allocated
        if command -v ufw >/dev/null 2>&1 && [ -n "$LOCAL_PORT" ] && [ "$LOCAL_PORT" -gt 0 ]; then
            echo "[INFO] Removing LAN port ${LOCAL_PORT} from UFW firewall..."
            ufw delete allow "${LOCAL_PORT}/tcp" 2>/dev/null || true
            ufw reload 2>/dev/null || true
        fi

        if [ "$DELETE_FILES" = "1" ] && [ -n "$DOCROOT" ] && [ -d "$DOCROOT" ]; then
            if SAFE_DOCROOT="$(canonical_wwwroot "$DOCROOT")" && [ -d "$SAFE_DOCROOT" ]; then
                echo "[INFO] Removing website directory: $SAFE_DOCROOT..."
                rm -rf -- "$SAFE_DOCROOT"
            else
                echo "[WARNING] Docroot outside /www/wwwroot (setelah kanonikalisasi), not deleted for safety: $DOCROOT"
            fi
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

    save_conf)
        SRC_FILE="${3:-}"
        echo "[INFO] Validating and applying Nginx configuration for $DOMAIN..."
        if [ -z "$SRC_FILE" ] || [ ! -f "$SRC_FILE" ]; then
            echo "[ERROR] Source configuration file '$SRC_FILE' does not exist."
            exit 1
        fi

        BACKUP_FILE="${CONF_FILE}.bak"
        TEST_FILE="${CONF_FILE}.test"
        cp "$CONF_FILE" "$BACKUP_FILE" 2>/dev/null || true
        cp "$SRC_FILE" "$TEST_FILE"

        # Temporarily link test file and test nginx configuration
        rm -f "$LINK_FILE"
        ln -sf "$TEST_FILE" "$LINK_FILE"

        if nginx -t; then
            mv -f "$TEST_FILE" "$CONF_FILE"
            ln -sf "$CONF_FILE" "$LINK_FILE"
            rm -f "$BACKUP_FILE" "$SRC_FILE"
            echo "[INFO] Reloading Nginx..."
            systemctl reload nginx 2>/dev/null || nginx -s reload 2>/dev/null || true
            echo "[SUCCESS] Configuration for $DOMAIN successfully saved and reloaded!"
        else
            echo "[ERROR] Nginx configuration test failed! Rolling back changes..."
            rm -f "$TEST_FILE"
            if [ -f "$BACKUP_FILE" ]; then
                mv -f "$BACKUP_FILE" "$CONF_FILE"
                ln -sf "$CONF_FILE" "$LINK_FILE"
            fi
            exit 1
        fi
        ;;

    fix_permissions)
        TARGET_PATH="${3:-}"
        echo "[INFO] Fixing permissions for $TARGET_PATH..."
        if [ -z "$TARGET_PATH" ] || [ ! -e "$TARGET_PATH" ]; then
            echo "[ERROR] Path '$TARGET_PATH' does not exist."
            exit 1
        fi
        if ! SAFE_TARGET="$(canonical_wwwroot "$TARGET_PATH")"; then
            echo "[ERROR] Refusing to modify permissions outside /www/wwwroot"
            exit 1
        fi
        # Pemilik mengikuti project (web_<slug> bila terisolasi, selain itu www-data);
        # grup bisa tulis agar panel (almusa) tetap bisa mengelola file.
        apply_site_perms "$SAFE_TARGET"
        echo "[SUCCESS] Permissions set for $SAFE_TARGET (owner: $(site_owner_of "$SAFE_TARGET"))."
        ;;

    chmod_path)
        TARGET_PATH="${3:-}"
        PERMS="${4:-0755}"
        RECURSIVE="${5:-0}"
        if [ -z "$TARGET_PATH" ] || [ ! -e "$TARGET_PATH" ]; then
            echo "[ERROR] Path '$TARGET_PATH' does not exist."
            exit 1
        fi
        if ! SAFE_TARGET="$(canonical_wwwroot "$TARGET_PATH")"; then
            echo "[ERROR] Refusing to modify permissions outside /www/wwwroot"
            exit 1
        fi
        if ! [[ "$PERMS" =~ ^0?[1-7][0-7]{2}$ ]]; then
            echo "[ERROR] Invalid octal permissions: '$PERMS'"
            exit 1
        fi
        if [ "$RECURSIVE" = "1" ] && [ -d "$SAFE_TARGET" ]; then
            apply_site_owner "$SAFE_TARGET" 1
            chmod -R "$PERMS" -- "$SAFE_TARGET"
        else
            apply_site_owner "$SAFE_TARGET" 0
            chmod "$PERMS" -- "$SAFE_TARGET"
        fi
        echo "[SUCCESS] Permissions updated to $PERMS for $SAFE_TARGET."
        ;;

    extract_archive)
        ARCHIVE_PATH="${3:-}"
        DEST_PATH="${4:-}"
        if [ -z "$ARCHIVE_PATH" ] || [ ! -f "$ARCHIVE_PATH" ]; then
            echo "[ERROR] Archive file '$ARCHIVE_PATH' does not exist."
            exit 1
        fi
        if ! SAFE_ARCHIVE="$(canonical_wwwroot "$ARCHIVE_PATH")"; then
            echo "[ERROR] Refusing to extract archive outside /www/wwwroot"
            exit 1
        fi
        if [ -z "$DEST_PATH" ]; then
            DEST_PATH="$(dirname "$SAFE_ARCHIVE")"
        fi
        if ! SAFE_DEST="$(canonical_wwwroot "$DEST_PATH")"; then
            echo "[ERROR] Refusing to extract to destination outside /www/wwwroot"
            exit 1
        fi
        mkdir -p -- "$SAFE_DEST"
        LOWER_ARC="$(echo "$SAFE_ARCHIVE" | tr '[:upper:]' '[:lower:]')"
        case "$LOWER_ARC" in
            *.tar.gz|*.tgz|*.tar)
                tar -xf "$SAFE_ARCHIVE" -C "$SAFE_DEST"
                ;;
            *.zip)
                if ! command -v unzip >/dev/null 2>&1; then
                    DEBIAN_FRONTEND=noninteractive apt-get update -qq >/dev/null 2>&1 || true
                    DEBIAN_FRONTEND=noninteractive apt-get install -y unzip >/dev/null 2>&1 || true
                fi
                if command -v unzip >/dev/null 2>&1; then
                    SET_EXIT=0
                    unzip -q -o "$SAFE_ARCHIVE" -d "$SAFE_DEST" || SET_EXIT=$?
                    if [ "$SET_EXIT" -ne 0 ] && [ "$SET_EXIT" -ne 1 ]; then
                        exit "$SET_EXIT"
                    fi
                elif command -v python3 >/dev/null 2>&1; then
                    python3 -m zipfile -e "$SAFE_ARCHIVE" "$SAFE_DEST"
                else
                    echo "[ERROR] Neither unzip nor python3 is available to extract zip archive."
                    exit 1
                fi
                ;;
            *)
                echo "[ERROR] Unsupported archive format: $SAFE_ARCHIVE"
                exit 1
                ;;
        esac
        apply_site_perms "$SAFE_DEST"
        echo "[SUCCESS] Archive extracted to $SAFE_DEST."
        ;;

    clear_log)
        TYPE="${3:-all}"
        echo "[INFO] Clearing $TYPE log for $DOMAIN..."
        if [ "$TYPE" = "access" ] || [ "$TYPE" = "all" ]; then
            [ -f "/var/log/nginx/${DOMAIN}_access.log" ] && > "/var/log/nginx/${DOMAIN}_access.log"
        fi
        if [ "$TYPE" = "error" ] || [ "$TYPE" = "all" ]; then
            [ -f "/var/log/nginx/${DOMAIN}_error.log" ] && > "/var/log/nginx/${DOMAIN}_error.log"
        fi
        echo "[SUCCESS] Logs cleared for $DOMAIN."
        ;;

    *)
        echo "Usage: $0 {create|switch_php|delete|save_conf|fix_permissions|chmod_path|clear_log} <domain> [docroot/src_file] [php_ver/perms] [rewrite/recursive]"
        exit 1
        ;;
esac

