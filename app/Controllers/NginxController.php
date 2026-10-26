<?php

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Database;
use App\Core\Response;
use App\Core\SystemMonitor;

class NginxController
{
    private string $confPath = '/etc/nginx/nginx.conf';

    /**
     * API: Get Nginx global config (raw + parsed) and service status
     */
    public function getConfig(): void
    {
        if (!file_exists($this->confPath)) {
            Response::json(['success' => false, 'error' => "File konfigurasi {$this->confPath} tidak ditemukan."], 404);
        }

        $raw = @file_get_contents($this->confPath) ?: '';

        // Parse key tuning parameters
        $clientMaxBody = '50M';
        if (preg_match('/client_max_body_size\s+([^;]+);/i', $raw, $m)) {
            $clientMaxBody = trim($m[1]);
        }

        $serverTokens = 'off';
        if (preg_match('/server_tokens\s+([^;]+);/i', $raw, $m)) {
            $serverTokens = trim($m[1]);
        }

        $gzip = 'on';
        if (preg_match('/(?<!#)\bgzip\s+(on|off);/i', $raw, $m)) {
            $gzip = trim($m[1]);
        }

        $keepalive = '65';
        if (preg_match('/keepalive_timeout\s+([0-9]+);/i', $raw, $m)) {
            $keepalive = trim($m[1]);
        }

        $workerProc = 'auto';
        if (preg_match('/worker_processes\s+([^;]+);/i', $raw, $m)) {
            $workerProc = trim($m[1]);
        }

        $workerConn = '1024';
        if (preg_match('/worker_connections\s+([0-9]+);/i', $raw, $m)) {
            $workerConn = trim($m[1]);
        }

        $fastcgiTimeout = '300';
        if (preg_match('/fastcgi_read_timeout\s+([0-9]+);/i', $raw, $m)) {
            $fastcgiTimeout = trim($m[1]);
        }

        // Get Nginx version
        $version = 'Nginx';
        $vOutput = @shell_exec('nginx -v 2>&1') ?: '';
        if (preg_match('/nginx\/([0-9\.]+)/i', $vOutput, $vm)) {
            $version = 'Nginx ' . $vm[1];
        }

        $isActive = SystemMonitor::isServiceActive('nginx');

        Response::json([
            'success' => true,
            'raw'     => $raw,
            'parsed'  => [
                'client_max_body_size' => $clientMaxBody,
                'server_tokens'        => $serverTokens,
                'gzip'                 => $gzip,
                'keepalive_timeout'    => $keepalive,
                'worker_processes'     => $workerProc,
                'worker_connections'   => $workerConn,
                'fastcgi_read_timeout' => $fastcgiTimeout,
            ],
            'status' => [
                'is_active' => $isActive,
                'version'   => $version,
                'path'      => $this->confPath,
            ],
        ]);
    }

