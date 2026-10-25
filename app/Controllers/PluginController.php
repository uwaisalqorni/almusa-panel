<?php

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Database;
use App\Core\Response;
use App\Core\SystemMonitor;

class PluginController
{
    public function index(): void
    {
        $root = defined('PANEL_ROOT') ? PANEL_ROOT : dirname(__DIR__, 2);

        // Core plugins status
        $plugins = [
            'nginx' => [
                'name'        => 'nginx',
                'title'       => 'Nginx Web Server',
                'installed'   => file_exists('/usr/sbin/nginx') || SystemMonitor::isServiceActive('nginx'),
                'active'      => SystemMonitor::isServiceActive('nginx'),
                'description' => 'Server HTTP/HTTPS berkecepatan tinggi & reverse proxy teruji.',
            ],
            'mysql' => [
                'name'        => 'mysql',
                'title'       => 'MySQL / MariaDB Engine',
                'installed'   => file_exists('/usr/bin/mysql') || file_exists('/usr/bin/mariadb'),
                'active'      => SystemMonitor::isServiceActive('mysql') || SystemMonitor::isServiceActive('mariadb'),
                'description' => 'Database server relasional dengan strict PDO UTF8MB4.',
            ],
            'cloudflared' => [
                'name'        => 'cloudflared',
                'title'       => 'Cloudflare Tunnel Daemon',
                'installed'   => file_exists('/usr/local/bin/cloudflared') || file_exists('/usr/bin/cloudflared'),
                'active'      => SystemMonitor::isServiceActive('cloudflared'),
                'description' => 'Zero Trust Tunnel & Quick Tunnel trycloudflare 1-klik.',
            ],
        ];

        // PHP Multi-Versions
        $phpVersions = ['7.4', '8.0', '8.1', '8.2', '8.3', '8.4'];
        $phpList = [];
        foreach ($phpVersions as $ver) {
            $isInstalled = file_exists("/etc/php/{$ver}/fpm") || file_exists("/usr/bin/php{$ver}");
            $isActive = file_exists("/run/php/php{$ver}-fpm.sock") || SystemMonitor::isServiceActive("php{$ver}-fpm");
            $phpList[$ver] = [
                'version'   => $ver,
                'installed' => $isInstalled,
                'active'    => $isActive,
            ];
        }

        // Available Extensions
        $extensions = ['redis', 'imagick', 'memcached', 'xdebug', 'soap', 'intl', 'zip'];

        Response::view('plugins/index', [
            'title'      => 'App Store & Plugins',
            'user'       => Auth::user(),
            'plugins'    => $plugins,
            'phpList'    => $phpList,
            'extensions' => $extensions,
        ]);
    }

    public function install(): void
    {
        $plugin = trim($_POST['plugin'] ?? '');
        $version = trim($_POST['version'] ?? '');

        if (!in_array($plugin, ['nginx', 'php', 'mysql', 'cloudflared'])) {
            Response::json(['success' => false, 'error' => 'Plugin tidak valid.'], 400);
        }

        if ($plugin === 'php' && !preg_match('/^(7\.4|8\.0|8\.1|8\.2|8\.3|8\.4)$/', $version)) {
            Response::json(['success' => false, 'error' => 'Versi PHP tidak valid.'], 400);
        }

        $title = "Install " . strtoupper($plugin) . ($version ? " {$version}" : '');
        $pdo = Database::getInstance();
        $stmt = $pdo->prepare("
            INSERT INTO tasks (title, action, payload, status) 
            VALUES (:title, 'plugin_install', :payload, 'pending')
        ");
        $stmt->execute([
            ':title'   => $title,
            ':payload' => json_encode(['plugin' => $plugin, 'version' => $version]),
        ]);

        Response::json([
            'success' => true,
            'message' => "Tugas '{$title}' berhasil dijadwalkan ke antrean daemon.",
        ]);
    }

    public function uninstall(): void
    {
        $plugin = trim($_POST['plugin'] ?? '');
        $version = trim($_POST['version'] ?? '');

        if (!in_array($plugin, ['nginx', 'php', 'mysql', 'cloudflared'])) {
            Response::json(['success' => false, 'error' => 'Plugin tidak valid.'], 400);
        }

        // If PHP, check if any active site is using this version
        if ($plugin === 'php') {
            $pdo = Database::getInstance();
            $check = $pdo->prepare("SELECT COUNT(*) FROM sites WHERE php_version = :ver AND status = 'active'");
            $check->execute([':ver' => $version]);
            if ((int)$check->fetchColumn() > 0) {
                Response::json(['success' => false, 'error' => "PHP {$version} sedang digunakan oleh website aktif. Ubah versi PHP website terlebih dahulu."], 400);
            }
        }

        $title = "Uninstall " . strtoupper($plugin) . ($version ? " {$version}" : '');
        $pdo = Database::getInstance();
        $stmt = $pdo->prepare("
            INSERT INTO tasks (title, action, payload, status) 
            VALUES (:title, 'plugin_uninstall', :payload, 'pending')
        ");
        $stmt->execute([
            ':title'   => $title,
            ':payload' => json_encode(['plugin' => $plugin, 'version' => $version]),
        ]);

        Response::json([
            'success' => true,
            'message' => "Tugas '{$title}' berhasil dimasukkan ke antrean.",
        ]);
    }

    public function manageExtension(): void
    {
        $version = trim($_POST['version'] ?? '');
        $ext = trim($_POST['extension'] ?? '');
        $subAction = trim($_POST['sub_action'] ?? 'install');

        if (!preg_match('/^(7\.4|8\.0|8\.1|8\.2|8\.3|8\.4)$/', $version)) {
            Response::json(['success' => false, 'error' => 'Versi PHP tidak valid.'], 400);
        }

        $allowedExts = ['redis', 'imagick', 'memcached', 'xdebug', 'soap', 'intl', 'zip'];
        if (!in_array($ext, $allowedExts)) {
            Response::json(['success' => false, 'error' => 'Ekstensi tidak diizinkan.'], 400);
        }

        $title = ucfirst($subAction) . " Ekstensi php{$version}-{$ext}";
        $pdo = Database::getInstance();
        $stmt = $pdo->prepare("
            INSERT INTO tasks (title, action, payload, status) 
            VALUES (:title, 'php_ext_install', :payload, 'pending')
        ");
        $stmt->execute([
            ':title'   => $title,
            ':payload' => json_encode([
                'version'    => $version,
                'extension'  => $ext,
                'sub_action' => $subAction,
            ]),
        ]);

        Response::json([
            'success' => true,
            'message' => "Tugas '{$title}' dijadwalkan ke antrean.",
        ]);
    }
}
