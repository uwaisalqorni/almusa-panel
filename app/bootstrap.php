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
    }
});

// Initialize and auto-migrate database
try {
    \App\Core\Database::init();
    \App\Core\Database::autoMigrate();
} catch (\Throwable $e) {
    error_log("Failed to bootstrap Almusa Panel database: " . $e->getMessage());
}
