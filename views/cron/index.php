<?php
/**
 * Almusa Server Control Panel - Cron Jobs Manager View
 */
?>

<header class="page-header">
    <div>
        <h1 class="page-title">Cron Jobs</h1>
        <p class="page-desc">Kelola penjadwalan tugas otomatis sistem, scheduler framework (Laravel, WordPress), dan background script secara terpusat.</p>
    </div>
    <div class="page-actions">
        <button type="button" class="btn btn-primary" onclick="openCreateCronModal()">
            <?= ui_icon('plus') ?> Tambah Tugas Cron
        </button>
    </div>
</header>

<!-- System Status Cards -->
<section class="grid-stats" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 16px; margin-bottom: 24px;">
    <div class="card stat" style="padding: 16px 20px;">
        <div class="stat-head" style="display: flex; justify-content: space-between; align-items: center;">
            <span class="stat-label" style="font-size: 13px; color: var(--text-3);">Total Tugas Cron</span>
            <div class="icon-tile" style="width: 34px; height: 34px; border-radius: 8px; background: rgba(56, 189, 248, 0.12); color: #38bdf8; display: flex; align-items: center; justify-content: center;">
                <?= ui_icon('clock') ?>
            </div>
        </div>
        <div class="stat-value" style="font-size: 26px; font-weight: 700; margin-top: 8px;"><?= count($cronJobs) ?></div>
        <div class="stat-sub" style="font-size: 12px; color: var(--text-3); margin-top: 4px;">
            <?= count(array_filter($cronJobs, fn($j) => (int)$j['is_enabled'] === 1)) ?> aktif · <?= count(array_filter($cronJobs, fn($j) => (int)$j['is_enabled'] === 0)) ?> dijeda
        </div>
    </div>

    <div class="card stat" style="padding: 16px 20px;">
        <div class="stat-head" style="display: flex; justify-content: space-between; align-items: center;">
            <span class="stat-label" style="font-size: 13px; color: var(--text-3);">Daemon Sistem (cron)</span>
            <div class="icon-tile" style="width: 34px; height: 34px; border-radius: 8px; background: rgba(76, 195, 138, 0.12); color: #4cc38a; display: flex; align-items: center; justify-content: center;">
                <?= ui_icon('check-circle') ?>
            </div>
        </div>
        <div class="stat-value" style="font-size: 18px; font-weight: 700; margin-top: 14px; display: flex; align-items: center; gap: 8px;">
            <span class="badge badge-success">Active (Running)</span>
        </div>
        <div class="stat-sub" style="font-size: 12px; color: var(--text-3); margin-top: 4px;">
            Vixie-Cron service sistem Linux
        </div>
    </div>

    <div class="card stat" style="padding: 16px 20px;">
        <div class="stat-head" style="display: flex; justify-content: space-between; align-items: center;">
            <span class="stat-label" style="font-size: 13px; color: var(--text-3);">File Target Sistem</span>
            <div class="icon-tile" style="width: 34px; height: 34px; border-radius: 8px; background: rgba(148, 163, 184, 0.12); color: var(--text-2); display: flex; align-items: center; justify-content: center;">
                <?= ui_icon('file-text') ?>
            </div>
        </div>
        <div class="stat-value" style="font-size: 13px; font-weight: 600; font-family: var(--mono); margin-top: 12px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">
            <?= e($cronSystemStatus['file']) ?>
        </div>
        <div class="stat-sub" style="font-size: 12px; color: var(--text-3); margin-top: 6px;">
            <?= $cronSystemStatus['exists'] ? 'Disinkronkan ' . ($cronSystemStatus['last_modified'] ?? 'Baru saja') : 'Menunggu sinkronisasi' ?>
        </div>
    </div>
</section>

