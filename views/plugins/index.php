<?php
/**
 * Almusa Control Panel - App Store & Plugins View
 */

/** Badge status layanan */
$statusBadge = static function (array $p, bool $hasActive = true): string {
    if ($hasActive && !empty($p['active'])) return '<span class="badge badge-success">Berjalan</span>';
    if (!empty($p['installed']))            return $hasActive
        ? '<span class="badge badge-warning">Terpasang</span>'
        : '<span class="badge badge-success">Siap</span>';
    return '<span class="badge badge-neutral">Belum terpasang</span>';
};

$services = [
    [
        'key'       => 'nginx',
        'icon'      => 'server',
        'name'      => 'Nginx',
        'tag'       => 'Web server & reverse proxy',
        'desc'      => 'Web server berperforma tinggi dengan isolasi virtual host, aturan rewrite, dan HTTP/2.',
        'version'   => $plugins['nginx']['version'] ?? null,
        'badge'     => $statusBadge($plugins['nginx']),
        'installed' => $plugins['nginx']['installed'],
        'active'    => $plugins['nginx']['active'],
        'install'   => 'Pasang Nginx',
        'open'      => null,
    ],
    [
        'key'       => 'mysql',
        'icon'      => 'database',
        'name'      => 'MySQL / MariaDB',
        'tag'       => 'Database relasional',
        'desc'      => 'Mesin database dengan charset utf8mb4 bawaan, manajemen user, dan backup dump.',
        'version'   => $plugins['mysql']['version'] ?? null,
        'badge'     => $statusBadge($plugins['mysql']),
        'installed' => $plugins['mysql']['installed'],
        'active'    => $plugins['mysql']['active'],
        'install'   => 'Pasang MariaDB',
        'open'      => ['/databases', 'Buka Databases'],
    ],
    [
        'key'       => 'cloudflared',
        'icon'      => 'cloud',
        'name'      => 'Cloudflare Tunnel',
        'tag'       => 'Akses online tanpa buka port',
        'desc'      => 'Binary cloudflared untuk Quick Tunnel (trycloudflare.com) dan domain sendiri via Zero Trust.',
        'version'   => $plugins['cloudflared']['version'] ?? null,
        'badge'     => $statusBadge($plugins['cloudflared'], false),
        'installed' => $plugins['cloudflared']['installed'],
        'active'    => $plugins['cloudflared']['active'],
        'install'   => 'Unduh cloudflared',
        'open'      => ['/tunnels', 'Kelola Tunnel'],
    ],
];
?>

<header class="page-header">
    <div>
        <h1 class="page-title">App Store</h1>
        <p class="page-desc">Pasang dan kelola web server, versi PHP, database, serta utilitas server.</p>
    </div>
    <div class="page-actions">
        <button type="button" class="btn btn-secondary" onclick="openTaskTerminal()">
            <?= ui_icon('terminal') ?> Buka terminal
        </button>
    </div>
</header>

