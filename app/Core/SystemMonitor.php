<?php

namespace App\Core;

class SystemMonitor
{
    private static ?int $cachedCores = null;
    private static array $serviceStateCache = [];
    private static array $versionCache = [];

    /**
     * Dapatkan direktori shared memory RAM (/dev/shm) dengan fallback ke /tmp
     */
    private static function getShmPath(string $filename): string
    {
        static $base = null;
        if ($base === null) {
            $base = (is_dir('/dev/shm') && is_writable('/dev/shm')) ? '/dev/shm' : '/tmp';
        }
        return $base . '/' . $filename;
    }

    /**
     * Mengembalikan snapshot lengkap statistik server dengan latensi ultra-rendah
     */
    public static function getStats(): array
    {
        return [
            'cpu'        => self::getCpuUsage(),
            'memory'     => self::getMemoryUsage(),
            'disk'       => self::getDiskUsage('/'),
            'network'    => self::getNetworkUsage(),
            'mysql'      => self::getMysqlStats(),
            'load'       => self::getLoadAverage(),
            'uptime'     => self::getUptime(),
            'os'         => self::getOsInfo(),
            'timestamp'  => time(),
        ];
    }

    /**
     * Membaca CPU Usage secara langsung dari /proc/stat tanpa fork sub-shell
     */
    public static function getCpuUsage(): array
    {
        $cacheFile = self::getShmPath('almusa_cpu_stat.json');
        $currentStat = @file_get_contents('/proc/stat');

        if (!$currentStat) {
            return ['percent' => 0.0, 'cores' => 1];
        }

        // Cache jumlah CPU core agar /proc/cpuinfo hanya dibaca sekali seumur hidup proses
        if (self::$cachedCores === null) {
            $coresCount = 0;
            $cpuInfo = @file_get_contents('/proc/cpuinfo');
            if ($cpuInfo) {
                $coresCount = substr_count($cpuInfo, 'processor');
            }
            self::$cachedCores = max(1, $coresCount);
        }
        $cores = self::$cachedCores;

        // Ambil baris pertama ('cpu ...')
        $firstLine = strtok($currentStat, "\r\n");
        $fields = preg_split('/\s+/', trim((string)$firstLine));

        // $fields[0] is 'cpu', 1=user, 2=nice, 3=system, 4=idle, 5=iowait, 6=irq, 7=softirq, 8=steal
        $user    = (int)($fields[1] ?? 0);
        $nice    = (int)($fields[2] ?? 0);
        $system  = (int)($fields[3] ?? 0);
        $idle    = (int)($fields[4] ?? 0);
        $iowait  = (int)($fields[5] ?? 0);
        $irq     = (int)($fields[6] ?? 0);
        $softirq = (int)($fields[7] ?? 0);
        $steal   = (int)($fields[8] ?? 0);

        $total = $user + $nice + $system + $idle + $iowait + $irq + $softirq + $steal;
        $idleTotal = $idle + $iowait;

        $now = microtime(true);
        $percent = 0.0;

        if (file_exists($cacheFile)) {
            $prev = json_decode((string)@file_get_contents($cacheFile), true);
            if ($prev && isset($prev['total'], $prev['idleTotal'])) {
                $timeDiff = isset($prev['time']) ? ($now - (float)$prev['time']) : 1.0;
                // Jika request dipanggil terlalu rapat (< 0.7s), reuse persentase sebelumnya untuk menghindari jitter
                if ($timeDiff < 0.7 && isset($prev['percent'])) {
                    return [
                        'percent' => (float)$prev['percent'],
                        'cores'   => $cores,
                    ];
                }
                $diffTotal = $total - $prev['total'];
                $diffIdle = $idleTotal - $prev['idleTotal'];
                if ($diffTotal > 0) {
                    $diffUsed = $diffTotal - $diffIdle;
                    $percent = round(($diffUsed / $diffTotal) * 100, 1);
                }
            }
        }

        $percent = max(0.0, min(100.0, $percent));

        @file_put_contents($cacheFile, json_encode([
            'total'     => $total,
            'idleTotal' => $idleTotal,
            'time'      => $now,
            'percent'   => $percent,
        ]));

        return [
            'percent' => $percent,
            'cores'   => $cores,
        ];
    }