<!-- Cron Jobs Table -->
<section class="card card-flush">
    <div class="card-body">
        <div class="table-wrap">
            <table class="table">
                <thead>
                    <tr>
                        <th style="width: 80px;">Status</th>
                        <th>Nama Tugas</th>
                        <th>Jadwal / Interval</th>
                        <th>Perintah Shell</th>
                        <th>Eksekusi Terakhir</th>
                        <th class="th-right" style="width: 170px;">Aksi</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($cronJobs)): ?>
                    <tr>
                        <td colspan="6">
                            <div class="empty" style="padding: 48px 20px;">
                                <?= ui_icon('clock', 'icon-xl') ?>
                                <div class="empty-title">Belum ada tugas cron yang dibuat</div>
                                <p class="empty-text">Jadwalkan script PHP, backup harian, atau Laravel artisan schedule dengan mudah tanpa SSH.</p>
                                <button type="button" class="btn btn-primary" onclick="openCreateCronModal()">
                                    <?= ui_icon('plus') ?> Buat Tugas Cron Sekarang
                                </button>
                            </div>
                        </td>
                    </tr>
                <?php else: foreach ($cronJobs as $job): 
                    $isEnabled = (int)$job['is_enabled'] === 1;
                    $exitCode = $job['last_exit_code'];
                ?>
                    <tr id="cron-row-<?= (int)$job['id'] ?>">
                        <td>
                            <button type="button" 
                                    class="badge <?= $isEnabled ? 'badge-success' : 'badge-neutral' ?>"
                                    style="cursor: pointer; border: none; font-size: 11px; padding: 4px 8px;"
                                    title="Klik untuk mengubah status"
                                    onclick="toggleCronJob(<?= (int)$job['id'] ?>, this)">
                                <?= $isEnabled ? '● Aktif' : '○ Dijeda' ?>
                            </button>
                        </td>
                        <td>
                            <div class="cell-title" style="font-weight: 600;"><?= e($job['name']) ?></div>
                            <div class="cell-sub" style="display: flex; align-items: center; gap: 6px; margin-top: 2px;">
                                <span class="badge badge-neutral" style="font-size: 11px; padding: 1px 6px;">user: <?= e($job['run_as_user']) ?></span>
                                <?php if ($job['log_mode'] === 'file'): ?>
                                    <span class="badge badge-neutral" style="font-size: 11px; padding: 1px 6px;">log aktif</span>
                                <?php endif; ?>
                            </div>
                        </td>
                        <td>
                            <div style="font-weight: 600; font-size: 13px; color: #38bdf8;"><?= e($job['human_expr']) ?></div>
                            <code style="font-size: 11.5px; color: var(--text-3); font-family: var(--mono); background: var(--surface-2); padding: 1px 6px; border-radius: 4px; display: inline-block; margin-top: 3px;">
                                <?= e($job['expression']) ?>
                            </code>
                        </td>
                        <td>
                            <div style="display: flex; align-items: center; gap: 6px; max-width: 420px;">
                                <code style="font-family: var(--mono); font-size: 11.5px; background: #0b0f19; color: #d6dbe3; padding: 5px 8px; border-radius: 6px; border: 1px solid #232832; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; flex: 1;" title="<?= e($job['command']) ?>">
                                    <?= e($job['command']) ?>
                                </code>
                                <button type="button" class="btn btn-ghost btn-icon btn-sm" title="Salin Perintah" onclick="Almusa.copy(<?= js_arg($job['command']) ?>)">
                                    <?= ui_icon('copy') ?>
                                </button>
                            </div>
                        </td>
                        <td>
                            <?php if ($job['last_run_at']): ?>
                                <div style="font-size: 12.5px; color: var(--text);"><?= e($job['last_run_at']) ?></div>
                                <div style="margin-top: 3px;">
                                    <?php if ($exitCode === 0): ?>
                                        <span class="badge badge-success" style="font-size: 11px;">Sukses (Exit 0)</span>
                                    <?php elseif ($exitCode !== null): ?>
                                        <span class="badge badge-danger" style="font-size: 11px;">Gagal (Exit <?= (int)$exitCode ?>)</span>
                                    <?php endif; ?>
                                </div>
                            <?php else: ?>
                                <span class="text-muted" style="font-size: 12px; color: var(--text-3);">Belum pernah dijalankan</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <div class="cell-actions" style="justify-content: flex-end;">
                                <button type="button" class="btn btn-secondary btn-icon btn-sm" title="Jalankan Sekarang (Uji Manual)" aria-label="Jalankan Sekarang"
                                        onclick="runCronNow(<?= (int)$job['id'] ?>, <?= js_arg($job['name']) ?>, this)">
                                    <?= ui_icon('zap') ?>
                                </button>
                                <button type="button" class="btn btn-ghost btn-icon btn-sm" title="Lihat Log Output" aria-label="Log"
                                        onclick="openCronLogModal(<?= (int)$job['id'] ?>, <?= js_arg($job['name']) ?>)">
                                    <?= ui_icon('file-text') ?>
                                </button>
                                <button type="button" class="btn btn-ghost btn-icon btn-sm" title="Edit Tugas Cron" aria-label="Edit"
                                        onclick='openEditCronModal(<?= json_encode($job) ?>)'>
                                    <?= ui_icon('sliders') ?>
                                </button>
                                <button type="button" class="btn btn-ghost btn-icon btn-sm" title="Hapus Tugas Cron" aria-label="Hapus"
                                        onclick="deleteCronJob(<?= (int)$job['id'] ?>, <?= js_arg($job['name']) ?>)">
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

