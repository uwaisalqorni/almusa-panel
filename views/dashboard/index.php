<?php
/**
 * Almusa Control Panel - Dashboard Overview
 */
?>

<!-- TOP STAT CARDS -->
<div class="card-grid-4">
    <div class="card stat-card">
        <div class="stat-icon blue">
            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <circle cx="12" cy="12" r="10"></circle>
                <line x1="2" y1="12" x2="22" y2="12"></line>
                <path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"></path>
            </svg>
        </div>
        <div class="stat-info">
            <h3><?= $sitesCount ?></h3>
            <p>Websites Aktif</p>
        </div>
    </div>

    <div class="card stat-card">
        <div class="stat-icon emerald">
            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <ellipse cx="12" cy="5" rx="9" ry="3"></ellipse>
                <path d="M21 12c0 1.66-4 3-9 3s-9-1.34-9-3"></path>
                <path d="M3 5v14c0 1.66 4 3 9 3s9-1.34 9-3V5"></path>
            </svg>
        </div>
        <div class="stat-info">
            <h3><?= $dbCount ?></h3>
            <p>Database MySQL</p>
        </div>
    </div>

    <div class="card stat-card">
        <div class="stat-icon purple">
            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <path d="M17.5 19H9a7 7 0 1 1 6.71-9h1.79a4.5 4.5 0 1 1 0 9Z"></path>
            </svg>
        </div>
        <div class="stat-info">
            <h3><?= $tunnelCount ?></h3>
            <p>Cloudflare Tunnels</p>
        </div>
    </div>

    <div class="card stat-card" style="cursor: pointer;" onclick="openTaskTerminal()">
        <div class="stat-icon amber">
            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <polyline points="4 17 10 11 4 5"></polyline>
                <line x1="12" y1="19" x2="20" y2="19"></line>
            </svg>
        </div>
        <div class="stat-info">
            <h3><?= $taskPending ?></h3>
            <p>Task Antrean</p>
        </div>
    </div>
</div>

<!-- HARDWARE MONITOR GAUGES -->
<div class="gauges-grid">
    <!-- CPU -->
    <div class="gauge-card">
        <div class="gauge-header">
            <div class="gauge-title">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <rect x="4" y="4" width="16" height="16" rx="2" ry="2"></rect>
                    <rect x="9" y="9" width="6" height="6"></rect>
                    <line x1="9" y1="1" x2="9" y2="4"></line>
                    <line x1="15" y1="1" x2="15" y2="4"></line>
                    <line x1="9" y1="20" x2="9" y2="23"></line>
                    <line x1="15" y1="20" x2="15" y2="23"></line>
                    <line x1="20" y1="9" x2="23" y2="9"></line>
                    <line x1="20" y1="14" x2="23" y2="14"></line>
                    <line x1="1" y1="9" x2="4" y2="9"></line>
                    <line x1="1" y1="14" x2="4" y2="14"></line>
                </svg>
                <span>CPU Usage</span>
            </div>
            <div class="gauge-val" id="statCpuPercent"><?= $stats['cpu']['percent'] ?>%</div>
        </div>
        <div class="gauge-progress-bar">
            <div class="gauge-fill <?= ($stats['cpu']['percent'] > 85 ? 'warning' : '') ?>" id="statCpuFill" style="width: <?= $stats['cpu']['percent'] ?>%;"></div>
        </div>
        <div class="gauge-subtext">
            <span>Processor Cores: <?= $stats['cpu']['cores'] ?> Core(s)</span>
            <span>Real-time /proc/stat</span>
        </div>
    </div>

    <!-- RAM -->
    <div class="gauge-card">
        <div class="gauge-header">
            <div class="gauge-title">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M6 19v-3"></path>
                    <path d="M10 19v-3"></path>
                    <path d="M14 19v-3"></path>
                    <path d="M18 19v-3"></path>
                    <path d="M6 8V5"></path>
                    <path d="M10 8V5"></path>
                    <path d="M14 8V5"></path>
                    <path d="M18 8V5"></path>
                    <rect x="2" y="8" width="20" height="8" rx="2"></rect>
                </svg>
                <span>Memory (RAM)</span>
            </div>
            <div class="gauge-val" id="statRamPercent"><?= $stats['memory']['percent'] ?>%</div>
        </div>
        <div class="gauge-progress-bar">
            <div class="gauge-fill <?= ($stats['memory']['percent'] > 85 ? 'warning' : '') ?>" id="statRamFill" style="width: <?= $stats['memory']['percent'] ?>%;"></div>
        </div>
        <div class="gauge-subtext">
            <span id="statRamSub"><?= $stats['memory']['formatted'] ?? '' ?></span>
            <span><?= $stats['memory']['free_mb'] ?> MB Bebas</span>
        </div>
    </div>

    <!-- DISK -->
    <div class="gauge-card">
        <div class="gauge-header">
            <div class="gauge-title">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <line x1="22" y1="12" x2="2" y2="12"></line>
                    <path d="M5.45 5.11 2 12v6a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2v-6l-3.45-6.89A2 2 0 0 0 16.76 4H7.24a2 2 0 0 0-1.79 1.11z"></path>
                    <line x1="6" y1="16" x2="6.01" y2="16"></line>
                    <line x1="10" y1="16" x2="10.01" y2="16"></line>
                </svg>
                <span>Penyimpanan Disk (/)</span>
            </div>
            <div class="gauge-val" id="statDiskPercent"><?= $stats['disk']['percent'] ?>%</div>
        </div>
        <div class="gauge-progress-bar">
            <div class="gauge-fill <?= ($stats['disk']['percent'] > 90 ? 'warning' : '') ?>" id="statDiskFill" style="width: <?= $stats['disk']['percent'] ?>%;"></div>
        </div>
        <div class="gauge-subtext">
            <span><?= $stats['disk']['formatted'] ?? '' ?></span>
            <span><?= $stats['disk']['free_gb'] ?> GB Tersedia</span>
        </div>
    </div>

    <!-- LOAD AVERAGE & UPTIME -->
    <div class="gauge-card">
        <div class="gauge-header">
            <div class="gauge-title">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <circle cx="12" cy="12" r="10"></circle>
                    <polyline points="12 6 12 12 16 14"></polyline>
                </svg>
                <span>Load & Uptime</span>
            </div>
            <div class="gauge-val" style="font-size: 16px;"><?= htmlspecialchars($stats['uptime']['formatted']) ?></div>
        </div>
        <div style="margin: 12px 0 14px; display: flex; gap: 8px;">
            <div style="flex: 1; background: var(--bg-surface); padding: 8px 12px; border-radius: var(--radius-md); text-align: center;">
                <div style="font-size: 11px; color: var(--text-muted);">1 Menit</div>
                <div style="font-weight: 700; font-size: 14px;" id="statLoad1m"><?= $stats['load']['1m'] ?></div>
            </div>
            <div style="flex: 1; background: var(--bg-surface); padding: 8px 12px; border-radius: var(--radius-md); text-align: center;">
                <div style="font-size: 11px; color: var(--text-muted);">5 Menit</div>
                <div style="font-weight: 700; font-size: 14px;" id="statLoad5m"><?= $stats['load']['5m'] ?></div>
            </div>
            <div style="flex: 1; background: var(--bg-surface); padding: 8px 12px; border-radius: var(--radius-md); text-align: center;">
                <div style="font-size: 11px; color: var(--text-muted);">15 Menit</div>
                <div style="font-weight: 700; font-size: 14px;" id="statLoad15m"><?= $stats['load']['15m'] ?></div>
            </div>
        </div>
        <div class="gauge-subtext">
            <span>OS: <?= htmlspecialchars($stats['os']['os']) ?></span>
            <span>Kernel: <?= htmlspecialchars($stats['os']['kernel']) ?></span>
        </div>
    </div>
