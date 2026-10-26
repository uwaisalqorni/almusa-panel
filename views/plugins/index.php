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
        'key'   => 'nginx',
        'icon'  => 'server',
        'name'  => 'Nginx',
        'tag'   => 'Web server & reverse proxy',
        'desc'  => 'Web server berperforma tinggi dengan isolasi virtual host, aturan rewrite, dan HTTP/2.',
        'badge' => $statusBadge($plugins['nginx']),
        'installed' => $plugins['nginx']['installed'],
        'install'   => 'Pasang Nginx',
        'open'      => null,
    ],
    [
        'key'   => 'mysql',
        'icon'  => 'database',
        'name'  => 'MySQL / MariaDB',
        'tag'   => 'Database relasional',
        'desc'  => 'Mesin database dengan charset utf8mb4 bawaan, manajemen user, dan backup dump.',
        'badge' => $statusBadge($plugins['mysql']),
        'installed' => $plugins['mysql']['installed'],
        'install'   => 'Pasang MariaDB',
        'open'      => ['/databases', 'Buka Databases'],
    ],
    [
        'key'   => 'cloudflared',
        'icon'  => 'cloud',
        'name'  => 'Cloudflare Tunnel',
        'tag'   => 'Akses online tanpa buka port',
        'desc'  => 'Binary cloudflared untuk Quick Tunnel (trycloudflare.com) dan domain sendiri via Zero Trust.',
        'badge' => $statusBadge($plugins['cloudflared'], false),
        'installed' => $plugins['cloudflared']['installed'],
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
                        <h2 class="row-title"><?= e($s['name']) ?></h2>
                        <div class="row-sub"><?= e($s['tag']) ?></div>
                    </div>
                    <?= $s['badge'] ?>
                </div>
                <p class="text-2" style="font-size:13px;margin:14px 0 16px"><?= e($s['desc']) ?></p>
            </div>
            <div class="cell-actions" style="justify-content:flex-start">
                <?php if (!$s['installed']): ?>
                    <button type="button" class="btn btn-primary btn-sm" onclick="queuePluginAction('install', '<?= e($s['key']) ?>', '')">
                        <?= ui_icon('download') ?> <?= e($s['install']) ?>
                    </button>
                <?php else: ?>
                    <?php if ($s['key'] === 'nginx'): ?>
                        <button type="button" class="btn btn-primary btn-sm" onclick="openNginxModal()">
                            <?= ui_icon('sliders') ?> Kelola Nginx
                        </button>
                    <?php endif; ?>
                    <?php if ($s['open']): ?>
                        <a href="<?= e($s['open'][0]) ?>" class="btn btn-secondary btn-sm"><?= e($s['open'][1]) ?></a>
                    <?php endif; ?>
                    <button type="button" class="btn btn-secondary btn-sm" onclick="queuePluginAction('install', '<?= e($s['key']) ?>', '')">
                        <?= ui_icon('refresh') ?> Pasang ulang
                    </button>
                    <button type="button" class="btn btn-ghost btn-sm" style="color:var(--err)" onclick="queuePluginAction('uninstall', '<?= e($s['key']) ?>', '')">
                        Hapus
                    </button>
                <?php endif; ?>
            </div>
        </article>
    <?php endforeach; ?>
</section>

<section class="card card-flush">
    <div class="card-header">
        <div>
            <h2 class="card-title">Versi PHP</h2>
            <p class="card-desc">Setiap versi berjalan pada socket PHP-FPM terpisah.</p>
        </div>
    </div>
    <div class="card-body">
        <div class="table-wrap">
            <table class="table">
                <thead>
                    <tr>
                        <th>Versi</th>
                        <th>Socket FPM</th>
                        <th>Status</th>
                        <th class="th-right">Aksi</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($phpList as $ver => $info): ?>
                    <tr>
                        <td><div class="cell-title">PHP <?= e($ver) ?></div></td>
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
                            <div class="cell-actions">
                                <?php if (!$info['installed']): ?>
                                    <button type="button" class="btn btn-primary btn-sm" onclick="queuePluginAction('install', 'php', <?= js_arg($ver) ?>)">
                                        <?= ui_icon('download') ?> Pasang
                                    </button>
                                <?php else: ?>
                                    <button type="button" class="btn btn-secondary btn-sm" onclick="openExtModal(<?= js_arg($ver) ?>)">
                                        <?= ui_icon('puzzle') ?> Ekstensi
                                    </button>
                                    <button type="button" class="btn btn-secondary btn-sm" onclick="queuePluginAction('install', 'php', <?= js_arg($ver) ?>)">
                                        <?= ui_icon('refresh') ?> Perbarui
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
    var installing = action === 'install';
    var target = plugin === 'php' ? 'PHP ' + version : ({ nginx: 'Nginx', mysql: 'MySQL / MariaDB', cloudflared: 'cloudflared' }[plugin] || plugin);

    var ok = await Almusa.confirm({
        title: installing ? 'Pasang ' + target : 'Hapus ' + target,
        message: installing
            ? 'Proses akan dijalankan oleh daemon di latar belakang. Anda bisa memantaunya di terminal.'
            : target + ' akan dihapus dari server. Pastikan tidak ada website yang masih memakainya.',
        confirmText: installing ? 'Pasang' : 'Hapus',
        danger: !installing
    });
    if (!ok) return;

    try {
        var data = await Almusa.post(installing ? '/api/plugins/install' : '/api/plugins/uninstall', { plugin: plugin, version: version });
        if (data.success) {
            openTaskTerminal();
        } else {
            Almusa.toast(data.error || 'Gagal menjadwalkan tugas.', 'error');
        }
    } catch (err) {
        Almusa.toast('Terjadi kesalahan koneksi.', 'error');
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
</script>
