#!/usr/bin/env bash
# ==============================================================================
# Almusa Panel - PHP Extension Manager (1-Click Extender)
# ==============================================================================

set -e

SOURCE="${BASH_SOURCE[0]}"
while [ -h "$SOURCE" ]; do
  DIR="$( cd -P "$( dirname "$SOURCE" )" >/dev/null 2>&1 && pwd )"
  SOURCE="$(readlink "$SOURCE")"
  [[ $SOURCE != /* ]] && SOURCE="$DIR/$SOURCE"
done
SCRIPT_DIR="$( cd -P "$( dirname "$SOURCE" )" >/dev/null 2>&1 && pwd )"

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
    redis|imagick|memcached|xdebug|soap|intl|zip|bz2|gmp|ldap|gd|bcmath|mbstring|curl|xml|mysql|pgsql|sqlite3|exif|apcu|igbinary|msgpack|mongodb)
        ;;
    *)
        echo "[ERROR] Disallowed extension: $EXTENSION"
        exit 1
        ;;
esac

wait_for_apt_lock() {
    local max_wait=60
    local waited=0
    while fuser /var/lib/dpkg/lock >/dev/null 2>&1 || \
          fuser /var/lib/apt/lists/lock >/dev/null 2>&1 || \
          fuser /var/lib/dpkg/lock-frontend >/dev/null 2>&1; do
        if [ "$waited" -ge "$max_wait" ]; then
            echo "[WARNING] Timeout waiting for apt/dpkg lock after ${max_wait}s. Continuing..."
            break
        fi
        echo "[INFO] Waiting for other apt/dpkg process to finish... (${waited}s/${max_wait}s)"
        sleep 3
        waited=$((waited + 3))
    done
    DEBIAN_FRONTEND=noninteractive dpkg --configure -a >/dev/null 2>&1 || true
}

has_pkg_candidate() {
    local pkg="$1"
    local cand
    cand=$(apt-cache policy "$pkg" 2>/dev/null | awk '/Candidate:/ {print $2}' | head -n 1)
    if [ -n "$cand" ] && [ "$cand" != "(none)" ]; then
        return 0
    fi
    return 1
}

ensure_php_repo() {
    if [ -f /etc/apt/sources.list.d/ondrej-ubuntu-php-fallback.sources ]; then
        rm -f /etc/apt/sources.list.d/ondrej-ubuntu-php-fallback.sources
    fi

    if [ -f /etc/apt/preferences.d/99-ondrej-php ] \
       && (has_pkg_candidate "php${VERSION}-${EXTENSION}" || has_pkg_candidate "php-${EXTENSION}") \
       && has_pkg_candidate "libxml2" \
       && has_pkg_candidate "libzip4t64"; then
        return 0
    fi

    echo "[INFO] Synchronizing PHP APT repository & shared library sources..."
    DISTRO_ID="ubuntu"
    CODENAME=""
    VER_ID=""
    if [ -f /etc/os-release ]; then
        . /etc/os-release
        DISTRO_ID="${ID:-ubuntu}"
        CODENAME="${VERSION_CODENAME:-}"
        VER_ID="${VERSION_ID:-}"
    fi
    [ -z "$CODENAME" ] && CODENAME="$(lsb_release -sc 2>/dev/null || echo 'noble')"

    wait_for_apt_lock
    mkdir -p /usr/share/keyrings /etc/apt/trusted.gpg.d /etc/apt/preferences.d

    cat << 'EOF' > /etc/apt/preferences.d/99-ondrej-php
Package: php* libapache2-mod-php*
Pin: release o=LP-PPA-ondrej-php
Pin-Priority: 1001

Package: php* libapache2-mod-php*
Pin: origin "ppa.launchpadcontent.net"
Pin-Priority: 1001

Package: php* libapache2-mod-php*
Pin: origin "packages.sury.org"
Pin-Priority: 1001
EOF
    chmod 0644 /etc/apt/preferences.d/99-ondrej-php

    if [ "$DISTRO_ID" = "ubuntu" ] || [[ "${ID_LIKE:-}" == *ubuntu* ]]; then
        rm -f /etc/apt/sources.list.d/php.list /etc/apt/sources.list.d/ondrej-ubuntu-php-fallback.sources /etc/apt/sources.list.d/*ondrej* 2>/dev/null || true
        TARGET_SUITE="$CODENAME"
        if ! curl -fsSLI --connect-timeout 10 "https://ppa.launchpadcontent.net/ondrej/php/ubuntu/dists/${TARGET_SUITE}/InRelease" >/dev/null 2>&1; then
            case "$VER_ID" in
                20.*|21.*) TARGET_SUITE="focal" ;;
                22.*|23.*) TARGET_SUITE="jammy" ;;
                *)         TARGET_SUITE="noble" ;;
            esac
        fi

        if [ "$TARGET_SUITE" != "$CODENAME" ]; then
            cat << EOF >> /etc/apt/preferences.d/99-ondrej-php

Package: *
Pin: release o=Ubuntu,n=${TARGET_SUITE}*
Pin-Priority: 400
EOF
        fi

        ARCH=$(dpkg --print-architecture 2>/dev/null || echo "amd64")
        UBU_MIRROR="http://archive.ubuntu.com/ubuntu"
        UBU_SEC="http://security.ubuntu.com/ubuntu"
        if [ "$ARCH" != "amd64" ] && [ "$ARCH" != "i386" ]; then
            UBU_MIRROR="http://ports.ubuntu.com/ubuntu-ports"
            UBU_SEC="http://ports.ubuntu.com/ubuntu-ports"
        fi
        SIGNED_BY_OPT=""
        if [ -s /usr/share/keyrings/ubuntu-archive-keyring.gpg ]; then
            SIGNED_BY_OPT="[signed-by=/usr/share/keyrings/ubuntu-archive-keyring.gpg] "
        fi
        cat << EOF > "/etc/apt/sources.list.d/ubuntu-${TARGET_SUITE}-compat.list"
deb ${SIGNED_BY_OPT}${UBU_MIRROR} ${TARGET_SUITE} main universe
deb ${SIGNED_BY_OPT}${UBU_MIRROR} ${TARGET_SUITE}-updates main universe
deb ${SIGNED_BY_OPT}${UBU_SEC} ${TARGET_SUITE}-security main universe
EOF
        chmod 0644 "/etc/apt/sources.list.d/ubuntu-${TARGET_SUITE}-compat.list"

        KEYRING_FILE="/usr/share/keyrings/ondrej-ubuntu-php.gpg"
        TMP_IMPORT_RING="/tmp/ondrej_ext_import_$$.gpg"
        rm -f "$TMP_IMPORT_RING"
        for k in 14AA40EC0831756756D7F66C4F4EA0AAE5267A6C 71DAEAAB4AD4CAB6; do
            ASC_DATA=$(curl -fsSL --connect-timeout 15 "https://keyserver.ubuntu.com/pks/lookup?op=get&search=0x${k}" 2>/dev/null || true)
            if [ -n "$ASC_DATA" ] && echo "$ASC_DATA" | grep -q "BEGIN PGP PUBLIC KEY BLOCK"; then
                echo "$ASC_DATA" | gpg --batch --no-default-keyring --keyring "$TMP_IMPORT_RING" --import >/dev/null 2>&1 || true
            fi
        done
        if [ -s "$TMP_IMPORT_RING" ]; then
            gpg --batch --no-default-keyring --keyring "$TMP_IMPORT_RING" --export > "$KEYRING_FILE" 2>/dev/null || true
        fi
        rm -f "$TMP_IMPORT_RING" "${TMP_IMPORT_RING}~" 2>/dev/null || true
        if [ -s "$KEYRING_FILE" ]; then
            chmod 0644 "$KEYRING_FILE"
            cp -f "$KEYRING_FILE" /etc/apt/trusted.gpg.d/ondrej-ubuntu-php.gpg 2>/dev/null || true
            echo "deb [signed-by=${KEYRING_FILE}] https://ppa.launchpadcontent.net/ondrej/php/ubuntu ${TARGET_SUITE} main" > /etc/apt/sources.list.d/ondrej-ubuntu-php.list
            chmod 0644 /etc/apt/sources.list.d/ondrej-ubuntu-php.list
        fi
    else
        KEYRING_FILE="/usr/share/keyrings/debsuryorg-archive-keyring.gpg"
        curl -fsSL --connect-timeout 15 https://packages.sury.org/php/apt.gpg -o "$KEYRING_FILE" || true
        chmod 0644 "$KEYRING_FILE" 2>/dev/null || true
        echo "deb [signed-by=${KEYRING_FILE}] https://packages.sury.org/php/ ${CODENAME} main" > /etc/apt/sources.list.d/php.list
    fi

    wait_for_apt_lock
    DEBIAN_FRONTEND=noninteractive apt-get update -y || true
}

case "$ACTION" in
    install)
        ensure_php_repo
        echo "[INFO] Installing php${VERSION}-${EXTENSION}..."
        wait_for_apt_lock
        if has_pkg_candidate "php${VERSION}-${EXTENSION}"; then
            DEBIAN_FRONTEND=noninteractive apt-get install -y --allow-downgrades \
                -o Dpkg::Options::="--force-confdef" \
                -o Dpkg::Options::="--force-confold" \
                php-common "php${VERSION}-${EXTENSION}"
        elif has_pkg_candidate "php-${EXTENSION}"; then
            DEBIAN_FRONTEND=noninteractive apt-get install -y --allow-downgrades \
                -o Dpkg::Options::="--force-confdef" \
                -o Dpkg::Options::="--force-confold" \
                php-common "php-${EXTENSION}"
        else
            echo "[ERROR] Package php${VERSION}-${EXTENSION} has no installation candidate."
            exit 1
        fi
        systemctl restart "php${VERSION}-fpm"
        rm -f /dev/shm/almusa_svc_*.json /tmp/almusa_svc_*.json 2>/dev/null || true
        echo "[SUCCESS] php${VERSION}-${EXTENSION} installed and PHP ${VERSION}-FPM restarted!"
        ;;

    uninstall)
        echo "[INFO] Removing php${VERSION}-${EXTENSION}..."
        wait_for_apt_lock
        DEBIAN_FRONTEND=noninteractive apt-get purge -y "php${VERSION}-${EXTENSION}" || true
        systemctl restart "php${VERSION}-fpm" || true
        rm -f /dev/shm/almusa_svc_*.json /tmp/almusa_svc_*.json 2>/dev/null || true
        echo "[SUCCESS] php${VERSION}-${EXTENSION} uninstalled."
        ;;

    *)
        echo "Usage: $0 {install|uninstall} <php_version> <extension>"
        exit 1
        ;;
esac
