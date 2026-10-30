<?php

namespace App\Services;

use App\Core\Database;
use App\Core\SystemMonitor;

class WatchdogService
{
    /**
     * Run watchdog inspection and self-healing cycle
     */
    public static function run(bool $dryRun = false): array
    {
        $panelRoot = defined('PANEL_ROOT') ? PANEL_ROOT : dirname(__DIR__, 2);
        $lockFile = "{$panelRoot}/data/watchdog.lock";
        $incidentLogFile = "{$panelRoot}/data/panel_incident.log";

        // Concurrency protection: prevent overlapping watchdog runs
        if (!file_exists($lockFile)) {
            @touch($lockFile);
            @chmod($lockFile, 0666);
        }
        $fp = @fopen($lockFile, 'c+');
        if ($fp) {
            @chmod($lockFile, 0666);
            if (!flock($fp, LOCK_EX | LOCK_NB)) {
                fclose($fp);
                return [
                    'success' => false,
                    'message' => 'Watchdog cycle already in progress. Skipped.',
                    'skipped' => true,
                ];
            }
        }

        $results = [
            'timestamp' => date('Y-m-d H:i:s'),
            'checked'   => [],
            'incidents' => [],
            'healed'    => [],
            'failed'    => [],
        ];

        // 1. Gather monitored system services
        $services = self::getMonitoredServices();

        foreach ($services as $service) {
            $status = self::getServiceStatus($service);
            $results['checked'][$service] = $status;

            if ($status !== 'active') {
                $results['incidents'][] = [
                    'service'    => $service,
                    'old_status' => $status,
                ];

                if ($dryRun) {
                    continue;
                }

                // Execute auto-healing restart
                $healRes = self::autoHealService($service, $status, $incidentLogFile);
                if ($healRes['recovered']) {
                    $results['healed'][] = $service;
                } else {
                    $results['failed'][] = $service;
                }
            }
        }

        // 2. Check Cloudflare Quick Tunnels
        self::checkQuickTunnels($dryRun, $incidentLogFile, $results);

        // 3. Check Disk Storage Warning (threshold: 85%)
        self::checkDiskStorageThreshold($incidentLogFile, $results);

        if ($fp) {
            flock($fp, LOCK_UN);
            fclose($fp);
        }

        return [
            'success'   => true,
            'timestamp' => $results['timestamp'],
            'checked'   => $results['checked'],
            'incidents' => $results['incidents'],
            'healed'    => $results['healed'],
            'failed'    => $results['failed'],
            'summary'   => count($results['incidents']) === 0
                ? 'Semua layanan terpantau sehat dan berjalan normal.'
                : 'Terdeteksi ' . count($results['incidents']) . ' insiden. ' . count($results['healed']) . ' berhasil dipulihkan secara otomatis.',
        ];
    }

    /**
     * Get list of all services that should be monitored
     */
    public static function getMonitoredServices(): array
    {
        $list = ['nginx'];

        // Database service (MariaDB or MySQL)
        if (self::isUnitAvailable('mariadb')) {
            $list[] = 'mariadb';
        } elseif (self::isUnitAvailable('mysql')) {
            $list[] = 'mysql';
        }

        // PHP-FPM versions (dynamic detection)
        $phpVersions = self::getInstalledPhpFpmServices();
        foreach ($phpVersions as $phpSvc) {
            $list[] = $phpSvc;
        }

        // Cloudflared system service (if unit exists/enabled)
        if (self::isUnitAvailable('cloudflared')) {
            $list[] = 'cloudflared';
        }

        // Almusa Panel Background Task Worker
        if (self::isUnitAvailable('mypanel-worker')) {
            $list[] = 'mypanel-worker';
        }

        return array_unique($list);
    }

    /**
     * Check if a systemd unit is available on the machine
     */
    public static function isUnitAvailable(string $unitName): bool
    {
        $clean = preg_replace('/[^a-zA-Z0-9_\-\.]/', '', $unitName);
        $check = @shell_exec("systemctl list-unit-files {$clean}.service 2>/dev/null | grep -i '{$clean}.service'");
        return !empty(trim((string)$check));
    }

