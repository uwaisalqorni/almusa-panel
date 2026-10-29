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
                            <div class="cell-title d-flex align-center gap-2">
                                <span><?= e($site['domain']) ?></span>
                                <?php if (!empty($site['is_laravel'])): ?>
                                    <span class="badge no-dot" title="Project Laravel Terdeteksi - Klik untuk membuka Laravel Toolkit" style="cursor:pointer; font-size: 11px; padding: 2px 7px; background: rgba(239, 68, 68, 0.12); color: #f87171; border: 1px solid rgba(239, 68, 68, 0.3); font-weight: 600;" onclick="openLaravelModal(<?= (int)$site['id'] ?>, <?= js_arg($site['domain']) ?>)">
                                        <?= ui_icon('terminal') ?> Laravel
                                    </span>
                                <?php endif; ?>
                                <?php if (!empty($site['ssl_enabled'])): ?>
                                    <span class="badge badge-success no-dot" title="SSL Aktif (HTTPS)" style="cursor:pointer; font-size: 11px; padding: 2px 6px;" onclick="openSslModal(<?= (int)$site['id'] ?>, <?= js_arg($site['domain']) ?>)">
                                        <?= ui_icon('shield') ?> HTTPS
                                    </span>
                                <?php endif; ?>
                            </div>
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
                                <?php if (!empty($site['is_laravel'])): ?>
                                    <button type="button" class="btn btn-secondary btn-sm" style="color: #f87171; border-color: rgba(239, 68, 68, 0.35); background: rgba(239, 68, 68, 0.08); font-weight: 600;"
                                            title="Laravel Toolkit, Database Connector & Artisan Migrate"
                                            onclick="openLaravelModal(<?= (int)$site['id'] ?>, <?= js_arg($site['domain']) ?>)">
                                        <?= ui_icon('terminal') ?> Laravel
                                    </button>
                                <?php endif; ?>
                                <button type="button" class="btn btn-ghost btn-icon btn-sm <?= !empty($site['ssl_enabled']) ? 'text-success' : '' ?>"
                                        title="<?= !empty($site['ssl_enabled']) ? 'Kelola SSL (Aktif)' : 'Pasang Let\'s Encrypt SSL' ?>"
                                        aria-label="SSL <?= e($site['domain']) ?>"
                                        onclick="openSslModal(<?= (int)$site['id'] ?>, <?= js_arg($site['domain']) ?>)">
                                    <?= ui_icon('shield') ?>
                                </button>
                                <a href="/sites/files?site_id=<?= (int)$site['id'] ?>" class="btn btn-secondary btn-sm" title="Buka File Manager">
                                    <?= ui_icon('folder') ?> Files
                                </a>
                                <a href="/sites/files?site_id=<?= (int)$site['id'] ?>&edit=nginx" class="btn btn-ghost btn-icon btn-sm" title="Edit Konfigurasi Nginx" aria-label="Nginx Conf">
                                    <?= ui_icon('sliders') ?>
                                </a>
                                <button type="button" class="btn btn-ghost btn-icon btn-sm" title="Lihat Log Realtime (Access/Error)" aria-label="Log <?= e($site['domain']) ?>"
                                        onclick="openSiteLogModal(<?= (int)$site['id'] ?>, <?= js_arg($site['domain']) ?>)">
                                    <?= ui_icon('file-text') ?>
                                </button>
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

