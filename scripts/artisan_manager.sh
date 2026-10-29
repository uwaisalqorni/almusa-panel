#!/usr/bin/env bash
# ==============================================================================
# Almusa Server Control Panel - Laravel Artisan Task Runner
# ==============================================================================
set -e

ACTION="${1:-run}"
PROJECT_PATH="${2:-}"
PHP_VER="${3:-8.3}"
ARTISAN_CMD="${4:-migrate}"
EXTRA_ARGS="${5:-}"

echo "======================================================"
echo "[ARTISAN RUNNER] $(date '+%Y-%m-%d %H:%M:%S')"
echo "Project Path: ${PROJECT_PATH}"
echo "PHP Version : ${PHP_VER}"
echo "Artisan Cmd : ${ARTISAN_CMD}"
echo "======================================================"

if [ -z "$PROJECT_PATH" ]; then
    echo "[ERROR] Project path is required."
    exit 1
fi

# Clean path and verify it is within /www/wwwroot
REAL_PATH=$(readlink -f "$PROJECT_PATH" 2>/dev/null || echo "$PROJECT_PATH")
if [[ "$REAL_PATH" != /www/wwwroot/* ]]; then
    echo "[ERROR] Invalid project path. Must reside under /www/wwwroot"
    exit 1
fi

if [ ! -f "${REAL_PATH}/artisan" ]; then
    echo "[ERROR] Artisan binary not found in: ${REAL_PATH}"
    exit 1
fi

# Resolve PHP binary
PHP_BIN="/usr/bin/php${PHP_VER}"
if [ ! -x "$PHP_BIN" ]; then
    echo "[WARN] Specific PHP binary '${PHP_BIN}' not found or not executable. Falling back to system default 'php'..."
    PHP_BIN="$(command -v php || echo '/usr/bin/php')"
fi

echo "[INFO] Using PHP Binary: $("$PHP_BIN" -v | head -n 1)"

# Whitelist allowed commands
SAFE_CMD=""
case "$ARTISAN_CMD" in
    migrate)
        if [ "$EXTRA_ARGS" = "--seed" ]; then
            SAFE_CMD="migrate --force --seed"
        else
            SAFE_CMD="migrate --force"
        fi
        ;;
    "migrate:status")
        SAFE_CMD="migrate:status"
        ;;
    "migrate:rollback")
        SAFE_CMD="migrate:rollback --force"
        ;;
    "migrate:fresh")
        if [ "$EXTRA_ARGS" = "--seed" ]; then
            SAFE_CMD="migrate:fresh --force --seed"
        else
            SAFE_CMD="migrate:fresh --force"
        fi
        ;;
    "db:seed")
        SAFE_CMD="db:seed --force"
        ;;
    "optimize:clear")
        SAFE_CMD="optimize:clear"
        ;;
    "config:cache")
        SAFE_CMD="config:cache"
        ;;
    "route:cache")
        SAFE_CMD="route:cache"
        ;;
    "view:cache")
        SAFE_CMD="view:cache"
        ;;
    "key:generate")
        SAFE_CMD="key:generate --force"
        ;;
    "storage:link")
        SAFE_CMD="storage:link"
        ;;
    *)
        echo "[ERROR] Disallowed or unrecognized artisan command: '${ARTISAN_CMD}'"
        exit 1
        ;;
esac

echo "[EXEC] Running: ${PHP_BIN} artisan ${SAFE_CMD}"
echo "------------------------------------------------------"

# Ensure storage and bootstrap/cache permissions before and after running
mkdir -p "${REAL_PATH}/storage/framework/cache" \
         "${REAL_PATH}/storage/framework/sessions" \
         "${REAL_PATH}/storage/framework/views" \
         "${REAL_PATH}/storage/logs" \
         "${REAL_PATH}/bootstrap/cache" 2>/dev/null || true

chown -R www-data:www-data "${REAL_PATH}/storage" "${REAL_PATH}/bootstrap/cache" 2>/dev/null || true
chmod -R 775 "${REAL_PATH}/storage" "${REAL_PATH}/bootstrap/cache" 2>/dev/null || true

# Execute as www-data to prevent root ownership of logs or cache
cd "${REAL_PATH}"
if [ "$(id -u)" -eq 0 ]; then
    su -s /bin/bash www-data -c "cd $(printf '%q' "$REAL_PATH") && $(printf '%q' "$PHP_BIN") artisan $SAFE_CMD"
else
    "$PHP_BIN" artisan $SAFE_CMD
fi

EXIT_CODE=$?

echo "------------------------------------------------------"
if [ $EXIT_CODE -eq 0 ]; then
    echo "[SUCCESS] Artisan command '${SAFE_CMD}' executed successfully! (Exit Code: 0)"
else
    echo "[FAILED] Artisan command '${SAFE_CMD}' failed with exit code: ${EXIT_CODE}"
fi

# Ensure permissions remain clean
chown -R www-data:www-data "${REAL_PATH}/storage" "${REAL_PATH}/bootstrap/cache" 2>/dev/null || true

exit $EXIT_CODE
