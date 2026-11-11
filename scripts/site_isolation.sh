#!/usr/bin/env bash
# ==============================================================================
# Almusa Panel - Isolasi website per user Linux + pool PHP-FPM sendiri
#
# Alur tanpa downtime:
#   1. prepare  <slug> <project_dir> <php_ver> <domain>
#        buat user web_<slug>, pool FPM baru (socket /run/php/php-site-<slug>.sock)
#        di samping pool lama, beri akses sementara via ACL.
#   2. (ubah fastcgi_pass nginx ke socket baru, nginx -t, reload)
#   3. finalize <slug> <project_dir>
#        chown ke web_<slug>, cabut akses other (www-data / website lain), ACL nginx.
#   revert <slug> <project_dir>   kembalikan ke www-data (setelah nginx dikembalikan).
#
# Pool menyimpan penanda "; almusa-domain = <domain>" agar switch_php / delete
# di vhost_manager.sh bisa menemukannya.
# ==============================================================================
set -euo pipefail

SCRIPT_DIR="$(cd -P "$(dirname "${BASH_SOURCE[0]}")" >/dev/null 2>&1 && pwd)"
# shellcheck source=lib/site_perms.sh
source "$SCRIPT_DIR/lib/site_perms.sh"

ACTION="${1:-}"
SLUG="${2:-}"
PROJECT="${3:-}"
PHP_VER="${4:-}"
DOMAIN="${5:-}"

[ "$(id -u)" -eq 0 ] || { echo "[ERROR] Jalankan sebagai root." >&2; exit 1; }
[[ "$SLUG" =~ ^[a-z0-9]{2,20}$ ]] || { echo "[ERROR] Slug tidak valid: '$SLUG' (a-z0-9, 2-20 karakter)" >&2; exit 1; }

SITE_USER="web_${SLUG}"
SOCK="/run/php/php-site-${SLUG}.sock"
SESS_DIR="/var/lib/php/sessions-site/${SLUG}"
TMP_DIR="/var/lib/php/tmp-site/${SLUG}"
CRON_LOG_DIR="/var/log/almusa-cron"
PANEL_USER="almusa"

resolve_project() {
    local p
    p="$(realpath -e -- "$PROJECT" 2>/dev/null)" || { echo "[ERROR] Folder project tidak ada: $PROJECT" >&2; exit 1; }
    case "$p" in
        "$ALMUSA_WWWROOT"/*/*|"$ALMUSA_WWWROOT") echo "[ERROR] Project harus folder level-1 di $ALMUSA_WWWROOT: $p" >&2; exit 1 ;;
        "$ALMUSA_WWWROOT"/*) ;;
        *) echo "[ERROR] Project di luar $ALMUSA_WWWROOT: $p" >&2; exit 1 ;;
    esac
    PROJECT="$p"
}

find_pool() {
    ls /etc/php/*/fpm/pool.d/site-"${SLUG}".conf 2>/dev/null | head -1 || true
}

wait_socket() {
    local ver="$1" i
    for i in 1 2 3 4 5 6 7 8 9 10; do
        if [ -S "$SOCK" ] && [ "$(stat -c '%U:%G' "$SOCK")" = "${SITE_USER}:${ALMUSA_NGINX_USER}" ]; then
            return 0
        fi
        sleep 1
        # Cache NSS kadang belum mengenal user/grup baru: reload ulang
        [ "$i" = "4" ] && systemctl reload "php${ver}-fpm"
    done
    echo "[ERROR] Socket $SOCK tidak muncul dengan pemilik ${SITE_USER}:${ALMUSA_NGINX_USER}." >&2
    return 1
}

case "$ACTION" in
    prepare)
        resolve_project
        [[ "$PHP_VER" =~ ^[0-9]+\.[0-9]+$ ]] && [ -d "/etc/php/${PHP_VER}/fpm/pool.d" ] \
            || { echo "[ERROR] PHP-FPM ${PHP_VER} tidak terpasang." >&2; exit 1; }
        [[ "$DOMAIN" =~ ^[a-zA-Z0-9.-]+$ ]] || { echo "[ERROR] Domain tidak valid: '$DOMAIN'" >&2; exit 1; }
        EXISTING="$(find_pool)"
        if [ -n "$EXISTING" ] && [ "$EXISTING" != "/etc/php/${PHP_VER}/fpm/pool.d/site-${SLUG}.conf" ]; then
            echo "[ERROR] Pool site-${SLUG} sudah ada di versi PHP lain: $EXISTING" >&2; exit 1
        fi

        echo "[1/4] User ${SITE_USER}"
        if ! id "$SITE_USER" >/dev/null 2>&1; then
            useradd --system --user-group --no-create-home --home-dir /nonexistent --shell /usr/sbin/nologin "$SITE_USER"
        fi
        usermod -aG "$SITE_USER" "$PANEL_USER"

        echo "[2/4] Folder sesi & temp privat"
        mkdir -p "$SESS_DIR" "$TMP_DIR"
        chown "$SITE_USER:$SITE_USER" "$SESS_DIR" "$TMP_DIR"
        chmod 0700 "$SESS_DIR" "$TMP_DIR"
        chmod 0711 "$(dirname "$SESS_DIR")" "$(dirname "$TMP_DIR")"

        echo "[3/4] Pool PHP-FPM ${PHP_VER} site-${SLUG}"
        POOL="/etc/php/${PHP_VER}/fpm/pool.d/site-${SLUG}.conf"
        cat > "$POOL" <<EOF
