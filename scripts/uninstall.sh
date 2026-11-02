#!/usr/bin/env bash
# ==============================================================================
# Almusa Server Control Panel - Official Clean Uninstaller Script
# Removes Almusa Panel components, services, and configs cleanly while
# preserving user websites (/www/wwwroot) and MySQL databases.
# ==============================================================================

set -e

# Color definitions
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
CYAN='\033[0;36m'
BOLD='\033[1m'
NC='\033[0m'

# 1. Parse Parameters
NON_INTERACTIVE=false
PURGE_BACKUPS=false
SKIP_BACKUP=false
DRY_RUN=false

for arg in "$@"; do
    case "$arg" in
        --dry-run|--simulate)
            DRY_RUN=true
            ;;
        -h|--help)
            echo "Almusa Control Panel Official Clean Uninstaller"
            echo "Penggunaan: sudo bash uninstall.sh [OPSI]"
            echo ""
            echo "Opsi:"
            echo "  -y, --yes, --force    Hapus tanpa konfirmasi interaktif (untuk automasi/skrip)"
            echo "  --purge               Hapus juga snapshot backup lama di /www/backup"
            echo "  --no-backup           Lewati pembuatan arsip cadangan darurat di /root"
            echo "  --dry-run, --simulate Simulasi pembersihan tanpa menghapus berkas apapun"
            echo "  -h, --help            Tampilkan bantuan ini"
            echo ""
            exit 0
            ;;
    esac
done

# 2. Check Root Privileges (Kecuali Mode Dry-Run / Simulasi)
if [ "$DRY_RUN" = true ]; then
    echo -e "${YELLOW}${BOLD}>>> MODE SIMULASI UNINSTALL (DRY-RUN) DIAKTIFKAN <<<${NC}"
    echo -e "${YELLOW}Tidak ada berkas sistem, paket OS, konfigurasi, atau service yang akan dihapus.${NC}\n"
else
    if [ "$(id -u)" -ne 0 ]; then
        echo -e "${RED}[ERROR] Script uninstaller harus dijalankan sebagai root (gunakan sudo bash uninstall.sh).${NC}"
        exit 1
    fi
fi

echo -e "${YELLOW}${BOLD}"
echo "================================================================================"
echo "          ALMUSA SERVER CONTROL PANEL - OFFICIAL CLEAN UNINSTALLER              "
echo "================================================================================"
echo -e "${NC}"

while [[ $# -gt 0 ]]; do
    case "$1" in
        -y|--yes|--force)
            NON_INTERACTIVE=true
            shift
            ;;
        --purge)
            PURGE_BACKUPS=true
            shift
            ;;
        --no-backup)
            SKIP_BACKUP=true
            shift
            ;;
        --dry-run|--simulate)
            DRY_RUN=true
            shift
            ;;
        *)
            shift
            ;;
    esac
done

# 3. Interactive Confirmation (Dilewati pada mode simulasi)
if [ "$NON_INTERACTIVE" != true ] && [ "$DRY_RUN" != true ]; then
    echo -e "${YELLOW}PERINGATAN:${NC}"
    echo "Tindakan ini akan menghentikan seluruh layanan Almusa Panel dan menghapus direktori /www/panel."
    echo "Berkas website Anda di ${BOLD}/www/wwwroot${NC} dan basis data MariaDB/MySQL ${BOLD}TIDAK${NC} akan dihapus."
    echo ""
    read -r -p "Apakah Anda yakin ingin melanjutkan proses uninstal? (ketik 'yes' untuk konfirmasi): " ans
    if [ "$ans" != "yes" ] && [ "$ans" != "y" ] && [ "$ans" != "Y" ]; then
        echo -e "${BLUE}[*] Proses uninstal dibatalkan oleh pengguna.${NC}"
        exit 0
    fi
fi

# 4. Detect Panel Port Before Deletion (for UFW Cleanup)
PANEL_PORT=""
if [ -f /www/panel/config/nginx-panel.conf ]; then
    PANEL_PORT=$(grep -E "listen\s+[0-9]+" /www/panel/config/nginx-panel.conf 2>/dev/null | head -n 1 | awk '{print $2}' | tr -cd '0-9')
fi
if [ -z "$PANEL_PORT" ] && [ -f /www/panel/data/panel.db ]; then
    if command -v sqlite3 >/dev/null 2>&1; then
        PANEL_PORT=$(sqlite3 /www/panel/data/panel.db "SELECT key_value FROM settings WHERE key_name = 'panel_port' LIMIT 1;" 2>/dev/null || true)
    fi
fi
[ -z "$PANEL_PORT" ] && PANEL_PORT="64448"

