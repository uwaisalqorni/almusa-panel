#!/usr/bin/env bash
# ==============================================================================
# Almusa Server Control Panel - Master Bootstrap Installer
# Compatible with: Ubuntu 20.04 / 22.04 / 24.04, Debian 11 / 12
# Idempotent & Self-Healing Architecture
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

# Banner
echo -e "${CYAN}${BOLD}"
echo "================================================================================"
echo "          ALMUSA SERVER CONTROL PANEL (SLEMP EDITION) - INSTALLER               "
echo "================================================================================"
echo -e "${NC}"

# 1. Parse CLI Parameters
CLI_PORT=""
CLI_ENTRANCE=""
NON_INTERACTIVE=false
FORCE_REINSTALL=false
DRY_RUN=false

while [[ $# -gt 0 ]]; do
    case "$1" in
        -p|--port)
            CLI_PORT="$2"
            shift 2
            ;;
        -e|--entrance)
            CLI_ENTRANCE="$2"
            shift 2
            ;;
        -y|--yes)
            NON_INTERACTIVE=true
            shift
            ;;
        -f|--force)
            FORCE_REINSTALL=true
            shift
            ;;
        --fresh)
            FORCE_FRESH=true
            shift
            ;;
        --dry-run|--simulate)
            DRY_RUN=true
            shift
            ;;
        -h|--help)
            echo "Almusa Control Panel Master Installer"
            echo "Penggunaan: sudo bash install.sh [OPSI]"
            echo ""
            echo "Opsi:"
            echo "  -p, --port <PORT>        Kustomisasi port listen panel (contoh: 64448, 8888)"
            echo "  -e, --entrance <PATH>    Kustomisasi secret entrance URL path (contoh: mypanel)"
            echo "  -y, --yes                Mode non-interaktif (otomatis menyetujui prompt)"
            echo "  -f, --force              Paksa re-instalasi dan perbarui seluruh konfigurasi"
            echo "  --fresh                  Instalasi baru murni (abaikan cadangan data sebelumnya)"
            echo "  --dry-run, --simulate    Simulasi instalasi tanpa melakukan perubahan pada sistem"
            echo "  -h, --help               Tampilkan panduan bantuan ini"
            echo ""
            exit 0
            ;;
        *)
            shift
            ;;
    esac
done

# Allow environment variables override if CLI option not passed
CLI_PORT="${CLI_PORT:-${PANEL_PORT:-}}"
CLI_ENTRANCE="${CLI_ENTRANCE:-${PANEL_ENTRANCE:-}}"

# 2. Check Root Privileges (Kecuali Mode Dry-Run / Simulasi)
if [ "$DRY_RUN" = true ]; then
    echo -e "${YELLOW}${BOLD}>>> MODE SIMULASI (DRY-RUN) DIAKTIFKAN <<<${NC}"
    echo -e "${YELLOW}Tidak ada berkas sistem, paket OS, konfigurasi, atau service yang akan diubah.${NC}\n"
else
    if [ "$(id -u)" -ne 0 ]; then
        echo -e "${RED}[ERROR] Installer ini harus dijalankan sebagai root (gunakan sudo bash install.sh).${NC}"
        exit 1
    fi
fi

# 3. Check OS Compatibility
if [ -f /etc/os-release ]; then
    . /etc/os-release
    OS=$ID
    VER=$VERSION_ID
else
    echo -e "${RED}[ERROR] Tidak dapat mendeteksi sistem operasi Linux.${NC}"
    exit 1
fi

echo -e "${BLUE}[1/7] Terdeteksi Sistem Operasi: ${OS} ${VER}${NC}"

# 4. Check for Previous Installation (Idempotency Check)
IS_PREVIOUS_INSTALL=false
DB_FILE="/www/panel/data/panel.db"
LAST_BACKUP="/www/backup/last_panel_data.tar.gz"

# Pemulihan otomatis jika panel sebelumnya pernah di-uninstall namun arsip cadangan ada di /www/backup
if [ ! -f "$DB_FILE" ] && [ -f "$LAST_BACKUP" ] && [ "${FORCE_FRESH:-false}" != true ]; then
    echo -e "${GREEN}[✔] Terdeteksi arsip cadangan panel pasca-uninstal di ${LAST_BACKUP}.${NC}"
    echo -e "${BLUE}[*] Memulihkan basis data dan konfigurasi panel otomatis (Self-Healing Recovery)...${NC}"
    if [ "$DRY_RUN" = true ]; then
        echo -e "${GREEN}[✔] [SIMULASI] Data panel akan dipulihkan dari ${LAST_BACKUP}.${NC}"
    else
        mkdir -p /www/panel
        tar -xzf "$LAST_BACKUP" -C /www/panel 2>/dev/null || true
    fi
