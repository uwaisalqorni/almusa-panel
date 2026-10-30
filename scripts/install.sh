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
echo -e "${BLUE}[2/7] Menginstal dependensi inti engine panel (Git, Curl, SQLite3, PHP)...${NC}"
if [ "$OS" = "ubuntu" ] && [ -f /etc/apt/sources.list.d/php.list ]; then
    if grep -q "packages.sury.org" /etc/apt/sources.list.d/php.list 2>/dev/null; then
        rm -f /etc/apt/sources.list.d/php.list
    fi
fi
apt-get update -y || true
DEBIAN_FRONTEND=noninteractive apt-get install -y \
    curl \
    git \
    sqlite3 \
    unzip \
    software-properties-common \
    ca-certificates \
    lsb-release \
    apt-transport-https

# Register multi-version PHP repository (Ondrej Sury - supports Debian & Ubuntu)
echo -e "${BLUE}[*] Mendaftarkan repositori multi-versi PHP (Ondrej Sury)...${NC}"
CODENAME="${VERSION_CODENAME:-}"
if [ -z "$CODENAME" ]; then
    CODENAME="$(lsb_release -sc 2>/dev/null || echo 'noble')"
fi

if [ "$OS" = "ubuntu" ]; then
    rm -f /etc/apt/sources.list.d/php.list
    add-apt-repository -y ppa:ondrej/php 2>/dev/null || true
else
    mkdir -p /usr/share/keyrings
    if [ ! -s /usr/share/keyrings/debsuryorg-archive-keyring.gpg ]; then
        curl -sSL https://packages.sury.org/php/apt.gpg -o /usr/share/keyrings/debsuryorg-archive-keyring.gpg || true
    fi
    echo "deb [signed-by=/usr/share/keyrings/debsuryorg-archive-keyring.gpg] https://packages.sury.org/php/ ${CODENAME} main" > /etc/apt/sources.list.d/php.list
fi
apt-get update -y || true

# Kunci engine inti panel ke PHP 8.3 LTS (Standar Emas Stabilitas & Kompatibilitas Ekstensi)
PANEL_PHP_VER="8.3"
echo -e "${BLUE}[*] Memasang runtime engine inti panel: PHP ${PANEL_PHP_VER} LTS...${NC}"

DEBIAN_FRONTEND=noninteractive apt-get install -y \
    php${PANEL_PHP_VER}-fpm \
    php${PANEL_PHP_VER}-cli \
    php${PANEL_PHP_VER}-sqlite3 \
    php${PANEL_PHP_VER}-mysql \
    php${PANEL_PHP_VER}-curl \
    php${PANEL_PHP_VER}-mbstring \
    php${PANEL_PHP_VER}-xml \
    php${PANEL_PHP_VER}-zip || \
DEBIAN_FRONTEND=noninteractive apt-get install -y \
    php-fpm php-cli php-sqlite3 php-mysql php-curl php-mbstring php-xml php-zip || true

# Tentukan binary PHP-FPM dan PHP-CLI untuk Panel Engine (Terkunci ke PHP 8.3 LTS)
PHP_FPM_BIN=$(command -v php-fpm8.3 || command -v php-fpm || echo "/usr/sbin/php-fpm8.3")
PHP_CLI_BIN=$(command -v php8.3 || command -v php || echo "/usr/bin/php8.3")
echo -e "${BLUE}[*] Menggunakan PHP-FPM binary: ${PHP_FPM_BIN}${NC}"
echo -e "${BLUE}[*] Menggunakan PHP CLI binary : ${PHP_CLI_BIN}${NC}"

# 4. Setup Isolated Directory Structure under /www
echo -e "${BLUE}[3/7] Menyiapkan struktur direktori /www...${NC}"
mkdir -p /www/server /www/wwwroot /www/backup /www/panel /www/panel/data /run/php

# Resolve source directory reliably (pipe-safe)
SCRIPT_SOURCE="${BASH_SOURCE[0]:-}"
SOURCE_DIR=""

if [ -n "$SCRIPT_SOURCE" ] && [ -f "$SCRIPT_SOURCE" ]; then
    CANDIDATE_DIR="$( cd -P "$( dirname "$SCRIPT_SOURCE" )/.." >/dev/null 2>&1 && pwd )"
    if [ -f "$CANDIDATE_DIR/app/bootstrap.php" ]; then
        SOURCE_DIR="$CANDIDATE_DIR"
    fi
