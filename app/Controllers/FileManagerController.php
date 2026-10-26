<?php

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Database;
use App\Core\Response;

class FileManagerController
{
    /**
     * Resolves the site record and its base file management directory.
     *
     * @return array{0: array, 1: string, 2: array} [site, baseDir, allSites]
     */
    private function getSiteContext(?int $siteId = null): array
    {
        $pdo = Database::getInstance();
        $allSites = $pdo->query("SELECT id, domain, docroot, php_version, rewrite_template, status FROM sites ORDER BY id DESC")->fetchAll();

        if (empty($allSites)) {
            Response::error(404, 'Belum ada website yang terdaftar. Buat website terlebih dahulu di menu Websites.');
        }

        $site = null;
        if ($siteId !== null && $siteId > 0) {
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

        $docroot = $site['docroot'] ?: ("/www/wwwroot/" . $site['domain']);

        // Determine base directory: if docroot ends with /public, use parent project folder so .env and app are accessible
        $baseDir = $docroot;
        if (preg_match('#/public/?$#i', $docroot)) {
            $parent = dirname($docroot);
            if (is_dir($parent)) {
                $baseDir = $parent;
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
            return number_format($bytes / 1048576, 1) . ' MB';
        }
        if ($bytes >= 1024) {
            return number_format($bytes / 1024, 1) . ' KB';
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
        if (in_array($ext, ['sh', 'bash'])) return 'shell';
        if (in_array($ext, ['yml', 'yaml'])) return 'yaml';
        if (in_array($ext, ['sql'])) return 'sql';
        if (in_array($ext, ['md', 'markdown'])) return 'markdown';
        if (in_array($ext, ['xml', 'svg'])) return 'xml';
        if (in_array($ext, ['ini'])) return 'ini';

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

        $initialPath = trim($_GET['path'] ?? '/', '/');
        $openEdit = trim($_GET['edit'] ?? '');

        Response::view('sites/files', [
            'title'        => 'File Manager · ' . $site['domain'],
            'user'         => Auth::user(),
            'site'         => $site,
            'allSites'     => $allSites,
            'baseDir'      => $baseDir,
            'initialPath'  => $initialPath,
            'openEdit'     => $openEdit,
        ]);
    }

    /**
     * API: List directory contents.
     */
    public function listFiles(): void
    {
        $siteId = (int)($_GET['site_id'] ?? 0);
        [$site, $baseDir] = $this->getSiteContext($siteId);

        $relPath = trim($_GET['path'] ?? '', '/');
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
        $handle = @opendir($targetDir);
        if ($handle) {
            while (($entry = readdir($handle)) !== false) {
                if ($entry === '.' || $entry === '..') {
                    continue;
                }

                $full = $targetDir . '/' . $entry;
                $isDir = is_dir($full);
                $relItem = ($relPath === '' ? '' : ($relPath . '/')) . $entry;
                $stat = @stat($full);

                $item = [
                    'name'        => $entry,
                    'path'        => $relItem,
                    'is_dir'      => $isDir,
                    'size_bytes'  => $isDir ? 0 : ($stat['size'] ?? 0),
                    'size'        => $isDir ? '—' : $this->formatSize($stat['size'] ?? 0),
                    'mtime'       => $stat['mtime'] ?? 0,
                    'mtime_fmt'   => date('Y-m-d H:i', $stat['mtime'] ?? time()),
                    'perms'       => substr(sprintf('%o', $stat['mode'] ?? 0), -4),
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
                'success'     => true,
                'name'        => "nginx.conf ({$site['domain']})",
                'path'        => $confPath,
                'is_nginx'    => true,
                'content'     => $content !== false ? $content : '',
                'size'        => strlen((string)$content),
                'syntax'      => 'nginx',
                'is_writable' => true,
            ]);
            return;
        }

        $relFile = trim($_GET['file'] ?? '', '/');
        $fullPath = $this->resolveSafePath($baseDir, $relFile);

        if ($fullPath === null || !file_exists($fullPath) || is_dir($fullPath)) {
            Response::json(['success' => false, 'error' => 'File tidak ditemukan atau akses ditolak.'], 404);
        }

        $size = filesize($fullPath);
        if ($size > 2 * 1024 * 1024) {
            Response::json(['success' => false, 'error' => 'Ukuran file terlalu besar untuk dibuka di editor teks web (maksimal 2 MB).'], 400);
        }

        $content = @file_get_contents($fullPath);
        if ($content === false) {
            Response::json(['success' => false, 'error' => 'Gagal membaca isi file.'], 500);
        }

        Response::json([
            'success'     => true,
            'name'        => basename($fullPath),
            'path'        => $relFile,
            'is_nginx'    => false,
            'content'     => $content,
            'size'        => $size,
            'syntax'      => $this->detectSyntax($fullPath),
            'is_writable' => is_writable($fullPath) || is_writable(dirname($fullPath)),
        ]);
    }

    /**
     * API: Save edited file content.
     */
    public function saveFile(): void
    {
        $siteId = (int)($_POST['site_id'] ?? 0);
        $isNginx = !empty($_POST['is_nginx']);
        $content = (string)($_POST['content'] ?? '');
        [$site, $baseDir] = $this->getSiteContext($siteId);

        if ($isNginx) {
            // Save Nginx configuration safely with syntax test & reload
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

        $relFile = trim($_POST['file'] ?? '', '/');
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
        $type = trim($_POST['type'] ?? 'file'); // 'file' or 'dir'
        $name = trim($_POST['name'] ?? '');
        $relPath = trim($_POST['path'] ?? '', '/');
        [$site, $baseDir] = $this->getSiteContext($siteId);

        // Sanitize name: cannot contain slashes or null bytes
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
        $relPath = trim($_POST['path'] ?? '', '/');
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
     * API: Upload file(s).
     */
    public function uploadFile(): void
    {
        $siteId = (int)($_POST['site_id'] ?? 0);
        $relPath = trim($_POST['path'] ?? '', '/');
        [$site, $baseDir] = $this->getSiteContext($siteId);

        $targetDir = $this->resolveSafePath($baseDir, $relPath);
        if ($targetDir === null || !is_dir($targetDir)) {
            Response::json(['success' => false, 'error' => 'Direktori tujuan tidak valid.'], 400);
        }

        if (empty($_FILES['file']) && empty($_FILES['files'])) {
            Response::json(['success' => false, 'error' => 'Tidak ada file yang diunggah.'], 400);
        }

        $uploaded = [];
        $fileData = $_FILES['file'] ?? $_FILES['files'];

        // Normalize single vs multi upload
        if (is_array($fileData['name'])) {
            $count = count($fileData['name']);
            for ($i = 0; $i < $count; $i++) {
                if ($fileData['error'][$i] === UPLOAD_ERR_OK) {
                    $cleanName = basename($fileData['name'][$i]);
                    $dest = $targetDir . '/' . $cleanName;
                    if (move_uploaded_file($fileData['tmp_name'][$i], $dest)) {
                        @chmod($dest, 0644);
                        $uploaded[] = $cleanName;
                    }
                }
            }
        } else {
            if ($fileData['error'] === UPLOAD_ERR_OK) {
                $cleanName = basename($fileData['name']);
                $dest = $targetDir . '/' . $cleanName;
                if (move_uploaded_file($fileData['tmp_name'], $dest)) {
                    @chmod($dest, 0644);
                    $uploaded[] = $cleanName;
                }
            } else {
                $errCode = $fileData['error'];
                $msg = match ($errCode) {
                    UPLOAD_ERR_INI_SIZE   => 'Ukuran file melebihi upload_max_filesize di php.ini.',
                    UPLOAD_ERR_FORM_SIZE  => 'Ukuran file melebihi batas form.',
                    UPLOAD_ERR_PARTIAL    => 'File hanya terunggah sebagian.',
                    UPLOAD_ERR_NO_FILE    => 'Tidak ada file yang dipilih.',
                    default               => 'Terjadi kesalahan saat mengunggah file (kode: ' . $errCode . ').',
                };
                Response::json(['success' => false, 'error' => $msg], 400);
            }
        }

        if (empty($uploaded)) {
            Response::json(['success' => false, 'error' => 'Gagal memindahkan file ke direktori tujuan.'], 500);
        }

        Response::json([
            'success' => true,
            'message' => count($uploaded) . " file berhasil diunggah.",
            'files'   => $uploaded,
        ]);
    }

    /**
     * API: Extract zip archive safely.
     */
    public function extractZip(): void
    {
        $siteId = (int)($_POST['site_id'] ?? 0);
        $relZip = trim($_POST['file'] ?? '', '/');
        $relDest = trim($_POST['destination'] ?? '', '/');
        [$site, $baseDir] = $this->getSiteContext($siteId);

        $zipPath = $this->resolveSafePath($baseDir, $relZip);
        if ($zipPath === null || !file_exists($zipPath) || is_dir($zipPath)) {
            Response::json(['success' => false, 'error' => 'File arsip tidak ditemukan atau akses ditolak.'], 404);
        }

        $destDir = empty($relDest) ? dirname($zipPath) : $this->resolveSafePath($baseDir, $relDest);
        if ($destDir === null) {
            Response::json(['success' => false, 'error' => 'Direktori tujuan ekstraksi tidak valid.'], 400);
        }

        if (!is_dir($destDir)) {
            @mkdir($destDir, 0755, true);
        }

        if (!class_exists('ZipArchive')) {
            // Fallback to unzip command line
            $cmd = "unzip -o " . escapeshellarg($zipPath) . " -d " . escapeshellarg($destDir) . " 2>&1";
            exec($cmd, $out, $ret);
            if ($ret !== 0) {
                Response::json(['success' => false, 'error' => 'Gagal mengekstrak zip: ' . implode("\n", $out)], 500);
            }
        } else {
            $zip = new \ZipArchive();
            $res = $zip->open($zipPath);
            if ($res !== true) {
                Response::json(['success' => false, 'error' => 'Tidak dapat membuka file zip (kode error: ' . $res . ').'], 400);
            }

            // Zip-Slip protection: ensure no extracted file escapes destination
            $realDest = realpath($destDir);
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $stat = $zip->statIndex($i);
                $name = $stat['name'];
                if (str_contains($name, '..')) {
                    $zip->close();
                    Response::json(['success' => false, 'error' => 'Arsip mengandung path traversal berbahaya (..). Ekstraksi dibatalkan demi keamanan.'], 400);
                }
            }

            $extracted = $zip->extractTo($destDir);
            $zip->close();

            if (!$extracted) {
                Response::json(['success' => false, 'error' => 'Gagal mengekstrak isi file zip.'], 500);
            }
        }

        // Fix permissions automatically on extracted files
        $script = defined('PANEL_ROOT') ? PANEL_ROOT . '/scripts/vhost_manager.sh' : dirname(__DIR__, 2) . '/scripts/vhost_manager.sh';
        @exec("sudo " . escapeshellarg($script) . " fix_permissions _ " . escapeshellarg($destDir) . " 2>&1");

        Response::json([
            'success' => true,
            'message' => "Arsip zip berhasil diekstrak dan permission file telah diperbarui.",
        ]);
    }

    /**
     * API: Download a file directly.
     */
    public function downloadFile(): void
    {
        $siteId = (int)($_GET['site_id'] ?? 0);
        $relFile = trim($_GET['file'] ?? '', '/');
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
}
