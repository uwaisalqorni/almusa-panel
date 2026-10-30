<?php

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Database;
use App\Core\Response;
use App\Core\SystemMonitor;
use App\Core\Updater;
use App\Services\NotificationService;

class DashboardController
{
    public function index(): void
    {
        $pdo = Database::getInstance();

        $sitesCount = (int)$pdo->query("SELECT COUNT(*) FROM sites")->fetchColumn();
        $dbCount = (int)$pdo->query("SELECT COUNT(*) FROM databases")->fetchColumn();
        $tunnelCount = (int)$pdo->query("SELECT COUNT(*) FROM tunnels WHERE status = 'running'")->fetchColumn();
        $taskPending = (int)$pdo->query("SELECT COUNT(*) FROM tasks WHERE status = 'pending'")->fetchColumn();

        $recentSites = $pdo->query("SELECT * FROM sites ORDER BY id DESC LIMIT 5")->fetchAll();
        $recentTasks = $pdo->query("SELECT * FROM tasks ORDER BY id DESC LIMIT 5")->fetchAll();

        $stats = SystemMonitor::getStats();

        // Check common services
        $services = [
            'nginx'       => SystemMonitor::isServiceActive('nginx'),
            'mysql'       => SystemMonitor::isServiceActive('mysql') || SystemMonitor::isServiceActive('mariadb'),
            'cloudflared' => SystemMonitor::isServiceActive('cloudflared'),
        ];

        // Check PHP-FPM versions installed
        $phpVersions = ['7.4', '8.0', '8.1', '8.2', '8.3', '8.4'];
        $phpStatus = [];
        foreach ($phpVersions as $ver) {
            $sock = "/run/php/php{$ver}-fpm.sock";
            $isActive = file_exists($sock) || SystemMonitor::isServiceActive("php{$ver}-fpm");
            $phpStatus[$ver] = $isActive;
        }

        Response::view('dashboard/index', [
            'title'       => 'Dashboard',
            'user'        => Auth::user(),
            'sitesCount'  => $sitesCount,
            'dbCount'     => $dbCount,
            'tunnelCount' => $tunnelCount,
            'taskPending' => $taskPending,
            'recentSites' => $recentSites,
            'recentTasks' => $recentTasks,
            'stats'       => $stats,
            'services'    => $services,
            'phpStatus'   => $phpStatus,
        ]);
    }

    public function stats(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
        header('Cache-Control: no-store, no-cache, must-revalidate');
        $stats = SystemMonitor::getStats();
        Response::json([
            'success' => true,
            'data'    => $stats,
        ]);
    }

    public function settings(): void
    {
        $settings = [
            'panel_title'                   => Database::getSetting('panel_title', 'Almusa Server Panel'),
            'entrance_path'                 => Database::getSetting('entrance_path', 'almusa'),
            'panel_port'                    => Database::getSetting('panel_port', '64448'),
            'cf_api_token'                  => Database::getSetting('cf_api_token', ''),
            'cf_account_id'                 => Database::getSetting('cf_account_id', ''),
            'cf_tunnel_id'                  => Database::getSetting('cf_tunnel_id', ''),
            'cf_tunnel_token'               => Database::getSetting('cf_tunnel_token', ''),
            'update_repo_url'               => Database::getSetting('update_repo_url', 'https://github.com/uwaisalqorni/almusa-panel'),
            'mysql_root_pass'               => Database::getSetting('mysql_root_pass', ''),
            // Notifikasi Darurat (Telegram & Discord)
            'notif_telegram_enabled'        => Database::getSetting('notif_telegram_enabled', '0'),
            'notif_telegram_bot_token'      => Database::getSetting('notif_telegram_bot_token', ''),
            'notif_telegram_chat_id'        => Database::getSetting('notif_telegram_chat_id', ''),
            'notif_discord_enabled'         => Database::getSetting('notif_discord_enabled', '0'),
            'notif_discord_webhook_url'     => Database::getSetting('notif_discord_webhook_url', ''),
            'notif_trigger_service_restart' => Database::getSetting('notif_trigger_service_restart', '1'),
            'notif_trigger_disk_usage'      => Database::getSetting('notif_trigger_disk_usage', '1'),
            'notif_trigger_brute_force'     => Database::getSetting('notif_trigger_brute_force', '1'),
        ];

        Response::view('dashboard/settings', [
            'title'    => 'Pengaturan Panel',
            'user'     => Auth::user(),
            'settings' => $settings,
            'message'  => $_SESSION['settings_msg'] ?? null,
            'error'    => $_SESSION['settings_err'] ?? null,
        ]);
        unset($_SESSION['settings_msg'], $_SESSION['settings_err']);
    }

