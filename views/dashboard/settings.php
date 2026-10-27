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

    <div style="display: flex; flex-direction: column; gap: var(--space-lg, 24px);">
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

        <!-- Notifikasi Darurat (Telegram & Discord) -->
        <form id="formNotifications" onsubmit="saveNotifications(event)" class="card card-flush" autocomplete="off">
            <input type="hidden" name="_csrf_token" value="<?= e($csrf) ?>">

            <section class="form-section">
                <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 6px;">
                    <h2 class="form-section-title" style="margin-bottom:0; display:flex; align-items:center; gap:8px;">
                        <?= ui_icon('bell') ?> Notifikasi Darurat
                    </h2>
                    <span class="badge <?= ($settings['notif_telegram_enabled'] === '1' || $settings['notif_discord_enabled'] === '1') ? 'badge-success' : 'badge-neutral' ?>">
                        <?= ($settings['notif_telegram_enabled'] === '1' || $settings['notif_discord_enabled'] === '1') ? 'Aktif' : 'Nonaktif' ?>
                    </span>
                </div>
                <p class="form-section-desc">Peringatan otomatis saat terjadi insiden server, self-healing watchdog, disk penuh, atau serangan brute-force.</p>

                <!-- 1. Telegram Bot Integration -->
                <div class="card card-sub" style="border: 1px solid var(--border); border-radius: var(--radius-md); padding: 14px; margin-bottom: 16px; background: var(--surface);">
                    <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 12px;">
                        <div style="display: flex; align-items: center; gap: 8px;">
                            <span style="display:inline-flex; align-items:center; justify-content:center; width:28px; height:28px; border-radius:50%; background:rgba(0,136,204,0.15); color:#0088cc;">
                                <?= ui_icon('send') ?>
                            </span>
                            <div>
                                <div style="font-weight: 600; font-size: 13.5px; color: var(--text);">Telegram Bot</div>
                                <div class="help" style="margin-top: 1px; font-size: 11.5px;">Kirim alert instan langsung ke akun atau grup Telegram Anda.</div>
                            </div>
                        </div>
                        <label class="check" style="margin: 0; cursor: pointer;">
                            <input type="checkbox" id="notif_telegram_enabled" name="notif_telegram_enabled" value="1" <?= $settings['notif_telegram_enabled'] === '1' ? 'checked' : '' ?> style="width: 18px; height: 18px;">
                        </label>
                    </div>

                    <div class="field" style="margin-bottom: 12px;">
                        <label class="label" for="notif_telegram_bot_token" style="font-size: 12px;">Bot Token</label>
                        <div class="input-group">
                            <input type="password" id="notif_telegram_bot_token" name="notif_telegram_bot_token" class="input has-reveal mono" value="<?= e($settings['notif_telegram_bot_token']) ?>" placeholder="Contoh: 123456789:ABCdefGhIJKlmNoPQRsTUVwxyZ">
                            <button type="button" class="icon-btn reveal" data-toggle-password="notif_telegram_bot_token" aria-label="Tampilkan token"><?= ui_icon('eye') ?></button>
                        </div>
                        <div class="help" style="font-size: 11px;">Dapatkan token bot dengan membuat bot baru di <b>@BotFather</b> pada Telegram.</div>
                    </div>

                    <div class="field" style="margin-bottom: 14px;">
                        <label class="label" for="notif_telegram_chat_id" style="font-size: 12px;">Chat ID Target</label>
                        <input type="text" id="notif_telegram_chat_id" name="notif_telegram_chat_id" class="input mono" value="<?= e($settings['notif_telegram_chat_id']) ?>" placeholder="Contoh: 123456789 atau -1001234567890 (Grup)">
                        <div class="help" style="font-size: 11px;">Kirim pesan ke <b>@userinfobot</b> untuk melihat Chat ID Anda, atau tambahkan bot ke grup dan masukkan Group ID.</div>
                    </div>

                    <div style="display:flex; justify-content: flex-end;">
                        <button type="button" class="btn btn-secondary btn-sm" id="btnTestTelegram" onclick="testNotificationChannel('telegram')">
                            <?= ui_icon('send') ?> Kirim Uji Coba Telegram
                        </button>
                    </div>
                </div>

                <!-- 2. Discord Webhook Integration -->
                <div class="card card-sub" style="border: 1px solid var(--border); border-radius: var(--radius-md); padding: 14px; margin-bottom: 16px; background: var(--surface);">
                    <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 12px;">
                        <div style="display: flex; align-items: center; gap: 8px;">
                            <span style="display:inline-flex; align-items:center; justify-content:center; width:28px; height:28px; border-radius:50%; background:rgba(88,101,242,0.15); color:#5865F2;">
                                <?= ui_icon('shield') ?>
                            </span>
                            <div>
                                <div style="font-weight: 600; font-size: 13.5px; color: var(--text);">Discord Webhook <span class="opt">opsional</span></div>
                                <div class="help" style="margin-top: 1px; font-size: 11.5px;">Kirim notifikasi kaya (embed) ke channel server Discord.</div>
                            </div>
                        </div>
                        <label class="check" style="margin: 0; cursor: pointer;">
                            <input type="checkbox" id="notif_discord_enabled" name="notif_discord_enabled" value="1" <?= $settings['notif_discord_enabled'] === '1' ? 'checked' : '' ?> style="width: 18px; height: 18px;">
                        </label>
                    </div>

                    <div class="field" style="margin-bottom: 14px;">
                        <label class="label" for="notif_discord_webhook_url" style="font-size: 12px;">Webhook URL</label>
                        <input type="url" id="notif_discord_webhook_url" name="notif_discord_webhook_url" class="input mono" value="<?= e($settings['notif_discord_webhook_url']) ?>" placeholder="https://discord.com/api/webhooks/...">
                    </div>

                    <div style="display:flex; justify-content: flex-end;">
                        <button type="button" class="btn btn-secondary btn-sm" id="btnTestDiscord" onclick="testNotificationChannel('discord')">
                            <?= ui_icon('send') ?> Kirim Uji Coba Discord
                        </button>
                    </div>
                </div>

                <!-- 3. Event Triggers -->
                <div style="margin-top: 6px;">
                    <label class="label" style="font-weight: 600; margin-bottom: 8px;">Pemicu Notifikasi Darurat</label>
                    
                    <div style="display:flex; flex-direction:column; gap:8px;">
                        <label class="check" style="display:flex; align-items:flex-start; gap:10px; cursor:pointer;">
                            <input type="checkbox" id="notif_trigger_service_restart" name="notif_trigger_service_restart" value="1" <?= $settings['notif_trigger_service_restart'] === '1' ? 'checked' : '' ?> style="margin-top:3px; width:16px; height:16px;">
                            <div>
                                <div style="font-size:13px; font-weight:500;">Restart Layanan oleh Watchdog (Auto-Healing)</div>
                                <div class="help" style="font-size:11px;">Notifikasi saat Nginx, MariaDB, PHP-FPM, atau daemon worker mati dan dipulihkan.</div>
                            </div>
                        </label>

                        <label class="check" style="display:flex; align-items:flex-start; gap:10px; cursor:pointer;">
                            <input type="checkbox" id="notif_trigger_disk_usage" name="notif_trigger_disk_usage" value="1" <?= $settings['notif_trigger_disk_usage'] === '1' ? 'checked' : '' ?> style="margin-top:3px; width:16px; height:16px;">
                            <div>
                                <div style="font-size:13px; font-weight:500;">Penggunaan Disk Storage Melebihi 85%</div>
                                <div class="help" style="font-size:11px;">Peringatan dini sebelum ruang harddisk root server penuh.</div>
                            </div>
                        </label>

                        <label class="check" style="display:flex; align-items:flex-start; gap:10px; cursor:pointer;">
                            <input type="checkbox" id="notif_trigger_brute_force" name="notif_trigger_brute_force" value="1" <?= $settings['notif_trigger_brute_force'] === '1' ? 'checked' : '' ?> style="margin-top:3px; width:16px; height:16px;">
                            <div>
                                <div style="font-size:13px; font-weight:500;">Indikasi Brute-Force (5x Gagal Login)</div>
                                <div class="help" style="font-size:11px;">Alert keamanan saat alamat IP asing terkunci karena gagal login beruntun.</div>
                            </div>
                        </label>
                    </div>
                </div>
            </section>

            <div class="card-footer" style="display:flex; justify-content:space-between; align-items:center;">
                <span class="help" style="font-size:11.5px;">Perubahan langsung aktif seketika.</span>
                <button type="submit" class="btn btn-primary" id="btnSaveNotif">
                    <?= ui_icon('check-circle') ?> Simpan Notifikasi
                </button>
            </div>
        </form>
    </div>
