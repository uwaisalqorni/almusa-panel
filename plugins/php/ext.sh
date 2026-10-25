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

case "$ACTION" in
    install)
        echo "[INFO] Installing php${VERSION}-${EXTENSION}..."
        apt-get update -y
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
