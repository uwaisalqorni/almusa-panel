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

<!-- Modal Kelola SSL / Let's Encrypt -->
<div class="modal-backdrop" id="sslModal">
    <div class="modal modal-md" role="dialog" aria-modal="true" aria-labelledby="sslModalTitle">
        <div class="modal-header">
            <div>
                <h2 class="modal-title" id="sslModalTitle">Sertifikat SSL / HTTPS</h2>
                <p class="modal-subtitle">Kelola sertifikat Let's Encrypt untuk <strong id="sslDomainTitle"></strong></p>
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
                                <div class="text-muted" style="color: var(--text-3); font-size: 12px;">Auto-Renewal</div>
                                <div style="font-weight: 500; color: var(--ok);">Aktif (Systemd Timer)</div>
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

                <!-- Form Issue SSL -->
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
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-secondary" onclick="closeSslModal()">Tutup</button>
        </div>
    </div>
</div>

<script>
var currentSslSiteId = null;
var currentSslDomain = null;

async function openSslModal(siteId, domain) {
    currentSslSiteId = siteId;
    currentSslDomain = domain;

    document.getElementById('sslDomainTitle').textContent = domain;
    document.getElementById('sslEmail').value = 'admin@' + domain;
    document.getElementById('sslForceHttps').checked = true;

    document.getElementById('sslLoadingState').style.display = 'block';
    document.getElementById('sslContentArea').style.display = 'none';

    Almusa.openModal('sslModal');

    try {
        var data = await Almusa.get('/api/sites/ssl-status?site_id=' + siteId);
        document.getElementById('sslLoadingState').style.display = 'none';
        document.getElementById('sslContentArea').style.display = 'block';

        var activeCard = document.getElementById('sslActiveCard');
        var issueText = document.getElementById('btnSubmitIssueText');

        if (data.has_cert && data.details) {
            activeCard.style.display = 'block';
            document.getElementById('sslSubjectText').textContent = data.details.subject || domain;
            document.getElementById('sslIssuerText').textContent = data.details.issuer || "Let's Encrypt";
            document.getElementById('sslValidDates').textContent = (data.details.valid_from ? data.details.valid_from.substring(0, 10) : '') + ' s/d ' + (data.details.valid_to ? data.details.valid_to.substring(0, 10) : '');

            var daysBadge = document.getElementById('sslDaysBadge');
            if (data.details.is_expired) {
                daysBadge.className = 'badge badge-danger';
                daysBadge.textContent = 'Kedaluwarsa';
            } else {
                daysBadge.className = 'badge badge-success';
                daysBadge.textContent = 'Sisa ' + data.details.days_left + ' hari';
            }
            issueText.textContent = 'Terbitkan Ulang Sertifikat SSL';
        } else {
            activeCard.style.display = 'none';
            issueText.textContent = 'Terbitkan SSL Sekarang (1-Click Let\'s Encrypt)';
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
</script>
