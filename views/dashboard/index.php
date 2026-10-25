<?php
/**
 * Almusa Control Panel — Dashboard
 */
$level = static fn($p) => $p >= 90 ? ' is-danger' : ($p >= 75 ? ' is-warn' : '');
$cpu  = (float)$stats['cpu']['percent'];
$ram  = (float)$stats['memory']['percent'];
$disk = (float)$stats['disk']['percent'];
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
                    <h2 class="card-title" id="resTitle">Penggunaan sumber daya</h2>
                    <p class="card-desc">Diperbarui otomatis setiap 4 detik</p>
                </div>
            </div>
            <div class="card-body">
                <div class="meters">
                    <div>
                        <div class="meter-head">
                            <span class="meter-label"><?= ui_icon('cpu') ?> CPU</span>
                            <span class="meter-value" id="statCpuPercent"><?= e($stats['cpu']['percent']) ?>%</span>
                        </div>
                        <div class="meter-track"><div class="meter-fill<?= $level($cpu) ?>" id="statCpuFill" style="width: <?= e($cpu) ?>%"></div></div>
                        <div class="meter-foot"><span><?= e($stats['cpu']['cores']) ?> core</span></div>
                    </div>
                    <div>
                        <div class="meter-head">
                            <span class="meter-label"><?= ui_icon('memory') ?> Memori</span>
                            <span class="meter-value" id="statRamPercent"><?= e($stats['memory']['percent']) ?>%</span>
                        </div>
                        <div class="meter-track"><div class="meter-fill<?= $level($ram) ?>" id="statRamFill" style="width: <?= e($ram) ?>%"></div></div>
                        <div class="meter-foot"><span id="statRamSub"><?= e($stats['memory']['formatted'] ?? '') ?></span></div>
                    </div>
                    <div>
                        <div class="meter-head">
                            <span class="meter-label"><?= ui_icon('disk') ?> Disk /</span>
                            <span class="meter-value" id="statDiskPercent"><?= e($stats['disk']['percent']) ?>%</span>
                        </div>
                        <div class="meter-track"><div class="meter-fill<?= $level($disk) ?>" id="statDiskFill" style="width: <?= e($disk) ?>%"></div></div>
                        <div class="meter-foot"><span><?= e($stats['disk']['formatted'] ?? '') ?></span></div>
                    </div>
                </div>
            </div>
        </section>

        <section class="card card-flush" aria-labelledby="svcTitle">
            <div class="card-header">
                <div>
                    <h2 class="card-title" id="svcTitle">Layanan server</h2>
                    <p class="card-desc">Status layanan inti yang dikelola panel</p>
                </div>
                <a href="/plugins" class="btn btn-secondary btn-sm">Kelola</a>
            </div>
            <div class="card-body">
                <div class="rows">
                    <div class="row">
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
