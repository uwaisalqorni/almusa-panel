<?php
/**
 * Almusa Control Panel - Cloudflare Tunnels View
 */
?>

<div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 24px; flex-wrap: wrap; gap: 12px;">
    <div>
        <h2 style="font-size: 20px; font-weight: 700; color: #ffffff;">Cloudflare Tunnel Manager</h2>
        <p style="font-size: 13px; color: var(--text-muted);">Publikasikan website lokal ke internet secara aman tanpa port forwarding</p>
    </div>
    <div style="display: flex; gap: 10px; flex-wrap: wrap;">
        <button type="button" class="btn btn-secondary" onclick="openQuickTunnelModal()">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"></polygon>
            </svg>
            + 1-Click Quick Tunnel
        </button>
        <button type="button" class="btn btn-primary" onclick="openCustomDomainModal()">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <path d="M17.5 19H9a7 7 0 1 1 6.71-9h1.79a4.5 4.5 0 1 1 0 9Z"></path>
            </svg>
            Publikasikan ke Custom Domain (Zero Trust)
        </button>
    </div>
</div>

<!-- TUNNEL STATUS SUMMARY -->
<div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 16px; margin-bottom: 24px;">
    <div class="card" style="padding: 16px 20px; display: flex; align-items: center; justify-content: space-between;">
        <div>
            <div style="font-weight: 600; color: #ffffff;">Daemon cloudflared Binary</div>
            <div style="font-size: 12px; color: var(--text-muted);">/usr/local/bin/cloudflared</div>
        </div>
        <?php if ($isBinaryInstalled): ?>
            <span class="badge badge-success">Terpasang</span>
        <?php else: ?>
            <span class="badge badge-warning">Belum Ada</span>
        <?php endif; ?>
    </div>

    <div class="card" style="padding: 16px 20px; display: flex; align-items: center; justify-content: space-between;">
        <div>
            <div style="font-weight: 600; color: #ffffff;">Cloudflare Zero Trust API</div>
            <div style="font-size: 12px; color: var(--text-muted);">Ingress & DNS Automation</div>
        </div>
        <?php if ($isApiReady): ?>
            <span class="badge badge-success">Terkoneksi</span>
        <?php else: ?>
            <a href="/settings" class="badge badge-neutral" style="text-decoration: underline;">Konfigurasi Token</a>
        <?php endif; ?>
    </div>
</div>

<div class="table-container">
    <div class="table-header">
        <h3 class="table-title">Daftar Tunnel Aktif</h3>
        <button type="button" class="btn btn-secondary btn-sm" onclick="openTaskTerminal()">Buka Terminal</button>
    </div>

    <table class="custom-table">
        <thead>
            <tr>
                <th>Domain Website</th>
                <th>Tipe Tunnel</th>
                <th>Public Hostname / URL</th>
                <th>Layanan Lokal</th>
                <th>PID</th>
                <th>Status</th>
                <th>Aksi</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($tunnels)): ?>
                <tr>
                    <td colspan="7" style="text-align: center; padding: 48px; color: var(--text-muted);">
                        <svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" style="margin: 0 auto 12px; display: block; opacity: 0.5;">
                            <path d="M17.5 19H9a7 7 0 1 1 6.71-9h1.79a4.5 4.5 0 1 1 0 9Z"></path>
                        </svg>
                        <p style="margin-bottom: 16px;">Belum ada Cloudflare Tunnel yang berjalan.</p>
                        <button type="button" class="btn btn-secondary btn-sm" onclick="openQuickTunnelModal()">
                            <polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"></polygon>
                            Mulai 1-Click Quick Tunnel Sekarang
                        </button>
                    </td>
                </tr>
            <?php else: ?>
                <?php foreach ($tunnels as $tunnel): ?>
                    <tr>
                        <td>
                            <strong style="color: #ffffff; font-size: 14px;"><?= htmlspecialchars($tunnel['domain'] ?? 'N/A') ?></strong>
                        </td>
                        <td>
                            <span class="badge <?= ($tunnel['tunnel_type'] === 'quick' ? 'badge-info' : 'badge-success') ?>">
                                <?= strtoupper(htmlspecialchars($tunnel['tunnel_type'])) ?>
                            </span>
                        </td>
                        <td>
                            <a href="<?= htmlspecialchars($tunnel['public_hostname']) ?>" target="_blank" style="color: #38bdf8; font-weight: 500;">
                                <?= htmlspecialchars($tunnel['public_hostname']) ?> ↗
                            </a>
                        </td>
                        <td><code><?= htmlspecialchars($tunnel['local_service']) ?></code></td>
                        <td><?= htmlspecialchars($tunnel['pid'] ?? '-') ?></td>
                        <td>
                            <span class="badge <?= ($tunnel['status'] === 'running' ? 'badge-success' : 'badge-neutral') ?>">
                                <?= htmlspecialchars(ucfirst($tunnel['status'])) ?>
                            </span>
                        </td>
                        <td>
                            <?php if ($tunnel['tunnel_type'] === 'quick'): ?>
                                <button type="button" class="btn btn-danger btn-sm" onclick="stopQuickTunnel(<?= $tunnel['site_id'] ?>, <?= $tunnel['pid'] ?? 0 ?>)">
                                    Stop Tunnel
                                </button>
                            <?php else: ?>
                                <span style="font-size: 12px; color: var(--text-muted);">Managed via API</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<!-- MODAL PUBLISH CUSTOM DOMAIN -->
