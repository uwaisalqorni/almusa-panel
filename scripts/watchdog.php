#!/usr/bin/env php
<?php
/**
 * Almusa Server Control Panel - Health Watchdog & Auto-Heal Daemon
 *
 * Runs every 1 minute via cron or systemd timer.
 * Inspects essential services (Nginx, MariaDB/MySQL, PHP-FPM, Cloudflared)
 * and automatically triggers auto-healing if any service is down.
 */

declare(strict_types=1);

$root = dirname(__DIR__);
require_once "{$root}/app/bootstrap.php";

use App\Services\WatchdogService;

$args = $argv;
array_shift($args);

$isStatusOnly = in_array('--status', $args, true) || in_array('-s', $args, true);
$isDryRun = in_array('--dry-run', $args, true);

if ($isStatusOnly) {
    echo "=======================================================\n";
    echo "Almusa Health Watchdog - Service Inspection Status\n";
    echo "Time: " . date('Y-m-d H:i:s') . "\n";
    echo "=======================================================\n";

    $services = WatchdogService::getMonitoredServices();
    foreach ($services as $svc) {
        $status = WatchdogService::getServiceStatus($svc);
        $badge = ($status === 'active') ? "\033[32m[ACTIVE]\033[0m" : "\033[31m[{$status}]\033[0m";
        printf(" %-22s : %s\n", $svc, $badge);
    }
    echo "=======================================================\n";
    exit(0);
}

// Check if worker reload/restart was requested
$workerTrigger = "{$root}/data/restart_worker.trigger";
if (file_exists($workerTrigger)) {
    @unlink($workerTrigger);
    @shell_exec("systemctl restart mypanel-worker.service");
}

$start = microtime(true);
$res = WatchdogService::run($isDryRun);
$duration = round(microtime(true) - $start, 3);

if (!empty($res['skipped'])) {
    echo "[" . date('Y-m-d H:i:s') . "] [WATCHDOG] Another watchdog instance is currently running. Cycle skipped.\n";
    exit(0);
}

$incidentCount = count($res['incidents'] ?? []);
$healedCount = count($res['healed'] ?? []);
$failedCount = count($res['failed'] ?? []);

if ($incidentCount === 0) {
    echo "[" . date('Y-m-d H:i:s') . "] [WATCHDOG] OK - " . count($res['checked']) . " services healthy. ({$duration}s)\n";
} else {
    echo "[" . date('Y-m-d H:i:s') . "] [WATCHDOG] INCIDENT DETECTED! Incidents: {$incidentCount} | Auto-healed: {$healedCount} | Failed: {$failedCount} ({$duration}s)\n";
    foreach ($res['incidents'] as $inc) {
        $isHealed = in_array($inc['service'], $res['healed'] ?? [], true);
        $statusText = $isHealed ? "HEALED (RESTARTED)" : "FAILED TO RECOVER";
        echo "  -> Service: '{$inc['service']}' (was {$inc['old_status']}) => {$statusText}\n";
    }
}

exit($failedCount > 0 ? 1 : 0);