; Dikelola Almusa Panel (scripts/site_isolation.sh) - jangan ubah baris penanda di bawah
; almusa-domain = ${DOMAIN}
; almusa-project = ${PROJECT}
[site-${SLUG}]
user = ${SITE_USER}
group = ${SITE_USER}
listen = ${SOCK}
listen.owner = ${SITE_USER}
listen.group = ${ALMUSA_NGINX_USER}
listen.mode = 0660

pm = dynamic
pm.max_children = 10
pm.start_servers = 2
pm.min_spare_servers = 1
pm.max_spare_servers = 3
pm.max_requests = 500

; Sesi & file sementara terpisah per website
php_admin_value[session.save_path] = ${SESS_DIR}
php_admin_value[session.gc_probability] = 1
php_admin_value[session.gc_divisor] = 1000
php_admin_value[upload_tmp_dir] = ${TMP_DIR}
php_admin_value[sys_temp_dir] = ${TMP_DIR}
EOF
        chmod 0644 "$POOL"
        "php-fpm${PHP_VER}" -t >/dev/null 2>&1 || { echo "[ERROR] Konfigurasi FPM tidak valid, pool dihapus." >&2; rm -f "$POOL"; "php-fpm${PHP_VER}" -t; exit 1; }
        systemctl reload "php${PHP_VER}-fpm"
        wait_socket "$PHP_VER"

        echo "[4/4] Akses sementara ${SITE_USER} ke project (ACL), pemilik lama tetap"
        setfacl -R -m "u:${SITE_USER}:rwX" -- "$PROJECT"
        find "$PROJECT" -type d -exec setfacl -d -m "u:${SITE_USER}:rwx" {} +

        echo "[SUCCESS] Siap. Arahkan fastcgi_pass ke unix:${SOCK} lalu jalankan finalize."
        ;;

    finalize)
        resolve_project
        [ -n "$(find_pool)" ] || { echo "[ERROR] Pool site-${SLUG} belum ada; jalankan prepare dulu." >&2; exit 1; }
        echo "[1/3] Pemilik project -> ${SITE_USER}"
        chown -R "${SITE_USER}:${SITE_USER}" -- "$PROJECT"

        echo "[2/3] Izin: grup tulis, other tanpa akses, nginx baca (ACL)"
        # Bersihkan ACL sementara (pemilik sekarang sudah web_<slug>)
        setfacl -R -x "u:${SITE_USER}" -- "$PROJECT" 2>/dev/null || true
        find "$PROJECT" -type d -exec setfacl -d -x "u:${SITE_USER}" {} + 2>/dev/null || true
        _apply_isolated_modes "$PROJECT"

        echo "[3/3] Log cron bisa ditulis job ${SITE_USER}"
        if [ -d "$CRON_LOG_DIR" ]; then
            setfacl -m "g:${SITE_USER}:rwx" "$CRON_LOG_DIR"
        fi

        # Panel perlu keanggotaan grup baru -> restart FPM panel (cepat)
        systemctl restart mypanel-web 2>/dev/null || true
        echo "[SUCCESS] ${PROJECT} terisolasi sebagai ${SITE_USER}."
        ;;

    revert)
        resolve_project
        echo "[INFO] Mengembalikan ${PROJECT} ke www-data (pastikan nginx sudah diarahkan ke socket lama)."
        chown -R www-data:www-data -- "$PROJECT"
        setfacl -R -b -- "$PROJECT" 2>/dev/null || true
        find "$PROJECT" -type d -exec chmod 0775 {} + 2>/dev/null || true
        find "$PROJECT" -type f -exec chmod u+rw,g+rw,o+r {} + 2>/dev/null || true
        find "$PROJECT" -type d -exec chmod g-s {} + 2>/dev/null || true
        POOL="$(find_pool)"
        if [ -n "$POOL" ]; then
            VER="$(echo "$POOL" | cut -d/ -f4)"
            rm -f "$POOL"
            systemctl reload "php${VER}-fpm"
        fi
        echo "[SUCCESS] Dikembalikan. User ${SITE_USER} dibiarkan (hapus manual: userdel ${SITE_USER})."
        ;;

    *)
        echo "Usage: $0 {prepare <slug> <project_dir> <php_ver> <domain>|finalize <slug> <project_dir>|revert <slug> <project_dir>}"
        exit 1
        ;;
esac
