<?php
/**
 * Almusa Control Panel - Databases View
 */
?>

<div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 24px;">
    <div>
        <h2 style="font-size: 20px; font-weight: 700; color: #ffffff;">Database MySQL & MariaDB</h2>
        <p style="font-size: 13px; color: var(--text-muted);">Kelola database, user privileges, backup dump berkala, dan enforce UTF8MB4 Unicode</p>
    </div>
    <div style="display: flex; gap: 10px;">
        <button type="button" class="btn btn-primary" onclick="openAddDbModal()">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <ellipse cx="12" cy="5" rx="9" ry="3"></ellipse>
                <path d="M21 12c0 1.66-4 3-9 3s-9-1.34-9-3"></path>
                <path d="M3 5v14c0 1.66 4 3 9 3s9-1.34 9-3V5"></path>
            </svg>
            Tambah Database Baru
        </button>
    </div>
</div>

<?php if (!$isServiceRunning): ?>
    <div class="alert alert-danger" style="margin-bottom: 20px;">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <circle cx="12" cy="12" r="10"></circle>
            <line x1="12" y1="8" x2="12" y2="12"></line>
            <line x1="12" y1="16" x2="12.01" y2="16"></line>
        </svg>
        <span>Layanan MySQL / MariaDB sedang tidak aktif. Buka menu <strong>App Store</strong> untuk menginstal atau menjalankan service.</span>
    </div>
<?php endif; ?>

<div class="table-container">
    <table class="custom-table">
        <thead>
            <tr>
                <th>Nama Database</th>
                <th>Database User</th>
                <th>Password</th>
                <th>Engine</th>
                <th>Port</th>
                <th>Charset</th>
                <th>Host Akses</th>
                <th>Aksi</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($databases)): ?>
                <tr>
                    <td colspan="8" style="text-align: center; padding: 48px; color: var(--text-muted);">
                        <svg width="36" height="36" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" style="margin: 0 auto 12px; display: block; opacity: 0.5;">
                            <ellipse cx="12" cy="5" rx="9" ry="3"></ellipse>
                            <path d="M21 12c0 1.66-4 3-9 3s-9-1.34-9-3"></path>
                            <path d="M3 5v14c0 1.66 4 3 9 3s9-1.34 9-3V5"></path>
                        </svg>
                        Belum ada database yang ditambahkan. Klik <strong>Tambah Database Baru</strong> untuk provisioning.
                    </td>
                </tr>
            <?php else: ?>
                <?php foreach ($databases as $db): ?>
                    <tr>
                        <td>
                            <strong style="color: #ffffff; font-size: 14px;"><?= htmlspecialchars($db['db_name']) ?></strong>
                        </td>
                        <td>
                            <code><?= htmlspecialchars($db['db_user']) ?></code>
                        </td>
                        <td>
                            <span style="font-family: monospace; background: rgba(255,255,255,0.06); padding: 2px 6px; border-radius: 4px; font-size: 12px;">
                                <?= htmlspecialchars(base64_decode($db['db_password_enc'] ?? '')) ?>
                            </span>
                        </td>
                        <td>
                            <span class="badge badge-info"><?= strtoupper(htmlspecialchars($db['db_engine'])) ?></span>
                        </td>
                        <td><?= htmlspecialchars($db['db_port']) ?></td>
                        <td><code><?= htmlspecialchars($db['charset']) ?></code></td>
                        <td><?= htmlspecialchars($db['access_host']) ?></td>
                        <td>
                            <div style="display: flex; gap: 6px;">
                                <button type="button" class="btn btn-secondary btn-sm" onclick="backupDb(<?= $db['id'] ?>, '<?= htmlspecialchars($db['db_name']) ?>')">
                                    Backup
                                </button>
                                <button type="button" class="btn btn-secondary btn-sm" onclick="changeDbPass(<?= $db['id'] ?>, '<?= htmlspecialchars($db['db_user']) ?>')">
                                    Ganti Pass
                                </button>
                                <button type="button" class="btn btn-danger btn-sm" onclick="deleteDb(<?= $db['id'] ?>, '<?= htmlspecialchars($db['db_name']) ?>')">
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

