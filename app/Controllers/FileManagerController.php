<?php

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Database;
use App\Core\Response;

class FileManagerController
{
    /**
     * Resolves the site record and its base file management directory.
     * If no sites are registered or siteId === 0, defaults to /www/wwwroot.
     *
     * @return array{0: array, 1: string, 2: array} [site, baseDir, allSites]
     */
    private function getSiteContext(?int $siteId = null): array
    {
        $pdo = Database::getInstance();
        $allSites = $pdo->query("SELECT id, domain, docroot, php_version, rewrite_template, status FROM sites ORDER BY id DESC")->fetchAll();

        // Default storage fallback (/www/wwwroot)
        $defaultStorage = [
            'id'               => 0,
            'domain'           => 'Folder Utama (/www/wwwroot)',
            'docroot'          => '/www/wwwroot',
            'php_version'      => '8.3',
            'rewrite_template' => 'default',
            'status'           => 'active',
            'is_root_storage'  => true,
        ];

        if (!is_dir('/www/wwwroot')) {
            @mkdir('/www/wwwroot', 0775, true);
        }
        if (!is_writable('/www/wwwroot')) {
            @chmod('/www/wwwroot', 0775);
            $script = defined('PANEL_ROOT') ? PANEL_ROOT . '/scripts/vhost_manager.sh' : dirname(__DIR__, 2) . '/scripts/vhost_manager.sh';
            if (is_file($script)) {
                @exec("sudo " . escapeshellarg($script) . " fix_permissions wwwroot /www/wwwroot 2>&1");
            }
        }

        if (empty($allSites)) {
            $allSites = [$defaultStorage];
            $site = $defaultStorage;
            $baseDir = '/www/wwwroot';
        } else {
            $site = null;
            if ($siteId === 0) {
                $site = $defaultStorage;
                $baseDir = '/www/wwwroot';
            } elseif ($siteId !== null && $siteId > 0) {
                foreach ($allSites as $s) {
                    if ((int)$s['id'] === $siteId) {
                        $site = $s;
                        break;
                    }
                }
            }

            if (!$site) {
                $site = $allSites[0];
            }

            if (!empty($site['is_root_storage'])) {
                $baseDir = '/www/wwwroot';
            } else {
                $docroot = $site['docroot'] ?: ("/www/wwwroot/" . $site['domain']);
                $baseDir = $docroot;
                if (preg_match('#/public/?$#i', $docroot)) {
                    $parent = dirname($docroot);
                    if (is_dir($parent)) {
                        $baseDir = $parent;
                    }
                }
            }
        }

        // Safety: Ensure baseDir is within allowed wwwroot path or site folder
        $realBase = realpath($baseDir);
        if (!$realBase) {
            @mkdir($baseDir, 0755, true);
            $realBase = realpath($baseDir) ?: $baseDir;
        }

        return [$site, rtrim($realBase, '/'), $allSites];
    }

    /**
     * Strictly verifies and resolves a relative subpath within the site's base directory.
     * Prevents directory traversal attacks.
     */
    private function resolveSafePath(string $baseDir, string $subPath): ?string
    {
        $subPath = str_replace(['\\', "\0"], ['/', ''], $subPath);
        $parts = array_filter(explode('/', $subPath), function ($p) {
            return $p !== '' && $p !== '.';
        });

        $resolved = [];
        foreach ($parts as $part) {
            if ($part === '..') {
                if (empty($resolved)) {
                    // Attempting to traverse above root
                    return null;
                }
                array_pop($resolved);
            } else {
                $resolved[] = $part;
            }
        }

        $cleanRel = implode('/', $resolved);
        $fullPath = empty($cleanRel) ? $baseDir : ($baseDir . '/' . $cleanRel);

        // If file exists, ensure realpath starts with baseDir
        if (file_exists($fullPath)) {
            $real = realpath($fullPath);
            if ($real === false || !str_starts_with($real, $baseDir)) {
                return null;
            }
            return $real;
        }

        // For files that are about to be created
        $parent = dirname($fullPath);
        if (file_exists($parent)) {
            $realParent = realpath($parent);
            if ($realParent === false || !str_starts_with($realParent, $baseDir)) {
                return null;
            }
        }

        return $fullPath;
    }

    /**
     * Formats bytes into human readable string.
     */
    private function formatSize(int $bytes): string
    {
        if ($bytes >= 1073741824) {
            return number_format($bytes / 1073741824, 2) . ' GB';
        }
        if ($bytes >= 1048576) {
            return number_format($bytes / 1048576, 2) . ' MB';
        }
        if ($bytes >= 1024) {
            return number_format($bytes / 1024, 2) . ' KB';
        }
        return $bytes . ' B';
    }

    /**
     * Detects code syntax mode from filename extension.
     */
    private function detectSyntax(string $filename): string
    {
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        $basename = strtolower(basename($filename));

        if ($basename === '.env' || str_starts_with($basename, '.env.')) return 'env';
        if ($basename === '.htaccess') return 'apache';
        if ($basename === 'nginx.conf' || $ext === 'conf') return 'nginx';
        if (in_array($ext, ['php', 'phtml'])) return 'php';
        if (in_array($ext, ['js', 'mjs', 'cjs'])) return 'javascript';
        if (in_array($ext, ['json', 'lock'])) return 'json';
        if (in_array($ext, ['css', 'scss', 'sass', 'less'])) return 'css';
        if (in_array($ext, ['html', 'htm', 'blade.php'])) return 'html';
        if (in_array($ext, ['sh', 'bash'])) return 'bash';
        if (in_array($ext, ['sql'])) return 'sql';
        if (in_array($ext, ['xml', 'svg'])) return 'xml';
        if (in_array($ext, ['yaml', 'yml'])) return 'yaml';
        if (in_array($ext, ['md', 'markdown'])) return 'markdown';
        if (in_array($ext, ['log', 'txt'])) return 'text';

        return 'text';
    }

