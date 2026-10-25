<?php
/**
 * Almusa Control Panel - Databases View
 */
?>

<header class="page-header">
    <div>
        <h1 class="page-title">Databases</h1>
        <p class="page-desc">Kelola database MySQL / MariaDB, hak akses user, dan backup.</p>
    </div>
    <div class="page-actions">
        <button type="button" class="btn btn-primary" onclick="openAddDbModal()">
            <?= ui_icon('plus') ?> Tambah database
        </button>
    </div>
</header>

<?php if (!$isServiceRunning): ?>
    <div class="alert alert-warning mb" role="alert">
        <?= ui_icon('alert-triangle') ?>
        <span>Layanan MySQL / MariaDB sedang tidak aktif. Buka <a href="/plugins">App Store</a> untuk memasang atau menjalankannya.</span>
    </div>
<?php endif; ?>

<section class="card card-flush">
    <div class="card-body">
        <div class="table-wrap">
            <table class="table">
                <thead>
                    <tr>
                        <th>Database</th>
                        <th>User</th>
                        <th>Password</th>
                        <th>Koneksi</th>
                        <th>Charset</th>
                        <th class="th-right">Aksi</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($databases)): ?>
                    <tr>
                        <td colspan="6">
                            <div class="empty">
                                <?= ui_icon('database', 'icon-xl') ?>
                                <div class="empty-title">Belum ada database</div>
                                <p class="empty-text">Buat database beserta user-nya dalam satu langkah.</p>
                                <button type="button" class="btn btn-primary" onclick="openAddDbModal()"><?= ui_icon('plus') ?> Tambah database</button>
                            </div>
                        </td>
                    </tr>
                <?php else: foreach ($databases as $db):
                    $plain = base64_decode($db['db_password_enc'] ?? '') ?: '';
                ?>
                    <tr>
                        <td>
                            <div class="cell-title"><?= e($db['db_name']) ?></div>
                            <div class="cell-sub"><?= e(strtoupper((string)$db['db_engine'])) ?></div>
                        </td>
                        <td><code><?= e($db['db_user']) ?></code></td>
                        <td>
                            <?php if ($plain !== ''): ?>
                                <span class="secret" data-secret="<?= e($plain) ?>">
                                    <code class="secret-text">••••••••••</code>
                                    <button type="button" class="icon-btn btn-sm" data-reveal title="Tampilkan / sembunyikan" aria-label="Tampilkan password"><?= ui_icon('eye') ?></button>
                                    <button type="button" class="icon-btn btn-sm" data-copy="<?= e($plain) ?>" title="Salin password" aria-label="Salin password"><?= ui_icon('copy') ?></button>
                                </span>
                            <?php else: ?>
                                <span class="text-3">—</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <div class="cell-title num"><?= e($db['access_host']) ?>:<?= e($db['db_port']) ?></div>
                        </td>
                        <td><span class="badge badge-neutral no-dot"><?= e($db['charset']) ?></span></td>
                        <td>
                            <div class="cell-actions">
                                <button type="button" class="btn btn-secondary btn-sm" onclick="backupDb(<?= (int)$db['id'] ?>, <?= js_arg($db['db_name']) ?>)">
                                    <?= ui_icon('archive') ?> Backup
                                </button>
                                <button type="button" class="btn btn-secondary btn-sm" onclick="changeDbPass(<?= (int)$db['id'] ?>, <?= js_arg($db['db_user']) ?>)">
                                    <?= ui_icon('key') ?> Password
                                </button>
                                <button type="button" class="btn btn-ghost btn-icon btn-sm" title="Hapus database" aria-label="Hapus <?= e($db['db_name']) ?>"
                                        onclick="deleteDb(<?= (int)$db['id'] ?>, <?= js_arg($db['db_name']) ?>)">
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

