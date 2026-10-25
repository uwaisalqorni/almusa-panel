<?php

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Database;
use App\Core\Response;
use App\Plugins\Cloudflared\CloudflareApi;

class TunnelController
{
    public function index(): void
    {
        $pdo = Database::getInstance();
        $tunnels = $pdo->query("
            SELECT t.*, s.domain 
            FROM tunnels t 
            LEFT JOIN sites s ON t.site_id = s.id 
            ORDER BY t.id DESC
        ")->fetchAll();

        $sites = $pdo->query("SELECT id, domain, tunnel_mode, tunnel_url FROM sites ORDER BY id DESC")->fetchAll();

        $cfApi = new CloudflareApi();
        $isApiReady = $cfApi->isConfigured();
        $isBinaryInstalled = file_exists('/usr/local/bin/cloudflared') || file_exists('/usr/bin/cloudflared');

        Response::view('tunnels/index', [
            'title'             => 'Cloudflare Tunnels',
            'user'              => Auth::user(),
            'tunnels'           => $tunnels,
            'sites'             => $sites,
            'isApiReady'        => $isApiReady,
            'isBinaryInstalled' => $isBinaryInstalled,
        ]);
    }

    public function quickStart(): void
    {
        $siteId = (int)($_POST['site_id'] ?? 0);
        $domain = trim($_POST['domain'] ?? '');

        $pdo = Database::getInstance();
        $stmt = $pdo->prepare("SELECT * FROM sites WHERE id = :id LIMIT 1");
        $stmt->execute([':id' => $siteId]);
        $site = $stmt->fetch();

        if (!$site) {
            Response::json(['success' => false, 'error' => 'Website tidak ditemukan.'], 404);
        }

        $domain = $site['domain'];

        $task = $pdo->prepare("
            INSERT INTO tasks (title, action, payload, status)
            VALUES (:title, 'tunnel_quick_start', :payload, 'pending')
        ");
        $task->execute([
            ':title'   => "Start Quick Tunnel: {$domain}",
            ':payload' => json_encode(['site_id' => $siteId, 'domain' => $domain]),
        ]);

        Response::json([
            'success' => true,
            'message' => "Quick Tunnel untuk {$domain} sedang dijalankan. Cek terminal untuk tautan publik.",
        ]);
    }

    public function quickStop(): void
    {
        $siteId = (int)($_POST['site_id'] ?? 0);
        $pid = (int)($_POST['pid'] ?? 0);

        $pdo = Database::getInstance();
        $task = $pdo->prepare("
            INSERT INTO tasks (title, action, payload, status)
            VALUES (:title, 'tunnel_quick_stop', :payload, 'pending')
        ");
        $task->execute([
            ':title'   => "Stop Quick Tunnel site #{$siteId}",
            ':payload' => json_encode(['site_id' => $siteId, 'pid' => $pid]),
        ]);

        Response::json([
            'success' => true,
            'message' => "Perintah stop Quick Tunnel telah dikirim ke antrean.",
        ]);
    }

    public function customPublish(): void
    {
        $siteId = (int)($_POST['site_id'] ?? 0);
        $hostname = strtolower(trim($_POST['custom_hostname'] ?? ''));

        if (!preg_match('/^[a-zA-Z0-9\.\-]+\.[a-zA-Z]{2,}$/', $hostname)) {
            Response::json(['success' => false, 'error' => 'Format hostname domain tidak valid.'], 400);
        }

        $cfApi = new CloudflareApi();
        if (!$cfApi->isConfigured()) {
            Response::json(['success' => false, 'error' => 'Kredensial Cloudflare API belum lengkap. Atur di menu Pengaturan Panel.'], 400);
        }

        $pdo = Database::getInstance();
        $stmt = $pdo->prepare("SELECT * FROM sites WHERE id = :id LIMIT 1");
        $stmt->execute([':id' => $siteId]);
        $site = $stmt->fetch();

        if (!$site) {
            Response::json(['success' => false, 'error' => 'Website tidak ditemukan.'], 404);
        }

        $tunnelId = Database::getSetting('cf_tunnel_id', '');

        try {
            // 1. Get Zone ID
            $zoneId = $cfApi->getZoneId($hostname);
            if (!$zoneId) {
                Response::json(['success' => false, 'error' => "Gagal menemukan Zone ID Cloudflare untuk domain {$hostname}."], 400);
            }

            // 2. Add Ingress Rule
            $cfApi->addPublicHostname($hostname, 'http://127.0.0.1:8088');

            // 3. Upsert CNAME
            $cnameTarget = "{$tunnelId}.cfargotunnel.com";
            $cfApi->upsertDnsCname($zoneId, $hostname, $cnameTarget);

            // 4. Update Database
            $url = "https://{$hostname}";
            $update = $pdo->prepare("UPDATE sites SET tunnel_mode = 'custom', tunnel_url = :url WHERE id = :id");
            $update->execute([':url' => $url, ':id' => $siteId]);

            $ins = $pdo->prepare("
                INSERT INTO tunnels (site_id, tunnel_type, public_hostname, local_service, status)
                VALUES (:sid, 'custom', :host, 'http://127.0.0.1:8088', 'running')
            ");
            $ins->execute([
                ':sid'  => $siteId,
                ':host' => $url,
            ]);

            Response::json([
                'success' => true,
                'message' => "Domain {$hostname} berhasil dipublikasikan via Cloudflare Tunnel Zero Trust!",
                'url'     => $url,
            ]);
        } catch (\Throwable $e) {
            Response::json(['success' => false, 'error' => $e->getMessage()], 500);
        }
    }
}
