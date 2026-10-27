<?php

if (!defined('PANEL_ROOT')) {
    define('PANEL_ROOT', dirname(__DIR__));
}

// Set default timezone and error reporting
date_default_timezone_set('Asia/Jakarta');
error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');
ini_set('error_log', PANEL_ROOT . '/data/panel_error.log');
ini_set('expose_php', '0');

// Mask PHP session fingerprint and enforce Strict SameSite
ini_set('session.name', '__adm_sess');
ini_set('session.cookie_httponly', '1');
ini_set('session.cookie_samesite', 'Strict');
ini_set('session.use_strict_mode', '1');

// View helpers (e(), ui_icon(), js_arg())
require_once __DIR__ . '/helpers.php';

// PSR-4 Autoloader for App namespace
spl_autoload_register(function ($class) {
    $prefix = 'App\\';
    $baseDir = PANEL_ROOT . '/app/';

    $len = strlen($prefix);
    if (strncmp($prefix, $class, $len) !== 0) {
        return;
    }

    $relativeClass = substr($class, $len);
    $file = $baseDir . str_replace('\\', '/', $relativeClass) . '.php';

    if (file_exists($file)) {
        require_once $file;
        return;
    }

    // Support App\Plugins\<Plugin>\<Class> located in plugins/<plugin>/<Class>.php
    if (str_starts_with($relativeClass, 'Plugins\\')) {
        $parts = explode('\\', $relativeClass);
        if (count($parts) >= 3) {
            $pluginDir = strtolower($parts[1]);
            $subPath = implode('/', array_slice($parts, 2));
            $pluginFile = PANEL_ROOT . '/plugins/' . $pluginDir . '/' . $subPath . '.php';
            if (file_exists($pluginFile)) {
                require_once $pluginFile;
                return;
            }
        }
    }
});

// Initialize and auto-migrate database
try {
    \App\Core\Database::init();
    \App\Core\Database::autoMigrate();
} catch (\Throwable $e) {
    error_log("Failed to bootstrap Almusa Panel database: " . $e->getMessage());
}