    /**
     * Get real-time status of a service via systemctl is-active
     */
    public static function getServiceStatus(string $service): string
    {
        $clean = preg_replace('/[^a-zA-Z0-9_\-\.]/', '', $service);
        $out = @shell_exec("systemctl is-active {$clean} 2>/dev/null");
        return trim((string)$out) ?: 'unknown';
    }

    /**
     * Detect all installed PHP-FPM versions on the system
     */
    public static function getInstalledPhpFpmServices(): array
    {
        $services = [];
        $candidates = ['php8.1-fpm', 'php8.2-fpm', 'php8.3-fpm', 'php8.4-fpm', 'php8.5-fpm', 'php8.0-fpm', 'php7.4-fpm'];

        foreach ($candidates as $cand) {
            $cleanVer = str_replace(['php', '-fpm'], '', $cand);
            $hasSock = file_exists("/run/php/php{$cleanVer}-fpm.sock");
            $hasBin = file_exists("/usr/sbin/php-fpm{$cleanVer}");
            $hasUnit = self::isUnitAvailable($cand);

            if ($hasSock || $hasBin || $hasUnit) {
                $services[] = $cand;
            }
        }

        return $services;
    }

    /**
     * Attempt auto-healing for a down service
     */
    public static function autoHealService(string $service, string $oldStatus, string $incidentLogFile): array
    {
        $clean = preg_replace('/[^a-zA-Z0-9_\-\.]/', '', $service);
        $timestamp = date('Y-m-d H:i:s');

        // Execute systemctl restart
        $restartOutput = [];
        $exitCode = 0;
        exec("systemctl restart {$clean} 2>&1", $restartOutput, $exitCode);
        $restartMessage = trim(implode("\n", $restartOutput));

        // Allow grace period for initialization
        usleep(1500000); // 1.5 seconds

        // Verify recovery status
        $newStatus = self::getServiceStatus($clean);
        $recovered = ($newStatus === 'active');
        $recoveryLabel = $recovered ? 'BERHASIL_DIPULIHKAN' : 'GAGAL_DIPULIHKAN';

        // 1. Format incident log entry
        $logEntry = sprintf(
            "[%s] [INCIDENT] Layanan '%s' terdeteksi mati (status: %s). Auto-heal dieksekusi: %s (status baru: %s).%s\n",
            $timestamp,
            $clean,
            $oldStatus,
            $recoveryLabel,
            $newStatus,
            $restartMessage ? " Log restart: {$restartMessage}" : ""
        );

        @file_put_contents($incidentLogFile, $logEntry, FILE_APPEND);

        // 2. Persist to SQLite incident_logs table
        try {
            $pdo = Database::getInstance();
            $stmt = $pdo->prepare("
                INSERT INTO incident_logs (service_name, event_type, details, action_taken, recovery_status)
                VALUES (:service, 'service_down', :details, 'systemctl restart', :status)
            ");
            $stmt->execute([
                ':service' => $clean,
                ':details' => "Layanan mati (status lama: {$oldStatus}). Restart output: " . ($restartMessage ?: 'None'),
                ':status'  => $recovered ? 'recovered' : 'failed',
            ]);
        } catch (\Throwable $e) {
            // Non-blocking if database insert fails
        }

        // 3. Dispatch Notification hook (used by Langkah 4.2)
        try {
            if (class_exists('\\App\\Services\\NotificationService')) {
                \App\Services\NotificationService::sendServiceIncidentAlert($clean, $oldStatus, $recovered, $restartMessage);
            }
        } catch (\Throwable $e) {
            // Non-blocking notification
        }

        return [
            'recovered'  => $recovered,
            'old_status' => $oldStatus,
            'new_status' => $newStatus,
            'output'     => $restartMessage,
        ];
    }

    /**
     * Check Quick Tunnels and resurrect dead processes if marked active
     */
    private static function checkQuickTunnels(bool $dryRun, string $incidentLogFile, array &$results): void
    {
        try {
            $pdo = Database::getInstance();
            $stmt = $pdo->query("
                SELECT id, site_id, public_hostname, pid, status 
                FROM tunnels 
                WHERE status = 'running' AND pid IS NOT NULL
            ");
            $activeTunnels = $stmt->fetchAll();

            foreach ($activeTunnels as $tunnel) {
                $pid = (int)$tunnel['pid'];
                $isAlive = ($pid > 0 && @posix_kill($pid, 0));

                if (!$isAlive) {
                    $results['incidents'][] = [
                        'service'    => "quick_tunnel_#{$tunnel['site_id']}",
                        'old_status' => 'dead_pid_' . $pid,
                    ];

                    if (!$dryRun) {
                        $logEntry = sprintf(
                            "[%s] [INCIDENT] Quick Tunnel site #%d (PID %d) terdeteksi mati. Mengupdate status di panel.\n",
                            date('Y-m-d H:i:s'),
                            $tunnel['site_id'],
                            $pid
                        );
                        @file_put_contents($incidentLogFile, $logEntry, FILE_APPEND);

                        // Mark tunnel as stopped so panel UI is accurate
                        $up = $pdo->prepare("UPDATE tunnels SET status = 'stopped' WHERE id = :id");
                        $up->execute([':id' => $tunnel['id']]);
                    }
                }
            }
        } catch (\Throwable $e) {
            // Ignore if tunnels table is not accessed
        }
    }

    /**
     * Check disk storage threshold on root partition /
     */
    public static function checkDiskStorageThreshold(string $incidentLogFile, array &$results): void
    {
        $disk = SystemMonitor::getDiskUsage('/');
        $percent = (int)($disk['percent'] ?? 0);

        if ($percent >= 85) {
            $results['incidents'][] = [
                'service'    => 'disk_root',
                'old_status' => "high_usage_{$percent}%",
            ];

            // Throttle disk warning to at most once per 2 hours to avoid log spamming
            $pdo = Database::getInstance();
            $lastCheck = $pdo->query("
                SELECT created_at FROM incident_logs 
                WHERE event_type = 'disk_high' 
                ORDER BY id DESC LIMIT 1
            ")->fetchColumn();

            $shouldLog = true;
            if ($lastCheck) {
                $diff = time() - strtotime($lastCheck);
                if ($diff < 7200) {
                    $shouldLog = false;
                }
            }

            if ($shouldLog) {
                $logEntry = sprintf(
                    "[%s] [WARNING] Penggunaan disk storage '/' mencapai %d%% (%s / %s). Ambang batas aman 85%% terlampaui!\n",
                    date('Y-m-d H:i:s'),
                    $percent,
                    $disk['used_formatted'] ?? '',
                    $disk['total_formatted'] ?? ''
                );
                @file_put_contents($incidentLogFile, $logEntry, FILE_APPEND);

                try {
                    $stmt = $pdo->prepare("
                        INSERT INTO incident_logs (service_name, event_type, details, action_taken, recovery_status)
                        VALUES ('disk_root', 'disk_high', :details, 'alert_warning', 'warning')
                    ");
                    $stmt->execute([
                        ':details' => "Penggunaan disk / melebihi 85% (terpakai: {$percent}% | {$disk['used_formatted']} dari {$disk['total_formatted']})",
                    ]);

                    // Dispatch notification hook
                    if (class_exists('\\App\\Services\\NotificationService')) {
                        \App\Services\NotificationService::sendDiskAlert($percent, $disk['used_formatted'] ?? '', $disk['total_formatted'] ?? '');
                    }
                } catch (\Throwable $e) {
                    // Ignore DB errors
                }
            }
        }
    }

    /**
     * Get recent incidents from database
     */
    public static function getRecentIncidents(int $limit = 50): array
    {
        try {
            $pdo = Database::getInstance();
            $stmt = $pdo->prepare("SELECT * FROM incident_logs ORDER BY id DESC LIMIT :lim");
            $stmt->bindValue(':lim', $limit, \PDO::PARAM_INT);
            $stmt->execute();
            $rows = $stmt->fetchAll();

            foreach ($rows as &$r) {
                $r['time_ago'] = self::timeAgo($r['created_at']);
            }
            return $rows;
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * Relative time formatter
     */
    private static function timeAgo(string $datetime): string
    {
        $time = strtotime($datetime);
        $diff = time() - $time;

        if ($diff < 60) return 'Baru saja';
        if ($diff < 3600) return floor($diff / 60) . ' menit lalu';
        if ($diff < 86400) return floor($diff / 3600) . ' jam lalu';
        return date('d M Y H:i', $time);
    }
}
