#!/usr/bin/env bash
# ==============================================================================
# Almusa Server Control Panel - Standalone Bootstrap Installer
# Compatible with: Ubuntu 20.04 / 22.04 / 24.04, Debian 11 / 12
# Usage: curl -fsSL https://bidanokta.xyz/almusa.sh | sudo bash
# ==============================================================================

set -e

# Color definitions
RED='\033[0;31m'
GREEN='\033[0;32m'
BLUE='\033[0;34m'
CYAN='\033[0;36m'
BOLD='\033[1m'
NC='\033[0m'

echo -e "${CYAN}${BOLD}"
echo "================================================================================"
echo "          ALMUSA SERVER CONTROL PANEL (SLEMP EDITION) - BOOTSTRAP               "
echo "================================================================================"
echo -e "${NC}"

# 1. Check Root Privileges
if [ "$(id -u)" -ne 0 ]; then
    echo -e "${RED}[ERROR] Installer ini harus dijalankan sebagai root (gunakan sudo bash).${NC}"
    exit 1
fi

# 2. Check and install minimal tools for downloading repo
echo -e "${BLUE}[*] Memeriksa dependensi dasar (curl, git, ca-certificates)...${NC}"
apt-get update -y
DEBIAN_FRONTEND=noninteractive apt-get install -y curl git ca-certificates

# 3. Download Almusa Panel repository
INSTALLER_TMP="/tmp/almusa-installer-$$"
rm -rf "$INSTALLER_TMP"
echo -e "${BLUE}[*] Mengunduh paket installer Almusa Panel dari repositori resmi...${NC}"
git clone --depth=1 https://github.com/uwaisalqorni/almusa-panel.git "$INSTALLER_TMP"

# 4. Run the master installer
echo -e "${BLUE}[*] Memulai instalasi utama...${NC}"
cd "$INSTALLER_TMP"
bash scripts/install.sh

# 5. Clean up temporary installer directory
rm -rf "$INSTALLER_TMP"
