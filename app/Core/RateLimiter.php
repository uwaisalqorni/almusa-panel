<?php

namespace App\Core;

class RateLimiter
{
    public const MAX_ATTEMPTS = 5;
    public const LOCKOUT_SECONDS = 900; // 15 menit
    public const WINDOW_SECONDS = 900;  // 15 menit

    /**
     * Dapatkan IP asli pengunjung (mendukung Cloudflare CF-Connecting-IP & X-Forwarded-For)
     */
    public static function clientIp(): string
    {
        $ip = $_SERVER['HTTP_CF_CONNECTING_IP']
            ?? $_SERVER['HTTP_X_FORWARDED_FOR']
            ?? $_SERVER['REMOTE_ADDR']
            ?? '127.0.0.1';

        if (str_contains($ip, ',')) {
            $parts = explode(',', $ip);
            $ip = trim($parts[0]);
        }

        return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : '127.0.0.1';
    }

    /**
     * Cek apakah IP saat ini sedang terkunci
     * 
     * @return array{locked: bool, remaining_seconds: int, attempts: int}
     */
    public static function check(?string $ip = null): array
    {
        $ip = $ip ?? self::clientIp();
        $pdo = Database::getInstance();

        $stmt = $pdo->prepare("SELECT attempts, last_attempt_at, locked_until FROM login_attempts WHERE ip_address = :ip LIMIT 1");
        $stmt->execute([':ip' => $ip]);
        $record = $stmt->fetch();

        if (!$record) {
            return ['locked' => false, 'remaining_seconds' => 0, 'attempts' => 0];
        }

        $now = time();

        // 1. Cek apakah ada status locked_until yang masih aktif
        if (!empty($record['locked_until'])) {
            $lockedUntilTs = strtotime((string)$record['locked_until']);
            if ($lockedUntilTs > $now) {
                return [
                    'locked' => true,
                    'remaining_seconds' => $lockedUntilTs - $now,
                    'attempts' => (int)$record['attempts'],
                ];
            } else {
                // Masa lockout telah kedaluwarsa, bersihkan catatan
                self::clear($ip);
                return ['locked' => false, 'remaining_seconds' => 0, 'attempts' => 0];
            }
        }

        // 2. Cek apakah attempt terakhir sudah lewat dari jendela 15 menit
        $lastAttemptTs = strtotime((string)$record['last_attempt_at']);
        if (($now - $lastAttemptTs) > self::WINDOW_SECONDS) {
            self::clear($ip);
            return ['locked' => false, 'remaining_seconds' => 0, 'attempts' => 0];
        }

        return [
            'locked' => false,
            'remaining_seconds' => 0,
            'attempts' => (int)$record['attempts'],
        ];
    }

    /**
     * Catat percobaan login yang gagal
     * 
     * @return array{locked: bool, attempts: int, remaining_attempts: int, locked_minutes: int}
     */
    public static function recordFailedAttempt(string $username, ?string $ip = null): array
    {
        $ip = $ip ?? self::clientIp();
        $pdo = Database::getInstance();
        $now = time();

        $stmt = $pdo->prepare("SELECT attempts, last_attempt_at FROM login_attempts WHERE ip_address = :ip LIMIT 1");
        $stmt->execute([':ip' => $ip]);
        $record = $stmt->fetch();

        $attempts = 1;
        if ($record) {
            $lastAttemptTs = strtotime((string)$record['last_attempt_at']);
            if (($now - $lastAttemptTs) <= self::WINDOW_SECONDS) {
                $attempts = (int)$record['attempts'] + 1;
            }
        }

        $isLocked = ($attempts >= self::MAX_ATTEMPTS);
        $lockedUntil = $isLocked ? date('Y-m-d H:i:s', $now + self::LOCKOUT_SECONDS) : null;
        $nowFormatted = date('Y-m-d H:i:s', $now);

        $upsert = $pdo->prepare("
            INSERT INTO login_attempts (ip_address, attempts, last_attempt_at, locked_until)
            VALUES (:ip, :attempts, :now, :locked_until)
            ON CONFLICT(ip_address) DO UPDATE SET
                attempts = :attempts,
                last_attempt_at = :now,
                locked_until = :locked_until
        ");
        $upsert->execute([
            ':ip' => $ip,
            ':attempts' => $attempts,
            ':now' => $nowFormatted,
            ':locked_until' => $lockedUntil,
        ]);

        if ($isLocked) {
            self::logSecurity("ALERT", "IP {$ip} terkunci selama 15 menit setelah {$attempts}x gagal login berturut-turut (username target: '{$username}')");
            try {
                if (class_exists('\\App\\Services\\NotificationService')) {
                    \App\Services\NotificationService::sendBruteForceAlert($ip, $username, $attempts);
                }
            } catch (\Throwable $e) {
                // Non-blocking notification
            }
        } else {
            $remaining = max(0, self::MAX_ATTEMPTS - $attempts);
            self::logSecurity("WARN", "Gagal login ({$attempts}/" . self::MAX_ATTEMPTS . ") dari IP {$ip} (username target: '{$username}'). Sisa percobaan: {$remaining}");
        }

        return [
            'locked' => $isLocked,
            'attempts' => $attempts,
            'remaining_attempts' => max(0, self::MAX_ATTEMPTS - $attempts),
            'locked_minutes' => (int)ceil(self::LOCKOUT_SECONDS / 60),
        ];
    }

    /**
     * Catat login sukses dan bersihkan catatan percobaan gagal untuk IP ini
     */
    public static function recordSuccess(string $username, ?string $ip = null): void
    {
        $ip = $ip ?? self::clientIp();
        self::clear($ip);
        self::logSecurity("INFO", "Login berhasil untuk user '{$username}' dari IP {$ip}");
    }

    /**
     * Hapus catatan percobaan untuk IP tertentu
     */
    public static function clear(?string $ip = null): void
    {
        $ip = $ip ?? self::clientIp();
        try {
            $pdo = Database::getInstance();
            $stmt = $pdo->prepare("DELETE FROM login_attempts WHERE ip_address = :ip");
            $stmt->execute([':ip' => $ip]);
        } catch (\Throwable $e) {
            // Ignore error
        }
    }

    /**
     * Tulis catatan ke data/panel_security.log
     */
    public static function logSecurity(string $level, string $message): void
    {
        $root = defined('PANEL_ROOT') ? PANEL_ROOT : dirname(__DIR__, 2);
        $file = $root . '/data/panel_security.log';
        $timestamp = date('Y-m-d H:i:s');
        $line = "[{$timestamp}] [{$level}] {$message}\n";
        @file_put_contents($file, $line, FILE_APPEND | LOCK_EX);
    }
}