<!-- Tambah database -->
<div class="modal-backdrop" id="addDbModal">
    <div class="modal modal-md" role="dialog" aria-modal="true" aria-labelledby="addDbTitle">
        <div class="modal-header">
            <div>
                <h2 class="modal-title" id="addDbTitle">Tambah database</h2>
                <p class="modal-subtitle">Database baru akan dibuat bersama user dan hak aksesnya.</p>
            </div>
            <button type="button" class="icon-btn" onclick="closeAddDbModal()" aria-label="Tutup"><?= ui_icon('x') ?></button>
        </div>
        <form id="addDbForm" onsubmit="submitAddDb(event)">
            <div class="modal-body">
                <div class="field">
                    <label class="label" for="dbName">Nama database</label>
                    <input type="text" id="dbName" class="input" placeholder="app_db" pattern="^[a-zA-Z0-9_]{1,64}$" required>
                    <div class="help">Huruf, angka, dan underscore. Maksimal 64 karakter.</div>
                </div>
                <div class="field-row">
                    <div class="field">
                        <label class="label" for="dbUser">Username</label>
                        <input type="text" id="dbUser" class="input" placeholder="app_user" pattern="^[a-zA-Z0-9_]{1,64}$" required>
                    </div>
                    <div class="field">
                        <label class="label" for="dbPass">Password</label>
                        <div class="input-group">
                            <input type="password" id="dbPass" class="input has-reveal" minlength="6" required autocomplete="new-password">
                            <button type="button" class="icon-btn reveal" data-toggle-password="dbPass" aria-label="Tampilkan password"><?= ui_icon('eye') ?></button>
                        </div>
                    </div>
                </div>
                <div class="field-row">
                    <div class="field">
                        <label class="label" for="dbCharset">Charset</label>
                        <select id="dbCharset" class="select">
                            <option value="utf8mb4" selected>utf8mb4 (disarankan)</option>
                            <option value="utf8">utf8</option>
                            <option value="latin1">latin1</option>
                        </select>
                    </div>
                    <div class="field">
                        <label class="label" for="dbHost">Host akses</label>
                        <input type="text" id="dbHost" class="input" value="127.0.0.1" required>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeAddDbModal()">Batal</button>
                <button type="submit" class="btn btn-primary" id="addDbSubmit">Buat database</button>
            </div>
        </form>
    </div>
</div>

<script>
function openAddDbModal()  { Almusa.openModal('addDbModal'); setTimeout(function () { document.getElementById('dbName').focus(); }, 50); }
function closeAddDbModal() { Almusa.closeModal('addDbModal'); }

async function submitAddDb(e) {
    e.preventDefault();
    var restore = Almusa.busy(document.getElementById('addDbSubmit'), 'Membuat…');
    try {
        var data = await Almusa.post('/api/databases/create', {
            db_name: document.getElementById('dbName').value.trim(),
            db_user: document.getElementById('dbUser').value.trim(),
            db_password: document.getElementById('dbPass').value,
            charset: document.getElementById('dbCharset').value,
            access_host: document.getElementById('dbHost').value.trim()
        });
        if (data.success) {
            closeAddDbModal();
            Almusa.reload(data.message || 'Database berhasil dibuat.', 'success');
        } else {
            Almusa.toast(data.error || 'Gagal membuat database.', 'error');
            restore();
        }
    } catch (err) {
        Almusa.toast('Terjadi kesalahan jaringan.', 'error');
        restore();
    }
}

async function changeDbPass(id, username) {
    var newPass = await Almusa.prompt({
        title: 'Ganti password',
        message: "Masukkan password baru untuk user '" + username + "'.",
        label: 'Password baru',
        type: 'password',
        minLength: 6
    });
    if (!newPass) return;

    try {
        var data = await Almusa.post('/api/databases/change-password', { id: id, new_password: newPass });
        if (data.success) Almusa.reload(data.message || 'Password diperbarui.', 'success');
        else Almusa.toast(data.error || 'Gagal mengubah password.', 'error');
    } catch (err) {
        Almusa.toast('Terjadi kesalahan koneksi.', 'error');
    }
}

async function backupDb(id, dbName) {
    var ok = await Almusa.confirm({
        title: 'Backup database',
        message: "Buat file backup (dump SQL) untuk database '" + dbName + "'?",
        confirmText: 'Buat backup'
    });
    if (!ok) return;

    try {
        var data = await Almusa.post('/api/databases/backup', { id: id });
        if (data.success) {
            openTaskTerminal();
            Almusa.toast('Backup dijadwalkan.', 'success');
        } else {
            Almusa.toast(data.error || 'Gagal menjalankan backup.', 'error');
        }
    } catch (err) {
        Almusa.toast('Terjadi kesalahan koneksi.', 'error');
    }
}

async function deleteDb(id, dbName) {
    var ok = await Almusa.confirm({
        title: 'Hapus database',
        message: "Database '" + dbName + "' beserta seluruh datanya akan dihapus permanen. Tindakan ini tidak bisa dibatalkan.",
        confirmText: 'Hapus database',
        danger: true
    });
    if (!ok) return;

    try {
        var data = await Almusa.post('/api/databases/delete', { id: id });
        if (data.success) Almusa.reload(data.message || 'Database dihapus.', 'success');
        else Almusa.toast(data.error || 'Gagal menghapus database.', 'error');
    } catch (err) {
        Almusa.toast('Terjadi kesalahan koneksi.', 'error');
    }
}
</script>
