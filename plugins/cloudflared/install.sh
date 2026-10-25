#!/usr/bin/env bash
# ==============================================================================
# Almusa Panel - Cloudflare Tunnel Binary Installer
# ==============================================================================

set -e

ACTION="${1:-install}"
TOKEN="${2:-}"

case "$ACTION" in
    install)
        echo "[INFO] Detecting system architecture..."
        ARCH=$(uname -m)
        case "$ARCH" in
            x86_64)  CF_ARCH="amd64" ;;
            aarch64) CF_ARCH="arm64" ;;
            armv7l)  CF_ARCH="arm" ;;
            *)       CF_ARCH="amd64" ;;
        esac

        echo "[INFO] Architecture: $CF_ARCH"
        URL="https://github.com/cloudflare/cloudflared/releases/latest/download/cloudflared-linux-${CF_ARCH}"

        echo "[INFO] Downloading cloudflared from $URL..."
        curl -fsSL "$URL" -o /usr/local/bin/cloudflared
        chmod +x /usr/local/bin/cloudflared

        if [ -n "$TOKEN" ]; then
            echo "[INFO] Installing cloudflared system service with Zero Trust token..."
            cloudflared service install "$TOKEN" || true
            systemctl enable cloudflared || true
            systemctl restart cloudflared || true
        fi

        echo "[SUCCESS] cloudflared $(/usr/local/bin/cloudflared --version) installed successfully!"
        ;;

    uninstall)
        echo "[INFO] Stopping and removing cloudflared service..."
        systemctl stop cloudflared 2>/dev/null || true
        systemctl disable cloudflared 2>/dev/null || true
        cloudflared service uninstall 2>/dev/null || true
        rm -f /usr/local/bin/cloudflared
        echo "[SUCCESS] cloudflared removed."
        ;;

    *)
        echo "Usage: $0 {install|uninstall} [tunnel_token]"
        exit 1
        ;;
esac