<section class="grid grid-cards mb" aria-label="Layanan inti">
    <?php foreach ($services as $s): ?>
        <article class="card">
            <div>
                <div class="row" style="padding:0;border:0">
                    <span class="icon-tile"><?= ui_icon($s['icon']) ?></span>
                    <div class="row-main">
                        <div style="display:flex; align-items:center; gap:8px; flex-wrap:wrap;">
                            <h2 class="row-title" style="margin:0;"><?= e($s['name']) ?></h2>
                            <?php if (!empty($s['version'])): ?>
                                <span class="badge badge-neutral mono" style="font-size:11px; padding:2px 7px;">v<?= e($s['version']) ?></span>
                            <?php endif; ?>
                        </div>
                        <div class="row-sub"><?= e($s['tag']) ?></div>
                    </div>
                    <?= $s['badge'] ?>
                </div>
                <p class="text-2" style="font-size:13px;margin:14px 0 16px"><?= e($s['desc']) ?></p>
            </div>
            <div class="cell-actions" style="justify-content:flex-start; flex-wrap:wrap; gap:6px;">
                <?php if (!$s['installed']): ?>
                    <button type="button" class="btn btn-primary btn-sm" onclick="queuePluginAction('install', '<?= e($s['key']) ?>', '')">
                        <?= ui_icon('download') ?> <?= e($s['install']) ?>
                    </button>
                <?php else: ?>
                    <?php if ($s['active']): ?>
                        <button type="button" class="btn btn-secondary btn-sm" title="Restart Service" onclick="controlService('<?= e($s['key']) ?>', 'restart')">
                            <?= ui_icon('refresh') ?> Restart
                        </button>
                        <button type="button" class="btn btn-ghost btn-sm" style="color:var(--err);" title="Hentikan Service" onclick="controlService('<?= e($s['key']) ?>', 'stop')">
                            <?= ui_icon('stop') ?> Stop
                        </button>
                    <?php else: ?>
                        <button type="button" class="btn btn-primary btn-sm" title="Jalankan Service" onclick="controlService('<?= e($s['key']) ?>', 'start')">
                            <?= ui_icon('play') ?> Start
                        </button>
                    <?php endif; ?>

                    <?php if ($s['key'] === 'nginx'): ?>
                        <button type="button" class="btn btn-secondary btn-sm" onclick="openNginxModal()">
                            <?= ui_icon('sliders') ?> Kelola Nginx
                        </button>
                    <?php endif; ?>
                    <?php if ($s['open']): ?>
                        <a href="<?= e($s['open'][0]) ?>" class="btn btn-secondary btn-sm"><?= e($s['open'][1]) ?></a>
                    <?php endif; ?>
                    <button type="button" class="btn btn-ghost btn-sm text-dim" title="Pasang ulang paket" onclick="queuePluginAction('install', '<?= e($s['key']) ?>', '')">
                        Reinstall
                    </button>
                <?php endif; ?>
            </div>
        </article>
    <?php endforeach; ?>
</section>