<!-- Modal: Tambah / Edit Tugas Cron -->
<div class="modal-backdrop" id="cronModal">
    <div class="modal modal-md" role="dialog" aria-modal="true" aria-labelledby="cronModalTitle">
        <div class="modal-header">
            <div>
                <h2 class="modal-title" id="cronModalTitle">Tambah Tugas Cron</h2>
                <p class="modal-subtitle">Jadwalkan otomatis eksekusi script shell di sistem server.</p>
            </div>
            <button type="button" class="icon-btn" onclick="closeCronModal()" aria-label="Tutup"><?= ui_icon('x') ?></button>
        </div>

        <form id="cronForm" onsubmit="submitCronForm(event)">
            <input type="hidden" id="cronId" value="">

            <div class="modal-body">
                <!-- Template Preset Cepat -->
                <div class="field" id="templatePresetBox">
                    <label class="label" for="cronPresetSelect">Preset Cepat Framework <span class="opt">opsional</span></label>
                    <select id="cronPresetSelect" class="select" onchange="applyPresetTemplate(this.value)">
                        <option value="">-- Pilih Template Cepat --</option>
                        <option value="laravel">Laravel Scheduler (`php artisan schedule:run` tiap menit)</option>
                        <option value="wordpress">WordPress Cron (`wp-cron.php` tiap jam)</option>
                        <option value="clear_cache">Bersihkan Cache /tmp Harian (00:00)</option>
                    </select>
                </div>

                <div class="field">
                    <label class="label" for="cronName">Nama Tugas</label>
                    <input type="text" id="cronName" class="input" placeholder="contoh: Laravel Artisan Scheduler" required>
                </div>

                <!-- Interval Selection -->
                <div class="field-row">
                    <div class="field">
                        <label class="label" for="cronScheduleType">Interval Visual</label>
                        <select id="cronScheduleType" class="select" onchange="onScheduleTypeChange(this.value)">
                            <option value="every_minute">Setiap menit (* * * * *)</option>
                            <option value="every_5_minutes">Setiap 5 menit (*/5 * * * *)</option>
                            <option value="every_15_minutes">Setiap 15 menit (*/15 * * * *)</option>
                            <option value="every_30_minutes">Setiap 30 menit (*/30 * * * *)</option>
                            <option value="hourly">Setiap jam (0 * * * *)</option>
                            <option value="daily" selected>Setiap hari jam 00:00 (0 0 * * *)</option>
                            <option value="weekly">Setiap minggu - Minggu 00:00 (0 0 * * 0)</option>
                            <option value="monthly">Setiap bulan - Tgl 1 00:00 (0 0 1 * *)</option>
                            <option value="custom">Kustom (Custom Cron Expression)</option>
                        </select>
                    </div>

                    <div class="field">
                        <label class="label" for="cronExpression">Ekspresi Cron (5 bagian)</label>
                        <input type="text" id="cronExpression" class="input" value="0 0 * * *" style="font-family: var(--mono);" oninput="onCustomExpressionInput(this.value)" required>
                    </div>
                </div>

                <div style="font-size: 12px; color: #38bdf8; background: rgba(56, 189, 248, 0.08); padding: 6px 12px; border-radius: 6px; border: 1px solid rgba(56, 189, 248, 0.2); margin-bottom: 16px;">
                    Jadwal aktif: <strong id="cronExpressionDesc">Setiap hari (pukul 00:00)</strong>
                </div>

                <!-- Shell Command -->
                <div class="field">
                    <label class="label" for="cronCommand">Perintah Shell yang Dijalankan</label>
                    <textarea id="cronCommand" class="input" rows="3" style="font-family: var(--mono); font-size: 12.5px; line-height: 1.5; resize: vertical;" placeholder="/usr/bin/php8.3 /www/wwwroot/domain.com/artisan schedule:run" required></textarea>
                    <div class="help">Gunakan path lengkap ke binary (misal: <code>/usr/bin/php8.3</code>, <code>/usr/bin/curl</code>, <code>/bin/bash</code>).</div>
                </div>

                <div class="field-row">
                    <div class="field">
                        <label class="label" for="cronUser">Eksekusi Sebagai User</label>
                        <select id="cronUser" class="select">
                            <option value="afandi" selected>afandi (Standar Aplikasi Web)</option>
                            <option value="www-data">www-data (Web Server)</option>
                            <option value="root">root (Hak Akses Penuh Sistem)</option>
                        </select>
                    </div>

                    <div class="field">
                        <label class="label" for="cronLogMode">Penyimpanan Log</label>
                        <select id="cronLogMode" class="select">
                            <option value="file" selected>Simpan riwayat output ke file log</option>
                            <option value="null">Abaikan output (&gt; /dev/null)</option>
                        </select>
                    </div>
                </div>
            </div>

            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeCronModal()">Batal</button>
                <button type="submit" class="btn btn-primary" id="cronSubmitBtn">Simpan Tugas</button>
            </div>
        </form>
    </div>
