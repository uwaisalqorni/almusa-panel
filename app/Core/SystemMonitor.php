<?php

namespace App\Core;

class SystemMonitor
{
    public static function getStats(): array
    {
        return [
            'cpu'        => self::getCpuUsage(),
            'memory'     => self::getMemoryUsage(),
            'disk'       => self::getDiskUsage(),
            'load'       => self::getLoadAverage(),
            'uptime'     => self::getUptime(),
            'os'         => self::getOsInfo(),
            'timestamp'  => time(),
        ];
    }

    public static function getCpuUsage(): array
    {
        $cacheFile = '/tmp/almusa_cpu_stat.json';
        $currentStat = @file('/proc/stat');

        if (!$currentStat || empty($currentStat[0])) {
            return ['percent' => 0, 'cores' => 1];
        }

        $cores = 1;
        $cpuInfo = @file('/proc/cpuinfo');
        if ($cpuInfo) {
            $coresCount = 0;
            foreach ($cpuInfo as $line) {
                if (preg_match('/^processor\s*:\s*\d+/', $line)) {
                    $coresCount++;
                }
            }
            if ($coresCount > 0) {
                $cores = $coresCount;
            }
        }

        $fields = preg_split('/\s+/', trim($currentStat[0]));
        // $fields[0] is 'cpu', 1=user, 2=nice, 3=system, 4=idle, 5=iowait, 6=irq, 7=softirq, 8=steal
        $user = (int)($fields[1] ?? 0);
        $nice = (int)($fields[2] ?? 0);
        $system = (int)($fields[3] ?? 0);
        $idle = (int)($fields[4] ?? 0);
        $iowait = (int)($fields[5] ?? 0);
        $irq = (int)($fields[6] ?? 0);
        $softirq = (int)($fields[7] ?? 0);
        $steal = (int)($fields[8] ?? 0);

        $total = $user + $nice + $system + $idle + $iowait + $irq + $softirq + $steal;
        $idleTotal = $idle + $iowait;

        $percent = 0.0;
        if (file_exists($cacheFile)) {
            $prev = json_decode(@file_get_contents($cacheFile), true);
            if ($prev && isset($prev['total'], $prev['idleTotal'])) {
                $diffTotal = $total - $prev['total'];
                $diffIdle = $idleTotal - $prev['idleTotal'];
                if ($diffTotal > 0) {
                    $diffUsed = $diffTotal - $diffIdle;
                    $percent = round(($diffUsed / $diffTotal) * 100, 1);
                }
            }
        }

        @file_put_contents($cacheFile, json_encode([
            'total'     => $total,
            'idleTotal' => $idleTotal,
            'time'      => microtime(true),
        ]));

        if ($percent < 0) {
            $percent = 0.0;
        } elseif ($percent > 100) {
            $percent = 100.0;
        }

        return [
            'percent' => $percent,
            'cores'   => $cores,
        ];
    }

    public static function getMemoryUsage(): array
    {
        $meminfo = @file('/proc/meminfo');
        if (!$meminfo) {
            return [
                'total_mb' => 0,
                'used_mb'  => 0,
                'free_mb'  => 0,
                'percent'  => 0,
            ];
        }

        $data = [];
        foreach ($meminfo as $line) {
            if (preg_match('/^(\w+):\s+(\d+)\s+kB/', $line, $matches)) {
                $data[$matches[1]] = (int)$matches[2];
            }
        }

        $totalKb = $data['MemTotal'] ?? 1;
        $availKb = $data['MemAvailable'] ?? ($data['MemFree'] ?? 0);
        $usedKb = max(0, $totalKb - $availKb);

        $totalMb = round($totalKb / 1024);
        $usedMb = round($usedKb / 1024);
        $freeMb = max(0, $totalMb - $usedMb);
        $percent = round(($usedKb / $totalKb) * 100, 1);

        return [
            'total_mb' => (int)$totalMb,
            'used_mb'  => (int)$usedMb,
            'free_mb'  => (int)$freeMb,
            'percent'  => $percent,
            'formatted' => self::formatBytes($usedKb * 1024) . ' / ' . self::formatBytes($totalKb * 1024),
        ];
    }

