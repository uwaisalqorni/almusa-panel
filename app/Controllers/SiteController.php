<?php

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Database;
use App\Core\Response;

class SiteController
{
    public function index(): void
    {
        $pdo = Database::getInstance();
        $sites = $pdo->query("SELECT * FROM sites ORDER BY id DESC")->fetchAll();

        $phpVersions = ['7.4', '8.0', '8.1', '8.2', '8.3', '8.4'];
        $rewrites = ['default', 'laravel', 'wordpress', 'thinkphp'];

        Response::view('sites/index', [
            'title'       => 'Websites & Virtual Hosts',
            'user'        => Auth::user(),
            'sites'       => $sites,
            'phpVersions' => $phpVersions,
            'rewrites'    => $rewrites,
        ]);
    }

    public function create(): void
    {
        $domain = strtolower(trim($_POST['domain'] ?? ''));
        $docroot = trim($_POST['docroot'] ?? '');
        $phpVer = trim($_POST['php_version'] ?? '8.3');
        $rewrite = trim($_POST['rewrite_template'] ?? 'default');

        // Validation
        if (!preg_match('/^[a-zA-Z0-9\.\-]+$/', $domain) || strlen($domain) < 3) {
            Response::json(['success' => false, 'error' => 'Nama domain tidak valid.'], 400);
        }

        if (empty($docroot)) {
            $docroot = "/www/wwwroot/{$domain}";
        }

        if (!preg_match('/^[a-zA-Z0-9_\.\/\-]+$/', $docroot)) {
            Response::json(['success' => false, 'error' => 'Path document root tidak valid.'], 400);
        }

        if (!in_array($phpVer, ['7.4', '8.0', '8.1', '8.2', '8.3', '8.4'])) {
            Response::json(['success' => false, 'error' => 'Versi PHP tidak didukung.'], 400);
        }

        if (!in_array($rewrite, ['default', 'laravel', 'wordpress', 'thinkphp'])) {
            $rewrite = 'default';
        }

        $pdo = Database::getInstance();

        // Check duplicate
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM sites WHERE domain = :d");
        $stmt->execute([':d' => $domain]);
        if ((int)$stmt->fetchColumn() > 0) {
            Response::json(['success' => false, 'error' => "Domain '{$domain}' sudah terdaftar di panel."], 400);
        }

        // Insert into SQLite
        $insert = $pdo->prepare("
            INSERT INTO sites (domain, docroot, php_version, rewrite_template, status)
            VALUES (:domain, :docroot, :php, :rewrite, 'active')
        ");
        $insert->execute([
            ':domain'  => $domain,
            ':docroot' => $docroot,
            ':php'     => $phpVer,
            ':rewrite' => $rewrite,
        ]);
        $siteId = (int)$pdo->lastInsertId();

        // Enqueue background task for root daemon
        $task = $pdo->prepare("
            INSERT INTO tasks (title, action, payload, status)
            VALUES (:title, 'vhost_create', :payload, 'pending')
        ");
        $task->execute([
            ':title'   => "Buat Virtual Host: {$domain} (PHP {$phpVer})",
            ':payload' => json_encode([
                'site_id'     => $siteId,
                'domain'      => $domain,
                'docroot'     => $docroot,
                'php_version' => $phpVer,
                'rewrite'     => $rewrite,
            ]),
        ]);

        Response::json([
            'success' => true,
            'message' => "Website '{$domain}' berhasil didaftarkan. Konfigurasi Nginx sedang diproses di background.",
            'site_id' => $siteId,
        ]);
    }

    public function switchPhp(): void
    {
        $siteId = (int)($_POST['site_id'] ?? 0);
        $newVersion = trim($_POST['php_version'] ?? '');

        if (!in_array($newVersion, ['7.4', '8.0', '8.1', '8.2', '8.3', '8.4'])) {
            Response::json(['success' => false, 'error' => 'Versi PHP tidak valid.'], 400);
        }

        $pdo = Database::getInstance();
        $stmt = $pdo->prepare("SELECT * FROM sites WHERE id = :id LIMIT 1");
        $stmt->execute([':id' => $siteId]);
        $site = $stmt->fetch();

        if (!$site) {
            Response::json(['success' => false, 'error' => 'Website tidak ditemukan.'], 404);
        }

        $domain = $site['domain'];

        // Update in database
        $update = $pdo->prepare("UPDATE sites SET php_version = :ver WHERE id = :id");
        $update->execute([':ver' => $newVersion, ':id' => $siteId]);

        // Enqueue task
        $task = $pdo->prepare("
            INSERT INTO tasks (title, action, payload, status)
            VALUES (:title, 'vhost_switch_php', :payload, 'pending')
        ");
        $task->execute([
            ':title'   => "Switch PHP {$domain} -> PHP {$newVersion}",
            ':payload' => json_encode([
                'domain'      => $domain,
                'php_version' => $newVersion,
            ]),
        ]);

        Response::json([
            'success' => true,
            'message' => "Versi PHP untuk {$domain} diubah ke PHP {$newVersion}. Nginx sedang di-reload.",
        ]);
    }

    public function delete(): void
    {
        $siteId = (int)($_POST['site_id'] ?? 0);
        $deleteFiles = !empty($_POST['delete_files']);

        $pdo = Database::getInstance();
        $stmt = $pdo->prepare("SELECT * FROM sites WHERE id = :id LIMIT 1");
        $stmt->execute([':id' => $siteId]);
        $site = $stmt->fetch();

        if (!$site) {
            Response::json(['success' => false, 'error' => 'Website tidak ditemukan.'], 404);
        }

        $domain = $site['domain'];
        $docroot = $site['docroot'] ?? '';

        // Kill active quick tunnels if running
        try {
            $tunnelStmt = $pdo->prepare("SELECT pid FROM tunnels WHERE site_id = :id");
            $tunnelStmt->execute([':id' => $siteId]);
            $pids = $tunnelStmt->fetchAll(\PDO::FETCH_COLUMN);
            foreach ($pids as $p) {
                if ($p && (int)$p > 0) {
                    @exec("kill -15 " . (int)$p . " 2>/dev/null || kill -9 " . (int)$p . " 2>/dev/null");
                }
            }
            $delTunnel = $pdo->prepare("DELETE FROM tunnels WHERE site_id = :id");
            $delTunnel->execute([':id' => $siteId]);
        } catch (\Throwable $te) {
            // Non-blocking tunnel cleanup
        }

        // Delete site record from DB
        $del = $pdo->prepare("DELETE FROM sites WHERE id = :id");
        $del->execute([':id' => $siteId]);

        // Enqueue delete task
        $task = $pdo->prepare("
            INSERT INTO tasks (title, action, payload, status)
            VALUES (:title, 'vhost_delete', :payload, 'pending')
        ");
        $task->execute([
            ':title'   => "Hapus Virtual Host: {$domain}" . ($deleteFiles ? " (beserta file)" : ""),
            ':payload' => json_encode([
                'domain'       => $domain,
                'docroot'      => $docroot,
                'delete_files' => $deleteFiles ? 1 : 0,
            ]),
        ]);

        Response::json([
            'success' => true,
            'message' => "Virtual Host {$domain} berhasil dihapus" . ($deleteFiles ? " beserta file dokumen" : "") . ".",
        ]);
    }
}