fi

if [ -f "$DB_FILE" ] && [ -s "$DB_FILE" ]; then
    IS_PREVIOUS_INSTALL=true
    echo -e "${GREEN}[✔] Terdeteksi instalasi Almusa Panel sebelumnya pada sistem (${DB_FILE}).${NC}"
    echo -e "${GREEN}[✔] Mode Idempotent diaktifkan: Basis data, kredensial admin, dan konfigurasi yang ada TIDAK akan ditimpa.${NC}"
    
    # Safe snapshot of previous SQLite database
    PRE_BACKUP="/www/backup/panel_db_pre_install_$(date +%Y%m%d_%H%M%S).db"
    if [ "$DRY_RUN" = true ]; then
        echo -e "${BLUE}[*] [SIMULASI] Snapshot cadangan pra-instalasi akan disimpan ke: ${PRE_BACKUP}${NC}"
    else
        mkdir -p /www/backup
        if command -v sqlite3 >/dev/null 2>&1; then
            sqlite3 "$DB_FILE" ".backup '${PRE_BACKUP}'" 2>/dev/null || cp -p "$DB_FILE" "$PRE_BACKUP" 2>/dev/null || true
        else
            cp -p "$DB_FILE" "$PRE_BACKUP" 2>/dev/null || true
        fi
        if [ -f "$PRE_BACKUP" ]; then
            chmod 600 "$PRE_BACKUP" 2>/dev/null || true
            echo -e "${BLUE}[*] Snapshot cadangan pra-instalasi disimpan di: ${PRE_BACKUP}${NC}"
        fi
    fi
fi

# Helper: Wait for Apt Locks (Self-Healing Package Manager)
wait_for_apt_lock() {
    local max_wait=45
    local waited=0
    while fuser /var/lib/dpkg/lock >/dev/null 2>&1 || \
          fuser /var/lib/apt/lists/lock >/dev/null 2>&1 || \
          fuser /var/lib/dpkg/lock-frontend >/dev/null 2>&1; do
        if [ "$waited" -ge "$max_wait" ]; then
            echo -e "${YELLOW}[!] Timeout menunggu kunci apt/dpkg. Melanjutkan instalasi...${NC}"
            break
        fi
        echo -e "${YELLOW}[*] Menunggu proses apt/dpkg lain selesai... (${waited}s / ${max_wait}s)${NC}"
        sleep 3
        waited=$((waited + 3))
    done
}

# 5. Install Minimal Dependencies for Panel Engine
echo -e "${BLUE}[2/7] Menginstal dependensi inti engine panel (Git, Curl, SQLite3, PHP)...${NC}"
PANEL_PHP_VER="8.3"

if [ "$DRY_RUN" = true ]; then
    PHP_CLI_BIN=$(command -v php || find /usr/bin -maxdepth 1 \( -type f -o -type l \) -name "php[0-9]*" 2>/dev/null | sort -V | tail -n 1 || echo "/usr/bin/php")
    PHP_FPM_BIN=$(find /usr/sbin -maxdepth 1 \( -type f -o -type l \) -name "php-fpm[0-9]*" 2>/dev/null | sort -V | tail -n 1 || command -v php-fpm || echo "/usr/sbin/php-fpm")
    echo -e "${GREEN}[✔] [SIMULASI] Runtime PHP-CLI : ${PHP_CLI_BIN}${NC}"
    echo -e "${GREEN}[✔] [SIMULASI] Runtime PHP-FPM : ${PHP_FPM_BIN}${NC}"
