<?php

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Database;
use App\Core\Response;

class CronController
{
    /**
     * Direktori log cron. Pada instalasi yang sudah dipisah user-nya, log ditaruh di
     * /var/log/almusa-cron (bisa ditulis job www-data) agar folder data/ panel
     * tidak perlu bisa diakses www-data.
     */
    public static function logsDir(): string
    {
        if (is_dir('/var/log/almusa-cron')) {
            return '/var/log/almusa-cron';
        }
        $panelRoot = defined('PANEL_ROOT') ? PANEL_ROOT : dirname(__DIR__, 2);
        $dir = $panelRoot . '/data/cron_logs';
        if (!is_dir($dir)) {
            @mkdir($dir, 0770, true);
        }
        return $dir;
    }

    private static function logFile(int $id): string
    {
        return self::logsDir() . "/cron_{$id}.log";
    }

    /**
     * User yang boleh menjalankan cron. User panel sengaja tidak termasuk.
     */
    private static function normalizeRunAsUser(string $user): string
    {
        if (in_array($user, ['www-data', 'root'], true)) {
            return $user;
        }
        // User website terisolasi (web_<slug>) yang benar-benar ada di sistem
        if (preg_match('/^web_[a-z0-9]{2,20}$/', $user) && function_exists('posix_getpwnam') && @posix_getpwnam($user)) {
            return $user;
        }
        return 'www-data';
    }

    /**
     * Daftar user website terisolasi beserta folder project-nya (dari pool PHP-FPM).
     * @return array<int, array{user: string, project: string, domain: string}>
     */
    public static function siteUsers(): array
    {
        $result = [];
        foreach (glob('/etc/php/*/fpm/pool.d/site-*.conf') ?: [] as $pool) {
            $txt = (string)@file_get_contents($pool);
            if (preg_match('/^user\s*=\s*(web_[a-z0-9]{2,20})\s*$/m', $txt, $u)) {
                preg_match('/^; almusa-project = (.+)$/m', $txt, $p);
                preg_match('/^; almusa-domain = (.+)$/m', $txt, $d);
                $result[] = ['user' => $u[1], 'project' => trim($p[1] ?? ''), 'domain' => trim($d[1] ?? '')];
            }
        }
        return $result;
    }

    /**
     * Job sebagai root = eksekusi perintah bebas sebagai root. Wajib konfirmasi
     * password panel agar sesi yang dicuri tidak langsung berarti root.
     */
    private static function requirePasswordConfirmation(): void
    {
        $password = (string)($_POST['confirm_password'] ?? '');
        $userId = Auth::id();
        if ($password === '' || !$userId) {
            Response::json(['success' => false, 'error' => 'Menjalankan tugas sebagai root wajib konfirmasi password panel.', 'need_password' => true], 403);
        }
        $stmt = Database::getInstance()->prepare("SELECT password_hash FROM users WHERE id = :id LIMIT 1");
        $stmt->execute([':id' => $userId]);
        $hash = (string)($stmt->fetchColumn() ?: '');
        if ($hash === '' || !password_verify($password, $hash)) {
            \App\Core\RateLimiter::logSecurity('WARN', 'Konfirmasi password gagal untuk aksi cron root dari IP ' . \App\Core\RateLimiter::clientIp());
            Response::json(['success' => false, 'error' => 'Password konfirmasi salah.', 'need_password' => true], 403);
        }
    }

