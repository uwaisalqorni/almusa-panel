<?php
/**
 * Almusa Control Panel - Settings View
 */
?>

<?php if (!empty($message)): ?>
    <div class="alert alert-success">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <polyline points="20 6 9 17 4 12"></polyline>
        </svg>
        <span><?= htmlspecialchars($message) ?></span>
    </div>
<?php endif; ?>

<?php if (!empty($error)): ?>
    <div class="alert alert-danger">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <circle cx="12" cy="12" r="10"></circle>
            <line x1="12" y1="8" x2="12" y2="12"></line>
            <line x1="12" y1="16" x2="12.01" y2="16"></line>
        </svg>
        <span><?= htmlspecialchars($error) ?></span>
    </div>
<?php endif; ?>

<div style="display: grid; grid-template-columns: 2fr 1fr; gap: 24px;">
    <!-- GENERAL SETTINGS -->
    <div class="card">
        <h3 style="font-size: 16px; font-weight: 700; color: #ffffff; margin-bottom: 20px;">Pengaturan Sistem Panel</h3>

        <form method="POST" action="/settings/save">
            <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars(\App\Core\Auth::token()) ?>">

            <div class="form-group">
                <label class="form-label">Nama / Judul Panel</label>
                <input type="text" name="panel_title" class="form-control" value="<?= htmlspecialchars($settings['panel_title']) ?>" required>
            </div>

            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px;">
                <div class="form-group">
                    <label class="form-label">Secret Entrance Path</label>
                    <div style="display: flex; align-items: center; background: var(--bg-input); border: 1px solid var(--border-color); border-radius: var(--radius-md);">
                        <span style="padding: 0 12px; color: var(--text-muted); font-size: 13px;">/</span>
                        <input type="text" name="entrance_path" class="form-control" style="border: none; background: transparent; padding-left: 0;" value="<?= htmlspecialchars($settings['entrance_path']) ?>" required>
                    </div>
                    <div class="form-help">Token rahasia URL panel untuk memblokir bot scanner.</div>
                </div>

                <div class="form-group">
                    <label class="form-label">Port Listen Panel</label>
                    <input type="number" name="panel_port" class="form-control" value="<?= htmlspecialchars($settings['panel_port']) ?>" required>
                    <div class="form-help">Port TCP khusus (default: 64448).</div>
                </div>
            </div>

            <h4 style="font-size: 14px; font-weight: 600; color: #60a5fa; margin: 24px 0 16px;">Integrasi Cloudflare Zero Trust API</h4>

            <div class="form-group">
                <label class="form-label">Cloudflare API Token</label>
                <input type="password" name="cf_api_token" class="form-control" value="<?= htmlspecialchars($settings['cf_api_token']) ?>" placeholder="Bearer Token dengan izin Tunnel:Edit & DNS:Edit">
            </div>

            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px;">
                <div class="form-group">
                    <label class="form-label">Cloudflare Account ID</label>
                    <input type="text" name="cf_account_id" class="form-control" value="<?= htmlspecialchars($settings['cf_account_id']) ?>" placeholder="32-char Account ID">
                </div>

                <div class="form-group">
                    <label class="form-label">Cloudflare Tunnel ID</label>
                    <input type="text" name="cf_tunnel_id" class="form-control" value="<?= htmlspecialchars($settings['cf_tunnel_id']) ?>" placeholder="UUID Tunnel">
                </div>
            </div>

            <div class="form-group">
                <label class="form-label">Tunnel Token (Systemd Service)</label>
                <input type="password" name="cf_tunnel_token" class="form-control" value="<?= htmlspecialchars($settings['cf_tunnel_token']) ?>" placeholder="eyJhIjoi...">
            </div>

            <h4 style="font-size: 14px; font-weight: 600; color: #10b981; margin: 24px 0 16px;">Koneksi Database MySQL Root</h4>

            <div class="form-group">
                <label class="form-label">MySQL / MariaDB Root Password</label>
                <input type="password" name="mysql_root_pass" class="form-control" value="<?= htmlspecialchars($settings['mysql_root_pass']) ?>" placeholder="Password root untuk provisioning database">
                <div class="form-help">Disimpan aman untuk membuat database & user otomatis.</div>
            </div>

            <button type="submit" class="btn btn-primary" style="margin-top: 10px;">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"></path>
                    <polyline points="17 21 17 13 7 13 7 21"></polyline>
                    <polyline points="7 3 7 8 15 8"></polyline>
                </svg>
                Simpan Pengaturan
            </button>
        </form>
    </div>

    <!-- CHANGE PASSWORD -->
    <div class="card">
        <h3 style="font-size: 16px; font-weight: 700; color: #ffffff; margin-bottom: 20px;">Ubah Password Admin</h3>

        <form method="POST" action="/settings/change-password">
            <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars(\App\Core\Auth::token()) ?>">

            <div class="form-group">
                <label class="form-label">Password Saat Ini</label>
                <input type="password" name="current_password" class="form-control" required>
            </div>

            <div class="form-group">
                <label class="form-label">Password Baru</label>
                <input type="password" name="new_password" class="form-control" required minlength="8">
            </div>

            <div class="form-group">
                <label class="form-label">Konfirmasi Password Baru</label>
                <input type="password" name="confirm_password" class="form-control" required minlength="8">
            </div>

            <button type="submit" class="btn btn-secondary" style="width: 100%;">
                Perbarui Password
            </button>
        </form>
    </div>
</div>
