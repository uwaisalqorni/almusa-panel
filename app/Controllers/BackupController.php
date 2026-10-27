<?php

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Database;
use App\Core\Response;
use App\Services\BackupEngine;
use App\Services\S3Client;

class BackupController
{
    public function index(): void
    {
        $pdo = Database::getInstance();

        $backups = BackupEngine::listBackups();
        $stats = BackupEngine::getStats();

        // Get hosted sites for dropdown
        $sites = $pdo->query("SELECT id, domain, docroot FROM sites ORDER BY domain ASC")->fetchAll();

        // Get databases from panel + MariaDB
        $databases = $pdo->query("SELECT id, db_name, db_user FROM databases ORDER BY db_name ASC")->fetchAll();

        // Fallback to fetch directly from MySQL if available
        $mysqlDbs = [];
        try {
            $rootPass = Database::getSetting('mysql_root_pass', '');
            $dsn = "mysql:host=127.0.0.1;port=3306;charset=utf8mb4";
            $mysqlPdo = new \PDO($dsn, 'root', $rootPass, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
            $rows = $mysqlPdo->query("SHOW DATABASES")->fetchAll(\PDO::FETCH_COLUMN);
            $systemDbs = ['information_schema', 'performance_schema', 'mysql', 'sys'];
            foreach ($rows as $r) {
                if (!in_array($r, $systemDbs, true)) {
                    $mysqlDbs[] = $r;
                }
            }
        } catch (\Throwable $e) {
            // Use panel databases list if connection fails
            $mysqlDbs = array_column($databases, 'db_name');
        }

        // Cloud settings
        $cfAccountId = Database::getSetting('cf_account_id', '');
        $defaultEndpoint = $cfAccountId ? "https://{$cfAccountId}.r2.cloudflarestorage.com" : "";

        $cloudSettings = [
            'enabled'    => Database::getSetting('cloud_storage_enabled', '0') === '1',
            'provider'   => Database::getSetting('cloud_storage_provider', 'cloudflare_r2'),
            'endpoint'   => Database::getSetting('cloud_storage_endpoint', $defaultEndpoint),
            'bucket'     => Database::getSetting('cloud_storage_bucket', ''),
            'access_key' => Database::getSetting('cloud_storage_access_key', ''),
            'secret_key' => Database::getSetting('cloud_storage_secret_key', ''),
            'region'     => Database::getSetting('cloud_storage_region', 'auto'),
            'prefix'     => Database::getSetting('cloud_storage_prefix', 'almusa-backups'),
        ];

        // Schedule settings
        $scheduleSettings = [
            'enabled'        => Database::getSetting('backup_auto_enabled', '0') === '1',
            'schedule'       => Database::getSetting('backup_auto_schedule', '0 2 * * *'),
            'target_type'    => Database::getSetting('backup_auto_target_type', 'all_db'),
            'destination'    => Database::getSetting('backup_auto_destination', 'local'),
            'retention_days' => (int)Database::getSetting('backup_retention_days', '7'),
        ];

        Response::view('backups/index', [
            'title'            => 'Database & Site Backups',
            'user'             => Auth::user(),
            'backups'          => $backups,
            'stats'            => $stats,
            'sites'            => $sites,
            'databases'        => $databases,
            'mysqlDbs'         => array_unique($mysqlDbs),
            'cloudSettings'    => $cloudSettings,
            'scheduleSettings' => $scheduleSettings,
        ]);
    }

    /**
     * Trigger instant backup creation
     */
    public function create(): void
    {
        $type = trim($_POST['type'] ?? 'database');
        $target = trim($_POST['target'] ?? '');
        $destination = trim($_POST['destination'] ?? 'local');

        if (!in_array($destination, ['local', 'cloud', 'both'], true)) {
            $destination = 'local';
        }

        try {
            switch ($type) {
                case 'database':
                    if (empty($target)) {
                        Response::json(['success' => false, 'error' => 'Pilih database yang ingin di-backup.'], 400);
                        return;
                    }
                    $res = BackupEngine::createDatabaseBackup($target, $destination, 'manual');
                    break;

                case 'all_db':
                    $res = BackupEngine::createDatabaseBackup('all', $destination, 'manual');
                    break;

                case 'site':
                    if (empty($target)) {
                        Response::json(['success' => false, 'error' => 'Pilih website yang ingin di-backup.'], 400);
                        return;
                    }
                    $res = BackupEngine::createSiteBackup($target, $destination, 'manual');
                    break;

                case 'all_sites':
                    $res = BackupEngine::createSiteBackup('all', $destination, 'manual');
                    break;

                case 'full':
                    $res = BackupEngine::createFullBackup($destination, 'manual');
                    break;

                default:
                    Response::json(['success' => false, 'error' => 'Tipe backup tidak dikenali.'], 400);
                    return;
            }

            if ($res['success']) {
                Response::json($res);
            } else {
                Response::json(['success' => false, 'error' => $res['error'] ?? 'Gagal membuat backup.'], 500);
            }
        } catch (\Throwable $e) {
            Response::json(['success' => false, 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Delete backup snapshot
     */
    public function delete(): void
    {
        $id = (int)($_POST['id'] ?? 0);
        $deleteRemote = !empty($_POST['delete_remote']);

        if ($id <= 0) {
            Response::json(['success' => false, 'error' => 'ID backup tidak valid.'], 400);
            return;
        }

        $ok = BackupEngine::deleteBackup($id, $deleteRemote);
        if ($ok) {
            Response::json(['success' => true, 'message' => 'File backup berhasil dihapus.']);
        } else {
            Response::json(['success' => false, 'error' => 'Gagal menghapus file backup.'], 500);
        }
    }

    /**
     * Download backup file securely
     */
    public function download(): void
    {
        $id = (int)($_GET['id'] ?? 0);
        if ($id <= 0) {
            http_response_code(400);
            echo "ID backup tidak valid.";
            exit;
        }

        $pdo = Database::getInstance();
        $stmt = $pdo->prepare("SELECT * FROM backups WHERE id = :id LIMIT 1");
        $stmt->execute([':id' => $id]);
        $backup = $stmt->fetch();

        if (!$backup) {
            http_response_code(404);
            echo "Data backup tidak ditemukan.";
            exit;
        }

        $filepath = $backup['filepath'];
        // Path traversal guard: must reside inside /www/backup or panel backup directory
        $realPath = realpath($filepath);
        $backupDir = realpath(BackupEngine::getBackupDir());

        if (!$realPath || !str_starts_with($realPath, $backupDir) || !file_exists($realPath)) {
            http_response_code(404);
            echo "File cadangan fisik tidak ditemukan di server lokal. File mungkin hanya tersimpan di Cloud Storage.";
            exit;
        }

        $filename = basename($realPath);
        $filesize = filesize($realPath);

        // Clear output buffer
        if (ob_get_level()) {
            ob_end_clean();
        }

        header('Content-Description: File Transfer');
        header('Content-Type: application/gzip');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Expires: 0');
        header('Cache-Control: must-revalidate');
        header('Pragma: public');
        header('Content-Length: ' . $filesize);

        readfile($realPath);
        exit;
    }

    /**
     * Restore database or site snapshot (1-Click Restore)
     */
    public function restore(): void
    {
        $id = (int)($_POST['id'] ?? 0);
        $target = trim($_POST['target'] ?? '');

        if ($id <= 0) {
            Response::json(['success' => false, 'error' => 'ID snapshot backup tidak valid.'], 400);
            return;
        }

        try {
            $res = BackupEngine::restoreBackup($id, $target ?: null);
            if ($res['success']) {
                Response::json($res);
            } else {
                Response::json(['success' => false, 'error' => $res['error'] ?? 'Gagal memulihkan snapshot.'], 500);
            }
        } catch (\Throwable $e) {
            Response::json(['success' => false, 'error' => 'Pengecualian sistem: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Sync existing local backup to Cloudflare R2 / AWS S3
     */
    public function syncCloud(): void
    {
        $id = (int)($_POST['id'] ?? 0);
        if ($id <= 0) {
            Response::json(['success' => false, 'error' => 'ID backup tidak valid.'], 400);
            return;
        }

        $res = BackupEngine::syncToCloud($id);
        if ($res['success']) {
            Response::json($res);
        } else {
            Response::json(['success' => false, 'error' => $res['error'] ?? 'Gagal sinkronisasi ke cloud.'], 500);
        }
    }

    /**
     * Save Cloudflare R2 / AWS S3 settings
     */
    public function saveCloudSettings(): void
    {
        $enabled   = !empty($_POST['cloud_storage_enabled']) ? '1' : '0';
        $provider  = trim($_POST['cloud_storage_provider'] ?? 'cloudflare_r2');
        $endpoint  = trim($_POST['cloud_storage_endpoint'] ?? '');
        $bucket    = trim($_POST['cloud_storage_bucket'] ?? '');
        $accessKey = trim($_POST['cloud_storage_access_key'] ?? '');
        $secretKey = trim($_POST['cloud_storage_secret_key'] ?? '');
        $region    = trim($_POST['cloud_storage_region'] ?? 'auto');
        $prefix    = trim($_POST['cloud_storage_prefix'] ?? 'almusa-backups');

        Database::setSetting('cloud_storage_enabled', $enabled);
        Database::setSetting('cloud_storage_provider', $provider);
        Database::setSetting('cloud_storage_endpoint', $endpoint);
        Database::setSetting('cloud_storage_bucket', $bucket);
        Database::setSetting('cloud_storage_access_key', $accessKey);
        if ($secretKey !== '') {
            Database::setSetting('cloud_storage_secret_key', $secretKey);
        }
        Database::setSetting('cloud_storage_region', $region ?: 'auto');
        Database::setSetting('cloud_storage_prefix', $prefix);

        Response::json([
            'success' => true,
            'message' => 'Pengaturan Cloud Storage (R2 / S3) berhasil disimpan!',
        ]);
    }

    /**
     * Test connection to remote cloud bucket
     */
    public function testCloud(): void
    {
        $endpoint  = trim($_POST['endpoint'] ?? Database::getSetting('cloud_storage_endpoint', ''));
        $bucket    = trim($_POST['bucket'] ?? Database::getSetting('cloud_storage_bucket', ''));
        $accessKey = trim($_POST['access_key'] ?? Database::getSetting('cloud_storage_access_key', ''));
        $secretKey = trim($_POST['secret_key'] ?? Database::getSetting('cloud_storage_secret_key', ''));
        $region    = trim($_POST['region'] ?? Database::getSetting('cloud_storage_region', 'auto'));

        if (empty($endpoint) || empty($bucket) || empty($accessKey) || empty($secretKey)) {
            Response::json([
                'success' => false,
                'error'   => 'Lengkapi form Endpoint, Bucket Name, Access Key, dan Secret Key terlebih dahulu.',
            ], 400);
            return;
        }

        $client = new S3Client($endpoint, $bucket, $accessKey, $secretKey, $region);
        $res = $client->testConnection();

        if ($res['success']) {
            Response::json($res);
        } else {
            Response::json($res, 400);
        }
    }

    /**
     * Save automated backup schedule & retention settings
     */
    public function saveSchedule(): void
    {
        $enabled     = !empty($_POST['backup_auto_enabled']) ? '1' : '0';
        $schedule    = trim($_POST['backup_auto_schedule'] ?? '0 2 * * *');
        $targetType  = trim($_POST['backup_auto_target_type'] ?? 'all_db');
        $destination = trim($_POST['backup_auto_destination'] ?? 'local');
        $retention   = (int)($_POST['backup_retention_days'] ?? 7);

        if ($retention < 1) $retention = 7;
        if ($retention > 365) $retention = 365;

        if (!\App\Controllers\CronController::isValidCronExpression($schedule)) {
            Response::json(['success' => false, 'error' => 'Format ekspresi cron tidak valid. Contoh: 0 2 * * * (Setiap hari pk 02:00).'], 400);
            return;
        }

        Database::setSetting('backup_auto_enabled', $enabled);
        Database::setSetting('backup_auto_schedule', $schedule);
        Database::setSetting('backup_auto_target_type', $targetType);
        Database::setSetting('backup_auto_destination', $destination);
        Database::setSetting('backup_retention_days', (string)$retention);

        // Sync with Linux system cron
        BackupEngine::syncScheduleCron();

        Response::json([
            'success' => true,
            'message' => 'Pengaturan jadwal otomatis dan retensi berhasil disimpan dan disinkronkan ke cron sistem!',
        ]);
    }
}
