#!/usr/bin/env bash
# ==============================================================================
# Almusa Server Control Panel - Secure Self-Updater Executor
# ==============================================================================
# Performs safe git update, atomic SQLite WAL backup, non-destructive migration,
# permission restoration, and detached background service restarts.
# ==============================================================================

set -euo pipefail

# 1. Resolve Dynamic Panel Root Directory
SOURCE="${BASH_SOURCE[0]}"
while [ -h "$SOURCE" ]; do
  DIR="$( cd -P "$( dirname "$SOURCE" )" >/dev/null 2>&1 && pwd )"
  SOURCE="$(readlink "$SOURCE")"
  [[ $SOURCE != /* ]] && SOURCE="$DIR/$SOURCE"
done
PANEL_ROOT="$( cd -P "$( dirname "$SOURCE" )/.." >/dev/null 2>&1 && pwd )"

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
echo "          ALMUSA SERVER CONTROL PANEL - SELF-UPDATER EXECUTOR                   "
echo "================================================================================"
echo -e "${NC}"
echo "[*] Panel Root Directory: ${PANEL_ROOT}"
echo "[*] Timestamp: $(date '+%Y-%m-%d %H:%M:%S')"
echo ""

# 2. Determine Backup Directory
BACKUP_DIR="/www/backup"
if [ ! -d "$BACKUP_DIR" ] || [ ! -w "$BACKUP_DIR" ]; then
    BACKUP_DIR="${PANEL_ROOT}/backup"
    mkdir -p "$BACKUP_DIR"
fi

TIMESTAMP=$(date '+%Y%m%d_%H%M%S')
DB_FILE="${PANEL_ROOT}/data/panel.db"
DB_BACKUP_FILE="${BACKUP_DIR}/panel_db_backup_${TIMESTAMP}.db"

# 3. Stage 1: Atomic Database Backup (SQLite WAL-safe)
echo -e "${BLUE}[1/5] Membuat cadangan aman database panel (Atomic Backup)...${NC}"
if [ -f "$DB_FILE" ]; then
    if command -v sqlite3 >/dev/null 2>&1; then
        echo "[*] Menjalankan SQLite Online Backup API (.backup)..."
        sqlite3 "$DB_FILE" ".backup '${DB_BACKUP_FILE}'"
    else
        echo "[*] Mengosongkan SQLite WAL buffer via PHP checkpoint..."
        php -r "require_once '${PANEL_ROOT}/app/bootstrap.php'; try { \App\Core\Database::getInstance()->exec('PRAGMA wal_checkpoint(TRUNCATE);'); } catch (\Throwable \$e) {}" || true
        cp -p "$DB_FILE" "$DB_BACKUP_FILE"
        if [ -f "${DB_FILE}-wal" ]; then
            cp -p "${DB_FILE}-wal" "${DB_BACKUP_FILE}-wal" 2>/dev/null || true
        fi
    fi

    # Backup credential files if present
    if [ -f "${PANEL_ROOT}/data/entrance.txt" ]; then
        cp -p "${PANEL_ROOT}/data/entrance.txt" "${BACKUP_DIR}/entrance_${TIMESTAMP}.txt" 2>/dev/null || true
    fi
    if [ -f "${PANEL_ROOT}/data/secret.key" ]; then
        cp -p "${PANEL_ROOT}/data/secret.key" "${BACKUP_DIR}/secret_${TIMESTAMP}.key" 2>/dev/null || true
    fi

    chmod 600 "${DB_BACKUP_FILE}"* 2>/dev/null || true
    echo -e "${GREEN}[✔] Database berhasil dicadangkan ke: ${DB_BACKUP_FILE}${NC}"
else
    echo -e "${YELLOW}[!] Database ${DB_FILE} belum ada. Langkah backup dilewati.${NC}"
fi

# 4. Stage 2: Git Repository Update
echo ""
echo -e "${BLUE}[2/5] Memeriksa repositori Git dan mengunduh kode terbaru...${NC}"
cd "$PANEL_ROOT"

if [ -d "${PANEL_ROOT}/.git" ] && git rev-parse --is-inside-work-tree >/dev/null 2>&1; then
    # Detect active branch
    BRANCH=$(git rev-parse --abbrev-ref HEAD 2>/dev/null || echo "main")
    if [ "$BRANCH" = "HEAD" ] || [ -z "$BRANCH" ]; then
        BRANCH="main"
    fi
    echo "[*] Branch aktif: ${BRANCH}"

    echo "[*] Menjalankan git fetch origin ${BRANCH}..."
    if ! git fetch origin "$BRANCH"; then
        echo -e "${RED}[ERROR] Gagal melakukan git fetch dari remote origin. Periksa koneksi internet.${NC}"
        exit 1
    fi

    echo "[*] Melakukan sinkronisasi file (git reset --hard origin/${BRANCH})..."
    git reset --hard "origin/${BRANCH}"

    # Verify that data/panel.db was NOT destroyed or removed
    if [ -f "$DB_BACKUP_FILE" ]; then
        if [ ! -f "$DB_FILE" ] || [ ! -s "$DB_FILE" ]; then
            echo -e "${YELLOW}[!] Mendeteksi file database terhapus/kosong pasca-reset. Memulihkan dari backup...${NC}"
            cp -p "$DB_BACKUP_FILE" "$DB_FILE"
            chmod 664 "$DB_FILE"
            chown www-data:www-data "$DB_FILE" 2>/dev/null || true
        fi
    fi

    echo -e "${GREEN}[✔] Repositori berhasil diperbarui ke commit: $(git rev-parse --short HEAD)${NC}"
else
    echo -e "${YELLOW}[!] Direktori ini bukan repositori Git aktif (.git tidak ditemukan).${NC}"
    echo -e "${YELLOW}[!] Melewati langkah git pull. Melanjutkan ke migrasi dan optimasi.${NC}"
fi

# 5. Stage 3: Restore File Permissions
echo ""
echo -e "${BLUE}[3/5] Memulihkan hak akses dan izin eksekusi berkas...${NC}"
chmod +x "${PANEL_ROOT}/cli.sh" 2>/dev/null || true
chmod +x "${PANEL_ROOT}/scripts/"*.sh 2>/dev/null || true
find "${PANEL_ROOT}/plugins" -type f -name "*.sh" -exec chmod +x {} + 2>/dev/null || true

if [ -d "${PANEL_ROOT}/data" ]; then
    chown -R www-data:www-data "${PANEL_ROOT}/data" 2>/dev/null || true
    chmod 2775 "${PANEL_ROOT}/data" 2>/dev/null || true
    chmod 664 "${PANEL_ROOT}/data"/* 2>/dev/null || true
fi
echo -e "${GREEN}[✔] Izin berkas berhasil diperbarui.${NC}"

# 6. Stage 4: Non-Destructive Database Auto-Migration & Syntax Verification
echo ""
echo -e "${BLUE}[4/5] Memvalidasi sintaks dan menjalankan migrasi database non-destruktif...${NC}"

# Pre-flight syntax check on critical entry points
if ! php -l "${PANEL_ROOT}/app/bootstrap.php" >/dev/null 2>&1; then
    echo -e "${RED}[ERROR] File app/bootstrap.php mengalami error sintaks!${NC}"
    exit 1
fi
if ! php -l "${PANEL_ROOT}/public/index.php" >/dev/null 2>&1; then
    echo -e "${RED}[ERROR] File public/index.php mengalami error sintaks!${NC}"
    exit 1
fi

php -r "
require_once '${PANEL_ROOT}/app/bootstrap.php';
try {
    \App\Core\Database::autoMigrate();
    echo '[✔] Database autoMigrate selesai tanpa konflik.' . PHP_EOL;
} catch (\Throwable \$e) {
    echo '[ERROR] Gagal menjalankan migrasi: ' . \$e->getMessage() . PHP_EOL;
    exit(1);
}
"

# 7. Stage 5: Detached Background Service Restart
echo ""
echo -e "${BLUE}[5/5] Menyiapkan restart layanan panel di background...${NC}"

# We detach the restart in a background timer so that the running task daemon
# (mypanel-worker / task.php) can cleanly finalize this task record, record exit code 0,
# and close its execution log before being restarted.
(
    sleep 3
    if systemctl is-active --quiet mypanel-web.service 2>/dev/null || [ -f /etc/systemd/system/mypanel-web.service ]; then
        systemctl restart mypanel-web.service 2>/dev/null || true
    fi
    if systemctl is-active --quiet mypanel-worker.service 2>/dev/null || [ -f /etc/systemd/system/mypanel-worker.service ]; then
        systemctl restart mypanel-worker.service 2>/dev/null || true
    fi
) >/dev/null 2>&1 &

echo -e "${GREEN}[✔] Pembaruan Almusa Panel selesai dengan sukses!${NC}"
echo -e "${GREEN}[✔] Layanan panel (web & task worker) akan dimuat ulang dalam 3 detik.${NC}"
echo ""
exit 0
