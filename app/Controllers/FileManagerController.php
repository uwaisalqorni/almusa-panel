<?php

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Database;
use App\Core\Response;

class FileManagerController
{
    /**
     * Normalizes filesystem paths to use forward slashes and strips null bytes.
     */
    private function normalizePath(string $path): string
    {
        $path = str_replace(["\0", '\\'], ['', '/'], $path);
        $path = preg_replace('#/+#', '/', $path) ?? $path;
        if ($path !== '/' && !preg_match('#^[A-Za-z]:/$#', $path)) {
            $path = rtrim($path, '/');
        }
        return $path;
    }

    /**
     * Strictly checks if $candidatePath is equal to or inside $baseDir (boundary-safe).
     */
    private function isWithinBaseDir(string $candidatePath, string $baseDir): bool
    {
        $cand = $this->normalizePath($candidatePath);
        $base = $this->normalizePath($baseDir);

        if (PHP_OS_FAMILY === 'Windows') {
            $candCmp = strtolower($cand);
            $baseCmp = strtolower($base);
        } else {
            $candCmp = $cand;
            $baseCmp = $base;
        }

        if ($candCmp === $baseCmp) {
            return true;
        }

        $prefix = rtrim($baseCmp, '/') . '/';
        return str_starts_with($candCmp, $prefix);
    }

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

        $defaultRootPath = '/www/wwwroot';

        // Default storage fallback (/www/wwwroot)
        $defaultStorage = [
            'id'               => 0,
            'domain'           => 'Folder Utama (/www/wwwroot)',
            'docroot'          => $defaultRootPath,
            'php_version'      => '8.3',
            'rewrite_template' => 'default',
            'status'           => 'active',
            'is_root_storage'  => true,
        ];

        if (!is_dir($defaultRootPath)) {
            @mkdir($defaultRootPath, 0775, true);
        }
        if (is_dir($defaultRootPath) && !is_writable($defaultRootPath)) {
            @chmod($defaultRootPath, 0775);
            $script = defined('PANEL_ROOT') ? PANEL_ROOT . '/scripts/vhost_manager.sh' : dirname(__DIR__, 2) . '/scripts/vhost_manager.sh';
            if (PHP_OS_FAMILY !== 'Windows' && is_file($script)) {
                @exec("sudo " . escapeshellarg($script) . " fix_permissions wwwroot " . escapeshellarg($defaultRootPath) . " 2>&1");
            }
        }

        if (empty($allSites)) {
            $allSites = [$defaultStorage];
            $site = $defaultStorage;
            $baseDir = $defaultRootPath;
        } else {
            $site = null;
            if ($siteId === 0) {
                $site = $defaultStorage;
                $baseDir = $defaultRootPath;
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
                $baseDir = $defaultRootPath;
            } else {
                $docroot = $site['docroot'] ?: ("/www/wwwroot/" . $site['domain']);
                $baseDir = $this->normalizePath($docroot);
                if (preg_match('#/public/?$#i', $baseDir)) {
                    $parent = dirname($baseDir);
                    if (is_dir($parent)) {
                        $baseDir = $this->normalizePath($parent);
                    }
                }
            }
        }

        // Safety: Ensure baseDir exists and normalize realpath
        $realBase = realpath($baseDir);
        if ($realBase === false) {
            @mkdir($baseDir, 0755, true);
            $realBase = realpath($baseDir) ?: $baseDir;
        }

