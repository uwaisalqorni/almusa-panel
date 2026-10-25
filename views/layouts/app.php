<?php
use App\Core\Auth;
use App\Core\Database;

$panelTitle = Database::getSetting('panel_title', 'Almusa Server Panel');
$currentUri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$user = Auth::user();
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?= htmlspecialchars(Auth::token()) ?>">
    <title><?= htmlspecialchars($title ?? 'Dashboard') ?> - <?= htmlspecialchars($panelTitle) ?></title>
    <link rel="stylesheet" href="/assets/css/panel.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Plus Jakarta Sans', -apple-system, sans-serif; }
    </style>
</head>
<body>

<div class="panel-wrapper">
    <!-- SIDEBAR -->
    <aside class="sidebar">
        <div class="sidebar-brand">
            <div class="sidebar-logo-icon">A</div>
            <div class="sidebar-brand-text">
                <h2>Almusa Panel</h2>
                <span>SLEMP Edition</span>
            </div>
        </div>

        <nav class="sidebar-menu">
            <div class="menu-category">Menu Utama</div>

            <a href="/dashboard" class="nav-item <?= ($currentUri === '/dashboard' || $currentUri === '/') ? 'active' : '' ?>">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor">
                    <rect x="3" y="3" width="7" height="7" rx="1.5"></rect>
                    <rect x="14" y="3" width="7" height="7" rx="1.5"></rect>
                    <rect x="14" y="14" width="7" height="7" rx="1.5"></rect>
                    <rect x="3" y="14" width="7" height="7" rx="1.5"></rect>
                </svg>
                <span>Dashboard</span>
            </a>

            <a href="/sites" class="nav-item <?= (str_starts_with($currentUri, '/sites')) ? 'active' : '' ?>">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor">
                    <circle cx="12" cy="12" r="10"></circle>
                    <line x1="2" y1="12" x2="22" y2="12"></line>
                    <path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"></path>
                </svg>
                <span>Websites</span>
            </a>

            <a href="/databases" class="nav-item <?= (str_starts_with($currentUri, '/databases')) ? 'active' : '' ?>">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor">
                    <ellipse cx="12" cy="5" rx="9" ry="3"></ellipse>
                    <path d="M21 12c0 1.66-4 3-9 3s-9-1.34-9-3"></path>
                    <path d="M3 5v14c0 1.66 4 3 9 3s9-1.34 9-3V5"></path>
                </svg>
                <span>Databases</span>
            </a>

            <div class="menu-category">Ekstensi & Jaringan</div>

            <a href="/plugins" class="nav-item <?= (str_starts_with($currentUri, '/plugins')) ? 'active' : '' ?>">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor">
                    <path d="M16.5 9.4 7.55 4.24a1.78 1.78 0 0 0-2.5 1.55v12.42a1.78 1.78 0 0 0 2.5 1.55L16.5 14.6a1.78 1.78 0 0 0 0-3.2z"></path>
                    <polyline points="21 5 12 12 21 19"></polyline>
                </svg>
                <span>App Store / Plugins</span>
            </a>

            <a href="/tunnels" class="nav-item <?= (str_starts_with($currentUri, '/tunnels')) ? 'active' : '' ?>">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor">
                    <path d="M17.5 19H9a7 7 0 1 1 6.71-9h1.79a4.5 4.5 0 1 1 0 9Z"></path>
                </svg>
                <span>Cloudflare Tunnel</span>
            </a>

            <div class="menu-category">Konfigurasi</div>

            <a href="/settings" class="nav-item <?= (str_starts_with($currentUri, '/settings')) ? 'active' : '' ?>">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor">
                    <circle cx="12" cy="12" r="3"></circle>
                    <path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1 0 2.83 2 2 0 0 1-2.83 0l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-2 2 2 2 0 0 1-2-2v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83 0 2 2 0 0 1 0-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1-2-2 2 2 0 0 1 2-2h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 0-2.83 2 2 0 0 1 2.83 0l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 2-2 2 2 0 0 1 2 2v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 0 2 2 0 0 1 0 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 2 2 2 2 0 0 1-2 2h-.09a1.65 1.65 0 0 0-1.51 1z"></path>
                </svg>
                <span>Pengaturan Panel</span>
            </a>
        </nav>

        <div class="sidebar-footer">
            <div class="system-status-pill">
                <span class="status-dot pulsing"></span>
                <span>System Operational</span>
            </div>
        </div>
    </aside>

    <!-- MAIN CONTENT -->
    <div class="main-content">
        <!-- TOP HEADER -->
        <header class="top-header">
            <div class="header-left">
                <h1 class="page-title"><?= htmlspecialchars($title ?? 'Dashboard') ?></h1>

                <div class="header-metrics-bar">
                    <div class="metric-pill">
                        <span class="metric-label">CPU</span>
                        <div class="metric-bar-micro">
                            <div class="metric-bar-fill" id="statCpuFill" style="width: 0%;"></div>
                        </div>
                        <span id="headerCpuVal">--%</span>
                    </div>

                    <div class="metric-pill">
                        <span class="metric-label">RAM</span>
                        <div class="metric-bar-micro">
                            <div class="metric-bar-fill" id="statRamFill" style="width: 0%;"></div>
                        </div>
                        <span id="headerRamVal">--%</span>
                    </div>
                </div>
            </div>

            <div class="header-right">
                <!-- Task Terminal Bell Button -->
                <button type="button" class="task-bell-btn" id="taskBellBtn" onclick="openTaskTerminal()">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <polyline points="4 17 10 11 4 5"></polyline>
                        <line x1="12" y1="19" x2="20" y2="19"></line>
                    </svg>
                    <span>Terminal</span>
                    <span id="runningTaskSpinner" class="badge-spinner" style="display: none;"></span>
                    <span id="pendingTaskBadge" class="nav-badge" style="display: none;">0</span>
                </button>

                <!-- User Profile -->
                <div class="user-profile-badge">
                    <div class="avatar-circle"><?= strtoupper(substr($user['username'] ?? 'A', 0, 1)) ?></div>
                    <span><?= htmlspecialchars($user['username'] ?? 'Admin') ?></span>
                </div>

                <!-- Logout -->
                <a href="/logout" class="logout-link" title="Keluar">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"></path>
                        <polyline points="16 17 21 12 16 7"></polyline>
                        <line x1="21" y1="12" x2="9" y2="12"></line>
                    </svg>
                </a>
            </div>
        </header>

        <!-- PAGE CONTENT CONTAINER -->
        <main class="content-body">
            <?= $content ?>
        </main>
    </div>