<section class="card card-flush">
    <div class="card-header" style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:12px;">
        <div>
            <h2 class="card-title" style="display:flex; align-items:center; gap:8px;">
                <?= ui_icon('shield') ?> Versi PHP &amp; Mitigasi PHP2Shell
            </h2>
            <p class="card-desc">Setiap versi berjalan pada socket PHP-FPM terisolasi dengan proteksi sandbox <code>open_basedir</code>, pemblokiran fungsi eksekusi sistem, dan verifikasi patch APT (CVE-2026-109618 &amp; CVE-2026-109619).</p>
        </div>
        <div>
            <button type="button" class="btn btn-secondary btn-sm" id="btnCheckPhpUpdates" onclick="checkAllPhpUpdates()">
                <?= ui_icon('refresh') ?> Cek Update Patch APT
            </button>
        </div>
    </div>
    <div class="card-body">
        <div class="table-wrap">
            <table class="table">
                <thead>
                    <tr>
                        <th>Versi</th>
                        <th>Socket FPM</th>
                        <th>Status Layanan</th>
                        <th>Mitigasi PHP2Shell &amp; Patch</th>
                        <th class="th-right">Aksi</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($phpList as $ver => $info): ?>
                    <tr>
                        <td>
                            <div class="cell-title" style="display:flex; align-items:center; gap:6px;">
                                <strong>PHP <?= e($ver) ?></strong>
                                <?php if ($info['installed']): ?>
                                    <span class="badge badge-neutral mono" style="font-size:10.5px;"><?= e(substr($info['installed_version'] ?: 'installed', 0, 16)) ?></span>
                                <?php endif; ?>
                            </div>
                        </td>
                        <td><code>/run/php/php<?= e($ver) ?>-fpm.sock</code></td>
                        <td>
                            <?php if ($info['active']): ?>
                                <span class="badge badge-success">Berjalan</span>
                            <?php elseif ($info['installed']): ?>
                                <span class="badge badge-warning">Berhenti</span>
                            <?php else: ?>
                                <span class="badge badge-neutral">Belum terpasang</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if (!$info['installed']): ?>
                                <span class="badge badge-neutral">—</span>
                            <?php elseif (!empty($info['update_required'])): ?>
                                <span class="badge badge-danger" title="Candidate: <?= e($info['candidate_version']) ?>">
                                    <?= ui_icon('alert-triangle') ?> CVE-2026-109618 / CVE-2026-109619 Patch: Update Required
                                </span>
                            <?php else: ?>
                                <span class="badge badge-success" title="Version: <?= e($info['installed_version']) ?>">
                                    <?= ui_icon('shield') ?> Patched &amp; Hardened
                                </span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <div class="cell-actions">
                                <?php if (!$info['installed']): ?>
                                    <button type="button" class="btn btn-primary btn-sm" onclick="queuePluginAction('install', 'php', <?= js_arg($ver) ?>)">
                                        <?= ui_icon('download') ?> Pasang
                                    </button>
                                <?php else: ?>
                                    <?php if (!empty($info['update_required'])): ?>
                                        <button type="button" class="btn btn-primary btn-sm" style="background:var(--danger, #ef4444); border-color:var(--danger, #ef4444);" onclick="queuePluginAction('update', 'php', <?= js_arg($ver) ?>)">
                                            <?= ui_icon('shield') ?> 1-Click Patch PHP
                                        </button>
                                    <?php else: ?>
                                        <button type="button" class="btn btn-secondary btn-sm" title="Paket sudah versi terbaru. Klik untuk menjalankan update ulang." onclick="queuePluginAction('update', 'php', <?= js_arg($ver) ?>)">
                                            <?= ui_icon('refresh') ?> 1-Click Patch PHP
                                        </button>
                                    <?php endif; ?>

                                    <button type="button" class="btn btn-secondary btn-sm" onclick="openExtModal(<?= js_arg($ver) ?>)">
                                        <?= ui_icon('puzzle') ?> Ekstensi
                                    </button>
                                    <button type="button" class="btn btn-ghost btn-icon btn-sm" title="Restart PHP-FPM <?= e($ver) ?>" aria-label="Restart PHP-FPM <?= e($ver) ?>"
                                            onclick="controlService('php<?= e($ver) ?>-fpm', 'restart')">
                                        <?= ui_icon('refresh') ?>
                                    </button>
                                    <button type="button" class="btn btn-ghost btn-icon btn-sm" title="Hapus PHP <?= e($ver) ?>" aria-label="Hapus PHP <?= e($ver) ?>"
                                            onclick="queuePluginAction('uninstall', 'php', <?= js_arg($ver) ?>)">
                                        <?= ui_icon('trash') ?>
                                    </button>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</section>

<script>
async function queuePluginAction(action, plugin, version) {
    var target = plugin === 'php' ? 'PHP ' + version : ({ nginx: 'Nginx', mysql: 'MySQL / MariaDB', cloudflared: 'cloudflared' }[plugin] || plugin);
    let title, message, confirmText, danger = false, endpoint;

    if (action === 'install') {
        title = 'Pasang ' + target;
        message = 'Proses instalasi akan dijalankan oleh daemon di latar belakang. Anda bisa memantaunya di terminal task.';
        confirmText = 'Pasang';
        endpoint = '/api/plugins/install';
    } else if (action === 'update') {
        title = '1-Click Patch ' + target;
        message = 'Sistem akan mengunduh paket APT terbaru dan menerapkan patch mitigasi CVE-2026-109618 / CVE-2026-109619 untuk ' + target + '.';
        confirmText = 'Terapkan Patch';
        endpoint = '/api/plugins/update';
    } else {
        title = 'Hapus ' + target;
        message = target + ' akan dihapus dari server. Pastikan tidak ada website yang masih memakainya.';
        confirmText = 'Hapus';
        danger = true;
        endpoint = '/api/plugins/uninstall';
    }

    var ok = await Almusa.confirm({
        title: title,
        message: message,
        confirmText: confirmText,
        danger: danger
    });
    if (!ok) return;

    try {
        var data = await Almusa.post(endpoint, { plugin: plugin, version: version });
        if (data.success) {
            openTaskTerminal();
        } else {
            Almusa.toast(data.error || 'Gagal menjadwalkan tugas.', 'error');
        }
    } catch (err) {
        Almusa.toast('Terjadi kesalahan koneksi.', 'error');
    }
}