    /**
     * Checks if a file is safe to view/edit in web text editor.
     */
    private function isEditable(string $filename): bool
    {
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        $basename = strtolower(basename($filename));

        if ($basename === '.env' || str_starts_with($basename, '.env.') || $basename === '.htaccess' || $basename === '.gitignore' || $basename === '.editorconfig' || $basename === 'artisan') {
            return true;
        }

        $editableExts = [
            'php', 'html', 'htm', 'js', 'mjs', 'cjs', 'ts', 'css', 'scss', 'json', 'lock',
            'txt', 'md', 'xml', 'svg', 'conf', 'config', 'ini', 'sh', 'sql', 'yaml', 'yml',
            'log', 'example', 'stub', 'vue'
        ];

        return in_array($ext, $editableExts, true);
    }

    /**
     * Main File Manager page view.
     */
    public function index(): void
    {
        $siteId = isset($_GET['site_id']) ? (int)$_GET['site_id'] : null;
        [$site, $baseDir, $allSites] = $this->getSiteContext($siteId);

        $initialPath = trim((string)($_GET['path'] ?? '/'), '/');
        $openEdit = trim((string)($_GET['edit'] ?? ''));

        Response::view('sites/files', [
            'title'        => 'File Manager · ' . ($site['domain'] ?? 'wwwroot'),
            'user'         => Auth::user(),
            'site'         => $site,
            'allSites'     => $allSites,
            'baseDir'      => $baseDir,
            'initialPath'  => $initialPath,
            'openEdit'     => $openEdit,
        ]);
    }

    /**
     * API: List directory contents with owner, permissions, and disk usage statistics.
     */
    public function listFiles(): void
    {
        $siteId = isset($_GET['site_id']) ? (int)$_GET['site_id'] : null;
        [$site, $baseDir] = $this->getSiteContext($siteId);

        $relPath = trim((string)($_GET['path'] ?? ''), '/');
        $targetDir = $this->resolveSafePath($baseDir, $relPath);

        if ($targetDir === null || !is_dir($targetDir)) {
            Response::json(['success' => false, 'error' => 'Direktori tidak ditemukan atau akses ditolak.'], 404);
        }

        // Build breadcrumbs
        $crumbs = [];
        $parts = array_filter(explode('/', $relPath));
        $accum = '';
        foreach ($parts as $p) {
            $accum .= ($accum === '' ? '' : '/') . $p;
            $crumbs[] = [
                'name' => $p,
                'path' => $accum,
            ];
        }

        // Read items
        $files = [];
        $dirs = [];
        $totalBytes = 0;
        $handle = @opendir($targetDir);
        if ($handle) {
            while (($entry = readdir($handle)) !== false) {
                if ($entry === '.' || $entry === '..' || str_ends_with($entry, '.part.almusa')) {
                    continue;
                }

                $full = $targetDir . '/' . $entry;
                $isDir = is_dir($full);
                $relItem = ($relPath === '' ? '' : ($relPath . '/')) . $entry;
                $stat = @stat($full);

                $sizeBytes = $isDir ? 0 : ($stat['size'] ?? 0);
                if (!$isDir) {
                    $totalBytes += $sizeBytes;
                }

                $owner = 'www-data';
                if (isset($stat['uid'])) {
                    if (function_exists('posix_getpwuid')) {
                        $pw = @posix_getpwuid($stat['uid']);
                        $owner = $pw['name'] ?? (string)$stat['uid'];
                    } else {
                        $owner = $stat['uid'] === 0 ? 'root' : ($stat['uid'] === 33 ? 'www-data' : (string)$stat['uid']);
                    }
                }

                $item = [
                    'name'        => $entry,
                    'path'        => $relItem,
                    'is_dir'      => $isDir,
                    'size_bytes'  => $sizeBytes,
                    'size'        => $isDir ? '—' : $this->formatSize($sizeBytes),
                    'mtime'       => $stat['mtime'] ?? 0,
                    'mtime_fmt'   => date('Y-m-d H:i:s', $stat['mtime'] ?? time()),
                    'perms'       => substr(sprintf('%o', $stat['mode'] ?? 0), -4),
                    'perms_num'   => substr(sprintf('%o', $stat['mode'] ?? 0), -3),
                    'owner'       => $owner,
                    'is_editable' => !$isDir && $this->isEditable($entry),
                    'is_archive'  => !$isDir && strtolower(pathinfo($entry, PATHINFO_EXTENSION)) === 'zip',
                    'syntax'      => !$isDir ? $this->detectSyntax($entry) : null,
                ];

                if ($isDir) {
                    $dirs[] = $item;
                } else {
                    $files[] = $item;
                }
            }
            closedir($handle);
        }

        // Sort: alphabetical ascending
        usort($dirs, fn($a, $b) => strcasecmp($a['name'], $b['name']));
        usort($files, fn($a, $b) => strcasecmp($a['name'], $b['name']));

        $parentPath = null;
        if (!empty($relPath)) {
            $parentPath = dirname($relPath);
            if ($parentPath === '.') {
                $parentPath = '';
            }
        }

        $diskFree = @disk_free_space($baseDir) ?: 0;
        $diskTotal = @disk_total_space($baseDir) ?: 0;

        Response::json([
            'success'      => true,
            'site'         => [
                'id'       => $site['id'],
                'domain'   => $site['domain'],
                'base_dir' => $baseDir,
            ],
            'current_path' => $relPath,
            'parent_path'  => $parentPath,
            'breadcrumbs'  => $crumbs,
            'items'        => array_merge($dirs, $files),
            'stats'        => [
                'dir_count'      => count($dirs),
                'file_count'     => count($files),
                'total_bytes'    => $totalBytes,
                'total_size_fmt' => $this->formatSize($totalBytes),
                'disk_free_fmt'  => $this->formatSize($diskFree),
                'disk_total_fmt' => $this->formatSize($diskTotal),
            ],
        ]);
    }