    public function index(): void
    {
        $pdo = Database::getInstance();
        $cronJobs = $pdo->query("SELECT * FROM cron_jobs ORDER BY id DESC")->fetchAll();

        $panelRoot = defined('PANEL_ROOT') ? PANEL_ROOT : dirname(__DIR__, 2);
        $logsDir = self::logsDir();

        foreach ($cronJobs as &$job) {
            $job['human_expr'] = self::describeExpression($job['expression']);
            $logFile = $logsDir . "/cron_{$job['id']}.log";
            $job['has_log'] = file_exists($logFile) && filesize($logFile) > 0;
            $job['log_size'] = $job['has_log'] ? self::formatBytes(filesize($logFile)) : '0 B';
        }
        unset($job);

        // Fetch hosted websites for easy command presets
        $sites = $pdo->query("SELECT id, domain, docroot, php_version, app_type FROM sites ORDER BY domain ASC")->fetchAll();

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
            'siteUsers'        => self::siteUsers(),
        ]);
    }

    public function create(): void
    {
        $name = trim($_POST['name'] ?? '');
        $scheduleType = trim($_POST['schedule_type'] ?? 'custom');
        $expression = trim($_POST['expression'] ?? '* * * * *');
        $command = trim($_POST['command'] ?? '');
        $runAsUser = trim($_POST['run_as_user'] ?? 'www-data');
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

        $runAsUser = self::normalizeRunAsUser($runAsUser);
        if ($runAsUser === 'root') {
            self::requirePasswordConfirmation();
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
        $runAsUser = trim($_POST['run_as_user'] ?? 'www-data');
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

        $runAsUser = self::normalizeRunAsUser($runAsUser);
        if ($runAsUser === 'root') {
            self::requirePasswordConfirmation();
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
        $logFile = self::logFile($id);
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
        $user = self::normalizeRunAsUser((string)($job['run_as_user'] ?? 'www-data'));
        $logFile = self::logFile($id);

        if ($user === 'root') {
            self::requirePasswordConfirmation();
        }

        $startTime = microtime(true);

        // Eksekusi lewat root worker (task.php -> cron_manager.sh run) agar perintah
        // benar-benar berjalan sebagai user target, bukan sebagai user panel.
        $pdo->prepare("INSERT INTO tasks (title, action, payload, status) VALUES (:title, 'cron_run', :payload, 'pending')")
            ->execute([
                ':title'   => "Run Now Cron #{$id}: " . mb_substr((string)$job['name'], 0, 60) . " (user: {$user})",
                ':payload' => json_encode(['user' => $user, 'command' => $command]),
            ]);
        $taskId = (int)$pdo->lastInsertId();

        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }

        $exitCode = null;
        $check = $pdo->prepare("SELECT status, exit_code FROM tasks WHERE id = :id");
        $deadline = microtime(true) + 300;
        while (microtime(true) < $deadline) {
            $check->execute([':id' => $taskId]);
            $row = $check->fetch();
            $check->closeCursor();
            if (in_array($row['status'] ?? '', ['completed', 'failed'], true)) {
                $exitCode = (int)($row['exit_code'] ?? 1);
                break;
            }
            usleep(500000);
        }

        if ($exitCode === null) {
            Response::json([
                'success'   => true,
                'exit_code' => null,
                'duration'  => '-',
                'output'    => "Tugas masih berjalan di background (Task #{$taskId}).",
                'message'   => "Tugas sedang dijalankan di background (Task #{$taskId}). Pantau di log task.",
            ]);
        }

        // Ambil output dari log eksekusi worker (tanpa banner)
        $output = '';
        $panelLog = (defined('PANEL_ROOT') ? PANEL_ROOT : dirname(__DIR__, 2)) . '/data/panelExec.log';
        $log = @file_get_contents($panelLog, false, null, max(0, (int)@filesize($panelLog) - 131072));
        if ($log !== false && ($pos = strrpos($log, "[TASK #{$taskId}]")) !== false) {
            $chunk = substr($log, $pos);
            if (($p = strpos($chunk, "[EXEC]")) !== false) {
                $chunk = substr($chunk, (int)strpos($chunk, "\n", $p) + 1);
            }
            if (($p = strpos($chunk, "\n------------------------------------------------------\nFinished:")) !== false) {
                $chunk = substr($chunk, 0, $p);
            }
            $output = trim(preg_replace('/^\[INFO\] Executing cron command as user .*$/m', '', $chunk));
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
        $logFile = self::logFile($id);

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
        $logFile = self::logFile($id);

        if (file_exists($logFile)) {
            // File log bisa dimiliki www-data/root (ditulis oleh cron); hapus jika tak bisa dikosongkan
            if (@file_put_contents($logFile, '') === false) {
                @unlink($logFile);
            }
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
        $logsDir = self::logsDir();

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
                $user = trim($job['run_as_user'] ?: 'www-data');
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
