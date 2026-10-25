<?php
/**
 * Almusa Control Panel - Websites & Virtual Hosts View
 */
?>

<div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 24px;">
    <div>
        <h2 style="font-size: 20px; font-weight: 700; color: #ffffff;">Websites & Virtual Hosts</h2>
        <p style="font-size: 13px; color: var(--text-muted);">Kelola Virtual Host, document root, switch versi PHP instan, dan integrasi Cloudflare Tunnel</p>
    </div>
    <button type="button" class="btn btn-primary" onclick="openAddSiteModal()">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <line x1="12" y1="5" x2="12" y2="19"></line>
            <line x1="5" y1="12" x2="19" y2="12"></line>
        </svg>
        Tambah Website Baru
    </button>
</div>

<div class="table-container">
    <table class="custom-table">
        <thead>
            <tr>
                <th>Domain / Host</th>
                <th>Document Root</th>
                <th>Versi PHP</th>
                <th>URL Rewrite</th>
                <th>Cloudflare Tunnel</th>
                <th>Status</th>
                <th>Aksi</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($sites)): ?>
                <tr>
                    <td colspan="7" style="text-align: center; padding: 48px; color: var(--text-muted);">
                        <svg width="36" height="36" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" style="margin: 0 auto 12px; display: block; opacity: 0.5;">
                            <circle cx="12" cy="12" r="10"></circle>
                            <line x1="2" y1="12" x2="22" y2="12"></line>
                            <path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"></path>
                        </svg>
                        Belum ada website yang dikonfigurasi. Klik <strong>Tambah Website Baru</strong> untuk memulai.
                    </td>
                </tr>
            <?php else: ?>
                <?php foreach ($sites as $site): ?>
                    <tr>
                        <td>
                            <strong style="color: #ffffff; font-size: 14px;"><?= htmlspecialchars($site['domain']) ?></strong>
                        </td>
                        <td>
                            <code><?= htmlspecialchars($site['docroot']) ?></code>
                        </td>
                        <td>
                            <!-- Instant PHP Switcher Selector -->
                            <select class="form-control" style="width: auto; padding: 4px 8px; font-size: 12px; display: inline-block;" onchange="switchSitePhp(<?= $site['id'] ?>, this.value)">
                                <?php foreach ($phpVersions as $ver): ?>
                                    <option value="<?= $ver ?>" <?= ($site['php_version'] === $ver ? 'selected' : '') ?>>
                                        PHP <?= $ver ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                        <td>
                            <span class="badge badge-neutral"><?= ucfirst(htmlspecialchars($site['rewrite_template'] ?? 'default')) ?></span>
                        </td>
                        <td>
                            <?php if (!empty($site['tunnel_url'])): ?>
                                <a href="<?= htmlspecialchars($site['tunnel_url']) ?>" target="_blank" style="color: #38bdf8; font-weight: 500;">
                                    <?= htmlspecialchars($site['tunnel_url']) ?> ↗
                                </a>
                            <?php else: ?>
                                <button type="button" class="btn btn-secondary btn-sm" onclick="startQuickTunnel(<?= $site['id'] ?>, '<?= htmlspecialchars($site['domain']) ?>')">
                                    <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                        <path d="M17.5 19H9a7 7 0 1 1 6.71-9h1.79a4.5 4.5 0 1 1 0 9Z"></path>
                                    </svg>
                                    Quick Online
                                </button>
                            <?php endif; ?>
                        </td>
                        <td>
                            <span class="badge <?= ($site['status'] === 'active' ? 'badge-success' : 'badge-neutral') ?>">
                                <?= htmlspecialchars(ucfirst($site['status'])) ?>
                            </span>
                        </td>
                        <td>
                            <div style="display: flex; gap: 6px;">
                                <button type="button" class="btn btn-danger btn-sm" onclick="openDeleteSiteModal(<?= $site['id'] ?>, '<?= htmlspecialchars($site['domain']) ?>', '<?= htmlspecialchars($site['docroot']) ?>')">
                                    Hapus
                                </button>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<!-- MODAL ADD WEBSITE -->