    /**
     * API: Read file content for editing.
     */
    public function readFile(): void
    {
        $siteId = (int)($_GET['site_id'] ?? 0);
        $isNginx = !empty($_GET['is_nginx']);
        [$site, $baseDir] = $this->getSiteContext($siteId);

        if ($isNginx) {
            $confPath = "/etc/nginx/sites-available/{$site['domain']}.conf";
            if (!file_exists($confPath)) {
                Response::json(['success' => false, 'error' => "File konfigurasi Nginx untuk {$site['domain']} tidak ditemukan."], 404);
            }
            $content = @file_get_contents($confPath);
            Response::json([
                'success'  => true,
                'filename' => "{$site['domain']}.conf",
                'path'     => $confPath,
                'content'  => $content !== false ? $content : '',
                'size'     => strlen((string)$content),
                'syntax'   => 'nginx',
                'is_nginx' => true,
            ]);
            return;
        }

        $relFile = trim((string)($_GET['file'] ?? ''), '/');
        $fullPath = $this->resolveSafePath($baseDir, $relFile);

        if ($fullPath === null || !file_exists($fullPath) || is_dir($fullPath)) {
            Response::json(['success' => false, 'error' => 'File tidak ditemukan atau akses ditolak.'], 404);
        }

        if (filesize($fullPath) > 5242880) { // 5MB limit for web editor
            Response::json(['success' => false, 'error' => 'File terlalu besar untuk dibuka di editor teks web (maksimal 5 MB).'], 400);
        }

        $content = @file_get_contents($fullPath);
        if ($content === false) {
            Response::json(['success' => false, 'error' => 'Gagal membaca isi file.'], 500);
        }

        Response::json([
            'success'  => true,
            'filename' => basename($fullPath),
            'path'     => $relFile,
            'content'  => $content,
            'size'     => strlen($content),
            'syntax'   => $this->detectSyntax(basename($fullPath)),
            'is_nginx' => false,
        ]);
    }

    /**
     * API: Save edited file content.
     */
    public function saveFile(): void
    {
        $siteId = (int)($_POST['site_id'] ?? 0);
        $content = (string)($_POST['content'] ?? '');
        $isNginx = !empty($_POST['is_nginx']);
        [$site, $baseDir] = $this->getSiteContext($siteId);

        if ($isNginx) {
            $domain = preg_replace('/[^a-zA-Z0-9\.\-]/', '', (string)$site['domain']);
            $tmpFile = "/tmp/almusa_nginx_save_{$domain}_" . time() . ".conf";
            if (@file_put_contents($tmpFile, $content) === false) {
                Response::json(['success' => false, 'error' => 'Gagal membuat file staging sementara.'], 500);
            }
            @chmod($tmpFile, 0644);

            $script = defined('PANEL_ROOT') ? PANEL_ROOT . '/scripts/vhost_manager.sh' : dirname(__DIR__, 2) . '/scripts/vhost_manager.sh';
            $cmd = "sudo " . escapeshellarg($script) . " save_conf " . escapeshellarg($domain) . " " . escapeshellarg($tmpFile) . " 2>&1";
            exec($cmd, $outputLines, $exitCode);
            @unlink($tmpFile);

            $output = implode("\n", $outputLines);

            if ($exitCode === 0) {
                Response::json([
                    'success' => true,
                    'message' => "Konfigurasi Nginx untuk {$domain} berhasil diuji, disimpan, dan Nginx dimuat ulang.",
                ]);
            } else {
                Response::json([
                    'success' => false,
                    'error'   => "Sintaks Nginx tidak valid! Perubahan dibatalkan:\n" . $output,
                ], 400);
            }
            return;
        }

        $relFile = trim((string)($_POST['file'] ?? ''), '/');
        $fullPath = $this->resolveSafePath($baseDir, $relFile);

        if ($fullPath === null) {
            Response::json(['success' => false, 'error' => 'Path file tidak valid atau berada di luar root website.'], 403);
        }

        $parent = dirname($fullPath);
        if (!is_dir($parent)) {
            @mkdir($parent, 0755, true);
        }

        $written = @file_put_contents($fullPath, $content);
        if ($written === false) {
            Response::json(['success' => false, 'error' => 'Gagal menyimpan file. Periksa hak akses direktori.'], 500);
        }

        @chmod($fullPath, 0644);

        Response::json([
            'success' => true,
            'message' => "File '{$relFile}' berhasil disimpan (" . $this->formatSize($written) . ").",
        ]);
    }

    /**
     * API: Create new file or folder.
     */
    public function createItem(): void
    {
        $siteId = (int)($_POST['site_id'] ?? 0);
        $type = trim((string)($_POST['type'] ?? 'file')); // 'file' or 'dir'
        $name = trim((string)($_POST['name'] ?? ''));
        $relPath = trim((string)($_POST['path'] ?? ''), '/');
        [$site, $baseDir] = $this->getSiteContext($siteId);

        if (empty($name) || str_contains($name, '/') || str_contains($name, '\\') || str_contains($name, "\0")) {
            Response::json(['success' => false, 'error' => 'Nama file atau folder tidak valid.'], 400);
        }

        $targetParent = $this->resolveSafePath($baseDir, $relPath);
        if ($targetParent === null || !is_dir($targetParent)) {
            Response::json(['success' => false, 'error' => 'Direktori tujuan tidak valid.'], 400);
        }

        $newItemPath = $targetParent . '/' . $name;
        if (file_exists($newItemPath)) {
            Response::json(['success' => false, 'error' => "'{$name}' sudah ada di direktori ini."], 400);
        }

        if ($type === 'dir') {
            if (!@mkdir($newItemPath, 0755, true)) {
                Response::json(['success' => false, 'error' => 'Gagal membuat folder.'], 500);
            }
        } else {
            if (@file_put_contents($newItemPath, '') === false) {
                Response::json(['success' => false, 'error' => 'Gagal membuat file.'], 500);
            }
            @chmod($newItemPath, 0644);
        }

        Response::json([
            'success' => true,
            'message' => ($type === 'dir' ? 'Folder' : 'File') . " '{$name}' berhasil dibuat.",
        ]);
    }

