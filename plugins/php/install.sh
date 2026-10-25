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

case "$ACTION" in
    install)
        echo "[INFO] Installing PHP $VERSION and core extensions..."
        apt-get update -y
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
        echo "[INFO] Updating PHP $VERSION packages..."
        apt-get update -y
        DEBIAN_FRONTEND=noninteractive apt-get install --only-upgrade -y "php${VERSION}-*"
        systemctl restart "php${VERSION}-fpm"
        echo "[SUCCESS] PHP $VERSION updated and restarted!"
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