</div>

<!-- Modal: Hasil Eksekusi Manual -->
<div class="modal-backdrop" id="cronRunModal">
    <div class="modal modal-md" style="max-width: 680px;" role="dialog" aria-modal="true" aria-labelledby="cronRunTitle">
        <div class="modal-header">
            <div>
                <h2 class="modal-title" id="cronRunTitle">Eksekusi Manual</h2>
                <p class="modal-subtitle">Hasil output perintah shell yang dijalankan saat ini.</p>
            </div>
            <button type="button" class="icon-btn" onclick="Almusa.closeModal('cronRunModal')" aria-label="Tutup"><?= ui_icon('x') ?></button>
        </div>
        <div class="modal-body" style="padding-top: 10px;">
            <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 12px;">
                <div style="display: flex; align-items: center; gap: 8px;">
                    <span id="cronRunBadge" class="badge badge-success">Exit Code: 0</span>
                    <span id="cronRunDuration" style="font-size: 12px; color: var(--text-3);">0.15s</span>
                </div>
                <button type="button" class="btn btn-ghost btn-sm" onclick="Almusa.copy(document.getElementById('cronRunOutput').textContent)">
                    <?= ui_icon('copy') ?> Salin Output
                </button>
            </div>

            <pre id="cronRunOutput" style="background: #080b11; color: #d6dbe3; border: 1px solid #232832; border-radius: 8px; padding: 14px; font-family: var(--mono); font-size: 12px; line-height: 1.6; max-height: 380px; overflow-y: auto; white-space: pre-wrap; word-break: break-all; margin: 0;"></pre>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-secondary" onclick="Almusa.closeModal('cronRunModal')">Tutup</button>
        </div>
    </div>
</div>

