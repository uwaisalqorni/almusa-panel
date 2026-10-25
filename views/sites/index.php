<?php
/**
 * Almusa Control Panel - Websites & Virtual Hosts View
 */
$rewriteLabels = [
    'default'   => 'Default',
    'laravel'   => 'Laravel',
    'wordpress' => 'WordPress',
    'thinkphp'  => 'ThinkPHP',
];
?>

<header class="page-header">
    <div>
        <h1 class="page-title">Websites</h1>
        <p class="page-desc">Kelola virtual host, document root, versi PHP, dan publikasi online lewat Cloudflare Tunnel.</p>
    </div>
    <div class="page-actions">
        <button type="button" class="btn btn-primary" onclick="openAddSiteModal()">
            <?= ui_icon('plus') ?> Tambah website
        </button>
    </div>
</header>

<section class="card card-flush">
    <div class="card-body">
        <div class="table-wrap">
            <table class="table">
                <thead>
                    <tr>
                        <th>Website</th>
                        <th>PHP</th>
                        <th>Rewrite</th>
                        <th>Akses online</th>
                        <th>Status</th>
                        <th class="th-right">Aksi</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($sites)): ?>
                    <tr>
                        <td colspan="6">
                            <div class="empty">
                                <?= ui_icon('globe', 'icon-xl') ?>
                                <div class="empty-title">Belum ada website</div>
                                <p class="empty-text">Tambahkan website pertama untuk membuat virtual host Nginx beserta document root-nya.</p>
                                <button type="button" class="btn btn-primary" onclick="openAddSiteModal()"><?= ui_icon('plus') ?> Tambah website</button>
                            </div>
                        </td>
                    </tr>
                <?php else: foreach ($sites as $site): ?>
                    <tr>
                        <td>
                            <div class="cell-title"><?= e($site['domain']) ?></div>
                            <div class="cell-sub"><?= e($site['docroot']) ?></div>
                        </td>
                        <td>
                            <select class="select select-sm" aria-label="Versi PHP untuk <?= e($site['domain']) ?>"
                                    data-current="<?= e($site['php_version']) ?>"
                                    onchange="switchSitePhp(<?= (int)$site['id'] ?>, this.value)">
                                <?php foreach ($phpVersions as $ver): ?>
                                    <option value="<?= e($ver) ?>" <?= $site['php_version'] === $ver ? 'selected' : '' ?>>PHP <?= e($ver) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                        <td>
                            <span class="badge badge-neutral no-dot">
                                <?= e($rewriteLabels[$site['rewrite_template'] ?? 'default'] ?? ucfirst((string)$site['rewrite_template'])) ?>
                            </span>
                        </td>
                        <td>
                            <?php if (!empty($site['tunnel_url'])): ?>
                                <a href="<?= e($site['tunnel_url']) ?>" target="_blank" rel="noopener" class="cell-link">
                                    <span class="truncate"><?= e(preg_replace('#^https?://#', '', $site['tunnel_url'])) ?></span>
                                    <?= ui_icon('external') ?>
                                </a>
                            <?php else: ?>
                                <button type="button" class="btn btn-secondary btn-sm"
                                        onclick="startQuickTunnel(<?= (int)$site['id'] ?>, <?= js_arg($site['domain']) ?>, this)">
                                    <?= ui_icon('cloud') ?> Aktifkan online
                                </button>
                            <?php endif; ?>
                        </td>
                        <td>
                            <span class="badge <?= $site['status'] === 'active' ? 'badge-success' : 'badge-neutral' ?>">
                                <?= e($site['status'] === 'active' ? 'Aktif' : ucfirst((string)$site['status'])) ?>
                            </span>
                        </td>
                        <td>
                            <div class="cell-actions">
                                <button type="button" class="btn btn-ghost btn-icon btn-sm" title="Hapus website" aria-label="Hapus <?= e($site['domain']) ?>"
                                        onclick="openDeleteSiteModal(<?= (int)$site['id'] ?>, <?= js_arg($site['domain']) ?>, <?= js_arg($site['docroot']) ?>)">
                                    <?= ui_icon('trash') ?>
                                </button>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</section>

