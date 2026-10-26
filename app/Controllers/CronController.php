<?php

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Database;
use App\Core\Response;

class CronController
{
    public function index(): void
    {
        $pdo = Database::getInstance();
        $cronJobs = $pdo->query("SELECT * FROM cron_jobs ORDER BY id DESC")->fetchAll();

        $panelRoot = defined('PANEL_ROOT') ? PANEL_ROOT : dirname(__DIR__, 2);
        $logsDir = $panelRoot . '/data/cron_logs';
        if (!is_dir($logsDir)) {
            @mkdir($logsDir, 0777, true);
        }

        foreach ($cronJobs as &$job) {
            $job['human_expr'] = self::describeExpression($job['expression']);
            $logFile = $logsDir . "/cron_{$job['id']}.log";
            $job['has_log'] = file_exists($logFile) && filesize($logFile) > 0;
            $job['log_size'] = $job['has_log'] ? self::formatBytes(filesize($logFile)) : '0 B';
        }
        unset($job);

        // Fetch hosted websites for easy command presets
        $sites = $pdo->query("SELECT id, domain, docroot FROM sites ORDER BY domain ASC")->fetchAll();

        // System cron file info
        $cronSystemFile = '/etc/cron.d/almusa-cron';
        $cronSystemStatus = [
            'file'          => $cronSystemFile,
            'exists'        => file_exists($cronSystemFile),
            'last_modified' => file_exists($cronSystemFile) ? date('Y-m-d H:i:s', filemtime($cronSystemFile)) : null,
            'total_active'  => count(array_filter($cronJobs, fn($j) => (int)$j['is_enabled'] === 1)),
        ];

        Response::view('cron/index', [
            'title'            => 'Cron Jobs Manager',
            'user'             => Auth::user(),
            'cronJobs'         => $cronJobs,
            'sites'            => $sites,
            'cronSystemStatus' => $cronSystemStatus,
        ]);
    }

