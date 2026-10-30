<?php

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Database;
use App\Core\EnvManager;
use App\Core\Response;

class SiteController
{
    public function index(): void
    {
        $pdo = Database::getInstance();
        $sites = $pdo->query("SELECT * FROM sites ORDER BY id DESC")->fetchAll();

        foreach ($sites as &$site) {
            $site['is_laravel'] = ($site['rewrite_template'] === 'laravel') || EnvManager::isLaravelProject($site['docroot']);
        }
        unset($site);

        $databases = $pdo->query("SELECT id, db_name, db_user, charset, access_host FROM databases ORDER BY db_name ASC")->fetchAll();

        $phpVersions = ['7.4', '8.0', '8.1', '8.2', '8.3', '8.4', '8.5'];
        $rewrites = ['default', 'laravel', 'wordpress', 'thinkphp'];

        Response::view('sites/index', [
            'title'       => 'Websites & Virtual Hosts',
            'user'        => Auth::user(),
            'sites'       => $sites,
            'databases'   => $databases,
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

        if (!in_array($phpVer, ['7.4', '8.0', '8.1', '8.2', '8.3', '8.4', '8.5'])) {
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

        if (!in_array($newVersion, ['7.4', '8.0', '8.1', '8.2', '8.3', '8.4', '8.5'])) {
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

    public function saveCustomSsl(): void
    {
        $siteId = (int)($_POST['site_id'] ?? 0);
        $cert = trim($_POST['certificate'] ?? '');
        $key = trim($_POST['private_key'] ?? '');
        $forceHttps = !isset($_POST['force_https']) || !empty($_POST['force_https']) ? 1 : 0;

        $pdo = Database::getInstance();
        $stmt = $pdo->prepare("SELECT * FROM sites WHERE id = :id LIMIT 1");
        $stmt->execute([':id' => $siteId]);
        $site = $stmt->fetch();

        if (!$site) {
            Response::json(['success' => false, 'error' => 'Website tidak ditemukan.'], 404);
        }

        $domain = $site['domain'];

        if (empty($cert)) {
            Response::json(['success' => false, 'error' => 'Sertifikat SSL (Certificate) tidak boleh kosong.'], 400);
        }
        if (empty($key)) {
            Response::json(['success' => false, 'error' => 'Private Key tidak boleh kosong.'], 400);
        }

        // Validate certificate format
        if (!str_contains($cert, 'BEGIN CERTIFICATE')) {
            Response::json(['success' => false, 'error' => 'Format Sertifikat tidak valid. Harus menyertakan "-----BEGIN CERTIFICATE-----".'], 400);
        }

        $certData = @openssl_x509_parse($cert);
        if (!$certData) {
            Response::json(['success' => false, 'error' => 'Sertifikat SSL tidak valid atau rusak (gagal diparse oleh OpenSSL).'], 400);
        }

        // Validate private key
        $keyResource = @openssl_pkey_get_private($key);
        if (!$keyResource) {
            Response::json(['success' => false, 'error' => 'Format Private Key tidak valid atau terproteksi passphrase. Pastikan private key tanpa passphrase.'], 400);
        }

        // Verify key matches certificate
        if (!@openssl_x509_check_private_key($cert, $key)) {
            Response::json(['success' => false, 'error' => 'Private Key tidak cocok dengan Sertifikat SSL yang dimasukkan!'], 400);
        }

        // Write to staging files in /tmp with strict permissions
        $tmpDir = sys_get_temp_dir() . '/almusa_ssl_' . uniqid();
        if (!@mkdir($tmpDir, 0700, true)) {
            Response::json(['success' => false, 'error' => 'Gagal membuat direktori staging sementara.'], 500);
        }

        $certTmp = $tmpDir . '/cert.pem';
        $keyTmp  = $tmpDir . '/key.pem';

        if (@file_put_contents($certTmp, $cert . "\n") === false || @file_put_contents($keyTmp, $key . "\n") === false) {
            @unlink($certTmp);
            @unlink($keyTmp);
            @rmdir($tmpDir);
            Response::json(['success' => false, 'error' => 'Gagal menulis file staging sertifikat.'], 500);
        }

        @chmod($certTmp, 0644);
        @chmod($keyTmp, 0600);

        // Enqueue background task for root daemon
        $task = $pdo->prepare("
            INSERT INTO tasks (title, action, payload, status)
            VALUES (:title, 'site_custom_ssl', :payload, 'pending')
        ");
        $task->execute([
            ':title'   => "Pasang Custom / Paid SSL: {$domain}",
            ':payload' => json_encode([
                'site_id'     => $siteId,
                'domain'      => $domain,
                'cert_file'   => $certTmp,
                'key_file'    => $keyTmp,
                'force_https' => $forceHttps,
            ]),
        ]);

        Response::json([
            'success' => true,
            'message' => "Pemasangan Custom SSL untuk '{$domain}' dijadwalkan di background queue.",
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
        $dbProvider = (string)($site['ssl_provider'] ?? '');

        $customCertPath = "/etc/ssl/almusa/{$domain}/fullchain.pem";
        $customKeyPath  = "/etc/ssl/almusa/{$domain}/privkey.pem";
        $leCertPath     = "/etc/letsencrypt/live/{$domain}/fullchain.pem";
        $leKeyPath      = "/etc/letsencrypt/live/{$domain}/privkey.pem";

        $provider = 'none';
        $certPath = '';
        $keyPath = '';

        if ($dbProvider === 'custom' && file_exists($customCertPath) && file_exists($customKeyPath)) {
            $certPath = $customCertPath;
            $keyPath  = $customKeyPath;
            $provider = 'custom';
        } elseif ($dbProvider === 'letsencrypt' && file_exists($leCertPath) && file_exists($leKeyPath)) {
            $certPath = $leCertPath;
            $keyPath  = $leKeyPath;
            $provider = 'letsencrypt';
        } elseif (file_exists($customCertPath) && file_exists($customKeyPath)) {
            $certPath = $customCertPath;
            $keyPath  = $customKeyPath;
            $provider = 'custom';
        } elseif (file_exists($leCertPath) && file_exists($leKeyPath)) {
            $certPath = $leCertPath;
            $keyPath  = $leKeyPath;
            $provider = 'letsencrypt';
        }

        $hasCert = ($provider !== 'none');
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

                    $issuer = $certData['issuer']['O'] ?? $certData['issuer']['CN'] ?? ($provider === 'custom' ? 'Custom Certificate' : 'Let\'s Encrypt');
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
            'provider'    => $provider,
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

        // If not directly writable by web worker, enqueue background task for root daemon
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

    /**
     * Get Laravel status, project path, .env DB settings, and available databases
     */
    public function laravelStatus(): void
    {
        $siteId = (int)($_GET['site_id'] ?? 0);
        $pdo = Database::getInstance();
        $stmt = $pdo->prepare("SELECT * FROM sites WHERE id = :id LIMIT 1");
        $stmt->execute([':id' => $siteId]);
        $site = $stmt->fetch();

        if (!$site) {
            Response::json(['success' => false, 'error' => 'Website tidak ditemukan.'], 404);
        }

        $projectPath = EnvManager::resolveProjectRoot($site['docroot']);
        $isLaravel = EnvManager::isLaravelProject($site['docroot']);
        $envConfig = EnvManager::getDbConfig($site['docroot']);

        // Mask password for display
        $maskedConfig = $envConfig;
        if (!empty($maskedConfig['password'])) {
            $maskedConfig['password_masked'] = str_repeat('•', min(8, strlen($maskedConfig['password'])));
            unset($maskedConfig['password']);
        }

        $databases = $pdo->query("SELECT id, db_name, db_user, charset, access_host FROM databases ORDER BY db_name ASC")->fetchAll();

        Response::json([
            'success'      => true,
            'site_id'      => $siteId,
            'domain'       => $site['domain'],
            'docroot'      => $site['docroot'],
            'project_path' => $projectPath,
            'php_version'  => $site['php_version'],
            'is_laravel'   => $isLaravel,
            'env_config'   => $maskedConfig,
            'databases'    => $databases,
        ]);
    }

    /**
     * Connect a panel MySQL database directly into Laravel's .env file
     */
    public function laravelConnectDb(): void
    {
        $siteId = (int)($_POST['site_id'] ?? 0);
        $dbId = (int)($_POST['database_id'] ?? 0);

        $pdo = Database::getInstance();
        $stmt = $pdo->prepare("SELECT * FROM sites WHERE id = :id LIMIT 1");
        $stmt->execute([':id' => $siteId]);
        $site = $stmt->fetch();

        if (!$site) {
            Response::json(['success' => false, 'error' => 'Website tidak ditemukan.'], 404);
        }

        $stmtDb = $pdo->prepare("SELECT * FROM databases WHERE id = :id LIMIT 1");
        $stmtDb->execute([':id' => $dbId]);
        $db = $stmtDb->fetch();

        if (!$db) {
            Response::json(['success' => false, 'error' => 'Database tidak ditemukan di panel.'], 404);
        }

        $plainPass = base64_decode((string)$db['db_password_enc']);
        $projectPath = EnvManager::resolveProjectRoot($site['docroot']);

        $updated = EnvManager::updateEnv($projectPath, [
            'DB_CONNECTION' => 'mysql',
            'DB_HOST'       => '127.0.0.1',
            'DB_PORT'       => (string)($db['db_port'] ?? '3306'),
            'DB_DATABASE'   => $db['db_name'],
            'DB_USERNAME'   => $db['db_user'],
            'DB_PASSWORD'   => $plainPass,
        ]);

        if (!$updated) {
            Response::json(['success' => false, 'error' => 'Gagal memperbarui file .env proyek.'], 500);
        }

        Response::json([
            'success' => true,
            'message' => "File .env berhasil disinkronkan dengan database MySQL '{$db['db_name']}'!",
            'db_name' => $db['db_name'],
            'db_user' => $db['db_user'],
        ]);
    }

    /**
     * Dispatch background task for artisan command
     */
    public function laravelRunArtisan(): void
    {
        $siteId = (int)($_POST['site_id'] ?? 0);
        $command = trim($_POST['command'] ?? 'migrate');
        $extraArgs = trim($_POST['extra_args'] ?? '');

        $allowedCmds = [
            'migrate',
            'migrate:status',
            'migrate:rollback',
            'migrate:fresh',
            'db:seed',
            'optimize:clear',
            'config:cache',
            'route:cache',
            'view:cache',
            'key:generate',
            'storage:link',
        ];

        if (!in_array($command, $allowedCmds, true)) {
            Response::json(['success' => false, 'error' => 'Perintah artisan tidak diizinkan.'], 400);
        }

        $pdo = Database::getInstance();
        $stmt = $pdo->prepare("SELECT * FROM sites WHERE id = :id LIMIT 1");
        $stmt->execute([':id' => $siteId]);
        $site = $stmt->fetch();

        if (!$site) {
            Response::json(['success' => false, 'error' => 'Website tidak ditemukan.'], 404);
        }

        $projectPath = EnvManager::resolveProjectRoot($site['docroot']);
        $domain = $site['domain'];
        $phpVer = $site['php_version'] ?? '8.3';

        // Enqueue background task
        $task = $pdo->prepare("
            INSERT INTO tasks (title, action, payload, status)
            VALUES (:title, 'laravel_artisan', :payload, 'pending')
        ");
        $task->execute([
            ':title'   => "Laravel Artisan: {$command}" . ($extraArgs ? " {$extraArgs}" : "") . " [{$domain}]",
            ':payload' => json_encode([
                'site_id'      => $siteId,
                'domain'       => $domain,
                'project_path' => $projectPath,
                'php_version'  => $phpVer,
                'command'      => $command,
                'extra_args'   => $extraArgs,
            ]),
        ]);

        $taskId = (int)$pdo->lastInsertId();

        Response::json([
            'success' => true,
            'message' => "Perintah 'php artisan {$command}' telah dijadwalkan di background queue.",
            'task_id' => $taskId,
        ]);
    }
}

