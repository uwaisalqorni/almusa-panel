<?php

namespace App\Services;

use App\Core\Database;
use App\Controllers\CronController;
use PDO;

class BackupEngine
{
    private static string $backupDir = '/www/backup';

    public static function getBackupDir(): string
    {
        if (!is_dir(self::$backupDir)) {
            @mkdir(self::$backupDir, 0755, true);
        }
        return self::$backupDir;
    }

    /**
     * Create snapshot of a specific database or all databases
     */
    public static function createDatabaseBackup(string $dbName, string $destination = 'local', string $trigger = 'manual'): array
    {
        $backupDir = self::getBackupDir();
        $timestamp = date('Ymd_His');
        $isAll = ($dbName === 'all' || $dbName === 'all_databases');

        $cleanDb = $isAll ? 'all_databases' : preg_replace('/[^a-zA-Z0-9_]/', '', $dbName);
        if (empty($cleanDb)) {
            return ['success' => false, 'error' => 'Nama database tidak valid.'];
        }

        $filename = "db_{$cleanDb}_{$timestamp}.sql.gz";
        $filepath = "{$backupDir}/{$filename}";

        $panelRoot = defined('PANEL_ROOT') ? PANEL_ROOT : dirname(__DIR__, 2);
        $script = "{$panelRoot}/scripts/backup_manager.sh";

        $startTime = microtime(true);
        $action = $isAll ? 'backup_all_db' : 'backup_db';
        $cmd = "bash " . escapeshellarg($script) . " {$action} " . ($isAll ? "" : escapeshellarg($cleanDb) . " ") . escapeshellarg($filepath) . " 2>&1";

        $output = [];
        $exitCode = 1;
        exec($cmd, $output, $exitCode);
        $duration = round(microtime(true) - $startTime, 2);

        if ($exitCode !== 0 || !file_exists($filepath)) {
            return [
                'success' => false,
                'error'   => 'Gagal mengeksekusi mysqldump: ' . implode("\n", $output),
            ];
        }

        $filesize = filesize($filepath);

        // Record into SQLite
        $pdo = Database::getInstance();
        $name = $isAll ? "Semua Database (Full Dump)" : "Database: {$cleanDb}";
        $stmt = $pdo->prepare("
            INSERT INTO backups (name, type, target, filename, filepath, filesize, storage_location, cloud_synced, created_by)
            VALUES (:name, 'database', :target, :filename, :filepath, :filesize, :loc, 0, :created_by)
        ");
        $stmt->execute([
            ':name'       => $name,
            ':target'     => $cleanDb,
            ':filename'   => $filename,
            ':filepath'   => $filepath,
            ':filesize'   => $filesize,
            ':loc'        => ($destination === 'cloud' ? 'cloud' : ($destination === 'both' ? 'both' : 'local')),
            ':created_by' => $trigger,
        ]);
        $backupId = (int)$pdo->lastInsertId();

        $cloudSynced = false;
        $cloudMessage = '';

        if ($destination === 'cloud' || $destination === 'both') {
            $syncRes = self::syncToCloud($backupId, ($destination === 'cloud'));
            $cloudSynced = $syncRes['success'];
            $cloudMessage = $cloudSynced ? ' dan berhasil disinkronkan ke Cloud' : ' (namun sinkronisasi cloud gagal: ' . ($syncRes['error'] ?? '') . ')';
        }

        // Auto retention pruning if triggered by schedule
        if ($trigger === 'schedule') {
            $retentionDays = (int)Database::getSetting('backup_retention_days', '7');
            self::pruneOldBackups($retentionDays);
        }

        $sizeH = self::formatBytes($filesize);

        return [
            'success'      => true,
            'backup_id'    => $backupId,
            'filename'     => $filename,
            'filepath'     => $filepath,
            'filesize'     => $filesize,
            'filesize_h'   => $sizeH,
            'duration'     => "{$duration}s",
            'cloud_synced' => $cloudSynced,
            'message'      => "Snapshot {$name} selesai dalam {$duration} detik ({$sizeH}){$cloudMessage}.",
        ];
    }

    /**
     * Create snapshot of a specific website directory or all websites
     */
    public static function createSiteBackup(string $domain, string $destination = 'local', string $trigger = 'manual'): array
    {
        $backupDir = self::getBackupDir();
        $timestamp = date('Ymd_His');
        $isAll = ($domain === 'all' || $domain === 'all_websites');

        $pdo = Database::getInstance();
        $cleanDomain = '';
        $docroot = '';

        if ($isAll) {
            $cleanDomain = 'all_websites';
            $docroot = '/www/wwwroot';
            $name = "Semua Website (/www/wwwroot)";
        } else {
            $stmt = $pdo->prepare("SELECT domain, docroot FROM sites WHERE domain = :domain LIMIT 1");
            $stmt->execute([':domain' => $domain]);
            $site = $stmt->fetch();
            if (!$site) {
                return ['success' => false, 'error' => "Website dengan domain '{$domain}' tidak ditemukan."];
            }
            $cleanDomain = str_replace(['.', '-'], '_', $site['domain']);
            $docroot = $site['docroot'];
            $name = "Website: {$site['domain']}";
        }

        $filename = "site_{$cleanDomain}_{$timestamp}.tar.gz";
        $filepath = "{$backupDir}/{$filename}";

        $panelRoot = defined('PANEL_ROOT') ? PANEL_ROOT : dirname(__DIR__, 2);
        $script = "{$panelRoot}/scripts/backup_manager.sh";

        $startTime = microtime(true);
        $action = $isAll ? 'backup_all_sites' : 'backup_site';
        $cmd = "bash " . escapeshellarg($script) . " {$action} " . ($isAll ? "" : escapeshellarg($domain) . " " . escapeshellarg($docroot) . " ") . escapeshellarg($filepath) . " 2>&1";

        $output = [];
        $exitCode = 1;
        exec($cmd, $output, $exitCode);
        $duration = round(microtime(true) - $startTime, 2);

        if ($exitCode !== 0 || !file_exists($filepath)) {
            return [
                'success' => false,
                'error'   => 'Gagal mengeksekusi kompresi tar: ' . implode("\n", $output),
            ];
        }

        $filesize = filesize($filepath);

        $stmt = $pdo->prepare("
            INSERT INTO backups (name, type, target, filename, filepath, filesize, storage_location, cloud_synced, created_by)
            VALUES (:name, 'site', :target, :filename, :filepath, :filesize, :loc, 0, :created_by)
        ");
        $stmt->execute([
            ':name'       => $name,
            ':target'     => ($isAll ? 'all_websites' : $domain),
            ':filename'   => $filename,
            ':filepath'   => $filepath,
            ':filesize'   => $filesize,
            ':loc'        => ($destination === 'cloud' ? 'cloud' : ($destination === 'both' ? 'both' : 'local')),
            ':created_by' => $trigger,
        ]);
        $backupId = (int)$pdo->lastInsertId();

        $cloudSynced = false;
        $cloudMessage = '';

        if ($destination === 'cloud' || $destination === 'both') {
            $syncRes = self::syncToCloud($backupId, ($destination === 'cloud'));
            $cloudSynced = $syncRes['success'];
            $cloudMessage = $cloudSynced ? ' dan berhasil disinkronkan ke Cloud' : ' (namun sinkronisasi cloud gagal: ' . ($syncRes['error'] ?? '') . ')';
        }

        if ($trigger === 'schedule') {
            $retentionDays = (int)Database::getSetting('backup_retention_days', '7');
            self::pruneOldBackups($retentionDays);
        }

        $sizeH = self::formatBytes($filesize);

        return [
            'success'      => true,
            'backup_id'    => $backupId,
            'filename'     => $filename,
            'filepath'     => $filepath,
            'filesize'     => $filesize,
            'filesize_h'   => $sizeH,
            'duration'     => "{$duration}s",
            'cloud_synced' => $cloudSynced,
            'message'      => "Snapshot {$name} selesai dalam {$duration} detik ({$sizeH}){$cloudMessage}.",
        ];
    }

    /**
     * Create full backup (all databases + all websites)
     */
    public static function createFullBackup(string $destination = 'local', string $trigger = 'manual'): array
    {
        $dbRes = self::createDatabaseBackup('all', $destination, $trigger);
        $siteRes = self::createSiteBackup('all', $destination, $trigger);

        $success = ($dbRes['success'] && $siteRes['success']);
        $msg = "Backup Penuh Server selesai! " . ($dbRes['message'] ?? '') . " " . ($siteRes['message'] ?? '');

        return [
            'success'  => $success,
            'database' => $dbRes,
            'site'     => $siteRes,
            'message'  => $msg,
        ];
    }

    /**
     * Restore database or website snapshot from local file (or download from cloud if missing)
     */
    public static function restoreBackup(int $id, ?string $targetOverride = null): array
    {
        $pdo = Database::getInstance();
        $stmt = $pdo->prepare("SELECT * FROM backups WHERE id = :id LIMIT 1");
        $stmt->execute([':id' => $id]);
        $backup = $stmt->fetch();

        if (!$backup) {
            return ['success' => false, 'error' => 'Data snapshot backup tidak ditemukan.'];
        }

        $filepath = $backup['filepath'];

        // If local file is missing, attempt to pull it from Cloud Storage if configured
        if (!file_exists($filepath)) {
            if (!empty($backup['cloud_key'])) {
                $endpoint  = Database::getSetting('cloud_storage_endpoint', '');
                $bucket    = Database::getSetting('cloud_storage_bucket', '');
                $accessKey = Database::getSetting('cloud_storage_access_key', '');
                $secretKey = Database::getSetting('cloud_storage_secret_key', '');
                $region    = Database::getSetting('cloud_storage_region', 'auto');

                if ($endpoint && $bucket && $accessKey && $secretKey) {
                    $client = new S3Client($endpoint, $bucket, $accessKey, $secretKey, $region);
                    $dlRes = $client->downloadFile($backup['cloud_key'], $filepath);
                    if (!$dlRes['success']) {
                        return [
                            'success' => false,
                            'error'   => "File backup tidak ada di server dan gagal diunduh dari Cloud: " . ($dlRes['error'] ?? ''),
                        ];
                    }
                } else {
                    return [
                        'success' => false,
                        'error'   => "File backup fisik tidak ditemukan di server: {$filepath}, dan kredensial cloud tidak lengkap.",
                    ];
                }
            } else {
                return [
                    'success' => false,
                    'error'   => "File backup fisik tidak ditemukan di server: {$filepath}",
                ];
            }
        }

        $panelRoot = defined('PANEL_ROOT') ? PANEL_ROOT : dirname(__DIR__, 2);
        $script = "{$panelRoot}/scripts/backup_manager.sh";
        if (!file_exists($script)) {
            return ['success' => false, 'error' => "Skrip backup engine tidak ditemukan: {$script}"];
        }

        $target = trim($targetOverride ?? '');
        if (empty($target)) {
            $target = $backup['target'];
        }

        $startTime = microtime(true);
        $cmd = '';

        if ($backup['type'] === 'database') {
            $isAll = ($backup['target'] === 'all_databases' || $target === 'all_databases' || $target === 'all');
            $cleanDb = $isAll ? 'all_databases' : preg_replace('/[^a-zA-Z0-9_]/', '', $target);

            $cmd = "bash " . escapeshellarg($script) . " restore_db " . escapeshellarg($filepath) . " " . escapeshellarg($cleanDb) . " 2>&1";
        } elseif ($backup['type'] === 'site') {
            $targetDir = '';
            if ($target === 'all_websites' || $target === 'all' || $backup['target'] === 'all_websites') {
                $targetDir = '/www/wwwroot';
            } else {
                // Check if target is a domain registered in sites table
                $sStmt = $pdo->prepare("SELECT docroot FROM sites WHERE domain = :domain LIMIT 1");
                $sStmt->execute([':domain' => $target]);
                $siteRow = $sStmt->fetch();

                if ($siteRow && !empty($siteRow['docroot'])) {
                    $docroot = $siteRow['docroot'];
                    $targetDir = $docroot;
                    if (preg_match('#/public/?$#i', $docroot)) {
                        $targetDir = dirname($docroot);
                    }
                } elseif (str_starts_with($target, '/')) {
                    $targetDir = $target;
                } else {
                    $targetDir = "/www/wwwroot/{$target}";
                }
            }

            $cmd = "bash " . escapeshellarg($script) . " restore_site " . escapeshellarg($filepath) . " " . escapeshellarg($targetDir) . " 2>&1";
        } else {
            return ['success' => false, 'error' => "Tipe backup tidak valid: {$backup['type']}"];
        }

        exec($cmd, $output, $exitCode);
        $duration = round(microtime(true) - $startTime, 2);

        if ($exitCode !== 0) {
            return [
                'success'  => false,
                'duration' => "{$duration}s",
                'error'    => 'Gagal mengeksekusi restore: ' . implode("\n", $output),
            ];
        }

        return [
            'success'  => true,
            'duration' => "{$duration}s",
            'message'  => "Pemulihan data snapshot '{$backup['name']}' berhasil diselesaikan dalam {$duration} detik!",
            'output'   => implode("\n", $output),
        ];
    }

    /**
     * Upload an existing backup snapshot to remote Cloudflare R2 / AWS S3
     */
    public static function syncToCloud(int $backupId, bool $removeLocalAfter = false): array
    {
        $pdo = Database::getInstance();
        $stmt = $pdo->prepare("SELECT * FROM backups WHERE id = :id LIMIT 1");
        $stmt->execute([':id' => $backupId]);
        $backup = $stmt->fetch();

        if (!$backup) {
            return ['success' => false, 'error' => 'Data backup tidak ditemukan.'];
        }

        $filepath = $backup['filepath'];
        if (!file_exists($filepath)) {
            return ['success' => false, 'error' => "File lokal tidak ada di server: {$filepath}"];
        }

        $endpoint  = Database::getSetting('cloud_storage_endpoint', '');
        $bucket    = Database::getSetting('cloud_storage_bucket', '');
        $accessKey = Database::getSetting('cloud_storage_access_key', '');
        $secretKey = Database::getSetting('cloud_storage_secret_key', '');
        $region    = Database::getSetting('cloud_storage_region', 'auto');
        $prefix    = trim(Database::getSetting('cloud_storage_prefix', 'almusa-backups'), '/');

        if (empty($endpoint) || empty($bucket) || empty($accessKey) || empty($secretKey)) {
            return ['success' => false, 'error' => 'Kredensial Cloudflare R2 / S3 belum diatur di menu Konfigurasi Cloud.'];
        }

        $client = new S3Client($endpoint, $bucket, $accessKey, $secretKey, $region);
        $remoteKey = ($prefix ? $prefix . '/' : '') . $backup['filename'];
        $contentType = str_ends_with($backup['filename'], '.sql.gz') ? 'application/gzip' : 'application/gzip';

        $uploadRes = $client->uploadFile($filepath, $remoteKey, $contentType);
        if (!$uploadRes['success']) {
            return $uploadRes;
        }

        $newLoc = ($removeLocalAfter ? 'cloud' : 'both');
        if ($removeLocalAfter && file_exists($filepath)) {
            @unlink($filepath);
        }

        $update = $pdo->prepare("
            UPDATE backups 
            SET cloud_synced = 1, cloud_key = :key, storage_location = :loc 
            WHERE id = :id
        ");
        $update->execute([
            ':key' => $remoteKey,
            ':loc' => $newLoc,
            ':id'  => $backupId,
        ]);

        return [
            'success'   => true,
            'cloud_key' => $remoteKey,
            'message'   => "File {$backup['filename']} berhasil diunggah ke Cloud Storage ({$bucket})!",
        ];
    }

    /**
     * Delete backup from disk and SQLite (and optionally from Cloud)
     */
    public static function deleteBackup(int $id, bool $deleteRemote = false): bool
    {
        $pdo = Database::getInstance();
        $stmt = $pdo->prepare("SELECT * FROM backups WHERE id = :id LIMIT 1");
        $stmt->execute([':id' => $id]);
        $backup = $stmt->fetch();

        if (!$backup) {
            return false;
        }

        // Delete local file
        if (!empty($backup['filepath']) && file_exists($backup['filepath'])) {
            @unlink($backup['filepath']);
        }

        // Optionally delete remote file
        if ($deleteRemote && !empty($backup['cloud_key'])) {
            try {
                $endpoint  = Database::getSetting('cloud_storage_endpoint', '');
                $bucket    = Database::getSetting('cloud_storage_bucket', '');
                $accessKey = Database::getSetting('cloud_storage_access_key', '');
                $secretKey = Database::getSetting('cloud_storage_secret_key', '');
                $region    = Database::getSetting('cloud_storage_region', 'auto');
                if ($endpoint && $bucket && $accessKey && $secretKey) {
                    $client = new S3Client($endpoint, $bucket, $accessKey, $secretKey, $region);
                    $client->deleteFile($backup['cloud_key']);
                }
            } catch (\Throwable $e) {
                // Ignore remote deletion error
            }
        }

        $del = $pdo->prepare("DELETE FROM backups WHERE id = :id");
        return $del->execute([':id' => $id]);
    }

    /**
     * Prune backups older than specified retention days
     */
    public static function pruneOldBackups(int $retentionDays = 7): int
    {
        if ($retentionDays < 1) {
            $retentionDays = 7;
        }

        $pdo = Database::getInstance();
        $stmt = $pdo->prepare("
            SELECT * FROM backups 
            WHERE created_at < datetime('now', '-' || :days || ' days')
        ");
        $stmt->execute([':days' => $retentionDays]);
        $oldBackups = $stmt->fetchAll();

        $prunedCount = 0;
        foreach ($oldBackups as $b) {
            self::deleteBackup((int)$b['id']);
            $prunedCount++;
        }

        // Also clean orphaned local files via script
        $panelRoot = defined('PANEL_ROOT') ? PANEL_ROOT : dirname(__DIR__, 2);
        $script = "{$panelRoot}/scripts/backup_manager.sh";
        exec("bash " . escapeshellarg($script) . " prune " . escapeshellarg((string)$retentionDays) . " 2>&1");

        return $prunedCount;
    }

    /**
     * List all recorded backups with verified disk statuses
     */
    public static function listBackups(): array
    {
        $pdo = Database::getInstance();
        $backups = $pdo->query("SELECT * FROM backups ORDER BY id DESC")->fetchAll();

        foreach ($backups as &$b) {
            $exists = file_exists($b['filepath']);
            $b['file_exists'] = $exists;
            if ($exists) {
                $b['real_size'] = filesize($b['filepath']);
                $b['filesize_h'] = self::formatBytes($b['real_size']);
            } else {
                $b['filesize_h'] = self::formatBytes((int)$b['filesize']) . ' (Cloud only)';
            }
            $b['time_ago'] = self::timeAgo($b['created_at']);
        }
        unset($b);

        return $backups;
    }

    /**
     * Calculate summary statistics for dashboard cards
     */
    public static function getStats(): array
    {
        $pdo = Database::getInstance();
        $backups = self::listBackups();

        $totalCount = count($backups);
        $dbCount = 0;
        $siteCount = 0;
        $cloudCount = 0;
        $totalBytes = 0;
        $lastBackupAt = null;

        foreach ($backups as $b) {
            if ($b['type'] === 'database') $dbCount++;
            if ($b['type'] === 'site') $siteCount++;
            if ((int)$b['cloud_synced'] === 1) $cloudCount++;
            if (!empty($b['file_exists'])) {
                $totalBytes += (int)($b['real_size'] ?? $b['filesize']);
            }
            if ($lastBackupAt === null) {
                $lastBackupAt = $b['created_at'];
            }
        }

        $cloudConfigured = !empty(Database::getSetting('cloud_storage_bucket'))
            && !empty(Database::getSetting('cloud_storage_access_key'));

        $autoEnabled = (Database::getSetting('backup_auto_enabled', '0') === '1');
        $autoSchedule = Database::getSetting('backup_auto_schedule', '0 2 * * *');
        $retentionDays = Database::getSetting('backup_retention_days', '7');

        return [
            'total_count'      => $totalCount,
            'db_count'         => $dbCount,
            'site_count'       => $siteCount,
            'cloud_count'      => $cloudCount,
            'total_bytes'      => $totalBytes,
            'total_size_h'     => self::formatBytes($totalBytes),
            'last_backup'      => $lastBackupAt ? self::timeAgo($lastBackupAt) : 'Belum ada',
            'cloud_configured' => $cloudConfigured,
            'auto_enabled'     => $autoEnabled,
            'auto_schedule'    => $autoSchedule,
            'retention_days'   => $retentionDays,
        ];
    }

    /**
     * Test Cloudflare R2 / AWS S3 connectivity using saved settings
     */
    public static function testCloudConnection(): array
    {
        $endpoint  = Database::getSetting('cloud_storage_endpoint', '');
        $bucket    = Database::getSetting('cloud_storage_bucket', '');
        $accessKey = Database::getSetting('cloud_storage_access_key', '');
        $secretKey = Database::getSetting('cloud_storage_secret_key', '');
        $region    = Database::getSetting('cloud_storage_region', 'auto');

        $client = new S3Client($endpoint, $bucket, $accessKey, $secretKey, $region);
        return $client->testConnection();
    }

    /**
     * Synchronize backup engine schedule to the Linux cron system
     */
    public static function syncScheduleCron(): void
    {
        $pdo = Database::getInstance();
        $autoEnabled = (Database::getSetting('backup_auto_enabled', '0') === '1');
        $schedule = Database::getSetting('backup_auto_schedule', '0 2 * * *');

        $panelRoot = defined('PANEL_ROOT') ? PANEL_ROOT : dirname(__DIR__, 2);
        $cronScript = "{$panelRoot}/scripts/backup_cron.php";
        $cmd = "/usr/bin/php {$cronScript}";

        // Check if cron job row already exists
        $stmt = $pdo->prepare("SELECT id FROM cron_jobs WHERE command LIKE '%backup_cron.php%' LIMIT 1");
        $stmt->execute();
        $existingId = $stmt->fetchColumn();

        if ($existingId) {
            $up = $pdo->prepare("
                UPDATE cron_jobs 
                SET expression = :expr, is_enabled = :enabled, updated_at = CURRENT_TIMESTAMP 
                WHERE id = :id
            ");
            $up->execute([
                ':expr'    => $schedule,
                ':enabled' => $autoEnabled ? 1 : 0,
                ':id'      => $existingId,
            ]);
        } elseif ($autoEnabled) {
            $ins = $pdo->prepare("
                INSERT INTO cron_jobs (name, schedule_type, expression, command, run_as_user, log_mode, is_enabled)
                VALUES ('Automated Database & Site Backup Engine', 'custom', :expr, :cmd, 'root', 'file', 1)
            ");
            $ins->execute([
                ':expr' => $schedule,
                ':cmd'  => $cmd,
            ]);
        }

        // Trigger system cron file re-synchronization
        CronController::syncCronJobs();
    }

    /**
     * Execute scheduled backup runner
     */
    public static function runScheduledBackup(): array
    {
        $targetType = Database::getSetting('backup_auto_target_type', 'all_db');
        $destination = Database::getSetting('backup_auto_destination', 'local');

        echo "[" . date('Y-m-d H:i:s') . "] Starting scheduled backup [Target: {$targetType}, Dest: {$destination}]...\n";

        $res = [];
        switch ($targetType) {
            case 'all_sites':
                $res = self::createSiteBackup('all', $destination, 'schedule');
                break;
            case 'full':
                $res = self::createFullBackup($destination, 'schedule');
                break;
            case 'all_db':
            default:
                $res = self::createDatabaseBackup('all', $destination, 'schedule');
                break;
        }

        echo "[" . date('Y-m-d H:i:s') . "] Scheduled backup complete. Result: " . json_encode($res) . "\n";
        return $res;
    }

    /**
     * Format bytes to readable string
     */
    public static function formatBytes(int $bytes, int $precision = 2): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);
        $bytes /= (1 << (10 * $pow));
        return round($bytes, $precision) . ' ' . $units[$pow];
    }

    /**
     * Indonesian relative time helper
     */
    public static function timeAgo(string $datetime): string
    {
        $hasTz = str_contains($datetime, 'Z') || str_contains($datetime, '+');
        $time = strtotime($hasTz ? $datetime : $datetime . ' UTC');
        $diff = time() - $time;

        if ($diff < 60) {
            return 'Baru saja';
        } elseif ($diff < 3600) {
            return floor($diff / 60) . ' menit lalu';
        } elseif ($diff < 86400) {
            return floor($diff / 3600) . ' jam lalu';
        } elseif ($diff < 2592000) {
            return floor($diff / 86400) . ' hari lalu';
        } else {
            return date('d M Y H:i', $time);
        }
    }
}