<div class="modal-backdrop" id="addSiteModal">
    <div class="card" style="width: 100%; max-width: 520px; box-shadow: 0 25px 60px rgba(0,0,0,0.8);">
        <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 20px;">
            <h3 style="font-size: 18px; font-weight: 700; color: #ffffff;">Tambah Website Baru</h3>
            <button type="button" class="btn btn-secondary btn-sm" onclick="closeAddSiteModal()">✕</button>
        </div>

        <form id="addSiteForm" onsubmit="submitAddSite(event)">
            <div class="form-group">
                <label class="form-label">Nama Domain (contoh: mysite.local, app.domain.com)</label>
                <input type="text" id="siteDomain" class="form-control" placeholder="proyek-saya.com" required>
            </div>

            <div class="form-group">
                <label class="form-label">Document Root</label>
                <input type="text" id="siteDocroot" class="form-control" placeholder="/www/wwwroot/proyek-saya.com">
                <div class="form-help">Biarkan kosong untuk menggunakan path default otomatis.</div>
            </div>

            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px;">
                <div class="form-group">
                    <label class="form-label">Versi PHP</label>
                    <select id="sitePhp" class="form-control">
                        <?php foreach ($phpVersions as $ver): ?>
                            <option value="<?= $ver ?>" <?= ($ver === '8.3' ? 'selected' : '') ?>>PHP <?= $ver ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label class="form-label">Template URL Rewrite</label>
                    <select id="siteRewrite" class="form-control">
                        <option value="default">Default / Plain</option>
                        <option value="laravel">Laravel / Lumen</option>
                        <option value="wordpress">WordPress</option>
                        <option value="thinkphp">ThinkPHP</option>
                    </select>
                </div>
            </div>

            <div style="display: flex; justify-content: flex-end; gap: 10px; margin-top: 24px;">
                <button type="button" class="btn btn-secondary" onclick="closeAddSiteModal()">Batal</button>
                <button type="submit" class="btn btn-primary">Simpan & Buat Virtual Host</button>
            </div>
        </form>
    </div>
</div>

<!-- MODAL DELETE WEBSITE -->
<div class="modal-backdrop" id="deleteSiteModal">
    <div class="card" style="width: 100%; max-width: 480px; box-shadow: 0 25px 60px rgba(0,0,0,0.8);">
        <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 20px;">
            <h3 style="font-size: 18px; font-weight: 700; color: #ef4444;">Hapus Virtual Host Website</h3>
            <button type="button" class="btn btn-secondary btn-sm" onclick="closeDeleteSiteModal()">✕</button>
        </div>

        <p style="font-size: 14px; color: var(--text-secondary); margin-bottom: 16px; line-height: 1.5;">
            Apakah Anda yakin ingin menghapus website <strong id="delDomainText" style="color: #ffffff;"></strong>? Konfigurasi Nginx dan Virtual Host akan dicabut.
        </p>

        <div style="background: rgba(239, 68, 68, 0.08); border: 1px solid rgba(239, 68, 68, 0.25); border-radius: 8px; padding: 14px; margin-bottom: 20px;">
            <label style="display: flex; align-items: center; gap: 10px; cursor: pointer; color: #f87171; font-size: 13px; font-weight: 600;">
                <input type="checkbox" id="delFilesCheck" value="1" checked style="width: 18px; height: 18px; cursor: pointer;">
                <span>Hapus juga folder dan seluruh file proyek di direktori:</span>
            </label>
            <div id="delDocrootText" style="margin-top: 6px; font-family: monospace; font-size: 12px; color: #ffffff; padding-left: 28px; word-break: break-all;"></div>
        </div>

        <div style="display: flex; justify-content: flex-end; gap: 10px;">
            <button type="button" class="btn btn-secondary" onclick="closeDeleteSiteModal()">Batal</button>
            <button type="button" class="btn btn-danger" id="confirmDeleteBtn" onclick="confirmDeleteSite()">Hapus Sekarang</button>
        </div>
    </div>
</div>

<script>
function openAddSiteModal() {
    document.getElementById('addSiteModal').classList.add('show');
}

function closeAddSiteModal() {
    document.getElementById('addSiteModal').classList.remove('show');
}

