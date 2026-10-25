<?php
/**
 * Almusa Control Panel - App Store & Plugins View
 */
?>

<div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 24px;">
    <div>
        <h2 style="font-size: 20px; font-weight: 700; color: #ffffff;">App Store & Plugin Services</h2>
        <p style="font-size: 13px; color: var(--text-muted);">Instalasi 1-klik untuk Web Server, Multi-versi PHP, Database, dan Utilitas Server</p>
    </div>
    <button type="button" class="btn btn-secondary btn-sm" onclick="openTaskTerminal()">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <polyline points="4 17 10 11 4 5"></polyline>
            <line x1="12" y1="19" x2="20" y2="19"></line>
        </svg>
        Buka Terminal Eksekusi
    </button>
</div>

<!-- CORE SERVICES GRID -->
<div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(320px, 1fr)); gap: 20px; margin-bottom: 32px;">
    <!-- NGINX -->
    <div class="card">
        <div style="display: flex; align-items: flex-start; justify-content: space-between; margin-bottom: 14px;">
            <div style="display: flex; gap: 14px;">
                <div style="width: 44px; height: 44px; background: rgba(16, 185, 129, 0.15); border-radius: var(--radius-md); display: flex; align-items: center; justify-content: center; color: #10b981; font-weight: 700;">
                    NX
                </div>
                <div>
                    <h3 style="font-size: 16px; font-weight: 700; color: #ffffff;">Nginx Web Server</h3>
                    <p style="font-size: 12px; color: var(--text-muted);">HTTP & Reverse Proxy</p>
                </div>
            </div>
            <?php if ($plugins['nginx']['active']): ?>
                <span class="badge badge-success">Running</span>
            <?php elseif ($plugins['nginx']['installed']): ?>
                <span class="badge badge-warning">Installed</span>
            <?php else: ?>
                <span class="badge badge-neutral">Not Installed</span>
            <?php endif; ?>
        </div>
        <p style="font-size: 13px; color: var(--text-secondary); margin-bottom: 18px;">
            Web server berkecepatan tinggi dengan isolasi Virtual Host, rewrite rules dinamis, dan HTTP/2.
        </p>
        <div style="display: flex; gap: 8px;">
            <?php if (!$plugins['nginx']['installed']): ?>
                <button class="btn btn-primary btn-sm" onclick="queuePluginAction('install', 'nginx', '')">
                    Install Nginx
                </button>
            <?php else: ?>
                <button class="btn btn-secondary btn-sm" onclick="queuePluginAction('install', 'nginx', '')">
                    Reinstall / Update
                </button>
                <button class="btn btn-danger btn-sm" onclick="queuePluginAction('uninstall', 'nginx', '')">
                    Uninstall
                </button>
            <?php endif; ?>
        </div>
    </div>

    <!-- MYSQL / MARIADB -->
    <div class="card">
        <div style="display: flex; align-items: flex-start; justify-content: space-between; margin-bottom: 14px;">
            <div style="display: flex; gap: 14px;">
                <div style="width: 44px; height: 44px; background: rgba(245, 158, 11, 0.15); border-radius: var(--radius-md); display: flex; align-items: center; justify-content: center; color: #f59e0b; font-weight: 700;">
                    SQL
                </div>
                <div>
                    <h3 style="font-size: 16px; font-weight: 700; color: #ffffff;">MySQL / MariaDB</h3>
                    <p style="font-size: 12px; color: var(--text-muted);">Database Relasional</p>
                </div>
            </div>
            <?php if ($plugins['mysql']['active']): ?>
                <span class="badge badge-success">Running</span>
            <?php elseif ($plugins['mysql']['installed']): ?>
                <span class="badge badge-warning">Installed</span>
            <?php else: ?>
                <span class="badge badge-neutral">Not Installed</span>
            <?php endif; ?>
        </div>
        <p style="font-size: 13px; color: var(--text-secondary); margin-bottom: 18px;">
            Mesin database aman dengan charset UTF8MB4 bawaan, user management, dan backup dump otomatis.
        </p>
        <div style="display: flex; gap: 8px;">
            <?php if (!$plugins['mysql']['installed']): ?>
                <button class="btn btn-primary btn-sm" onclick="queuePluginAction('install', 'mysql', '')">
                    Install Native MariaDB
                </button>
            <?php else: ?>
                <a href="/databases" class="btn btn-secondary btn-sm">Buka Database</a>
                <button class="btn btn-danger btn-sm" onclick="queuePluginAction('uninstall', 'mysql', '')">
                    Uninstall
                </button>
            <?php endif; ?>
        </div>
    </div>

    <!-- CLOUDFLARE TUNNEL -->
    <div class="card">
        <div style="display: flex; align-items: flex-start; justify-content: space-between; margin-bottom: 14px;">
            <div style="display: flex; gap: 14px;">
                <div style="width: 44px; height: 44px; background: rgba(6, 182, 212, 0.15); border-radius: var(--radius-md); display: flex; align-items: center; justify-content: center; color: #06b6d4; font-weight: 700;">
                    CF
                </div>
                <div>
                    <h3 style="font-size: 16px; font-weight: 700; color: #ffffff;">Cloudflare Tunnel</h3>
                    <p style="font-size: 12px; color: var(--text-muted);">Zero Trust Tunnel</p>
                </div>
            </div>
            <?php if ($plugins['cloudflared']['installed']): ?>
                <span class="badge badge-success">Siap</span>
            <?php else: ?>
                <span class="badge badge-neutral">Not Installed</span>
            <?php endif; ?>
        </div>
        <p style="font-size: 13px; color: var(--text-secondary); margin-bottom: 18px;">
            Daemon resmi `cloudflared` untuk Quick Tunnel (.trycloudflare.com) & custom domain Zero Trust API.
        </p>
        <div style="display: flex; gap: 8px;">
            <?php if (!$plugins['cloudflared']['installed']): ?>
                <button class="btn btn-primary btn-sm" onclick="queuePluginAction('install', 'cloudflared', '')">
                    Download Binary cloudflared
                </button>
            <?php else: ?>
                <a href="/tunnels" class="btn btn-secondary btn-sm">Kelola Tunnels</a>
                <button class="btn btn-danger btn-sm" onclick="queuePluginAction('uninstall', 'cloudflared', '')">
                    Uninstall
                </button>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- PHP MULTI-VERSION MANAGER -->