<!-- MODAL ADD DATABASE -->
<div class="modal-backdrop" id="addDbModal">
    <div class="card" style="width: 100%; max-width: 500px; box-shadow: 0 25px 60px rgba(0,0,0,0.8);">
        <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 20px;">
            <h3 style="font-size: 18px; font-weight: 700; color: #ffffff;">Buat Database Baru</h3>
            <button type="button" class="btn btn-secondary btn-sm" onclick="closeAddDbModal()">✕</button>
        </div>

        <form id="addDbForm" onsubmit="submitAddDb(event)">
            <div class="form-group">
                <label class="form-label">Nama Database</label>
                <input type="text" id="dbName" class="form-control" placeholder="app_db" pattern="^[a-zA-Z0-9_]{1,64}$" required>
                <div class="form-help">Hanya huruf, angka, dan underscore (_). Maksimal 64 karakter.</div>
            </div>

            <div class="form-group">
                <label class="form-label">Username Database</label>
                <input type="text" id="dbUser" class="form-control" placeholder="app_user" pattern="^[a-zA-Z0-9_]{1,64}$" required>
            </div>

            <div class="form-group">
                <label class="form-label">Password User</label>
                <input type="password" id="dbPass" class="form-control" placeholder="••••••••••••" required minlength="6">
            </div>

            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px;">
                <div class="form-group">
                    <label class="form-label">Charset</label>
                    <select id="dbCharset" class="form-control">
                        <option value="utf8mb4" selected>utf8mb4 (Rekomendasi)</option>
                        <option value="utf8">utf8</option>
                        <option value="latin1">latin1</option>
                    </select>
                </div>

                <div class="form-group">
                    <label class="form-label">Host Akses</label>
                    <input type="text" id="dbHost" class="form-control" value="127.0.0.1" required>
                </div>
            </div>

            <div style="display: flex; justify-content: flex-end; gap: 10px; margin-top: 24px;">
                <button type="button" class="btn btn-secondary" onclick="closeAddDbModal()">Batal</button>
                <button type="submit" class="btn btn-primary">Buat Database</button>
            </div>
        </form>
    </div>
</div>

<script>
function openAddDbModal() {
    document.getElementById('addDbModal').classList.add('show');
}

function closeAddDbModal() {
    document.getElementById('addDbModal').classList.remove('show');
}

async function submitAddDb(e) {
    e.preventDefault();
    const db_name = document.getElementById('dbName').value.trim();
    const db_user = document.getElementById('dbUser').value.trim();
    const db_password = document.getElementById('dbPass').value;
    const charset = document.getElementById('dbCharset').value;
    const access_host = document.getElementById('dbHost').value.trim();
    const csrf = document.querySelector('meta[name="csrf-token"]').getAttribute('content');

    try {
        const res = await fetch('/api/databases/create', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
                'X-CSRF-TOKEN': csrf
            },
            body: `_csrf_token=${encodeURIComponent(csrf)}&db_name=${encodeURIComponent(db_name)}&db_user=${encodeURIComponent(db_user)}&db_password=${encodeURIComponent(db_password)}&charset=${encodeURIComponent(charset)}&access_host=${encodeURIComponent(access_host)}`
        });

        const data = await res.json();
        if (data.success) {
            alert(data.message);
            closeAddDbModal();
            location.reload();
        } else {
            alert(data.error || 'Gagal membuat database.');
        }
    } catch (err) {
        alert('Terjadi kesalahan jaringan.');
    }
}

async function changeDbPass(id, username) {
    const newPass = prompt(`Masukkan password baru untuk user '${username}':`);
    if (!newPass || newPass.length < 6) {
        if (newPass) alert('Password minimal 6 karakter.');
        return;
    }

    const csrf = document.querySelector('meta[name="csrf-token"]').getAttribute('content');
    try {
        const res = await fetch('/api/databases/change-password', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
                'X-CSRF-TOKEN': csrf
            },
            body: `_csrf_token=${encodeURIComponent(csrf)}&id=${id}&new_password=${encodeURIComponent(newPass)}`
        });
        const data = await res.json();
        if (data.success) {
            alert(data.message);
            location.reload();
        } else {
            alert(data.error || 'Gagal mengubah password.');
        }
    } catch (e) {
        alert('Terjadi kesalahan koneksi.');
    }
}

async function backupDb(id, dbName) {
    if (!confirm(`Buat file backup snapshot untuk database '${dbName}'?`)) return;

    const csrf = document.querySelector('meta[name="csrf-token"]').getAttribute('content');
    try {
        const res = await fetch('/api/databases/backup', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
                'X-CSRF-TOKEN': csrf
            },
            body: `_csrf_token=${encodeURIComponent(csrf)}&id=${id}`
        });
        const data = await res.json();
        if (data.success) {
            openTaskTerminal();
        } else {
            alert(data.error || 'Gagal menjalankan backup.');
        }
    } catch (e) {
        alert('Terjadi kesalahan koneksi.');
    }
}

async function deleteDb(id, dbName) {
    if (!confirm(`PERINGATAN: Apakah Anda yakin ingin menghapus database '${dbName}' beserta seluruh datanya?`)) return;

    const csrf = document.querySelector('meta[name="csrf-token"]').getAttribute('content');
    try {
        const res = await fetch('/api/databases/delete', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
                'X-CSRF-TOKEN': csrf
            },
            body: `_csrf_token=${encodeURIComponent(csrf)}&id=${id}`
        });
        const data = await res.json();
        if (data.success) {
            alert(data.message);
            location.reload();
        } else {
            alert(data.error || 'Gagal menghapus database.');
        }
    } catch (e) {
        alert('Terjadi kesalahan koneksi.');
    }
}
</script>
