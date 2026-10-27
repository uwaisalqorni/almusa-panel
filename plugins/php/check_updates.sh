#!/usr/bin/env bash
# ==============================================================================
# Almusa Panel - PHP APT Patch & Version Update Checker
# Compares Installed vs Candidate versions for CVE-2026-109618/CVE-2026-109619
# ==============================================================================

set -e

TARGET_VER="${1:-all}"
VERSIONS=("7.4" "8.0" "8.1" "8.2" "8.3" "8.4")

if [ "$TARGET_VER" != "all" ]; then
    VERSIONS=("$TARGET_VER")
fi

FIRST=1
echo "{"

for VER in "${VERSIONS[@]}"; do
    POLICY=$(apt-cache policy "php${VER}-fpm" 2>/dev/null || true)
    INSTALLED=$(echo "$POLICY" | grep -i "Installed:" | awk '{print $2}')
    CANDIDATE=$(echo "$POLICY" | grep -i "Candidate:" | awk '{print $2}')

    IS_INSTALLED=false
    UPDATE_REQ=false
    STATUS="not_installed"

    if [ -n "$INSTALLED" ] && [ "$INSTALLED" != "(none)" ]; then
        IS_INSTALLED=true
        if [ -n "$CANDIDATE" ] && [ "$CANDIDATE" != "(none)" ] && dpkg --compare-versions "$CANDIDATE" gt "$INSTALLED" 2>/dev/null; then
            UPDATE_REQ=true
            STATUS="update_required"
        else
            STATUS="patched"
        fi
    fi

    if [ $FIRST -eq 0 ]; then
        echo ","
    fi
    FIRST=0

    printf '  "%s": {\n' "$VER"
    printf '    "version": "%s",\n' "$VER"
    printf '    "is_installed": %s,\n' "$IS_INSTALLED"
    printf '    "installed_version": "%s",\n' "${INSTALLED:-}"
    printf '    "candidate_version": "%s",\n' "${CANDIDATE:-}"
    printf '    "update_required": %s,\n' "$UPDATE_REQ"
    printf '    "status": "%s"\n' "$STATUS"
    printf '  }'
done

echo ""
echo "}"
