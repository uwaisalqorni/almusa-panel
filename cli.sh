#!/usr/bin/env bash
# ==============================================================================
# Almusa Server Control Panel - CLI Helper Tool (mypanel)
# ==============================================================================

set -e

# Resolve script root directory
SOURCE="${BASH_SOURCE[0]}"
while [ -h "$SOURCE" ]; do
  DIR="$( cd -P "$( dirname "$SOURCE" )" >/dev/null 2>&1 && pwd )"
  SOURCE="$(readlink "$SOURCE")"
  [[ $SOURCE != /* ]] && SOURCE="$DIR/$SOURCE"
done
PANEL_ROOT="$( cd -P "$( dirname "$SOURCE" )" >/dev/null 2>&1 && pwd )"

# Color definitions
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
CYAN='\033[0;36m'
BOLD='\033[1m'
NC='\033[0m' # No Color

show_banner() {
    echo -e "${CYAN}${BOLD}"
    echo "================================================================"
    echo "            ALMUSA SERVER CONTROL PANEL (SLEMP EDITION)          "
    echo "================================================================"
    echo -e "${NC}"
}

get_server_ip() {
    local ip
    ip=$(curl -s --connect-timeout 2 -4 ifconfig.me || curl -s --connect-timeout 2 -4 api.ipify.org || echo "127.0.0.1")
    echo "$ip"
}

cmd_default() {
    show_banner
    local creds_file="${PANEL_ROOT}/data/initial_credentials.txt"
    if [ -f "$creds_file" ]; then
        cat "$creds_file"
    else
        # Read from database via PHP
        php -r "
        require_once '${PANEL_ROOT}/app/bootstrap.php';
        \$entrance = \App\Core\Database::getSetting('entrance_path', 'almusa');
        \$port = \App\Core\Database::getSetting('panel_port', '64448');
        echo 'Entrance Path : /' . \$entrance . PHP_EOL;
        echo 'Panel Port    : ' . \$port . PHP_EOL;
        echo 'Access URL    : http://<SERVER_IP>:' . \$port . '/' . \$entrance . PHP_EOL;
        "
    fi
    echo ""
}

cmd_start() {
    echo -e "${BLUE}[*] Starting Almusa Panel services...${NC}"
    if systemctl is-active --quiet mypanel-web.service 2>/dev/null; then
        echo -e "${GREEN}[✔] Web service is already running.${NC}"
    else
        systemctl start mypanel-web.service 2>/dev/null || php -S 0.0.0.0:64448 -t "${PANEL_ROOT}/public" "${PANEL_ROOT}/public/index.php" >/dev/null 2>&1 &
        echo -e "${GREEN}[✔] Web service started.${NC}"
    fi

    if systemctl is-active --quiet mypanel-worker.service 2>/dev/null; then
        echo -e "${GREEN}[✔] Worker daemon is already running.${NC}"
    else
        systemctl start mypanel-worker.service 2>/dev/null || nohup php "${PANEL_ROOT}/task.php" >/tmp/task_runner.log 2>&1 &
        echo -e "${GREEN}[✔] Worker daemon started.${NC}"
    fi
}

cmd_stop() {
    echo -e "${YELLOW}[*] Stopping Almusa Panel services...${NC}"
    systemctl stop mypanel-web.service 2>/dev/null || true
    systemctl stop mypanel-worker.service 2>/dev/null || true
    pkill -f "${PANEL_ROOT}/task.php" || true
    pkill -f "php -S.*${PANEL_ROOT}/public" || true
    echo -e "${GREEN}[✔] Services stopped.${NC}"
}

cmd_restart() {
    echo -e "${BLUE}[*] Restarting Almusa Panel services...${NC}"
    cmd_stop
    sleep 1
    cmd_start
    echo -e "${GREEN}[✔] Almusa Panel restarted successfully.${NC}"
}

cmd_status() {
    show_banner
    echo -e "${BOLD}Panel Services Status:${NC}"
    
    local web_status="INACTIVE"
    if systemctl is-active --quiet mypanel-web.service 2>/dev/null || pgrep -f "php -S.*${PANEL_ROOT}/public" >/dev/null; then
        web_status="${GREEN}ACTIVE (Running)${NC}"
    else
        web_status="${RED}STOPPED${NC}"
    fi

    local worker_status="INACTIVE"
    if systemctl is-active --quiet mypanel-worker.service 2>/dev/null || pgrep -f "${PANEL_ROOT}/task.php" >/dev/null; then
        worker_status="${GREEN}ACTIVE (Running)${NC}"
    else
        worker_status="${RED}STOPPED${NC}"
    fi

    echo -e " - Web Interface   : $web_status"
    echo -e " - Worker Daemon   : $worker_status"
    echo ""
}

cmd_reset_password() {
    echo -e "${CYAN}=== Reset Almusa Panel Admin Password ===${NC}"
    read -r -p "Masukkan password baru (minimal 8 karakter): " new_pass
    if [ ${#new_pass} -lt 8 ]; then
        echo -e "${RED}[!] Password terlalu pendek. Minimal 8 karakter.${NC}"
        return 1
    fi

    php -r "
    require_once '${PANEL_ROOT}/app/bootstrap.php';
    \$pdo = \App\Core\Database::getInstance();
    \$hash = password_hash('${new_pass}', PASSWORD_BCRYPT, ['cost' => 12]);
    \$stmt = \$pdo->prepare('UPDATE users SET password_hash = :p WHERE username = :u');
    \$stmt->execute([':p' => \$hash, ':u' => 'admin']);
    echo PHP_EOL . '[✔] Password admin berhasil diperbarui!' . PHP_EOL;
    "
}

cmd_change_port() {
    echo -e "${CYAN}=== Ganti Port Listen Almusa Panel ===${NC}"
    read -r -p "Masukkan port baru (1024 - 65535): " new_port
    if ! [[ "$new_port" =~ ^[0-9]+$ ]] || [ "$new_port" -lt 1024 ] || [ "$new_port" -gt 65535 ]; then
        echo -e "${RED}[!] Port tidak valid. Harus angka antara 1024-65535.${NC}"
        return 1
    fi

    php -r "
    require_once '${PANEL_ROOT}/app/bootstrap.php';
    \App\Core\Database::setSetting('panel_port', '${new_port}');
    echo PHP_EOL . '[✔] Port panel berhasil diubah menjadi: ${new_port}' . PHP_EOL;
    "
    cmd_restart
}

cmd_change_entrance() {
    echo -e "${CYAN}=== Ganti Secret Entrance Path ===${NC}"
    read -r -p "Masukkan entrance path baru (contoh: mypanel, secret77): " new_entrance
    new_entrance=$(echo "$new_entrance" | tr -cd '[:alnum:]_-')
    if [ ${#new_entrance} -lt 4 ]; then
        echo -e "${RED}[!] Entrance path minimal 4 karakter alfanumerik.${NC}"
        return 1
    fi

    php -r "
    require_once '${PANEL_ROOT}/app/bootstrap.php';
    \App\Core\Database::setSetting('entrance_path', '${new_entrance}');
    file_put_contents('${PANEL_ROOT}/data/entrance.txt', '${new_entrance}');
    echo PHP_EOL . '[✔] Secret Entrance berhasil diubah menjadi: /${new_entrance}' . PHP_EOL;
    "
}

interactive_menu() {
    while true; do
        show_banner
        echo -e "${BOLD}Silakan pilih nomor menu:${NC}"
        echo " 1) Restart Panel"
        echo " 2) Stop Panel"
        echo " 3) Start Panel"
        echo " 4) Status Layanan"
        echo " 5) Tampilkan Informasi Login & URL (Default)"
        echo " 6) Reset Password Admin"
        echo " 7) Ganti Port Panel"
        echo " 8) Ganti Secret Entrance Path"
        echo " 0) Keluar"
        echo ""
        read -r -p "Pilihan Anda [0-8]: " choice

        case "$choice" in
            1) cmd_restart ;;
            2) cmd_stop ;;
            3) cmd_start ;;
            4) cmd_status ;;
            5) cmd_default ;;
            6) cmd_reset_password ;;
            7) cmd_change_port ;;
            8) cmd_change_entrance ;;
            0) exit 0 ;;
            *) echo -e "${RED}[!] Pilihan tidak valid.${NC}" ;;
        esac

        echo ""
        read -r -p "Tekan [Enter] untuk kembali ke menu..."
    done
}

# Routing arguments
case "${1:-}" in
    start)
        cmd_start
        ;;
    stop)
        cmd_stop
        ;;
    restart)
        cmd_restart
        ;;
    status)
        cmd_status
        ;;
    default)
        cmd_default
        ;;
    *)
        interactive_menu
        ;;
esac