<!-- Modal: Log Viewer Cron -->
<div class="modal-backdrop" id="cronLogModal">
    <div class="modal modal-lg" style="max-width: 860px;" role="dialog" aria-modal="true" aria-labelledby="cronLogTitle">
        <div class="modal-header">
            <div>
                <h2 class="modal-title" id="cronLogTitle">Log Riwayat Cron</h2>
                <p class="modal-subtitle" id="cronLogSubtitle">Catatan eksekusi tugas.</p>
            </div>
            <button type="button" class="icon-btn" onclick="Almusa.closeModal('cronLogModal')" aria-label="Tutup"><?= ui_icon('x') ?></button>
        </div>
        <div class="modal-body" style="padding-top: 10px;">
            <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 12px; font-size: 12px; color: var(--text-3);">
                <div>Ukuran: <strong id="cronLogSize" style="color: var(--text);">-</strong> · Pembaruan: <span id="cronLogUpdated">-</span></div>
                <div style="display: flex; gap: 8px;">
                    <button type="button" class="btn btn-secondary btn-sm" onclick="reloadCurrentCronLog()">
                        <?= ui_icon('refresh') ?> Segarkan
                    </button>
                    <button type="button" class="btn btn-ghost btn-sm" style="color: var(--err);" onclick="clearCurrentCronLog()">
                        <?= ui_icon('trash') ?> Kosongkan
                    </button>
                </div>
            </div>

            <pre id="cronLogBody" style="background: #080b11; color: #d6dbe3; border: 1px solid #232832; border-radius: 8px; padding: 14px; font-family: var(--mono); font-size: 12px; line-height: 1.6; max-height: 440px; overflow-y: auto; white-space: pre-wrap; word-break: break-all; margin: 0;"></pre>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-secondary" onclick="Almusa.closeModal('cronLogModal')">Tutup</button>
        </div>
    </div>
</div>

<script>
var sitesList = <?= json_encode($sites) ?>;
var currentViewingCronId = null;

var scheduleExpressions = {
    'every_minute': '* * * * *',
    'every_5_minutes': '*/5 * * * *',
    'every_15_minutes': '*/15 * * * *',
    'every_30_minutes': '*/30 * * * *',
    'hourly': '0 * * * *',
    'daily': '0 0 * * *',
    'weekly': '0 0 * * 0',
    'monthly': '0 0 1 * *'
};

var scheduleDescriptions = {
    '* * * * *': 'Setiap menit',
    '*/5 * * * *': 'Setiap 5 menit',
    '*/15 * * * *': 'Setiap 15 menit',
    '*/30 * * * *': 'Setiap 30 menit',
    '0 * * * *': 'Setiap jam (menit 00)',
    '0 0 * * *': 'Setiap hari (pukul 00:00)',
    '0 0 * * 0': 'Setiap minggu (Minggu pukul 00:00)',
    '0 0 1 * *': 'Setiap bulan (Tanggal 1 pukul 00:00)'
};

function onScheduleTypeChange(type) {
    var exprInput = document.getElementById('cronExpression');
    if (scheduleExpressions[type]) {
        exprInput.value = scheduleExpressions[type];
        updateExpressionDesc(exprInput.value);
    }
}

function onCustomExpressionInput(val) {
    document.getElementById('cronScheduleType').value = 'custom';
    updateExpressionDesc(val);
}

function updateExpressionDesc(val) {
    var descEl = document.getElementById('cronExpressionDesc');
    var trimmed = (val || '').trim();
    if (scheduleDescriptions[trimmed]) {
        descEl.textContent = scheduleDescriptions[trimmed];
    } else {
        descEl.textContent = 'Kustom (' + trimmed + ')';
    }
}

