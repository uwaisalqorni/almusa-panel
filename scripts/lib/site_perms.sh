#!/usr/bin/env bash
# ==============================================================================
# Almusa Panel - helper kepemilikan & izin file website (di-source oleh skrip lain)
#
# Dua model website di /www/wwwroot:
#   - Terisolasi : folder project (level-1 di /www/wwwroot) dimiliki user web_<slug>.
#                  PHP-FPM pool sendiri, file tidak bisa dibaca website lain (o-rwx),
#                  nginx membaca lewat ACL, panel (almusa) lewat grup web_<slug>.
#   - Lama       : dimiliki www-data (pool PHP bersama).
# ==============================================================================

ALMUSA_WWWROOT="/www/wwwroot"
ALMUSA_SITE_USER_RE='^web_[a-z0-9]{2,20}$'
ALMUSA_NGINX_USER="nginx"

# Cetak "user:group" pemilik project yang berisi PATH.
site_owner_of() {
    local p seg top owner
    p="$(realpath -m -- "$1" 2>/dev/null)" || { echo "www-data:www-data"; return 0; }
    case "$p" in
        "$ALMUSA_WWWROOT"/*) ;;
        *) echo "www-data:www-data"; return 0 ;;
    esac
    seg="${p#"$ALMUSA_WWWROOT"/}"
    seg="${seg%%/*}"
    top="$ALMUSA_WWWROOT/$seg"
    if [ -e "$top" ]; then
        owner="$(stat -L -c '%U:%G' -- "$top" 2>/dev/null || true)"
        if [[ "${owner%%:*}" =~ $ALMUSA_SITE_USER_RE ]]; then
            echo "$owner"
            return 0
        fi
    fi
    echo "www-data:www-data"
}

site_is_isolated() {
    local owner
    owner="$(site_owner_of "$1")"
    [[ "${owner%%:*}" =~ $ALMUSA_SITE_USER_RE ]]
}

# Izin khusus project terisolasi: grup bisa tulis, other tanpa akses,
# nginx baca lewat ACL, default ACL agar file baru mengikuti aturan yang sama.
_apply_isolated_modes() {
    local target="$1"
    chmod -R u+rwX,g+rwX,o-rwx -- "$target" 2>/dev/null || true
    find "$target" -type d -exec chmod g+s {} + 2>/dev/null || true
    if command -v setfacl >/dev/null 2>&1; then
        setfacl -R -m "u:${ALMUSA_NGINX_USER}:rX" -- "$target" 2>/dev/null || true
        find "$target" -type d -exec setfacl -d -m "u::rwx,g::rwx,o::---,u:${ALMUSA_NGINX_USER}:rx" {} + 2>/dev/null || true
    fi
}

# Terapkan kepemilikan & izin standar ke PATH (rekursif) sesuai model project-nya.
apply_site_perms() {
    local target="$1" owner
    [ -e "$target" ] || return 0
    owner="$(site_owner_of "$target")"
    chown -R "$owner" -- "$target" 2>/dev/null || true
    if [[ "${owner%%:*}" =~ $ALMUSA_SITE_USER_RE ]]; then
        _apply_isolated_modes "$target"
    else
        find "$target" -type d -exec chmod 0775 {} + 2>/dev/null || true
        find "$target" -type f -exec chmod 0664 {} + 2>/dev/null || true
    fi
}

# Hanya kepemilikan (dipakai setelah chmod dengan izin pilihan user).
apply_site_owner() {
    local target="$1" recursive="${2:-0}" owner
    owner="$(site_owner_of "$target")"
    if [ "$recursive" = "1" ]; then
        chown -R "$owner" -- "$target" 2>/dev/null || true
    else
        chown "$owner" -- "$target" 2>/dev/null || true
    fi
}

# User Linux untuk menjalankan perintah (artisan, dll.) di dalam project.
site_run_user_of() {
    local owner
    owner="$(site_owner_of "$1")"
    echo "${owner%%:*}"
}