<!-- Modal Kelola SSL / HTTPS -->
<div class="modal-backdrop" id="sslModal">
    <div class="modal" role="dialog" aria-modal="true" aria-labelledby="sslModalTitle" style="max-width: 640px;">
        <div class="modal-header">
            <div>
                <h2 class="modal-title" id="sslModalTitle">Sertifikat SSL / HTTPS</h2>
                <p class="modal-subtitle">Kelola enkripsi SSL/HTTPS untuk <strong id="sslDomainTitle"></strong></p>
            </div>
            <button type="button" class="icon-btn" onclick="closeSslModal()" aria-label="Tutup"><?= ui_icon('x') ?></button>
        </div>
        <div class="modal-body">
            <!-- Loading indicator -->
            <div id="sslLoadingState" class="text-center py-6" style="display:none; text-align: center; padding: 24px 0;">
                <div class="text-muted">Memeriksa status sertifikat SSL…</div>
            </div>

            <!-- Content Area -->
            <div id="sslContentArea">
                <!-- Active Certificate Card -->
                <div id="sslActiveCard" class="card mb-4" style="display:none; background: rgba(16, 185, 129, 0.05); border: 1px solid rgba(16, 185, 129, 0.2); margin-bottom: 16px;">
                    <div class="card-body">
                        <div class="d-flex align-center justify-between mb-3" style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 12px;">
                            <div class="d-flex align-center gap-2">
                                <span class="badge badge-success"><?= ui_icon('shield') ?> SSL Terpasang & Aktif</span>
                                <span id="sslProviderBadge" class="badge badge-neutral" style="font-size: 11px;">Let's Encrypt</span>
                            </div>
                            <span id="sslDaysBadge" class="badge badge-neutral"></span>
                        </div>
                        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px; font-size: 13px;">
                            <div>
                                <div class="text-muted" style="color: var(--text-3); font-size: 12px;">Domain / Subjek</div>
                                <div style="font-weight: 500;" id="sslSubjectText">-</div>
                            </div>
                            <div>
                                <div class="text-muted" style="color: var(--text-3); font-size: 12px;">Penerbit (CA)</div>
                                <div style="font-weight: 500;" id="sslIssuerText">-</div>
                            </div>
                            <div>
                                <div class="text-muted" style="color: var(--text-3); font-size: 12px;">Masa Berlaku</div>
                                <div style="font-weight: 500;" id="sslValidDates">-</div>
                            </div>
                            <div>
                                <div class="text-muted" style="color: var(--text-3); font-size: 12px;">Metode Pembaruan</div>
                                <div style="font-weight: 500;" id="sslRenewMethodText">Auto-Renew (Systemd Timer)</div>
                            </div>
                        </div>
                        <div style="display: flex; gap: 8px; margin-top: 14px; padding-top: 12px; border-top: 1px solid rgba(255,255,255,0.08);">
                            <button type="button" class="btn btn-secondary btn-sm" id="btnRenewSsl" onclick="renewSiteSsl()">
                                <?= ui_icon('refresh') ?> Perbarui sertifikat (Renew)
                            </button>
                            <button type="button" class="btn btn-ghost btn-sm" id="btnDisableSsl" style="color: var(--err);" onclick="disableSiteSsl()">
                                <?= ui_icon('trash') ?> Matikan SSL
                            </button>
                        </div>
                    </div>
                </div>

                <!-- Provider Tabs Switcher -->
                <div class="ssl-tabs-wrap" style="display: flex; gap: 6px; padding: 4px; background: var(--surface-2); border-radius: var(--radius-md); margin-bottom: 16px;">
                    <button type="button" class="btn btn-sm" id="tabBtnLetsencrypt" onclick="switchSslTab('letsencrypt')" style="flex: 1; justify-content: center; border-radius: var(--radius-sm); font-weight: 600; background: var(--surface); color: var(--text); box-shadow: var(--shadow-sm); border: 1px solid var(--border);">
                        <?= ui_icon('shield') ?> Let's Encrypt (1-Click)
                    </button>
                    <button type="button" class="btn btn-sm" id="tabBtnCustom" onclick="switchSslTab('custom')" style="flex: 1; justify-content: center; border-radius: var(--radius-sm); font-weight: 500; background: transparent; color: var(--text-2); border: 1px solid transparent;">
                        <?= ui_icon('key') ?> Custom / Paid SSL
                    </button>
                </div>

                <!-- TAB 1: Let's Encrypt Form -->
                <div id="tabContentLetsencrypt">
                    <form id="issueSslForm" onsubmit="submitIssueSsl(event)">
                        <div class="callout-info mb-4" style="margin-bottom: 16px;">
                            <div style="display: flex; gap: 8px; font-size: 13px; line-height: 1.5;">
                                <div><?= ui_icon('info') ?></div>
                                <div>
                                    <strong>Persyaratan 1-Click Let's Encrypt:</strong>
                                    <ul style="margin: 4px 0 0 16px; padding: 0;">
                                        <li>DNS domain (A-record) harus sudah mengarah ke IP publik server ini.</li>
                                        <li>Port 80 harus terbuka agar bot verifikasi ACME dapat memvalidasi token webroot.</li>
                                    </ul>
                                </div>
                            </div>
                        </div>

                        <div class="field">
                            <label class="label" for="sslEmail">Email notifikasi Let's Encrypt</label>
                            <input type="email" id="sslEmail" class="input" required placeholder="admin@domain.com">
                            <div class="help">Digunakan oleh Let's Encrypt untuk notifikasi darurat masa kedaluwarsa.</div>
                        </div>

                        <div class="field" style="margin-top: 12px;">
                            <label class="check">
                                <input type="checkbox" id="sslForceHttps" value="1" checked>
                                <span>Paksa redirect otomatis HTTP ke HTTPS (301 Permanent Redirect)</span>
                            </label>
                        </div>

                        <div style="margin-top: 18px;">
                            <button type="submit" class="btn btn-primary" style="width: 100%; justify-content: center;" id="btnSubmitIssueSsl">
                                <?= ui_icon('shield') ?> <span id="btnSubmitIssueText">Terbitkan SSL Sekarang (1-Click Let's Encrypt)</span>
                            </button>
                        </div>
                    </form>
                </div>

                <!-- TAB 2: Custom / Paid SSL Form -->
                <div id="tabContentCustom" style="display: none;">
                    <form id="customSslForm" onsubmit="submitCustomSsl(event)">
                        <div class="callout-info mb-4" style="margin-bottom: 16px;">
                            <div style="display: flex; gap: 8px; font-size: 13px; line-height: 1.5;">
                                <div><?= ui_icon('info') ?></div>
                                <div>
                                    <strong>Pemasangan Sertifikat Resmi / Berbayar (Custom SSL):</strong>
                                    <div style="margin-top: 4px; color: var(--text-2);">
                                        Gunakan form ini untuk memasang sertifikat dari CA komersial (Sectigo, DigiCert, Comodo, PositiveSSL, GlobalSign, dll.) atau sertifikat Wildcard pihak ketiga.
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="field mb-3" style="margin-bottom: 14px;">
                            <label class="label" for="customSslCert" style="display: flex; justify-content: space-between; align-items: center;">
                                <span>Sertifikat SSL (Certificate / CRT / PEM)</span>
                                <span class="text-muted" style="font-size: 11px; font-weight: normal; color: var(--text-3);">Termasuk CA Bundle / Intermediate jika ada</span>
                            </label>
                            <textarea id="customSslCert" class="input mono" rows="6" required placeholder="-----BEGIN CERTIFICATE-----&#10;MIID... (paste sertifikat domain Anda di sini)&#10;-----END CERTIFICATE-----&#10;-----BEGIN CERTIFICATE-----&#10;(opsional: paste intermediate / CA bundle di bawahnya)&#10;-----END CERTIFICATE-----" style="width: 100%; font-size: 11.5px; line-height: 1.4; resize: vertical;"></textarea>
                            <div class="help">Format PEM standar x509 yang diawali dengan <code>-----BEGIN CERTIFICATE-----</code>.</div>
                        </div>

                        <div class="field mb-3" style="margin-bottom: 14px;">
                            <label class="label" for="customSslKey" style="display: flex; justify-content: space-between; align-items: center;">
                                <span>Kunci Privat (Private Key / KEY)</span>
                                <span class="text-muted" style="font-size: 11px; font-weight: normal; color: var(--text-3);">Tanpa passphrase / password</span>
                            </label>
                            <textarea id="customSslKey" class="input mono" rows="5" required placeholder="-----BEGIN RSA PRIVATE KEY-----&#10;MIIE... (paste private key yang dibuat saat generate CSR)&#10;-----END RSA PRIVATE KEY-----" style="width: 100%; font-size: 11.5px; line-height: 1.4; resize: vertical;"></textarea>
                            <div class="help">Private key tanpa enkripsi password passphrase (RSA atau EC).</div>
                        </div>

                        <div class="field" style="margin-top: 12px;">
                            <label class="check">
                                <input type="checkbox" id="customSslForceHttps" value="1" checked>
                                <span>Paksa redirect otomatis HTTP ke HTTPS (301 Permanent Redirect)</span>
                            </label>
                        </div>

                        <div style="margin-top: 18px;">
                            <button type="submit" class="btn btn-primary" style="width: 100%; justify-content: center;" id="btnSubmitCustomSsl">
                                <?= ui_icon('shield') ?> <span id="btnSubmitCustomText">Pasang Sertifikat SSL Resmi (Custom SSL)</span>
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-secondary" onclick="closeSslModal()">Tutup</button>
        </div>
    </div>
</div>

<script>
var currentSslSiteId = null;
var currentSslDomain = null;

function switchSslTab(tab) {
    var tabLe = document.getElementById('tabContentLetsencrypt');
    var tabCustom = document.getElementById('tabContentCustom');
    var btnLe = document.getElementById('tabBtnLetsencrypt');
    var btnCustom = document.getElementById('tabBtnCustom');
    if (!tabLe || !tabCustom || !btnLe || !btnCustom) return;

    if (tab === 'custom') {
        tabLe.style.display = 'none';
        tabCustom.style.display = 'block';

        btnCustom.style.background = 'var(--surface)';
        btnCustom.style.color = 'var(--text)';
        btnCustom.style.boxShadow = 'var(--shadow-sm)';
        btnCustom.style.border = '1px solid var(--border)';
        btnCustom.style.fontWeight = '600';

        btnLe.style.background = 'transparent';
        btnLe.style.color = 'var(--text-2)';
        btnLe.style.boxShadow = 'none';
        btnLe.style.border = '1px solid transparent';
        btnLe.style.fontWeight = '500';
    } else {
        tabLe.style.display = 'block';
        tabCustom.style.display = 'none';

        btnLe.style.background = 'var(--surface)';
        btnLe.style.color = 'var(--text)';
        btnLe.style.boxShadow = 'var(--shadow-sm)';
        btnLe.style.border = '1px solid var(--border)';
        btnLe.style.fontWeight = '600';

        btnCustom.style.background = 'transparent';
        btnCustom.style.color = 'var(--text-2)';
        btnCustom.style.boxShadow = 'none';
        btnCustom.style.border = '1px solid transparent';
        btnCustom.style.fontWeight = '500';
    }
}

async function openSslModal(siteId, domain) {
    currentSslSiteId = siteId;
    currentSslDomain = domain;

    document.getElementById('sslDomainTitle').textContent = domain;
    document.getElementById('sslEmail').value = 'admin@' + domain;
    document.getElementById('sslForceHttps').checked = true;

    // Reset Custom SSL inputs
    var customCertInput = document.getElementById('customSslCert');
    var customKeyInput = document.getElementById('customSslKey');
    var customHttpsInput = document.getElementById('customSslForceHttps');
    if (customCertInput) customCertInput.value = '';
    if (customKeyInput) customKeyInput.value = '';
    if (customHttpsInput) customHttpsInput.checked = true;

    // Default to Let's Encrypt tab initially
    switchSslTab('letsencrypt');

    document.getElementById('sslLoadingState').style.display = 'block';
    document.getElementById('sslContentArea').style.display = 'none';

    Almusa.openModal('sslModal');

    try {
        var data = await Almusa.get('/api/sites/ssl-status?site_id=' + siteId);
        document.getElementById('sslLoadingState').style.display = 'none';
        document.getElementById('sslContentArea').style.display = 'block';

        var activeCard = document.getElementById('sslActiveCard');
        var issueText = document.getElementById('btnSubmitIssueText');
        var customText = document.getElementById('btnSubmitCustomText');
        var btnRenew = document.getElementById('btnRenewSsl');
        var providerBadge = document.getElementById('sslProviderBadge');
        var renewMethod = document.getElementById('sslRenewMethodText');

        if (data.has_cert && data.details) {
            activeCard.style.display = 'block';
            document.getElementById('sslSubjectText').textContent = data.details.subject || domain;
            document.getElementById('sslIssuerText').textContent = data.details.issuer || (data.provider === 'custom' ? 'Custom Certificate' : "Let's Encrypt");
            document.getElementById('sslValidDates').textContent = (data.details.valid_from ? data.details.valid_from.substring(0, 10) : '') + ' s/d ' + (data.details.valid_to ? data.details.valid_to.substring(0, 10) : '');

            var isCustom = (data.provider === 'custom');
            if (providerBadge) {
                providerBadge.textContent = isCustom ? 'Custom / Paid SSL' : "Let's Encrypt";
                providerBadge.className = isCustom ? 'badge badge-primary' : 'badge badge-neutral';
            }
            if (renewMethod) {
                renewMethod.textContent = isCustom ? 'Manual (Ganti lewat Custom SSL)' : 'Auto-Renew (Systemd Timer)';
            }
            if (btnRenew) {
                btnRenew.style.display = isCustom ? 'none' : 'inline-flex';
            }

            var daysBadge = document.getElementById('sslDaysBadge');
            if (data.details.is_expired) {
                daysBadge.className = 'badge badge-danger';
                daysBadge.textContent = 'Kedaluwarsa';
            } else {
                daysBadge.className = 'badge badge-success';
                daysBadge.textContent = 'Sisa ' + data.details.days_left + ' hari';
            }
            issueText.textContent = 'Terbitkan Ulang Let\'s Encrypt';
            if (customText) {
                customText.textContent = 'Perbarui / Timpa Sertifikat Custom SSL';
            }

            if (isCustom) {
                switchSslTab('custom');
            }
        } else {
            activeCard.style.display = 'none';
            issueText.textContent = 'Terbitkan SSL Sekarang (1-Click Let\'s Encrypt)';
            if (customText) {
                customText.textContent = 'Pasang Sertifikat SSL Resmi (Custom SSL)';
            }
            if (btnRenew) {
                btnRenew.style.display = 'inline-flex';
            }
        }
    } catch (e) {
        document.getElementById('sslLoadingState').style.display = 'none';
        document.getElementById('sslContentArea').style.display = 'block';
        document.getElementById('sslActiveCard').style.display = 'none';
    }
}

function closeSslModal() {
    Almusa.closeModal('sslModal');
    currentSslSiteId = null;
    currentSslDomain = null;
}

async function submitIssueSsl(e) {
    e.preventDefault();
    if (!currentSslSiteId) return;

    var btn = document.getElementById('btnSubmitIssueSsl');
    var restore = Almusa.busy(btn, 'Menerbitkan…');

    try {
        var data = await Almusa.post('/api/sites/issue-ssl', {
            site_id: currentSslSiteId,
            email: document.getElementById('sslEmail').value.trim(),
            force_https: document.getElementById('sslForceHttps').checked ? 1 : 0,
            provider: 'letsencrypt'
        });

        if (data.success) {
            closeSslModal();
            openTaskTerminal();
            Almusa.toast(data.message, 'info');
        } else {
            Almusa.toast(data.error || 'Gagal menerbitkan SSL.', 'error');
            restore();
        }
    } catch (err) {
        Almusa.toast('Terjadi kesalahan jaringan.', 'error');
        restore();
    }
}

async function submitCustomSsl(e) {
    e.preventDefault();
    if (!currentSslSiteId) return;

    var cert = document.getElementById('customSslCert').value.trim();
    var key = document.getElementById('customSslKey').value.trim();

    if (!cert) {
        Almusa.toast('Silakan masukkan Sertifikat SSL (CRT/PEM).', 'error');
        return;
    }
    if (!key) {
        Almusa.toast('Silakan masukkan Private Key.', 'error');
        return;
    }

    if (!cert.includes('BEGIN CERTIFICATE')) {
        Almusa.toast('Format Sertifikat harus diawali -----BEGIN CERTIFICATE-----.', 'error');
        return;
    }

    var btn = document.getElementById('btnSubmitCustomSsl');
    var restore = Almusa.busy(btn, 'Memvalidasi & Memasang…');

    try {
        var data = await Almusa.post('/api/sites/custom-ssl', {
            site_id: currentSslSiteId,
            certificate: cert,
            private_key: key,
            force_https: document.getElementById('customSslForceHttps').checked ? 1 : 0
        });

        if (data.success) {
            closeSslModal();
            openTaskTerminal();
            Almusa.toast(data.message, 'info');
        } else {
            Almusa.toast(data.error || 'Gagal memasang Custom SSL.', 'error');
            restore();
        }
    } catch (err) {
        Almusa.toast('Terjadi kesalahan jaringan atau sertifikat tidak valid.', 'error');
        restore();
    }
}

async function renewSiteSsl() {
    if (!currentSslSiteId) return;
    var ok = await Almusa.confirm({
        title: 'Perbarui Sertifikat SSL',
        message: 'Certbot akan mencoba memperbarui sertifikat SSL untuk domain ' + currentSslDomain + '.',
        confirmText: 'Perbarui Sekarang'
    });
    if (!ok) return;

    var btn = document.getElementById('btnRenewSsl');
    var restore = Almusa.busy(btn, 'Memperbarui…');

    try {
        var data = await Almusa.post('/api/sites/renew-ssl', { site_id: currentSslSiteId });
        if (data.success) {
            closeSslModal();
            openTaskTerminal();
            Almusa.toast(data.message, 'info');
        } else {
            Almusa.toast(data.error || 'Gagal memperbarui SSL.', 'error');
            restore();
        }
    } catch (err) {
        Almusa.toast('Terjadi kesalahan jaringan.', 'error');
        restore();
    }
}

async function disableSiteSsl() {
    if (!currentSslSiteId) return;
    var ok = await Almusa.confirm({
        title: 'Cabut / Matikan SSL',
        message: 'Konfigurasi virtual host ' + currentSslDomain + ' akan dikembalikan ke HTTP biasa (tanpa SSL port 443). Anda yakin?',
        confirmText: 'Matikan SSL',
        danger: true
    });
    if (!ok) return;

    var btn = document.getElementById('btnDisableSsl');
    var restore = Almusa.busy(btn, 'Mematikan…');

    try {
        var data = await Almusa.post('/api/sites/disable-ssl', { site_id: currentSslSiteId });
        if (data.success) {
            closeSslModal();
            openTaskTerminal();
            Almusa.toast(data.message, 'info');
        } else {
            Almusa.toast(data.error || 'Gagal mematikan SSL.', 'error');
            restore();
        }
    } catch (err) {
        Almusa.toast('Terjadi kesalahan jaringan.', 'error');
        restore();
    }
}
</script>

<style>
.live-dot {
    width: 8px;
    height: 8px;
    border-radius: 50%;
    display: inline-block;
    flex-shrink: 0;
    transition: all 0.2s ease;
}
.live-dot.live-active {
    background-color: #10b981;
    box-shadow: 0 0 8px #10b981;
    animation: pulse-live 1.6s infinite ease-in-out;
}
.live-dot.live-paused {
    background-color: #6b7280;
    box-shadow: none;
    animation: none;
}
@keyframes pulse-live {
    0%, 100% { opacity: 1; transform: scale(1); }
    50% { opacity: 0.35; transform: scale(0.85); }
}

.modal-tab-btn {
    background: transparent;
    border: none;
    padding: 7px 13px;
    font-size: 13px;
    font-weight: 500;
    color: var(--text-3);
    cursor: pointer;
    border-radius: var(--radius);
    display: inline-flex;
    align-items: center;
    gap: 7px;
    transition: all 0.15s ease;
}
.modal-tab-btn:hover {
    color: var(--text);
    background: var(--surface-2);
}
.modal-tab-btn.active {
    color: #38bdf8;
    background: rgba(56, 189, 248, 0.12);
    font-weight: 600;
}
</style>

<!-- Modal Log Realtime per Website -->
<div class="modal-backdrop" id="siteLogModal">
    <div class="modal modal-lg" style="max-width: 1040px; width: 95vw; height: min(85vh, 760px); display: flex; flex-direction: column; padding: 0; overflow: hidden;" role="dialog" aria-modal="true" aria-labelledby="siteLogDomainText">
        
        <!-- Header -->
        <div class="modal-header" style="padding: 14px 20px; border-bottom: 1px solid var(--border); display: flex; align-items: center; justify-content: space-between; flex-shrink: 0;">
            <div style="display: flex; align-items: center; gap: 12px; min-width: 0;">
                <div class="icon-tile" style="width: 36px; height: 36px; border-radius: 8px; background: rgba(56, 189, 248, 0.12); color: #38bdf8; display: flex; align-items: center; justify-content: center; flex-shrink: 0;">
                    <?= ui_icon('file-text') ?>
                </div>
                <div style="min-width: 0;">
                    <div style="display: flex; align-items: center; gap: 8px;">
                        <h2 class="modal-title" style="font-size: 15px; margin: 0; font-weight: 600;">Log Realtime:</h2>
                        <span id="siteLogDomainText" style="font-size: 15px; font-weight: 700; color: #38bdf8;"></span>
                    </div>
                    <div style="font-size: 12px; color: var(--text-3); display: flex; align-items: center; gap: 6px; margin-top: 2px;">
                        <span>File:</span>
                        <code id="siteLogPathDisplay" style="font-family: var(--mono); color: var(--text-2); background: var(--surface-2); padding: 1px 6px; border-radius: 4px; border: 1px solid var(--border);"></code>
                    </div>
                </div>
            </div>
            <button type="button" class="icon-btn" onclick="closeSiteLogModal()" aria-label="Tutup" style="margin-left: 12px;">
                <?= ui_icon('x', 'icon-lg') ?>
            </button>
        </div>

        <!-- Toolbar: Tabs & Controls -->
        <div style="display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 10px; padding: 10px 20px; background: var(--surface-2); border-bottom: 1px solid var(--border); flex-shrink: 0;">
            <!-- Tabs (Access / Error) -->
            <div style="display: flex; gap: 6px; align-items: center;">
                <button type="button" class="modal-tab-btn active" id="tabBtnLogAccess" onclick="switchSiteLogTab('access')">
                    <?= ui_icon('file-text') ?> Access Log
                </button>
                <button type="button" class="modal-tab-btn" id="tabBtnLogError" onclick="switchSiteLogTab('error')">
                    <?= ui_icon('alert-circle') ?> Error Log
                </button>
            </div>

            <!-- Right Controls: Line Limit, Auto-refresh, Manual Refresh, Clear Log -->
            <div style="display: flex; align-items: center; gap: 8px; flex-wrap: wrap;">
                <div style="display: flex; align-items: center; gap: 6px;">
                    <label for="siteLogLinesSelect" style="font-size: 12px; color: var(--text-3); white-space: nowrap;">Tampilkan:</label>
                    <select id="siteLogLinesSelect" class="select" style="padding: 4px 10px; font-size: 12px; height: 32px; width: auto;" onchange="onSiteLogLinesChange()">
                        <option value="50" selected>50 baris</option>
                        <option value="100">100 baris</option>
                        <option value="200">200 baris</option>
                        <option value="500">500 baris</option>
                    </select>
                </div>

                <!-- Auto-refresh Toggle Button -->
                <button type="button" class="btn btn-secondary btn-sm" id="btnLogAutoRefresh" onclick="toggleLogAutoRefresh()" style="gap: 6px; height: 32px;" title="Klik untuk menjeda / mengaktifkan auto-refresh">
                    <span class="live-dot live-active" id="logLiveDot"></span>
                    <span id="logAutoRefreshText">Auto-refresh (3s)</span>
                </button>

                <!-- Refresh Button -->
                <button type="button" class="btn btn-secondary btn-sm btn-icon" id="btnLogRefresh" onclick="fetchSiteLogs(true)" title="Segarkan Log Sekarang" aria-label="Segarkan" style="height: 32px; width: 32px;">
                    <?= ui_icon('refresh') ?>
                </button>

                <!-- Clear / Truncate Log Button -->
                <button type="button" class="btn btn-ghost btn-sm" id="btnLogClear" onclick="clearSiteLogs()" title="Kosongkan file log ini" style="color: var(--err); height: 32px; gap: 4px;">
                    <?= ui_icon('trash') ?> Kosongkan
                </button>
            </div>
        </div>

        <!-- Terminal Console Body -->
        <div id="siteLogContainer" style="flex: 1; min-height: 280px; overflow-y: auto; background: #080b11; padding: 14px 16px; font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, 'Liberation Mono', monospace; font-size: 12px; line-height: 1.6; color: #d6dbe3; position: relative;">
            
            <!-- Loading Overlay -->
            <div id="siteLogLoading" style="display: none; position: absolute; inset: 0; background: rgba(8, 11, 17, 0.7); backdrop-filter: blur(2px); align-items: center; justify-content: center; z-index: 10;">
                <div style="display: flex; align-items: center; gap: 10px; background: #121620; padding: 10px 18px; border-radius: 8px; border: 1px solid #232832; color: #38bdf8;">
                    <span class="spinner" style="width: 16px; height: 16px; border-width: 2px;"></span>
                    <span>Memuat data log…</span>
                </div>
            </div>

            <!-- Content Lines -->
            <div id="siteLogContent" style="white-space: pre-wrap; word-break: break-all;"></div>
        </div>

        <!-- Footer -->
        <div class="modal-footer" style="padding: 10px 20px; background: var(--surface-2); border-top: 1px solid var(--border); display: flex; align-items: center; justify-content: space-between; flex-shrink: 0;">
            <div style="display: flex; align-items: center; gap: 16px; font-size: 12px; color: var(--text-3); flex-wrap: wrap;">
                <div>Ukuran: <strong id="siteLogSizeDisplay" style="color: var(--text-2); font-family: var(--mono);">-</strong></div>
                <div>Ditampilkan: <strong id="siteLogCountDisplay" style="color: var(--text-2); font-family: var(--mono);">-</strong></div>
                <div>Pembaruan: <span id="siteLogUpdatedDisplay" style="color: var(--text-2); font-family: var(--mono);">-</span></div>
            </div>
            <div style="display: flex; gap: 8px;">
                <button type="button" class="btn btn-secondary btn-sm" id="btnLogScrollBottom" onclick="scrollSiteLogToBottom()">Ke Bawah</button>
                <button type="button" class="btn btn-primary btn-sm" onclick="closeSiteLogModal()">Tutup</button>
            </div>
        </div>

    </div>
</div>

<!-- Laravel Toolkit & Database Connector Modal -->
<div class="modal-backdrop" id="laravelModal">
    <div class="modal modal-lg" style="max-width: 860px; width: 95vw; max-height: 90vh; display: flex; flex-direction: column; padding: 0; overflow: hidden;" role="dialog" aria-modal="true" aria-labelledby="laravelDomainTitle">
        <!-- Header -->
        <div class="modal-header" style="padding: 16px 20px; border-bottom: 1px solid var(--border); display: flex; align-items: center; justify-content: space-between; flex-shrink: 0; background: linear-gradient(135deg, rgba(239, 68, 68, 0.08) 0%, transparent 100%);">
            <div style="display: flex; align-items: center; gap: 12px; min-width: 0;">
                <div class="icon-tile" style="width: 40px; height: 40px; border-radius: 10px; background: rgba(239, 68, 68, 0.15); color: #f87171; display: flex; align-items: center; justify-content: center; flex-shrink: 0; border: 1px solid rgba(239, 68, 68, 0.3);">
                    <?= ui_icon('terminal', 'icon-lg') ?>
                </div>
                <div style="min-width: 0;">
                    <div style="display: flex; align-items: center; gap: 8px;">
                        <h2 class="modal-title" id="laravelDomainTitle" style="font-size: 16px; margin: 0; font-weight: 700;">Laravel Toolkit & Database Manager</h2>
                        <span class="badge" id="laravelPhpBadge" style="background: rgba(56, 189, 248, 0.15); color: #38bdf8; border: 1px solid rgba(56, 189, 248, 0.3); font-size: 11px;">PHP 8.3</span>
                    </div>
                    <div style="font-size: 12px; color: var(--text-3); display: flex; align-items: center; gap: 6px; margin-top: 3px;">
                        <span>Domain: <strong id="laravelDomainText" style="color: var(--text-1);"></strong></span>
                        <span>•</span>
                        <span>Folder: <code id="laravelProjectPathDisplay" style="font-family: var(--mono); color: var(--text-2); background: var(--surface-2); padding: 1px 6px; border-radius: 4px; border: 1px solid var(--border);"></code></span>
                    </div>
                </div>
            </div>
            <button type="button" class="icon-btn" onclick="closeLaravelModal()" aria-label="Tutup" style="margin-left: 12px;">
                <?= ui_icon('x', 'icon-lg') ?>
            </button>
        </div>

        <!-- Scrollable Modal Body -->
        <div class="modal-body" style="padding: 20px; overflow-y: auto; flex: 1; display: flex; flex-direction: column; gap: 20px;">
            
            <!-- SECTION 1: Connect Database & .env Sync -->
            <div class="card" style="border: 1px solid var(--border); background: var(--surface-2); border-radius: 12px; padding: 16px;">
                <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 12px; flex-wrap: wrap; gap: 8px;">
                    <div style="display: flex; align-items: center; gap: 8px;">
                        <span style="color: #38bdf8;"><?= ui_icon('database') ?></span>
                        <h3 style="font-size: 14px; font-weight: 600; margin: 0;">1. Hubungkan Database MySQL ke .env</h3>
                    </div>
                    <div id="laravelDbStatusBadge">
                        <span class="badge badge-neutral">Memuat status…</span>
                    </div>
                </div>

                <p style="font-size: 12.5px; color: var(--text-3); margin: 0 0 14px 0; line-height: 1.5;">
                    Pilih database MySQL yang sudah Anda buat di menu <strong>Databases</strong>. Panel akan otomatis memperbarui parameter <code>DB_CONNECTION</code>, <code>DB_HOST</code>, <code>DB_DATABASE</code>, <code>DB_USERNAME</code>, dan <code>DB_PASSWORD</code> pada file <code>.env</code> project tanpa mengubah baris lainnya.
                </p>

                <div style="display: flex; gap: 10px; align-items: flex-end; flex-wrap: wrap;">
                    <div style="flex: 1; min-width: 240px;">
                        <label class="label" for="laravelDbSelect" style="font-size: 12px; margin-bottom: 4px;">Pilih Database Panel</label>
                        <select id="laravelDbSelect" class="select" style="width: 100%;">
                            <option value="">-- Memuat daftar database --</option>
                        </select>
                    </div>
                    <button type="button" class="btn btn-primary" id="btnSyncLaravelDb" onclick="connectLaravelDb()" style="height: 38px;">
                        <?= ui_icon('refresh') ?> Hubungkan & Sinkronkan .env
                    </button>
                </div>

                <!-- Info detail koneksi saat ini -->
                <div id="laravelCurrentEnvInfo" style="margin-top: 12px; padding: 10px 14px; background: rgba(0,0,0,0.25); border-radius: 8px; font-size: 12px; font-family: var(--mono); color: var(--text-2); display: flex; flex-wrap: wrap; gap: 16px;">
                    <div>Koneksi: <span id="envConnectionVal" style="color: #38bdf8;">-</span></div>
                    <div>Database: <span id="envDatabaseVal" style="color: #4ade80;">-</span></div>
                    <div>User: <span id="envUserVal" style="color: #facc15;">-</span></div>
                    <div>Host: <span id="envHostVal">-</span></div>
                </div>
            </div>

            <!-- SECTION 2: Artisan Migration Actions -->
            <div class="card" style="border: 1px solid var(--border); background: var(--surface-2); border-radius: 12px; padding: 16px;">
                <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 12px; flex-wrap: wrap; gap: 8px;">
                    <div style="display: flex; align-items: center; gap: 8px;">
                        <span style="color: #f87171;"><?= ui_icon('terminal') ?></span>
                        <h3 style="font-size: 14px; font-weight: 600; margin: 0;">2. Database Migration (Artisan Tools)</h3>
                    </div>
                    <span style="font-size: 11.5px; color: var(--text-3);">Dijalankan aman sebagai user <code>www-data</code></span>
                </div>

                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 10px; margin-bottom: 12px;">
                    <!-- Tombol Utama: Run Migration -->
                    <div style="grid-column: 1 / -1; display: flex; align-items: center; justify-content: space-between; background: rgba(16, 185, 129, 0.08); border: 1px solid rgba(16, 185, 129, 0.25); border-radius: 8px; padding: 12px 16px; flex-wrap: wrap; gap: 12px;">
                        <div>
                            <div style="font-weight: 600; font-size: 13.5px; color: #34d399;">Jalankan Migrasi Database</div>
                            <div style="font-size: 12px; color: var(--text-3); margin-top: 2px;">Mengeksekusi semua migrasi pending dari folder <code>database/migrations/</code></div>
                        </div>
                        <div style="display: flex; align-items: center; gap: 12px;">
                            <label style="display: flex; align-items: center; gap: 6px; font-size: 12px; cursor: pointer; color: var(--text-2);">
                                <input type="checkbox" id="artisanSeedOption" style="cursor: pointer;">
                                <span>Sertakan Data Awal (<code>--seed</code>)</span>
                            </label>
                            <button type="button" class="btn btn-primary" id="btnRunMigrate" onclick="runArtisanWithSeed('migrate')" style="background: #059669; border-color: #059669;">
                                <?= ui_icon('zap') ?> Run Migration
                            </button>
                        </div>
                    </div>

                    <!-- Quick buttons -->
                    <button type="button" class="btn btn-secondary btn-sm" onclick="runArtisanCmd('migrate:status')" title="Cek status migrasi tabel">
                        <?= ui_icon('activity') ?> Status Migrasi (<code>status</code>)
                    </button>
                    <button type="button" class="btn btn-secondary btn-sm" onclick="runArtisanCmd('db:seed')" title="Jalankan seeder">
                        <?= ui_icon('layers') ?> Run Seeder (<code>db:seed</code>)
                    </button>
                    <button type="button" class="btn btn-secondary btn-sm" onclick="runArtisanCmd('migrate:rollback')" title="Rollback batch terakhir">
                        <?= ui_icon('refresh') ?> Rollback (<code>rollback</code>)
                    </button>
                    <button type="button" class="btn btn-outline-danger btn-sm" onclick="runArtisanFresh()" title="Drop semua tabel lalu migrasi ulang" style="color: #f87171; border-color: rgba(239,68,68,0.35);">
                        <?= ui_icon('trash') ?> Fresh Migrate (<code>fresh</code>)
                    </button>
                </div>
            </div>

            <!-- SECTION 3: Cache & Maintenance -->
            <div class="card" style="border: 1px solid var(--border); background: var(--surface-2); border-radius: 12px; padding: 16px;">
                <div style="display: flex; align-items: center; gap: 8px; margin-bottom: 12px;">
                    <span style="color: #facc15;"><?= ui_icon('sliders') ?></span>
                    <h3 style="font-size: 14px; font-weight: 600; margin: 0;">3. Optimalisasi & Pemeliharaan Laravel</h3>
                </div>

                <div style="display: flex; gap: 10px; flex-wrap: wrap;">
                    <button type="button" class="btn btn-secondary btn-sm" onclick="runArtisanCmd('optimize:clear')" title="Hapus cache config, route, view">
                        <?= ui_icon('refresh') ?> Clear Cache (<code>optimize:clear</code>)
                    </button>
                    <button type="button" class="btn btn-secondary btn-sm" onclick="runArtisanCmd('key:generate')" title="Generate APP_KEY baru di .env">
                        <?= ui_icon('key') ?> Generate App Key (<code>key:generate</code>)
                    </button>
                    <button type="button" class="btn btn-secondary btn-sm" onclick="runArtisanCmd('storage:link')" title="Buat symlink storage/app/public ke public/storage">
                        <?= ui_icon('folder') ?> Storage Link (<code>storage:link</code>)
                    </button>
                </div>
            </div>

            <!-- SECTION 4: Terminal Console Log -->
            <div style="border: 1px solid #1e293b; background: #0b0f19; border-radius: 12px; overflow: hidden;">
                <div style="padding: 10px 16px; background: #111827; border-bottom: 1px solid #1e293b; display: flex; align-items: center; justify-content: space-between;">
                    <div style="display: flex; align-items: center; gap: 8px;">
                        <span style="display: inline-block; width: 8px; height: 8px; border-radius: 50%; background: #22c55e;" id="terminalStatusDot"></span>
                        <span style="font-size: 12px; font-weight: 600; color: #94a3b8; font-family: var(--mono);">Console Output</span>
                    </div>
                    <div style="display: flex; align-items: center; gap: 8px;">
                        <span id="terminalTaskStatusBadge" class="badge badge-neutral" style="font-size: 11px;">Idle</span>
                        <button type="button" class="btn btn-ghost btn-icon btn-sm" onclick="clearTerminalOutput()" title="Bersihkan tampilan output" style="color: #94a3b8; width: 28px; height: 28px;">
                            <?= ui_icon('x') ?>
                        </button>
                    </div>
                </div>
                <pre id="laravelTerminalContent" style="margin: 0; padding: 14px 16px; min-height: 180px; max-height: 280px; overflow-y: auto; font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, 'Liberation Mono', monospace; font-size: 12px; line-height: 1.6; color: #e2e8f0; white-space: pre-wrap; word-break: break-all;">Pilih aksi di atas (misal: 'Run Migration' atau 'Hubungkan Database') untuk melihat output perintah di sini...</pre>
            </div>

        </div>

        <!-- Footer -->
        <div class="modal-footer" style="padding: 12px 20px; background: var(--surface-2); border-top: 1px solid var(--border); display: flex; align-items: center; justify-content: space-between; flex-shrink: 0;">
            <div style="font-size: 12px; color: var(--text-3);">
                Tip: Pastikan server MySQL aktif saat menjalankan migrasi database.
            </div>
            <button type="button" class="btn btn-secondary btn-sm" onclick="closeLaravelModal()">Tutup</button>
        </div>
    </div>
</div>

<script>
var currentLogSiteId = null;
var currentLogDomain = '';
var currentLogType = 'access';
var logRefreshInterval = null;
var isLogAutoRefreshActive = true;
var isFetchingLogs = false;

function openSiteLogModal(siteId, domain) {
    currentLogSiteId = siteId;
    currentLogDomain = domain;
    currentLogType = 'access';
    isLogAutoRefreshActive = true;

    document.getElementById('siteLogDomainText').textContent = domain;
    document.getElementById('siteLogPathDisplay').textContent = '/var/log/nginx/' + domain + '_access.log';
    document.getElementById('siteLogContent').innerHTML = '<div style="color: #6b7280; padding: 30px 0; text-align: center;">Memuat log…</div>';
    document.getElementById('siteLogSizeDisplay').textContent = '-';
    document.getElementById('siteLogCountDisplay').textContent = '-';
    document.getElementById('siteLogUpdatedDisplay').textContent = '-';

    updateLogAutoRefreshUI();
    switchSiteLogTab('access', false);

    Almusa.openModal('siteLogModal');
    fetchSiteLogs(true);
    startLogAutoRefresh();
}

function closeSiteLogModal() {
    stopLogAutoRefresh();
    Almusa.closeModal('siteLogModal');
    currentLogSiteId = null;
}

function switchSiteLogTab(type, doFetch) {
    currentLogType = type;
    document.getElementById('tabBtnLogAccess').classList.toggle('active', type === 'access');
    document.getElementById('tabBtnLogError').classList.toggle('active', type === 'error');
    document.getElementById('siteLogPathDisplay').textContent = '/var/log/nginx/' + currentLogDomain + '_' + type + '.log';

    if (doFetch !== false) {
        fetchSiteLogs(true);
    }
}

function onSiteLogLinesChange() {
    fetchSiteLogs(true);
}

function toggleLogAutoRefresh() {
    isLogAutoRefreshActive = !isLogAutoRefreshActive;
    updateLogAutoRefreshUI();
    if (isLogAutoRefreshActive) {
        startLogAutoRefresh();
        fetchSiteLogs(false);
    } else {
        stopLogAutoRefresh();
    }
}

function updateLogAutoRefreshUI() {
    var dot = document.getElementById('logLiveDot');
    var text = document.getElementById('logAutoRefreshText');
    if (dot && text) {
        if (isLogAutoRefreshActive) {
            dot.className = 'live-dot live-active';
            text.textContent = 'Auto-refresh (3s)';
        } else {
            dot.className = 'live-dot live-paused';
            text.textContent = 'Auto-refresh: Pause';
        }
    }
}

function startLogAutoRefresh() {
    stopLogAutoRefresh();
    logRefreshInterval = setInterval(function () {
        var modal = document.getElementById('siteLogModal');
        if (!modal || !modal.classList.contains('show')) {
            stopLogAutoRefresh();
            return;
        }
        if (isLogAutoRefreshActive && currentLogSiteId) {
            fetchSiteLogs(false);
        }
    }, 3000);
}

function stopLogAutoRefresh() {
    if (logRefreshInterval) {
        clearInterval(logRefreshInterval);
        logRefreshInterval = null;
    }
}

async function fetchSiteLogs(showSpinner) {
    if (!currentLogSiteId || isFetchingLogs) return;
    isFetchingLogs = true;

    var loadingEl = document.getElementById('siteLogLoading');
    if (showSpinner && loadingEl) {
        loadingEl.style.display = 'flex';
    }

    var linesLimit = document.getElementById('siteLogLinesSelect').value || 50;

    try {
        var res = await Almusa.get('/api/sites/logs?site_id=' + currentLogSiteId + '&type=' + currentLogType + '&lines=' + linesLimit);
        if (res.success) {
            renderSiteLogLines(res);
        } else {
            document.getElementById('siteLogContent').innerHTML = '<div style="color: #f87171; padding: 30px 0; text-align: center;">Gagal memuat log: ' + Almusa.escapeHtml(res.error || 'Terjadi kesalahan') + '</div>';
        }
    } catch (e) {
        console.error('Error fetching logs:', e);
    } finally {
        isFetchingLogs = false;
        if (loadingEl) {
            loadingEl.style.display = 'none';
        }
    }
}

function renderSiteLogLines(data) {
    document.getElementById('siteLogSizeDisplay').textContent = data.size_human || '0 B';
    document.getElementById('siteLogCountDisplay').textContent = (data.total_lines || 0) + ' baris';
    document.getElementById('siteLogUpdatedDisplay').textContent = data.updated_at || '-';

    var container = document.getElementById('siteLogContainer');
    var contentEl = document.getElementById('siteLogContent');
    
    // Auto-scroll if user is already near bottom (within 60px)
    var isAtBottom = (container.scrollHeight - container.scrollTop - container.clientHeight) < 60;

    if (!data.exists || !data.lines || data.lines.length === 0) {
        contentEl.innerHTML = '<div style="color: #64748b; padding: 40px 0; text-align: center; font-style: italic;">Log ' + Almusa.escapeHtml(data.type) + ' kosong atau belum ada entri terbaru.</div>';
        return;
    }

    var html = '';
    var lines = data.lines;
    for (var i = 0; i < lines.length; i++) {
        var raw = lines[i];
        html += '<div style="padding: 2px 0; border-bottom: 1px solid rgba(255,255,255,0.03); display: flex; gap: 12px; font-family: ui-monospace, Consolas, monospace;">'
              + '<span style="color: #475569; user-select: none; width: 34px; text-align: right; flex-shrink: 0; font-size: 11px;">' + (i + 1) + '</span>'
              + '<span style="flex: 1; word-break: break-all;">' + formatLogLine(raw, data.type) + '</span>'
              + '</div>';
    }

    contentEl.innerHTML = html;

    if (isAtBottom) {
        scrollSiteLogToBottom();
    }
}

function formatLogLine(raw, type) {
    var escaped = Almusa.escapeHtml(raw);
    if (type === 'access') {
        // Highlight HTTP method and status codes
        escaped = escaped.replace(/ ("GET|POST|PUT|DELETE|HEAD|OPTIONS|PATCH) /g, ' <strong style="color:#38bdf8;">$1</strong> ')
                         .replace(/ (2\d\d) /g, ' <span style="color:#4ade80; font-weight:600;">$1</span> ')
                         .replace(/ (3\d\d) /g, ' <span style="color:#38bdf8; font-weight:600;">$1</span> ')
                         .replace(/ (4\d\d) /g, ' <span style="color:#fb923c; font-weight:600;">$1</span> ')
                         .replace(/ (5\d\d) /g, ' <span style="color:#f87171; font-weight:700;">$1</span> ');
    } else {
        // Highlight error log levels
        escaped = escaped.replace(/\[(error|emerg|alert|crit)\]/gi, '<strong style="color:#f87171;">[$1]</strong>')
                         .replace(/\[(warn|warning)\]/gi, '<strong style="color:#facc15;">[$1]</strong>')
                         .replace(/\[(notice|info)\]/gi, '<strong style="color:#38bdf8;">[$1]</strong>');
    }
    return escaped;
}

function scrollSiteLogToBottom() {
    var container = document.getElementById('siteLogContainer');
    if (container) {
        container.scrollTop = container.scrollHeight;
    }
}

async function clearSiteLogs() {
    if (!currentLogSiteId) return;

    var ok = await Almusa.confirm({
        title: 'Kosongkan File Log',
        message: 'Apakah Anda yakin ingin mengosongkan log ' + currentLogType.toUpperCase() + ' untuk domain ' + currentLogDomain + '?\nData riwayat log yang dihapus tidak dapat dipulihkan.',
        confirmText: 'Kosongkan Sekarang',
        danger: true
    });
    if (!ok) return;

    var btn = document.getElementById('btnLogClear');
    var restore = Almusa.busy(btn, 'Mengosongkan…');

    try {
        var res = await Almusa.post('/api/sites/clear-log', {
            site_id: currentLogSiteId,
            type: currentLogType
        });
        if (res.success) {
            Almusa.toast(res.message || 'Log berhasil dikosongkan.', 'success');
            setTimeout(function () {
                fetchSiteLogs(true);
            }, 600);
        } else {
            Almusa.toast(res.error || 'Gagal mengosongkan log.', 'error');
        }
    } catch (e) {
        Almusa.toast('Terjadi kesalahan jaringan.', 'error');
    } finally {
        restore();
    }
}

// ==============================================================================
// Laravel Toolkit & Database Connector Handlers
// ==============================================================================
var currentLaravelSiteId = null;
var currentLaravelDomain = '';
var artisanPollingTimer = null;
var activeArtisanTaskId = null;

async function openLaravelModal(siteId, domain) {
    currentLaravelSiteId = siteId;
    currentLaravelDomain = domain;

    document.getElementById('laravelDomainText').textContent = domain;
    document.getElementById('laravelProjectPathDisplay').textContent = 'Memuat…';
    document.getElementById('laravelDbStatusBadge').innerHTML = '<span class="badge badge-neutral">Memeriksa .env…</span>';
    document.getElementById('laravelCurrentEnvInfo').style.display = 'flex';
    document.getElementById('envConnectionVal').textContent = '…';
    document.getElementById('envDatabaseVal').textContent = '…';
    document.getElementById('envUserVal').textContent = '…';
    document.getElementById('envHostVal').textContent = '…';

    Almusa.openModal('laravelModal');
    await loadLaravelDetails(siteId);
}

function closeLaravelModal() {
    stopArtisanPolling();
    Almusa.closeModal('laravelModal');
    currentLaravelSiteId = null;
}

async function loadLaravelDetails(siteId) {
    try {
        var res = await Almusa.get('/api/sites/laravel/status?site_id=' + siteId);
        if (!res.success) {
            Almusa.toast(res.error || 'Gagal memuat status Laravel.', 'error');
            return;
        }

        document.getElementById('laravelProjectPathDisplay').textContent = res.project_path;
        document.getElementById('laravelPhpBadge').textContent = 'PHP ' + res.php_version;

        // Render Databases dropdown
        var select = document.getElementById('laravelDbSelect');
        select.innerHTML = '';
        if (res.databases && res.databases.length > 0) {
            var optDefault = document.createElement('option');
            optDefault.value = '';
            optDefault.textContent = '-- Pilih Database MySQL dari Panel --';
            select.appendChild(optDefault);

            res.databases.forEach(function(db) {
                var opt = document.createElement('option');
                opt.value = db.id;
                opt.textContent = db.db_name + ' (User: ' + db.db_user + ' @ ' + db.access_host + ')';
                // Auto-select if matching currently configured database in .env
                if (res.env_config && res.env_config.database === db.db_name) {
                    opt.selected = true;
                }
                select.appendChild(opt);
            });
        } else {
            var optEmpty = document.createElement('option');
            optEmpty.value = '';
            optEmpty.textContent = 'Belum ada database di panel. Buat dulu di menu Databases!';
            select.appendChild(optEmpty);
        }

        // Render current .env DB info & status badge
        var env = res.env_config || {};
        document.getElementById('envConnectionVal').textContent = env.connection || '-';
        document.getElementById('envDatabaseVal').textContent = env.database || '(kosong)';
        document.getElementById('envUserVal').textContent = env.username || '(kosong)';
        document.getElementById('envHostVal').textContent = (env.host || '127.0.0.1') + (env.port ? ':' + env.port : '');

        var statusBadge = document.getElementById('laravelDbStatusBadge');
        if (env.connection === 'mysql' && env.database) {
            statusBadge.innerHTML = '<span class="badge badge-success no-dot">✓ Terhubung ke MySQL: ' + Almusa.escapeHtml(env.database) + '</span>';
        } else if (env.connection === 'sqlite') {
            statusBadge.innerHTML = '<span class="badge badge-warning no-dot">Mode SQLite (Belum terhubung MySQL)</span>';
        } else {
            statusBadge.innerHTML = '<span class="badge badge-neutral no-dot">Driver: ' + Almusa.escapeHtml(env.connection || 'None') + '</span>';
        }

    } catch (e) {
        Almusa.toast('Terjadi kesalahan memuat detail Laravel.', 'error');
    }
}

async function connectLaravelDb() {
    if (!currentLaravelSiteId) return;

    var dbSelect = document.getElementById('laravelDbSelect');
    var dbId = dbSelect.value;
    if (!dbId) {
        Almusa.toast('Pilih salah satu database MySQL terlebih dahulu.', 'warning');
        return;
    }

    var btn = document.getElementById('btnSyncLaravelDb');
    var restore = Almusa.busy(btn, 'Menyinkronkan .env…');

    try {
        var res = await Almusa.post('/api/sites/laravel/connect-db', {
            site_id: currentLaravelSiteId,
            database_id: dbId
        });

        if (res.success) {
            Almusa.toast(res.message, 'success');
            appendTerminalOutput('\n[INFO] ' + res.message);
            appendTerminalOutput('[INFO] File .env diperbarui: DB_CONNECTION=mysql, DB_DATABASE=' + res.db_name + ', DB_USERNAME=' + res.db_user);
            await loadLaravelDetails(currentLaravelSiteId);
        } else {
            Almusa.toast(res.error || 'Gagal menyinkronkan .env.', 'error');
            appendTerminalOutput('\n[ERROR] ' + (res.error || 'Gagal'));
        }
    } catch (e) {
        Almusa.toast('Terjadi kesalahan jaringan.', 'error');
    } finally {
        restore();
    }
}

function runArtisanWithSeed(command) {
    var withSeed = document.getElementById('artisanSeedOption').checked;
    runArtisanCmd(command, withSeed ? '--seed' : '');
}

async function runArtisanFresh() {
    var ok = await Almusa.confirm({
        title: 'Konfirmasi Fresh Migrate',
        message: 'PERINGATAN: `php artisan migrate:fresh` akan MENGHAPUS SEMUA TABEL di database terpilih lalu membuat tabel dari awal!\nApakah Anda yakin ingin melanjutkan?',
        confirmText: 'Ya, Drop & Migrate Fresh',
        danger: true
    });
    if (!ok) return;

    var withSeed = document.getElementById('artisanSeedOption').checked;
    runArtisanCmd('migrate:fresh', withSeed ? '--seed' : '');
}

async function runArtisanCmd(command, extraArgs) {
    if (!currentLaravelSiteId) return;

    stopArtisanPolling();
    setTerminalStatus('processing', 'Memulai ' + command + '…');
    appendTerminalOutput('\n[REQUEST] Menjadwalkan php artisan ' + command + (extraArgs ? ' ' + extraArgs : '') + '...');

    try {
        var res = await Almusa.post('/api/sites/laravel/artisan', {
            site_id: currentLaravelSiteId,
            command: command,
            extra_args: extraArgs || ''
        });

        if (res.success) {
            Almusa.toast(res.message, 'info');
            activeArtisanTaskId = res.task_id;
            appendTerminalOutput('[TASK #' + res.task_id + '] Task berhasil dimasukkan ke antrean daemon.');
            startArtisanPolling(res.task_id);
        } else {
            setTerminalStatus('failed', 'Gagal memulai');
            Almusa.toast(res.error || 'Gagal menjalankan perintah artisan.', 'error');
            appendTerminalOutput('[ERROR] ' + (res.error || 'Gagal'));
        }
    } catch (e) {
        setTerminalStatus('failed', 'Error jaringan');
        Almusa.toast('Terjadi kesalahan saat mengirim perintah.', 'error');
    }
}

function startArtisanPolling(taskId) {
    stopArtisanPolling();
    var pollCount = 0;

    artisanPollingTimer = setInterval(async function() {
        pollCount++;
        try {
            var res = await Almusa.get('/api/tasks/status');
            if (res.success) {
                if (res.log) {
                    var term = document.getElementById('laravelTerminalContent');
                    term.textContent = res.log;
                    term.scrollTop = term.scrollHeight;
                }

                var running = res.running_task;
                if (running && parseInt(running.id) === taskId) {
                    setTerminalStatus('processing', 'Sedang memproses (Task #' + taskId + ')');
                } else {
                    var last = res.last_task;
                    if (last && parseInt(last.id) === taskId) {
                        if (last.status === 'completed') {
                            setTerminalStatus('completed', 'Selesai (Sukses)');
                            Almusa.toast('Artisan command selesai dengan sukses!', 'success');
                            loadLaravelDetails(currentLaravelSiteId);
                        } else {
                            setTerminalStatus('failed', 'Gagal (Exit code: ' + (last.exit_code || 1) + ')');
                            Almusa.toast('Artisan command gagal atau keluar dengan error.', 'error');
                        }
                        stopArtisanPolling();
                    } else if (pollCount > 60) {
                        stopArtisanPolling();
                        setTerminalStatus('idle', 'Polling selesai');
                    }
                }
            }
        } catch (e) {
            // Ignore
        }
    }, 1200);
}

function stopArtisanPolling() {
    if (artisanPollingTimer) {
        clearInterval(artisanPollingTimer);
        artisanPollingTimer = null;
    }
}

function setTerminalStatus(status, text) {
    var dot = document.getElementById('terminalStatusDot');
    var badge = document.getElementById('terminalTaskStatusBadge');

    if (badge) badge.textContent = text;
    if (dot) {
        if (status === 'processing') {
            dot.style.background = '#38bdf8';
            if (badge) badge.className = 'badge badge-primary';
        } else if (status === 'completed') {
            dot.style.background = '#22c55e';
            if (badge) badge.className = 'badge badge-success';
        } else if (status === 'failed') {
            dot.style.background = '#ef4444';
            if (badge) badge.className = 'badge badge-danger';
        } else {
            dot.style.background = '#94a3b8';
            if (badge) badge.className = 'badge badge-neutral';
        }
    }
}

function appendTerminalOutput(text) {
    var term = document.getElementById('laravelTerminalContent');
    if (term) {
        term.textContent += (term.textContent ? '\n' : '') + text;
        term.scrollTop = term.scrollHeight;
    }
}

function clearTerminalOutput() {
    var term = document.getElementById('laravelTerminalContent');
    if (term) {
        term.textContent = 'Terminal dibersihkan.';
    }
    setTerminalStatus('idle', 'Idle');
}
</script>
