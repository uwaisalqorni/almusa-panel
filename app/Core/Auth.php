<?php

namespace App\Core;

class Auth
{
    public static function startSession(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
                || (isset($_SERVER['SERVER_PORT']) && (int)$_SERVER['SERVER_PORT'] === 443)
                || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower($_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https')
                || (isset($_SERVER['HTTP_CF_VISITOR']) && str_contains($_SERVER['HTTP_CF_VISITOR'], 'https'));

            // Mask default PHP session cookie name to avoid scanner technology detection
            if (session_name() !== '__adm_sess') {
                session_name('__adm_sess');
            }

            session_set_cookie_params([
                'lifetime' => 86400 * 7, // 7 days
                'path'     => '/',
                'domain'   => '',
                'secure'   => $isHttps,
                'httponly' => true,
                'samesite' => 'Strict',
            ]);

            session_start();

            // Expire and flush any legacy PHPSESSID cookie to clear scanner warnings
            if (isset($_COOKIE['PHPSESSID'])) {
                setcookie('PHPSESSID', '', [
                    'expires'  => time() - 3600,
                    'path'     => '/',
                    'secure'   => $isHttps,
                    'httponly' => true,
                    'samesite' => 'Strict',
                ]);
                unset($_COOKIE['PHPSESSID']);
            }
        }
    }

    public static function check(): bool
    {
        self::startSession();
        return !empty($_SESSION['panel_user_id']);
    }

    public static function id(): ?int
    {
        self::startSession();
        return isset($_SESSION['panel_user_id']) ? (int)$_SESSION['panel_user_id'] : null;
    }

    public static function user(): ?array
    {
        $id = self::id();
        if (!$id) {
            return null;
        }

        try {
            $pdo = Database::getInstance();
            $stmt = $pdo->prepare("SELECT id, username, last_login_ip, last_login_at, created_at FROM users WHERE id = :id LIMIT 1");
            $stmt->execute([':id' => $id]);
            $user = $stmt->fetch();
            return $user ?: null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    public static function attempt(string $username, string $password): bool
    {
        self::startSession();

        $pdo = Database::getInstance();
        $stmt = $pdo->prepare("SELECT id, username, password_hash FROM users WHERE username = :u LIMIT 1");
        $stmt->execute([':u' => $username]);
        $user = $stmt->fetch();

        if ($user && password_verify($password, $user['password_hash'])) {
            session_regenerate_id(true);
            $_SESSION['panel_user_id'] = (int)$user['id'];
            $_SESSION['entrance_verified'] = true;

            $ip = $_SERVER['HTTP_CF_CONNECTING_IP']
                ?? $_SERVER['HTTP_X_FORWARDED_FOR']
                ?? $_SERVER['REMOTE_ADDR']
                ?? '127.0.0.1';

            if (strpos($ip, ',') !== false) {
                $parts = explode(',', $ip);
                $ip = trim($parts[0]);
            }

            $update = $pdo->prepare("UPDATE users SET last_login_ip = :ip, last_login_at = CURRENT_TIMESTAMP WHERE id = :id");
            $update->execute([':ip' => $ip, ':id' => $user['id']]);

            return true;
        }

        return false;
    }

    public static function logout(): void
    {
        self::startSession();
        $entranceVerified = !empty($_SESSION['entrance_verified']);

        $_SESSION = [];

        if (ini_get("session.use_cookies")) {
            $params = session_get_cookie_params();
            setcookie(
                session_name(),
                '',
                time() - 42000,
                $params["path"],
                $params["domain"],
                $params["secure"],
                $params["httponly"]
            );
        }

        session_destroy();

        // Start a fresh session to retain entrance verification if needed
        self::startSession();
        if ($entranceVerified) {
            $_SESSION['entrance_verified'] = true;
        }
    }

    public static function isEntranceVerified(): bool
    {
        self::startSession();
        return !empty($_SESSION['entrance_verified']);
    }

    public static function setEntranceVerified(bool $status = true): void
    {
        self::startSession();
        $_SESSION['entrance_verified'] = $status;
    }

    public static function token(): string
    {
        self::startSession();
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf_token'];
    }

    public static function verifyToken(?string $token): bool
    {
        self::startSession();
        if (empty($_SESSION['csrf_token']) || empty($token)) {
            return false;
        }
        return hash_equals($_SESSION['csrf_token'], $token);
    }

    public static function rotateToken(): string
    {
        self::startSession();
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        return $_SESSION['csrf_token'];
    }
}
