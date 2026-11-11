#!/usr/bin/env bash
# ==============================================================================
# Almusa Panel - Isolasi User (idempotent, aman dijalankan berulang)
#
# Model keamanan:
#   www-data : user website (PHP-FPM pool situs). Tidak punya akses ke panel.
#   nginx    : user worker nginx. Satu-satunya yang boleh menyambung ke socket FPM.
#   almusa   : user panel web (PHP-FPM panel). Pemilik data/ & backup.
#   root     : pemilik kode panel; task.php (worker) mengeksekusi aksi sistem.
#
# Dengan ini, website yang diretas (www-data) tidak bisa:
#   - mengubah kode panel yang dijalankan root (task.php, scripts/*.sh)
#   - menulis antrean task di data/panel.db (=> perintah root)
#   - membaca secret.key, sesi panel, entrance path, atau file backup
#   - menyambung langsung ke socket PHP-FPM panel
#
# Penggunaan: sudo bash scripts/harden_isolation.sh [--dry-run]
# ==============================================================================
set -euo pipefail

DRY_RUN=0
[ "${1:-}" = "--dry-run" ] && DRY_RUN=1

if [ "$(id -u)" -ne 0 ]; then
    echo "[ERROR] Jalankan sebagai root." >&2
    exit 1
fi