    /**
     * Membaca statistik Memory & Swap murni dari /proc/meminfo kernel Linux
     */
    public static function getMemoryUsage(): array
    {
        $raw = @file_get_contents('/proc/meminfo');
        if (!$raw) {
            return [
                'total_mb'  => 0,
                'used_mb'   => 0,
                'free_mb'   => 0,
                'percent'   => 0.0,
                'formatted' => '0 B / 0 B',
            ];
        }

        $stats = [];
        $lines = explode("\n", $raw);
        foreach ($lines as $line) {
            if (empty($line) || !str_contains($line, ':')) continue;
            [$k, $v] = explode(':', $line, 2);
            $stats[$k] = (int)filter_var($v, FILTER_SANITIZE_NUMBER_INT);
        }

        $totalKb = $stats['MemTotal'] ?? 1;
        // MemAvailable diperkenalkan di Linux 3.14+ untuk mengukur RAM yang benar-benar bebas
        $availKb = $stats['MemAvailable'] ?? (($stats['MemFree'] ?? 0) + ($stats['Buffers'] ?? 0) + ($stats['Cached'] ?? 0));
        $usedKb = max(0, $totalKb - $availKb);

        $totalMb = round($totalKb / 1024);
        $usedMb = round($usedKb / 1024);
        $freeMb = max(0, $totalMb - $usedMb);
        $percent = round(($usedKb / $totalKb) * 100, 1);

        // Swap memory statistics
        $swapTotalKb = $stats['SwapTotal'] ?? 0;
        $swapFreeKb = $stats['SwapFree'] ?? 0;
        $swapUsedKb = max(0, $swapTotalKb - $swapFreeKb);
        $swapPercent = $swapTotalKb > 0 ? round(($swapUsedKb / $swapTotalKb) * 100, 1) : 0.0;

        return [
            'total_mb'      => (int)$totalMb,
            'used_mb'       => (int)$usedMb,
            'free_mb'       => (int)$freeMb,
            'percent'       => $percent,
            'swap_total_mb' => (int)round($swapTotalKb / 1024),
            'swap_used_mb'  => (int)round($swapUsedKb / 1024),
            'swap_percent'  => $swapPercent,
            'formatted'     => self::formatBytes($usedKb * 1024) . ' / ' . self::formatBytes($totalKb * 1024),
        ];
    }

    /**
     * Membaca kapasitas disk penyimpanan
     */
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

    /**
     * Membaca Load Average murni dari /proc/loadavg tanpa spawn shell
     */
    public static function getLoadAverage(): array
    {
        if (function_exists('sys_getloadavg')) {
            $load = sys_getloadavg();
            if (is_array($load) && count($load) >= 3) {
                return [
                    '1m'  => round($load[0] ?? 0.0, 2),
                    '5m'  => round($load[1] ?? 0.0, 2),
                    '15m' => round($load[2] ?? 0.0, 2),
                ];
            }
        }

        $raw = @file_get_contents('/proc/loadavg');
        if ($raw) {
            $parts = explode(' ', trim($raw));
            return [
                '1m'  => round((float)($parts[0] ?? 0), 2),
                '5m'  => round((float)($parts[1] ?? 0), 2),
                '15m' => round((float)($parts[2] ?? 0), 2),
            ];
        }

        return ['1m' => 0.0, '5m' => 0.0, '15m' => 0.0];
    }