</div>

<!-- SERVER CORE SERVICES STATUS & QUICK ACTIONS -->
<div style="display: grid; grid-template-columns: 2fr 1fr; gap: 20px; margin-bottom: 24px;">
    <!-- CORE SERVICES -->
    <div class="card">
        <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 18px;">
            <h3 style="font-size: 16px; font-weight: 700; color: #ffffff;">Status Layanan Server</h3>
            <a href="/plugins" class="btn btn-secondary btn-sm">Kelola di App Store</a>
        </div>

        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 14px;">
            <!-- Nginx -->
            <div style="background: var(--bg-surface); border: 1px solid var(--border-color); padding: 14px; border-radius: var(--radius-md); display: flex; align-items: center; justify-content: space-between;">
                <div>
                    <div style="font-weight: 600; color: #ffffff;">Nginx Web Server</div>
                    <div style="font-size: 11px; color: var(--text-muted);">Port 80, 443</div>
                </div>
                <?php if ($services['nginx']): ?>
                    <span class="badge badge-success">Running</span>
                <?php else: ?>
                    <span class="badge badge-neutral">Inactive</span>
                <?php endif; ?>
            </div>

            <!-- MySQL -->
            <div style="background: var(--bg-surface); border: 1px solid var(--border-color); padding: 14px; border-radius: var(--radius-md); display: flex; align-items: center; justify-content: space-between;">
                <div>
                    <div style="font-weight: 600; color: #ffffff;">MySQL / MariaDB</div>
                    <div style="font-size: 11px; color: var(--text-muted);">Port 3306</div>
                </div>
                <?php if ($services['mysql']): ?>
                    <span class="badge badge-success">Running</span>
                <?php else: ?>
                    <span class="badge badge-neutral">Inactive</span>
                <?php endif; ?>
            </div>

            <!-- Cloudflared -->
            <div style="background: var(--bg-surface); border: 1px solid var(--border-color); padding: 14px; border-radius: var(--radius-md); display: flex; align-items: center; justify-content: space-between;">
                <div>
                    <div style="font-weight: 600; color: #ffffff;">Cloudflare Tunnel</div>
                    <div style="font-size: 11px; color: var(--text-muted);">Zero Trust / Quick</div>
                </div>
                <?php if ($services['cloudflared']): ?>
                    <span class="badge badge-success">Running</span>
                <?php else: ?>
                    <span class="badge badge-neutral">Ready</span>
                <?php endif; ?>
            </div>
        </div>

        <!-- PHP-FPM Pool Status -->
        <div style="margin-top: 18px; padding-top: 16px; border-top: 1px solid var(--border-color);">
            <div style="font-size: 12px; font-weight: 600; color: var(--text-muted); text-transform: uppercase; margin-bottom: 10px;">
                PHP-FPM Versi Multi-Pool
            </div>
            <div style="display: flex; flex-wrap: wrap; gap: 8px;">
                <?php foreach ($phpStatus as $ver => $active): ?>
                    <div style="background: var(--bg-surface); border: 1px solid var(--border-color); padding: 6px 12px; border-radius: var(--radius-md); display: flex; align-items: center; gap: 8px; font-size: 12px;">
                        <span style="font-weight: 600; color: #ffffff;">PHP <?= $ver ?></span>
                        <?php if ($active): ?>
                            <span class="status-dot" style="background-color: var(--success); box-shadow: 0 0 6px var(--success);"></span>
                        <?php else: ?>
                            <span class="status-dot" style="background-color: var(--text-muted); box-shadow: none;"></span>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    <!-- QUICK ACTIONS -->
    <div class="card">
        <h3 style="font-size: 16px; font-weight: 700; color: #ffffff; margin-bottom: 16px;">Aksi Cepat</h3>
        <div style="display: flex; flex-direction: column; gap: 10px;">
            <a href="/sites" class="btn btn-primary" style="justify-content: flex-start;">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <line x1="12" y1="5" x2="12" y2="19"></line>
                    <line x1="5" y1="12" x2="19" y2="12"></line>
                </svg>
                Tambah Website Baru
            </a>

            <a href="/databases" class="btn btn-secondary" style="justify-content: flex-start;">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <ellipse cx="12" cy="5" rx="9" ry="3"></ellipse>
                    <path d="M21 12c0 1.66-4 3-9 3s-9-1.34-9-3"></path>
                    <path d="M3 5v14c0 1.66 4 3 9 3s9-1.34 9-3V5"></path>
                </svg>
                Buat Database & User
            </a>

            <button type="button" class="btn btn-secondary" style="justify-content: flex-start;" onclick="openTaskTerminal()">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <polyline points="4 17 10 11 4 5"></polyline>
                    <line x1="12" y1="19" x2="20" y2="19"></line>
                </svg>
                Buka Terminal Eksekusi
            </button>

            <a href="/tunnels" class="btn btn-secondary" style="justify-content: flex-start;">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M17.5 19H9a7 7 0 1 1 6.71-9h1.79a4.5 4.5 0 1 1 0 9Z"></path>
                </svg>
                Cloudflare Quick Tunnel
            </a>
        </div>
    </div>