</div>

<script>
// Save Notification Settings via AJAX
async function saveNotifications(e) {
    e.preventDefault();
    const btn = document.getElementById('btnSaveNotif');
    const restoreBtn = Almusa.busy(btn, 'Menyimpan...');

    const payload = {
        notif_telegram_enabled: document.getElementById('notif_telegram_enabled').checked ? '1' : '0',
        notif_telegram_bot_token: document.getElementById('notif_telegram_bot_token').value.trim(),
        notif_telegram_chat_id: document.getElementById('notif_telegram_chat_id').value.trim(),
        notif_discord_enabled: document.getElementById('notif_discord_enabled').checked ? '1' : '0',
        notif_discord_webhook_url: document.getElementById('notif_discord_webhook_url').value.trim(),
        notif_trigger_service_restart: document.getElementById('notif_trigger_service_restart').checked ? '1' : '0',
        notif_trigger_disk_usage: document.getElementById('notif_trigger_disk_usage').checked ? '1' : '0',
        notif_trigger_brute_force: document.getElementById('notif_trigger_brute_force').checked ? '1' : '0',
    };

    try {
        const res = await Almusa.post('/api/settings/notifications/save', payload);
        if (typeof restoreBtn === 'function') restoreBtn();
        else if (Almusa.unbusy) Almusa.unbusy(btn);

        if (res.success) {
            Almusa.toast(res.message || 'Pengaturan notifikasi berhasil disimpan!', 'success');
        } else {
            Almusa.toast(res.error || res.message || 'Gagal menyimpan pengaturan notifikasi.', 'error');
        }
    } catch (err) {
        if (typeof restoreBtn === 'function') restoreBtn();
        else if (Almusa.unbusy) Almusa.unbusy(btn);
        Almusa.toast('Terjadi kesalahan jaringan.', 'error');
    }
}