fi

if [ -z "$SOURCE_DIR" ]; then
    if [ -f "$(pwd)/app/bootstrap.php" ]; then
        SOURCE_DIR="$(pwd)"
    elif [ -f "/www/panel/app/bootstrap.php" ]; then
        SOURCE_DIR="/www/panel"
    fi
fi

# If SOURCE_DIR still cannot be determined (e.g. piped via curl directly on a fresh server)
if [ -z "$SOURCE_DIR" ] || [ ! -f "$SOURCE_DIR/app/bootstrap.php" ]; then
    echo -e "${BLUE}[*] Mengunduh source code Almusa Panel dari GitHub...${NC}"
    TMP_CLONE="/tmp/almusa-panel-src-$$"
    rm -rf "$TMP_CLONE"
    git clone --depth=1 https://github.com/uwaisalqorni/almusa-panel.git "$TMP_CLONE"
    SOURCE_DIR="$TMP_CLONE"
fi

# Copy files to /www/panel if not already located there
if [ -L /www/panel ] && [ "$(readlink -f /www/panel)" = "$SOURCE_DIR" ]; then
    echo -e "${GREEN}[✔] /www/panel sudah terhubung ke ${SOURCE_DIR}.${NC}"
elif [ "$SOURCE_DIR" != "/www/panel" ]; then
    echo -e "${BLUE}[*] Memasang file aplikasi ke /www/panel...${NC}"
    mkdir -p /www/panel
    cp -rf "$SOURCE_DIR"/* /www/panel/
    if [[ "$SOURCE_DIR" == /tmp/almusa-panel-src-* ]]; then
        rm -rf "$SOURCE_DIR"
    fi
fi

chmod +x /www/panel/cli.sh 2>/dev/null || true
chmod +x /www/panel/scripts/*.sh 2>/dev/null || true
find /www/panel/plugins -name "*.sh" -exec chmod +x {} + 2>/dev/null || true

# 5. Initialize Database & Generate Credentials
echo -e "${BLUE}[4/7] Menginisialisasi basis data SQLite dan Security Entrance...${NC}"
cd /www/panel
${PHP_CLI_BIN} -r "
define('PANEL_ROOT', '/www/panel');
require_once '/www/panel/app/bootstrap.php';
\App\Core\Database::init();
\App\Core\Database::autoMigrate();
"

# Set standard permissions for /www/panel and data directory
chown -R www-data:www-data /www/panel
chmod -R 755 /www/panel
chmod 2775 /www/panel/data
chmod 664 /www/panel/data/* 2>/dev/null || true

# 6. Configure Nginx Virtual Host and Dedicated PHP-FPM for Panel
echo -e "${BLUE}[5/7] Menyiapkan Nginx & Dedicated PHP-FPM untuk Almusa Panel...${NC}"

# Pastikan nginx terpasang dan aktif
if ! command -v nginx >/dev/null 2>&1; then
    echo -e "${BLUE}[*] Memasang Nginx...${NC}"
    DEBIAN_FRONTEND=noninteractive apt-get install -y nginx || true
fi

systemctl enable nginx 2>/dev/null || true
systemctl start nginx 2>/dev/null || true

PORT=$(${PHP_CLI_BIN} -r "
define('PANEL_ROOT', '/www/panel');
require_once '/www/panel/app/bootstrap.php';
echo \App\Core\Database::getSetting('panel_port', '64448');
")

# Pastikan direktori config dan socket ada
mkdir -p /www/panel/config /run/php /www/panel/public/adminer
chown -R www-data:www-data /run/php
chmod 755 /run/php
if [ ! -f /www/panel/public/adminer/adminer-core.php ]; then
    curl -fsSL https://github.com/vrana/adminer/releases/download/v4.8.1/adminer-4.8.1-mysql.php -o /www/panel/public/adminer/adminer-core.php 2>/dev/null || true
fi

# Sesuaikan port pada konfigurasi Nginx panel jika sudah ada, atau buat jika belum ada
if [ -f /www/panel/config/nginx-panel.conf ]; then
    sed -i -E "s/listen [0-9]+/listen ${PORT}/g" /www/panel/config/nginx-panel.conf
else
    cat << EOF > /www/panel/config/nginx-panel.conf
server {
    listen ${PORT} default_server;
    listen [::]:${PORT} default_server;
    server_name _;

    root /www/panel/public;
    index index.php index.html;

    charset utf-8;
    server_tokens off;

    access_log /var/log/nginx/almusa_access.log;
    error_log /var/log/nginx/almusa_error.log warn;

    add_header X-Frame-Options "SAMEORIGIN" always;
    add_header X-XSS-Protection "1; mode=block" always;
    add_header X-Content-Type-Options "nosniff" always;
    add_header Referrer-Policy "strict-origin-when-cross-origin" always;

    client_max_body_size 50M;

    location ~* \.(jpg|jpeg|gif|png|webp|svg|ico|css|js|woff|woff2|ttf|eot)$ {
        expires 30d;
        access_log off;
        add_header Cache-Control "public, no-transform";
        try_files \$uri =404;
    }

    location / {
        try_files \$uri \$uri/ /index.php?\$query_string;
    }

    location ~ \.php$ {
        fastcgi_split_path_info ^(.+\.php)(/.+)$;
        fastcgi_pass unix:/run/php/almusa-panel.sock;
        fastcgi_index index.php;
        fastcgi_param SCRIPT_FILENAME \$realpath_root\$fastcgi_script_name;
        include fastcgi_params;
        fastcgi_buffers 16 16k;
        fastcgi_buffer_size 32k;
        fastcgi_read_timeout 300;
        fastcgi_intercept_errors off;
    }

    location ~ /\.(?!well-known) {
        deny all;
    }

    location ^~ /data/ {
        deny all;
    }
}
EOF
fi

# Link ke sites Nginx & conf.d
mkdir -p /etc/nginx/sites-available /etc/nginx/sites-enabled /etc/nginx/conf.d
ln -sf /www/panel/config/nginx-panel.conf /etc/nginx/sites-available/almusa-panel.conf
ln -sf /etc/nginx/sites-available/almusa-panel.conf /etc/nginx/sites-enabled/almusa-panel.conf
if [ -f /www/panel/config/cloudflare-realip.conf ]; then
    ln -sf /www/panel/config/cloudflare-realip.conf /etc/nginx/conf.d/cloudflare-realip.conf
fi

# Hak eksekusi vhost_manager.sh untuk user worker/panel tanpa password
PANEL_USER=$(stat -c '%U' /www/panel 2>/dev/null || echo "www-data")
if [ "$PANEL_USER" = "root" ]; then
    PANEL_USER="www-data"
fi
if [ "$PANEL_USER" != "root" ]; then
    echo "${PANEL_USER} ALL=(ALL) NOPASSWD: /www/panel/scripts/vhost_manager.sh, $(readlink -f /www/panel)/scripts/vhost_manager.sh" > /etc/sudoers.d/almusa-panel
    chmod 0440 /etc/sudoers.d/almusa-panel
fi

# Daftarkan dedicated PHP-FPM service untuk panel
cat << EOF > /etc/systemd/system/mypanel-web.service
[Unit]
Description=Almusa Server Control Panel (Dedicated PHP-FPM Service)
After=network.target

[Service]
Type=simple
User=root
RuntimeDirectory=php
RuntimeDirectoryMode=0755
ExecStart=${PHP_FPM_BIN} -F -y /www/panel/config/panel-fpm.conf
ExecReload=/bin/kill -USR2 \$MAINPID
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
ExecStart=${PHP_CLI_BIN} /www/panel/task.php
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
nginx -t && (systemctl reload nginx 2>/dev/null || systemctl restart nginx 2>/dev/null || true)

# Buka port di firewall jika UFW aktif
if command -v ufw >/dev/null 2>&1; then
    if ufw status 2>/dev/null | grep -q "Status: active"; then
        echo -e "${BLUE}[*] Membuka port listen panel (${PORT}/tcp) di UFW...${NC}"
        ufw allow "${PORT}/tcp" >/dev/null 2>&1 || true
        ufw reload >/dev/null 2>&1 || true
    fi
fi

# 7. Symlink global command 'mypanel' & 'almusa'
echo -e "${BLUE}[6/7] Membuat symlink CLI /usr/local/bin/mypanel & /usr/local/bin/almusa...${NC}"
ln -sf /www/panel/cli.sh /usr/local/bin/mypanel
ln -sf /www/panel/cli.sh /usr/local/bin/almusa

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