    /**
     * API: Delete file or directory.
     */
    public function deleteItem(): void
    {
        $siteId = (int)($_POST['site_id'] ?? 0);
        $relPath = trim((string)($_POST['path'] ?? ''), '/');
        [$site, $baseDir] = $this->getSiteContext($siteId);

        if (empty($relPath)) {
            Response::json(['success' => false, 'error' => 'Dilarang menghapus root folder website.'], 403);
        }

        $target = $this->resolveSafePath($baseDir, $relPath);
        if ($target === null || !file_exists($target)) {
            Response::json(['success' => false, 'error' => 'Item tidak ditemukan atau akses ditolak.'], 404);
        }

        if (is_dir($target)) {
            $this->recursiveRmdir($target);
            if (file_exists($target)) {
                Response::json(['success' => false, 'error' => 'Gagal menghapus folder. Periksa hak akses file di dalamnya.'], 500);
            }
        } else {
            if (!@unlink($target)) {
                Response::json(['success' => false, 'error' => 'Gagal menghapus file.'], 500);
            }
        }

        Response::json([
            'success' => true,
            'message' => "Item '{$relPath}' berhasil dihapus.",
        ]);
    }

    /**
     * API: Rename file or directory.
     */
    public function renameItem(): void
    {
        $siteId = (int)($_POST['site_id'] ?? 0);
        $oldRel = trim((string)($_POST['path'] ?? ''), '/');
        $newName = basename(trim((string)($_POST['new_name'] ?? '')));
        [$site, $baseDir] = $this->getSiteContext($siteId);

        if ($oldRel === '') {
            Response::json(['success' => false, 'error' => 'Root direktori tidak dapat diubah namanya.'], 400);
        }

        if ($newName === '' || $newName === '.' || $newName === '..' || str_contains($newName, '/') || str_contains($newName, '\\')) {
            Response::json(['success' => false, 'error' => 'Nama baru tidak valid.'], 400);
        }

        $oldPath = $this->resolveSafePath($baseDir, $oldRel);
        if ($oldPath === null || !file_exists($oldPath)) {
            Response::json(['success' => false, 'error' => 'Berkas asal tidak ditemukan.'], 404);
        }

        $parent = dirname($oldPath);
        $newPath = $parent . '/' . $newName;

        if (file_exists($newPath)) {
            Response::json(['success' => false, 'error' => "Nama '{$newName}' sudah digunakan di folder ini."], 400);
        }

        if (!@rename($oldPath, $newPath)) {
            Response::json(['success' => false, 'error' => 'Gagal mengubah nama. Periksa hak akses file/folder.'], 500);
        }

        Response::json([
            'success'  => true,
            'message'  => "Berhasil mengubah nama menjadi '{$newName}'.",
            'new_name' => $newName,
        ]);
    }

    /**
     * API: Change permissions (chmod) for file or directory.
     */
    public function chmodItem(): void
    {
        $siteId = (int)($_POST['site_id'] ?? 0);
        $relPath = trim((string)($_POST['path'] ?? ''), '/');
        $perms = trim((string)($_POST['perms'] ?? ''));
        $recursive = !empty($_POST['recursive']);
        [$site, $baseDir] = $this->getSiteContext($siteId);

        if (!preg_match('/^0?[1-7][0-7]{2}$/', $perms)) {
            Response::json(['success' => false, 'error' => 'Format izin hak akses tidak valid. Contoh: 755 atau 644.'], 400);
        }

        $target = $this->resolveSafePath($baseDir, $relPath);
        if ($target === null || !file_exists($target)) {
            Response::json(['success' => false, 'error' => 'File atau folder tidak ditemukan.'], 404);
        }

        $octal = octdec($perms);

        if ($recursive && is_dir($target)) {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($target, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::SELF_FIRST
            );
            foreach ($iterator as $item) {
                if ($item->isDir()) {
                    @chmod($item->getPathname(), $octal);
                } else {
                    @chmod($item->getPathname(), $octal === 0777 ? 0777 : ($item->isExecutable() ? $octal : 0644));
                }
            }
            @chmod($target, $octal);
        } else {
            if (!@chmod($target, $octal)) {
                $domain = preg_replace('/[^a-zA-Z0-9\.\-]/', '', (string)$site['domain']);
                $script = defined('PANEL_ROOT') ? PANEL_ROOT . '/scripts/vhost_manager.sh' : dirname(__DIR__, 2) . '/scripts/vhost_manager.sh';
                if (is_file($script)) {
                    @exec("sudo " . escapeshellarg($script) . " fix_permissions " . escapeshellarg($domain) . " " . escapeshellarg($target) . " 2>&1");
                }
            }
        }

        Response::json([
            'success' => true,
            'message' => "Izin berkas berhasil diubah menjadi {$perms}" . ($recursive ? ' (rekursif).' : '.'),
        ]);
    }

    /**
     * API: Copy file or folder.
     */
    public function copyItem(): void
    {
        $siteId = (int)($_POST['site_id'] ?? 0);
        $srcRel = trim((string)($_POST['source'] ?? ''), '/');
        $destRel = trim((string)($_POST['destination'] ?? ''), '/');
        [$site, $baseDir] = $this->getSiteContext($siteId);

        $srcPath = $this->resolveSafePath($baseDir, $srcRel);
        if ($srcPath === null || !file_exists($srcPath)) {
            Response::json(['success' => false, 'error' => 'Berkas asal tidak ditemukan.'], 404);
        }

        $targetDir = $this->resolveSafePath($baseDir, $destRel);
        if ($targetDir === null || !is_dir($targetDir)) {
            Response::json(['success' => false, 'error' => 'Direktori tujuan tidak valid.'], 400);
        }

        $baseName = basename($srcPath);
        $destPath = $targetDir . '/' . $baseName;

        if (file_exists($destPath)) {
            $ext = pathinfo($baseName, PATHINFO_EXTENSION);
            $nameWithoutExt = pathinfo($baseName, PATHINFO_FILENAME);
            $suffix = '_copy_' . time();
            $destPath = is_dir($srcPath)
                ? ($targetDir . '/' . $baseName . $suffix)
                : ($targetDir . '/' . $nameWithoutExt . $suffix . ($ext ? ('.' . $ext) : ''));
        }

        if (is_dir($srcPath)) {
            $this->recursiveCopy($srcPath, $destPath);
        } else {
            if (!@copy($srcPath, $destPath)) {
                Response::json(['success' => false, 'error' => 'Gagal menyalin file.'], 500);
            }
            @chmod($destPath, 0644);
        }

        Response::json([
            'success' => true,
            'message' => "Berhasil menyalin '" . basename($srcPath) . "'.",
        ]);
    }