    /**
     * API: Save global Nginx configuration (Quick GUI or Raw Code Editor)
     */
    public function saveConfig(): void
    {
        $mode = trim($_POST['mode'] ?? 'quick');
        $raw = @file_get_contents($this->confPath) ?: '';

        if ($mode === 'raw') {
            $content = (string)($_POST['content'] ?? '');
            if (empty($content) || !str_contains($content, 'http {')) {
                Response::json(['success' => false, 'error' => 'Konfigurasi tidak valid: blok http { ... } wajib ada.'], 400);
            }
        } else {
            // Quick Tuning mode: safely inject/replace directives
            $clientMaxBody = trim($_POST['client_max_body_size'] ?? '50M');
            $serverTokens  = ($_POST['server_tokens'] ?? 'off') === 'on' ? 'on' : 'off';
            $gzip          = ($_POST['gzip'] ?? 'on') === 'off' ? 'off' : 'on';
            $keepalive     = (int)($_POST['keepalive_timeout'] ?? 65);
            $workerProc    = trim($_POST['worker_processes'] ?? 'auto');
            $workerConn    = (int)($_POST['worker_connections'] ?? 1024);
            $fastcgiTimeout= (int)($_POST['fastcgi_read_timeout'] ?? 300);

            if ($keepalive <= 0) $keepalive = 65;
            if ($workerConn <= 0) $workerConn = 1024;
            if ($fastcgiTimeout <= 0) $fastcgiTimeout = 300;

            $content = $raw;

            // 1. worker_processes
            if (preg_match('/worker_processes\s+[^;]+;/i', $content)) {
                $content = preg_replace('/worker_processes\s+[^;]+;/i', "worker_processes {$workerProc};", $content);
            }

            // 2. worker_connections inside events { ... }
            if (preg_match('/worker_connections\s+[0-9]+;/i', $content)) {
                $content = preg_replace('/worker_connections\s+[0-9]+;/i', "worker_connections {$workerConn};", $content);
            }

            // 3. server_tokens inside http { ... }
            if (preg_match('/server_tokens\s+[^;]+;/i', $content)) {
                $content = preg_replace('/server_tokens\s+[^;]+;/i', "server_tokens {$serverTokens};", $content);
            } else {
                $content = preg_replace('/(http\s*\{)/i', "$1\n\tserver_tokens {$serverTokens};", $content, 1);
            }

            // 4. client_max_body_size
            if (preg_match('/client_max_body_size\s+[^;]+;/i', $content)) {
                $content = preg_replace('/client_max_body_size\s+[^;]+;/i', "client_max_body_size {$clientMaxBody};", $content);
            } else {
                $content = preg_replace('/(http\s*\{)/i', "$1\n\tclient_max_body_size {$clientMaxBody};", $content, 1);
            }

            // 5. keepalive_timeout
            if (preg_match('/keepalive_timeout\s+[0-9]+;/i', $content)) {
                $content = preg_replace('/keepalive_timeout\s+[0-9]+;/i', "keepalive_timeout {$keepalive};", $content);
            } else {
                $content = preg_replace('/(http\s*\{)/i', "$1\n\tkeepalive_timeout {$keepalive};", $content, 1);
            }

            // 6. fastcgi_read_timeout
            if (preg_match('/fastcgi_read_timeout\s+[0-9]+;/i', $content)) {
                $content = preg_replace('/fastcgi_read_timeout\s+[0-9]+;/i', "fastcgi_read_timeout {$fastcgiTimeout};", $content);
            } else {
                $content = preg_replace('/(http\s*\{)/i', "$1\n\tfastcgi_read_timeout {$fastcgiTimeout};", $content, 1);
            }

            // 7. gzip
            if (preg_match('/(?<!#)\bgzip\s+(on|off);/i', $content)) {
                $content = preg_replace('/(?<!#)\bgzip\s+(on|off);/i', "gzip {$gzip};", $content);
            }
        }

        // Staging file
        $tmpFile = "/tmp/almusa_nginx_save_" . time() . ".conf";
        if (@file_put_contents($tmpFile, $content) === false) {
            Response::json(['success' => false, 'error' => 'Gagal membuat file staging sementara.'], 500);
        }
        @chmod($tmpFile, 0644);

        // Enqueue background task for root daemon
        $pdo = Database::getInstance();
        $stmt = $pdo->prepare("
            INSERT INTO tasks (title, action, payload, status)
            VALUES (:title, 'nginx_save_global', :payload, 'pending')
        ");
        $stmt->execute([
            ':title'   => "Simpan & Validasi Nginx Global (Mode: " . ucfirst($mode) . ")",
            ':payload' => json_encode(['src_file' => $tmpFile]),
        ]);

        Response::json([
            'success' => true,
            'message' => 'Konfigurasi Nginx Global sedang diuji sintaks dan diterapkan di background queue.',
            'task_id' => (int)$pdo->lastInsertId(),
        ]);
    }

    /**
     * API: Test Nginx configuration syntax (nginx -t)
     */
    public function testConfig(): void
    {
        $content = trim($_POST['content'] ?? '');
        $tmpFile = '';

        if (!empty($content)) {
            $tmpFile = "/tmp/almusa_nginx_test_" . time() . ".conf";
            if (@file_put_contents($tmpFile, $content) === false) {
                Response::json(['success' => false, 'error' => 'Gagal membuat file staging tes.'], 500);
            }
            @chmod($tmpFile, 0644);
        }

        $pdo = Database::getInstance();
        $stmt = $pdo->prepare("
            INSERT INTO tasks (title, action, payload, status)
            VALUES (:title, 'nginx_test', :payload, 'pending')
        ");
        $stmt->execute([
            ':title'   => "Uji Sintaks Nginx Global (nginx -t)",
            ':payload' => json_encode(['src_file' => $tmpFile]),
        ]);

        Response::json([
            'success' => true,
            'message' => 'Pengujian sintaks Nginx dimulai di terminal background.',
            'task_id' => (int)$pdo->lastInsertId(),
        ]);
    }

    /**
     * API: Control Nginx service (reload, restart, stop, start)
     */
    public function serviceControl(): void
    {
        $cmd = strtolower(trim($_POST['command'] ?? 'reload'));
        if (!in_array($cmd, ['reload', 'restart', 'stop', 'start'])) {
            Response::json(['success' => false, 'error' => 'Perintah layanan tidak valid.'], 400);
        }

        $pdo = Database::getInstance();
        $stmt = $pdo->prepare("
            INSERT INTO tasks (title, action, payload, status)
            VALUES (:title, 'nginx_service', :payload, 'pending')
        ");
        $stmt->execute([
            ':title'   => "Nginx Service: " . strtoupper($cmd),
            ':payload' => json_encode(['command' => $cmd]),
        ]);

        Response::json([
            'success' => true,
            'message' => "Perintah '{$cmd}' Nginx telah dikirim ke background daemon.",
            'task_id' => (int)$pdo->lastInsertId(),
        ]);
    }
}
