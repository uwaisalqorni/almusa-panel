<?php

namespace App\Core;

/**
 * Almusa Server Control Panel - Self-Updater Helper
 *
 * Handles version checking against GitHub Releases / Raw version.json,
 * SQLite settings caching (6-hour TTL), and update scheduling.
 */
class Updater
{
    public const CACHE_TTL = 21600; // 6 hours in seconds

    /**
     * Resolves the panel root directory.
     */
    public static function getPanelRoot(): string
    {
        return defined('PANEL_ROOT') ? PANEL_ROOT : dirname(__DIR__, 2);
    }

    /**
     * Reads local version information from version.json.
     */
    public static function getLocalVersion(): array
    {
        $root = self::getPanelRoot();
        $file = $root . '/version.json';

        $defaults = [
            'version'         => '1.0.0',
            'build'           => 20261029,
            'release_date'    => '2026-10-29',
            'repo_url'        => 'https://github.com/uwaisalqorni/almusa-panel',
            'raw_version_url' => 'https://raw.githubusercontent.com/uwaisalqorni/almusa-panel/main/version.json',
            'changelog'       => [],
        ];

        if (!file_exists($file) || !is_readable($file)) {
            return $defaults;
        }

        $content = @file_get_contents($file);
        $json = @json_decode($content, true);

        if (!is_array($json)) {
            return $defaults;
        }

        $merged = array_merge($defaults, $json);

        // Allow repo URL override from SQLite settings if configured
        $overrideRepo = Database::getSetting('update_repo_url');
        if (!empty($overrideRepo)) {
            $merged['repo_url'] = $overrideRepo;
        }

        $overrideRaw = Database::getSetting('update_raw_url');
        if (!empty($overrideRaw)) {
            $merged['raw_version_url'] = $overrideRaw;
        }

        return $merged;
    }

    /**
     * Fetches remote version information with 6-hour SQLite cache.
     */
    public static function getRemoteVersion(bool $force = false): ?array
    {
        $lastCheck = (int)Database::getSetting('last_update_check', '0');
        $cachedJson = Database::getSetting('latest_remote_version_json');
        $now = time();

        // 1. Return cached if valid and not forced
        if (!$force && ($now - $lastCheck < self::CACHE_TTL) && !empty($cachedJson)) {
            $decoded = @json_decode($cachedJson, true);
            if (is_array($decoded) && isset($decoded['version'])) {
                return $decoded;
            }
        }

        // 2. Fetch fresh version info from remote raw_version_url
        $local = self::getLocalVersion();
        $rawUrl = (string)($local['raw_version_url'] ?? '');

        if (empty($rawUrl)) {
            return null;
        }

        $rawResponse = self::fetchUrl($rawUrl, 5);

        if ($rawResponse === null) {
            // If fetch failed, return cached data if available
            if (!empty($cachedJson)) {
                $decoded = @json_decode($cachedJson, true);
                if (is_array($decoded)) {
                    return $decoded;
                }
            }
            return null;
        }

        $remoteData = @json_decode($rawResponse, true);
        if (!is_array($remoteData) || empty($remoteData['version'])) {
            return null;
        }

        // 3. Save successful result to SQLite settings cache
        Database::setSetting('last_update_check', (string)$now);
        Database::setSetting('latest_remote_version_json', json_encode($remoteData));

        return $remoteData;
    }

    /**
     * Comprehensive check comparing local vs remote version.
     */
    public static function check(bool $force = false): array
    {
        $local = self::getLocalVersion();
        $remote = self::getRemoteVersion($force);
        $lastChecked = (int)Database::getSetting('last_update_check', '0');

        if ($remote === null) {
            return [
                'update_available' => false,
                'current_version'  => (string)$local['version'],
                'current_build'    => (int)($local['build'] ?? 0),
                'latest_version'   => (string)$local['version'],
                'latest_build'     => (int)($local['build'] ?? 0),
                'repo_url'         => (string)($local['repo_url'] ?? ''),
                'changelog'        => (array)($local['changelog'] ?? []),
                'release_date'     => (string)($local['release_date'] ?? date('Y-m-d')),
                'last_checked'     => $lastChecked,
                'status'           => 'offline_or_error',
                'error_message'    => 'Tidak dapat memeriksa pembaruan dari server remote saat ini.',
            ];
        }

        $localVer = (string)$local['version'];
        $remoteVer = (string)$remote['version'];
        $localBuild = (int)($local['build'] ?? 0);
        $remoteBuild = (int)($remote['build'] ?? 0);

        // Compare semantic version or build number
        $isNewer = version_compare($remoteVer, $localVer, '>');
        if (!$isNewer && version_compare($remoteVer, $localVer, '==')) {
            $isNewer = ($remoteBuild > $localBuild);
        }

        return [
            'update_available' => $isNewer,
            'current_version'  => $localVer,
            'current_build'    => $localBuild,
            'latest_version'   => $remoteVer,
            'latest_build'     => $remoteBuild,
            'repo_url'         => (string)($remote['repo_url'] ?? $local['repo_url']),
            'changelog'        => (array)($remote['changelog'] ?? []),
            'release_date'     => (string)($remote['release_date'] ?? ''),
            'last_checked'     => $lastChecked ?: time(),
            'status'           => $isNewer ? 'available' : 'latest',
            'error_message'    => null,
        ];
    }

    /**
     * Schedules a self-update task in the background worker queue.
     */
    public static function queueUpdate(): array
    {
        $info = self::check(false);
        $targetVer = $info['latest_version'];
        $title = "Update Almusa Panel ke Versi " . $targetVer;

        $pdo = Database::getInstance();

        // Check if there is already a pending or processing update task
        $stmtCheck = $pdo->prepare("
            SELECT id FROM tasks 
            WHERE action = 'panel_self_update' AND status IN ('pending', 'processing') 
            LIMIT 1
        ");
        $stmtCheck->execute();
        $existing = $stmtCheck->fetch();

        if ($existing) {
            return [
                'success' => true,
                'task_id' => (int)$existing['id'],
                'message' => 'Tugas pembaruan sudah berada dalam antrean daemon.',
            ];
        }

        $stmt = $pdo->prepare("
            INSERT INTO tasks (title, action, payload, status) 
            VALUES (:title, 'panel_self_update', :payload, 'pending')
        ");
        $stmt->execute([
            ':title'   => $title,
            ':payload' => json_encode([
                'target_version' => $targetVer,
                'target_build'   => $info['latest_build'],
                'requested_at'   => date('Y-m-d H:i:s'),
            ]),
        ]);

        $taskId = (int)$pdo->lastInsertId();

        return [
            'success' => true,
            'task_id' => $taskId,
            'message' => "Tugas '{$title}' berhasil dimasukkan ke antrean daemon.",
        ];
    }

    /**
     * Safe cURL fetcher with short timeout.
     */
    private static function fetchUrl(string $url, int $timeoutSeconds = 5): ?string
    {
        if (!function_exists('curl_init')) {
            return @file_get_contents($url, false, stream_context_create([
                'http' => ['timeout' => $timeoutSeconds, 'user_agent' => 'AlmusaPanel-Updater/1.0'],
            ])) ?: null;
        }

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 3,
            CURLOPT_TIMEOUT        => $timeoutSeconds,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_USERAGENT      => 'AlmusaPanel-Updater/1.0 (+https://github.com/uwaisalqorni/almusa-panel)',
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);

        $response = curl_exec($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);

        if ($response === false || $httpCode < 200 || $httpCode >= 300) {
            return null;
        }

        return (string)$response;
    }
}