<!-- Tambah website -->
<div class="modal-backdrop" id="addSiteModal">
    <div class="modal modal-md" role="dialog" aria-modal="true" aria-labelledby="addSiteTitle">
        <div class="modal-header">
            <div>
                <h2 class="modal-title" id="addSiteTitle">Tambah website</h2>
                <p class="modal-subtitle">Membuat virtual host Nginx dan folder proyek.</p>
            </div>
            <button type="button" class="icon-btn" onclick="closeAddSiteModal()" aria-label="Tutup"><?= ui_icon('x') ?></button>
        </div>
        <form id="addSiteForm" onsubmit="submitAddSite(event)">
            <div class="modal-body">
                <div class="field">
                    <label class="label" for="siteDomain">Nama domain</label>
                    <input type="text" id="siteDomain" class="input" placeholder="contoh: app.domain.com" required>
                </div>
                <div class="field">
                    <label class="label" for="siteDocroot">Document root <span class="opt">opsional</span></label>
                    <input type="text" id="siteDocroot" class="input" placeholder="/www/wwwroot/app.domain.com">
                    <div class="help">Kosongkan untuk memakai path bawaan.</div>
                </div>
                <div class="field-row">
                    <div class="field">
                        <label class="label" for="sitePhp">Versi PHP</label>
                        <select id="sitePhp" class="select">
                            <?php foreach ($phpVersions as $ver): ?>
                                <option value="<?= e($ver) ?>" <?= $ver === '8.3' ? 'selected' : '' ?>>PHP <?= e($ver) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="field">
                        <label class="label" for="siteRewrite">URL rewrite</label>
                        <select id="siteRewrite" class="select">
                            <option value="default">Default / Plain</option>
                            <option value="laravel">Laravel / Lumen</option>
                            <option value="wordpress">WordPress</option>
                            <option value="thinkphp">ThinkPHP</option>
                        </select>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeAddSiteModal()">Batal</button>
                <button type="submit" class="btn btn-primary" id="addSiteSubmit">Buat website</button>
            </div>
        </form>
    </div>
</div>

<!-- Hapus website -->
<div class="modal-backdrop" id="deleteSiteModal">
    <div class="modal modal-sm" role="dialog" aria-modal="true" aria-labelledby="delSiteTitle">
        <div class="modal-header">
            <div>
                <h2 class="modal-title" id="delSiteTitle">Hapus website</h2>
                <p class="modal-subtitle">
                    Konfigurasi Nginx untuk <strong id="delDomainText"></strong> akan dicabut.
                </p>
            </div>
            <button type="button" class="icon-btn" onclick="closeDeleteSiteModal()" aria-label="Tutup"><?= ui_icon('x') ?></button>
        </div>
        <div class="modal-body">
            <div class="callout-danger">
                <label class="check">
                    <input type="checkbox" id="delFilesCheck" value="1" checked>
                    <span>Hapus juga seluruh file proyek di direktori ini</span>
                </label>
                <code id="delDocrootText"></code>
            </div>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-secondary" onclick="closeDeleteSiteModal()">Batal</button>
            <button type="button" class="btn btn-danger-solid" id="confirmDeleteBtn" onclick="confirmDeleteSite()">Hapus website</button>
        </div>
    </div>
</div>

<script>
function openAddSiteModal()  { Almusa.openModal('addSiteModal'); setTimeout(function () { document.getElementById('siteDomain').focus(); }, 50); }
function closeAddSiteModal() { Almusa.closeModal('addSiteModal'); }