    public static function getDiskUsage(?string $path = '/'): array
    {
        $target = ($path && is_dir($path)) ? $path : '/';
        $totalBytes = @disk_total_space($target) ?: 1;
        $freeBytes = @disk_free_space($target) ?: 0;
        $usedBytes = max(0, $totalBytes - $freeBytes);

        $percent = round(($usedBytes / $totalBytes) * 100, 1);

        return [
            'total_gb'  => round($totalBytes / (1024 * 1024 * 1024), 1),
            'used_gb'   => round($usedBytes / (1024 * 1024 * 1024), 1),
            'free_gb'   => round($freeBytes / (1024 * 1024 * 1024), 1),
            'percent'   => $percent,
            'formatted' => self::formatBytes($usedBytes) . ' / ' . self::formatBytes($totalBytes),
        ];
    }

    public static function getLoadAverage(): array
    {
        $load = sys_getloadavg();
        return [
            '1m'  => round($load[0] ?? 0, 2),
            '5m'  => round($load[1] ?? 0, 2),
            '15m' => round($load[2] ?? 0, 2),
        ];
    }

    public static function getUptime(): array
    {
        $uptimeContent = @file_get_contents('/proc/uptime');
        if (!$uptimeContent) {
            return ['seconds' => 0, 'formatted' => 'Unknown'];
        }

        $parts = explode(' ', trim($uptimeContent));
        $sec = (int)($parts[0] ?? 0);

        $days = floor($sec / 86400);
        $hours = floor(($sec % 86400) / 3600);
        $minutes = floor(($sec % 3600) / 60);

        $formatted = '';
        if ($days > 0) {
            $formatted .= "{$days}d ";
        }
        $formatted .= "{$hours}h {$minutes}m";

        return [
            'seconds'   => $sec,
            'formatted' => trim($formatted),
        ];
    }

    public static function getOsInfo(): array
    {
        $osName = 'Linux';
        if (file_exists('/etc/os-release')) {
            $osRelease = @parse_ini_file('/etc/os-release');
            if (!empty($osRelease['PRETTY_NAME'])) {
                $osName = trim($osRelease['PRETTY_NAME'], '"');
            }
        }

        $kernel = php_uname('r');
        $hostname = gethostname();
        $ip = $_SERVER['SERVER_ADDR'] ?? '127.0.0.1';

        return [
            'os'       => $osName,
            'kernel'   => $kernel,
            'hostname' => $hostname,
            'ip'       => $ip,
        ];
    }

    public static function isServiceActive(string $serviceName): bool
    {
        $sanitized = preg_replace('/[^a-zA-Z0-9_\-\.]/', '', $serviceName);
        if (empty($sanitized)) {
            return false;
        }

        $output = @shell_exec("systemctl is-active " . escapeshellarg($sanitized) . " 2>/dev/null");
        return trim((string)$output) === 'active';
    }

    public static function formatBytes(float|int $bytes, int $precision = 1): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);
        $bytes /= (1 << (10 * $pow));

        return round($bytes, $precision) . ' ' . $units[$pow];
    }

    /**
     * Dapatkan versi binary service yang terpasang di sistem Linux.
     */
    public static function getServiceVersion(string $service): ?string
    {
        switch ($service) {
            case 'nginx':
                $out = @shell_exec("nginx -v 2>&1");
                if ($out && preg_match('/nginx\/([0-9\.]+)/i', $out, $m)) {
                    return $m[1];
                }
                return null;

            case 'mysql':
            case 'mariadb':
                $out = @shell_exec("mariadb -V 2>&1 || mysql -V 2>&1");
                if ($out) {
                    if (preg_match('/(?:Distrib|from)\s+([0-9\.]+(?:-[a-zA-Z0-9]+)?)/i', $out, $m)) {
                        return $m[1];
                    }
                    if (preg_match('/([0-9]+\.[0-9]+\.[0-9]+(?:-[a-zA-Z0-9]+)?)/', $out, $m)) {
                        return $m[1];
                    }
                }
                return null;

            case 'cloudflared':
                $out = @shell_exec("cloudflared -v 2>&1");
                if ($out && preg_match('/version\s+([0-9\.]+)/i', $out, $m)) {
                    return $m[1];
                }
                return null;

            default:
                if (preg_match('/^php([0-9\.]+)$/', $service, $m)) {
                    $ver = $m[1];
                    $out = @shell_exec("php{$ver} -v 2>&1");
                    if ($out && preg_match('/PHP\s+([0-9\.]+)/i', $out, $pm)) {
                        return $pm[1];
                    }
                }
                return null;
        }
    }
}
