<?php
/**
 * Almusa Server Control Panel - Automated Backup Cron Worker
 * 
 * Executed via Linux system cron according to user schedule.
 */

if (php_sapi_name() !== 'cli') {
    die("This script can only be run from the CLI.\n");
}

define('PANEL_ROOT', dirname(__DIR__));
require_once PANEL_ROOT . '/app/bootstrap.php';

use App\Core\Database;
use App\Services\BackupEngine;

$autoEnabled = (Database::getSetting('backup_auto_enabled', '0') === '1');
if (!$autoEnabled) {
    echo "[" . date('Y-m-d H:i:s') . "] Automated backups are currently disabled in panel settings. Exiting.\n";
    exit(0);
}

try {
    $result = BackupEngine::runScheduledBackup();
    if ($result['success']) {
        echo "[" . date('Y-m-d H:i:s') . "] [SUCCESS] Scheduled backup completed successfully.\n";
        exit(0);
    } else {
        echo "[" . date('Y-m-d H:i:s') . "] [ERROR] Scheduled backup failed: " . ($result['error'] ?? 'Unknown error') . "\n";
        exit(1);
    }
} catch (\Throwable $e) {
    echo "[" . date('Y-m-d H:i:s') . "] [EXCEPTION] " . $e->getMessage() . "\n";
    exit(1);
}
