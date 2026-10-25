#!/usr/bin/env bash
# ==============================================================================
# Almusa Server Control Panel - Master Bootstrap Installer
# Compatible with: Ubuntu 20.04 / 22.04 / 24.04, Debian 11 / 12
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

echo -e "${CYAN}${BOLD}"
echo "================================================================================"
echo "          ALMUSA SERVER CONTROL PANEL (SLEMP EDITION) - INSTALLER               "
echo "================================================================================"
echo -e "${NC}"

# 1. Check Root Privileges
if [ "$(id -u)" -ne 0 ]; then
    echo -e "${RED}[ERROR] Installer ini harus dijalankan sebagai root (gunakan sudo bash install.sh).${NC}"
    exit 1
fi

# 2. Check OS Compatibility
if [ -f /etc/os-release ]; then
    . /etc/os-release
    OS=$ID
    VER=$VERSION_ID
else
    echo -e "${RED}[ERROR] Tidak dapat mendeteksi sistem operasi Linux.${NC}"
    exit 1
fi

echo -e "${BLUE}[1/7] Terdeteksi Sistem Operasi: ${OS} ${VER}${NC}"

# 3. Install Minimal Dependencies for Panel Engine
echo -e "${BLUE}[2/7] Menginstal dependensi inti engine panel (PHP CLI, SQLite3, Curl)...${NC}"
apt-get update -y
DEBIAN_FRONTEND=noninteractive apt-get install -y \
    curl \
    sqlite3 \
    unzip \
    software-properties-common \
    ca-certificates \
    lsb-release \
    apt-transport-https

# Register multi-version PHP repository (Ondrej Sury - supports Debian & Ubuntu)
echo -e "${BLUE}[*] Mendaftarkan repositori multi-versi PHP (Ondrej Sury)...${NC}"
mkdir -p /usr/share/keyrings
if [ ! -s /usr/share/keyrings/debsuryorg-archive-keyring.gpg ]; then
    curl -sSL https://packages.sury.org/php/apt.gpg -o /usr/share/keyrings/debsuryorg-archive-keyring.gpg || true
fi

CODENAME="${VERSION_CODENAME:-}"
if [ -z "$CODENAME" ]; then
    CODENAME="$(lsb_release -sc 2>/dev/null || echo 'noble')"
fi

echo "deb [signed-by=/usr/share/keyrings/debsuryorg-archive-keyring.gpg] https://packages.sury.org/php/ ${CODENAME} main" > /etc/apt/sources.list.d/php.list

if [ "$OS" = "ubuntu" ] && [[ "$CODENAME" =~ ^(focal|jammy|noble)$ ]]; then
    add-apt-repository -y ppa:ondrej/php 2>/dev/null || true
fi
apt-get update -y || true

# Ensure standalone PHP CLI and sqlite extensions are installed
DEBIAN_FRONTEND=noninteractive apt-get install -y \
    php-cli \
    php-sqlite3 \
    php-curl \
    php-mbstring \
    php-xml || true

# 4. Setup Isolated Directory Structure under /www
echo -e "${BLUE}[3/7] Menyiapkan struktur direktori /www...${NC}"
mkdir -p /www/server /www/wwwroot /www/backup

# Setup /www/panel if not already symlinked or located there
SOURCE_DIR="$( cd -P "$( dirname "${BASH_SOURCE[0]}" )/.." >/dev/null 2>&1 && pwd )"
if [ -L /www/panel ] && [ "$(readlink -f /www/panel)" = "$SOURCE_DIR" ]; then
    echo -e "${GREEN}[✔] /www/panel sudah terhubung ke ${SOURCE_DIR}.${NC}"
elif [ "$SOURCE_DIR" != "/www/panel" ]; then
    echo -e "${BLUE}[*] Menyiapkan tautan /www/panel ke ${SOURCE_DIR}...${NC}"
    mkdir -p /www/panel
    cp -rf "$SOURCE_DIR"/* /www/panel/
fi

chmod 700 /www/panel/data
chmod +x /www/panel/cli.sh
chmod +x /www/panel/scripts/*.sh
find /www/panel/plugins -name "*.sh" -exec chmod +x {} +

# 5. Initialize Database & Generate Credentials
echo -e "${BLUE}[4/7] Menginisialisasi basis data SQLite dan Security Entrance...${NC}"
cd /www/panel
php -r "
define('PANEL_ROOT', '/www/panel');
require_once '/www/panel/app/bootstrap.php';
\App\Core\Database::init();
\App\Core\Database::autoMigrate();
"

# 6. Create Systemd Service Units
echo -e "${BLUE}[5/7] Mendaftarkan systemd service: mypanel-web & mypanel-worker...${NC}"

PORT=$(php -r "
define('PANEL_ROOT', '/www/panel');
require_once '/www/panel/app/bootstrap.php';
echo \App\Core\Database::getSetting('panel_port', '64448');
")

cat << EOF > /etc/systemd/system/mypanel-web.service
[Unit]
Description=Almusa Server Control Panel (Web Interface)
After=network.target

[Service]
Type=simple
User=root
WorkingDirectory=/www/panel
ExecStart=/usr/bin/php -S 0.0.0.0:${PORT} -t /www/panel/public /www/panel/public/index.php
Restart=always
RestartSec=3

[Install]
WantedBy=multi-user.target
EOF

cat << EOF > /etc/systemd/system/mypanel-worker.service
[Unit]
Description=Almusa Server Control Panel (Root Background Task Worker)
After=network.target

[Service]
Type=simple
User=root
WorkingDirectory=/www/panel
ExecStart=/usr/bin/php /www/panel/task.php
Restart=always
RestartSec=3

[Install]
WantedBy=multi-user.target
EOF

systemctl daemon-reload
systemctl enable mypanel-web.service
systemctl restart mypanel-web.service
systemctl enable mypanel-worker.service
systemctl restart mypanel-worker.service

# 7. Symlink global command 'mypanel'
echo -e "${BLUE}[6/7] Membuat symlink CLI /usr/local/bin/mypanel...${NC}"
ln -sf /www/panel/cli.sh /usr/local/bin/mypanel

# 8. Print Summary
echo -e "${GREEN}${BOLD}"
echo "================================================================================"
echo "          INSTALASI ALMUSA CONTROL PANEL BERHASIL DISELESAIKAN!                 "
echo "================================================================================"
echo -e "${NC}"

/www/panel/cli.sh default

echo -e "${YELLOW}Catatan Keamanan:${NC}"
echo " - Akses URL di atas menggunakan browser Anda."
echo " - Anda dapat menjalankan perintah '${BOLD}mypanel${NC}' kapan saja di terminal SSH untuk reset password, ubah port, atau melihat status."
echo ""
