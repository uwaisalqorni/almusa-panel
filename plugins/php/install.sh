#!/usr/bin/env bash
# ==============================================================================
# Almusa Panel - PHP Multi-Version Manager Installer
# Supports PHP 7.4, 8.0, 8.1, 8.2, 8.3, 8.4, 8.5 on Ubuntu & Debian
# ==============================================================================

set -e

ACTION="${1:-install}"
VERSION="${2:-8.3}"

# Validate version format
if ! [[ "$VERSION" =~ ^(7\.4|8\.0|8\.1|8\.2|8\.3|8\.4|8\.5)$ ]]; then
    echo "[ERROR] Unsupported PHP version: $VERSION. Supported: 7.4, 8.0, 8.1, 8.2, 8.3, 8.4, 8.5"
    exit 1
fi

# Wait for apt/dpkg locks (e.g. unattended-upgrades)
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

# Check whether an APT package actually has an installable candidate (not '(none)')
has_pkg_candidate() {
    local pkg="$1"
    local cand
    cand=$(apt-cache policy "$pkg" 2>/dev/null | awk '/Candidate:/ {print $2}' | head -n 1)
    if [ -n "$cand" ] && [ "$cand" != "(none)" ]; then
        return 0
    fi
    return 1
}

# Clean up any previously misconfigured or unsigned PHP repository files
cleanup_broken_php_sources() {
    local cleaned=0
    if [ -f /etc/apt/sources.list.d/ondrej-ubuntu-php-fallback.sources ]; then
        echo "[INFO] Removing broken fallback repository file: /etc/apt/sources.list.d/ondrej-ubuntu-php-fallback.sources"
        rm -f /etc/apt/sources.list.d/ondrej-ubuntu-php-fallback.sources
        cleaned=1
    fi

    for src in /etc/apt/sources.list.d/*.list /etc/apt/sources.list.d/*.sources; do
        [ -f "$src" ] || continue
        if grep -q "ppa.launchpadcontent.net/ondrej/php" "$src" 2>/dev/null && grep -q "debsuryorg-archive-keyring.gpg" "$src" 2>/dev/null; then
            echo "[INFO] Removing misconfigured PHP source file: $src"
            rm -f "$src"
            cleaned=1
        fi
    done

    return $cleaned
}

# Configure APT Pinning so Ondrej's multi-PHP php-common & modules take precedence
# over Ubuntu/Debian's restrictive single-version php-common package
configure_apt_pinning() {
    local target_suite="${1:-}"
    local host_codename="${2:-}"

    mkdir -p /etc/apt/preferences.d
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

    if [ -n "$target_suite" ] && [ -n "$host_codename" ] && [ "$target_suite" != "$host_codename" ]; then
        cat << EOF >> /etc/apt/preferences.d/99-ondrej-php

Package: *
Pin: release o=Ubuntu,n=${target_suite}*
Pin-Priority: 400
EOF
    fi

    chmod 0644 /etc/apt/preferences.d/99-ondrej-php
}

# Ensure Ubuntu base LTS main & universe repositories are available for shared libraries
# required by ppa:ondrej/php (e.g. libxml2, libicu74, libzip4t64, libonig5, libldap-2.5-0)
ensure_ubuntu_compat_libs_repo() {
    local target_suite="$1"
    local host_codename="$2"

    local arch
    arch=$(dpkg --print-architecture 2>/dev/null || echo "amd64")
    local ubu_mirror="http://archive.ubuntu.com/ubuntu"
    local ubu_sec="http://security.ubuntu.com/ubuntu"
    if [ "$arch" != "amd64" ] && [ "$arch" != "i386" ]; then
        ubu_mirror="http://ports.ubuntu.com/ubuntu-ports"
        ubu_sec="http://ports.ubuntu.com/ubuntu-ports"
    fi

    local signed_by_opt=""
    if [ -s /usr/share/keyrings/ubuntu-archive-keyring.gpg ]; then
        signed_by_opt="[signed-by=/usr/share/keyrings/ubuntu-archive-keyring.gpg] "
    fi

    echo "[INFO] Enabling Ubuntu '${target_suite}' (main & universe) shared library repository..."
    cat << EOF > "/etc/apt/sources.list.d/ubuntu-${target_suite}-compat.list"
deb ${signed_by_opt}${ubu_mirror} ${target_suite} main universe
deb ${signed_by_opt}${ubu_mirror} ${target_suite}-updates main universe
deb ${signed_by_opt}${ubu_sec} ${target_suite}-security main universe
EOF
    chmod 0644 "/etc/apt/sources.list.d/ubuntu-${target_suite}-compat.list"
    configure_apt_pinning "$target_suite" "$host_codename"
}

# Import a GPG key by hex fingerprint/ID over HTTPS (port 443) with HKP fallback
import_ubuntu_key() {
    local key_id="$1"
    local target_keyring="$2"
    local tmp_asc="/tmp/almusa_key_${key_id}_$$.asc"
    local tmp_gpg="/tmp/almusa_key_${key_id}_$$.gpg"

    rm -f "$tmp_asc" "$tmp_gpg"

    if curl -fsSL --connect-timeout 15 "https://keyserver.ubuntu.com/pks/lookup?op=get&search=0x${key_id}" -o "$tmp_asc" 2>/dev/null; then
        if grep -q "BEGIN PGP PUBLIC KEY BLOCK" "$tmp_asc" 2>/dev/null; then
            if gpg --dearmor --yes -o "$tmp_gpg" "$tmp_asc" 2>/dev/null && [ -s "$tmp_gpg" ]; then
                cat "$tmp_gpg" >> "$target_keyring"
                rm -f "$tmp_asc" "$tmp_gpg"
                return 0
            fi
        fi
    fi

    rm -f "$tmp_asc" "$tmp_gpg"
    gpg --batch --no-default-keyring --keyring "$target_keyring" \
        --keyserver hkp://keyserver.ubuntu.com:80 --recv-keys "$key_id" >/dev/null 2>&1 || true
}

ensure_php_repo() {
    cleanup_broken_php_sources || true

    # Only skip repository setup if PHP packages, APT preferences pinning, AND base shared libraries are all available
    if [ "${FORCE_REPO_SETUP:-0}" != "1" ] \
       && [ -f /etc/apt/preferences.d/99-ondrej-php ] \
       && has_pkg_candidate "php${VERSION}-fpm" \
       && has_pkg_candidate "php${VERSION}-common" \
       && has_pkg_candidate "libxml2" \
       && has_pkg_candidate "libzip4t64"; then
        return 0
    fi

    echo "[INFO] Configuring Ondrej Sury PHP repository & required runtime libraries for PHP ${VERSION}..."

    DISTRO_ID="ubuntu"
    CODENAME=""
    VER_ID=""
    if [ -f /etc/os-release ]; then
        . /etc/os-release
        DISTRO_ID="${ID:-ubuntu}"
        CODENAME="${VERSION_CODENAME:-}"
        VER_ID="${VERSION_ID:-}"
    fi

    if [ -z "$CODENAME" ]; then
        CODENAME="$(lsb_release -sc 2>/dev/null || echo 'noble')"
    fi

    wait_for_apt_lock
    DEBIAN_FRONTEND=noninteractive apt-get update -y >/dev/null 2>&1 || true
    DEBIAN_FRONTEND=noninteractive apt-get install -y \
        curl ca-certificates lsb-release apt-transport-https gnupg dirmngr software-properties-common >/dev/null 2>&1 || true

    mkdir -p /usr/share/keyrings /etc/apt/keyrings /etc/apt/trusted.gpg.d

    if [ "$DISTRO_ID" = "ubuntu" ] || [[ "${ID_LIKE:-}" == *ubuntu* ]]; then
        # Clean all old ondrej/php source definitions to prevent duplicate/conflicting Signed-By errors
        rm -f /etc/apt/sources.list.d/php.list \
              /etc/apt/sources.list.d/ondrej-ubuntu-php-fallback.sources \
              /etc/apt/sources.list.d/*ondrej* 2>/dev/null || true

        # Determine target Ubuntu suite supported by ppa:ondrej/php
        TARGET_SUITE="$CODENAME"
        if ! curl -fsSLI --connect-timeout 10 "https://ppa.launchpadcontent.net/ondrej/php/ubuntu/dists/${TARGET_SUITE}/InRelease" >/dev/null 2>&1; then
            case "$VER_ID" in
                20.*|21.*) TARGET_SUITE="focal" ;;
                22.*|23.*) TARGET_SUITE="jammy" ;;
                *)         TARGET_SUITE="noble" ;;
            esac
            echo "[INFO] Ubuntu suite '${CODENAME}' does not have a dedicated PPA build; using LTS suite '${TARGET_SUITE}'."
        fi

        # Configure APT pinning & compatibility library repository for target LTS suite
        ensure_ubuntu_compat_libs_repo "$TARGET_SUITE" "$CODENAME"

        echo "[INFO] Fetching official Launchpad GPG signing keys for ppa:ondrej/php..."
        KEYRING_FILE="/usr/share/keyrings/ondrej-ubuntu-php.gpg"
        TMP_COMBINED="/tmp/ondrej_combined_$$.gpg"
        rm -f "$KEYRING_FILE" "$TMP_COMBINED"

        # Dynamically query current signing_key_fingerprint from Launchpad API + known Ondrej PPA keys
        LP_KEY=$(curl -fsSL --connect-timeout 10 "https://api.launchpad.net/1.0/~ondrej/+archive/ubuntu/php" 2>/dev/null | grep -oE '"signing_key_fingerprint":[[:space:]]*"[0-9A-Fa-f]+"' | grep -oE '[0-9A-Fa-f]{16,40}' | head -n 1 || true)

        KEY_LIST="14AA40EC0831756756D7F66C4F4EA0AAE5267A6C 71DAEAAB4AD4CAB6"
        if [ -n "$LP_KEY" ] && [[ "$KEY_LIST" != *"$LP_KEY"* ]]; then
            KEY_LIST="$LP_KEY $KEY_LIST"
        fi

        TMP_IMPORT_RING="/tmp/ondrej_import_$$.gpg"
        rm -f "$TMP_IMPORT_RING"
        for k in $KEY_LIST; do
            ASC_DATA=$(curl -fsSL --connect-timeout 15 "https://keyserver.ubuntu.com/pks/lookup?op=get&search=0x${k}" 2>/dev/null || true)
            if [ -n "$ASC_DATA" ] && echo "$ASC_DATA" | grep -q "BEGIN PGP PUBLIC KEY BLOCK"; then
                echo "$ASC_DATA" | gpg --batch --no-default-keyring --keyring "$TMP_IMPORT_RING" --import >/dev/null 2>&1 || \
                echo "$ASC_DATA" | gpg --dearmor >> "$TMP_COMBINED" 2>/dev/null || true
            else
                import_ubuntu_key "$k" "$TMP_COMBINED"
            fi
        done

        if [ -s "$TMP_IMPORT_RING" ]; then
            gpg --batch --no-default-keyring --keyring "$TMP_IMPORT_RING" --export > "$KEYRING_FILE" 2>/dev/null || true
        fi
        if [ ! -s "$KEYRING_FILE" ] && [ -s "$TMP_COMBINED" ]; then
            cp -f "$TMP_COMBINED" "$KEYRING_FILE"
        fi
        rm -f "$TMP_IMPORT_RING" "${TMP_IMPORT_RING}~" "$TMP_COMBINED" 2>/dev/null || true

        if [ -s "$KEYRING_FILE" ]; then
            chmod 0644 "$KEYRING_FILE"
            cp -f "$KEYRING_FILE" /etc/apt/trusted.gpg.d/ondrej-ubuntu-php.gpg 2>/dev/null || true
            chmod 0644 /etc/apt/trusted.gpg.d/ondrej-ubuntu-php.gpg 2>/dev/null || true
            echo "deb [signed-by=${KEYRING_FILE}] https://ppa.launchpadcontent.net/ondrej/php/ubuntu ${TARGET_SUITE} main" > /etc/apt/sources.list.d/ondrej-ubuntu-php.list
            chmod 0644 /etc/apt/sources.list.d/ondrej-ubuntu-php.list
        elif [ "$TARGET_SUITE" = "$CODENAME" ] && command -v add-apt-repository >/dev/null 2>&1; then
            echo "[INFO] Falling back to add-apt-repository ppa:ondrej/php..."
            LC_ALL=C.UTF-8 DEBIAN_FRONTEND=noninteractive add-apt-repository -y ppa:ondrej/php || true
        else
            echo "[ERROR] Failed to download GPG signing key for ppa:ondrej/php. Check server internet/DNS connection."
            exit 1
        fi
    else
        # Debian: packages.sury.org
        rm -f /etc/apt/sources.list.d/ondrej-ubuntu-php-fallback.sources \
              /etc/apt/sources.list.d/*ondrej* 2>/dev/null || true
        configure_apt_pinning "$CODENAME" "$CODENAME"
        KEYRING_FILE="/usr/share/keyrings/debsuryorg-archive-keyring.gpg"
        echo "[INFO] Downloading Debian Sury PHP archive keyring..."
        curl -fsSL --connect-timeout 15 https://packages.sury.org/php/apt.gpg -o "$KEYRING_FILE"
        chmod 0644 "$KEYRING_FILE"
        cp -f "$KEYRING_FILE" /etc/apt/trusted.gpg.d/debsuryorg-archive-keyring.gpg 2>/dev/null || true
        echo "deb [signed-by=${KEYRING_FILE}] https://packages.sury.org/php/ ${CODENAME} main" > /etc/apt/sources.list.d/php.list
        chmod 0644 /etc/apt/sources.list.d/php.list
    fi

    echo "[INFO] Updating APT package indices..."
    wait_for_apt_lock
    APT_UPDATE_LOG=$(DEBIAN_FRONTEND=noninteractive apt-get update -y 2>&1) || true
    echo "$APT_UPDATE_LOG"

    # Self-healing if APT reports NO_PUBKEY (e.g. future Launchpad key rotation)
    if echo "$APT_UPDATE_LOG" | grep -qE "NO_PUBKEY|is not signed"; then
        MISSING_KEYS=$(echo "$APT_UPDATE_LOG" | grep -oE "NO_PUBKEY [0-9A-Fa-f]+" | awk '{print $2}' | sort -u)
        if [ -n "$MISSING_KEYS" ]; then
            echo "[INFO] Detected missing GPG public key(s): $MISSING_KEYS. Importing automatically..."
            for mk in $MISSING_KEYS; do
                import_ubuntu_key "$mk" "${KEYRING_FILE:-/etc/apt/trusted.gpg.d/ondrej-ubuntu-php.gpg}"
                cp -f "${KEYRING_FILE:-/etc/apt/trusted.gpg.d/ondrej-ubuntu-php.gpg}" /etc/apt/trusted.gpg.d/ondrej-ubuntu-php.gpg 2>/dev/null || true
            done
            chmod 0644 "${KEYRING_FILE:-/etc/apt/trusted.gpg.d/ondrej-ubuntu-php.gpg}" /etc/apt/trusted.gpg.d/ondrej-ubuntu-php.gpg 2>/dev/null || true
            wait_for_apt_lock
            DEBIAN_FRONTEND=noninteractive apt-get update -y
        fi
    fi
}

clear_panel_service_cache() {
    rm -f /dev/shm/almusa_svc_*.json /tmp/almusa_svc_*.json 2>/dev/null || true
}

case "$ACTION" in
    install)
        echo "[INFO] Ensuring PHP ${VERSION} repository is available..."
        ensure_php_repo

        if ! has_pkg_candidate "php${VERSION}-fpm" || ! has_pkg_candidate "libxml2"; then
            echo "[INFO] Forcing full repository re-synchronization for PHP ${VERSION}..."
            FORCE_REPO_SETUP=1 ensure_php_repo
        fi

        if ! has_pkg_candidate "php${VERSION}-fpm"; then
            echo "[ERROR] Package 'php${VERSION}-fpm' has no installation candidate in APT."
            echo "[ERROR] Please verify server internet connectivity and Ubuntu/Debian repository access."
            exit 1
        fi

        # Include php-common explicitly so APT upgrades/replaces distro php-common with Ondrej's multi-PHP php-common
        PKGS_TO_INSTALL=("php-common" "php${VERSION}-common" "php${VERSION}-cli" "php${VERSION}-fpm")
        CORE_EXTS=("json" "readline" "opcache" "mysql" "pgsql" "sqlite3" "curl" "mbstring" "xml" "zip" "bcmath" "gd" "intl")

        for ext in "${CORE_EXTS[@]}"; do
            if has_pkg_candidate "php${VERSION}-${ext}"; then
                PKGS_TO_INSTALL+=("php${VERSION}-${ext}")
            fi
        done

        echo "[INFO] Installing PHP ${VERSION} and extensions: ${PKGS_TO_INSTALL[*]}..."
        wait_for_apt_lock
        if ! DEBIAN_FRONTEND=noninteractive apt-get install -y --allow-downgrades \
            -o Dpkg::Options::="--force-confdef" \
            -o Dpkg::Options::="--force-confold" \
            "${PKGS_TO_INSTALL[@]}"; then

            echo "[WARNING] Combined package installation encountered a dependency conflict. Running resilient staged install..."
            wait_for_apt_lock
            DEBIAN_FRONTEND=noninteractive apt-get install -y --allow-downgrades \
                -o Dpkg::Options::="--force-confdef" \
                -o Dpkg::Options::="--force-confold" \
                php-common "php${VERSION}-common" "php${VERSION}-cli" "php${VERSION}-fpm"

            for ext in "${CORE_EXTS[@]}"; do
                if has_pkg_candidate "php${VERSION}-${ext}"; then
                    DEBIAN_FRONTEND=noninteractive apt-get install -y --allow-downgrades \
                        -o Dpkg::Options::="--force-confdef" \
                        -o Dpkg::Options::="--force-confold" \
                        "php${VERSION}-${ext}" || echo "[WARNING] Optional extension php${VERSION}-${ext} skipped."
                fi
            done
        fi

        # Ensure /run/php runtime socket directory exists with correct permissions
        mkdir -p /run/php
        chown www-data:www-data /run/php 2>/dev/null || true
        chmod 0755 /run/php 2>/dev/null || true

        # Ensure pool config uses standard socket path and www-data permissions
        POOL_CONF="/etc/php/${VERSION}/fpm/pool.d/www.conf"
        if [ -f "$POOL_CONF" ]; then
            sed -i -E "s|^;?\s*listen\s*=.*|listen = /run/php/php${VERSION}-fpm.sock|g" "$POOL_CONF" 2>/dev/null || true
            sed -i -E "s|^;?\s*listen\.owner\s*=.*|listen.owner = www-data|g" "$POOL_CONF" 2>/dev/null || true
            # Socket hanya bisa disambungi nginx (user 'nginx' bila sudah dipisah dari www-data)
            SOCK_GROUP="www-data"
            id nginx >/dev/null 2>&1 && SOCK_GROUP="nginx"
            sed -i -E "s|^;?\s*listen\.group\s*=.*|listen.group = ${SOCK_GROUP}|g" "$POOL_CONF" 2>/dev/null || true
            sed -i -E "s|^;?\s*listen\.mode\s*=.*|listen.mode = 0660|g" "$POOL_CONF" 2>/dev/null || true
        fi

        systemctl daemon-reload 2>/dev/null || true
        systemctl enable "php${VERSION}-fpm"
        systemctl restart "php${VERSION}-fpm"

        clear_panel_service_cache

        if [ -S "/run/php/php${VERSION}-fpm.sock" ] || systemctl is-active --quiet "php${VERSION}-fpm"; then
            echo "[SUCCESS] PHP ${VERSION}-FPM installed and running on /run/php/php${VERSION}-fpm.sock!"
        else
            echo "[ERROR] PHP ${VERSION}-FPM was installed but failed to start. Checking service status:"
            systemctl status "php${VERSION}-fpm" --no-pager || true
            exit 1
        fi
        ;;

    update)
        echo "[INFO] Patching and updating PHP $VERSION against CVE-2026-109618 / CVE-2026-109619..."
        ensure_php_repo
        wait_for_apt_lock
        DEBIAN_FRONTEND=noninteractive apt-get install --only-upgrade -y --allow-downgrades \
            -o Dpkg::Options::="--force-confdef" \
            -o Dpkg::Options::="--force-confold" \
            php-common "php${VERSION}-*"
        systemctl reload "php${VERSION}-fpm" || systemctl restart "php${VERSION}-fpm"
        clear_panel_service_cache
        echo "[SUCCESS] PHP $VERSION successfully patched and reloaded!"
        ;;

    uninstall)
        echo "[WARNING] Stopping PHP $VERSION-FPM service..."
        systemctl stop "php${VERSION}-fpm" || true
        systemctl disable "php${VERSION}-fpm" || true

        echo "[INFO] Purging PHP $VERSION packages..."
        wait_for_apt_lock
        DEBIAN_FRONTEND=noninteractive apt-get purge -y "php${VERSION}*" || true
        DEBIAN_FRONTEND=noninteractive apt-get autoremove -y || true
        clear_panel_service_cache
        echo "[SUCCESS] PHP $VERSION uninstalled."
        ;;

    restart)
        echo "[INFO] Restarting PHP $VERSION-FPM..."
        mkdir -p /run/php
        chown www-data:www-data /run/php 2>/dev/null || true
        chmod 0755 /run/php 2>/dev/null || true
        systemctl restart "php${VERSION}-fpm"
        clear_panel_service_cache
        echo "[SUCCESS] PHP $VERSION-FPM restarted."
        ;;

    *)
        echo "Usage: $0 {install|update|uninstall|restart} <version>"
        exit 1
        ;;
esac