    public function create(): void
    {
        $name = trim($_POST['name'] ?? '');
        $scheduleType = trim($_POST['schedule_type'] ?? 'custom');
        $expression = trim($_POST['expression'] ?? '* * * * *');
        $command = trim($_POST['command'] ?? '');
        $runAsUser = trim($_POST['run_as_user'] ?? 'afandi');
        $logMode = trim($_POST['log_mode'] ?? 'file');

        if (empty($name)) {
            Response::json(['success' => false, 'error' => 'Nama tugas wajib diisi.'], 422);
        }

        if (empty($command)) {
            Response::json(['success' => false, 'error' => 'Perintah shell wajib diisi.'], 422);
        }

        if (!self::isValidCronExpression($expression)) {
            Response::json(['success' => false, 'error' => 'Format cron expression tidak valid. Gunakan 5 bagian standar (cth: * * * * * atau 0 0 * * *).'], 422);
        }

        $validUsers = ['afandi', 'www-data', 'root'];
        if (!in_array($runAsUser, $validUsers, true)) {
            $runAsUser = 'afandi';
        }

        $pdo = Database::getInstance();
        $stmt = $pdo->prepare("
            INSERT INTO cron_jobs (name, schedule_type, expression, command, run_as_user, log_mode, is_enabled)
            VALUES (:name, :st, :expr, :cmd, :user, :lm, 1)
        ");
        $stmt->execute([
            ':name' => $name,
            ':st'   => $scheduleType,
            ':expr' => $expression,
            ':cmd'  => $command,
            ':user' => $runAsUser,
            ':lm'   => $logMode,
        ]);

        $cronId = (int)$pdo->lastInsertId();

        self::syncCronJobs();

        Response::json([
            'success' => true,
            'message' => "Tugas cron '{$name}' berhasil ditambahkan dan disinkronkan ke sistem.",
            'id'      => $cronId,
        ]);
    }

    public function update(): void
    {
        $id = (int)($_POST['id'] ?? 0);
        $name = trim($_POST['name'] ?? '');
        $scheduleType = trim($_POST['schedule_type'] ?? 'custom');
        $expression = trim($_POST['expression'] ?? '* * * * *');
        $command = trim($_POST['command'] ?? '');
        $runAsUser = trim($_POST['run_as_user'] ?? 'afandi');
        $logMode = trim($_POST['log_mode'] ?? 'file');

        $pdo = Database::getInstance();
        $stmt = $pdo->prepare("SELECT * FROM cron_jobs WHERE id = :id LIMIT 1");
        $stmt->execute([':id' => $id]);
        $existing = $stmt->fetch();

        if (!$existing) {
            Response::json(['success' => false, 'error' => 'Tugas cron tidak ditemukan.'], 404);
        }

        if (empty($name)) {
            Response::json(['success' => false, 'error' => 'Nama tugas wajib diisi.'], 422);
        }

        if (empty($command)) {
            Response::json(['success' => false, 'error' => 'Perintah shell wajib diisi.'], 422);
        }

        if (!self::isValidCronExpression($expression)) {
            Response::json(['success' => false, 'error' => 'Format cron expression tidak valid.'], 422);
        }

        $validUsers = ['afandi', 'www-data', 'root'];
        if (!in_array($runAsUser, $validUsers, true)) {
            $runAsUser = 'afandi';
        }

        $update = $pdo->prepare("
            UPDATE cron_jobs 
            SET name = :name, schedule_type = :st, expression = :expr, command = :cmd,
                run_as_user = :user, log_mode = :lm, updated_at = CURRENT_TIMESTAMP
            WHERE id = :id
        ");
        $update->execute([
            ':name' => $name,
            ':st'   => $scheduleType,
            ':expr' => $expression,
            ':cmd'  => $command,
            ':user' => $runAsUser,
            ':lm'   => $logMode,
            ':id'   => $id,
        ]);

        self::syncCronJobs();

        Response::json([
            'success' => true,
            'message' => "Tugas cron '{$name}' berhasil diperbarui.",
        ]);
    }

    public function toggle(): void
    {
        $id = (int)($_POST['id'] ?? 0);

        $pdo = Database::getInstance();
        $stmt = $pdo->prepare("SELECT * FROM cron_jobs WHERE id = :id LIMIT 1");
        $stmt->execute([':id' => $id]);
        $job = $stmt->fetch();

        if (!$job) {
            Response::json(['success' => false, 'error' => 'Tugas cron tidak ditemukan.'], 404);
        }

        $newState = (int)$job['is_enabled'] === 1 ? 0 : 1;

        $update = $pdo->prepare("UPDATE cron_jobs SET is_enabled = :state, updated_at = CURRENT_TIMESTAMP WHERE id = :id");
        $update->execute([':state' => $newState, ':id' => $id]);

        self::syncCronJobs();

        $statusText = $newState === 1 ? 'diaktifkan' : 'dinonaktifkan';
        Response::json([
            'success'    => true,
            'is_enabled' => $newState,
            'message'    => "Tugas cron '{$job['name']}' {$statusText}.",
        ]);
    }

    public function delete(): void
    {
        $id = (int)($_POST['id'] ?? 0);

        $pdo = Database::getInstance();
        $stmt = $pdo->prepare("SELECT * FROM cron_jobs WHERE id = :id LIMIT 1");
        $stmt->execute([':id' => $id]);
        $job = $stmt->fetch();

        if (!$job) {
            Response::json(['success' => false, 'error' => 'Tugas cron tidak ditemukan.'], 404);
        }

        $del = $pdo->prepare("DELETE FROM cron_jobs WHERE id = :id");
        $del->execute([':id' => $id]);

        $panelRoot = defined('PANEL_ROOT') ? PANEL_ROOT : dirname(__DIR__, 2);
        $logFile = $panelRoot . "/data/cron_logs/cron_{$id}.log";
        if (file_exists($logFile)) {
            @unlink($logFile);
        }

        self::syncCronJobs();

        Response::json([
            'success' => true,
            'message' => "Tugas cron '{$job['name']}' berhasil dihapus.",
        ]);
    }

    /**
     * Manual Execution (Run Now)
     */
    public function runNow(): void
    {
        $id = (int)($_POST['id'] ?? 0);

        $pdo = Database::getInstance();
        $stmt = $pdo->prepare("SELECT * FROM cron_jobs WHERE id = :id LIMIT 1");
        $stmt->execute([':id' => $id]);
        $job = $stmt->fetch();

        if (!$job) {
            Response::json(['success' => false, 'error' => 'Tugas cron tidak ditemukan.'], 404);
        }

        $command = $job['command'];
        $user = $job['run_as_user'] ?? 'afandi';
        $panelRoot = defined('PANEL_ROOT') ? PANEL_ROOT : dirname(__DIR__, 2);
        $logFile = $panelRoot . "/data/cron_logs/cron_{$id}.log";

        $startTime = microtime(true);

        // Run command and capture output
        $descriptorspec = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        // Format execution command based on target user
        if ($user === 'root') {
            // If root needed, run via task runner or su
            $execCmd = "bash -c " . escapeshellarg($command);
        } else {
            $execCmd = "su -s /bin/bash - " . escapeshellarg($user) . " -c " . escapeshellarg($command) . " 2>&1";
            // If current process is already afandi and target user is afandi, run directly without su
            $currentUser = get_current_user();
            if ($currentUser === $user) {
                $execCmd = $command;
            }
        }

        $output = '';
        $exitCode = 0;

        $proc = @proc_open($execCmd, $descriptorspec, $pipes);
        if (is_resource($proc)) {
            fclose($pipes[0]);
            $stdout = stream_get_contents($pipes[1]);
            $stderr = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $exitCode = proc_close($proc);
            $output = trim($stdout . "\n" . $stderr);
        } else {
            $output = "Gagal memulai proses eksekusi.";
            $exitCode = 1;
        }

        $duration = number_format(microtime(true) - $startTime, 2) . 's';

        // Update last run info in database
        $update = $pdo->prepare("
            UPDATE cron_jobs 
            SET last_run_at = CURRENT_TIMESTAMP, last_exit_code = :code 
            WHERE id = :id
        ");
        $update->execute([':code' => $exitCode, ':id' => $id]);

        // If log_mode is file, append manual run result
        if (($job['log_mode'] ?? 'file') === 'file') {
            $logEntry = "\n======================================================\n";
            $logEntry .= "[MANUAL RUN] " . date('Y-m-d H:i:s') . " | User: {$user} | Exit Code: {$exitCode} | Duration: {$duration}\n";
            $logEntry .= "======================================================\n";
            $logEntry .= (!empty($output) ? $output : "(Tidak ada output terminal)") . "\n";
            @file_put_contents($logFile, $logEntry, FILE_APPEND);
        }

        Response::json([
            'success'   => true,
            'exit_code' => $exitCode,
            'duration'  => $duration,
            'output'    => !empty($output) ? $output : '(Perintah selesai tanpa pesan output)',
            'message'   => $exitCode === 0 
                ? "Tugas berhasil dijalankan dalam {$duration} (Exit Code: 0)." 
                : "Tugas dijalankan dengan kode status error {$exitCode}.",
        ]);
    }

    public function getLogs(): void
    {
        $id = (int)($_GET['id'] ?? 0);

        $pdo = Database::getInstance();
        $stmt = $pdo->prepare("SELECT * FROM cron_jobs WHERE id = :id LIMIT 1");
        $stmt->execute([':id' => $id]);
        $job = $stmt->fetch();

        if (!$job) {
            Response::json(['success' => false, 'error' => 'Tugas cron tidak ditemukan.'], 404);
        }

        $panelRoot = defined('PANEL_ROOT') ? PANEL_ROOT : dirname(__DIR__, 2);
        $logFile = $panelRoot . "/data/cron_logs/cron_{$id}.log";

        if (!file_exists($logFile) || filesize($logFile) === 0) {
            Response::json([
                'success'    => true,
                'name'       => $job['name'],
                'exists'     => false,
                'size_human' => '0 B',
                'log'        => "Belum ada catatan log untuk tugas cron ini.",
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
            return;
        }

        $content = self::tailFile($logFile, 100);

        Response::json([
            'success'    => true,
            'name'       => $job['name'],
            'exists'     => true,
            'size_human' => self::formatBytes(filesize($logFile)),
            'log'        => $content,
            'updated_at' => date('Y-m-d H:i:s', filemtime($logFile) ?: time()),
        ]);
    }

    public function clearLogs(): void
    {
        $id = (int)($_POST['id'] ?? 0);

        $panelRoot = defined('PANEL_ROOT') ? PANEL_ROOT : dirname(__DIR__, 2);
        $logFile = $panelRoot . "/data/cron_logs/cron_{$id}.log";

        if (file_exists($logFile)) {
            @file_put_contents($logFile, '');
        }

        Response::json([
            'success' => true,
            'message' => 'Log riwayat tugas cron berhasil dikosongkan.',
        ]);
    }

    /**
     * Synchronize SQLite active cron jobs to /etc/cron.d/almusa-cron
     */
    public static function syncCronJobs(): void
    {
        $pdo = Database::getInstance();
        $jobs = $pdo->query("SELECT * FROM cron_jobs WHERE is_enabled = 1 ORDER BY id ASC")->fetchAll();

        $panelRoot = defined('PANEL_ROOT') ? PANEL_ROOT : dirname(__DIR__, 2);
        $logsDir = $panelRoot . '/data/cron_logs';
        if (!is_dir($logsDir)) {
            @mkdir($logsDir, 0777, true);
        }

        $stagingFile = $panelRoot . '/data/almusa-cron.staging';

        $content = "# ==============================================================================\n";
        $content .= "# Almusa Server Control Panel - System Cron Configuration\n";
        $content .= "# GENERATED AUTOMATICALLY - DO NOT EDIT THIS FILE DIRECTLY\n";
        $content .= "# Synchronized: " . date('Y-m-d H:i:s') . "\n";
        $content .= "# ==============================================================================\n";
        $content .= "SHELL=/bin/bash\n";
        $content .= "PATH=/usr/local/sbin:/usr/local/bin:/sbin:/bin:/usr/sbin:/usr/bin\n\n";

        if (empty($jobs)) {
            $content .= "# (Tidak ada tugas cron yang aktif saat ini)\n";
        } else {
            foreach ($jobs as $job) {
                $id = (int)$job['id'];
                $name = addslashes($job['name']);
                $expr = trim($job['expression']);
                $user = trim($job['run_as_user'] ?: 'afandi');
                $cmd = trim($job['command']);
                $logMode = $job['log_mode'] ?? 'file';

                $content .= "# ID: {$id} | Name: {$name}\n";
                if ($logMode === 'file') {
                    $logPath = "{$logsDir}/cron_{$id}.log";
                    $content .= "{$expr} {$user} {$cmd} >> " . escapeshellarg($logPath) . " 2>&1\n\n";
                } else {
                    $content .= "{$expr} {$user} {$cmd} >> /dev/null 2>&1\n\n";
                }
            }
        }

        // Add mandatory trailing newline
        $content .= "\n";

        @file_put_contents($stagingFile, $content);
        @chmod($stagingFile, 0666);

        // Enqueue task for root daemon to copy to /etc/cron.d/almusa-cron
        $task = $pdo->prepare("
            INSERT INTO tasks (title, action, payload, status)
            VALUES (:title, 'cron_sync', :payload, 'pending')
        ");
        $task->execute([
            ':title'   => 'Sinkronisasi Jadwal Cron Sistem (/etc/cron.d/almusa-cron)',
            ':payload' => json_encode(['src_file' => $stagingFile]),
        ]);
    }

    public static function isValidCronExpression(string $expr): bool
    {
        $parts = preg_split('/\s+/', trim($expr));
        if (count($parts) !== 5) {
            return false;
        }

        $pattern = '/^(\*|[0-9,\-\/]+)$/';
        foreach ($parts as $part) {
            if (!preg_match($pattern, $part)) {
                return false;
            }
        }
        return true;
    }

    public static function describeExpression(string $expr): string
    {
        $expr = trim($expr);
        $presets = [
            '* * * * *'    => 'Setiap menit',
            '*/2 * * * *'  => 'Setiap 2 menit',
            '*/5 * * * *'  => 'Setiap 5 menit',
            '*/10 * * * *' => 'Setiap 10 menit',
            '*/15 * * * *' => 'Setiap 15 menit',
            '*/30 * * * *' => 'Setiap 30 menit',
            '0 * * * *'    => 'Setiap jam (menit 00)',
            '0 */2 * * *'  => 'Setiap 2 jam',
            '0 */6 * * *'  => 'Setiap 6 jam',
            '0 */12 * * *' => 'Setiap 12 jam',
            '0 0 * * *'    => 'Setiap hari (pukul 00:00)',
            '0 1 * * *'    => 'Setiap hari (pukul 01:00)',
            '0 2 * * *'    => 'Setiap hari (pukul 02:00)',
            '0 3 * * *'    => 'Setiap hari (pukul 03:00)',
            '0 0 * * 0'    => 'Setiap minggu (Minggu pukul 00:00)',
            '0 0 * * 1'    => 'Setiap minggu (Senin pukul 00:00)',
            '0 0 1 * *'    => 'Setiap bulan (Tanggal 1 pukul 00:00)',
            '0 0 1 1 *'    => 'Setiap tahun (1 Januari pukul 00:00)',
        ];

        if (isset($presets[$expr])) {
            return $presets[$expr];
        }

        $parts = preg_split('/\s+/', $expr);
        if (count($parts) === 5) {
            [$min, $hour, $dom, $mon, $dow] = $parts;
            if (str_starts_with($min, '*/')) {
                $n = substr($min, 2);
                return "Setiap {$n} menit";
            }
            if (str_starts_with($hour, '*/') && $min === '0') {
                $n = substr($hour, 2);
                return "Setiap {$n} jam";
            }
            if (is_numeric($min) && is_numeric($hour) && $dom === '*' && $mon === '*' && $dow === '*') {
                $time = sprintf('%02d:%02d', (int)$hour, (int)$min);
                return "Setiap hari pukul {$time}";
            }
        }

        return "Kustom ({$expr})";
    }

    private static function tailFile(string $path, int $lines = 100): string
    {
        if (!file_exists($path)) return '';
        $f = @fopen($path, 'rb');
        if (!$f) return '';

        $buffer = '';
        $chunkSize = 4096;
        $fileSize = filesize($path);
        $offset = $fileSize;

        $lineCount = 0;
        while ($offset > 0 && $lineCount <= $lines) {
            $readSize = min($chunkSize, $offset);
            $offset -= $readSize;
            fseek($f, $offset);
            $chunk = fread($f, $readSize);
            $buffer = $chunk . $buffer;
            $lineCount = substr_count($buffer, "\n");
        }
        fclose($f);

        $arr = explode("\n", trim($buffer));
        if (count($arr) > $lines) {
            $arr = array_slice($arr, -$lines);
        }
        return implode("\n", $arr);
    }

    private static function formatBytes(int $bytes): string
    {
        if ($bytes >= 1073741824) return number_format($bytes / 1073741824, 2) . ' GB';
        if ($bytes >= 1048576) return number_format($bytes / 1048576, 2) . ' MB';
        if ($bytes >= 1024) return number_format($bytes / 1024, 1) . ' KB';
        return $bytes . ' B';
    }
}