<div class="table-container">
    <div class="table-header">
        <div>
            <h3 class="table-title">PHP Multi-Version Manager</h3>
            <p style="font-size: 12px; color: var(--text-muted); margin-top: 4px;">
                Instal versi PHP yang Anda butuhkan. Setiap versi berjalan di socket PHP-FPM terpisah.
            </p>
        </div>
    </div>

    <table class="custom-table">
        <thead>
            <tr>
                <th>Versi PHP</th>
                <th>Socket FPM</th>
                <th>Status Layanan</th>
                <th>Ekstensi Tambahan</th>
                <th>Aksi</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($phpList as $ver => $info): ?>
                <tr>
                    <td>
                        <strong style="color: #ffffff; font-size: 15px;">PHP <?= $ver ?></strong>
                    </td>
                    <td>
                        <code>/run/php/php<?= $ver ?>-fpm.sock</code>
                    </td>
                    <td>
                        <?php if ($info['active']): ?>
                            <span class="badge badge-success">Running</span>
                        <?php elseif ($info['installed']): ?>
                            <span class="badge badge-warning">Stopped</span>
                        <?php else: ?>
                            <span class="badge badge-neutral">Not Installed</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ($info['installed']): ?>
                            <button class="btn btn-secondary btn-sm" onclick="openExtModal('<?= $ver ?>')">
                                Kelola Ekstensi (Redis, Imagick, dll)
                            </button>
                        <?php else: ?>
                            <span style="color: var(--text-muted);">Tersedia setelah instalasi</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if (!$info['installed']): ?>
                            <button class="btn btn-primary btn-sm" onclick="queuePluginAction('install', 'php', '<?= $ver ?>')">
                                Install PHP <?= $ver ?>
                            </button>
                        <?php else: ?>
                            <button class="btn btn-secondary btn-sm" onclick="queuePluginAction('install', 'php', '<?= $ver ?>')">
                                Update
                            </button>
                            <button class="btn btn-danger btn-sm" onclick="queuePluginAction('uninstall', 'php', '<?= $ver ?>')">
                                Uninstall
                            </button>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<script>
async function queuePluginAction(action, plugin, version) {
    const verb = action === 'install' ? 'menginstal' : 'menghapus';
    const target = plugin.toUpperCase() + (version ? ' ' + version : '');
    if (!confirm(`Yakin ingin ${verb} ${target}? Proses akan dijalankan oleh daemon di terminal background.`)) return;

    try {
        const url = action === 'install' ? '/api/plugins/install' : '/api/plugins/uninstall';
        const csrf = document.querySelector('meta[name="csrf-token"]').getAttribute('content');

        const res = await fetch(url, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
                'X-CSRF-TOKEN': csrf
            },
            body: `_csrf_token=${encodeURIComponent(csrf)}&plugin=${encodeURIComponent(plugin)}&version=${encodeURIComponent(version)}`
        });

        const data = await res.json();
        if (data.success) {
            openTaskTerminal();
        } else {
            alert(data.error || 'Gagal menjadwalkan tugas.');
        }
    } catch (e) {
        alert('Terjadi kesalahan koneksi.');
    }
}

function openExtModal(ver) {
    const ext = prompt(`Masukkan nama ekstensi yang ingin diinstal untuk PHP ${ver} (contoh: redis, imagick, memcached, soap, xdebug):`);
    if (!ext) return;

    const csrf = document.querySelector('meta[name="csrf-token"]').getAttribute('content');
    fetch('/api/plugins/php/extension', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
            'X-CSRF-TOKEN': csrf
        },
        body: `_csrf_token=${encodeURIComponent(csrf)}&version=${encodeURIComponent(ver)}&extension=${encodeURIComponent(ext.trim())}&sub_action=install`
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            openTaskTerminal();
        } else {
            alert(data.error || 'Gagal menginstal ekstensi.');
        }
    });
}
</script>