<div class="modal-backdrop" id="customDomainModal">
    <div class="card" style="width: 100%; max-width: 500px; box-shadow: 0 25px 60px rgba(0,0,0,0.8);">
        <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 20px;">
            <h3 style="font-size: 18px; font-weight: 700; color: #ffffff;">Publikasikan ke Custom Subdomain</h3>
            <button type="button" class="btn btn-secondary btn-sm" onclick="closeCustomDomainModal()">✕</button>
        </div>

        <form id="customDomainForm" onsubmit="submitCustomPublish(event)">
            <div class="form-group">
                <label class="form-label">Pilih Website Lokal</label>
                <select id="tunnelSiteId" class="form-control" required>
                    <?php foreach ($sites as $site): ?>
                        <option value="<?= $site['id'] ?>"><?= htmlspecialchars($site['domain']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-group">
                <label class="form-label">Target Custom Hostname (Cloudflare DNS)</label>
                <input type="text" id="tunnelCustomHost" class="form-control" placeholder="api.proyek-saya.id" required>
                <div class="form-help">Domain harus dikelola di akun Cloudflare yang terhubung.</div>
            </div>

            <div style="display: flex; justify-content: flex-end; gap: 10px; margin-top: 24px;">
                <button type="button" class="btn btn-secondary" onclick="closeCustomDomainModal()">Batal</button>
                <button type="submit" class="btn btn-primary">Publikasikan via API</button>
            </div>
        </form>
    </div>
</div>

<!-- MODAL QUICK TUNNEL -->
<div class="modal-backdrop" id="quickTunnelModal">
    <div class="card" style="width: 100%; max-width: 500px; box-shadow: 0 25px 60px rgba(0,0,0,0.8);">
        <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 20px;">
            <h3 style="font-size: 18px; font-weight: 700; color: #ffffff;">Jalankan 1-Click Quick Tunnel</h3>
            <button type="button" class="btn btn-secondary btn-sm" onclick="closeQuickTunnelModal()">✕</button>
        </div>

        <form id="quickTunnelForm" onsubmit="submitQuickStart(event)">
            <div class="form-group">
                <label class="form-label">Pilih Website</label>
                <select id="quickSiteSelect" class="form-control" required>
                    <?php foreach ($sites as $site): ?>
                        <option value="<?= $site['id'] ?>" data-domain="<?= htmlspecialchars($site['domain']) ?>">
                            <?= htmlspecialchars($site['domain']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <div class="form-help">Cloudflare akan membuatkan URL publik (*.trycloudflare.com) tanpa perlu registrasi domain.</div>
            </div>

            <div style="display: flex; justify-content: flex-end; gap: 10px; margin-top: 24px;">
                <button type="button" class="btn btn-secondary" onclick="closeQuickTunnelModal()">Batal</button>
                <button type="submit" class="btn btn-primary">
                    <polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"></polygon>
                    Jalankan Tunnel
                </button>
            </div>
        </form>
    </div>
</div>

<script>
function openQuickTunnelModal() {
    document.getElementById('quickTunnelModal').classList.add('show');
}

function closeQuickTunnelModal() {
    document.getElementById('quickTunnelModal').classList.remove('show');
}

async function submitQuickStart(e) {
    e.preventDefault();
    const sel = document.getElementById('quickSiteSelect');
    if (!sel || !sel.value) {
        alert('Pilih website terlebih dahulu.');
        return;
    }
    const siteId = sel.value;
    const domain = sel.options[sel.selectedIndex].getAttribute('data-domain');
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
            closeQuickTunnelModal();
            openTaskTerminal();
            setTimeout(() => location.reload(), 4000);
        } else {
            alert(data.error || 'Gagal memulai tunnel.');
        }
    } catch (err) {
        alert('Terjadi kesalahan koneksi.');
    }
}

function openCustomDomainModal() {
    document.getElementById('customDomainModal').classList.add('show');
}

function closeCustomDomainModal() {
    document.getElementById('customDomainModal').classList.remove('show');
}

async function stopQuickTunnel(siteId, pid) {
    if (!confirm('Hentikan Quick Tunnel untuk website ini?')) return;

    const csrf = document.querySelector('meta[name="csrf-token"]').getAttribute('content');
    try {
        const res = await fetch('/api/tunnels/quick-stop', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
                'X-CSRF-TOKEN': csrf
            },
            body: `_csrf_token=${encodeURIComponent(csrf)}&site_id=${siteId}&pid=${pid}`
        });
        const data = await res.json();
        if (data.success) {
            openTaskTerminal();
            setTimeout(() => location.reload(), 1500);
        } else {
            alert(data.error || 'Gagal menghentikan tunnel.');
        }
    } catch (e) {
        alert('Terjadi kesalahan koneksi.');
    }
}

async function submitCustomPublish(e) {
    e.preventDefault();
    const site_id = document.getElementById('tunnelSiteId').value;
    const custom_hostname = document.getElementById('tunnelCustomHost').value.trim();
    const csrf = document.querySelector('meta[name="csrf-token"]').getAttribute('content');

    try {
        const res = await fetch('/api/tunnels/custom-publish', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
                'X-CSRF-TOKEN': csrf
            },
            body: `_csrf_token=${encodeURIComponent(csrf)}&site_id=${site_id}&custom_hostname=${encodeURIComponent(custom_hostname)}`
        });

        const data = await res.json();
        if (data.success) {
            alert(data.message);
            closeCustomDomainModal();
            location.reload();
        } else {
            alert(data.error || 'Gagal mempublikasikan domain.');
        }
    } catch (err) {
        alert('Terjadi kesalahan koneksi.');
    }
}
</script>
