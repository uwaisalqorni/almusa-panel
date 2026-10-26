<?php
use App\Core\Auth;
use App\Core\Database;

$panelTitle = Database::getSetting('panel_title', 'Almusa Server Panel');
$currentUri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$user = Auth::user();

$nav = [
    'Utama' => [
        ['/dashboard', 'Dashboard', 'dashboard'],
        ['/sites', 'Websites', 'globe'],
        ['/sites/files', 'File Manager', 'folder'],
        ['/databases', 'Databases', 'database'],
    ],
    'Ekstensi & jaringan' => [
        ['/plugins', 'App Store', 'package'],
        ['/tunnels', 'Cloudflare Tunnel', 'cloud'],
    ],
    'Konfigurasi' => [
        ['/settings', 'Pengaturan', 'sliders'],
    ],
];

$isActive = static function (string $href) use ($currentUri): bool {
    if ($href === '/dashboard') {
        return $currentUri === '/dashboard' || $currentUri === '/';
    }
    if ($href === '/sites') {
        return $currentUri === '/sites';
    }
    return str_starts_with($currentUri, $href);
};
?>
<!DOCTYPE html>
<html lang="id" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?= e(Auth::token()) ?>">
    <meta name="robots" content="noindex, nofollow">
    <title><?= e($title ?? 'Dashboard') ?> · <?= e($panelTitle) ?></title>
    <link rel="icon" type="image/svg+xml" href="/assets/favicon.svg">
    <link rel="alternate icon" href="/favicon.ico">
    <script src="<?= asset_url('/assets/js/theme.js') ?>"></script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= asset_url('/assets/css/panel.css') ?>">
</head>
<body>
<?php require __DIR__ . '/_icons.php'; ?>

<div class="app">
    <aside class="sidebar" id="sidebar" aria-label="Navigasi utama">
        <div class="sidebar-brand">
            <div class="brand-mark"><?= ui_icon('layers') ?></div>
            <div>
                <div class="brand-name">Almusa Panel</div>
                <div class="brand-sub">SLEMP Edition</div>
            </div>
        </div>

        <nav class="nav">
            <?php foreach ($nav as $section => $items): ?>
                <div class="nav-section"><?= e($section) ?></div>
                <?php foreach ($items as [$href, $label, $ico]): ?>
                    <a href="<?= e($href) ?>" class="nav-item<?= $isActive($href) ? ' active' : '' ?>"<?= $isActive($href) ? ' aria-current="page"' : '' ?>>
                        <?= ui_icon($ico) ?>
                        <span><?= e($label) ?></span>
                    </a>
                <?php endforeach; ?>
            <?php endforeach; ?>
        </nav>

        <div class="sidebar-footer">
            <div class="host-card">
                <?= ui_icon('server') ?>
                <div style="min-width:0">
                    <div class="host-name truncate"><?= e(gethostname() ?: 'server') ?></div>
                    <div class="host-meta"><span class="dot dot-ok"></span> Server aktif</div>
                </div>
            </div>
        </div>
    </aside>
    <div class="scrim" aria-hidden="true"></div>

    <div class="main">
        <header class="topbar">
            <div class="topbar-left">
                <button type="button" class="icon-btn menu-toggle" onclick="toggleSidebar()" aria-label="Buka menu">
                    <?= ui_icon('menu', 'icon-lg') ?>
                </button>
                <nav class="crumbs" aria-label="Breadcrumb">
                    <span>Almusa</span>
                    <span class="sep">/</span>
                    <strong class="truncate"><?= e($title ?? 'Dashboard') ?></strong>
                </nav>
            </div>

            <div class="topbar-right">
                <div class="vitals" title="Penggunaan sumber daya server">
                    <div class="vital">
                        <span>CPU</span>
                        <div class="meter-track"><div class="meter-fill" id="headerCpuFill"></div></div>
                        <b id="headerCpuVal">--%</b>
                    </div>
                    <div class="vital">
                        <span>RAM</span>
                        <div class="meter-track"><div class="meter-fill" id="headerRamFill"></div></div>
                        <b id="headerRamVal">--%</b>
                    </div>
                </div>

                <button type="button" class="term-btn" id="taskBellBtn" onclick="openTaskTerminal()">
                    <?= ui_icon('terminal') ?>
                    <span class="term-label">Terminal</span>
                    <span id="runningTaskSpinner" class="spinner" style="display:none"></span>
                    <span id="pendingTaskBadge" class="term-count" style="display:none">0</span>
                </button>

                <button type="button" class="icon-btn" onclick="toggleTheme()" aria-label="Ganti tema" title="Ganti tema terang / gelap">
                    <span data-show="dark"><?= ui_icon('sun', 'icon-lg') ?></span>
                    <span data-show="light"><?= ui_icon('moon', 'icon-lg') ?></span>
                </button>

                <div class="user-chip">
                    <div class="avatar"><?= e(substr($user['username'] ?? 'A', 0, 1)) ?></div>
                    <span class="user-name"><?= e($user['username'] ?? 'Admin') ?></span>
                </div>

                <a href="/logout" class="icon-btn" aria-label="Keluar" title="Keluar">
                    <?= ui_icon('logout', 'icon-lg') ?>
                </a>
            </div>
        </header>

        <main class="content">
            <?= $content ?>
        </main>
    </div>
</div>

<!-- Terminal eksekusi -->
<div class="modal-backdrop" id="taskTerminalModal">
    <div class="terminal-modal" role="dialog" aria-modal="true" aria-labelledby="terminalTaskTitle">
        <div class="terminal-head">
            <div class="terminal-title">
                <?= ui_icon('terminal') ?>
                <span id="terminalTaskTitle">Terminal eksekusi</span>
                <span id="terminalStatusBadge" class="badge badge-neutral">Idle</span>
            </div>
            <button type="button" class="icon-btn" onclick="closeTaskTerminal()" aria-label="Tutup terminal" style="color:#a5afbd">
                <?= ui_icon('x', 'icon-lg') ?>
            </button>
        </div>

        <div class="terminal-body" id="terminalLogBody">Menghubungkan ke daemon…</div>

        <div class="terminal-foot">
            <span class="text-3">Sumber: <code style="background:#181d26;border-color:#2d3441;color:#a5afbd">data/panelExec.log</code></span>
            <div style="display:flex;gap:8px">
                <button type="button" class="btn btn-secondary btn-sm" id="btnToggleScroll" onclick="toggleAutoScroll()" aria-pressed="true">Auto-scroll: aktif</button>
                <button type="button" class="btn btn-secondary btn-sm" onclick="clearTerminalLog()">Kosongkan log</button>
                <button type="button" class="btn btn-primary btn-sm" onclick="closeTaskTerminal()">Tutup</button>
            </div>
        </div>
    </div>
</div>

<script src="<?= asset_url('/assets/js/panel.js') ?>"></script>
</body>
</html>
