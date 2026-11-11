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

        $siteDomain = $site['domain'];
        $tunnelId = Database::getSetting('cf_tunnel_id', '');

        // Detect host local LAN IP so container or local proxy can reach port 8088
        $localIp = '127.0.0.1';
        $ipCmd = shell_exec("ip -4 route get 1.1.1.1 2>/dev/null | awk '{print $7}'");
        $ipTrim = trim((string)$ipCmd);
        if (!empty($ipTrim) && filter_var($ipTrim, FILTER_VALIDATE_IP)) {
            $localIp = $ipTrim;
        }
        $localService = "http://{$localIp}:8088";

        try {
            // 1. Get Zone ID
            $zoneId = $cfApi->getZoneId($hostname);
            if (!$zoneId) {
                Response::json(['success' => false, 'error' => "Gagal menemukan Zone ID Cloudflare untuk domain {$hostname}."], 400);
            }

            // 2. Add Ingress Rule
            $cfApi->addPublicHostname($hostname, $localService, $siteDomain);

            // 3. Upsert CNAME
            $cnameTarget = "{$tunnelId}.cfargotunnel.com";
            $cfApi->upsertDnsCname($zoneId, $hostname, $cnameTarget);

            // 4. Update Database
            $url = "https://{$hostname}";
            $update = $pdo->prepare("UPDATE sites SET tunnel_mode = 'custom', tunnel_url = :url WHERE id = :id");
            $update->execute([':url' => $url, ':id' => $siteId]);

            // Clean up previous tunnel entry for this site
            $del = $pdo->prepare("DELETE FROM tunnels WHERE site_id = :sid");
            $del->execute([':sid' => $siteId]);

            $ins = $pdo->prepare("
                INSERT INTO tunnels (site_id, tunnel_type, public_hostname, local_service, status)
                VALUES (:sid, 'custom', :host, :service, 'running')
            ");
            $ins->execute([
                ':sid'     => $siteId,
                ':host'    => $url,
                ':service' => $localService,
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

    public function customUnpublish(): void
    {
        $siteId = (int)($_POST['site_id'] ?? 0);

        $pdo = Database::getInstance();
        $stmt = $pdo->prepare("SELECT * FROM sites WHERE id = :id LIMIT 1");
        $stmt->execute([':id' => $siteId]);
        $site = $stmt->fetch();

        if (!$site) {
            Response::json(['success' => false, 'error' => 'Website tidak ditemukan.'], 404);
        }

        $tunnelStmt = $pdo->prepare("SELECT public_hostname FROM tunnels WHERE site_id = :id AND tunnel_type = 'custom' LIMIT 1");
        $tunnelStmt->execute([':id' => $siteId]);
        $rawHost = (string)$tunnelStmt->fetchColumn();
        $hostname = preg_replace('#^https?://#', '', $rawHost);

        if (!empty($hostname)) {
            try {
                $cfApi = new CloudflareApi();
                if ($cfApi->isConfigured()) {
                    $cfApi->removePublicHostname($hostname);
                    $zoneId = $cfApi->getZoneId($hostname);
                    if ($zoneId) {
                        $cfApi->deleteDnsCname($zoneId, $hostname);
                    }
                }
            } catch (\Throwable $e) {
                // Non-fatal, continue DB cleanup
            }
        }

        // Clean up DB
        $del = $pdo->prepare("DELETE FROM tunnels WHERE site_id = :sid");
        $del->execute([':sid' => $siteId]);

        $upd = $pdo->prepare("UPDATE sites SET tunnel_mode = 'none', tunnel_url = '' WHERE id = :id");
        $upd->execute([':id' => $siteId]);

        Response::json([
            'success' => true,
            'message' => "Publikasi domain Zero Trust untuk {$site['domain']} berhasil dicabut.",
        ]);
    }

    public function setupTunnel(): void
    {
        $tunnelName = trim($_POST['tunnel_name'] ?? 'almusa-server-tunnel');
        $cfApi = new CloudflareApi();

        try {
            $created = $cfApi->createTunnel($tunnelName);
            $tunnelId = $created['tunnel_id'];
            $token = $created['token'];

            Database::setSetting('cf_tunnel_id', $tunnelId);
            if (!empty($token)) {
                Database::setSetting('cf_tunnel_token', $token);
                // Dispatch task to save token and start cloudflared service
                $pdo = Database::getInstance();
                $task = $pdo->prepare("
                    INSERT INTO tasks (title, action, payload, status)
                    VALUES (:title, 'cron_run', :payload, 'pending')
                ");
                $cmd = "mkdir -p /etc/cloudflared && echo '{$token}' > /etc/cloudflared/token && chmod 600 /etc/cloudflared/token && systemctl enable --now cloudflared 2>/dev/null || true";
                $task->execute([
                    ':title'   => 'Setup Cloudflare Zero Trust Daemon',
                    ':payload' => json_encode(['user' => 'root', 'command' => $cmd]),
                ]);
            }

            Response::json([
                'success'   => true,
                'message'   => "Tunnel Zero Trust '{$tunnelName}' berhasil dibuat dan terhubung ke server!",
                'tunnel_id' => $tunnelId,
            ]);
        } catch (\Throwable $e) {
            Response::json(['success' => false, 'error' => $e->getMessage()], 400);
        }
    }
}