</div>

<!-- RECENT SITES TABLE -->
<div class="table-container">
    <div class="table-header">
        <h3 class="table-title">Daftar Website Terdaftar</h3>
        <a href="/sites" class="btn btn-secondary btn-sm">Lihat Semua</a>
    </div>

    <table class="custom-table">
        <thead>
            <tr>
                <th>Domain</th>
                <th>Docroot</th>
                <th>PHP Version</th>
                <th>Tunnel URL</th>
                <th>Status</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($recentSites)): ?>
                <tr>
                    <td colspan="5" style="text-align: center; padding: 32px; color: var(--text-muted);">
                        Belum ada website yang ditambahkan. Klik <strong>Tambah Website Baru</strong> untuk memulai.
                    </td>
                </tr>
            <?php else: ?>
                <?php foreach ($recentSites as $site): ?>
                    <tr>
                        <td>
                            <strong style="color: #ffffff;"><?= htmlspecialchars($site['domain']) ?></strong>
                        </td>
                        <td><code><?= htmlspecialchars($site['docroot']) ?></code></td>
                        <td><span class="badge badge-info">PHP <?= htmlspecialchars($site['php_version']) ?></span></td>
                        <td>
                            <?php if (!empty($site['tunnel_url'])): ?>
                                <a href="<?= htmlspecialchars($site['tunnel_url']) ?>" target="_blank" style="color: #38bdf8;">
                                    <?= htmlspecialchars($site['tunnel_url']) ?> ↗
                                </a>
                            <?php else: ?>
                                <span style="color: var(--text-muted);">None</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <span class="badge <?= ($site['status'] === 'active' ? 'badge-success' : 'badge-neutral') ?>">
                                <?= htmlspecialchars(ucfirst($site['status'])) ?>
                            </span>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
</div>