    /**
     * API: Move/Cut file or folder.
     */
    public function moveItem(): void
    {
        $siteId = (int)($_POST['site_id'] ?? 0);
        $srcRel = trim((string)($_POST['source'] ?? ''), '/');
        $destRel = trim((string)($_POST['destination'] ?? ''), '/');
        [$site, $baseDir] = $this->getSiteContext($siteId);

        $srcPath = $this->resolveSafePath($baseDir, $srcRel);
        if ($srcPath === null || !file_exists($srcPath)) {
            Response::json(['success' => false, 'error' => 'Berkas asal tidak ditemukan.'], 404);
        }

        $targetDir = $this->resolveSafePath($baseDir, $destRel);
        if ($targetDir === null || !is_dir($targetDir)) {
            Response::json(['success' => false, 'error' => 'Direktori tujuan tidak valid.'], 400);
        }

        if (is_dir($srcPath) && str_starts_with($targetDir . '/', $srcPath . '/')) {
            Response::json(['success' => false, 'error' => 'Tidak dapat memindahkan folder ke dalam dirinya sendiri.'], 400);
        }

        $baseName = basename($srcPath);
        $destPath = $targetDir . '/' . $baseName;

        if (file_exists($destPath)) {
            Response::json(['success' => false, 'error' => "Nama '{$baseName}' sudah ada di direktori tujuan."], 400);
        }

        if (!@rename($srcPath, $destPath)) {
            Response::json(['success' => false, 'error' => 'Gagal memindahkan file/folder.'], 500);
        }

        Response::json([
            'success' => true,
            'message' => "Berhasil memindahkan '{$baseName}'.",
        ]);
    }

    /**
     * API: Compress file or folder into .zip archive.
     */
    public function compressZip(): void
    {
        @set_time_limit(600);
        $siteId = (int)($_POST['site_id'] ?? 0);
        $itemRel = trim((string)($_POST['item'] ?? ''), '/');
        $zipName = basename(trim((string)($_POST['zip_name'] ?? '')));
        [$site, $baseDir] = $this->getSiteContext($siteId);

        $sourcePath = $this->resolveSafePath($baseDir, $itemRel);
        if ($sourcePath === null || !file_exists($sourcePath)) {
            Response::json(['success' => false, 'error' => 'File atau folder yang akan dikompres tidak ditemukan.'], 404);
        }

        if ($zipName === '' || !str_ends_with(strtolower($zipName), '.zip')) {
            $zipName = ($zipName === '' ? basename($sourcePath) : $zipName) . '.zip';
        }

        $destDir = dirname($sourcePath);
        $zipPath = $destDir . '/' . $zipName;

        if (file_exists($zipPath)) {
            @unlink($zipPath);
        }

        $success = false;

        // Try zip CLI command first
        $zipBin = trim((string)@shell_exec('command -v zip 2>/dev/null'));
        if (!empty($zipBin) && is_executable($zipBin)) {
            $parentDir = dirname($sourcePath);
            $targetBase = basename($sourcePath);
            $cmd = "cd " . escapeshellarg($parentDir) . " && " . escapeshellarg($zipBin) . " -r -q " . escapeshellarg($zipPath) . " " . escapeshellarg($targetBase) . " 2>&1";
            exec($cmd, $out, $ret);
            if ($ret === 0) {
                $success = true;
            }
        }

        if (!$success && class_exists('ZipArchive')) {
            $zip = new \ZipArchive();
            if ($zip->open($zipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) === true) {
                if (is_dir($sourcePath)) {
                    $files = new \RecursiveIteratorIterator(
                        new \RecursiveDirectoryIterator($sourcePath, \FilesystemIterator::SKIP_DOTS),
                        \RecursiveIteratorIterator::LEAVES_ONLY
                    );
                    foreach ($files as $file) {
                        if (!$file->isDir()) {
                            $filePath = $file->getRealPath();
                            $rel = substr($filePath, strlen(dirname($sourcePath)) + 1);
                            $zip->addFile($filePath, str_replace('\\', '/', $rel));
                        }
                    }
                } else {
                    $zip->addFile($sourcePath, basename($sourcePath));
                }
                $zip->close();
                $success = true;
            }
        }

        if (!$success) {
            Response::json(['success' => false, 'error' => 'Gagal membuat file arsip zip.'], 500);
        }

        @chmod($zipPath, 0644);

        Response::json([
            'success'  => true,
            'message'  => "Arsip '{$zipName}' berhasil dibuat.",
            'zip_file' => $zipName,
        ]);
    }

    /**
     * API: Batch action on multiple items.
     */
    public function batchAction(): void
    {
        $siteId = (int)($_POST['site_id'] ?? 0);
        $action = trim((string)($_POST['action'] ?? ''));
        $items = (array)($_POST['items'] ?? []);
        $destRel = trim((string)($_POST['destination'] ?? ''), '/');
        $perms = trim((string)($_POST['perms'] ?? ''));
        [$site, $baseDir] = $this->getSiteContext($siteId);

        if (empty($items)) {
            Response::json(['success' => false, 'error' => 'Tidak ada item yang dipilih.'], 400);
        }

        $processed = 0;
        $targetDir = !empty($destRel) ? $this->resolveSafePath($baseDir, $destRel) : null;

        foreach ($items as $rel) {
            $path = $this->resolveSafePath($baseDir, trim((string)$rel, '/'));
            if ($path === null || !file_exists($path)) continue;

            if ($action === 'delete') {
                if (is_dir($path)) {
                    $this->recursiveRmdir($path);
                } else {
                    @unlink($path);
                }
                $processed++;
            } elseif ($action === 'copy' && $targetDir && is_dir($targetDir)) {
                $destPath = $targetDir . '/' . basename($path);
                if (file_exists($destPath)) {
                    $destPath = $targetDir . '/' . basename($path) . '_copy_' . time();
                }
                if (is_dir($path)) {
                    $this->recursiveCopy($path, $destPath);
                } else {
                    @copy($path, $destPath);
                    @chmod($destPath, 0644);
                }
                $processed++;
            } elseif ($action === 'move' && $targetDir && is_dir($targetDir)) {
                if (!is_dir($path) || !str_starts_with($targetDir . '/', $path . '/')) {
                    $destPath = $targetDir . '/' . basename($path);
                    if (!file_exists($destPath) && @rename($path, $destPath)) {
                        $processed++;
                    }
                }
            } elseif ($action === 'chmod' && preg_match('/^0?[1-7][0-7]{2}$/', $perms)) {
                @chmod($path, octdec($perms));
                $processed++;
            }
        }

        Response::json([
            'success'   => true,
            'message'   => "{$processed} item berhasil diproses ({$action}).",
            'processed' => $processed,
        ]);
    }