async function checkAllPhpUpdates() {
    var btn = document.getElementById('btnCheckPhpUpdates');
    var restoreBtn = Almusa.busy(btn, 'Memeriksa...');

    try {
        var res = await Almusa.post('/api/plugins/check-php-updates', {});
        if (typeof restoreBtn === 'function') restoreBtn();
        else if (Almusa.unbusy) Almusa.unbusy(btn);

        if (res.success) {
            Almusa.toast('Pemeriksaan patch APT selesai. Memuat ulang status...', 'success');
            setTimeout(() => location.reload(), 700);
        } else {
            Almusa.toast(res.error || 'Gagal memeriksa update patch APT.', 'error');
        }
    } catch (err) {
        if (typeof restoreBtn === 'function') restoreBtn();
        else if (Almusa.unbusy) Almusa.unbusy(btn);
        Almusa.toast('Terjadi kesalahan jaringan.', 'error');
    }
}

async function openExtModal(ver) {
    var ext = await Almusa.prompt({
        title: 'Pasang ekstensi PHP ' + ver,
        message: 'Contoh: redis, imagick, memcached, soap, xdebug.',
        label: 'Nama ekstensi',
        placeholder: 'redis'
    });
    if (!ext) return;

    try {
        var data = await Almusa.post('/api/plugins/php/extension', { version: ver, extension: ext.trim(), sub_action: 'install' });
        if (data.success) openTaskTerminal();
        else Almusa.toast(data.error || 'Gagal memasang ekstensi.', 'error');
    } catch (err) {
        Almusa.toast('Terjadi kesalahan koneksi.', 'error');
    }
}

async function controlService(service, action) {
    var serviceNames = {
        nginx: 'Nginx Web Server',
        mysql: 'MySQL / MariaDB Database',
        mariadb: 'MariaDB Database',
        cloudflared: 'Cloudflare Tunnel'
    };
    var sTitle = serviceNames[service] || service;
    var actionLabels = {
        restart: 'Restart',
        stop: 'Hentikan (Stop)',
        start: 'Jalankan (Start)',
        reload: 'Reload'
    };
    var aLabel = actionLabels[action] || action;
    var title = aLabel + ' ' + sTitle;
    var message = 'Sistem akan mengeksekusi perintah ' + action.toUpperCase() + ' pada service ' + sTitle + '.';
    var danger = action === 'stop';

    if (service === 'nginx' && action === 'stop') {
        danger = true;
        message = 'PERINGATAN KRUSIAL: Almusa Panel berjalan di atas web server Nginx (Port 64448). Jika Anda menghentikan (STOP) Nginx, antarmuka web panel ini AKAN IKUT MATI dan Anda harus menjalankannya kembali secara manual via terminal SSH (mypanel start / sudo systemctl start nginx). Yakin ingin menghentikan Nginx?';
    } else if ((service === 'mysql' || service === 'mariadb') && action === 'stop') {
        danger = true;
        message = 'PERINGATAN: Menghentikan database MariaDB/MySQL akan menyebabkan semua website berbasis database (WordPress, Laravel, dll) mengalami Database Connection Error. Yakin ingin menghentikan?';
    }

    var ok = await Almusa.confirm({
        title: title,
        message: message,
        confirmText: action === 'stop' ? 'Ya, Hentikan Service' : (action === 'restart' ? 'Restart Sekarang' : 'Start'),
        danger: danger
    });
    if (!ok) return;

    try {
        var res = await Almusa.post('/api/plugins/service-control', { service: service, action: action });
        if (res.success) {
            openTaskTerminal();
            Almusa.toast(res.message || 'Perintah service dijadwalkan.', 'info');
        } else {
            Almusa.toast(res.error || 'Gagal mengeksekusi service.', 'error');
        }
    } catch (e) {
        Almusa.toast('Terjadi kesalahan jaringan.', 'error');
    }
}
</script>
