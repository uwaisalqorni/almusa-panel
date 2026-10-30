#!/usr/bin/env bash
# ==============================================================================
# Almusa Panel - PHP Extension Manager (1-Click Extender)
# ==============================================================================

set -e

ACTION="${1:-install}"
VERSION="${2:-8.3}"
EXTENSION="${3:-redis}"

# Validate version
if ! [[ "$VERSION" =~ ^(7\.4|8\.0|8\.1|8\.2|8\.3|8\.4|8\.5)$ ]]; then
    echo "[ERROR] Unsupported PHP version: $VERSION"
    exit 1
fi

# Whitelist extensions
case "$EXTENSION" in
    redis|imagick|memcached|xdebug|soap|intl|zip|bz2|gmp|ldap)
        ;;
    *)
        echo "[ERROR] Disallowed extension: $EXTENSION"
        exit 1
        ;;
esac

ensure_php_repo() {
    if apt-cache show "php${VERSION}-${EXTENSION}" >/dev/null 2>&1; then
        return 0
    fi

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

    if [ "$DISTRO_ID" = "ubuntu" ]; then
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
}

case "$ACTION" in
    install)
        ensure_php_repo
        echo "[INFO] Installing php${VERSION}-${EXTENSION}..."
        DEBIAN_FRONTEND=noninteractive apt-get install -y "php${VERSION}-${EXTENSION}"
        systemctl restart "php${VERSION}-fpm"
        echo "[SUCCESS] php${VERSION}-${EXTENSION} installed and PHP-FPM restarted!"
        ;;

    uninstall)
        echo "[INFO] Removing php${VERSION}-${EXTENSION}..."
        DEBIAN_FRONTEND=noninteractive apt-get purge -y "php${VERSION}-${EXTENSION}" || true
        systemctl restart "php${VERSION}-fpm"
        echo "[SUCCESS] php${VERSION}-${EXTENSION} uninstalled."
        ;;

    *)
        echo "Usage: $0 {install|uninstall} <php_version> <extension>"
        exit 1
        ;;
esac
