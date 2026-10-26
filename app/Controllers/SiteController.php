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

    public function issueSsl(): void
    {
        $siteId = (int)($_POST['site_id'] ?? 0);
        $provider = trim($_POST['provider'] ?? 'letsencrypt');
        $email = trim($_POST['email'] ?? '');
        $forceHttps = !isset($_POST['force_https']) || !empty($_POST['force_https']) ? 1 : 0;

        $pdo = Database::getInstance();
        $stmt = $pdo->prepare("SELECT * FROM sites WHERE id = :id LIMIT 1");
        $stmt->execute([':id' => $siteId]);
        $site = $stmt->fetch();

        if (!$site) {
            Response::json(['success' => false, 'error' => 'Website tidak ditemukan.'], 404);
        }

        $domain = $site['domain'];
        $docroot = $site['docroot'];

        if (!str_contains($domain, '.') || str_ends_with($domain, '.local')) {
            Response::json([
                'success' => false,
                'error'   => "Domain '{$domain}' bukan nama domain publik (FQDN). Let's Encrypt membutuhkan domain publik yang memiliki minimal satu titik (contoh: domain.com).",
            ], 400);
        }

        if (empty($email)) {
            $email = "admin@{$domain}";
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            Response::json(['success' => false, 'error' => 'Format email notifikasi SSL tidak valid.'], 400);
        }

        // Enqueue background task for root daemon
        $task = $pdo->prepare("
            INSERT INTO tasks (title, action, payload, status)
            VALUES (:title, 'site_issue_ssl', :payload, 'pending')
        ");
        $task->execute([
            ':title'   => "Terbitkan Let's Encrypt SSL: {$domain}",
            ':payload' => json_encode([
                'site_id'     => $siteId,
                'domain'      => $domain,
                'docroot'     => $docroot,
                'email'       => $email,
                'force_https' => $forceHttps,
                'provider'    => $provider,
            ]),
        ]);

        Response::json([
            'success' => true,
            'message' => "Penerbitan Let's Encrypt SSL untuk '{$domain}' dijadwalkan di background queue.",
            'task_id' => (int)$pdo->lastInsertId(),
        ]);
    }

    public function disableSsl(): void
    {
        $siteId = (int)($_POST['site_id'] ?? 0);

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
            VALUES (:title, 'site_disable_ssl', :payload, 'pending')
        ");
        $task->execute([
            ':title'   => "Matikan SSL / HTTPS: {$domain}",
            ':payload' => json_encode([
                'site_id' => $siteId,
                'domain'  => $domain,
            ]),
        ]);

        Response::json([
            'success' => true,
            'message' => "Pencabutan SSL untuk '{$domain}' sedang diproses di background queue.",
            'task_id' => (int)$pdo->lastInsertId(),
        ]);
    }

    public function renewSsl(): void
    {
        $siteId = (int)($_POST['site_id'] ?? 0);

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
            VALUES (:title, 'site_renew_ssl', :payload, 'pending')
        ");
        $task->execute([
            ':title'   => "Perbarui Sertifikat SSL: {$domain}",
            ':payload' => json_encode([
                'site_id' => $siteId,
                'domain'  => $domain,
            ]),
        ]);

        Response::json([
            'success' => true,
            'message' => "Proses pembaruan SSL untuk '{$domain}' dijadwalkan di background queue.",
            'task_id' => (int)$pdo->lastInsertId(),
        ]);
    }

    public function sslStatus(): void
    {
        $siteId = (int)($_GET['site_id'] ?? 0);

        $pdo = Database::getInstance();
        $stmt = $pdo->prepare("SELECT * FROM sites WHERE id = :id LIMIT 1");
        $stmt->execute([':id' => $siteId]);
        $site = $stmt->fetch();

        if (!$site) {
            Response::json(['success' => false, 'error' => 'Website tidak ditemukan.'], 404);
        }

        $domain = $site['domain'];
        $certPath = "/etc/letsencrypt/live/{$domain}/fullchain.pem";
        $keyPath = "/etc/letsencrypt/live/{$domain}/privkey.pem";

        $hasCert = file_exists($certPath) && file_exists($keyPath);
        $details = null;

        if ($hasCert) {
            $certContent = @file_get_contents($certPath);
            if ($certContent) {
                $certData = @openssl_x509_parse($certContent);
                if ($certData) {
                    $validFrom = $certData['validFrom_time_t'] ?? 0;
                    $validTo = $certData['validTo_time_t'] ?? 0;
                    $now = time();
                    $daysLeft = (int)ceil(($validTo - $now) / 86400);

                    $issuer = $certData['issuer']['O'] ?? $certData['issuer']['CN'] ?? 'Let\'s Encrypt';
                    $subject = $certData['subject']['CN'] ?? $domain;

                    $details = [
                        'subject'    => $subject,
                        'issuer'     => $issuer,
                        'valid_from' => date('Y-m-d H:i:s', $validFrom),
                        'valid_to'   => date('Y-m-d H:i:s', $validTo),
                        'days_left'  => $daysLeft,
                        'is_expired' => $now > $validTo,
                        'cert_file'  => $certPath,
                        'key_file'   => $keyPath,
                    ];
                }
            }
        }

        Response::json([
            'success'     => true,
            'domain'      => $domain,
            'ssl_enabled' => (int)($site['ssl_enabled'] ?? 0) === 1,
            'has_cert'    => $hasCert,
            'details'     => $details,
        ]);
    }

    /**
     * API: Get realtime access or error log for a website
     */
    public function getLogs(): void
    {
        $siteId = (int)($_GET['site_id'] ?? 0);
        $type = ($_GET['type'] ?? 'access') === 'error' ? 'error' : 'access';
        $limit = max(10, min(500, (int)($_GET['lines'] ?? 50)));

        $pdo = Database::getInstance();
        $stmt = $pdo->prepare("SELECT * FROM sites WHERE id = :id LIMIT 1");
        $stmt->execute([':id' => $siteId]);
        $site = $stmt->fetch();

        if (!$site) {
            Response::json(['success' => false, 'error' => 'Website tidak ditemukan.'], 404);
        }

        $domain = $site['domain'];
        $logPath = "/var/log/nginx/{$domain}_{$type}.log";

        if (!file_exists($logPath)) {
            Response::json([
                'success'     => true,
                'domain'      => $domain,
                'type'        => $type,
                'file'        => $logPath,
                'exists'      => false,
                'size'        => 0,
                'size_human'  => '0 B',
                'lines'       => [],
                'total_lines' => 0,
                'updated_at'  => date('Y-m-d H:i:s'),
            ]);
            return;
        }

        $size = filesize($logPath);
        $sizeHuman = $this->formatBytes($size);

        // Read last $limit lines
        $lines = $this->readTailLines($logPath, $limit);

        Response::json([
            'success'     => true,
            'domain'      => $domain,
            'type'        => $type,
            'file'        => $logPath,
            'exists'      => true,
            'size'        => $size,
            'size_human'  => $sizeHuman,
            'lines'       => $lines,
            'total_lines' => count($lines),
            'updated_at'  => date('Y-m-d H:i:s', filemtime($logPath) ?: time()),
        ]);
    }

    /**
     * API: Clear access or error log for a website
     */
    public function clearLog(): void
    {
        $siteId = (int)($_POST['site_id'] ?? 0);
        $type = trim($_POST['type'] ?? 'access'); // 'access', 'error', 'all'

        $pdo = Database::getInstance();
        $stmt = $pdo->prepare("SELECT * FROM sites WHERE id = :id LIMIT 1");
        $stmt->execute([':id' => $siteId]);
        $site = $stmt->fetch();

        if (!$site) {
            Response::json(['success' => false, 'error' => 'Website tidak ditemukan.'], 404);
        }

        $domain = $site['domain'];
        $accessLog = "/var/log/nginx/{$domain}_access.log";
        $errorLog = "/var/log/nginx/{$domain}_error.log";

        $cleared = false;

        // Try direct truncate first if writable
        if ($type === 'access' || $type === 'all') {
            if (file_exists($accessLog) && is_writable($accessLog)) {
                @file_put_contents($accessLog, '');
                $cleared = true;
            }
        }
        if ($type === 'error' || $type === 'all') {
            if (file_exists($errorLog) && is_writable($errorLog)) {
                @file_put_contents($errorLog, '');
                $cleared = true;
            }
        }

        // If not writable by www-data/afandi, enqueue background task for root daemon
        if (!$cleared) {
            $task = $pdo->prepare("
                INSERT INTO tasks (title, action, payload, status)
                VALUES (:title, 'site_clear_log', :payload, 'pending')
            ");
            $task->execute([
                ':title'   => "Bersihkan Log ({$type}): {$domain}",
                ':payload' => json_encode([
                    'domain' => $domain,
                    'type'   => $type,
                ]),
            ]);
        }

        Response::json([
            'success' => true,
            'message' => "Log {$type} untuk {$domain} berhasil dibersihkan.",
        ]);
    }

    private function formatBytes(int $bytes): string
    {
        if ($bytes >= 1073741824) {
            return number_format($bytes / 1073741824, 2) . ' GB';
        }
        if ($bytes >= 1048576) {
            return number_format($bytes / 1048576, 2) . ' MB';
        }
        if ($bytes >= 1024) {
            return number_format($bytes / 1024, 1) . ' KB';
        }
        return $bytes . ' B';
    }

    private function readTailLines(string $filePath, int $lines = 50): array
    {
        if (!file_exists($filePath) || filesize($filePath) === 0) {
            return [];
        }

        $f = @fopen($filePath, 'rb');
        if (!$f) {
            return [];
        }

        $buffer = '';
        $lineList = [];
        $chunkSize = 4096;
        $fileSize = filesize($filePath);
        $offset = $fileSize;

        while ($offset > 0 && count($lineList) <= $lines) {
            $seek = max(0, $offset - $chunkSize);
            $readSize = $offset - $seek;
            fseek($f, $seek);
            $chunk = fread($f, $readSize);
            $buffer = $chunk . $buffer;
            $offset = $seek;

            $parts = explode("\n", $buffer);
            if ($offset > 0) {
                $buffer = array_shift($parts);
            }
            $lineList = $parts;
        }
        fclose($f);

        $lineList = array_filter($lineList, static fn($l) => trim($l) !== '');
        return array_values(array_slice($lineList, -$lines));
    }
}