async function submitAddSite(e) {
    e.preventDefault();
    const domain = document.getElementById('siteDomain').value.trim();
    const docroot = document.getElementById('siteDocroot').value.trim();
    const php_version = document.getElementById('sitePhp').value;
    const rewrite_template = document.getElementById('siteRewrite').value;
    const csrf = document.querySelector('meta[name="csrf-token"]').getAttribute('content');

    try {
        const res = await fetch('/api/sites/create', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
                'X-CSRF-TOKEN': csrf
            },
            body: `_csrf_token=${encodeURIComponent(csrf)}&domain=${encodeURIComponent(domain)}&docroot=${encodeURIComponent(docroot)}&php_version=${encodeURIComponent(php_version)}&rewrite_template=${encodeURIComponent(rewrite_template)}`
        });

        const data = await res.json();
        if (data.success) {
            closeAddSiteModal();
            openTaskTerminal();
            setTimeout(() => location.reload(), 2000);
        } else {
            alert(data.error || 'Gagal menambahkan website.');
        }
    } catch (err) {
        alert('Terjadi kesalahan jaringan.');
    }
}

async function switchSitePhp(siteId, newVersion) {
    if (!confirm(`Ubah versi PHP website ini ke PHP ${newVersion}?`)) {
        location.reload();
        return;
    }

    const csrf = document.querySelector('meta[name="csrf-token"]').getAttribute('content');
    try {
        const res = await fetch('/api/sites/switch-php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
                'X-CSRF-TOKEN': csrf
            },
            body: `_csrf_token=${encodeURIComponent(csrf)}&site_id=${siteId}&php_version=${encodeURIComponent(newVersion)}`
        });
        const data = await res.json();
        if (data.success) {
            openTaskTerminal();
        } else {
            alert(data.error || 'Gagal mengubah versi PHP.');
            location.reload();
        }
    } catch (e) {
        alert('Terjadi kesalahan jaringan.');
    }
}

let currentDeleteSiteId = null;
let currentDeleteDomain = null;

function openDeleteSiteModal(siteId, domain, docroot) {
    currentDeleteSiteId = siteId;
    currentDeleteDomain = domain;
    document.getElementById('delDomainText').innerText = domain;
    document.getElementById('delDocrootText').innerText = docroot || `/www/wwwroot/${domain}`;
    document.getElementById('delFilesCheck').checked = true;
    document.getElementById('deleteSiteModal').classList.add('show');
}

function closeDeleteSiteModal() {
    document.getElementById('deleteSiteModal').classList.remove('show');
    currentDeleteSiteId = null;
    currentDeleteDomain = null;
}

async function confirmDeleteSite() {
    if (!currentDeleteSiteId) return;

    const deleteFiles = document.getElementById('delFilesCheck').checked ? 1 : 0;
    const btn = document.getElementById('confirmDeleteBtn');
    btn.disabled = true;
    btn.innerText = 'Menghapus...';

    const csrf = document.querySelector('meta[name="csrf-token"]').getAttribute('content');
    try {
        const res = await fetch('/api/sites/delete', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
                'X-CSRF-TOKEN': csrf
            },
            body: `_csrf_token=${encodeURIComponent(csrf)}&site_id=${currentDeleteSiteId}&delete_files=${deleteFiles}`
        });
        const data = await res.json();
        if (data.success) {
            closeDeleteSiteModal();
            openTaskTerminal();
            setTimeout(() => location.reload(), 1500);
        } else {
            alert(data.error || 'Gagal menghapus website.');
            btn.disabled = false;
            btn.innerText = 'Hapus Sekarang';
        }
    } catch (e) {
        alert('Terjadi kesalahan jaringan.');
        btn.disabled = false;
        btn.innerText = 'Hapus Sekarang';
    }
}

// Fallback direct delete
async function deleteSite(siteId, domain) {
    openDeleteSiteModal(siteId, domain, `/www/wwwroot/${domain}`);
}

async function startQuickTunnel(siteId, domain) {
    const csrf = document.querySelector('meta[name="csrf-token"]').getAttribute('content');
    try {
        const res = await fetch('/api/tunnels/quick-start', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
                'X-CSRF-TOKEN': csrf
            },
            body: `_csrf_token=${encodeURIComponent(csrf)}&site_id=${siteId}&domain=${encodeURIComponent(domain)}`
        });
        const data = await res.json();
        if (data.success) {
            openTaskTerminal();
            setTimeout(() => location.reload(), 4000);
        } else {
            alert(data.error || 'Gagal memulai tunnel.');
        }
    } catch (e) {
        alert('Terjadi kesalahan jaringan.');
    }
}
</script>
