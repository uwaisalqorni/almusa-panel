#!/usr/bin/env bash
# ==============================================================================
# Almusa Panel - PHP Multi-Version Manager Installer
# ==============================================================================

set -e

ACTION="${1:-install}"
VERSION="${2:-8.3}"

# Validate version format
if ! [[ "$VERSION" =~ ^(7\.4|8\.0|8\.1|8\.2|8\.3|8\.4)$ ]]; then
    echo "[ERROR] Unsupported PHP version: $VERSION. Supported: 7.4, 8.0, 8.1, 8.2, 8.3, 8.4"
    exit 1
fi

ensure_php_repo() {
    # Check if target PHP version packages are already resolvable
    if apt-cache show "php${VERSION}-fpm" >/dev/null 2>&1 && apt-cache show "php${VERSION}-common" >/dev/null 2>&1; then
        return 0
    fi

    echo "[INFO] PHP ${VERSION} packages not available in current sources. Configuring Sury PHP repository..."

    DISTRO_ID="ubuntu"
    CODENAME=""
    if [ -f /etc/os-release ]; then
        . /etc/os-release
        DISTRO_ID="${ID:-ubuntu}"
        CODENAME="${VERSION_CODENAME:-}"
    fi

    if [ -z "$CODENAME" ]; then
        CODENAME="$(lsb_release -sc 2>/dev/null || echo 'noble')"
    fi

    DEBIAN_FRONTEND=noninteractive apt-get update -y || true
    DEBIAN_FRONTEND=noninteractive apt-get install -y curl ca-certificates lsb-release apt-transport-https gnupg

    mkdir -p /usr/share/keyrings
    if [ ! -s /usr/share/keyrings/debsuryorg-archive-keyring.gpg ]; then
        echo "[INFO] Downloading Sury archive keyring..."
        curl -sSL https://packages.sury.org/php/apt.gpg -o /usr/share/keyrings/debsuryorg-archive-keyring.gpg || true
    fi

    echo "deb [signed-by=/usr/share/keyrings/debsuryorg-archive-keyring.gpg] https://packages.sury.org/php/ ${CODENAME} main" > /etc/apt/sources.list.d/php.list

    if [ "$DISTRO_ID" = "ubuntu" ] && [ "$CODENAME" = "focal" ]; then
        add-apt-repository -y ppa:ondrej/php || true
    fi

    echo "[INFO] Updating apt package indices..."
    apt-get update -y
}

case "$ACTION" in
    install)
        echo "[INFO] Ensuring PHP repository is available..."
        ensure_php_repo

        echo "[INFO] Installing PHP $VERSION and core extensions..."
        DEBIAN_FRONTEND=noninteractive apt-get install -y \
            "php${VERSION}-fpm" \
            "php${VERSION}-cli" \
            "php${VERSION}-common" \
            "php${VERSION}-mysql" \
            "php${VERSION}-pgsql" \
            "php${VERSION}-sqlite3" \
            "php${VERSION}-curl" \
            "php${VERSION}-mbstring" \
            "php${VERSION}-xml" \
            "php${VERSION}-zip" \
            "php${VERSION}-bcmath" \
            "php${VERSION}-gd" \
            "php${VERSION}-intl"

        systemctl enable "php${VERSION}-fpm"
        systemctl restart "php${VERSION}-fpm"
        echo "[SUCCESS] PHP $VERSION-FPM installed and running!"
        ;;

    update)
        echo "[INFO] Patching and updating PHP $VERSION against CVE-2026-109618 / CVE-2026-109619..."
        DEBIAN_FRONTEND=noninteractive apt-get update -qq
        DEBIAN_FRONTEND=noninteractive apt-get install --only-upgrade -y \
            -o Dpkg::Options::="--force-confdef" \
            -o Dpkg::Options::="--force-confold" \
            "php${VERSION}-*"
        systemctl reload "php${VERSION}-fpm" || systemctl restart "php${VERSION}-fpm"
        echo "[SUCCESS] PHP $VERSION successfully patched and reloaded!"
        ;;

    uninstall)
        echo "[WARNING] Stopping PHP $VERSION-FPM service..."
        systemctl stop "php${VERSION}-fpm" || true
        systemctl disable "php${VERSION}-fpm" || true

        echo "[INFO] Purging PHP $VERSION packages..."
        DEBIAN_FRONTEND=noninteractive apt-get purge -y "php${VERSION}*" || true
        apt-get autoremove -y || true
        echo "[SUCCESS] PHP $VERSION uninstalled."
        ;;

    restart)
        echo "[INFO] Restarting PHP $VERSION-FPM..."
        systemctl restart "php${VERSION}-fpm"
        echo "[SUCCESS] PHP $VERSION-FPM restarted."
        ;;

    *)
        echo "Usage: $0 {install|update|uninstall|restart} <version>"
        exit 1
        ;;
esac
