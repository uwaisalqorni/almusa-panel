<?php
/**
 * Almusa Server Control Panel - Embedded Adminer & Single Sign-On Gateway
 * 
 * Protects Adminer from public exposure and enables instant 1-click SSO auto-login.
 */

define('PANEL_ROOT', dirname(__DIR__, 2));
require_once PANEL_ROOT . '/app/bootstrap.php';

use App\Core\Auth;
use App\Core\Database;

// 1. Enforce Almusa Panel Authentication
Auth::startSession();

if (!Auth::check()) {
    http_response_code(403);
    die('<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>403 - Akses Ditolak · Almusa Panel</title>
    <link rel="stylesheet" href="/assets/css/panel.css">
</head>
<body style="display:flex;align-items:center;justify-content:center;min-height:100vh;background:var(--bg);font-family:sans-serif;">
    <div class="card" style="max-width:440px;padding:36px;text-align:center;box-shadow:var(--shadow-pop);">
        <h2 style="margin-bottom:12px;">Akses Ditolak (403)</h2>
        <p style="color:var(--text-2);margin-bottom:24px;font-size:14px;line-height:1.6;">
            Akses ke Web GUI Database dibatasi hanya untuk administrator yang telah terotentikasi. Silakan login ke Almusa Panel terlebih dahulu.
        </p>
        <a href="/login" class="btn btn-primary" style="display:inline-flex;align-items:center;gap:8px;">
            Login ke Almusa Panel
        </a>
    </div>
</body>
</html>');
}

// 2. Process Ephemeral SSO Token
if (!empty($_GET['sso'])) {
    $token = trim((string)$_GET['sso']);
    $ssoData = $_SESSION['adminer_sso'][$token] ?? null;

    if ($ssoData && !empty($ssoData['expires']) && $ssoData['expires'] >= time()) {
        $server   = $ssoData['server'] ?: '127.0.0.1';
        $username = $ssoData['username'] ?: 'root';
        $password = $ssoData['password'] ?? '';
        $database = $ssoData['db'] ?? '';

        $_SESSION['adminer_active_auth'] = [
            'server'   => $server,
            'username' => $username,
            'password' => $password,
            'db'       => $database,
        ];

        // Pre-fill Adminer internal password map
        $_SESSION["pwds"]["server"][$server][$username] = $password;
        $_SESSION["pwds"]["server"]["127.0.0.1"][$username] = $password;
        $_SESSION["pwds"]["server"]["localhost"][$username] = $password;

        // Invalidate single-use token
        unset($_SESSION['adminer_sso'][$token]);

        $redirectUrl = '/adminer/?server=' . urlencode($server) . '&username=' . urlencode($username);
        if (!empty($database)) {
            $redirectUrl .= '&db=' . urlencode($database);
        }

        header("Location: {$redirectUrl}");
        exit;
    }
}

// 3. Fallback Auto-Authentication for Panel Admins
if (empty($_SESSION['adminer_active_auth'])) {
    $rootPass = Database::getSetting('mysql_root_pass', '');
    $_SESSION['adminer_active_auth'] = [
        'server'   => '127.0.0.1',
        'username' => 'root',
        'password' => $rootPass,
        'db'       => '',
    ];
}

$activeAuth = $_SESSION['adminer_active_auth'] ?? [];
$srv  = $activeAuth['server'] ?? '127.0.0.1';
$user = $activeAuth['username'] ?? 'root';
$pass = $activeAuth['password'] ?? '';
$db   = $activeAuth['db'] ?? '';

// Populate Adminer internal session cache
$_SESSION["pwds"]["server"][$srv][$user] = $pass;
$_SESSION["pwds"]["server"]["127.0.0.1"][$user] = $pass;
$_SESSION["pwds"]["server"]["localhost"][$user] = $pass;

// If visiting /adminer/ without query params, redirect to active credentials
if (empty($_GET['username']) && empty($_POST)) {
    $targetUrl = '/adminer/?server=' . urlencode($srv) . '&username=' . urlencode($user);
    if (!empty($db)) {
        $targetUrl .= '&db=' . urlencode($db);
    }
    header("Location: {$targetUrl}");
    exit;
}

// 4. Custom Adminer Extension Class
function adminer_object()
{
    class AlmusaAdminerPlugin extends Adminer
    {
        public function name(): string
        {
            return '<div style="display:flex;align-items:center;justify-content:space-between;gap:8px;">' .
                   '<a href="/databases" style="font-weight:700;color:var(--text);display:flex;align-items:center;gap:6px;text-decoration:none;">' .
                   '← Almusa Panel' .
                   '</a>' .
                   '</div>';
        }

        public function credentials(): array
        {
            $auth = $_SESSION['adminer_active_auth'] ?? [];
            $server = $auth['server'] ?: '127.0.0.1';
            $user   = $auth['username'] ?: 'root';
            $pass   = $auth['password'] ?? '';
            return [$server, $user, $pass];
        }

        public function database(): string
        {
            return $_GET['db'] ?? ($_SESSION['adminer_active_auth']['db'] ?? '');
        }

        public function login($login, $password): bool
        {
            // Authenticated via Almusa Panel session
            return true;
        }

        public function head(): bool
        {
            echo '<link rel="preconnect" href="https://fonts.googleapis.com">' .
                 '<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>' .
                 '<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">' .
                 '<link rel="stylesheet" href="adminer.css">';
            return true;
        }
    }

    return new AlmusaAdminerPlugin();
}

// 5. Load Adminer Engine
require_once __DIR__ . '/adminer-core.php';
