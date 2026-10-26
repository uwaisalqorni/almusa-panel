<?php

use App\Core\Router;
use App\Controllers\AuthController;
use App\Controllers\DashboardController;
use App\Controllers\SiteController;
use App\Controllers\DatabaseController;
use App\Controllers\PluginController;
use App\Controllers\TunnelController;
use App\Controllers\TaskController;
use App\Controllers\FileManagerController;

// Auth routes
Router::get('/login', [AuthController::class, 'showLogin']);
Router::post('/login', [AuthController::class, 'login']);
Router::get('/logout', [AuthController::class, 'logout']);
Router::post('/logout', [AuthController::class, 'logout']);

// Dashboard & Settings
Router::get('/', [DashboardController::class, 'index']);
Router::get('/dashboard', [DashboardController::class, 'index']);
Router::get('/settings', [DashboardController::class, 'settings']);
Router::post('/settings/save', [DashboardController::class, 'saveSettings']);
Router::post('/settings/change-password', [DashboardController::class, 'changePassword']);

// Sites / Virtual Hosts
Router::get('/sites', [SiteController::class, 'index']);
Router::post('/api/sites/create', [SiteController::class, 'create']);
Router::post('/api/sites/switch-php', [SiteController::class, 'switchPhp']);
Router::post('/api/sites/delete', [SiteController::class, 'delete']);

// File Manager & Config Editor
Router::get('/sites/files', [FileManagerController::class, 'index']);
Router::get('/api/files/list', [FileManagerController::class, 'listFiles']);
Router::get('/api/files/read', [FileManagerController::class, 'readFile']);
Router::post('/api/files/save', [FileManagerController::class, 'saveFile']);
Router::post('/api/files/create', [FileManagerController::class, 'createItem']);
Router::post('/api/files/delete', [FileManagerController::class, 'deleteItem']);
Router::post('/api/files/upload', [FileManagerController::class, 'uploadFile']);
Router::post('/api/files/extract', [FileManagerController::class, 'extractZip']);
Router::get('/api/files/download', [FileManagerController::class, 'downloadFile']);

// Databases
Router::get('/databases', [DatabaseController::class, 'index']);
Router::post('/api/databases/create', [DatabaseController::class, 'create']);
Router::post('/api/databases/change-password', [DatabaseController::class, 'changePassword']);
Router::post('/api/databases/delete', [DatabaseController::class, 'delete']);
Router::post('/api/databases/backup', [DatabaseController::class, 'backup']);
Router::post('/api/databases/sso-token', [DatabaseController::class, 'ssoToken']);

// Plugins & App Store
Router::get('/plugins', [PluginController::class, 'index']);
Router::post('/api/plugins/install', [PluginController::class, 'install']);
Router::post('/api/plugins/uninstall', [PluginController::class, 'uninstall']);
Router::post('/api/plugins/php/extension', [PluginController::class, 'manageExtension']);

// Cloudflare Tunnels
Router::get('/tunnels', [TunnelController::class, 'index']);
Router::post('/api/tunnels/quick-start', [TunnelController::class, 'quickStart']);
Router::post('/api/tunnels/quick-stop', [TunnelController::class, 'quickStop']);
Router::post('/api/tunnels/custom-publish', [TunnelController::class, 'customPublish']);

// Tasks & Real-time Logs API
Router::get('/api/system/stats', [DashboardController::class, 'stats']);
Router::get('/api/tasks/status', [TaskController::class, 'status']);
Router::get('/api/tasks/history', [TaskController::class, 'history']);
Router::post('/api/tasks/clear-log', [TaskController::class, 'clearLog']);