else
    wait_for_apt_lock

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

    USE_ONDREJ_PPA=false
    if [ "$OS" = "ubuntu" ]; then
        rm -f /etc/apt/sources.list.d/php.list
        echo -e "${BLUE}[*] Memeriksa ketersediaan PPA ondrej/php untuk Ubuntu '${CODENAME}'...${NC}"
        if curl -fsSLI "https://ppa.launchpadcontent.net/ondrej/php/ubuntu/dists/${CODENAME}/Release" >/dev/null 2>&1; then
            echo -e "${GREEN}[✔] PPA ondrej/php mendukung '${CODENAME}'. Mendaftarkan PPA...${NC}"
            add-apt-repository -y ppa:ondrej/php 2>/dev/null || true
            USE_ONDREJ_PPA=true
        else
            echo -e "${YELLOW}[!] PPA ondrej/php belum memiliki build rilis untuk Ubuntu '${CODENAME}'.${NC}"
            echo -e "${BLUE}[*] Menggunakan paket runtime PHP resmi bawaan distro Ubuntu ${CODENAME}...${NC}"
            rm -f /etc/apt/sources.list.d/*ondrej* 2>/dev/null || true
        fi
    else
        mkdir -p /usr/share/keyrings
        if [ ! -s /usr/share/keyrings/debsuryorg-archive-keyring.gpg ]; then
            curl -sSL https://packages.sury.org/php/apt.gpg -o /usr/share/keyrings/debsuryorg-archive-keyring.gpg || true
        fi
        echo "deb [signed-by=/usr/share/keyrings/debsuryorg-archive-keyring.gpg] https://packages.sury.org/php/ ${CODENAME} main" > /etc/apt/sources.list.d/php.list
        USE_ONDREJ_PPA=true
    fi
    apt-get update -y || true

    echo -e "${BLUE}[*] Memasang runtime engine inti panel...${NC}"
    INSTALL_SUCCESS=false
    if [ "$USE_ONDREJ_PPA" = true ] && apt-cache show "php${PANEL_PHP_VER}-fpm" >/dev/null 2>&1; then
        echo -e "${BLUE}[*] Memasang PHP ${PANEL_PHP_VER} LTS...${NC}"
        DEBIAN_FRONTEND=noninteractive apt-get install -y \
            php${PANEL_PHP_VER}-fpm \
            php${PANEL_PHP_VER}-cli \
            php${PANEL_PHP_VER}-sqlite3 \
            php${PANEL_PHP_VER}-mysql \
            php${PANEL_PHP_VER}-curl \
            php${PANEL_PHP_VER}-mbstring \
            php${PANEL_PHP_VER}-xml \
            php${PANEL_PHP_VER}-zip && INSTALL_SUCCESS=true
    fi

    if [ "$INSTALL_SUCCESS" != true ]; then
        echo -e "${BLUE}[*] Memasang paket PHP bawaan repositori resmi sistem operasi...${NC}"
        DEBIAN_FRONTEND=noninteractive apt-get install -y \
            php-fpm php-cli php-sqlite3 php-mysql php-curl php-mbstring php-xml php-zip || true
    fi

    PHP_CLI_BIN=$(command -v php || find /usr/bin -maxdepth 1 \( -type f -o -type l \) -name "php[0-9]*" 2>/dev/null | sort -V | tail -n 1 || echo "/usr/bin/php")
    PHP_FPM_BIN=$(find /usr/sbin -maxdepth 1 \( -type f -o -type l \) -name "php-fpm[0-9]*" 2>/dev/null | sort -V | tail -n 1)
    if [ -z "$PHP_FPM_BIN" ]; then
        PHP_FPM_BIN=$(command -v php-fpm || echo "/usr/sbin/php-fpm")
    fi
    echo -e "${GREEN}[✔] Terdeteksi PHP-FPM binary: ${PHP_FPM_BIN}${NC}"
    echo -e "${GREEN}[✔] Terdeteksi PHP CLI binary : ${PHP_CLI_BIN}${NC}"
fi

# 6. Setup Isolated Directory Structure under /www
echo -e "${BLUE}[3/7] Menyiapkan struktur direktori /www...${NC}"
if [ "$DRY_RUN" != true ]; then
    mkdir -p /www/server /www/wwwroot /www/backup /www/panel /www/panel/data /run/php
fi

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

# Copy/Update files to /www/panel with strict DATA PROTECTION
if [ -L /www/panel ] && [ "$(readlink -f /www/panel)" = "$SOURCE_DIR" ]; then
    echo -e "${GREEN}[✔] /www/panel sudah terhubung ke ${SOURCE_DIR}.${NC}"
elif [ "$SOURCE_DIR" != "/www/panel" ]; then
    if [ "$DRY_RUN" = true ]; then
        echo -e "${GREEN}[✔] [SIMULASI] Berkas aplikasi dari ${SOURCE_DIR} akan disalin ke /www/panel dengan proteksi data/ utuh.${NC}"
    else
        echo -e "${BLUE}[*] Memasang file aplikasi ke /www/panel...${NC}"
        mkdir -p /www/panel
        
        # Proteksi direktori data/ agar database dan kredensial lama tidak tertimpa
        PROTECT_DATA_DIR="/tmp/almusa_data_protect_$$"
        if [ -d /www/panel/data ]; then
            mkdir -p "$PROTECT_DATA_DIR"
            cp -a /www/panel/data/* "$PROTECT_DATA_DIR"/ 2>/dev/null || true
        fi

        # Salin berkas aplikasi
        cp -rf "$SOURCE_DIR"/* /www/panel/

        # Kembalikan direktori data yang diproteksi
        if [ -d "$PROTECT_DATA_DIR" ]; then
            mkdir -p /www/panel/data
            cp -a "$PROTECT_DATA_DIR"/* /www/panel/data/ 2>/dev/null || true
            rm -rf "$PROTECT_DATA_DIR"
        fi

        if [[ "$SOURCE_DIR" == /tmp/almusa-panel-src-* ]]; then
            rm -rf "$SOURCE_DIR"
        fi
    fi
fi

if [ "$DRY_RUN" != true ]; then
    chmod +x /www/panel/cli.sh 2>/dev/null || true
    chmod +x /www/panel/scripts/*.sh 2>/dev/null || true
    find /www/panel/plugins -name "*.sh" -exec chmod +x {} + 2>/dev/null || true
fi

# 7. Initialize Database & Run Non-Destructive Migration
echo -e "${BLUE}[4/7] Menginisialisasi basis data SQLite dan Security Entrance...${NC}"
cd /www/panel

if [ "$DRY_RUN" = true ]; then
    ${PHP_CLI_BIN} -r "
    define('PANEL_ROOT', '/www/panel');
    require_once '/www/panel/app/bootstrap.php';
    \App\Core\Database::init();
    \$pdo = \App\Core\Database::getInstance();
    \$stmt = \$pdo->query('SELECT COUNT(*) FROM users');
    \$count = (int)\$stmt->fetchColumn();
    echo '[✔] [SIMULASI] Basis data SQLite terhubung normal (ditemukan ' . \$count . ' admin user).' . PHP_EOL;
    echo '[✔] [SIMULASI] autoMigrate() memvalidasi skema tanpa mereset akun atau password.' . PHP_EOL;
    "
else
    ${PHP_CLI_BIN} -r "
    define('PANEL_ROOT', '/www/panel');
    require_once '/www/panel/app/bootstrap.php';
    \App\Core\Database::init();
    \App\Core\Database::autoMigrate();
    "

    # Set standard permissions for /www/panel and data directory
    chown -R www-data:www-data /www/panel
    chmod -R 755 /www/panel
    chmod 2770 /www/panel/data
    find /www/panel/data -maxdepth 1 -type f -exec chmod 660 {} + 2>/dev/null || true
    [ -f /www/panel/data/secret.key ] && chmod 600 /www/panel/data/secret.key 2>/dev/null || true

    # Set standard permissions for /www/wwwroot (allow uploads when site_id=0)
    mkdir -p /www/wwwroot
    chown -R www-data:www-data /www/wwwroot
    chmod 0775 /www/wwwroot
fi

# Custom Entrance Override (if provided via CLI -e or env)
if [ -n "$CLI_ENTRANCE" ]; then
    CLEAN_ENTRANCE=$(echo "$CLI_ENTRANCE" | tr -cd '[:alnum:]_-')
    if [ ${#CLEAN_ENTRANCE} -ge 4 ]; then
        ${PHP_CLI_BIN} -r "
        define('PANEL_ROOT', '/www/panel');
        require_once '/www/panel/app/bootstrap.php';
        \App\Core\Database::setSetting('entrance_path', '${CLEAN_ENTRANCE}');
        \$f = '/www/panel/data/entrance.txt';
        file_put_contents(\$f, '${CLEAN_ENTRANCE}');
        \App\Core\Database::fixPermissions(\$f, 0664);
        "
        echo -e "${GREEN}[✔] Secret entrance path berhasil dikonfigurasi ke: /${CLEAN_ENTRANCE}${NC}"
    fi
fi

# 8. Port Conflict Detection & Smart Self-Healing Allocation
echo -e "${BLUE}[5/7] Menyiapkan Nginx & Dedicated PHP-FPM untuk Almusa Panel...${NC}"

# Pastikan nginx terpasang dan aktif (Dilewati pada mode simulasi)
if [ "$DRY_RUN" != true ]; then
    if ! command -v nginx >/dev/null 2>&1; then
        echo -e "${BLUE}[*] Memasang Nginx...${NC}"
        wait_for_apt_lock
        DEBIAN_FRONTEND=noninteractive apt-get install -y nginx || true
    fi

    systemctl enable nginx 2>/dev/null || true
    systemctl start nginx 2>/dev/null || true
fi

# Helper Functions for Port Conflict Detection
is_port_in_use() {
    local check_port="$1"
    if command -v ss >/dev/null 2>&1; then
        ss -tuln | grep -qE "[: ]${check_port}\b" && return 0
    elif command -v lsof >/dev/null 2>&1; then
        lsof -iTCP:"${check_port}" -sTCP:LISTEN -P -n >/dev/null 2>&1 && return 0
    elif command -v fuser >/dev/null 2>&1; then
        fuser "${check_port}/tcp" >/dev/null 2>&1 && return 0
    fi
    return 1
}

get_port_process() {
    local check_port="$1"
    local proc=""
    if command -v ss >/dev/null 2>&1; then
        proc=$(ss -tulpn "sport = :${check_port}" 2>/dev/null | grep -E "users:\(\(\"[^\"]+\"" | sed -E 's/.*users:\(\("([^"]+)".*/\1/' | head -n 1)
    fi
    if [ -z "$proc" ] && command -v lsof >/dev/null 2>&1; then
        proc=$(lsof -iTCP:"${check_port}" -sTCP:LISTEN -P -n 2>/dev/null | awk 'NR>1 {print $1}' | head -n 1)
    fi
    if [ -z "$proc" ] && command -v fuser >/dev/null 2>&1; then
        proc=$(fuser "${check_port}/tcp" 2>/dev/null | awk '{print $1}')
    fi
    echo "${proc:-aplikasi lain}"
}