    /**
     * Membaca Network I/O langsung dari /proc/net/dev
     */
    public static function getNetworkUsage(): array
    {
        $cacheFile = self::getShmPath('almusa_net_stat.json');
        $raw = @file_get_contents('/proc/net/dev');
        $rx = 0.0;
        $tx = 0.0;

        if ($raw) {
            $lines = explode("\n", $raw);
            foreach ($lines as $line) {
                if (!str_contains($line, ':')) continue;
                [$iface, $data] = explode(':', $line, 2);
                $iface = trim($iface);
                if ($iface === 'lo' || str_starts_with($iface, 'docker') || str_starts_with($iface, 'veth') || str_starts_with($iface, 'br-')) {
                    continue;
                }
                $cols = preg_split('/\s+/', trim($data));
                $rx += (float)($cols[0] ?? 0);
                $tx += (float)($cols[8] ?? 0);
            }
        }

        $now = microtime(true);
        $rxRate = 0.0; // bytes/sec
        $txRate = 0.0;

        if (file_exists($cacheFile)) {
            $prev = json_decode((string)@file_get_contents($cacheFile), true);
            if ($prev && isset($prev['time'], $prev['rx'], $prev['tx'])) {
                $timeDiff = max(0.1, $now - (float)$prev['time']);
                if ($timeDiff < 0.7 && isset($prev['rx_rate'], $prev['tx_rate'])) {
                    $rxRate = (float)$prev['rx_rate'];
                    $txRate = (float)$prev['tx_rate'];
                } else {
                    $rxRate = max(0.0, ($rx - (float)$prev['rx']) / $timeDiff);
                    $txRate = max(0.0, ($tx - (float)$prev['tx']) / $timeDiff);
                }
            }
        }

        @file_put_contents($cacheFile, json_encode([
            'rx'      => $rx,
            'tx'      => $tx,
            'time'    => $now,
            'rx_rate' => $rxRate,
            'tx_rate' => $txRate,
        ]));

        return [
            'rx_rate'      => round($rxRate, 1),
            'tx_rate'      => round($txRate, 1),
            'rx_formatted' => self::formatBytes($rxRate) . '/s',
            'tx_formatted' => self::formatBytes($txRate) . '/s',
            'rx_total'     => self::formatBytes($rx),
            'tx_total'     => self::formatBytes($tx),
        ];
    }

    /**
     * Membaca Uptime sistem dari /proc/uptime
     */
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

    /**
     * Membaca informasi sistem operasi dari /etc/os-release dan kernel
     */
    public static function getOsInfo(): array
    {
        static $cachedOs = null;
        if ($cachedOs !== null) {
            return $cachedOs;
        }

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

        $cachedOs = [
            'os'       => $osName,
            'kernel'   => $kernel,
            'hostname' => $hostname,
            'ip'       => $ip,
        ];
        return $cachedOs;
    }

    /**
     * Pengecekan status layanan super-cepat (< 0.05ms) berbasis Kernel /run /proc checks
     * Mencegah fork proses shell_exec('systemctl is-active ...') yang membebani CPU.
     */
    public static function isServiceActive(string $serviceName): bool
    {
        $sanitized = preg_replace('/[^a-zA-Z0-9_\-\.]/', '', $serviceName);
        if (empty($sanitized)) {
            return false;
        }

        // 1. Cek in-memory request cache terlebih dahulu
        if (isset(self::$serviceStateCache[$sanitized])) {
            return self::$serviceStateCache[$sanitized];
        }

        $active = false;

        // 2. Direct Kernel /run/ PID & Socket Check (Zero fork, sub-millisecond)
        switch ($sanitized) {
            case 'nginx':
                $active = self::isPidAlive('/run/nginx.pid') || self::isPidAlive('/var/run/nginx.pid');
                break;

            case 'mysql':
            case 'mariadb':
                $active = file_exists('/run/mysqld/mysqld.sock') 
                       || file_exists('/var/run/mysqld/mysqld.sock')
                       || self::isPidAlive('/run/mysqld/mysqld.pid');
                break;

            case 'mypanel-web':
                $active = self::isPidAlive('/run/almusa-panel-fpm.pid') 
                       || file_exists('/run/php/almusa-panel.sock');
                break;

            case 'mypanel-worker':
                $active = self::isWorkerRunning();
                break;

            default:
                // Cek PHP-FPM socket / pid (misal: php8.3-fpm)
                if (preg_match('/^php([0-9\.]+)-fpm$/', $sanitized, $m)) {
                    $ver = $m[1];
                    $active = file_exists("/run/php/php{$ver}-fpm.sock") 
                           || self::isPidAlive("/run/php/php{$ver}-fpm.pid");
                }
                break;
        }

        // 3. Fallback ke systemctl is-active dengan Shared Memory Cache 3 detik jika pengecekan PID belum membuahkan hasil
        if (!$active) {
            $shmKey = 'almusa_svc_' . md5($sanitized) . '.json';
            $shmFile = self::getShmPath($shmKey);
            $now = time();

            if (file_exists($shmFile)) {
                $cData = json_decode((string)@file_get_contents($shmFile), true);
                if ($cData && isset($cData['t'], $cData['a']) && ($now - (int)$cData['t'] < 3)) {
                    $active = (bool)$cData['a'];
                    self::$serviceStateCache[$sanitized] = $active;
                    return $active;
                }
            }

            $out = @shell_exec("systemctl is-active " . escapeshellarg($sanitized) . " 2>/dev/null");
            $active = trim((string)$out) === 'active';
            @file_put_contents($shmFile, json_encode(['t' => $now, 'a' => $active]));
        }

        self::$serviceStateCache[$sanitized] = $active;
        return $active;
    }

