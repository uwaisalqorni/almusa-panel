<?php
/**
 * Almusa Control Panel - Cloudflare Tunnels View
 */
?>

<header class="page-header">
    <div>
        <h1 class="page-title">Cloudflare Tunnel</h1>
        <p class="page-desc">Publikasikan website lokal ke internet dengan aman, tanpa port forwarding.</p>
    </div>
    <div class="page-actions">
        <button type="button" class="btn btn-secondary" onclick="openQuickTunnelModal()">
            <?= ui_icon('zap') ?> Quick Tunnel
        </button>
        <button type="button" class="btn btn-primary" onclick="openCustomDomainModal()">
            <?= ui_icon('globe') ?> Domain sendiri
        </button>
    </div>
</header>

<section class="grid grid-2 mb" aria-label="Status integrasi">
    <div class="card card-pad">
        <div class="row" style="padding:0;border:0">
            <span class="icon-tile"><?= ui_icon('terminal') ?></span>
            <div class="row-main">
                <h2 class="row-title">Binary cloudflared</h2>
                <div class="row-sub"><code>/usr/local/bin/cloudflared</code></div>
            </div>
            <?php if ($isBinaryInstalled): ?>
                <span class="badge badge-success">Terpasang</span>
            <?php else: ?>
                <a href="/plugins" class="badge badge-warning">Belum ada · Pasang</a>
            <?php endif; ?>
        </div>
    </div>
    <div class="card card-pad">
        <div class="row" style="padding:0;border:0">
            <span class="icon-tile"><?= ui_icon('shield') ?></span>
            <div class="row-main">
                <h2 class="row-title">Cloudflare Zero Trust API</h2>
                <div class="row-sub">Ingress &amp; otomasi DNS untuk domain sendiri</div>
            </div>
            <?php if ($isApiReady): ?>
                <span class="badge badge-success">Terhubung</span>
            <?php else: ?>
                <a href="/settings" class="badge badge-neutral">Atur token</a>
            <?php endif; ?>
        </div>
    </div>
</section>

<section class="card card-flush">
    <div class="card-header">
        <div>
            <h2 class="card-title">Tunnel</h2>
            <p class="card-desc">Daftar tunnel yang terdaftar di panel</p>
        </div>
        <button type="button" class="btn btn-secondary btn-sm" onclick="openTaskTerminal()"><?= ui_icon('terminal') ?> Terminal</button>
    </div>
    <div class="card-body">
        <div class="table-wrap">
            <table class="table">
                <thead>
                    <tr>
                        <th>Website</th>
                        <th>Tipe</th>
                        <th>URL publik</th>
                        <th>Layanan lokal</th>
                        <th>PID</th>
                        <th>Status</th>
                        <th class="th-right">Aksi</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($tunnels)): ?>
                    <tr>
                        <td colspan="7">
                            <div class="empty">
                                <?= ui_icon('cloud', 'icon-xl') ?>
                                <div class="empty-title">Belum ada tunnel</div>
                                <p class="empty-text">Mulai Quick Tunnel untuk mendapatkan URL publik sementara, atau hubungkan domain Anda sendiri.</p>
                                <button type="button" class="btn btn-primary" onclick="openQuickTunnelModal()"><?= ui_icon('zap') ?> Mulai Quick Tunnel</button>
                            </div>
                        </td>
                    </tr>
                <?php else: foreach ($tunnels as $tunnel):
                    $host = (string)($tunnel['public_hostname'] ?? '');
                    $href = preg_match('#^https?://#', $host) ? $host : 'https://' . $host;
                ?>
                    <tr>
                        <td><div class="cell-title"><?= e($tunnel['domain'] ?? 'N/A') ?></div></td>
                        <td>
                            <span class="badge <?= $tunnel['tunnel_type'] === 'quick' ? 'badge-info' : 'badge-success' ?> no-dot">
                                <?= $tunnel['tunnel_type'] === 'quick' ? 'Quick' : 'Custom' ?>
                            </span>
                        </td>
                        <td>
                            <?php if ($host !== ''): ?>
                                <a href="<?= e($href) ?>" target="_blank" rel="noopener" class="cell-link">
                                    <span class="truncate"><?= e(preg_replace('#^https?://#', '', $host)) ?></span>
                                    <?= ui_icon('external') ?>
                                </a>
                            <?php else: ?>
                                <span class="text-3">—</span>
                            <?php endif; ?>
                        </td>
                        <td><code><?= e($tunnel['local_service']) ?></code></td>
                        <td class="num"><?= e($tunnel['pid'] ?? '—') ?></td>
                        <td>
                            <span class="badge <?= $tunnel['status'] === 'running' ? 'badge-success' : 'badge-neutral' ?>">
                                <?= e($tunnel['status'] === 'running' ? 'Berjalan' : ucfirst((string)$tunnel['status'])) ?>
                            </span>
                        </td>
                        <td>
                            <div class="cell-actions">
                                <?php if ($tunnel['tunnel_type'] === 'quick'): ?>
                                    <button type="button" class="btn btn-secondary btn-sm" onclick="showQrCodeModal(<?= js_arg($href) ?>, <?= js_arg($tunnel['domain'] ?? '') ?>)" title="Scan QR Code di Smartphone">
                                        <?= ui_icon('maximize') ?> QR
                                    </button>
                                    <button type="button" class="btn btn-secondary btn-sm"
                                            onclick="stopQuickTunnel(<?= (int)$tunnel['site_id'] ?>, <?= (int)($tunnel['pid'] ?? 0) ?>, this)">
                                        <?= ui_icon('stop') ?> Hentikan
                                    </button>
                                <?php else: ?>
                                    <button type="button" class="btn btn-secondary btn-sm" style="color:var(--danger); border-color: rgba(239,68,68,0.3);"
                                            onclick="unpublishCustomDomain(<?= (int)$tunnel['site_id'] ?>, <?= js_arg($host) ?>, this)" title="Cabut domain dari Cloudflare Zero Trust">
                                        <?= ui_icon('trash') ?> Cabut Domain
                                    </button>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</section>