</div>

<!-- REAL-TIME TERMINAL LOG MODAL -->
<div class="modal-backdrop" id="taskTerminalModal">
    <div class="modal-terminal-card">
        <div class="modal-terminal-header">
            <div class="terminal-header-title">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#10b981" stroke-width="2">
                    <polyline points="4 17 10 11 4 5"></polyline>
                    <line x1="12" y1="19" x2="20" y2="19"></line>
                </svg>
                <span id="terminalTaskTitle">Panel Terminal Log</span>
                <span id="terminalStatusBadge" class="badge badge-neutral">Idle</span>
            </div>
            <button type="button" class="btn btn-secondary btn-sm" onclick="closeTaskTerminal()">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <line x1="18" y1="6" x2="6" y2="18"></line>
                    <line x1="6" y1="6" x2="18" y2="18"></line>
                </svg>
            </button>
        </div>

        <div class="terminal-body" id="terminalLogBody">Menghubungkan ke terminal daemon...</div>

        <div class="modal-terminal-footer">
            <div style="font-size: 11px; color: var(--text-muted);">
                Streaming dari <code style="color: #60a5fa;">/tmp/panelExec.log</code>
            </div>
            <div style="display: flex; gap: 8px;">
                <button type="button" class="btn btn-secondary btn-sm" id="btnToggleScroll" onclick="toggleAutoScroll()">
                    Auto-scroll: ON
                </button>
                <button type="button" class="btn btn-secondary btn-sm" onclick="clearTerminalLog()">
                    Kosongkan Log
                </button>
                <button type="button" class="btn btn-primary btn-sm" onclick="closeTaskTerminal()">
                    Tutup
                </button>
            </div>
        </div>
    </div>
</div>

<script src="/assets/js/panel.js"></script>
</body>
</html>
