<?php

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Database;
use App\Core\Response;
use App\Core\SystemMonitor;
use PDO;
use PDOException;

class DatabaseController
{
    private function getMysqlConnection(string $host = '127.0.0.1', int $port = 3306): PDO
    {
        $rootPass = Database::getSetting('mysql_root_pass', '');
        $dsn = "mysql:host={$host};port={$port};charset=utf8mb4";
        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ];

        try {
            return new PDO($dsn, 'root', $rootPass, $options);
        } catch (PDOException $e) {
            // Try socket or root without pass if local
            try {
                return new PDO("mysql:unix_socket=/var/run/mysqld/mysqld.sock;charset=utf8mb4", 'root', '', $options);
            } catch (PDOException $e2) {
                throw new \Exception("Gagal terhubung ke MySQL/MariaDB sebagai root: " . $e->getMessage() . " Silakan atur password root di menu Pengaturan Panel.");
            }
        }
    }

    public function index(): void
    {
        $pdo = Database::getInstance();
        $databases = $pdo->query("SELECT * FROM databases ORDER BY id DESC")->fetchAll();

        $isServiceRunning = SystemMonitor::isServiceActive('mysql') || SystemMonitor::isServiceActive('mariadb');

        Response::view('databases/index', [
            'title'            => 'Database Manager',
            'user'             => Auth::user(),
            'databases'        => $databases,
            'isServiceRunning' => $isServiceRunning,
        ]);
    }

    public function create(): void
    {
        $dbName = trim($_POST['db_name'] ?? '');
        $dbUser = trim($_POST['db_user'] ?? '');
        $dbPass = (string)($_POST['db_password'] ?? '');
        $charset = trim($_POST['charset'] ?? 'utf8mb4');
        $accessHost = trim($_POST['access_host'] ?? '127.0.0.1');

        // Regex whitelist validation
        if (!preg_match('/^[a-zA-Z0-9_]{1,64}$/', $dbName)) {
            Response::json(['success' => false, 'error' => 'Nama database harus 1-64 karakter alfanumerik & underscore.'], 400);
        }

        if (empty($dbUser)) {
            $dbUser = $dbName;
        }

        if (!preg_match('/^[a-zA-Z0-9_]{1,64}$/', $dbUser)) {
            Response::json(['success' => false, 'error' => 'Username database harus 1-64 karakter alfanumerik & underscore.'], 400);
        }

        if (!preg_match('/^[a-zA-Z0-9_.%-]{1,64}$/', $accessHost)) {
            $accessHost = '127.0.0.1';
        }

        if (strlen($dbPass) < 6) {
            Response::json(['success' => false, 'error' => 'Password minimal 6 karakter.'], 400);
        }

        if (!in_array($charset, ['utf8mb4', 'utf8', 'latin1'])) {
            $charset = 'utf8mb4';
        }

        $collate = ($charset === 'utf8mb4') ? 'utf8mb4_unicode_ci' : ($charset . '_general_ci');

        // Check duplicate in SQLite
        $pdoSqlite = Database::getInstance();
        $check = $pdoSqlite->prepare("SELECT COUNT(*) FROM databases WHERE db_name = :name");
        $check->execute([':name' => $dbName]);
        if ((int)$check->fetchColumn() > 0) {
            Response::json(['success' => false, 'error' => "Database '{$dbName}' sudah tercatat di panel."], 400);
        }

        try {
            $mysql = $this->getMysqlConnection('127.0.0.1', 3306);

            // 1. Create database
            $mysql->exec("CREATE DATABASE IF NOT EXISTS `{$dbName}` CHARACTER SET {$charset} COLLATE {$collate};");

            // 2. Create user (DDL does not allow prepared placeholders, quote password safely)
            $quotedPass = $mysql->quote($dbPass);
            $mysql->exec("CREATE USER IF NOT EXISTS '{$dbUser}'@'{$accessHost}' IDENTIFIED BY {$quotedPass};");

            // 3. Grant privileges
            $mysql->exec("GRANT ALL PRIVILEGES ON `{$dbName}`.* TO '{$dbUser}'@'{$accessHost}';");
            $mysql->exec("FLUSH PRIVILEGES;");

            // 4. Save metadata into panel SQLite
            $insert = $pdoSqlite->prepare("
                INSERT INTO databases (db_name, db_user, db_password_enc, db_engine, db_port, charset, access_host)
                VALUES (:name, :user, :pass, 'mysql', 3306, :charset, :host)
            ");
            $insert->execute([
                ':name'    => $dbName,
                ':user'    => $dbUser,
                ':pass'    => base64_encode($dbPass), // obfuscated for admin view
                ':charset' => $charset,
                ':host'    => $accessHost,
            ]);

            Response::json([
                'success' => true,
                'message' => "Database `{$dbName}` dan user `{$dbUser}` berhasil dibuat dengan charset UTF8MB4!",
            ]);
        } catch (\Throwable $e) {
            Response::json(['success' => false, 'error' => $e->getMessage()], 500);
        }
    }

    public function changePassword(): void
    {
        $id = (int)($_POST['id'] ?? 0);
        $newPass = (string)($_POST['new_password'] ?? '');

        if (strlen($newPass) < 6) {
            Response::json(['success' => false, 'error' => 'Password baru minimal 6 karakter.'], 400);
        }

        $pdoSqlite = Database::getInstance();
        $stmt = $pdoSqlite->prepare("SELECT * FROM databases WHERE id = :id LIMIT 1");
        $stmt->execute([':id' => $id]);
        $db = $stmt->fetch();

        if (!$db) {
            Response::json(['success' => false, 'error' => 'Database tidak ditemukan.'], 404);
        }

        $user = $db['db_user'];
        $host = $db['access_host'];

        try {
            $mysql = $this->getMysqlConnection('127.0.0.1', (int)$db['db_port']);

            if (!preg_match('/^[a-zA-Z0-9_]{1,64}$/', $user) || !preg_match('/^[a-zA-Z0-9_.%-]{1,64}$/', $host)) {
                Response::json(['success' => false, 'error' => 'Format username atau host database tidak valid.'], 400);
            }

            $quotedPass = $mysql->quote($newPass);
            $mysql->exec("ALTER USER '{$user}'@'{$host}' IDENTIFIED BY {$quotedPass};");
            $mysql->exec("FLUSH PRIVILEGES;");

            $update = $pdoSqlite->prepare("UPDATE databases SET db_password_enc = :pass WHERE id = :id");
            $update->execute([
                ':pass' => base64_encode($newPass),
                ':id'   => $id,
            ]);

            Response::json(['success' => true, 'message' => "Password user `{$user}` berhasil diperbarui!"]);
        } catch (\Throwable $e) {
            Response::json(['success' => false, 'error' => $e->getMessage()], 500);
        }
    }

    public function delete(): void
    {
        $id = (int)($_POST['id'] ?? 0);

        $pdoSqlite = Database::getInstance();
        $stmt = $pdoSqlite->prepare("SELECT * FROM databases WHERE id = :id LIMIT 1");
        $stmt->execute([':id' => $id]);
        $db = $stmt->fetch();

        if (!$db) {
            Response::json(['success' => false, 'error' => 'Database tidak ditemukan.'], 404);
        }

        $dbName = $db['db_name'];
        $dbUser = $db['db_user'];
        $host = $db['access_host'];

        try {
            $mysql = $this->getMysqlConnection('127.0.0.1', (int)$db['db_port']);

            if (preg_match('/^[a-zA-Z0-9_]{1,64}$/', $dbName)) {
                $mysql->exec("DROP DATABASE IF EXISTS `{$dbName}`;");
            }
            if (preg_match('/^[a-zA-Z0-9_]{1,64}$/', $dbUser) && preg_match('/^[a-zA-Z0-9_.%-]{1,64}$/', $host)) {
                $mysql->exec("DROP USER IF EXISTS '{$dbUser}'@'{$host}';");
            }

            $mysql->exec("FLUSH PRIVILEGES;");

            $del = $pdoSqlite->prepare("DELETE FROM databases WHERE id = :id");
            $del->execute([':id' => $id]);

            Response::json(['success' => true, 'message' => "Database `{$dbName}` dan user `{$dbUser}` berhasil dihapus."]);
        } catch (\Throwable $e) {
            // Even if MySQL error, allow removing from panel
            $del = $pdoSqlite->prepare("DELETE FROM databases WHERE id = :id");
            $del->execute([':id' => $id]);
            Response::json(['success' => true, 'message' => "Catatan database dihapus dari panel."]);
        }
    }

    public function backup(): void
    {
        $id = (int)($_POST['id'] ?? 0);

        $pdoSqlite = Database::getInstance();
        $stmt = $pdoSqlite->prepare("SELECT * FROM databases WHERE id = :id LIMIT 1");
        $stmt->execute([':id' => $id]);
        $db = $stmt->fetch();

        if (!$db) {
            Response::json(['success' => false, 'error' => 'Database tidak ditemukan.'], 404);
        }

        $dbName = $db['db_name'];

        // Enqueue backup task
        $task = $pdoSqlite->prepare("
            INSERT INTO tasks (title, action, payload, status)
            VALUES (:title, 'plugin_install', :payload, 'pending')
        ");
        $task->execute([
            ':title'   => "Backup Database: {$dbName}",
            ':payload' => json_encode([
                'plugin'  => 'mysql',
                'version' => "backup {$dbName}",
            ]),
        ]);

        Response::json([
            'success' => true,
            'message' => "Backup database `{$dbName}` sedang diproses. Cek di terminal untuk progress.",
        ]);
    }
}
