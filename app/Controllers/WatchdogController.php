<?php

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Database;
use App\Core\Response;
use App\Services\WatchdogService;

class WatchdogController
{
    /**
     * Display Watchdog & Auto-Heal Monitoring Dashboard
     */
    public function index(): void
    {
        $services = WatchdogService::getMonitoredServices();
        $statuses = [];
        foreach ($services as $svc) {
            $statuses[$svc] = [
                'name'   => $svc,
                'status' => WatchdogService::getServiceStatus($svc),
                'label'  => self::formatServiceLabel($svc),
                'meta'   => self::formatServiceMeta($svc),
                'icon'   => self::getServiceIcon($svc),
            ];
        }

        $incidents = WatchdogService::getRecentIncidents(100);

        // Systemd timer status check
        $timerStatus = trim((string)@shell_exec("systemctl is-active almusa-watchdog.timer 2>/dev/null"));
        $timerActive = ($timerStatus === 'active');

        // Cron job check
        $cronActive = false;
        try {
            $pdo = Database::getInstance();
            $cronActive = (bool)$pdo->query("SELECT is_enabled FROM cron_jobs WHERE command LIKE '%watchdog.php%' LIMIT 1")->fetchColumn();
        } catch (\Throwable $e) {}

        Response::view('watchdog/index', [
            'title'       => 'Service Watchdog & Auto-Heal',
            'user'        => Auth::user(),
            'services'    => $statuses,
            'incidents'   => $incidents,
            'timerActive' => $timerActive,
            'cronActive'  => $cronActive,
        ]);
    }

    /**
     * Run manual watchdog cycle via AJAX
     */
    public function run(): void
    {
        $res = WatchdogService::run();
        Response::json($res);
    }

    /**
     * Get raw text incident log
     */
    public function getRawLog(): void
    {
        $panelRoot = defined('PANEL_ROOT') ? PANEL_ROOT : dirname(__DIR__, 2);
        $file = "{$panelRoot}/data/panel_incident.log";
        $content = file_exists($file) ? file_get_contents($file) : 'Belum ada catatan insiden.';
        Response::json(['content' => $content]);
    }

    /**
     * Clear incident log history
     */
    public function clearLog(): void
    {
        $panelRoot = defined('PANEL_ROOT') ? PANEL_ROOT : dirname(__DIR__, 2);
        $file = "{$panelRoot}/data/panel_incident.log";
        @file_put_contents($file, '');

        try {
            $pdo = Database::getInstance();
            $pdo->exec("DELETE FROM incident_logs");
        } catch (\Throwable $e) {}

        Response::json([
            'success' => true,
            'message' => 'Riwayat insiden watchdog berhasil dibersihkan!',
        ]);
    }

    private static function formatServiceLabel(string $service): string
    {
        return match ($service) {
            'nginx'          => 'Web Server & Reverse Proxy',
            'mariadb'        => 'MariaDB Database Server',
            'mysql'          => 'MySQL Database Server',
            'php8.2-fpm'     => 'PHP 8.2 FastCGI Process Manager',
            'php8.3-fpm'     => 'PHP 8.3 FastCGI Process Manager',
            'php8.4-fpm'     => 'PHP 8.4 FastCGI Process Manager',
            'cloudflared'    => 'Cloudflare Zero Trust Tunnel Daemon',
            'mypanel-worker' => 'Almusa Panel Task Daemon (Worker)',
            default          => strtoupper($service) . ' Service',
        };
    }

    private static function formatServiceMeta(string $service): string
    {
        return match ($service) {
            'nginx'          => 'Port 8088 / 80 · Global Web Daemon',
            'mariadb'        => 'Port 3306 · MySQL Local Socket Engine',
            'mysql'          => 'Port 3306 · MySQL Local Socket Engine',
            'php8.2-fpm'     => 'Socket: /run/php/php8.2-fpm.sock',
            'php8.3-fpm'     => 'Socket: /run/php/php8.3-fpm.sock',
            'php8.4-fpm'     => 'Socket: /run/php/php8.4-fpm.sock',
            'cloudflared'    => 'Argo Zero Trust Tunnel Daemon',
            'mypanel-worker' => 'Process: /www/panel/task.php',
            default          => 'Systemd Managed Unit',
        };
    }

    private static function getServiceIcon(string $service): string
    {
        if (str_starts_with($service, 'php')) return 'zap';
        if ($service === 'nginx') return 'server';
        if ($service === 'mariadb' || $service === 'mysql') return 'database';
        if ($service === 'cloudflared') return 'cloud';
        return 'shield';
    }
}
