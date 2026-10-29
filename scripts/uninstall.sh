#!/usr/bin/env bash
# ==============================================================================
# Almusa Server Control Panel - Uninstaller Script
# ==============================================================================

set -e

RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
BOLD='\033[1m'
NC='\033[0m'

if [ "$(id -u)" -ne 0 ]; then
    echo -e "${RED}[ERROR] Script uninstaller harus dijalankan sebagai root.${NC}"
    exit 1
fi

echo -e "${YELLOW}${BOLD}"
echo "================================================================================"
echo "          ALMUSA SERVER CONTROL PANEL - UNINSTALLER                             "
echo "================================================================================"
echo -e "${NC}"

CONFIRM="${1:-}"
if [ "$CONFIRM" != "-y" ]; then
    read -r -p "PERINGATAN: Apakah Anda yakin ingin menghapus Almusa Control Panel dari server ini? (y/N): " ans
    if [ "$ans" != "y" ] && [ "$ans" != "Y" ]; then
        echo "Pembatalan uninstal."
        exit 0
    fi
fi

echo -e "${BLUE}[1/4] Menghentikan dan menonaktifkan systemd service panel...${NC}"
systemctl stop mypanel-web.service 2>/dev/null || true
systemctl disable mypanel-web.service 2>/dev/null || true
systemctl stop mypanel-worker.service 2>/dev/null || true
systemctl disable mypanel-worker.service 2>/dev/null || true

rm -f /etc/systemd/system/mypanel-web.service
rm -f /etc/systemd/system/mypanel-worker.service
systemctl daemon-reload

echo -e "${BLUE}[2/4] Menghapus symlink CLI & konfigurasi sistem panel...${NC}"
rm -f /usr/local/bin/mypanel /usr/local/bin/almusa
rm -f /etc/sudoers.d/almusa-panel
rm -f /etc/nginx/sites-enabled/almusa-panel.conf /etc/nginx/sites-available/almusa-panel.conf
nginx -t 2>/dev/null && systemctl reload nginx 2>/dev/null || true

echo -e "${BLUE}[3/4] Membuat arsip cadangan data penting ke /root...${NC}"
BACKUP_FILE="/root/almusa_panel_backup_$(date +%Y%m%d_%H%M%S).tar.gz"
if [ -d /www/panel/data ]; then
    tar -czf "$BACKUP_FILE" -C /www/panel data 2>/dev/null || true
    echo -e "${GREEN}[✔] Data SQLite dicadangkan ke: $BACKUP_FILE${NC}"
fi

echo -e "${BLUE}[4/4] Membersihkan direktori aplikasi panel /www/panel...${NC}"
if [ -d /www/panel ]; then
    rm -rf /www/panel
fi

echo -e "${GREEN}${BOLD}"
echo "================================================================================"
echo "          ALMUSA CONTROL PANEL BERHASIL DIHAPUS DARI SERVER                     "
echo "================================================================================"
echo -e "${NC}"
echo "Catatan: File website di /www/wwwroot dan konfigurasi database Anda tetap aman."
echo ""