SOURCE="${BASH_SOURCE[0]}"
while [ -h "$SOURCE" ]; do
    DIR="$(cd -P "$(dirname "$SOURCE")" >/dev/null 2>&1 && pwd)"
    SOURCE="$(readlink "$SOURCE")"
    [[ $SOURCE != /* ]] && SOURCE="$DIR/$SOURCE"
done
PANEL_ROOT="$(cd -P "$(dirname "$SOURCE")/.." >/dev/null 2>&1 && pwd)"

PANEL_USER="almusa"
NGINX_USER="nginx"
SITE_USER="www-data"
BACKUP_ROOT="/root/almusa-hardening-backup/$(date +%Y%m%d_%H%M%S)"
CRON_LOG_DIR="/var/log/almusa-cron"

run() {
    if [ "$DRY_RUN" = "1" ]; then
        echo "  [dry-run] $*"
    else
        "$@"
    fi
}

backup_file() {
    [ -e "$1" ] || return 0
    run mkdir -p "$BACKUP_ROOT$(dirname "$1")"
    run cp -a "$1" "$BACKUP_ROOT$1"
}

echo "== Almusa Panel: isolasi user (panel root: $PANEL_ROOT) =="
[ "$DRY_RUN" = "1" ] && echo "   MODE DRY-RUN: tidak ada perubahan yang diterapkan."

# ------------------------------------------------------------------------------
echo "[1/8] User sistem"
if ! id "$PANEL_USER" >/dev/null 2>&1; then
    run useradd --system --user-group --no-create-home --home-dir /nonexistent --shell /usr/sbin/nologin "$PANEL_USER"
fi
if ! id "$NGINX_USER" >/dev/null 2>&1; then
    run useradd --system --user-group --no-create-home --home-dir /nonexistent --shell /usr/sbin/nologin "$NGINX_USER"
fi
# Panel: grup www-data (kelola file website) + adm (baca log nginx).
# Grup almusa juga dicantumkan eksplisit: FPM panel berjalan dengan group=www-data,
# dan initgroups() hanya menambahkan grup yang mencantumkan user sebagai anggota.
run usermod -aG "$SITE_USER,adm,$PANEL_USER" "$PANEL_USER"
# nginx & www-data TIDAK boleh menjadi anggota grup panel / satu sama lain
for u in "$NGINX_USER" "$SITE_USER"; do
    for g in "$PANEL_USER" "$SITE_USER"; do
        [ "$u" = "$SITE_USER" ] && [ "$g" = "$SITE_USER" ] && continue
        if id -nG "$u" 2>/dev/null | tr ' ' '\n' | grep -qx "$g"; then
            run gpasswd -d "$u" "$g" >/dev/null
        fi
    done
done

# ------------------------------------------------------------------------------
echo "[2/8] Socket PHP-FPM website hanya untuk nginx"
FPM_RESTART=()
for conf in /etc/php/*/fpm/pool.d/*.conf; do
    [ -f "$conf" ] || continue
    grep -qE "^\s*user\s*=\s*${SITE_USER}\s*$" "$conf" || continue
    ver="$(echo "$conf" | cut -d/ -f4)"
    if grep -qE "^\s*listen\.group\s*=\s*${NGINX_USER}\s*$" "$conf" \
       && grep -qE "^\s*listen\.mode\s*=\s*0660\s*$" "$conf"; then
        continue
    fi
    backup_file "$conf"
    run sed -i -E \
        -e "s|^;?\s*listen\.owner\s*=.*|listen.owner = ${SITE_USER}|" \
        -e "s|^;?\s*listen\.group\s*=.*|listen.group = ${NGINX_USER}|" \
        -e "s|^;?\s*listen\.mode\s*=.*|listen.mode = 0660|" "$conf"
    grep -qE "^\s*listen\.mode\s*=" "$conf" || run sed -i "/^\s*listen\.group\s*=/a listen.mode = 0660" "$conf"
    FPM_RESTART+=("$ver")
done
for ver in $(printf '%s\n' "${FPM_RESTART[@]:-}" | sort -u); do
    [ -n "$ver" ] || continue
    if [ "$DRY_RUN" = "0" ]; then
        "php-fpm${ver}" -t >/dev/null 2>&1 || { echo "[ERROR] Konfigurasi php${ver}-fpm tidak valid." >&2; exit 1; }
        systemctl restart "php${ver}-fpm"
        # Grup yang baru dibuat kadang belum terbaca FPM (cache NSS): verifikasi & ulangi
        sock="$(grep -E '^\s*listen\s*=' /etc/php/${ver}/fpm/pool.d/*.conf | head -1 | sed -E 's/.*=\s*//')"
        for attempt in 1 2 3; do
            sleep 1
            [ -S "$sock" ] && [ "$(stat -c %G "$sock")" = "$NGINX_USER" ] && break
            command -v nscd >/dev/null 2>&1 && nscd -i group >/dev/null 2>&1 || true
            systemctl restart "php${ver}-fpm"
        done
        if [ -S "$sock" ] && [ "$(stat -c %G "$sock")" != "$NGINX_USER" ]; then
            echo "[ERROR] Socket $sock bukan milik grup ${NGINX_USER}; nginx tidak akan bisa menyambung." >&2
            exit 1
        fi
    fi
    echo "  - php${ver}-fpm: socket dibatasi untuk grup ${NGINX_USER}"
done

# ------------------------------------------------------------------------------
echo "[3/8] Nginx berjalan sebagai user ${NGINX_USER}"
NGINX_CONF="/etc/nginx/nginx.conf"
if [ -f "$NGINX_CONF" ] && ! grep -qE "^\s*user\s+${NGINX_USER}\s*;" "$NGINX_CONF"; then
    backup_file "$NGINX_CONF"
    run sed -i -E "s|^\s*user\s+[^;]+;|user ${NGINX_USER};|" "$NGINX_CONF"
    grep -qE "^\s*user\s+" "$NGINX_CONF" || run sed -i "1i user ${NGINX_USER};" "$NGINX_CONF"
fi
for d in /var/lib/nginx/*; do
    [ -d "$d" ] && run chown -R "$NGINX_USER" "$d"
done
if [ -f /etc/logrotate.d/nginx ] && grep -qE "create\s+[0-7]+\s+${SITE_USER}\s" /etc/logrotate.d/nginx; then
    backup_file /etc/logrotate.d/nginx
    run sed -i -E "s|(create\s+[0-7]+\s+)${SITE_USER}(\s)|\1${NGINX_USER}\2|" /etc/logrotate.d/nginx
fi

# ------------------------------------------------------------------------------
echo "[4/8] Kode panel milik root (tidak bisa diubah panel/website)"
run chown -R root:root "$PANEL_ROOT"
run find "$PANEL_ROOT" -path "$PANEL_ROOT/data" -prune -o -path "$PANEL_ROOT/.git" -prune -o -type d -exec chmod 0755 {} +
run find "$PANEL_ROOT" -path "$PANEL_ROOT/data" -prune -o -path "$PANEL_ROOT/.git" -prune -o -type f -exec chmod 0644 {} +
run find "$PANEL_ROOT" -path "$PANEL_ROOT/data" -prune -o -type f -name "*.sh" -exec chmod 0755 {} +

# ------------------------------------------------------------------------------
echo "[5/8] Data panel privat untuk ${PANEL_USER}"
DATA="$PANEL_ROOT/data"
run mkdir -p "$DATA/sessions" "$DATA/staging"
run chown -R "$PANEL_USER:$PANEL_USER" "$DATA"
run chmod 2770 "$DATA"
run find "$DATA" -mindepth 1 -type d -exec chmod 2770 {} +
run find "$DATA" -type f -exec chmod u+rw,g+rw,o-rwx {} +
run chmod 0700 "$DATA/sessions" "$DATA/staging"
[ -f "$DATA/secret.key" ] && run chmod 0600 "$DATA/secret.key"

# Log cron: ditulis job www-data/root, dibaca panel. Di luar data/ agar data/ tetap privat.
run mkdir -p "$CRON_LOG_DIR"
run chown "$PANEL_USER:$SITE_USER" "$CRON_LOG_DIR"
run chmod 2770 "$CRON_LOG_DIR"
if [ -d "$DATA/cron_logs" ]; then
    for f in "$DATA"/cron_logs/*.log; do
        [ -f "$f" ] && run mv -n "$f" "$CRON_LOG_DIR/"
    done
fi
# Path log lama di cron sistem -> lokasi baru
if [ -f /etc/cron.d/almusa-cron ] && grep -q "$DATA/cron_logs/" /etc/cron.d/almusa-cron; then
    backup_file /etc/cron.d/almusa-cron
    run sed -i "s|$DATA/cron_logs/|$CRON_LOG_DIR/|g" /etc/cron.d/almusa-cron
fi

# ------------------------------------------------------------------------------
echo "[6/8] Backup hanya bisa dibaca ${PANEL_USER}"
if [ -d /www/backup ]; then
    run chown -R "$PANEL_USER:$PANEL_USER" /www/backup
    # setgid: file baru (dibuat panel dengan grup proses www-data) tetap ber-grup almusa
    run chmod 2750 /www/backup
    run find /www/backup -mindepth 1 -type d -exec chmod 2750 {} +
    run find /www/backup -type f -exec chmod o-rwx,g-w {} +
fi

# ------------------------------------------------------------------------------
echo "[7/8] File website bisa ditulis grup ${SITE_USER} (agar file manager panel tetap berfungsi)"
if [ -d /www/wwwroot ]; then
    run find /www/wwwroot \( -type d -o -type f \) -group "$SITE_USER" ! -perm -g+w -exec chmod g+w {} +
fi

# ------------------------------------------------------------------------------
echo "[8/8] Hapus sudo untuk web & terapkan layanan"
if [ -f /etc/sudoers.d/almusa-panel ]; then
    backup_file /etc/sudoers.d/almusa-panel
    run rm -f /etc/sudoers.d/almusa-panel
fi

# File permission default panel: file baru group-writable (untuk file website)
DROPIN_DIR=/etc/systemd/system/mypanel-web.service.d
if [ ! -f "$DROPIN_DIR/isolation.conf" ]; then
    run mkdir -p "$DROPIN_DIR"
    if [ "$DRY_RUN" = "0" ]; then
        cat > "$DROPIN_DIR/isolation.conf" <<'EOF'
[Service]
UMask=0002
EOF
    fi
fi

if [ "$DRY_RUN" = "0" ]; then
    HAS_NGINX=0
    command -v nginx >/dev/null 2>&1 && HAS_NGINX=1
    if [ "$HAS_NGINX" = "1" ]; then
        nginx -t >/dev/null 2>&1 || { echo "[ERROR] nginx -t gagal, periksa konfigurasi." >&2; nginx -t; exit 1; }
    fi
    php_fpm_bin="$(grep -oE '/usr/sbin/php-fpm[0-9.]+' /etc/systemd/system/mypanel-web.service 2>/dev/null | head -1)"
    if [ -n "$php_fpm_bin" ]; then
        "$php_fpm_bin" -t -y "$PANEL_ROOT/config/panel-fpm.conf" >/dev/null 2>&1 \
            || { echo "[ERROR] Konfigurasi FPM panel tidak valid." >&2; exit 1; }
    fi
    systemctl daemon-reload
    # Reload (tanpa memutus koneksi); restart hanya jika worker belum berganti user
    if [ "$HAS_NGINX" = "1" ] && systemctl is-active --quiet nginx; then
        systemctl reload nginx
        sleep 2
        if ps -eo user,cmd | grep "nginx: worker" | grep -v grep | grep -qv "^${NGINX_USER} "; then
            systemctl restart nginx
        fi
    fi
    systemctl restart mypanel-web
    # Worker di-restart terakhir (bisa jadi skrip ini dijalankan dari worker itu sendiri)
    if [ -z "${ALMUSA_TASK_CONTEXT:-}" ]; then
        systemctl restart mypanel-worker
    fi
fi

echo ""
echo "[SUCCESS] Isolasi selesai. Cadangan konfigurasi: $BACKUP_ROOT"
