#!/usr/bin/env bash
# ==============================================================================
# Almusa Panel - PHP Extension Manager (1-Click Extender)
# ==============================================================================

set -e

ACTION="${1:-install}"
VERSION="${2:-8.3}"
EXTENSION="${3:-redis}"

# Validate version
if ! [[ "$VERSION" =~ ^(7\.4|8\.0|8\.1|8\.2|8\.3|8\.4)$ ]]; then
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

    CODENAME="${VERSION_CODENAME:-}"
    if [ -f /etc/os-release ]; then
        . /etc/os-release
        CODENAME="${VERSION_CODENAME:-}"
    fi
    [ -z "$CODENAME" ] && CODENAME="noble"

    mkdir -p /usr/share/keyrings
    if [ ! -s /usr/share/keyrings/debsuryorg-archive-keyring.gpg ]; then
        curl -sSL https://packages.sury.org/php/apt.gpg -o /usr/share/keyrings/debsuryorg-archive-keyring.gpg || true
    fi
    echo "deb [signed-by=/usr/share/keyrings/debsuryorg-archive-keyring.gpg] https://packages.sury.org/php/ ${CODENAME} main" > /etc/apt/sources.list.d/php.list
    apt-get update -y
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
