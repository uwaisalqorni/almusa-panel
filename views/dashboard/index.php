<?php
/**
 * Almusa Control Panel — Dashboard
 */
$level = static fn($p) => $p >= 90 ? ' is-danger' : ($p >= 75 ? ' is-warn' : '');
$calcOffset = static fn($p) => round(153.6 * (1 - (max(0, min(100, (float)$p)) / 100)), 1);
$strokeColor = static fn($p, $default = 'var(--accent)') => $p >= 90 ? 'var(--err)' : ($p >= 75 ? 'var(--warn)' : $default);

$cpuPct = (float)($stats['cpu']['percent'] ?? 0);
$cpuOffset = $calcOffset($cpuPct);
$cpuStroke = $strokeColor($cpuPct, 'var(--accent)');

$ramPct = (float)($stats['memory']['percent'] ?? 0);
$ramOffset = $calcOffset($ramPct);
$ramStroke = $strokeColor($ramPct, 'var(--info)');

$net = $stats['network'] ?? [];
$netRate = (float)($net['rx_rate'] ?? 0) + (float)($net['tx_rate'] ?? 0);
$netPct = min(100, max(0, round(($netRate / (1024 * 1024 * 5)) * 100)));
$netOffset = $calcOffset($netPct > 0 ? max(8, $netPct) : 0);
$netStroke = 'var(--ok)';

$mysql = $stats['mysql'] ?? [];
$mysqlActive = !empty($mysql['active']);
$mysqlPct = (float)($mysql['percent'] ?? 0);
$mysqlOffset = $mysqlActive ? $calcOffset($mysqlPct) : 153.6;
$mysqlStroke = $mysqlActive ? $strokeColor($mysqlPct, 'var(--warn)') : 'var(--text-3)';

$diskPct = (float)($stats['disk']['percent'] ?? 0);
?>

<header class="page-header">
    <div>
        <h1 class="page-title">Dashboard</h1>
        <p class="page-desc">Ringkasan kondisi server, layanan yang berjalan, dan website yang dikelola panel.</p>
    </div>
    <div class="page-actions">
        <a href="/sites" class="btn btn-primary"><?= ui_icon('plus') ?> Tambah website</a>
    </div>
</header>

<!-- Ringkasan -->
<section class="grid grid-stats" aria-label="Ringkasan">
    <a href="/sites" class="card stat">
        <div class="stat-head"><span class="stat-label">Website</span><?= ui_icon('globe', 'icon-lg') ?></div>
        <div class="stat-value"><?= (int)$sitesCount ?></div>
        <div class="stat-meta">Virtual host terdaftar</div>
    </a>
    <a href="/databases" class="card stat">
        <div class="stat-head"><span class="stat-label">Database</span><?= ui_icon('database', 'icon-lg') ?></div>
        <div class="stat-value"><?= (int)$dbCount ?></div>
        <div class="stat-meta">MySQL / MariaDB</div>
    </a>
    <a href="/tunnels" class="card stat">
        <div class="stat-head"><span class="stat-label">Tunnel aktif</span><?= ui_icon('cloud', 'icon-lg') ?></div>
        <div class="stat-value"><?= (int)$tunnelCount ?></div>
        <div class="stat-meta">Cloudflare Quick &amp; Zero Trust</div>
    </a>
    <a href="#terminal" class="card stat" onclick="openTaskTerminal(); return false;">
        <div class="stat-head"><span class="stat-label">Antrean tugas</span><?= ui_icon('terminal', 'icon-lg') ?></div>
        <div class="stat-value"><?= (int)$taskPending ?></div>
        <div class="stat-meta">Klik untuk membuka terminal</div>
    </a>
</section>