    public function saveSettings(): void
    {
        $title = trim($_POST['panel_title'] ?? 'Almusa Server Panel');
        $entrance = trim($_POST['entrance_path'] ?? '');
        $port = trim($_POST['panel_port'] ?? '64448');
        $cfToken = trim($_POST['cf_api_token'] ?? '');
        $cfAccount = trim($_POST['cf_account_id'] ?? '');
        $cfTunnelId = trim($_POST['cf_tunnel_id'] ?? '');
        $cfTunnelToken = trim($_POST['cf_tunnel_token'] ?? '');
        $mysqlPass = trim($_POST['mysql_root_pass'] ?? '');

        // Validation
        if (!preg_match('/^[a-zA-Z0-9_\-]{4,32}$/', $entrance)) {
            $_SESSION['settings_err'] = 'Path entrance harus 4-32 karakter alfanumerik (huruf, angka, _, -).';
            Response::redirect('/settings');
        }

        $portNum = (int)$port;
        if ($portNum < 1024 || $portNum > 65535) {
            $_SESSION['settings_err'] = 'Port harus antara 1024 sampai 65535.';
            Response::redirect('/settings');
        }

        $oldPort = Database::getSetting('panel_port', '64448');
        Database::setSetting('panel_title', $title);
        Database::setSetting('entrance_path', $entrance);
        Database::setSetting('panel_port', (string)$portNum);
        Database::setSetting('cf_api_token', $cfToken);
        Database::setSetting('cf_account_id', $cfAccount);
        Database::setSetting('cf_tunnel_id', $cfTunnelId);
        Database::setSetting('cf_tunnel_token', $cfTunnelToken);
        $updateRepo = trim((string)($_POST['update_repo_url'] ?? ''));
        if ($updateRepo !== '') {
            Database::setSetting('update_repo_url', $updateRepo);
        }
        if ($mysqlPass !== '') {
            Database::setSetting('mysql_root_pass', $mysqlPass);
        }

        // Update port Nginx jika port diubah
        $root = defined('PANEL_ROOT') ? PANEL_ROOT : dirname(__DIR__, 2);
        if ((string)$portNum !== (string)$oldPort) {
            $nginxConf = $root . '/config/nginx-panel.conf';
            if (file_exists($nginxConf)) {
                $conf = file_get_contents($nginxConf);
                $conf = preg_replace('/listen\s+\d+\s+default_server;/', "listen {$portNum} default_server;", $conf);
                $conf = preg_replace('/listen\s+\[::\]:\d+\s+default_server;/', "listen [::]:{$portNum} default_server;", $conf);
                @file_put_contents($nginxConf, $conf);

                // Antrekan reload Nginx
                $taskStmt = $pdo->prepare("
                    INSERT INTO tasks (title, action, payload, status)
                    VALUES (:title, 'service_reload', :payload, 'pending')
                ");
                $taskStmt->execute([
                    ':title'   => "Reload Nginx untuk menerapkan port panel {$portNum}",
                    ':payload' => json_encode(['service' => 'nginx']),
                ]);
            }
        }

        // Update entrance.txt file
        @file_put_contents($root . '/data/entrance.txt', $entrance);
        @chmod($root . '/data/entrance.txt', 0600);

        $_SESSION['settings_msg'] = 'Pengaturan berhasil disimpan. Jika port atau entrance diubah, sesuaikan URL akses Anda.';
        Response::redirect('/settings');
    }

    public function changePassword(): void
    {
        $currentPass = (string)($_POST['current_password'] ?? '');
        $newPass = (string)($_POST['new_password'] ?? '');
        $confirmPass = (string)($_POST['confirm_password'] ?? '');

        if (strlen($newPass) < 8) {
            $_SESSION['settings_err'] = 'Password baru minimal 8 karakter.';
            Response::redirect('/settings');
        }

        if ($newPass !== $confirmPass) {
            $_SESSION['settings_err'] = 'Konfirmasi password baru tidak cocok.';
            Response::redirect('/settings');
        }

        $userId = Auth::id();
        $pdo = Database::getInstance();
        $stmt = $pdo->prepare("SELECT password_hash FROM users WHERE id = :id LIMIT 1");
        $stmt->execute([':id' => $userId]);
        $hash = $stmt->fetchColumn();

        if (!$hash || !password_verify($currentPass, $hash)) {
            $_SESSION['settings_err'] = 'Password saat ini salah.';
            Response::redirect('/settings');
        }

        $newHash = password_hash($newPass, PASSWORD_BCRYPT, ['cost' => 12]);
        $update = $pdo->prepare("UPDATE users SET password_hash = :hash WHERE id = :id");
        $update->execute([':hash' => $newHash, ':id' => $userId]);

        $_SESSION['settings_msg'] = 'Password berhasil diperbarui!';
        Response::redirect('/settings');
    }

    /**
     * Save Emergency Notification Settings (Telegram & Discord)
     */
    public function saveNotificationSettings(): void
    {
        $tgEnabled    = isset($_POST['notif_telegram_enabled']) ? '1' : '0';
        $tgToken      = trim((string)($_POST['notif_telegram_bot_token'] ?? ''));
        $tgChatId     = trim((string)($_POST['notif_telegram_chat_id'] ?? ''));
        $dcEnabled    = isset($_POST['notif_discord_enabled']) ? '1' : '0';
        $dcWebhook    = trim((string)($_POST['notif_discord_webhook_url'] ?? ''));
        $trigRestart  = isset($_POST['notif_trigger_service_restart']) ? '1' : '0';
        $trigDisk     = isset($_POST['notif_trigger_disk_usage']) ? '1' : '0';
        $trigBrute    = isset($_POST['notif_trigger_brute_force']) ? '1' : '0';

        Database::setSetting('notif_telegram_enabled', $tgEnabled);
        Database::setSetting('notif_telegram_bot_token', $tgToken);
        Database::setSetting('notif_telegram_chat_id', $tgChatId);
        Database::setSetting('notif_discord_enabled', $dcEnabled);
        Database::setSetting('notif_discord_webhook_url', $dcWebhook);
        Database::setSetting('notif_trigger_service_restart', $trigRestart);
        Database::setSetting('notif_trigger_disk_usage', $trigDisk);
        Database::setSetting('notif_trigger_brute_force', $trigBrute);

        $isAjax = (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest')
            || (str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json'));

        if ($isAjax) {
            Response::json([
                'success' => true,
                'message' => 'Pengaturan notifikasi darurat berhasil disimpan!',
            ]);
            return;
        }

        $_SESSION['settings_msg'] = 'Pengaturan notifikasi darurat berhasil disimpan!';
        Response::redirect('/settings');
    }

    /**
     * Test notification sending (Telegram or Discord)
     */
    public function testNotification(): void
    {
        $channel = trim((string)($_POST['channel'] ?? 'telegram'));
        $token   = trim((string)($_POST['token'] ?? ''));
        $chatId  = trim((string)($_POST['chat_id'] ?? ''));
        $webhook = trim((string)($_POST['webhook'] ?? ''));

        if ($channel === 'discord') {
            $res = NotificationService::testDiscord($webhook ?: null);
        } else {
            $res = NotificationService::testTelegram($token ?: null, $chatId ?: null);
        }

        Response::json($res);
    }

    /**
     * Check panel updates via Updater helper (with optional force re-check)
     */
    public function checkUpdate(): void
    {
        if (!Auth::check()) {
            Response::json(['success' => false, 'error' => 'Unauthorized'], 401);
            return;
        }

        $force = !empty($_GET['force']);
        $info = Updater::check($force);

        Response::json([
            'success' => true,
            'data'    => $info,
        ]);
    }

    /**
     * Schedules a self-update task via 1-click update
     */
    public function applyUpdate(): void
    {
        if (!Auth::check()) {
            Response::json(['success' => false, 'error' => 'Unauthorized'], 401);
            return;
        }

        $result = Updater::queueUpdate();
        Response::json($result);
    }
}