<!-- Custom domain -->
<div class="modal-backdrop" id="customDomainModal">
    <div class="modal modal-md" role="dialog" aria-modal="true" aria-labelledby="customDomainTitle">
        <div class="modal-header">
            <div>
                <h2 class="modal-title" id="customDomainTitle">Publikasikan ke domain sendiri</h2>
                <p class="modal-subtitle">Rute ingress dan DNS dibuat otomatis lewat Cloudflare API.</p>
            </div>
            <button type="button" class="icon-btn" onclick="closeCustomDomainModal()" aria-label="Tutup"><?= ui_icon('x') ?></button>
        </div>
        <form id="customDomainForm" onsubmit="submitCustomPublish(event)">
            <div class="modal-body">
                <?php if (empty($sites)): ?>
                    <div class="alert alert-warning">
                        <?= ui_icon('alert-triangle') ?>
                        <span>Belum ada website. <a href="/sites">Tambahkan website</a> terlebih dahulu.</span>
                    </div>
                <?php else: ?>
                    <?php if (!$isApiReady): ?>
                        <div class="alert alert-warning mb">
                            <?= ui_icon('alert-triangle') ?>
                            <span>API Cloudflare belum dikonfigurasi. <a href="/settings">Atur token</a> lebih dulu.</span>
                        </div>
                    <?php endif; ?>
                    <div class="field">
                        <label class="label" for="tunnelSiteId">Website</label>
                        <select id="tunnelSiteId" class="select" required>
                            <?php foreach ($sites as $site): ?>
                                <option value="<?= (int)$site['id'] ?>"><?= e($site['domain']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="field">
                        <label class="label" for="tunnelCustomHost">Hostname publik</label>
                        <input type="text" id="tunnelCustomHost" class="input" placeholder="app.domainanda.com" required>
                        <div class="help">Domain harus dikelola di akun Cloudflare yang terhubung.</div>
                    </div>
                <?php endif; ?>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeCustomDomainModal()">Batal</button>
                <button type="submit" class="btn btn-primary" id="customPublishSubmit" <?= empty($sites) ? 'disabled' : '' ?>>Publikasikan</button>
            </div>
        </form>
    </div>
</div>

<!-- Quick tunnel -->
<div class="modal-backdrop" id="quickTunnelModal">
    <div class="modal modal-md" role="dialog" aria-modal="true" aria-labelledby="quickTunnelTitle">
        <div class="modal-header">
            <div>
                <h2 class="modal-title" id="quickTunnelTitle">Jalankan Quick Tunnel</h2>
                <p class="modal-subtitle">URL publik sementara (*.trycloudflare.com), tanpa registrasi domain.</p>
            </div>
            <button type="button" class="icon-btn" onclick="closeQuickTunnelModal()" aria-label="Tutup"><?= ui_icon('x') ?></button>
        </div>
        <form id="quickTunnelForm" onsubmit="submitQuickStart(event)">
            <div class="modal-body">
                <?php if (empty($sites)): ?>
                    <div class="alert alert-warning">
                        <?= ui_icon('alert-triangle') ?>
                        <span>Belum ada website. <a href="/sites">Tambahkan website</a> terlebih dahulu.</span>
                    </div>
                <?php else: ?>
                    <div class="field">
                        <label class="label" for="quickSiteSelect">Website</label>
                        <select id="quickSiteSelect" class="select" required>
                            <?php foreach ($sites as $site): ?>
                                <option value="<?= (int)$site['id'] ?>" data-domain="<?= e($site['domain']) ?>"><?= e($site['domain']) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <div class="help">URL akan berubah setiap kali tunnel dimulai ulang.</div>
                    </div>
                <?php endif; ?>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeQuickTunnelModal()">Batal</button>
                <button type="submit" class="btn btn-primary" id="quickStartSubmit" <?= empty($sites) ? 'disabled' : '' ?>>Jalankan tunnel</button>
            </div>
        </form>
    </div>
</div>

<script>
function openQuickTunnelModal()   { Almusa.openModal('quickTunnelModal'); }
function closeQuickTunnelModal()  { Almusa.closeModal('quickTunnelModal'); }
function openCustomDomainModal()  { Almusa.openModal('customDomainModal'); setTimeout(function () { var i = document.getElementById('tunnelCustomHost'); if (i) i.focus(); }, 50); }
function closeCustomDomainModal() { Almusa.closeModal('customDomainModal'); }

async function submitQuickStart(e) {
    e.preventDefault();
    var sel = document.getElementById('quickSiteSelect');
    if (!sel || !sel.value) { Almusa.toast('Pilih website terlebih dahulu.', 'error'); return; }

    var restore = Almusa.busy(document.getElementById('quickStartSubmit'), 'Memulai…');
    try {
        var data = await Almusa.post('/api/tunnels/quick-start', {
            site_id: sel.value,
            domain: sel.options[sel.selectedIndex].getAttribute('data-domain')
        });
        if (data.success) {
            closeQuickTunnelModal();
            openTaskTerminal();
            Almusa.reload('Tunnel sedang dibuat…', 'info', 4000);
        } else {
            Almusa.toast(data.error || 'Gagal memulai tunnel.', 'error');
            restore();
        }
    } catch (err) {
        Almusa.toast('Terjadi kesalahan koneksi.', 'error');
        restore();
    }
}

async function stopQuickTunnel(siteId, pid, btn) {
    var ok = await Almusa.confirm({
        title: 'Hentikan tunnel',
        message: 'URL publik untuk website ini akan berhenti dapat diakses.',
        confirmText: 'Hentikan',
        danger: true
    });
    if (!ok) return;

    var restore = btn ? Almusa.busy(btn, 'Menghentikan…') : function () {};
    try {
        var data = await Almusa.post('/api/tunnels/quick-stop', { site_id: siteId, pid: pid });
        if (data.success) {
            openTaskTerminal();
            Almusa.reload('Tunnel dihentikan.', 'success', 1500);
        } else {
            Almusa.toast(data.error || 'Gagal menghentikan tunnel.', 'error');
            restore();
        }
    } catch (err) {
        Almusa.toast('Terjadi kesalahan koneksi.', 'error');
        restore();
    }
}

async function submitCustomPublish(e) {
    e.preventDefault();
    var restore = Almusa.busy(document.getElementById('customPublishSubmit'), 'Memublikasikan…');
    try {
        var data = await Almusa.post('/api/tunnels/custom-publish', {
            site_id: document.getElementById('tunnelSiteId').value,
            custom_hostname: document.getElementById('tunnelCustomHost').value.trim()
        });
        if (data.success) {
            closeCustomDomainModal();
            Almusa.reload(data.message || 'Domain berhasil dipublikasikan.', 'success');
        } else {
            Almusa.toast(data.error || 'Gagal mempublikasikan domain.', 'error', 8000);
            restore();
        }
    } catch (err) {
        Almusa.toast('Terjadi kesalahan koneksi.', 'error');
        restore();
    }
}

async function unpublishCustomDomain(siteId, hostname, btn) {
    var ok = await Almusa.confirm({
        title: 'Cabut Publikasi Domain',
        message: 'Domain ' + hostname + ' akan dicabut dari Cloudflare Zero Trust dan DNS CNAME akan dihapus.',
        confirmText: 'Ya, Cabut Domain',
        danger: true
    });
    if (!ok) return;

    var restore = btn ? Almusa.busy(btn, 'Mencabut…') : function () {};
    try {
        var data = await Almusa.post('/api/tunnels/custom-unpublish', { site_id: siteId });
        if (data.success) {
            Almusa.reload(data.message || 'Domain berhasil dicabut.', 'success');
        } else {
            Almusa.toast(data.error || 'Gagal mencabut domain.', 'error');
            restore();
        }
    } catch (err) {
        Almusa.toast('Terjadi kesalahan koneksi.', 'error');
        restore();
    }
}

function showQrCodeModal(url, domain) {
    if (!url) return;
    var title = document.getElementById('qrModalTitle');
    if (title) title.textContent = domain ? ('QR Code: ' + domain) : 'Pindai QR Code';
    var inp = document.getElementById('qrUrlInput');
    if (inp) inp.value = url;
    var btn = document.getElementById('qrOpenBtn');
    if (btn) btn.href = url;
    var img = document.getElementById('qrCodeImg');
    if (img) img.src = 'https://api.qrserver.com/v1/create-qr-code/?size=220x220&margin=1&data=' + encodeURIComponent(url);
    Almusa.openModal('qrCodeModal');
}

function copyQrUrl() {
    var inp = document.getElementById('qrUrlInput');
    if (inp && inp.value) {
        navigator.clipboard.writeText(inp.value).then(function () {
            Almusa.toast('Tautan berhasil disalin ke clipboard!', 'success');
        }).catch(function () {
            inp.select();
            document.execCommand('copy');
            Almusa.toast('Tautan berhasil disalin!', 'success');
        });
    }
}
</script>

<!-- Modal QR Code -->
<div class="modal-backdrop" id="qrCodeModal">
    <div class="modal modal-sm" role="dialog" aria-modal="true" aria-labelledby="qrModalTitle" style="text-align: center;">
        <div class="modal-header">
            <div>
                <h2 class="modal-title" id="qrModalTitle">Pindai QR Code</h2>
                <p class="modal-subtitle">Akses website langsung dari kamera smartphone</p>
            </div>
            <button type="button" class="icon-btn" onclick="Almusa.closeModal('qrCodeModal')" aria-label="Tutup"><?= ui_icon('x') ?></button>
        </div>
        <div class="modal-body" style="display: flex; flex-direction: column; align-items: center; gap: 14px; padding: 20px;">
            <div id="qrCodeContainer" style="background: #fff; padding: 12px; border-radius: var(--radius-md); box-shadow: 0 4px 16px rgba(0,0,0,0.15);">
                <img id="qrCodeImg" src="" alt="QR Code" style="width: 200px; height: 200px; display: block;">
            </div>
            <div style="width: 100%;">
                <input type="text" id="qrUrlInput" class="input mono" readonly style="text-align: center; font-size: 11.5px; width: 100%;" onclick="this.select()">
            </div>
        </div>
        <div class="modal-footer" style="justify-content: center; gap: 10px;">
            <button type="button" class="btn btn-secondary" onclick="copyQrUrl()"><?= ui_icon('copy') ?> Salin Tautan</button>
            <a id="qrOpenBtn" href="#" target="_blank" rel="noopener" class="btn btn-primary"><?= ui_icon('external') ?> Buka di Tab Baru</a>
        </div>
    </div>
</div>
</script>
