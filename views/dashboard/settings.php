<?php
/**
 * Almusa Control Panel - Settings View
 */
$csrf = \App\Core\Auth::token();
?>

<header class="page-header">
    <div>
        <h1 class="page-title">Pengaturan</h1>
        <p class="page-desc">Konfigurasi panel, integrasi Cloudflare, dan keamanan akun administrator.</p>
    </div>
</header>

<?php if (!empty($message)): ?>
    <div class="alert alert-success mb" role="status">
        <?= ui_icon('check-circle') ?>
        <span><?= e($message) ?></span>
    </div>
<?php endif; ?>

<?php if (!empty($error)): ?>
    <div class="alert alert-danger mb" role="alert">
        <?= ui_icon('alert-circle') ?>
        <span><?= e($error) ?></span>
    </div>
<?php endif; ?>

<div class="grid grid-main" style="align-items:start">
    <!-- Pengaturan utama -->
    <form method="POST" action="/settings/save" class="card card-flush" autocomplete="off">
        <input type="hidden" name="_csrf_token" value="<?= e($csrf) ?>">

        <section class="form-section">
            <h2 class="form-section-title">Umum</h2>
            <p class="form-section-desc">Identitas dan alamat akses panel.</p>

            <div class="field">
                <label class="label" for="panel_title">Judul panel</label>
                <input type="text" id="panel_title" name="panel_title" class="input" value="<?= e($settings['panel_title']) ?>" required>
            </div>
            <div class="field-row">
                <div class="field">
                    <label class="label" for="entrance_path">Entrance path</label>
                    <div class="input-group">
                        <span class="affix">/</span>
                        <input type="text" id="entrance_path" name="entrance_path" class="input" value="<?= e($settings['entrance_path']) ?>" required>
                    </div>
                    <div class="help">Jalur rahasia untuk membuka halaman login.</div>
                </div>
                <div class="field">
                    <label class="label" for="panel_port">Port panel</label>
                    <input type="number" id="panel_port" name="panel_port" class="input" value="<?= e($settings['panel_port']) ?>" required>
                    <div class="help">Bawaan: 64448.</div>
                </div>
            </div>
        </section>

        <section class="form-section">
            <h2 class="form-section-title">Cloudflare Zero Trust</h2>
            <p class="form-section-desc">Dipakai untuk mempublikasikan website ke domain sendiri.</p>

            <div class="field">
                <label class="label" for="cf_api_token">API token</label>
                <div class="input-group">
                    <input type="password" id="cf_api_token" name="cf_api_token" class="input has-reveal" value="<?= e($settings['cf_api_token']) ?>" placeholder="Izin: Account · Cloudflare Tunnel · Edit, Zone · DNS · Edit">
                    <button type="button" class="icon-btn reveal" data-toggle-password="cf_api_token" aria-label="Tampilkan token"><?= ui_icon('eye') ?></button>
                </div>
            </div>
            <div class="field-row">
                <div class="field">
                    <label class="label" for="cf_account_id">Account ID</label>
                    <input type="text" id="cf_account_id" name="cf_account_id" class="input mono" value="<?= e($settings['cf_account_id']) ?>" placeholder="32 karakter">
                </div>
                <div class="field">
                    <label class="label" for="cf_tunnel_id">Tunnel ID</label>
                    <input type="text" id="cf_tunnel_id" name="cf_tunnel_id" class="input mono" value="<?= e($settings['cf_tunnel_id']) ?>" placeholder="UUID tunnel">
                </div>
            </div>
            <div class="field">
                <label class="label" for="cf_tunnel_token">Tunnel token <span class="opt">opsional</span></label>
                <div class="input-group">
                    <input type="password" id="cf_tunnel_token" name="cf_tunnel_token" class="input has-reveal" value="<?= e($settings['cf_tunnel_token']) ?>" placeholder="eyJhIjoi…">
                    <button type="button" class="icon-btn reveal" data-toggle-password="cf_tunnel_token" aria-label="Tampilkan token"><?= ui_icon('eye') ?></button>
                </div>
            </div>
        </section>

        <section class="form-section">
            <h2 class="form-section-title">Database</h2>
            <p class="form-section-desc">Kredensial root untuk membuat database dan user secara otomatis.</p>

            <div class="field">
                <label class="label" for="mysql_root_pass">Password root MySQL / MariaDB</label>
                <div class="input-group">
                    <input type="password" id="mysql_root_pass" name="mysql_root_pass" class="input has-reveal" value="<?= e($settings['mysql_root_pass']) ?>" placeholder="Password root">
                    <button type="button" class="icon-btn reveal" data-toggle-password="mysql_root_pass" aria-label="Tampilkan password"><?= ui_icon('eye') ?></button>
                </div>
            </div>
        </section>

        <div class="card-footer">
            <button type="submit" class="btn btn-primary">Simpan pengaturan</button>
        </div>
    </form>

    <!-- Ganti password -->
    <form method="POST" action="/settings/change-password" class="card card-flush" autocomplete="off">
        <input type="hidden" name="_csrf_token" value="<?= e($csrf) ?>">

        <section class="form-section">
            <h2 class="form-section-title">Password administrator</h2>
            <p class="form-section-desc">Gunakan minimal 8 karakter.</p>

            <div class="field">
                <label class="label" for="current_password">Password saat ini</label>
                <input type="password" id="current_password" name="current_password" class="input" autocomplete="current-password" required>
            </div>
            <div class="field">
                <label class="label" for="new_password">Password baru</label>
                <input type="password" id="new_password" name="new_password" class="input" autocomplete="new-password" minlength="8" required>
            </div>
            <div class="field">
                <label class="label" for="confirm_password">Konfirmasi password baru</label>
                <input type="password" id="confirm_password" name="confirm_password" class="input" autocomplete="new-password" minlength="8" required>
            </div>
        </section>

        <div class="card-footer">
            <button type="submit" class="btn btn-secondary">Perbarui password</button>
        </div>
    </form>
</div>