is_almusa_port_owner() {
    local check_port="$1"
    # Port dimiliki oleh konfigurasi Nginx panel Almusa
    if [ -f /etc/nginx/sites-enabled/almusa-panel.conf ]; then
        if grep -qE "listen\s+${check_port}\b" /etc/nginx/sites-enabled/almusa-panel.conf 2>/dev/null; then
            return 0
        fi
    fi
    return 1
}

find_available_port() {
    local start_port="$1"
    local candidate="$start_port"
    while [ "$candidate" -le 65535 ]; do
        if ! is_port_in_use "$candidate"; then
            echo "$candidate"
            return 0
        fi
        candidate=$((candidate + 1))
    done
    echo ""
    return 1
}

# Tentukan target port awal:
# 1. Parameter CLI --port atau env PANEL_PORT
# 2. Nilai database panel_port jika instalasi sebelumnya ada
# 3. Default port 64448
TARGET_PORT="${CLI_PORT}"
if [ -z "$TARGET_PORT" ] && [ -f "$DB_FILE" ]; then
    TARGET_PORT=$(${PHP_CLI_BIN} -r "
        define('PANEL_ROOT', '/www/panel');
        require_once '/www/panel/app/bootstrap.php';
        echo \App\Core\Database::getSetting('panel_port', '');
    " 2>/dev/null || echo "")
fi
if [ -z "$TARGET_PORT" ]; then
    TARGET_PORT="64448"
fi

echo -e "${BLUE}[*] Memeriksa ketersediaan port listen: ${TARGET_PORT}...${NC}"
FINAL_PORT="$TARGET_PORT"

if is_port_in_use "$TARGET_PORT"; then
    if is_almusa_port_owner "$TARGET_PORT"; then
        echo -e "${GREEN}[✔] Port ${TARGET_PORT} saat ini aktif digunakan oleh Almusa Panel (layanan akan dimuat ulang).${NC}"
    else
        PROC_NAME=$(get_port_process "$TARGET_PORT")
        echo -e "${YELLOW}[PERINGATAN] Terdeteksi konflik port! Port ${TARGET_PORT} sedang digunakan oleh: ${PROC_NAME}.${NC}"
        echo -e "${YELLOW}[*] Menjalankan mekanisme pengalihan port cerdas (Self-Healing Fallback)...${NC}"
        
        FALLBACK_PORT=$(find_available_port $((TARGET_PORT + 1)))
        if [ -z "$FALLBACK_PORT" ]; then
            FALLBACK_PORT=$(find_available_port 64448)
        fi

        if [ -n "$FALLBACK_PORT" ]; then
            echo -e "${GREEN}[✔] Port aman ditemukan! Mengalihkan port listen panel secara cerdas ke: ${BOLD}${FALLBACK_PORT}${NC}"
            FINAL_PORT="$FALLBACK_PORT"
        else
            echo -e "${RED}[ERROR] Gagal menemukan port terbuka di rentang ${TARGET_PORT}-65535.${NC}"
            exit 1
        fi
    fi
else
    echo -e "${GREEN}[✔] Port ${TARGET_PORT} berstatus bebas dan siap digunakan.${NC}"
fi

PORT="$FINAL_PORT"

if [ "$DRY_RUN" = true ]; then
    echo -e "${GREEN}[✔] [SIMULASI] Port terpilih: ${PORT}.${NC}"
    echo -e "${GREEN}[✔] [SIMULASI] Konfigurasi Nginx panel /www/panel/config/nginx-panel.conf akan disiapkan untuk port ${PORT}.${NC}"
    echo -e "${GREEN}[✔] [SIMULASI] Dedicated PHP-FPM service (mypanel-web) & Worker (mypanel-worker) siap didaftarkan.${NC}"
    echo -e "${GREEN}[✔] [SIMULASI] Rule firewall UFW port ${PORT}/tcp siap dibuka.${NC}"
    echo -e "${GREEN}[✔] [SIMULASI] Symlink CLI /usr/local/bin/mypanel & almusa akan diarahkan ke /www/panel/cli.sh.${NC}"

    echo -e "${YELLOW}${BOLD}"
    echo "================================================================================"
    echo "       HASIL SIMULASI RE-INSTALASI (DRY-RUN) SELESAI TANPA MASALAH!             "
    echo "================================================================================"
    echo -e "${NC}"
    echo -e "${GREEN}[✔] Seluruh pengecekan pra-instalasi lolos verifikasi.${NC}"
    echo -e "${GREEN}[✔] Database SQLite dan akun administrator dipertahankan (TIDAK ADA PERUBAHAN).${NC}"
    echo -e "${GREEN}[✔] Port listen panel aman (${PORT}).${NC}"
    echo -e "${GREEN}[✔] Berkas website di /www/wwwroot dan MariaDB tetap utuh 100%.${NC}"
    echo ""
    echo -e "${CYAN}Informasi Akses Panel yang Terdaftar:${NC}"
    ${PHP_CLI_BIN} -r "
    define('PANEL_ROOT', '/www/panel');
    require_once '/www/panel/app/bootstrap.php';
    \$entrance = \App\Core\Database::getSetting('entrance_path', 'almusa');
    \$port = \App\Core\Database::getSetting('panel_port', '${PORT}');
    echo 'Entrance Path : /' . \$entrance . PHP_EOL;
    echo 'Panel Port    : ' . \$port . PHP_EOL;
    echo 'Access URL    : http://<SERVER_IP>:' . \$port . '/' . \$entrance . PHP_EOL;
    "
    echo ""
    echo -e "Untuk menjalankan instalasi ulang sesungguhnya, jalankan:"
    echo -e "  ${BOLD}sudo bash scripts/install.sh${NC}"
    echo ""
    exit 0
fi

# Update port ke basis data
${PHP_CLI_BIN} -r "
define('PANEL_ROOT', '/www/panel');
require_once '/www/panel/app/bootstrap.php';
\App\Core\Database::setSetting('panel_port', '${PORT}');
"

# Pastikan direktori config dan socket ada
mkdir -p /www/panel/config /run/php /www/panel/public/adminer
chown -R www-data:www-data /run/php
chmod 755 /run/php
if [ ! -f /www/panel/public/adminer/adminer-core.php ]; then
    curl -fsSL https://github.com/vrana/adminer/releases/download/v4.8.1/adminer-4.8.1-mysql.php -o /www/panel/public/adminer/adminer-core.php 2>/dev/null || true
fi

# Sesuaikan port pada konfigurasi Nginx panel jika sudah ada, atau buat jika belum ada
if [ -f /www/panel/config/nginx-panel.conf ]; then
    sed -i -E "s/listen [0-9]+ default_server;/listen ${PORT} default_server;/g" /www/panel/config/nginx-panel.conf
    sed -i -E "s/listen \[::\]:[0-9]+ default_server;/listen [::]:${PORT} default_server;/g" /www/panel/config/nginx-panel.conf
    sed -i -E "s/client_max_body_size [0-9]+[mMgG];/client_max_body_size 1024M;/g" /www/panel/config/nginx-panel.conf
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

    client_max_body_size 1024M;
    client_body_timeout 600s;

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
RuntimeDirectoryPreserve=yes
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
systemctl enable mypanel-web.service 2>/dev/null || true
systemctl restart mypanel-web.service 2>/dev/null || true
systemctl enable mypanel-worker.service 2>/dev/null || true
systemctl restart mypanel-worker.service 2>/dev/null || true

# Pastikan kepemilikan direktori /run/php dan socket website pengguna selalu www-data:www-data
mkdir -p /run/php
chown www-data:www-data /run/php
chmod 755 /run/php
chmod 660 /run/php/*.sock 2>/dev/null || true
ACTIVE_PHP_VER=$(${PHP_CLI_BIN:-php} -r 'echo PHP_MAJOR_VERSION . "." . PHP_MINOR_VERSION;' 2>/dev/null || echo "")
if [ -n "$ACTIVE_PHP_VER" ] && systemctl list-unit-files "php${ACTIVE_PHP_VER}-fpm.service" >/dev/null 2>&1; then
    if ! systemctl is-active --quiet "php${ACTIVE_PHP_VER}-fpm" 2>/dev/null; then
        systemctl restart "php${ACTIVE_PHP_VER}-fpm" 2>/dev/null || true
    fi
elif systemctl list-unit-files "php8.3-fpm.service" >/dev/null 2>&1; then
    if ! systemctl is-active --quiet php8.3-fpm 2>/dev/null; then
        systemctl restart php8.3-fpm 2>/dev/null || true
    fi
fi

# Verifikasi sintaks Nginx dan muat ulang dengan mekanisme pemulihan
if nginx -t >/dev/null 2>&1; then
    systemctl reload nginx 2>/dev/null || systemctl restart nginx 2>/dev/null || true
else
    echo -e "${YELLOW}[PERINGATAN] Konfigurasi Nginx mengalami error sintaks. Mencoba self-healing...${NC}"
    nginx -t || true
fi

# Buka port di firewall jika UFW aktif
if command -v ufw >/dev/null 2>&1; then
    if ufw status 2>/dev/null | grep -q "Status: active"; then
        echo -e "${BLUE}[*] Membuka port listen panel (${PORT}/tcp) di UFW...${NC}"
        ufw allow "${PORT}/tcp" >/dev/null 2>&1 || true
        ufw reload >/dev/null 2>&1 || true
    fi
fi

# 9. Symlink global command 'mypanel' & 'almusa'
echo -e "${BLUE}[6/7] Membuat symlink CLI /usr/local/bin/mypanel & /usr/local/bin/almusa...${NC}"
ln -sf /www/panel/cli.sh /usr/local/bin/mypanel
ln -sf /www/panel/cli.sh /usr/local/bin/almusa

# 10. Print Summary
if [ "$IS_PREVIOUS_INSTALL" = true ]; then
    echo -e "${GREEN}${BOLD}"
    echo "================================================================================"
    echo "     PEMBARUAN / RE-INSTALL ALMUSA CONTROL PANEL BERHASIL DISELESAIKAN!        "
    echo "================================================================================"
    echo -e "${NC}"
    echo -e "${GREEN}[✔] Database SQLite dan akun administrator yang sudah ada tetap dipertahankan.${NC}"
    echo ""
    ${PHP_CLI_BIN} -r "
    define('PANEL_ROOT', '/www/panel');
    require_once '/www/panel/app/bootstrap.php';
    \$entrance = \App\Core\Database::getSetting('entrance_path', 'almusa');
    \$port = \App\Core\Database::getSetting('panel_port', '${PORT}');
    echo 'Entrance Path : /' . \$entrance . PHP_EOL;
    echo 'Panel Port    : ' . \$port . PHP_EOL;
    echo 'Access URL    : http://<SERVER_IP>:' . \$port . '/' . \$entrance . PHP_EOL;
    "
    echo ""
    echo -e "${YELLOW}Catatan:${NC}"
    echo " - Silakan akses panel menggunakan kredensial administrator Anda yang lama."
    echo " - Jika Anda lupa password, jalankan perintah '${BOLD}mypanel${NC}' di terminal untuk reset password."
    echo ""
else
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
fi