    /**
     * Memeriksa apakah PID dari pidfile masih aktif di dalam /proc tanpa spawn shell
     */
    private static function isPidAlive(string $pidFile): bool
    {
        if (!file_exists($pidFile) || !is_readable($pidFile)) {
            return false;
        }
        $pid = (int)trim((string)@file_get_contents($pidFile));
        return ($pid > 0 && is_dir("/proc/{$pid}"));
    }

    /**
     * Memeriksa apakah worker daemon task.php sedang berjalan
     */
    private static function isWorkerRunning(): bool
    {
        // Cari proses task.php di /proc
        $pids = @scandir('/proc');
        if ($pids) {
            foreach ($pids as $p) {
                if (!ctype_digit($p)) continue;
                $cmdline = @file_get_contents("/proc/{$p}/cmdline");
                if ($cmdline && str_contains($cmdline, 'task.php')) {
                    return true;
                }
            }
        }
        return false;
    }

    /**
     * Membaca performa dan statistik MariaDB/MySQL dengan shared memory caching 3s
     */
    public static function getMysqlStats(): array
    {
        $cacheFile = self::getShmPath('almusa_mysql_stat.json');
        $now = time();

        if (file_exists($cacheFile)) {
            $cached = json_decode((string)@file_get_contents($cacheFile), true);
            if ($cached && isset($cached['_cached_at']) && ($now - (int)$cached['_cached_at'] < 3)) {
                unset($cached['_cached_at']);
                return $cached;
            }
        }

        $isActive = self::isServiceActive('mysql') || self::isServiceActive('mariadb');
        if (!$isActive) {
            $res = [
                'active'          => false,
                'connected'       => 0,
                'max_connections' => 151,
                'percent'         => 0.0,
                'qps'             => 0.0,
                'slow_queries'    => 0,
                'uptime'          => 'Offline',
            ];
            @file_put_contents($cacheFile, json_encode(array_merge($res, ['_cached_at' => $now])));
            return $res;
        }

        $pass = Database::getSetting('mysql_root_pass', '');
        $options = [
            \PDO::ATTR_TIMEOUT => 1,
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_SILENT,
        ];

        try {
            try {
                $pdo = new \PDO('mysql:host=127.0.0.1;port=3306', 'root', $pass, $options);
            } catch (\Exception $e) {
                $pdo = new \PDO('mysql:unix_socket=/var/run/mysqld/mysqld.sock', 'root', '', $options);
            }

            $stmt = $pdo->query("SHOW GLOBAL STATUS WHERE Variable_name IN ('Threads_connected', 'Questions', 'Uptime', 'Slow_queries')");
            $status = $stmt ? $stmt->fetchAll(\PDO::FETCH_KEY_PAIR) : [];

            $stmt2 = $pdo->query("SHOW VARIABLES LIKE 'max_connections'");
            $vars = $stmt2 ? $stmt2->fetchAll(\PDO::FETCH_KEY_PAIR) : [];

            $connected = (int)($status['Threads_connected'] ?? 0);
            $maxConn = max(1, (int)($vars['max_connections'] ?? 151));
            $questions = (int)($status['Questions'] ?? 0);
            $uptimeSec = max(1, (int)($status['Uptime'] ?? 1));
            $slow = (int)($status['Slow_queries'] ?? 0);

            $percent = round(($connected / $maxConn) * 100, 1);
            $qps = round($questions / $uptimeSec, 1);

            $uptimeDays = floor($uptimeSec / 86400);
            $uptimeHours = floor(($uptimeSec % 86400) / 3600);
            $uptimeStr = $uptimeDays > 0 ? "{$uptimeDays}d {$uptimeHours}h" : "{$uptimeHours}h";

            $res = [
                'active'          => true,
                'connected'       => $connected,
                'max_connections' => $maxConn,
                'percent'         => min(100.0, $percent),
                'qps'             => $qps,
                'slow_queries'    => $slow,
                'uptime'          => $uptimeStr,
            ];
        } catch (\Exception $e) {
            $res = [
                'active'          => false,
                'connected'       => 0,
                'max_connections' => 151,
                'percent'         => 0.0,
                'qps'             => 0.0,
                'slow_queries'    => 0,
                'uptime'          => 'Auth/Conn Error',
            ];
        }

        @file_put_contents($cacheFile, json_encode(array_merge($res, ['_cached_at' => $now])));
        return $res;
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
     * Dapatkan versi binary service dengan memory caching 60 detik
     */
    public static function getServiceVersion(string $service): ?string
    {
        if (isset(self::$versionCache[$service])) {
            return self::$versionCache[$service];
        }

        $res = null;
        switch ($service) {
            case 'nginx':
                $bin = file_exists('/usr/sbin/nginx') ? '/usr/sbin/nginx' : (file_exists('/usr/bin/nginx') ? '/usr/bin/nginx' : 'nginx');
                $out = @shell_exec("PATH=\$PATH:/usr/sbin:/sbin {$bin} -v 2>&1");
                if ($out && preg_match('/nginx\/([0-9\.]+)/i', $out, $m)) {
                    $res = $m[1];
                }
                break;

            case 'mysql':
            case 'mariadb':
                $bin = file_exists('/usr/bin/mariadb') ? '/usr/bin/mariadb' : (file_exists('/usr/bin/mysql') ? '/usr/bin/mysql' : 'mariadb');
                $out = @shell_exec("PATH=\$PATH:/usr/sbin:/sbin {$bin} -V 2>&1 || mysql -V 2>&1");
                if ($out) {
                    if (preg_match('/(?:Distrib|from)\s+([0-9\.]+(?:-[a-zA-Z0-9]+)?)/i', $out, $m)) {
                        $res = $m[1];
                    } elseif (preg_match('/([0-9]+\.[0-9]+\.[0-9]+(?:-[a-zA-Z0-9]+)?)/', $out, $m)) {
                        $res = $m[1];
                    }
                }
                break;

            case 'cloudflared':
                $bin = file_exists('/usr/local/bin/cloudflared') ? '/usr/local/bin/cloudflared' : (file_exists('/usr/bin/cloudflared') ? '/usr/bin/cloudflared' : 'cloudflared');
                $out = @shell_exec("PATH=\$PATH:/usr/local/bin {$bin} -v 2>&1");
                if ($out && preg_match('/version\s+([0-9\.]+)/i', $out, $m)) {
                    $res = $m[1];
                }
                break;

            default:
                if (preg_match('/^php([0-9\.]+)$/', $service, $m)) {
                    $ver = $m[1];
                    $out = @shell_exec("php{$ver} -v 2>&1");
                    if ($out && preg_match('/PHP\s+([0-9\.]+)/i', $out, $pm)) {
                        $res = $pm[1];
                    }
                }
                break;
        }

        self::$versionCache[$service] = $res;
        return $res;
    }
}