async function submitAddSite(e) {
    e.preventDefault();
    var restore = Almusa.busy(document.getElementById('addSiteSubmit'), 'Membuat…');
    try {
        var data = await Almusa.post('/api/sites/create', {
            domain: document.getElementById('siteDomain').value.trim(),
            docroot: document.getElementById('siteDocroot').value.trim(),
            php_version: document.getElementById('sitePhp').value,
            rewrite_template: document.getElementById('siteRewrite').value
        });
        if (data.success) {
            closeAddSiteModal();
            openTaskTerminal();
            Almusa.reload('Website sedang dibuat…', 'info', 2000);
        } else {
            Almusa.toast(data.error || 'Gagal menambahkan website.', 'error');
            restore();
        }
    } catch (err) {
        Almusa.toast('Terjadi kesalahan jaringan.', 'error');
        restore();
    }
}

async function switchSitePhp(siteId, newVersion) {
    var select = event && event.target;
    var previous = select ? select.getAttribute('data-current') : null;

    var ok = await Almusa.confirm({
        title: 'Ganti versi PHP',
        message: 'Website ini akan dialihkan ke PHP ' + newVersion + '. PHP-FPM akan dimuat ulang.',
        confirmText: 'Ganti ke PHP ' + newVersion
    });
    if (!ok) {
        if (select && previous) select.value = previous;
        return;
    }

    try {
        var data = await Almusa.post('/api/sites/switch-php', { site_id: siteId, php_version: newVersion });
        if (data.success) {
            if (select) select.setAttribute('data-current', newVersion);
            openTaskTerminal();
            Almusa.toast('Versi PHP diganti ke ' + newVersion + '.', 'success');
        } else {
            Almusa.toast(data.error || 'Gagal mengubah versi PHP.', 'error');
            if (select && previous) select.value = previous;
        }
    } catch (err) {
        Almusa.toast('Terjadi kesalahan jaringan.', 'error');
        if (select && previous) select.value = previous;
    }
}

var currentDeleteSiteId = null;

function openDeleteSiteModal(siteId, domain, docroot) {
    currentDeleteSiteId = siteId;
    document.getElementById('delDomainText').textContent = domain;
    document.getElementById('delDocrootText').textContent = docroot || ('/www/wwwroot/' + domain);
    document.getElementById('delFilesCheck').checked = true;
    Almusa.openModal('deleteSiteModal');
}

function closeDeleteSiteModal() {
    Almusa.closeModal('deleteSiteModal');
    currentDeleteSiteId = null;
}

async function confirmDeleteSite() {
    if (!currentDeleteSiteId) return;
    var restore = Almusa.busy(document.getElementById('confirmDeleteBtn'), 'Menghapus…');
    try {
        var data = await Almusa.post('/api/sites/delete', {
            site_id: currentDeleteSiteId,
            delete_files: document.getElementById('delFilesCheck').checked ? 1 : 0
        });
        if (data.success) {
            closeDeleteSiteModal();
            openTaskTerminal();
            Almusa.reload('Website dihapus.', 'success', 1500);
        } else {
            Almusa.toast(data.error || 'Gagal menghapus website.', 'error');
            restore();
        }
    } catch (err) {
        Almusa.toast('Terjadi kesalahan jaringan.', 'error');
        restore();
    }
}

// Kompatibilitas pemanggilan lama
function deleteSite(siteId, domain) {
    openDeleteSiteModal(siteId, domain, '/www/wwwroot/' + domain);
}

async function startQuickTunnel(siteId, domain, btn) {
    var restore = btn ? Almusa.busy(btn, 'Memulai…') : function () {};
    try {
        var data = await Almusa.post('/api/tunnels/quick-start', { site_id: siteId, domain: domain });
        if (data.success) {
            openTaskTerminal();
            Almusa.reload('Tunnel sedang dibuat…', 'info', 4000);
        } else {
            Almusa.toast(data.error || 'Gagal memulai tunnel.', 'error');
            restore();
        }
    } catch (err) {
        Almusa.toast('Terjadi kesalahan jaringan.', 'error');
        restore();
    }
}
</script>