// Test Notification Channel (Telegram or Discord)
async function testNotificationChannel(channel) {
    let btn, payload = { channel: channel };

    if (channel === 'telegram') {
        btn = document.getElementById('btnTestTelegram');
        const token = document.getElementById('notif_telegram_bot_token').value.trim();
        const chatId = document.getElementById('notif_telegram_chat_id').value.trim();
        if (!token || !chatId) {
            Almusa.toast('Harap masukkan Bot Token dan Chat ID Telegram terlebih dahulu.', 'error');
            return;
        }
        payload.token = token;
        payload.chat_id = chatId;
    } else {
        btn = document.getElementById('btnTestDiscord');
        const webhook = document.getElementById('notif_discord_webhook_url').value.trim();
        if (!webhook) {
            Almusa.toast('Harap masukkan Discord Webhook URL terlebih dahulu.', 'error');
            return;
        }
        payload.webhook = webhook;
    }

    const restoreBtn = Almusa.busy(btn, 'Mengirim...');

    try {
        const res = await Almusa.post('/api/settings/notifications/test', payload);
        if (typeof restoreBtn === 'function') restoreBtn();
        else if (Almusa.unbusy) Almusa.unbusy(btn);

        if (res.success) {
            Almusa.toast(res.message || 'Pesan uji coba berhasil terkirim!', 'success');
        } else {
            Almusa.toast(res.error || 'Gagal mengirim pesan uji coba.', 'error');
        }
    } catch (err) {
        if (typeof restoreBtn === 'function') restoreBtn();
        else if (Almusa.unbusy) Almusa.unbusy(btn);
        Almusa.toast('Terjadi kesalahan koneksi.', 'error');
    }
}
</script>
