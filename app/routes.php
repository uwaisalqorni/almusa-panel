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
use App\Controllers\NginxController;
use App\Controllers\CronController;
use App\Controllers\BackupController;

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
Router::post('/api/sites/issue-ssl', [SiteController::class, 'issueSsl']);
Router::post('/api/sites/disable-ssl', [SiteController::class, 'disableSsl']);
Router::post('/api/sites/renew-ssl', [SiteController::class, 'renewSsl']);
Router::get('/api/sites/ssl-status', [SiteController::class, 'sslStatus']);
Router::get('/api/sites/logs', [SiteController::class, 'getLogs']);
Router::post('/api/sites/clear-log', [SiteController::class, 'clearLog']);

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

// Nginx Global Manager
Router::get('/api/nginx/config', [NginxController::class, 'getConfig']);
Router::post('/api/nginx/save', [NginxController::class, 'saveConfig']);
Router::post('/api/nginx/test', [NginxController::class, 'testConfig']);
Router::post('/api/nginx/service', [NginxController::class, 'serviceControl']);

// Cloudflare Tunnels
Router::get('/tunnels', [TunnelController::class, 'index']);
Router::post('/api/tunnels/quick-start', [TunnelController::class, 'quickStart']);
Router::post('/api/tunnels/quick-stop', [TunnelController::class, 'quickStop']);
Router::post('/api/tunnels/custom-publish', [TunnelController::class, 'customPublish']);

// Cron Jobs Manager
Router::get('/cron', [CronController::class, 'index']);
Router::post('/api/cron/create', [CronController::class, 'create']);
Router::post('/api/cron/update', [CronController::class, 'update']);
Router::post('/api/cron/toggle', [CronController::class, 'toggle']);
Router::post('/api/cron/delete', [CronController::class, 'delete']);
Router::post('/api/cron/run-now', [CronController::class, 'runNow']);
Router::get('/api/cron/logs', [CronController::class, 'getLogs']);
Router::post('/api/cron/clear-logs', [CronController::class, 'clearLogs']);

// Backups & Snapshots Engine
Router::get('/backups', [BackupController::class, 'index']);
Router::post('/api/backups/create', [BackupController::class, 'create']);
Router::post('/api/backups/delete', [BackupController::class, 'delete']);
Router::get('/api/backups/download', [BackupController::class, 'download']);
Router::post('/api/backups/sync-cloud', [BackupController::class, 'syncCloud']);
Router::post('/api/backups/settings/cloud', [BackupController::class, 'saveCloudSettings']);
Router::post('/api/backups/test-cloud', [BackupController::class, 'testCloud']);
Router::post('/api/backups/settings/schedule', [BackupController::class, 'saveSchedule']);

// Tasks & Real-time Logs API
Router::get('/api/system/stats', [DashboardController::class, 'stats']);
Router::get('/api/tasks/status', [TaskController::class, 'status']);
Router::get('/api/tasks/history', [TaskController::class, 'history']);
Router::post('/api/tasks/clear-log', [TaskController::class, 'clearLog']);