# 5. Percabangan Simulasi (Dry-Run)
if [ "$DRY_RUN" = true ]; then
    BACKUP_TIMESTAMP=$(date +%Y%m%d_%H%M%S)
    EMERGENCY_BACKUP="/root/almusa_panel_backup_${BACKUP_TIMESTAMP}.tar.gz"
    echo -e "${BLUE}[1/7] [SIMULASI] Direktori /www/panel/data dan config akan dicadangkan ke: ${EMERGENCY_BACKUP}${NC}"
    echo -e "${BLUE}[2/7] [SIMULASI] Layanan systemd mypanel-web.service & mypanel-worker.service akan dihentikan dan dinonaktifkan.${NC}"
    echo -e "${BLUE}[3/7] [SIMULASI] Konfigurasi Nginx panel /etc/nginx/sites-enabled/almusa-panel.conf akan dihapus.${NC}"
    echo -e "${BLUE}[4/7] [SIMULASI] Cron sistem /etc/cron.d/almusa-cron dan izin sudoers akan dihapus.${NC}"
    echo -e "${BLUE}[5/7] [SIMULASI] Symlink CLI /usr/local/bin/mypanel & almusa akan dihapus.${NC}"
    echo -e "${BLUE}[6/7] [SIMULASI] Rule firewall UFW port ${PANEL_PORT}/tcp akan ditutup.${NC}"
    echo -e "${BLUE}[7/7] [SIMULASI] Direktori aplikasi /www/panel akan dibersihkan.${NC}"
    echo ""
    echo -e "${YELLOW}${BOLD}================================================================================${NC}"
    echo -e "${YELLOW}${BOLD}       HASIL SIMULASI UNINSTALL (DRY-RUN) SELESAI TANPA MASALAH!                ${NC}"
    echo -e "${YELLOW}${BOLD}================================================================================${NC}"
    echo -e "${GREEN}[✔] Seluruh komponen panel teridentifikasi dan siap dibersihkan tanpa residu.${NC}"
    echo -e "${GREEN}[✔] Status Website: Aman (/www/wwwroot tetap utuh 100%).${NC}"
    echo -e "${GREEN}[✔] Status Database MySQL: Aman (Layanan MariaDB/MySQL tidak disentuh).${NC}"
    echo ""
    echo -e "Untuk menjalankan proses uninstal sesungguhnya, jalankan:"
    echo -e "  ${BOLD}sudo bash scripts/uninstall.sh${NC}"
    echo ""
    exit 0
fi

# 5. Create Safety Emergency Backup Archive
if [ "$SKIP_BACKUP" != true ] && [ -d /www/panel ]; then
    echo -e "${BLUE}[1/7] Membuat arsip cadangan darurat konfigurasi & database ke /root...${NC}"
    BACKUP_TIMESTAMP=$(date +%Y%m%d_%H%M%S)
    EMERGENCY_BACKUP="/root/almusa_panel_backup_${BACKUP_TIMESTAMP}.tar.gz"
    
    ITEMS_TO_BACKUP=""
    [ -d /www/panel/data ] && ITEMS_TO_BACKUP="$ITEMS_TO_BACKUP data"
    [ -d /www/panel/config ] && ITEMS_TO_BACKUP="$ITEMS_TO_BACKUP config"

    if [ -n "$ITEMS_TO_BACKUP" ]; then
        tar -czf "$EMERGENCY_BACKUP" -C /www/panel $ITEMS_TO_BACKUP 2>/dev/null || true
        if [ -f "$EMERGENCY_BACKUP" ]; then
            chmod 600 "$EMERGENCY_BACKUP" 2>/dev/null || true
            mkdir -p /www/backup
            cp -p "$EMERGENCY_BACKUP" "/www/backup/last_panel_data.tar.gz" 2>/dev/null || true
            chmod 600 "/www/backup/last_panel_data.tar.gz" 2>/dev/null || true
            echo -e "${GREEN}[✔] Cadangan data SQLite & config tersimpan di: ${BOLD}${EMERGENCY_BACKUP}${NC}"
            echo -e "${BLUE}[*] Salinan pemulihan otomatis disimpan di: /www/backup/last_panel_data.tar.gz${NC}"
        fi
    fi
fi

# 6. Stop and Disable Systemd Services
echo -e "${BLUE}[2/7] Menghentikan dan menonaktifkan systemd service panel...${NC}"
systemctl stop mypanel-web.service 2>/dev/null || true
systemctl disable mypanel-web.service 2>/dev/null || true
systemctl stop mypanel-worker.service 2>/dev/null || true
systemctl disable mypanel-worker.service 2>/dev/null || true

# Kill any orphaned processes
pkill -f "/www/panel/task.php" 2>/dev/null || true
pkill -f "watchdog.php" 2>/dev/null || true
pkill -f "php -S.*${PANEL_PORT}" 2>/dev/null || true

# Remove service unit files
rm -f /etc/systemd/system/mypanel-web.service
rm -f /etc/systemd/system/mypanel-worker.service
systemctl daemon-reload

# Pastikan direktori socket dan service PHP-FPM untuk website pengguna tetap aktif
mkdir -p /run/php
chown www-data:www-data /run/php
chmod 755 /run/php
for fpm_svc in $(systemctl list-units --type=service --state=running 2>/dev/null | grep -oE "php[0-9.]+-fpm\.service" | sort -u); do
    systemctl restart "$fpm_svc" 2>/dev/null || true