function applyPresetTemplate(type) {
    if (!type) return;

    var nameInput = document.getElementById('cronName');
    var cmdInput = document.getElementById('cronCommand');
    var typeSelect = document.getElementById('cronScheduleType');
    var exprInput = document.getElementById('cronExpression');

    // Default site path if available
    var defaultSite = sitesList.length > 0 ? sitesList[0] : null;
    var sitePath = defaultSite ? defaultSite.docroot : '/www/wwwroot/domain.com';

    if (type === 'laravel') {
        nameInput.value = 'Laravel Artisan Scheduler' + (defaultSite ? ' (' + defaultSite.domain + ')' : '');
        typeSelect.value = 'every_minute';
        exprInput.value = '* * * * *';
        cmdInput.value = '/usr/bin/php8.3 ' + sitePath + '/artisan schedule:run';
    } else if (type === 'wordpress') {
        nameInput.value = 'WordPress WP-Cron' + (defaultSite ? ' (' + defaultSite.domain + ')' : '');
        typeSelect.value = 'hourly';
        exprInput.value = '0 * * * *';
        cmdInput.value = '/usr/bin/curl -s -k https://' + (defaultSite ? defaultSite.domain : 'domain.com') + '/wp-cron.php?doing_wp_cron > /dev/null 2>&1';
    } else if (type === 'clear_cache') {
        nameInput.value = 'Bersihkan File Sementara /tmp';
        typeSelect.value = 'daily';
        exprInput.value = '0 0 * * *';
        cmdInput.value = 'find /tmp -type f -mtime +7 -delete';
    }

    updateExpressionDesc(exprInput.value);
}

function openCreateCronModal() {
    document.getElementById('cronId').value = '';
    document.getElementById('cronModalTitle').textContent = 'Tambah Tugas Cron';
    document.getElementById('cronName').value = '';
    document.getElementById('cronScheduleType').value = 'daily';
    document.getElementById('cronExpression').value = '0 0 * * *';
    document.getElementById('cronCommand').value = '';
    document.getElementById('cronUser').value = 'afandi';
    document.getElementById('cronLogMode').value = 'file';
    document.getElementById('cronPresetSelect').value = '';
    document.getElementById('templatePresetBox').style.display = 'block';

    updateExpressionDesc('0 0 * * *');
    Almusa.openModal('cronModal');
}

function openEditCronModal(job) {
    document.getElementById('cronId').value = job.id;
    document.getElementById('cronModalTitle').textContent = 'Edit Tugas Cron';
    document.getElementById('cronName').value = job.name;
    document.getElementById('cronScheduleType').value = job.schedule_type || 'custom';
    document.getElementById('cronExpression').value = job.expression;
    document.getElementById('cronCommand').value = job.command;
    document.getElementById('cronUser').value = job.run_as_user || 'afandi';
    document.getElementById('cronLogMode').value = job.log_mode || 'file';
    document.getElementById('templatePresetBox').style.display = 'none';

    updateExpressionDesc(job.expression);
    Almusa.openModal('cronModal');
}

function closeCronModal() {
    Almusa.closeModal('cronModal');
}

async function submitCronForm(e) {
    e.preventDefault();
    var id = document.getElementById('cronId').value;
    var btn = document.getElementById('cronSubmitBtn');
    var restore = Almusa.busy(btn, 'Menyimpan…');

    var payload = {
        name: document.getElementById('cronName').value.trim(),
        schedule_type: document.getElementById('cronScheduleType').value,
        expression: document.getElementById('cronExpression').value.trim(),
        command: document.getElementById('cronCommand').value.trim(),
        run_as_user: document.getElementById('cronUser').value,
        log_mode: document.getElementById('cronLogMode').value
    };

    var endpoint = id ? '/api/cron/update' : '/api/cron/create';
    if (id) payload.id = id;

    try {
        var res = await Almusa.post(endpoint, payload);
        if (res.success) {
            closeCronModal();
            Almusa.reload(res.message, 'success', 800);
        } else {
            Almusa.toast(res.error || 'Gagal menyimpan tugas cron.', 'error');
            restore();
        }
    } catch (err) {
        Almusa.toast('Terjadi kesalahan jaringan.', 'error');
        restore();
    }
}