    /**
     * API: Upload file(s) with automatic chunked upload support for large files.
     */
    public function uploadFile(): void
    {
        $contentLength = (int)($_SERVER['CONTENT_LENGTH'] ?? 0);
        if (empty($_POST) && empty($_FILES) && $contentLength > 0) {
            $postMax = ini_get('post_max_size');
            $uploadMax = ini_get('upload_max_filesize');
            Response::json([
                'success' => false,
                'error'   => "Ukuran request (" . round($contentLength / 1048576, 1) . " MB) melebihi batas post_max_size PHP aktif ({$postMax}, upload_max_filesize: {$uploadMax}).",
            ], 400);
        }

        $siteId = (int)($_POST['site_id'] ?? $_GET['site_id'] ?? 0);
        $relPath = trim((string)($_POST['path'] ?? $_GET['path'] ?? ''), '/');
        [$site, $baseDir] = $this->getSiteContext($siteId);

        $targetDir = $this->resolveSafePath($baseDir, $relPath);
        if ($targetDir === null || !is_dir($targetDir)) {
            Response::json(['success' => false, 'error' => 'Direktori tujuan tidak valid.'], 400);
        }

        // Self-healing directory permissions if owned by root
        $this->ensureWritable($targetDir, $site);

        // 1. Handle Chunked Upload (chunk_index & total_chunks)
        if (isset($_POST['chunk_index'], $_POST['total_chunks'])) {
            $chunkIndex = (int)$_POST['chunk_index'];
            $totalChunks = (int)$_POST['total_chunks'];
            $origName = basename(trim((string)($_POST['file_name'] ?? '')));

            if ($origName === '' || $origName === '.' || $origName === '..' || $totalChunks < 1 || $chunkIndex < 0 || $chunkIndex >= $totalChunks) {
                Response::json(['success' => false, 'error' => 'Parameter potongan file (chunk) tidak valid.'], 400);
            }

            $chunkFile = $_FILES['file'] ?? null;
            if (!$chunkFile && !empty($_FILES['files'])) {
                $f = $_FILES['files'];
                $chunkFile = [
                    'name'     => is_array($f['name']) ? ($f['name'][0] ?? '') : $f['name'],
                    'tmp_name' => is_array($f['tmp_name']) ? ($f['tmp_name'][0] ?? '') : $f['tmp_name'],
                    'error'    => is_array($f['error']) ? ($f['error'][0] ?? UPLOAD_ERR_NO_FILE) : $f['error'],
                ];
            }

            if (!$chunkFile) {
                Response::json(['success' => false, 'error' => 'Data potongan file tidak diterima oleh server.'], 400);
            }

            if (($chunkFile['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
                $errCode = (int)$chunkFile['error'];
                Response::json(['success' => false, 'error' => $this->translateUploadError($errCode)], 400);
            }

            $partPath = $targetDir . '/.' . $origName . '.part.almusa';
            $in = @fopen($chunkFile['tmp_name'], 'rb');
            $out = @fopen($partPath, $chunkIndex === 0 ? 'wb' : 'ab');

            if (!$out) {
                // If opening target file fails, force fix_permissions on targetDir and retry
                $this->ensureWritable($targetDir, $site);
                $out = @fopen($partPath, $chunkIndex === 0 ? 'wb' : 'ab');
            }

            if (!$in || !$out) {
                if ($in) @fclose($in);
                if ($out) @fclose($out);
                Response::json(['success' => false, 'error' => 'Gagal menulis potongan file ke direktori tujuan (' . basename($targetDir) . '). Periksa izin folder atau sisa ruang disk.'], 500);
            }

            stream_copy_to_stream($in, $out);
            @fclose($in);
            @fclose($out);
            @unlink($chunkFile['tmp_name']);

            // Final chunk: rename temporary part file to target filename
            if ($chunkIndex + 1 >= $totalChunks) {
                $finalDest = $targetDir . '/' . $origName;
                if (file_exists($finalDest) && !is_dir($finalDest)) {
                    @unlink($finalDest);
                }
                if (!@rename($partPath, $finalDest)) {
                    @copy($partPath, $finalDest);
                    @unlink($partPath);
                }
                @chmod($finalDest, 0644);

                Response::json([
                    'success'   => true,
                    'completed' => true,
                    'message'   => "File '{$origName}' berhasil diunggah.",
                    'files'     => [$origName],
                ]);
            }

            Response::json([
                'success'     => true,
                'completed'   => false,
                'chunk_index' => $chunkIndex,
            ]);
        }

        // 2. Standard Single / Multi-File Upload
        if (empty($_FILES['file']) && empty($_FILES['files'])) {
            Response::json([
                'success' => false,
                'error'   => 'Tidak ada file yang diterima server (post_max_size: ' . ini_get('post_max_size') . ', upload_max_filesize: ' . ini_get('upload_max_filesize') . ').',
            ], 400);
        }

        $uploaded = [];
        $errors = [];
        $fileData = $_FILES['file'] ?? $_FILES['files'];

        if (is_array($fileData['name'])) {
            $count = count($fileData['name']);
            for ($i = 0; $i < $count; $i++) {
                $errCode = (int)($fileData['error'][$i] ?? UPLOAD_ERR_NO_FILE);
                if ($errCode === UPLOAD_ERR_OK) {
                    $cleanName = basename((string)$fileData['name'][$i]);
                    $dest = $targetDir . '/' . $cleanName;
                    if (@move_uploaded_file($fileData['tmp_name'][$i], $dest)) {
                        @chmod($dest, 0644);
                        $uploaded[] = $cleanName;
                    } else {
                        $errors[] = "Gagal memindahkan '{$cleanName}' ke direktori tujuan.";
                    }
                } else {
                    $errors[] = $this->translateUploadError($errCode);
                }
            }
        } else {
            $errCode = (int)($fileData['error'] ?? UPLOAD_ERR_NO_FILE);
            if ($errCode === UPLOAD_ERR_OK) {
                $cleanName = basename((string)$fileData['name']);
                $dest = $targetDir . '/' . $cleanName;
                if (@move_uploaded_file($fileData['tmp_name'], $dest)) {
                    @chmod($dest, 0644);
                    $uploaded[] = $cleanName;
                } else {
                    $errors[] = "Gagal memindahkan '{$cleanName}' ke direktori tujuan.";
                }
            } else {
                Response::json(['success' => false, 'error' => $this->translateUploadError($errCode)], 400);
            }
        }

        if (empty($uploaded)) {
            $errMsg = !empty($errors) ? implode(' ', array_unique($errors)) : 'Gagal memindahkan file ke direktori tujuan.';
            Response::json(['success' => false, 'error' => $errMsg], 400);
        }

        Response::json([
            'success' => true,
            'message' => count($uploaded) . " file berhasil diunggah.",
            'files'   => $uploaded,
        ]);
    }

    /**
     * Translates PHP upload error code into descriptive Indonesian message.
     */
    private function translateUploadError(int $errCode): string
    {
        switch ($errCode) {
            case UPLOAD_ERR_INI_SIZE:
                return 'Ukuran file melebihi upload_max_filesize di PHP (' . ini_get('upload_max_filesize') . ').';
            case UPLOAD_ERR_FORM_SIZE:
                return 'Ukuran file melebihi batas MAX_FILE_SIZE pada form.';
            case UPLOAD_ERR_PARTIAL:
                return 'File hanya terunggah sebagian (koneksi terputus).';
            case UPLOAD_ERR_NO_FILE:
                return 'Tidak ada file yang dipilih.';
            case UPLOAD_ERR_NO_TMP_DIR:
                return 'Folder temporary (/tmp) tidak ditemukan di server.';
            case UPLOAD_ERR_CANT_WRITE:
                return 'Gagal menulis file ke disk temporary (/tmp penuh atau tidak writable).';
            case UPLOAD_ERR_EXTENSION:
                return 'Unggahan file dihentikan oleh ekstensi PHP.';
            default:
                return 'Terjadi kesalahan saat mengunggah file (kode: ' . $errCode . ').';
        }
    }

    /**
     * API: Extract zip archive safely and robustly.
     * Supports high-performance CLI unzip (handles Zip64, non-standard headers & large archives)
     * with automated fallback to PHP ZipArchive and Python zipfile.
     */
    public function extractZip(): void
    {
        @set_time_limit(600);
        @ini_set('memory_limit', '512M');

        $siteId = (int)($_POST['site_id'] ?? $_GET['site_id'] ?? 0);
        $relZip = trim((string)($_POST['file'] ?? $_GET['file'] ?? ''), '/');
        $relDest = trim((string)($_POST['destination'] ?? $_GET['destination'] ?? ''), '/');
        [$site, $baseDir] = $this->getSiteContext($siteId);

        $zipPath = $this->resolveSafePath($baseDir, $relZip);
        if ($zipPath === null || !file_exists($zipPath) || is_dir($zipPath)) {
            Response::json(['success' => false, 'error' => 'File arsip tidak ditemukan atau akses ditolak.'], 404);
        }

        // Determine destination directory
        $destDir = empty($relDest) ? dirname($zipPath) : $this->resolveSafePath($baseDir, $relDest);
        if ($destDir === null) {
            Response::json(['success' => false, 'error' => 'Direktori tujuan ekstraksi tidak valid atau berada di luar folder website.'], 400);
        }

        if (!is_dir($destDir)) {
            @mkdir($destDir, 0755, true);
        }

        $domain = preg_replace('/[^a-zA-Z0-9\.\-]/', '', (string)$site['domain']);
        $script = defined('PANEL_ROOT') ? PANEL_ROOT . '/scripts/vhost_manager.sh' : dirname(__DIR__, 2) . '/scripts/vhost_manager.sh';

        // Self-heal permissions on destination directory before extraction
        if (!is_writable($destDir)) {
            @chmod($destDir, 0755);
            if (!is_writable($destDir) && is_file($script)) {
                @exec("sudo " . escapeshellarg($script) . " fix_permissions " . escapeshellarg($domain) . " " . escapeshellarg($destDir) . " 2>&1");
            }
        }

        $extractedSuccess = false;
        $extractionError = '';

        // ENGINE 1: High-Performance System `unzip` CLI
        $unzipBin = '';
        if (function_exists('exec')) {
            $checkUnzip = trim((string)@shell_exec('command -v unzip 2>/dev/null'));
            if (!empty($checkUnzip) && is_executable($checkUnzip)) {
                $unzipBin = $checkUnzip;
            }
        }

        if (!empty($unzipBin)) {
            $cmd = escapeshellarg($unzipBin) . " -q -o " . escapeshellarg($zipPath) . " -d " . escapeshellarg($destDir) . " 2>&1";
            exec($cmd, $cliOutput, $exitCode);

            if ($exitCode === 0 || $exitCode === 1) {
                $extractedSuccess = true;
            } else {
                $extractionError = !empty($cliOutput) ? implode(' ', array_slice($cliOutput, -3)) : 'unzip exit code ' . $exitCode;
            }
        }

        // ENGINE 2: PHP ZipArchive (Fallback if unzip CLI is unavailable or failed)
        if (!$extractedSuccess && class_exists('ZipArchive')) {
            $zip = new \ZipArchive();
            $res = $zip->open($zipPath, \ZipArchive::RDONLY);
            if ($res !== true) {
                $res = $zip->open($zipPath);
            }

            if ($res === true) {
                $safeFiles = [];
                for ($i = 0; $i < $zip->numFiles; $i++) {
                    $stat = $zip->statIndex($i);
                    $entryName = $stat['name'] ?? '';
                    if ($entryName === '') {
                        continue;
                    }

                    // Granular Zip-Slip check: reject actual directory escape attempts
                    $normalizedEntry = str_replace('\\', '/', $entryName);
                    if (str_starts_with($normalizedEntry, '/') || preg_match('#(^|/)\.\.(/|$)#', $normalizedEntry)) {
                        continue;
                    }
                    $safeFiles[] = $entryName;
                }

                if (!empty($safeFiles)) {
                    if ($zip->extractTo($destDir, $safeFiles)) {
                        $extractedSuccess = true;
                    }
                } elseif ($zip->numFiles === 0) {
                    $extractedSuccess = true;
                }

                $zip->close();
            } else {
                switch ($res) {
                    case \ZipArchive::ER_NOZIP:
                        $codeMsg = 'File bukan merupakan arsip zip yang valid atau belum selesai terunggah.';
                        break;
                    case \ZipArchive::ER_INCONS:
                        $codeMsg = 'Struktur arsip zip tidak konsisten (inconsistent header).';
                        break;
                    case \ZipArchive::ER_CRC:
                        $codeMsg = 'Checksum CRC file zip tidak cocok (file korup).';
                        break;
                    case \ZipArchive::ER_OPEN:
                        $codeMsg = 'Server tidak dapat membuka file zip.';
                        break;
                    case \ZipArchive::ER_READ:
                        $codeMsg = 'Gagal membaca isi file zip dari disk.';
                        break;
                    case \ZipArchive::ER_SEEK:
                        $codeMsg = 'Gagal melakukan seek pada file zip.';
                        break;
                    default:
                        $codeMsg = 'Kode error: ' . $res;
                        break;
                }
                if (empty($extractionError)) {
                    $extractionError = $codeMsg;
                }
            }
        }

        // ENGINE 3: Python Fallback (if both unzip and ZipArchive failed)
        if (!$extractedSuccess && function_exists('exec')) {
            $pyBin = trim((string)@shell_exec('command -v python3 || command -v python 2>/dev/null'));
            if (!empty($pyBin)) {
                $pyScript = "import zipfile, sys; z = zipfile.ZipFile(sys.argv[1]); z.extractall(sys.argv[2])";
                $cmd = escapeshellarg($pyBin) . " -c " . escapeshellarg($pyScript) . " " . escapeshellarg($zipPath) . " " . escapeshellarg($destDir) . " 2>&1";
                exec($cmd, $pyOut, $pyRet);
                if ($pyRet === 0) {
                    $extractedSuccess = true;
                } elseif (empty($extractionError) && !empty($pyOut)) {
                    $extractionError = implode(' ', array_slice($pyOut, -2));
                }
            }
        }

        if (!$extractedSuccess) {
            $msg = 'Gagal mengekstrak arsip zip.';
            if (!empty($extractionError)) {
                $msg .= ' (' . $extractionError . ')';
            }
            Response::json(['success' => false, 'error' => $msg], 400);
        }

        // Fix permissions automatically on all extracted files & folders
        if (is_file($script)) {
            @exec("sudo " . escapeshellarg($script) . " fix_permissions " . escapeshellarg($domain) . " " . escapeshellarg($destDir) . " 2>&1");
        }

        Response::json([
            'success' => true,
            'message' => "Arsip zip berhasil diekstrak dan seluruh permission berkas telah diperbarui.",
            'destination' => $destDir,
        ]);
    }

    /**
     * API: Download a file directly.
     */
    public function downloadFile(): void
    {
        $siteId = (int)($_GET['site_id'] ?? 0);
        $relFile = trim((string)($_GET['file'] ?? ''), '/');
        [$site, $baseDir] = $this->getSiteContext($siteId);

        $fullPath = $this->resolveSafePath($baseDir, $relFile);
        if ($fullPath === null || !file_exists($fullPath) || is_dir($fullPath)) {
            Response::error(404, 'File tidak ditemukan.');
        }

        $filename = basename($fullPath);
        header('Content-Description: File Transfer');
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . addslashes($filename) . '"');
        header('Expires: 0');
        header('Cache-Control: must-revalidate');
        header('Pragma: public');
        header('Content-Length: ' . filesize($fullPath));
        readfile($fullPath);
        exit;
    }

    /**
     * Ensures target directory is writable by www-data, triggering self-healing if needed.
     */
    private function ensureWritable(string $dir, array $site): void
    {
        if (is_writable($dir)) {
            return;
        }

        @chmod($dir, 0775);
        if (is_writable($dir)) {
            return;
        }

        $domain = !empty($site['is_root_storage']) ? 'wwwroot' : preg_replace('/[^a-zA-Z0-9\.\-]/', '', (string)($site['domain'] ?? 'wwwroot'));
        $script = defined('PANEL_ROOT') ? PANEL_ROOT . '/scripts/vhost_manager.sh' : dirname(__DIR__, 2) . '/scripts/vhost_manager.sh';

        if (is_file($script)) {
            @exec("sudo " . escapeshellarg($script) . " fix_permissions " . escapeshellarg($domain ?: 'wwwroot') . " " . escapeshellarg($dir) . " 2>&1");
        }

        @chmod($dir, 0775);
    }

    /**
     * Helper to recursively delete directory contents.
     */
    private function recursiveRmdir(string $dir): void
    {
        if (!is_dir($dir)) return;
        $items = scandir($dir);
        if ($items === false) return;

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') continue;
            $path = $dir . '/' . $item;
            if (is_dir($path)) {
                $this->recursiveRmdir($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($dir);
    }

    /**
     * Helper to recursively copy directory contents.
     */
    private function recursiveCopy(string $src, string $dest): void
    {
        @mkdir($dest, 0755, true);
        $dir = opendir($src);
        if ($dir) {
            while (($file = readdir($dir)) !== false) {
                if ($file === '.' || $file === '..') continue;
                $srcFile = $src . '/' . $file;
                $destFile = $dest . '/' . $file;
                if (is_dir($srcFile)) {
                    $this->recursiveCopy($srcFile, $destFile);
                } else {
                    @copy($srcFile, $destFile);
                    @chmod($destFile, 0644);
                }
            }
            closedir($dir);
        }
    }
}