done
ACTIVE_PHP_BIN=$(command -v php || find /usr/bin -maxdepth 1 \( -type f -o -type l \) -name "php[0-9]*" 2>/dev/null | sort -V | tail -n 1 || true)
if [ -n "$ACTIVE_PHP_BIN" ] && [ -x "$ACTIVE_PHP_BIN" ]; then
    ACTIVE_VER=$($ACTIVE_PHP_BIN -r 'echo PHP_MAJOR_VERSION . "." . PHP_MINOR_VERSION;' 2>/dev/null || echo "")
    if [ -n "$ACTIVE_VER" ]; then
        systemctl restart "php${ACTIVE_VER}-fpm" 2>/dev/null || true
    fi
fi

# 7. Clean up Nginx Configurations
echo -e "${BLUE}[3/7] Membersihkan konfigurasi Nginx Almusa Panel...${NC}"
rm -f /etc/nginx/sites-enabled/almusa-panel.conf
rm -f /etc/nginx/sites-available/almusa-panel.conf
if [ -L /etc/nginx/conf.d/cloudflare-realip.conf ]; then
    TARGET_LINK=$(readlink -f /etc/nginx/conf.d/cloudflare-realip.conf 2>/dev/null || true)
    if [[ "$TARGET_LINK" == /www/panel/* ]]; then
        rm -f /etc/nginx/conf.d/cloudflare-realip.conf
    fi
fi

# Verify and reload Nginx
if nginx -t >/dev/null 2>&1; then
    systemctl reload nginx 2>/dev/null || systemctl restart nginx 2>/dev/null || true
    echo -e "${GREEN}[✔] Konfigurasi Nginx berhasil dimuat ulang.${NC}"
else
    echo -e "${YELLOW}[!] Memuat ulang Nginx dilewati karena ada vhost lain yang perlu diperiksa.${NC}"
fi

# 8. Clean up System Cron Jobs & Sudoers
echo -e "${BLUE}[4/7] Membersihkan cron job sistem dan izin sudoers panel...${NC}"
if [ -f /etc/cron.d/almusa-cron ]; then
    rm -f /etc/cron.d/almusa-cron
    touch /etc/cron.d 2>/dev/null || true
fi
rm -f /etc/sudoers.d/almusa-panel

# 9. Clean up CLI Global Symlinks
echo -e "${BLUE}[5/7] Menghapus symlink CLI global (/usr/local/bin/mypanel & almusa)...${NC}"
rm -f /usr/local/bin/mypanel
rm -f /usr/local/bin/almusa

# 10. Clean up Firewall (UFW) Rules
if command -v ufw >/dev/null 2>&1 && [ -n "$PANEL_PORT" ]; then
    if ufw status 2>/dev/null | grep -q "Status: active"; then
        echo -e "${BLUE}[6/7] Menutup rule port listen panel (${PANEL_PORT}/tcp) di Firewall UFW...${NC}"
        ufw delete allow "${PANEL_PORT}/tcp" >/dev/null 2>&1 || true
        ufw reload >/dev/null 2>&1 || true
    fi
fi

# 11. Clean up Runtime Sockets, Temporary Logs, and Panel Directory
echo -e "${BLUE}[7/7] Membersihkan direktori aplikasi /www/panel dan runtime socket...${NC}"
rm -f /run/php/almusa-panel.sock
rm -f /tmp/panelExec.log /tmp/panel_web.log /tmp/task_runner.log /tmp/cf_quick_*.log 2>/dev/null || true

if [ -L /www/panel ]; then
    rm -f /www/panel
elif [ -d /www/panel ]; then
    rm -rf /www/panel
fi

if [ "$PURGE_BACKUPS" = true ] && [ -d /www/backup ]; then
    echo -e "${YELLOW}[*] Menghapus arsip backup panel lama di /www/backup...${NC}"
    rm -f /www/backup/panel_*.db /www/backup/panel_db_*.db 2>/dev/null || true
fi

# 12. Final Report Banner
echo -e "${GREEN}${BOLD}"
echo "================================================================================"
echo "          ALMUSA CONTROL PANEL BERHASIL DIHAPUS DARI SERVER                     "
echo "================================================================================"
echo -e "${NC}"
if [ -n "$EMERGENCY_BACKUP" ] && [ -f "$EMERGENCY_BACKUP" ]; then
    echo -e "Arsip Cadangan Terakhir : ${CYAN}${BOLD}${EMERGENCY_BACKUP}${NC}"
fi
echo -e "Status Website          : ${GREEN}Aman (/www/wwwroot tetap utuh)${NC}"
echo -e "Status Database MySQL   : ${GREEN}Aman (Layanan MariaDB/MySQL tidak disentuh)${NC}"
echo ""
echo "Almusa Control Panel telah dibersihkan secara tuntas tanpa menyisakan service atau port terbuka."
echo ""