async function toggleCronJob(id, btn) {
    var restore = Almusa.busy(btn);
    try {
        var res = await Almusa.post('/api/cron/toggle', { id: id });
        if (res.success) {
            Almusa.reload(res.message, 'success', 600);
        } else {
            Almusa.toast(res.error || 'Gagal mengubah status tugas cron.', 'error');
            restore();
        }
    } catch (err) {
        Almusa.toast('Terjadi kesalahan jaringan.', 'error');
        restore();
    }
}

async function deleteCronJob(id, name) {
    var ok = await Almusa.confirm({
        title: 'Hapus Tugas Cron?',
        message: 'Tugas cron "' + name + '" akan dicabut dari sistem dan dihapus secara permanen.',
        confirmText: 'Hapus Tugas',
        danger: true
    });
    if (!ok) return;

    try {
        var res = await Almusa.post('/api/cron/delete', { id: id });
        if (res.success) {
            Almusa.reload(res.message, 'success', 600);
        } else {
            Almusa.toast(res.error || 'Gagal menghapus tugas cron.', 'error');
        }
    } catch (err) {
        Almusa.toast('Terjadi kesalahan jaringan.', 'error');
    }
}

async function runCronNow(id, name, btn) {
    var restore = Almusa.busy(btn);
    try {
        var res = await Almusa.post('/api/cron/run-now', { id: id });
        restore();

        if (res.success) {
            document.getElementById('cronRunTitle').textContent = 'Uji Eksekusi: ' + name;
            var badge = document.getElementById('cronRunBadge');
            badge.className = 'badge ' + (res.exit_code === 0 ? 'badge-success' : 'badge-danger');
            badge.textContent = 'Exit Code: ' + res.exit_code;
            document.getElementById('cronRunDuration').textContent = 'Durasi: ' + res.duration;
            document.getElementById('cronRunOutput').textContent = res.output;

            Almusa.openModal('cronRunModal');
            Almusa.toast(res.message, res.exit_code === 0 ? 'success' : 'warning');
        } else {
            Almusa.toast(res.error || 'Gagal mengeksekusi tugas cron.', 'error');
        }
    } catch (err) {
        restore();
        Almusa.toast('Terjadi kesalahan jaringan.', 'error');
    }
}

async function openCronLogModal(id, name) {
    currentViewingCronId = id;
    document.getElementById('cronLogTitle').textContent = 'Log Output: ' + name;
    document.getElementById('cronLogSubtitle').textContent = 'Riwayat hasil eksekusi tugas cron #' + id;
    document.getElementById('cronLogBody').textContent = 'Memuat log…';

    Almusa.openModal('cronLogModal');
    await reloadCurrentCronLog();
}

async function reloadCurrentCronLog() {
    if (!currentViewingCronId) return;

    try {
        var res = await Almusa.get('/api/cron/logs?id=' + currentViewingCronId);
        if (res.success) {
            document.getElementById('cronLogSize').textContent = res.size_human;
            document.getElementById('cronLogUpdated').textContent = res.updated_at;
            document.getElementById('cronLogBody').textContent = res.log || '(Log kosong)';
        } else {
            document.getElementById('cronLogBody').textContent = 'Gagal memuat log: ' + (res.error || 'Terjadi kesalahan.');
        }
    } catch (err) {
        document.getElementById('cronLogBody').textContent = 'Terjadi kesalahan koneksi saat memuat log.';
    }
}

async function clearCurrentCronLog() {
    if (!currentViewingCronId) return;

    var ok = await Almusa.confirm({
        title: 'Kosongkan Log?',
        message: 'Riwayat catatan log untuk tugas cron ini akan dihapus.',
        confirmText: 'Kosongkan',
        danger: true
    });
    if (!ok) return;

    try {
        var res = await Almusa.post('/api/cron/clear-logs', { id: currentViewingCronId });
        if (res.success) {
            Almusa.toast(res.message, 'success');
            reloadCurrentCronLog();
        } else {
            Almusa.toast(res.error || 'Gagal mengosongkan log.', 'error');
        }
    } catch (err) {
        Almusa.toast('Terjadi kesalahan jaringan.', 'error');
    }
}
</script>
