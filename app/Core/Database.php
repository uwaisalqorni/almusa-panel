<?php

namespace App\Core;

use PDO;
use PDOException;

class Database
{
    private static ?PDO $instance = null;
    private static string $dbFile = '';

    public static function init(?string $customPath = null): void
    {
        if ($customPath !== null) {
            self::$dbFile = $customPath;
        } else {
            $root = defined('PANEL_ROOT') ? PANEL_ROOT : dirname(__DIR__, 2);
            self::$dbFile = $root . '/data/panel.db';
        }
    }

    public static function getInstance(): PDO
    {
        if (self::$instance === null) {
            if (empty(self::$dbFile)) {
                self::init();
            }

            $dir = dirname(self::$dbFile);
            if (!is_dir($dir)) {
                mkdir($dir, 0750, true);
            }

            $dsn = 'sqlite:' . self::$dbFile;
            $options = [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ];

            try {
                self::$instance = new PDO($dsn, null, null, $options);
                self::$instance->exec('PRAGMA journal_mode = WAL;');
                self::$instance->exec('PRAGMA foreign_keys = ON;');
                self::$instance->exec('PRAGMA busy_timeout = 5000;');

                if (file_exists(self::$dbFile)) {
                    @chmod(self::$dbFile, 0600);
                }
            } catch (PDOException $e) {
                error_log("Database connection error: " . $e->getMessage());
                throw $e;
            }
        }

        return self::$instance;
    }

    public static function autoMigrate(): void
    {
        $pdo = self::getInstance();

        $schema = <<<SQL
        -- 1. Panel Users
        CREATE TABLE IF NOT EXISTS users (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            username TEXT NOT NULL UNIQUE,
            password_hash TEXT NOT NULL,
            last_login_ip TEXT,
            last_login_at DATETIME,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        );

        -- 2. Panel Settings
        CREATE TABLE IF NOT EXISTS settings (
            key_name TEXT PRIMARY KEY,
            key_value TEXT NOT NULL,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
        );

        -- 3. Hosted Websites
        CREATE TABLE IF NOT EXISTS sites (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            domain TEXT NOT NULL UNIQUE,
            aliases TEXT DEFAULT '',
            docroot TEXT NOT NULL,
            php_version TEXT NOT NULL DEFAULT '8.3',
            rewrite_template TEXT DEFAULT 'default',
            ssl_enabled INTEGER DEFAULT 0,
            tunnel_mode TEXT DEFAULT 'none',
            tunnel_url TEXT DEFAULT '',
            status TEXT DEFAULT 'active',
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        );

        -- 4. Managed Databases
        CREATE TABLE IF NOT EXISTS databases (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            db_name TEXT NOT NULL UNIQUE,
            db_user TEXT NOT NULL,
            db_password_enc TEXT NOT NULL,
            db_engine TEXT NOT NULL DEFAULT 'mysql',
            db_port INTEGER NOT NULL DEFAULT 3306,
            charset TEXT NOT NULL DEFAULT 'utf8mb4',
            access_host TEXT NOT NULL DEFAULT '127.0.0.1',
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        );

        -- 5. Background Task Queue
        CREATE TABLE IF NOT EXISTS tasks (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            title TEXT NOT NULL,
            action TEXT NOT NULL,
            payload TEXT NOT NULL,
            status TEXT NOT NULL DEFAULT 'pending',
            log_file TEXT DEFAULT 'data/panelExec.log',
            exit_code INTEGER,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            started_at DATETIME,
            finished_at DATETIME
        );

        -- 6. Active Cloudflare Quick Tunnels
        CREATE TABLE IF NOT EXISTS tunnels (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            site_id INTEGER NOT NULL,
            tunnel_type TEXT NOT NULL,
            public_hostname TEXT NOT NULL,
            local_service TEXT NOT NULL DEFAULT 'http://127.0.0.1:80',
            pid INTEGER DEFAULT NULL,
            status TEXT DEFAULT 'running',
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (site_id) REFERENCES sites(id) ON DELETE CASCADE
        );
SQL;

        $pdo->exec($schema);

        // Seed default admin and settings if not present
        $stmt = $pdo->query("SELECT COUNT(*) AS total FROM users");
        $count = (int)$stmt->fetchColumn();

        $root = defined('PANEL_ROOT') ? PANEL_ROOT : dirname(__DIR__, 2);
        $entranceFile = $root . '/data/entrance.txt';
        $credsFile = $root . '/data/initial_credentials.txt';

        if ($count === 0) {
            // Generate random secure password and entrance path
            $chars = 'abcdefghjkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789!@#$%';
            $defaultPassword = '';
            for ($i = 0; $i < 12; $i++) {
                $defaultPassword .= $chars[random_int(0, strlen($chars) - 1)];
            }

            $entrancePath = substr(bin2hex(random_bytes(6)), 0, 8);
            $hash = password_hash($defaultPassword, PASSWORD_BCRYPT, ['cost' => 12]);

            $insert = $pdo->prepare("INSERT INTO users (username, password_hash) VALUES (:user, :pass)");
            $insert->execute([
                ':user' => 'admin',
                ':pass' => $hash,
            ]);

            self::setSetting('entrance_path', $entrancePath);
            self::setSetting('panel_port', '64448');
            self::setSetting('panel_title', 'Almusa Control Panel');

            file_put_contents($entranceFile, $entrancePath);
            @chmod($entranceFile, 0600);

            $info = "======================================================\n";
            $info .= "        ALMUSA SERVER CONTROL PANEL CREDENTIALS        \n";
            $info .= "======================================================\n";
            $info .= "Default Entrance Path: /" . $entrancePath . "\n";
            $info .= "Default Username     : admin\n";
            $info .= "Default Password     : " . $defaultPassword . "\n";
            $info .= "Default Port         : 64448\n";
            $info .= "Login URL Format     : http://<SERVER_IP>:64448/" . $entrancePath . "\n";
            $info .= "======================================================\n";
            file_put_contents($credsFile, $info);
            @chmod($credsFile, 0600);
        } else {
            // Ensure entrance file exists and matches DB
            $dbEntrance = self::getSetting('entrance_path');
            if ($dbEntrance && !file_exists($entranceFile)) {
                file_put_contents($entranceFile, $dbEntrance);
                @chmod($entranceFile, 0600);
            }
        }
    }

    public static function getSetting(string $key, ?string $default = null): ?string
    {
        $pdo = self::getInstance();
        $stmt = $pdo->prepare("SELECT key_value FROM settings WHERE key_name = :key LIMIT 1");
        $stmt->execute([':key' => $key]);
        $row = $stmt->fetch();
        return $row ? (string)$row['key_value'] : $default;
    }

    public static function setSetting(string $key, string $value): void
    {
        $pdo = self::getInstance();
        $stmt = $pdo->prepare("
            INSERT INTO settings (key_name, key_value, updated_at)
            VALUES (:key, :val, CURRENT_TIMESTAMP)
            ON CONFLICT(key_name) DO UPDATE SET key_value = excluded.key_value, updated_at = CURRENT_TIMESTAMP
        ");
        $stmt->execute([':key' => $key, ':val' => $value]);
    }
}