<div class="grid grid-main">
    <!-- Kolom kiri -->
    <div class="stack">
        <section class="card" aria-labelledby="resTitle">
            <div class="card-header">
                <div>
                    <h2 class="card-title" id="resTitle">Telemetri &amp; Sumber Daya Server</h2>
                    <p class="card-desc">Kecepatan &amp; metrik real-time diperbarui otomatis tiap 4 detik</p>
                </div>
                <div style="display: flex; gap: 8px; align-items: center;">
                    <span class="badge badge-neutral" style="font-size: 11px;">
                        <span class="dot dot-ok"></span> Live Stream
                    </span>
                </div>
            </div>
            <div class="card-body">
                <div class="gauge-grid">
                    <!-- 1. CPU Speedometer -->
                    <div class="gauge-card">
                        <div class="gauge-header">
                            <span class="gauge-title"><?= ui_icon('cpu') ?> CPU</span>
                            <span class="gauge-badge" id="gaugeCpuBadge"><?= e($stats['cpu']['cores'] ?? 1) ?> Cores</span>
                        </div>
                        <div class="gauge-svg-wrap">
                            <svg class="gauge-svg" viewBox="0 0 120 110">
                                <circle class="gauge-bg" cx="60" cy="55" r="40" stroke-dasharray="153.6 251.3" transform="rotate(160 60 55)" />
                                <circle class="gauge-meter" id="gaugeCpuMeter" cx="60" cy="55" r="40"
                                        stroke="<?= e($cpuStroke) ?>" stroke-dasharray="153.6 251.3" stroke-dashoffset="<?= e($cpuOffset) ?>"
                                        transform="rotate(160 60 55)" />
                            </svg>
                            <div class="gauge-center">
                                <div class="gauge-val" id="gaugeCpuVal"><?= e($cpuPct) ?>%</div>
                                <div class="gauge-sub">Penggunaan</div>
                            </div>
                        </div>
                        <div class="gauge-spark-wrap">
                            <svg class="gauge-spark" id="gaugeCpuSpark" viewBox="0 0 100 22" preserveAspectRatio="none">
                                <polygon points="" fill="var(--accent-soft)" />
                                <polyline points="" fill="none" stroke="var(--accent)" stroke-width="1.6" stroke-linecap="round" />
                            </svg>
                        </div>
                        <div class="gauge-foot">
                            <span>Load 1/5/15m:</span>
                            <strong><span id="statLoad1m"><?= e($stats['load']['1m'] ?? 0) ?></span> · <span id="statLoad5m"><?= e($stats['load']['5m'] ?? 0) ?></span> · <span id="statLoad15m"><?= e($stats['load']['15m'] ?? 0) ?></span></strong>
                        </div>
                    </div>

                    <!-- 2. RAM / Memori Speedometer -->
                    <div class="gauge-card">
                        <div class="gauge-header">
                            <span class="gauge-title"><?= ui_icon('memory') ?> RAM</span>
                            <span class="gauge-badge" id="gaugeRamBadge"><?= e($stats['memory']['free_mb'] ?? 0) ?> MB bebas</span>
                        </div>
                        <div class="gauge-svg-wrap">
                            <svg class="gauge-svg" viewBox="0 0 120 110">
                                <circle class="gauge-bg" cx="60" cy="55" r="40" stroke-dasharray="153.6 251.3" transform="rotate(160 60 55)" />
                                <circle class="gauge-meter" id="gaugeRamMeter" cx="60" cy="55" r="40"
                                        stroke="<?= e($ramStroke) ?>" stroke-dasharray="153.6 251.3" stroke-dashoffset="<?= e($ramOffset) ?>"
                                        transform="rotate(160 60 55)" />
                            </svg>
                            <div class="gauge-center">
                                <div class="gauge-val" id="gaugeRamVal"><?= e($ramPct) ?>%</div>
                                <div class="gauge-sub" id="gaugeRamSub"><?= e($stats['memory']['formatted'] ?? '') ?></div>
                            </div>
                        </div>
                        <div class="gauge-spark-wrap">
                            <svg class="gauge-spark" id="gaugeRamSpark" viewBox="0 0 100 22" preserveAspectRatio="none">
                                <polygon points="" fill="var(--info-soft)" />
                                <polyline points="" fill="none" stroke="var(--info)" stroke-width="1.6" stroke-linecap="round" />
                            </svg>
                        </div>
                        <div class="gauge-foot">
                            <span>Terpakai:</span>
                            <strong id="gaugeRamFoot"><?= e($stats['memory']['used_mb'] ?? 0) ?> MB / <?= e($stats['memory']['total_mb'] ?? 0) ?> MB</strong>
                        </div>
                    </div>

                    <!-- 3. Trafik Jaringan (Network) Speedometer -->
                    <div class="gauge-card">
                        <div class="gauge-header">
                            <span class="gauge-title"><?= ui_icon('activity') ?> Trafik Jaringan</span>
                            <span class="gauge-badge" style="color:var(--ok)">Live I/O</span>
                        </div>
                        <div class="gauge-svg-wrap">
                            <svg class="gauge-svg" viewBox="0 0 120 110">
                                <circle class="gauge-bg" cx="60" cy="55" r="40" stroke-dasharray="153.6 251.3" transform="rotate(160 60 55)" />
                                <circle class="gauge-meter" id="gaugeNetMeter" cx="60" cy="55" r="40"
                                        stroke="<?= e($netStroke) ?>" stroke-dasharray="153.6 251.3" stroke-dashoffset="<?= e($netOffset) ?>"
                                        transform="rotate(160 60 55)" />
                            </svg>
                            <div class="gauge-center">
                                <div class="gauge-val" id="gaugeNetVal" style="font-size:16px"><?= e($net['rx_formatted'] ?? '0 B/s') ?></div>
                                <div class="gauge-sub" id="gaugeNetSub">↑ <?= e($net['tx_formatted'] ?? '0 B/s') ?></div>
                            </div>
                        </div>
                        <div class="gauge-spark-wrap">
                            <svg class="gauge-spark" id="gaugeNetSpark" viewBox="0 0 100 22" preserveAspectRatio="none">
                                <polygon points="" fill="var(--ok-soft)" />
                                <polyline points="" fill="none" stroke="var(--ok)" stroke-width="1.6" stroke-linecap="round" />
                            </svg>
                        </div>
                        <div class="gauge-foot">
                            <span>Total Transfer:</span>
                            <strong id="gaugeNetTotal">RX: <?= e($net['rx_total'] ?? '0 B') ?> · TX: <?= e($net['tx_total'] ?? '0 B') ?></strong>
                        </div>
                    </div>

                    <!-- 4. MySQL / MariaDB Speedometer -->
                    <div class="gauge-card">
                        <div class="gauge-header">
                            <span class="gauge-title"><?= ui_icon('database') ?> MySQL / MariaDB</span>
                            <span class="gauge-badge" id="gaugeMysqlBadge"><?= $mysqlActive ? 'QPS ' . e($mysql['qps'] ?? 0) : 'Offline' ?></span>
                        </div>
                        <div class="gauge-svg-wrap">
                            <svg class="gauge-svg" viewBox="0 0 120 110">
                                <circle class="gauge-bg" cx="60" cy="55" r="40" stroke-dasharray="153.6 251.3" transform="rotate(160 60 55)" />
                                <circle class="gauge-meter" id="gaugeMysqlMeter" cx="60" cy="55" r="40"
                                        stroke="<?= e($mysqlStroke) ?>" stroke-dasharray="153.6 251.3" stroke-dashoffset="<?= e($mysqlOffset) ?>"
                                        transform="rotate(160 60 55)" />
                            </svg>
                            <div class="gauge-center">
                                <div class="gauge-val" id="gaugeMysqlVal" style="font-size:<?= $mysqlActive ? '17px' : '15px' ?>">
                                    <?= $mysqlActive ? e($mysql['connected'] ?? 0) . ' / ' . e($mysql['max_connections'] ?? 151) : 'Offline' ?>
                                </div>
                                <div class="gauge-sub" id="gaugeMysqlSub">
                                    <?= $mysqlActive ? 'Koneksi (' . e($mysqlPct) . '%)' : 'Service Nonaktif' ?>
                                </div>
                            </div>
                        </div>
                        <div class="gauge-spark-wrap">
                            <svg class="gauge-spark" id="gaugeMysqlSpark" viewBox="0 0 100 22" preserveAspectRatio="none">
                                <polygon points="" fill="var(--warn-soft)" />
                                <polyline points="" fill="none" stroke="var(--warn)" stroke-width="1.6" stroke-linecap="round" />
                            </svg>
                        </div>
                        <div class="gauge-foot">
                            <span>Status:</span>
                            <strong id="gaugeMysqlFoot"><?= $mysqlActive ? 'Uptime ' . e($mysql['uptime'] ?? '') . ' · Slow: ' . e($mysql['slow_queries'] ?? 0) : 'MariaDB tidak berjalan' ?></strong>
                        </div>
                    </div>
                </div>

                <!-- Compact Disk Bar Row -->
                <div style="padding-top: 14px; border-top: 1px solid var(--border)">
                    <div class="meter-head" style="margin-bottom:6px">
                        <span class="meter-label"><?= ui_icon('disk') ?> Penyimpanan Root (/)</span>
                        <span class="meter-value" id="statDiskPercent" style="font-size:15px"><?= e($stats['disk']['percent']) ?>%</span>
                    </div>
                    <div class="meter-track" style="height:7px;">
                        <div class="meter-fill<?= $level($diskPct) ?>" id="statDiskFill" style="width: <?= e($diskPct) ?>%"></div>
                    </div>
                    <div class="meter-foot" style="margin-top:6px">
                        <span id="statDiskFormatted"><?= e($stats['disk']['formatted'] ?? '') ?></span>
                        <span id="statDiskFree"><?= e($stats['disk']['free_gb'] ?? '') ?> GB bebas</span>
                    </div>
                </div>
            </div>
        </section>

        <section class="card card-flush" aria-labelledby="svcTitle">
            <div class="card-header">
                <div>
                    <div style="display: flex; align-items: center; gap: 8px;">
                        <h2 class="card-title" id="svcTitle">Layanan server</h2>
                        <span class="badge badge-success" style="font-size: 10.5px; padding: 2px 7px;"><span class="dot dot-ok"></span> Auto-Heal Aktif</span>
                    </div>
                    <p class="card-desc">Status layanan inti yang dipantau & dipulihkan otomatis oleh Watchdog</p>
                </div>
                <div style="display: flex; gap: 8px; align-items: center;">
                    <a href="/watchdog" class="btn btn-secondary btn-sm" title="Pantau Service Watchdog & Riwayat Auto-Heal">
                        <?= ui_icon('activity') ?> Watchdog
                    </a>
                    <a href="/plugins" class="btn btn-secondary btn-sm">Kelola</a>
                </div>
            </div>
            <div class="card-body">
                <div class="rows">
                    <div class="row" style="cursor: pointer;" onclick="openNginxModal()" title="Klik untuk mengelola konfigurasi Nginx Global">
                        <span class="icon-tile"><?= ui_icon('server') ?></span>
                        <div class="row-main">
                            <div class="row-title">Nginx</div>
                            <div class="row-sub">Web server · port 8088</div>
                        </div>
                        <?php if ($services['nginx']): ?>
                            <span class="badge badge-success">Berjalan</span>
                        <?php else: ?>
                            <span class="badge badge-neutral">Nonaktif</span>
                        <?php endif; ?>
                    </div>
                    <div class="row">
                        <span class="icon-tile"><?= ui_icon('database') ?></span>
                        <div class="row-main">
                            <div class="row-title">MySQL / MariaDB</div>
                            <div class="row-sub">Database · port 3306</div>
                        </div>
                        <?php if ($services['mysql']): ?>
                            <span class="badge badge-success">Berjalan</span>
                        <?php else: ?>
                            <span class="badge badge-neutral">Nonaktif</span>
                        <?php endif; ?>
                    </div>
                    <div class="row">
                        <span class="icon-tile"><?= ui_icon('cloud') ?></span>
                        <div class="row-main">
                            <div class="row-title">Cloudflare Tunnel</div>
                            <div class="row-sub">Zero Trust &amp; Quick Tunnel</div>
                        </div>
                        <?php if ($services['cloudflared']): ?>
                            <span class="badge badge-success">Berjalan</span>
                        <?php else: ?>
                            <span class="badge badge-neutral">Siap</span>
                        <?php endif; ?>
                    </div>
                </div>

                <div style="padding: 14px 18px 18px; border-top: 1px solid var(--border)">
                    <div class="text-3" style="font-size:12px; font-weight:600; margin-bottom:10px">PHP-FPM</div>
                    <div class="chips">
                        <?php foreach ($phpStatus as $ver => $active): ?>
                            <span class="chip<?= $active ? ' on' : '' ?>" title="<?= $active ? 'Aktif' : 'Belum terpasang' ?>">
                                <span class="dot<?= $active ? ' dot-ok' : '' ?>"></span> PHP <?= e($ver) ?>
                            </span>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        </section>
    </div>

    <!-- Kolom kanan -->
    <div class="stack">
        <section class="card" aria-labelledby="sysTitle">
            <div class="card-header">
                <h2 class="card-title" id="sysTitle">Informasi sistem</h2>
            </div>
            <div class="card-body">
                <dl class="kv">
                    <div class="kv-row"><dt class="kv-key">Uptime</dt><dd class="kv-val"><?= e($stats['uptime']['formatted']) ?></dd></div>
                    <div class="kv-row">
                        <dt class="kv-key">Load average</dt>
                        <dd class="kv-val">
                            <span id="statLoad1m"><?= e($stats['load']['1m']) ?></span> ·
                            <span id="statLoad5m"><?= e($stats['load']['5m']) ?></span> ·
                            <span id="statLoad15m"><?= e($stats['load']['15m']) ?></span>
                        </dd>
                    </div>
                    <div class="kv-row"><dt class="kv-key">Sistem operasi</dt><dd class="kv-val"><?= e($stats['os']['os']) ?></dd></div>
                    <div class="kv-row"><dt class="kv-key">Kernel</dt><dd class="kv-val"><?= e($stats['os']['kernel']) ?></dd></div>
                    <div class="kv-row"><dt class="kv-key">Memori bebas</dt><dd class="kv-val"><?= e($stats['memory']['free_mb']) ?> MB</dd></div>
                    <div class="kv-row"><dt class="kv-key">Disk tersedia</dt><dd class="kv-val"><?= e($stats['disk']['free_gb']) ?> GB</dd></div>
                </dl>
            </div>
        </section>

        <section class="card card-flush" aria-labelledby="qaTitle">
            <div class="card-header"><h2 class="card-title" id="qaTitle">Aksi cepat</h2></div>
            <div class="card-body">
                <div class="rows">
                    <a href="/sites" class="row">
                        <span class="icon-tile"><?= ui_icon('globe') ?></span>
                        <div class="row-main"><div class="row-title">Tambah website</div><div class="row-sub">Buat virtual host baru</div></div>
                    </a>
                    <a href="/databases" class="row">
                        <span class="icon-tile"><?= ui_icon('database') ?></span>
                        <div class="row-main"><div class="row-title">Buat database</div><div class="row-sub">Database &amp; user MySQL</div></div>
                    </a>
                    <a href="/tunnels" class="row">
                        <span class="icon-tile"><?= ui_icon('cloud') ?></span>
                        <div class="row-main"><div class="row-title">Publikasikan online</div><div class="row-sub">Cloudflare Tunnel</div></div>
                    </a>
                </div>
            </div>
        </section>
    </div>
