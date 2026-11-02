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
        $phpVersions = ['7.4', '8.0', '8.1', '8.2', '8.3', '8.4', '8.5'];
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
            'cf_account_id'                 => Database::getSetting('cf_account_id', ''),
            'cf_tunnel_id'                  => Database::getSetting('cf_tunnel_id', ''),
            'update_repo_url'               => Database::getSetting('update_repo_url', 'https://github.com/uwaisalqorni/almusa-panel'),
            // Flag indikator keberadaan rahasia (agar nilai asli tidak diekspos mentah ke HTML/DOM)
            'has_cf_api_token'              => !empty(Database::getSetting('cf_api_token', '')),
            'has_cf_tunnel_token'           => !empty(Database::getSetting('cf_tunnel_token', '')),
            'has_mysql_root_pass'           => !empty(Database::getSetting('mysql_root_pass', '')),
            'has_telegram_bot_token'        => !empty(Database::getSetting('notif_telegram_bot_token', '')),
            'has_discord_webhook_url'       => !empty(Database::getSetting('notif_discord_webhook_url', '')),
            // Notifikasi Darurat (Telegram & Discord)
            'notif_telegram_enabled'        => Database::getSetting('notif_telegram_enabled', '0'),
            'notif_telegram_chat_id'        => Database::getSetting('notif_telegram_chat_id', ''),
            'notif_discord_enabled'         => Database::getSetting('notif_discord_enabled', '0'),
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
        $pdo = Database::getInstance();
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
        if ($cfToken !== '') {
            Database::setSetting('cf_api_token', $cfToken);
        }
        Database::setSetting('cf_account_id', $cfAccount);
        Database::setSetting('cf_tunnel_id', $cfTunnelId);
        if ($cfTunnelToken !== '') {
            Database::setSetting('cf_tunnel_token', $cfTunnelToken);
        }
        $updateRepo = trim((string)($_POST['update_repo_url'] ?? ''));
        if ($updateRepo !== '') {
            Database::setSetting('update_repo_url', $updateRepo);
        }
        if ($mysqlPass !== '') {
            $oldPass = Database::getSetting('mysql_root_pass', '');
            if ($oldPass !== '' && $oldPass !== $mysqlPass) {
                Database::setSetting('mysql_root_pass_prev', $oldPass);
            }
            Database::setSetting('mysql_root_pass', $mysqlPass);

            // Sinkronisasi perubahan password ke dalam engine MySQL/MariaDB
            try {
                $pdoMy = null;
                $options = [
                    \PDO::ATTR_ERRMODE            => \PDO::ERRMODE_EXCEPTION,
                    \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
                ];

                // Coba 1: TCP dengan password lama
                try {
                    $pdoMy = new \PDO("mysql:host=127.0.0.1;port=3306;charset=utf8mb4", 'root', $oldPass, $options);
                } catch (\Throwable $e1) {
                    // Coba 2: Socket dengan password lama
                    try {
                        $pdoMy = new \PDO("mysql:unix_socket=/var/run/mysqld/mysqld.sock;charset=utf8mb4", 'root', $oldPass, $options);
                    } catch (\Throwable $e2) {
                        // Coba 3: Socket tanpa password (auth_socket)
                        try {
                            $pdoMy = new \PDO("mysql:unix_socket=/var/run/mysqld/mysqld.sock;charset=utf8mb4", 'root', '', $options);
                        } catch (\Throwable $e3) {}
                    }
                }

                if ($pdoMy) {
                    $quoted = $pdoMy->quote($mysqlPass);
                    // Pertahankan dual-auth (unix_socket + password) pada MariaDB agar akses root lokal tidak pernah terkunci
                    try {
                        $pdoMy->exec("ALTER USER 'root'@'localhost' IDENTIFIED VIA unix_socket OR mysql_native_password USING PASSWORD({$quoted});");
                    } catch (\Throwable $eAlter) {
                        $pdoMy->exec("ALTER USER 'root'@'localhost' IDENTIFIED BY {$quoted};");
                    }
                    $pdoMy->exec("CREATE USER IF NOT EXISTS 'root'@'127.0.0.1' IDENTIFIED BY {$quoted};");
                    $pdoMy->exec("ALTER USER 'root'@'127.0.0.1' IDENTIFIED BY {$quoted};");
                    $pdoMy->exec("GRANT ALL PRIVILEGES ON *.* TO 'root'@'localhost' WITH GRANT OPTION;");
                    $pdoMy->exec("GRANT ALL PRIVILEGES ON *.* TO 'root'@'127.0.0.1' WITH GRANT OPTION;");
                    $pdoMy->exec("FLUSH PRIVILEGES;");
                    $synced = true;
                }
            } catch (\Throwable $e) {
                $synced = false;
            }

            // Jika eksekusi langsung via web gagal, antrekan ke background worker (berjalan dengan hak akses root OS)
            if (empty($synced)) {
                $taskStmt = $pdo->prepare("
                    INSERT INTO tasks (title, action, payload, status)
                    VALUES (:title, 'mysql_root_pass', :payload, 'pending')
                ");
                $taskStmt->execute([
                    ':title'   => 'Sinkronisasi Password Root MySQL / MariaDB',
                    ':payload' => json_encode(['password' => $mysqlPass]),
                ]);
            }
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
        $entranceFile = $root . '/data/entrance.txt';
        @file_put_contents($entranceFile, $entrance);
        Database::fixPermissions($entranceFile, 0664);

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

        // Tingkatkan session_version agar seluruh sesi aktif di perangkat lain otomatis di-revoke
        $update = $pdo->prepare("UPDATE users SET password_hash = :hash, session_version = session_version + 1 WHERE id = :id");
        $update->execute([':hash' => $newHash, ':id' => $userId]);

        // Perbarui session_version sesi lokal saat ini dan regenerate session ID
        $newVer = (int)$pdo->query("SELECT session_version FROM users WHERE id = {$userId}")->fetchColumn();
        $_SESSION['session_version'] = $newVer;
        session_regenerate_id(true);

        // Hapus file kredensial awal jika masih ada
        $root = defined('PANEL_ROOT') ? PANEL_ROOT : dirname(__DIR__, 2);
        $initCreds = $root . '/data/initial_credentials.txt';
        if (file_exists($initCreds)) {
            @unlink($initCreds);
        }

        $_SESSION['settings_msg'] = 'Password berhasil diperbarui! Sesi login di perangkat lain telah diakhiri.';
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
        if ($tgToken !== '') {
            Database::setSetting('notif_telegram_bot_token', $tgToken);
        }
        Database::setSetting('notif_telegram_chat_id', $tgChatId);
        Database::setSetting('notif_discord_enabled', $dcEnabled);
        if ($dcWebhook !== '') {
            Database::setSetting('notif_discord_webhook_url', $dcWebhook);
        }
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
