<?php
/**
 * Almusa Server Control Panel - Background Task Daemon
 * 
 * Runs as root under systemd (mypanel-worker.service)
 * Polls SQLite tasks table and safely executes whitelisted system scripts.
 */

if (php_sapi_name() !== 'cli') {
    die("This daemon script can only be run from the CLI.\n");
}

define('PANEL_ROOT', __DIR__);
require_once PANEL_ROOT . '/app/bootstrap.php';
use App\Core\Database;

$logFile = (defined('PANEL_ROOT') ? PANEL_ROOT : __DIR__) . '/data/panelExec.log';
if (!file_exists($logFile)) {
    @touch($logFile);
    @chmod($logFile, 0666);
}
$running = true;

// Signal handlers for graceful shutdown
if (function_exists('pcntl_signal')) {
    pcntl_async_signals(true);
    pcntl_signal(SIGTERM, function () use (&$running) {
        $running = false;
    });
    pcntl_signal(SIGINT, function () use (&$running) {
        $running = false;
    });
}

echo "[" . date('Y-m-d H:i:s') . "] Almusa Task Daemon initialized. Polling task queue...\n";

while ($running) {
    try {
        $pdo = Database::getInstance();
        $stmt = $pdo->prepare("SELECT * FROM tasks WHERE status = 'pending' ORDER BY id ASC LIMIT 1");
        $stmt->execute();
        $task = $stmt->fetch();

        if ($task) {
            $taskId = (int)$task['id'];
            $action = (string)$task['action'];
            $title  = (string)$task['title'];
            $payload = json_decode($task['payload'] ?? '{}', true) ?: [];

            echo "[" . date('Y-m-d H:i:s') . "] Processing Task #{$taskId}: {$title} [Action: {$action}]\n";

            // Mark as processing
            $update = $pdo->prepare("UPDATE tasks SET status = 'processing', started_at = CURRENT_TIMESTAMP WHERE id = :id");
            $update->execute([':id' => $taskId]);
            $update = null;
            $pdo = null;
            Database::reset();

            // Append start banner to log file
            $startBanner = "\n======================================================\n";
            $startBanner .= "[TASK #{$taskId}] {$title}\n";
            $startBanner .= "Action: {$action} | Started: " . date('Y-m-d H:i:s') . "\n";
            $startBanner .= "======================================================\n";
            @file_put_contents($logFile, $startBanner, FILE_APPEND);

            $command = buildSanitizedCommand($action, $payload);
            $exitCode = 1;

            if ($command === null) {
                $err = "[ERROR] Unrecognized or disallowed task action: '{$action}'. Skipped.\n";
                @file_put_contents($logFile, $err, FILE_APPEND);
            } else {
                // Execute command and stream output directly into log file
                $fullCmd = "{$command} >> " . escapeshellarg($logFile) . " 2>&1";
                @file_put_contents($logFile, "[EXEC] {$command}\n\n", FILE_APPEND);
                
                system($fullCmd, $exitCode);
            }

            $endBanner = "\n------------------------------------------------------\n";
            $endBanner .= "Finished: " . date('Y-m-d H:i:s') . " | Exit Code: {$exitCode}\n";
            $endBanner .= "------------------------------------------------------\n";
            @file_put_contents($logFile, $endBanner, FILE_APPEND);

            // Re-open clean database connection to update final status
            $pdo = Database::getInstance();
            $status = ($exitCode === 0) ? 'completed' : 'failed';
            $finalUpdate = $pdo->prepare("
                UPDATE tasks 
                SET status = :status, exit_code = :code, finished_at = CURRENT_TIMESTAMP 
                WHERE id = :id
            ");
            $finalUpdate->execute([
                ':status' => $status,
                ':code'   => $exitCode,
                ':id'     => $taskId,
            ]);
            $finalUpdate = null;
            $pdo = null;
            Database::reset();

            echo "[" . date('Y-m-d H:i:s') . "] Task #{$taskId} {$status} (Exit Code: {$exitCode})\n";
            $taskId = null;
        }
    } catch (\Throwable $e) {
        $msg = "[" . date('Y-m-d H:i:s') . "] Daemon Exception: " . $e->getMessage() . "\n";
        echo $msg;
        @file_put_contents($logFile, $msg, FILE_APPEND);
        Database::reset();

        // If a task failed during final status update, attempt a retry with fresh connection
        if (isset($taskId) && $taskId !== null) {
            try {
                $retryPdo = Database::getInstance();
                $retry = $retryPdo->prepare("UPDATE tasks SET status = :s, exit_code = :c, finished_at = CURRENT_TIMESTAMP WHERE id = :id");
                $retry->execute([':s' => ($exitCode === 0 ? 'completed' : 'failed'), ':c' => $exitCode, ':id' => $taskId]);
            } catch (\Throwable $e2) {
                // Ignore secondary error
            }
            $taskId = null;
        }
    }

    sleep(1);
}

echo "[" . date('Y-m-d H:i:s') . "] Almusa Task Daemon stopped gracefully.\n";

/**
 * Validates and maps task action to hardened shell commands
 */
function buildSanitizedCommand(string $action, array $payload): ?string
{
    $root = PANEL_ROOT;

    switch ($action) {
        case 'plugin_install':
            $plugin = preg_replace('/[^a-zA-Z0-9_\-]/', '', (string)($payload['plugin'] ?? ''));
            $version = preg_replace('/[^a-zA-Z0-9_\-\.]/', '', (string)($payload['version'] ?? ''));
            $script = "{$root}/plugins/{$plugin}/install.sh";
            if (!file_exists($script)) return null;
            return "bash " . escapeshellarg($script) . " install " . escapeshellarg($version);

        case 'plugin_uninstall':
            $plugin = preg_replace('/[^a-zA-Z0-9_\-]/', '', (string)($payload['plugin'] ?? ''));
            $version = preg_replace('/[^a-zA-Z0-9_\-\.]/', '', (string)($payload['version'] ?? ''));
            $script = "{$root}/plugins/{$plugin}/install.sh";
            if (!file_exists($script)) return null;
            return "bash " . escapeshellarg($script) . " uninstall " . escapeshellarg($version);

        case 'php_ext_install':
            $ver = preg_replace('/[^0-9\.]/', '', (string)($payload['version'] ?? ''));
            $ext = preg_replace('/[^a-zA-Z0-9_\-]/', '', (string)($payload['extension'] ?? ''));
            $subAction = ($payload['sub_action'] ?? 'install') === 'uninstall' ? 'uninstall' : 'install';
            $script = "{$root}/plugins/php/ext.sh";
            if (!file_exists($script)) return null;
            return "bash " . escapeshellarg($script) . " " . escapeshellarg($subAction) . " " . escapeshellarg($ver) . " " . escapeshellarg($ext);

        case 'vhost_create':
            $domain = preg_replace('/[^a-zA-Z0-9\.\-]/', '', (string)($payload['domain'] ?? ''));
            $docroot = preg_replace('/[^a-zA-Z0-9_\.\/\-]/', '', (string)($payload['docroot'] ?? ''));
            $phpVer = preg_replace('/[^0-9\.]/', '', (string)($payload['php_version'] ?? '8.3'));
            $rewrite = preg_replace('/[^a-zA-Z0-9_\-]/', '', (string)($payload['rewrite'] ?? 'default'));
            $script = "{$root}/scripts/vhost_manager.sh";
            if (!file_exists($script)) return null;
            return "bash " . escapeshellarg($script) . " create " . escapeshellarg($domain) . " " . escapeshellarg($docroot) . " " . escapeshellarg($phpVer) . " " . escapeshellarg($rewrite);

        case 'vhost_delete':
            $domain = preg_replace('/[^a-zA-Z0-9\.\-]/', '', (string)($payload['domain'] ?? ''));
            $docroot = preg_replace('/[^a-zA-Z0-9_\.\/\-]/', '', (string)($payload['docroot'] ?? ''));
            $deleteFiles = !empty($payload['delete_files']) ? '1' : '0';
            $script = "{$root}/scripts/vhost_manager.sh";
            if (!file_exists($script)) return null;
            return "bash " . escapeshellarg($script) . " delete " . escapeshellarg($domain) . " " . escapeshellarg($docroot) . " " . escapeshellarg($deleteFiles);

        case 'vhost_switch_php':
            $domain = preg_replace('/[^a-zA-Z0-9\.\-]/', '', (string)($payload['domain'] ?? ''));
            $phpVer = preg_replace('/[^0-9\.]/', '', (string)($payload['php_version'] ?? '8.3'));
            $script = "{$root}/scripts/vhost_manager.sh";
            if (!file_exists($script)) return null;
            return "bash " . escapeshellarg($script) . " switch_php " . escapeshellarg($domain) . " " . escapeshellarg($phpVer);

        case 'vhost_save_conf':
            $domain = preg_replace('/[^a-zA-Z0-9\.\-]/', '', (string)($payload['domain'] ?? ''));
            $srcFile = (string)($payload['src_file'] ?? '');
            $script = "{$root}/scripts/vhost_manager.sh";
            if (!file_exists($script) || !file_exists($srcFile)) return null;
            return "bash " . escapeshellarg($script) . " save_conf " . escapeshellarg($domain) . " " . escapeshellarg($srcFile);

        case 'site_issue_ssl':
            $domain = preg_replace('/[^a-zA-Z0-9\.\-]/', '', (string)($payload['domain'] ?? ''));
            $docroot = preg_replace('/[^a-zA-Z0-9_\.\/\-]/', '', (string)($payload['docroot'] ?? ''));
            $email = filter_var($payload['email'] ?? '', FILTER_VALIDATE_EMAIL) ? (string)$payload['email'] : "admin@{$domain}";
            $forceHttps = !empty($payload['force_https']) ? '1' : '0';
            $script = "{$root}/scripts/ssl_manager.sh";
            if (!file_exists($script)) return null;
            return "bash " . escapeshellarg($script) . " issue " . escapeshellarg($domain) . " " . escapeshellarg($docroot) . " " . escapeshellarg($email) . " " . escapeshellarg($forceHttps);

        case 'site_disable_ssl':
            $domain = preg_replace('/[^a-zA-Z0-9\.\-]/', '', (string)($payload['domain'] ?? ''));
            $script = "{$root}/scripts/ssl_manager.sh";
            if (!file_exists($script)) return null;
            return "bash " . escapeshellarg($script) . " disable " . escapeshellarg($domain);

        case 'site_renew_ssl':
            $domain = preg_replace('/[^a-zA-Z0-9\.\-]/', '', (string)($payload['domain'] ?? ''));
            $script = "{$root}/scripts/ssl_manager.sh";
            if (!file_exists($script)) return null;
            return "bash " . escapeshellarg($script) . " renew " . escapeshellarg($domain);

        case 'nginx_save_global':
            $srcFile = (string)($payload['src_file'] ?? '');
            $script = "{$root}/scripts/nginx_manager.sh";
            if (!file_exists($script) || !file_exists($srcFile)) return null;
            return "bash " . escapeshellarg($script) . " save " . escapeshellarg($srcFile);

        case 'nginx_test':
            $srcFile = (string)($payload['src_file'] ?? '');
            $script = "{$root}/scripts/nginx_manager.sh";
            if (!file_exists($script)) return null;
            return "bash " . escapeshellarg($script) . " test " . ($srcFile ? escapeshellarg($srcFile) : '');

        case 'nginx_service':
            $subCmd = preg_replace('/[^a-z]/', '', (string)($payload['command'] ?? 'reload'));
            $script = "{$root}/scripts/nginx_manager.sh";
            if (!file_exists($script)) return null;
            return "bash " . escapeshellarg($script) . " service " . escapeshellarg($subCmd);

        case 'site_clear_log':
            $domain = preg_replace('/[^a-zA-Z0-9\.\-]/', '', (string)($payload['domain'] ?? ''));
            $logType = preg_replace('/[^a-z]/', '', (string)($payload['type'] ?? 'all'));
            $script = "{$root}/scripts/vhost_manager.sh";
            if (!file_exists($script)) return null;
            return "bash " . escapeshellarg($script) . " clear_log " . escapeshellarg($domain) . " " . escapeshellarg($logType);

        case 'cron_sync':
            $srcFile = (string)($payload['src_file'] ?? '');
            $script = "{$root}/scripts/cron_manager.sh";
            if (!file_exists($script)) return null;
            return "bash " . escapeshellarg($script) . " sync " . ($srcFile ? escapeshellarg($srcFile) : '');

        case 'cron_run':
            $user = preg_replace('/[^a-zA-Z0-9_\-]/', '', (string)($payload['user'] ?? 'afandi'));
            $cmd = (string)($payload['command'] ?? '');
            $script = "{$root}/scripts/cron_manager.sh";
            if (!file_exists($script) || empty($cmd)) return null;
            return "bash " . escapeshellarg($script) . " run " . escapeshellarg($user) . " " . escapeshellarg($cmd);

        case 'fix_permissions':
            $targetPath = (string)($payload['path'] ?? '');
            $script = "{$root}/scripts/vhost_manager.sh";
            if (!file_exists($script) || empty($targetPath)) return null;
            return "bash " . escapeshellarg($script) . " fix_permissions _ " . escapeshellarg($targetPath);

        case 'tunnel_quick_start':
            $siteId = (int)($payload['site_id'] ?? 0);
            $domain = preg_replace('/[^a-zA-Z0-9\.\-]/', '', (string)($payload['domain'] ?? ''));
            $script = "{$root}/plugins/cloudflared/quick_tunnel.sh";
            if (!file_exists($script)) return null;
            return "bash " . escapeshellarg($script) . " start " . escapeshellarg((string)$siteId) . " " . escapeshellarg($domain);

        case 'tunnel_quick_stop':
            $siteId = (int)($payload['site_id'] ?? 0);
            $pid = (int)($payload['pid'] ?? 0);
            $script = "{$root}/plugins/cloudflared/quick_tunnel.sh";
            if (!file_exists($script)) return null;
            return "bash " . escapeshellarg($script) . " stop " . escapeshellarg((string)$siteId) . " " . escapeshellarg((string)$pid);

        case 'service_reload':
            $service = preg_replace('/[^a-zA-Z0-9_\-\.]/', '', (string)($payload['service'] ?? ''));
            if (!in_array($service, ['nginx', 'mysql', 'mariadb', 'cloudflared']) && !str_starts_with($service, 'php')) {
                return null;
            }
            return "systemctl reload " . escapeshellarg($service);

        default:
            return null;
    }
}