</div>

<!-- Website terbaru -->
<section class="card card-flush" style="margin-top: 16px" aria-labelledby="recentTitle">
    <div class="card-header">
        <h2 class="card-title" id="recentTitle">Website terbaru</h2>
        <a href="/sites" class="btn btn-secondary btn-sm">Lihat semua</a>
    </div>
    <div class="card-body">
        <div class="table-wrap">
            <table class="table">
                <thead>
                    <tr>
                        <th>Domain</th>
                        <th>PHP</th>
                        <th>URL publik</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($recentSites)): ?>
                        <tr>
                            <td colspan="4">
                                <div class="empty" style="padding: 32px 16px">
                                    <div class="empty-title">Belum ada website</div>
                                    <p class="empty-text" style="margin-bottom:0">Tambahkan website pertama Anda dari menu Websites.</p>
                                </div>
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($recentSites as $site): ?>
                            <tr>
                                <td>
                                    <div class="cell-title"><?= e($site['domain']) ?></div>
                                    <div class="cell-sub"><?= e($site['docroot']) ?></div>
                                </td>
                                <td><span class="badge badge-info no-dot">PHP <?= e($site['php_version']) ?></span></td>
                                <td>
                                    <?php if (!empty($site['tunnel_url'])): ?>
                                        <a href="<?= e($site['tunnel_url']) ?>" target="_blank" rel="noopener" class="cell-link">
                                            <span class="truncate"><?= e(preg_replace('#^https?://#', '', $site['tunnel_url'])) ?></span>
                                            <?= ui_icon('external') ?>
                                        </a>
                                    <?php else: ?>
                                        <span class="text-3">—</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span class="badge <?= $site['status'] === 'active' ? 'badge-success' : 'badge-neutral' ?>">
                                        <?= e($site['status'] === 'active' ? 'Aktif' : ucfirst($site['status'])) ?>
                                    </span>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</section>