        return [$site, $this->normalizePath($realBase), $allSites];
    }

    /**
     * Strictly verifies and resolves a relative subpath within the site's base directory.
     * Prevents directory traversal attacks and prefix-collision symlink escapes.
     */
    private function resolveSafePath(string $baseDir, string $subPath): ?string
    {
        $baseDir = $this->normalizePath($baseDir);
        $subPath = str_replace(['\\', "\0"], ['/', ''], $subPath);
        $parts = array_filter(explode('/', $subPath), static function ($p) {
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

        // If target exists, verify its canonical realpath is strictly within baseDir
        if (file_exists($fullPath)) {
            $real = realpath($fullPath);
            if ($real === false) {
                return null;
            }
            $realNorm = $this->normalizePath($real);
            if (!$this->isWithinBaseDir($realNorm, $baseDir)) {
                return null;
            }
            return $realNorm;
        }

        // For files/folders that are about to be created, verify the nearest existing ancestor
        $checkParent = dirname($fullPath);
        $guard = 0;
        while (!file_exists($checkParent) && $guard < 32) {
            $next = dirname($checkParent);
            if ($next === $checkParent) {
                break;
            }
            $checkParent = $next;
            $guard++;
        }

        if (file_exists($checkParent)) {
            $realParent = realpath($checkParent);
            if ($realParent === false || !$this->isWithinBaseDir($this->normalizePath($realParent), $baseDir)) {
                return null;
            }
        }

        return $this->normalizePath($fullPath);
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
        if (in_array($ext, ['php', 'phtml'], true)) return 'php';
        if (in_array($ext, ['js', 'mjs', 'cjs', 'ts', 'jsx', 'tsx'], true)) return 'javascript';
        if (in_array($ext, ['json', 'lock'], true)) return 'json';
        if (in_array($ext, ['css', 'scss', 'sass', 'less'], true)) return 'css';
        if (in_array($ext, ['html', 'htm', 'vue'], true) || str_ends_with($basename, '.blade.php')) return 'html';
        if (in_array($ext, ['sh', 'bash'], true)) return 'bash';
        if ($ext === 'sql') return 'sql';
        if (in_array($ext, ['xml', 'svg'], true)) return 'xml';
        if (in_array($ext, ['yaml', 'yml'], true)) return 'yaml';
        if (in_array($ext, ['md', 'markdown'], true)) return 'markdown';
        if (in_array($ext, ['log', 'txt', 'ini', 'cfg'], true)) return 'text';

        return 'text';
    }

    /**
     * Checks if a file is safe to view/edit in web text editor.
     */
    private function isEditable(string $filename): bool
    {
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        $basename = strtolower(basename($filename));

        $specialNames = [
            '.env', '.htaccess', '.gitignore', '.gitattributes', '.editorconfig',
            'artisan', 'dockerfile', 'makefile', 'composer.lock', 'package-lock.json'
        ];
        if (in_array($basename, $specialNames, true) || str_starts_with($basename, '.env.')) {
            return true;
        }

        $editableExts = [
            'php', 'phtml', 'html', 'htm', 'js', 'mjs', 'cjs', 'ts', 'jsx', 'tsx',
            'css', 'scss', 'sass', 'less', 'json', 'lock', 'txt', 'md', 'markdown',
            'xml', 'svg', 'conf', 'config', 'ini', 'cfg', 'sh', 'bash', 'sql',
            'yaml', 'yml', 'log', 'example', 'stub', 'vue', 'py', 'rb', 'go', 'toml'
        ];

        return in_array($ext, $editableExts, true);
    }

    /**
     * Checks if filename is a supported archive (.zip, .tar.gz, .tgz, .tar).
     */
    private function isArchiveFile(string $filename): bool
    {
        $lower = strtolower($filename);
        return str_ends_with($lower, '.zip')
            || str_ends_with($lower, '.tar.gz')
            || str_ends_with($lower, '.tgz')
            || str_ends_with($lower, '.tar');
    }

    /**
     * Main File Manager page view.
     */
    public function index(): void
    {
        $siteId = isset($_GET['site_id']) ? (int)$_GET['site_id'] : null;
        [$site, $baseDir, $allSites] = $this->getSiteContext($siteId);

        $initialPath = trim(str_replace('\\', '/', (string)($_GET['path'] ?? '/')), '/');
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

        $relPath = trim(str_replace('\\', '/', (string)($_GET['path'] ?? '')), '/');
        $targetDir = $this->resolveSafePath($baseDir, $relPath);

        if ($targetDir === null || !is_dir($targetDir)) {
            Response::json(['success' => false, 'error' => 'Direktori tidak ditemukan atau akses ditolak.'], 404);
        }

        // Build breadcrumbs
        $crumbs = [];
        $parts = array_filter(explode('/', $relPath), static fn($p) => $p !== '');
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

                $sizeBytes = $isDir ? 0 : (int)($stat['size'] ?? 0);
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
                    'is_archive'  => !$isDir && $this->isArchiveFile($entry),
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
        usort($dirs, static fn($a, $b) => strcasecmp($a['name'], $b['name']));
        usort($files, static fn($a, $b) => strcasecmp($a['name'], $b['name']));

        $parentPath = null;
        if ($relPath !== '') {
            $rawParent = str_replace('\\', '/', dirname($relPath));
            $parentPath = ($rawParent === '.' || $rawParent === '/') ? '' : trim($rawParent, '/');
        }

        $diskFree = (int)(@disk_free_space($baseDir) ?: 0);
        $diskTotal = (int)(@disk_total_space($baseDir) ?: 0);

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
            'dirs'         => $dirs,
            'files'        => $files,
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

        $relFile = trim(str_replace('\\', '/', (string)($_GET['file'] ?? '')), '/');
        $fullPath = $this->resolveSafePath($baseDir, $relFile);

        if ($fullPath === null || !file_exists($fullPath) || is_dir($fullPath)) {
            Response::json(['success' => false, 'error' => 'File tidak ditemukan atau akses ditolak.'], 404);
        }

        if (filesize($fullPath) > 5242880) { // 5MB limit for web editor
            Response::json(['success' => false, 'error' => 'File terlalu besar untuk dibuka di editor teks web (maksimal 5 MB).'], 400);
        }

        $content = @file_get_contents($fullPath);
        if ($content === false) {
            $this->ensureWritable(dirname($fullPath), $site);
            @chmod($fullPath, 0644);
            $content = @file_get_contents($fullPath);
        }

        if ($content === false) {
            Response::json(['success' => false, 'error' => 'Gagal membaca isi file. Periksa izin hak akses berkas.'], 500);
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
            $tmpFile = sys_get_temp_dir() . "/almusa_nginx_save_{$domain}_" . time() . ".conf";
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

        $relFile = trim(str_replace('\\', '/', (string)($_POST['file'] ?? '')), '/');
        if ($relFile === '') {
            Response::json(['success' => false, 'error' => 'Path file tidak boleh kosong.'], 400);
        }

        $fullPath = $this->resolveSafePath($baseDir, $relFile);
        if ($fullPath === null || is_dir($fullPath)) {
            Response::json(['success' => false, 'error' => 'Path file tidak valid atau berada di luar root website.'], 403);
        }

        $parent = dirname($fullPath);
        if (!is_dir($parent)) {
            @mkdir($parent, 0755, true);
        }

        $this->ensureWritable($parent, $site);
        if (file_exists($fullPath) && !is_writable($fullPath)) {
            $this->applyChmodToPath($fullPath, '0644', false, $site);
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
        $relPath = trim(str_replace('\\', '/', (string)($_POST['path'] ?? '')), '/');
        [$site, $baseDir] = $this->getSiteContext($siteId);

        if ($name === '' || $name === '.' || $name === '..' || str_contains($name, '/') || str_contains($name, '\\') || str_contains($name, "\0")) {
            Response::json(['success' => false, 'error' => 'Nama file atau folder tidak valid.'], 400);
        }

        $targetParent = $this->resolveSafePath($baseDir, $relPath);
        if ($targetParent === null || !is_dir($targetParent)) {
            Response::json(['success' => false, 'error' => 'Direktori tujuan tidak valid.'], 400);
        }

        $this->ensureWritable($targetParent, $site);

        $newItemPath = $targetParent . '/' . $name;
        if (file_exists($newItemPath)) {
            Response::json(['success' => false, 'error' => "'{$name}' sudah ada di direktori ini."], 400);
        }

        if ($type === 'dir') {
            if (!@mkdir($newItemPath, 0755, true)) {
                Response::json(['success' => false, 'error' => 'Gagal membuat folder. Periksa izin direktori.'], 500);
            }
            @chmod($newItemPath, 0755);
        } else {
            if (@file_put_contents($newItemPath, '') === false) {
                Response::json(['success' => false, 'error' => 'Gagal membuat file. Periksa izin direktori.'], 500);
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
        $relPath = trim(str_replace('\\', '/', (string)($_POST['path'] ?? '')), '/');
        [$site, $baseDir] = $this->getSiteContext($siteId);

        if ($relPath === '') {
            Response::json(['success' => false, 'error' => 'Dilarang menghapus root folder website.'], 403);
        }

        $target = $this->resolveSafePath($baseDir, $relPath);
        if ($target === null || !file_exists($target) || $this->normalizePath($target) === $this->normalizePath($baseDir)) {
            Response::json(['success' => false, 'error' => 'Item tidak ditemukan atau akses ditolak.'], 404);
        }

        $this->ensureWritable(dirname($target), $site);

        if (is_dir($target)) {
            $this->recursiveRmdir($target);
            if (file_exists($target)) {
                $this->ensureWritable($target, $site);
                $this->recursiveRmdir($target);
            }
            if (file_exists($target)) {
                Response::json(['success' => false, 'error' => 'Gagal menghapus folder. Periksa hak akses file di dalamnya.'], 500);
            }
        } else {
            if (!@unlink($target)) {
                $this->applyChmodToPath($target, '0666', false, $site);
                if (!@unlink($target)) {
                    Response::json(['success' => false, 'error' => 'Gagal menghapus file.'], 500);
                }
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
        $oldRel = trim(str_replace('\\', '/', (string)($_POST['path'] ?? '')), '/');
        $rawNewName = trim((string)($_POST['new_name'] ?? ''));
        [$site, $baseDir] = $this->getSiteContext($siteId);

        if ($oldRel === '') {
            Response::json(['success' => false, 'error' => 'Root direktori tidak dapat diubah namanya.'], 400);
        }

        if ($rawNewName === '' || $rawNewName === '.' || $rawNewName === '..' || str_contains($rawNewName, '/') || str_contains($rawNewName, '\\') || str_contains($rawNewName, "\0")) {
            Response::json(['success' => false, 'error' => 'Nama baru tidak valid.'], 400);
        }

        $newName = basename($rawNewName);
        $oldPath = $this->resolveSafePath($baseDir, $oldRel);
        if ($oldPath === null || !file_exists($oldPath)) {
            Response::json(['success' => false, 'error' => 'Berkas asal tidak ditemukan.'], 404);
        }

        $parent = dirname($oldPath);
        $this->ensureWritable($parent, $site);
        $newPath = $parent . '/' . $newName;

        if ($this->normalizePath($oldPath) === $this->normalizePath($newPath)) {
            Response::json([
                'success'  => true,
                'message'  => "Nama berkas tetap '{$newName}'.",
                'new_name' => $newName,
            ]);
        }

        if (file_exists($newPath)) {
            Response::json(['success' => false, 'error' => "Nama '{$newName}' sudah digunakan di folder ini."], 400);
        }

        if (!@rename($oldPath, $newPath)) {
            $this->ensureWritable($oldPath, $site);
            if (!@rename($oldPath, $newPath)) {
                Response::json(['success' => false, 'error' => 'Gagal mengubah nama. Periksa hak akses file/folder.'], 500);
            }
        }

        Response::json([
            'success'  => true,
            'message'  => "Berhasil mengubah nama menjadi '{$newName}'.",
            'new_name' => $newName,
        ]);
    }

    /**
     * Applies chmod permissions to a single path or recursively, falling back to sudo vhost_manager.sh if needed.
     */
    private function applyChmodToPath(string $target, string $perms, bool $recursive, array $site): bool
    {
        $cleanPerms = ltrim($perms, '0');
        if ($cleanPerms === '') {
            $cleanPerms = '755';
        }
        $paddedPerms = str_pad($cleanPerms, 4, '0', STR_PAD_LEFT);
        $octal = octdec($paddedPerms);

        $ok = true;
        if ($recursive && is_dir($target)) {
            try {
                $iterator = new \RecursiveIteratorIterator(
                    new \RecursiveDirectoryIterator($target, \FilesystemIterator::SKIP_DOTS),
                    \RecursiveIteratorIterator::SELF_FIRST
                );
                foreach ($iterator as $item) {
                    if (!@chmod($item->getPathname(), $octal)) {
                        $ok = false;
                    }
                }
            } catch (\Throwable $e) {
                $ok = false;
            }
            if (!@chmod($target, $octal)) {
                $ok = false;
            }
        } else {
            if (!@chmod($target, $octal)) {
                $ok = false;
            }
        }

        if (!$ok && PHP_OS_FAMILY !== 'Windows') {
            $domain = !empty($site['is_root_storage']) ? 'wwwroot' : preg_replace('/[^a-zA-Z0-9\.\-]/', '', (string)($site['domain'] ?? 'wwwroot'));
            $script = defined('PANEL_ROOT') ? PANEL_ROOT . '/scripts/vhost_manager.sh' : dirname(__DIR__, 2) . '/scripts/vhost_manager.sh';
            if (is_file($script)) {
                $cmd = "sudo " . escapeshellarg($script) . " chmod_path " . escapeshellarg($domain ?: 'wwwroot') . " " . escapeshellarg($target) . " " . escapeshellarg($paddedPerms) . " " . ($recursive ? '1' : '0') . " 2>&1";
                exec($cmd, $out, $ret);
                if ($ret === 0) {
                    $ok = true;
                }
            }
        }

        clearstatcache(true, $target);
        return $ok;
    }

    /**
     * API: Change permissions (chmod) for file or directory.
     */
    public function chmodItem(): void
    {
        $siteId = (int)($_POST['site_id'] ?? 0);
        $relPath = trim(str_replace('\\', '/', (string)($_POST['path'] ?? '')), '/');
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

        if (!$this->applyChmodToPath($target, $perms, $recursive, $site)) {
            Response::json(['success' => false, 'error' => 'Gagal mengubah izin berkas. Periksa kepemilikan berkas.'], 500);
        }

        Response::json([
            'success' => true,
            'message' => "Izin berkas berhasil diubah menjadi {$perms}" . ($recursive ? ' (rekursif).' : '.'),
        ]);
    }

    /**
     * Generates a safe non-colliding destination path when copying a file or folder.
     */
    private function buildCopyDestinationPath(string $targetDir, string $srcPath): string
    {
        $baseName = basename($srcPath);
        $destPath = $targetDir . '/' . $baseName;
        if (!file_exists($destPath)) {
            return $destPath;
        }

        $suffix = '_copy_' . time();
        if (is_dir($srcPath)) {
            $candidate = $targetDir . '/' . $baseName . $suffix;
        } elseif (str_starts_with($baseName, '.') && substr_count($baseName, '.') === 1) {
            // Dotfiles like .env or .htaccess
            $candidate = $targetDir . '/' . $baseName . $suffix;
        } else {
            $ext = pathinfo($baseName, PATHINFO_EXTENSION);
            $nameWithoutExt = pathinfo($baseName, PATHINFO_FILENAME);
            $candidate = $targetDir . '/' . $nameWithoutExt . $suffix . ($ext !== '' ? ('.' . $ext) : '');
        }

        $counter = 1;
        while (file_exists($candidate)) {
            $candidate .= '_' . $counter++;
        }
        return $candidate;
    }

    /**
     * API: Copy file or folder.
     */
    public function copyItem(): void
    {
        $siteId = (int)($_POST['site_id'] ?? 0);
        $srcRel = trim(str_replace('\\', '/', (string)($_POST['source'] ?? '')), '/');
        $destRel = trim(str_replace('\\', '/', (string)($_POST['destination'] ?? '')), '/');
        [$site, $baseDir] = $this->getSiteContext($siteId);

        $srcPath = $this->resolveSafePath($baseDir, $srcRel);
        if ($srcPath === null || !file_exists($srcPath)) {
            Response::json(['success' => false, 'error' => 'Berkas asal tidak ditemukan.'], 404);
        }

        $targetDir = $this->resolveSafePath($baseDir, $destRel);
        if ($targetDir === null || !is_dir($targetDir)) {
            Response::json(['success' => false, 'error' => 'Direktori tujuan tidak valid.'], 400);
        }

        if (is_dir($srcPath) && $this->isWithinBaseDir($targetDir, $srcPath) && $this->normalizePath($targetDir) !== $this->normalizePath(dirname($srcPath))) {
            Response::json(['success' => false, 'error' => 'Tidak dapat menyalin folder ke dalam sub-foldernya sendiri.'], 400);
        }

        $this->ensureWritable($targetDir, $site);
        $destPath = $this->buildCopyDestinationPath($targetDir, $srcPath);

        if (is_dir($srcPath)) {
            $this->recursiveCopy($srcPath, $destPath);
            if (!is_dir($destPath)) {
                Response::json(['success' => false, 'error' => 'Gagal menyalin folder.'], 500);
            }
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
        $srcRel = trim(str_replace('\\', '/', (string)($_POST['source'] ?? '')), '/');
        $destRel = trim(str_replace('\\', '/', (string)($_POST['destination'] ?? '')), '/');
        [$site, $baseDir] = $this->getSiteContext($siteId);

        $srcPath = $this->resolveSafePath($baseDir, $srcRel);
        if ($srcPath === null || !file_exists($srcPath)) {
            Response::json(['success' => false, 'error' => 'Berkas asal tidak ditemukan.'], 404);
        }

        $targetDir = $this->resolveSafePath($baseDir, $destRel);
        if ($targetDir === null || !is_dir($targetDir)) {
            Response::json(['success' => false, 'error' => 'Direktori tujuan tidak valid.'], 400);
        }

        if (is_dir($srcPath) && $this->isWithinBaseDir($targetDir, $srcPath)) {
            Response::json(['success' => false, 'error' => 'Tidak dapat memindahkan folder ke dalam dirinya sendiri.'], 400);
        }

        $baseName = basename($srcPath);
        $destPath = $targetDir . '/' . $baseName;

        if ($this->normalizePath($srcPath) === $this->normalizePath($destPath)) {
            Response::json(['success' => true, 'message' => "Item '{$baseName}' sudah berada di direktori ini."]);
        }

        if (file_exists($destPath)) {
            Response::json(['success' => false, 'error' => "Nama '{$baseName}' sudah ada di direktori tujuan."], 400);
        }

        $this->ensureWritable(dirname($srcPath), $site);
        $this->ensureWritable($targetDir, $site);

        if (!@rename($srcPath, $destPath)) {
            Response::json(['success' => false, 'error' => 'Gagal memindahkan file/folder.'], 500);
        }

        Response::json([
            'success' => true,
            'message' => "Berhasil memindahkan '{$baseName}'.",
        ]);
    }

    /**
     * Helper to create a zip archive from one or more source paths inside $commonParentDir.
     */
    private function createZipFromPaths(array $sourcePaths, string $zipPath): bool
    {
        if (empty($sourcePaths)) {
            return false;
        }

        if (file_exists($zipPath)) {
            @unlink($zipPath);
        }

        $success = false;
        $firstParent = dirname($sourcePaths[0]);
        $sameParent = true;
        foreach ($sourcePaths as $sp) {
            if ($this->normalizePath(dirname($sp)) !== $this->normalizePath($firstParent)) {
                $sameParent = false;
                break;
            }
        }

        // ENGINE 1: Try zip CLI command first if all items share a parent directory
        if ($sameParent && function_exists('exec')) {
            $zipBin = trim((string)@shell_exec('command -v zip 2>/dev/null'));
            if (!empty($zipBin) && is_executable($zipBin)) {
                $escapedNames = array_map(static fn($p) => escapeshellarg(basename($p)), $sourcePaths);
                $cmd = "cd " . escapeshellarg($firstParent) . " && " . escapeshellarg($zipBin) . " -r -q " . escapeshellarg($zipPath) . " " . implode(' ', $escapedNames) . " 2>&1";
                exec($cmd, $out, $ret);
                if ($ret === 0 && file_exists($zipPath)) {
                    $success = true;
                }
            }
        }

        // ENGINE 2: PHP ZipArchive (supports empty folders & multi-path compression)
        if (!$success && class_exists('ZipArchive')) {
            $zip = new \ZipArchive();
            if ($zip->open($zipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) === true) {
                $addedCount = 0;
                foreach ($sourcePaths as $sourcePath) {
                    $baseEntryName = basename($sourcePath);
                    if (is_dir($sourcePath)) {
                        $zip->addEmptyDir($baseEntryName);
                        $addedCount++;
                        $parentLen = strlen($this->normalizePath(dirname($sourcePath))) + 1;
                        $iterator = new \RecursiveIteratorIterator(
                            new \RecursiveDirectoryIterator($sourcePath, \FilesystemIterator::SKIP_DOTS),
                            \RecursiveIteratorIterator::SELF_FIRST
                        );
                        foreach ($iterator as $item) {
                            $itemReal = $item->getRealPath();
                            if ($itemReal === false) continue;
                            $relEntry = substr($this->normalizePath($itemReal), $parentLen);
                            if ($item->isDir()) {
                                $zip->addEmptyDir($relEntry);
                            } else {
                                $zip->addFile($itemReal, $relEntry);
                            }
                            $addedCount++;
                        }
                    } elseif (is_file($sourcePath)) {
                        $zip->addFile($sourcePath, $baseEntryName);
                        $addedCount++;
                    }
                }
                $zip->close();
                if ($addedCount > 0 && file_exists($zipPath)) {
                    $success = true;
                }
            }
        }

        // ENGINE 3: Python3 zipfile fallback
        if (!$success && function_exists('exec')) {
            $pyBin = trim((string)@shell_exec('command -v python3 || command -v python 2>/dev/null'));
            if (!empty($pyBin)) {
                $pyCode = 'import os, sys, zipfile; zf = zipfile.ZipFile(sys.argv[1], "w", zipfile.ZIP_DEFLATED); '
                    . 'items = sys.argv[2:]; '
                    . '[ (zf.write(p, os.path.basename(p)) if os.path.isfile(p) else [ (zf.write(os.path.join(r, f), os.path.relpath(os.path.join(r, f), os.path.dirname(p))) for f in files) if files else zf.writestr(os.path.relpath(r, os.path.dirname(p)).replace("\\\\", "/") + "/", "") for r, dirs, files in os.walk(p) ]) for p in items ]; '
                    . 'zf.close()';
                $escapedItems = array_map(static fn($p) => escapeshellarg($p), $sourcePaths);
                $cmd = escapeshellarg($pyBin) . " -c " . escapeshellarg($pyCode) . " " . escapeshellarg($zipPath) . " " . implode(' ', $escapedItems) . " 2>&1";
                exec($cmd, $pyOut, $pyRet);
                if ($pyRet === 0 && file_exists($zipPath)) {
                    $success = true;
                }
            }
        }

        if ($success && file_exists($zipPath)) {
            @chmod($zipPath, 0644);
            return true;
        }
        return false;
    }

    /**
     * API: Compress file or folder into .zip archive.
     */
    public function compressZip(): void
    {
        @set_time_limit(600);
        $siteId = (int)($_POST['site_id'] ?? 0);
        $itemRel = trim(str_replace('\\', '/', (string)($_POST['item'] ?? '')), '/');
        $rawItems = $_POST['items'] ?? null;
        $zipName = basename(trim((string)($_POST['zip_name'] ?? '')));
        [$site, $baseDir] = $this->getSiteContext($siteId);

        $sourcePaths = [];
        if (!empty($rawItems)) {
            $itemList = is_array($rawItems) ? $rawItems : explode(',', (string)$rawItems);
            foreach ($itemList as $rel) {
                $resolved = $this->resolveSafePath($baseDir, trim(str_replace('\\', '/', (string)$rel), '/'));
                if ($resolved !== null && file_exists($resolved)) {
                    $sourcePaths[] = $resolved;
                }
            }
        } elseif ($itemRel !== '') {
            $resolved = $this->resolveSafePath($baseDir, $itemRel);
            if ($resolved !== null && file_exists($resolved)) {
                $sourcePaths[] = $resolved;
            }
        }

        if (empty($sourcePaths)) {
            Response::json(['success' => false, 'error' => 'File atau folder yang akan dikompres tidak ditemukan.'], 404);
        }

        $firstSource = $sourcePaths[0];
        if ($zipName === '' || $zipName === '.' || $zipName === '..') {
            $zipName = (count($sourcePaths) === 1 ? basename($firstSource) : 'archive_' . date('Ymd_His')) . '.zip';
        } elseif (!str_ends_with(strtolower($zipName), '.zip')) {
            $zipName .= '.zip';
        }

        $destDir = dirname($firstSource);
        $this->ensureWritable($destDir, $site);
        $zipPath = $destDir . '/' . $zipName;

        if (!$this->createZipFromPaths($sourcePaths, $zipPath)) {
            Response::json(['success' => false, 'error' => 'Gagal membuat file arsip zip.'], 500);
        }

        Response::json([
            'success'  => true,
            'message'  => "Arsip '{$zipName}' berhasil dibuat.",
            'zip_file' => $zipName,
        ]);
    }

    /**
     * API: Batch action on multiple items (delete, copy, move, chmod, compress).
     */
    public function batchAction(): void
    {
        @set_time_limit(600);
        $siteId = (int)($_POST['site_id'] ?? 0);
        $action = trim((string)($_POST['action'] ?? ''));
        $rawItems = $_POST['items'] ?? [];
        $destRel = trim(str_replace('\\', '/', (string)($_POST['destination'] ?? '')), '/');
        $perms = trim((string)($_POST['perms'] ?? ''));
        $recursive = !empty($_POST['recursive']);
        [$site, $baseDir] = $this->getSiteContext($siteId);

        // Normalize items input (supports array, JSON array string, or comma-separated string)
        if (is_string($rawItems)) {
            $decoded = json_decode($rawItems, true);
            if (is_array($decoded)) {
                $items = $decoded;
            } else {
                $items = array_filter(array_map('trim', explode(',', $rawItems)), static fn($v) => $v !== '');
            }
        } elseif (is_array($rawItems)) {
            $items = $rawItems;
        } else {
            $items = [];
        }

        if (empty($items)) {
            Response::json(['success' => false, 'error' => 'Tidak ada item yang dipilih.'], 400);
        }

        // Batch compress shortcut
        if ($action === 'compress') {
            $_POST['items'] = $items;
            $this->compressZip();
            return;
        }

        // Resolve destination directory for copy & move (including root folder where $destRel === '')
        $targetDir = null;
        if ($action === 'copy' || $action === 'move') {
            $targetDir = $this->resolveSafePath($baseDir, $destRel);
            if ($targetDir === null || !is_dir($targetDir)) {
                Response::json(['success' => false, 'error' => 'Direktori tujuan tidak ditemukan atau tidak valid.'], 400);
            }
            $this->ensureWritable($targetDir, $site);
        }

        $processed = 0;
        $errors = [];

        foreach ($items as $rel) {
            $cleanRel = trim(str_replace('\\', '/', (string)$rel), '/');
            if ($cleanRel === '') {
                continue;
            }

            $path = $this->resolveSafePath($baseDir, $cleanRel);
            if ($path === null || !file_exists($path) || $this->normalizePath($path) === $this->normalizePath($baseDir)) {
                continue;
            }

            if ($action === 'delete') {
                $this->ensureWritable(dirname($path), $site);
                if (is_dir($path)) {
                    $this->recursiveRmdir($path);
                    if (file_exists($path)) {
                        $this->ensureWritable($path, $site);
                        $this->recursiveRmdir($path);
                    }
                } else {
                    if (!@unlink($path)) {
                        $this->applyChmodToPath($path, '0666', false, $site);
                        @unlink($path);
                    }
                }
                if (!file_exists($path)) {
                    $processed++;
                } else {
                    $errors[] = basename($path);
                }
            } elseif ($action === 'copy' && $targetDir) {
                if (is_dir($path) && $this->isWithinBaseDir($targetDir, $path) && $this->normalizePath($targetDir) !== $this->normalizePath(dirname($path))) {
                    $errors[] = basename($path) . ' (tidak dapat disalin ke dalam dirinya sendiri)';
                    continue;
                }
                $destPath = $this->buildCopyDestinationPath($targetDir, $path);
                if (is_dir($path)) {
                    $this->recursiveCopy($path, $destPath);
                    if (is_dir($destPath)) {
                        $processed++;
                    } else {
                        $errors[] = basename($path);
                    }
                } else {
                    if (@copy($path, $destPath)) {
                        @chmod($destPath, 0644);
                        $processed++;
                    } else {
                        $errors[] = basename($path);
                    }
                }
            } elseif ($action === 'move' && $targetDir) {
                if (is_dir($path) && $this->isWithinBaseDir($targetDir, $path)) {
                    $errors[] = basename($path) . ' (tidak dapat dipindahkan ke dalam dirinya sendiri)';
                    continue;
                }
                $destPath = $targetDir . '/' . basename($path);
                if ($this->normalizePath($path) === $this->normalizePath($destPath)) {
                    // Already in destination folder
                    $processed++;
                    continue;
                }
                if (file_exists($destPath)) {
                    $errors[] = basename($path) . ' (sudah ada di tujuan)';
                    continue;
                }
                $this->ensureWritable(dirname($path), $site);
                if (@rename($path, $destPath)) {
                    $processed++;
                } else {
                    $errors[] = basename($path);
                }
            } elseif ($action === 'chmod' && preg_match('/^0?[1-7][0-7]{2}$/', $perms)) {
                if ($this->applyChmodToPath($path, $perms, $recursive, $site)) {
                    $processed++;
                } else {
                    $errors[] = basename($path);
                }
            }
        }

        if ($processed === 0 && !empty($errors)) {
            Response::json([
                'success' => false,
                'error'   => 'Gagal memproses item: ' . implode(', ', $errors),
            ], 400);
        }

        $msg = "{$processed} item berhasil diproses ({$action}).";
        if (!empty($errors)) {
            $msg .= ' Dilewati/gagal: ' . implode(', ', $errors) . '.';
        }

        Response::json([
            'success'   => true,
            'message'   => $msg,
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
        $relPath = trim(str_replace('\\', '/', (string)($_POST['path'] ?? $_GET['path'] ?? '')), '/');
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
            if ($chunkIndex === 0 && file_exists($partPath)) {
                @unlink($partPath);
            }

            $in = @fopen($chunkFile['tmp_name'], 'rb');
            $out = @fopen($partPath, $chunkIndex === 0 ? 'wb' : 'ab');

            if (!$out) {
                $this->ensureWritable($targetDir, $site);
                if (file_exists($partPath)) {
                    $this->applyChmodToPath($partPath, '0666', false, $site);
                }
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
                    if (!@unlink($finalDest)) {
                        $this->applyChmodToPath($finalDest, '0666', false, $site);
                        @unlink($finalDest);
                    }
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
     * Inspects a zip archive prior to CLI extraction to prevent Zip-Slip directory traversal attacks.
     *
     * @return array{0: bool, 1: string} [isSafe, errorMessage]
     */
    private function verifyZipSafety(string $zipPath): array
    {
        if (class_exists('ZipArchive')) {
            $zip = new \ZipArchive();
            $res = $zip->open($zipPath, \ZipArchive::RDONLY);
            if ($res !== true) {
                $res = $zip->open($zipPath);
            }
            if ($res === true) {
                for ($i = 0; $i < $zip->numFiles; $i++) {
                    $stat = $zip->statIndex($i);
                    $entryName = str_replace('\\', '/', (string)($stat['name'] ?? ''));
                    if ($entryName === '') continue;
                    if (str_starts_with($entryName, '/') || preg_match('#(^|/)\.\.(/|$)#', $entryName)) {
                        $zip->close();
                        return [false, "Arsip ditolak karena mengandung path berbahaya (Zip-Slip): {$entryName}"];
                    }
                }
                $zip->close();
                return [true, ''];
            }
        }

        // Fallback inspection using unzip -Z1 if ZipArchive is unavailable
        if (function_exists('exec')) {
            $unzipBin = trim((string)@shell_exec('command -v unzip 2>/dev/null'));
            if (!empty($unzipBin) && is_executable($unzipBin)) {
                exec(escapeshellarg($unzipBin) . " -Z1 " . escapeshellarg($zipPath) . " 2>/dev/null", $entries, $ret);
                if ($ret === 0) {
                    foreach ($entries as $entry) {
                        $norm = str_replace('\\', '/', $entry);
                        if (str_starts_with($norm, '/') || preg_match('#(^|/)\.\.(/|$)#', $norm)) {
                            return [false, "Arsip ditolak karena mengandung path berbahaya (Zip-Slip): {$norm}"];
                        }
                    }
                }
            }
        }

        return [true, ''];
    }

    /**
     * API: Extract archive (.zip, .tar.gz, .tgz, .tar) safely and robustly.
     * Supports high-performance CLI unzip/tar with automated fallback to PHP ZipArchive and Python zipfile.
     */
    public function extractZip(): void
    {
        @set_time_limit(600);
        @ini_set('memory_limit', '512M');

        $siteId = (int)($_POST['site_id'] ?? $_GET['site_id'] ?? 0);
        $relZip = trim(str_replace('\\', '/', (string)($_POST['file'] ?? $_GET['file'] ?? '')), '/');
        $rawDest = trim(str_replace('\\', '/', (string)($_POST['destination'] ?? $_GET['destination'] ?? '')));
        [$site, $baseDir] = $this->getSiteContext($siteId);

        $zipPath = $this->resolveSafePath($baseDir, $relZip);
        if ($zipPath === null || !file_exists($zipPath) || is_dir($zipPath)) {
            Response::json(['success' => false, 'error' => 'File arsip tidak ditemukan atau akses ditolak.'], 404);
        }

        // Determine destination directory:
        // - If user typed "/" or ".", extract to root $baseDir
        // - If user left destination blank "", extract to the directory containing the archive
        // - Otherwise resolve the relative path within $baseDir
        if ($rawDest === '/' || $rawDest === '.') {
            $destDir = $baseDir;
        } elseif ($rawDest === '') {
            $destDir = dirname($zipPath);
        } else {
            $destDir = $this->resolveSafePath($baseDir, trim($rawDest, '/'));
        }

        if ($destDir === null) {
            Response::json(['success' => false, 'error' => 'Direktori tujuan ekstraksi tidak valid atau berada di luar folder website.'], 400);
        }

        if (!is_dir($destDir)) {
            @mkdir($destDir, 0755, true);
        }

        // Self-heal permissions on destination directory before extraction
        $this->ensureWritable($destDir, $site);

        $domain = !empty($site['is_root_storage']) ? 'wwwroot' : preg_replace('/[^a-zA-Z0-9\.\-]/', '', (string)$site['domain']);
        $script = defined('PANEL_ROOT') ? PANEL_ROOT . '/scripts/vhost_manager.sh' : dirname(__DIR__, 2) . '/scripts/vhost_manager.sh';

        $lowerName = strtolower(basename($zipPath));
        $isTar = str_ends_with($lowerName, '.tar.gz') || str_ends_with($lowerName, '.tgz') || str_ends_with($lowerName, '.tar');

        if ($isTar) {
            $tarBin = function_exists('exec') ? trim((string)@shell_exec('command -v tar 2>/dev/null')) : '';
            if (!empty($tarBin) && is_executable($tarBin)) {
                exec(escapeshellarg($tarBin) . " -tf " . escapeshellarg($zipPath) . " 2>&1", $listOut, $listRet);
                if ($listRet !== 0) {
                    Response::json(['success' => false, 'error' => 'File arsip tar tidak valid atau rusak.'], 400);
                }
                foreach ($listOut as $entry) {
                    $norm = str_replace('\\', '/', $entry);
                    if (str_starts_with($norm, '/') || preg_match('#(^|/)\.\.(/|$)#', $norm)) {
                        Response::json(['success' => false, 'error' => "Arsip ditolak karena mengandung path berbahaya: {$norm}"], 400);
                    }
                }
                exec(escapeshellarg($tarBin) . " -xf " . escapeshellarg($zipPath) . " -C " . escapeshellarg($destDir) . " 2>&1", $tarOut, $tarRet);
                if ($tarRet === 0) {
                    if (PHP_OS_FAMILY !== 'Windows' && is_file($script)) {
                        @exec("sudo " . escapeshellarg($script) . " fix_permissions " . escapeshellarg($domain ?: 'wwwroot') . " " . escapeshellarg($destDir) . " 2>&1");
                    }
                    Response::json([
                        'success'     => true,
                        'message'     => 'Arsip berhasil diekstrak dan seluruh permission berkas telah diperbarui.',
                        'destination' => $destDir,
                    ]);
                }
            }
            Response::json(['success' => false, 'error' => 'Gagal mengekstrak arsip tar.'], 400);
        }

        // Verify Zip-Slip safety before running any extraction engine
        [$isSafe, $safetyErr] = $this->verifyZipSafety($zipPath);
        if (!$isSafe) {
            Response::json(['success' => false, 'error' => $safetyErr], 400);
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

                    $normalizedEntry = str_replace('\\', '/', $entryName);
                    if (str_starts_with($normalizedEntry, '/') || preg_match('#(^|/)\.\.(/|$)#', $normalizedEntry)) {
                        continue;
                    }
                    $safeFiles[] = $entryName;
                }

                if (!empty($safeFiles)) {
                    if (@$zip->extractTo($destDir, $safeFiles)) {
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

        // ENGINE 3: Python Fallback (with Zip-Slip check per member)
        if (!$extractedSuccess && function_exists('exec')) {
            $pyBin = trim((string)@shell_exec('command -v python3 || command -v python 2>/dev/null'));
            if (!empty($pyBin)) {
                $pyScript = 'import os, sys, zipfile; z = zipfile.ZipFile(sys.argv[1]); dest = os.path.abspath(sys.argv[2]); '
                    . '[ ( (_ for _ in ()).throw(Exception("Zip-Slip terdeteksi")) if not os.path.abspath(os.path.join(dest, m)).startswith(dest) else None ) for m in z.namelist() ]; '
                    . 'z.extractall(dest)';
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
        if (PHP_OS_FAMILY !== 'Windows' && is_file($script)) {
            @exec("sudo " . escapeshellarg($script) . " fix_permissions " . escapeshellarg($domain ?: 'wwwroot') . " " . escapeshellarg($destDir) . " 2>&1");
        }

        Response::json([
            'success'     => true,
            'message'     => "Arsip zip berhasil diekstrak dan seluruh permission berkas telah diperbarui.",
            'destination' => $destDir,
        ]);
    }

    /**
     * API: Download a file directly with chunked streaming to prevent memory exhaustion.
     */
    public function downloadFile(): void
    {
        $siteId = (int)($_GET['site_id'] ?? 0);
        $relFile = trim(str_replace('\\', '/', (string)($_GET['file'] ?? '')), '/');
        [$site, $baseDir] = $this->getSiteContext($siteId);

        $fullPath = $this->resolveSafePath($baseDir, $relFile);
        if ($fullPath === null || !file_exists($fullPath) || is_dir($fullPath)) {
            Response::error(404, 'File tidak ditemukan.');
        }

        @set_time_limit(0);
        while (ob_get_level() > 0) {
            @ob_end_clean();
        }

        $filename = basename($fullPath);
        $safeQuoted = str_replace(['"', '\\', "\r", "\n"], '', $filename);
        $encodedName = rawurlencode($filename);
        $fileSize = (int)filesize($fullPath);

        header('Content-Description: File Transfer');
        header('Content-Type: application/octet-stream');
        header("Content-Disposition: attachment; filename=\"{$safeQuoted}\"; filename*=UTF-8''{$encodedName}");
        header('Expires: 0');
        header('Cache-Control: must-revalidate');
        header('Pragma: public');
        header('Content-Length: ' . $fileSize);

        $fp = @fopen($fullPath, 'rb');
        if ($fp) {
            while (!feof($fp)) {
                echo fread($fp, 65536);
                if (function_exists('flush')) {
                    @flush();
                }
            }
            fclose($fp);
        } else {
            readfile($fullPath);
        }
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
        clearstatcache(true, $dir);
        if (is_writable($dir)) {
            return;
        }

        if (PHP_OS_FAMILY !== 'Windows') {
            $domain = !empty($site['is_root_storage']) ? 'wwwroot' : preg_replace('/[^a-zA-Z0-9\.\-]/', '', (string)($site['domain'] ?? 'wwwroot'));
            $script = defined('PANEL_ROOT') ? PANEL_ROOT . '/scripts/vhost_manager.sh' : dirname(__DIR__, 2) . '/scripts/vhost_manager.sh';

            if (is_file($script)) {
                @exec("sudo " . escapeshellarg($script) . " fix_permissions " . escapeshellarg($domain ?: 'wwwroot') . " " . escapeshellarg($dir) . " 2>&1");
            }
        }

        @chmod($dir, 0775);
        clearstatcache(true, $dir);
    }

    /**
     * Helper to recursively delete directory contents.
     */
    private function recursiveRmdir(string $dir): void
    {
        if (!is_dir($dir)) return;
        $items = @scandir($dir);
        if ($items === false) return;

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') continue;
            $path = $dir . '/' . $item;
            if (is_dir($path) && !is_link($path)) {
                $this->recursiveRmdir($path);
            } else {
                @chmod($path, 0666);
                @unlink($path);
            }
        }
        @chmod($dir, 0777);
        @rmdir($dir);
    }

    /**
     * Helper to recursively copy directory contents.
     */
    private function recursiveCopy(string $src, string $dest): void
    {
        @mkdir($dest, 0755, true);
        $dir = @opendir($src);
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
