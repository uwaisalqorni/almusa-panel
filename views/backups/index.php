<?php
/**
 * Almusa Control Panel — Cadangan & Snapshot (Backups)
 */
?>

<header class="page-header">
    <div>
        <h1 class="page-title">Cadangan &amp; Snapshot (Backups)</h1>
        <p class="page-desc">Pencadangan snapshot MySQL (<code class="code-pill">.sql.gz</code>), arsip direktori website (<code class="code-pill">.tar.gz</code>), rotasi retensi lokal, serta integrasi Cloudflare R2 / AWS S3.</p>
    </div>
    <div class="page-actions">
        <button type="button" class="btn btn-secondary" onclick="Almusa.openModal('scheduleModal')">
            <?= ui_icon('clock') ?> Jadwal &amp; Retensi
        </button>
        <button type="button" class="btn btn-secondary" onclick="Almusa.openModal('cloudConfigModal')">
            <?= ui_icon('cloud') ?> Cloud Storage (R2/S3)
        </button>
        <button type="button" class="btn btn-primary" onclick="openCreateBackupModal()">
            <?= ui_icon('plus') ?> Buat cadangan
        </button>
    </div>
</header>

<!-- Metrik / Ringkasan -->
<section class="grid grid-stats" aria-label="Ringkasan Cadangan">
    <!-- Stat 1: Total Snapshot -->
    <div class="card stat">
        <div class="stat-head">
            <span class="stat-label">Total Snapshot</span>
            <?= ui_icon('archive', 'icon-lg') ?>
        </div>
        <div class="stat-value"><?= (int)$stats['total_count'] ?></div>
        <div class="stat-meta">Lokal: <b style="color:var(--text)"><?= e($stats['total_size_h']) ?></b> di <code style="font-size:11px">/www/backup</code></div>
    </div>

    <!-- Stat 2: Database MySQL -->
    <div class="card stat">
        <div class="stat-head">
            <span class="stat-label">Database MySQL</span>
            <?= ui_icon('database', 'icon-lg') ?>
        </div>
        <div class="stat-value"><?= (int)$stats['db_count'] ?></div>
        <div class="stat-meta">Kompresi dump gzip (<code style="font-size:11px">.sql.gz</code>)</div>
    </div>

    <!-- Stat 3: Website Files -->
    <div class="card stat">
        <div class="stat-head">
            <span class="stat-label">Website Virtual Host</span>
            <?= ui_icon('globe', 'icon-lg') ?>
        </div>
        <div class="stat-value"><?= (int)$stats['site_count'] ?></div>
        <div class="stat-meta">Arsip tarball (<code style="font-size:11px">.tar.gz</code>)</div>
    </div>

    <!-- Stat 4: Cloud & Retensi -->
    <div class="card stat">
        <div class="stat-head">
            <span class="stat-label">Cloud Sync &amp; Retensi</span>
            <?= ui_icon('cloud', 'icon-lg') ?>
        </div>
        <div class="stat-value" style="font-size: 16px; margin-top: 4px; display: flex; align-items: center; gap: 6px;">
            <?php if ($stats['cloud_configured']): ?>
                <span class="badge badge-success"><?= ui_icon('check-circle') ?> R2/S3 Aktif</span>
            <?php else: ?>
                <span class="badge badge-neutral no-dot">Hanya Lokal</span>
            <?php endif; ?>
            <span class="badge badge-info no-dot"><?= (int)$stats['retention_days'] ?> Hari Retensi</span>
        </div>
        <div class="stat-meta">Jadwal: <?= $stats['auto_enabled'] ? '<b style="color:var(--ok)">Aktif</b> (' . e($stats['auto_schedule']) . ')' : 'Nonaktif' ?></div>
    </div>
</section>

<!-- Tabel Berkas Cadangan -->
<section class="card card-flush">
    <div class="card-header" style="display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 12px; padding: 14px 18px; border-bottom: 1px solid var(--border);">
        <div style="display: flex; align-items: center; gap: 8px;">
            <div class="btn-group" style="display: inline-flex; border: 1px solid var(--border-strong); border-radius: var(--radius-sm); overflow: hidden; background: var(--surface);">
                <button type="button" class="btn btn-ghost btn-sm active-pill" id="filterBtnAll" onclick="filterBackups('all', this)" style="border-radius: 0; height: 30px; font-weight: 600;">Semua (<?= count($backups) ?>)</button>
                <button type="button" class="btn btn-ghost btn-sm" id="filterBtnDb" onclick="filterBackups('database', this)" style="border-radius: 0; height: 30px; border-left: 1px solid var(--border);">Database (<?= $stats['db_count'] ?>)</button>
                <button type="button" class="btn btn-ghost btn-sm" id="filterBtnSite" onclick="filterBackups('site', this)" style="border-radius: 0; height: 30px; border-left: 1px solid var(--border);">Website (<?= $stats['site_count'] ?>)</button>
            </div>
        </div>

        <div style="display: flex; align-items: center; gap: 8px;">
            <div style="position: relative; width: 220px;">
                <input type="search" id="backupSearch" class="input input-sm" placeholder="Cari nama atau target…" oninput="searchBackups(this.value)" style="height: 30px; font-size: 12.5px;">
            </div>
            <button type="button" class="btn btn-secondary btn-sm btn-icon" onclick="window.location.reload()" title="Segarkan" aria-label="Segarkan">
                <?= ui_icon('refresh') ?>
            </button>
        </div>
    </div>

    <div class="card-body" style="padding: 0;">
        <div class="table-wrap">
            <table class="table" id="backupsTable">
                <thead>
                    <tr>
                        <th style="width: 40px; text-align: center;">Tipe</th>
                        <th>Target &amp; Berkas</th>
                        <th>Format</th>
                        <th>Ukuran</th>
                        <th>Penyimpanan</th>
                        <th>Waktu Pembuatan</th>
                        <th class="th-right" style="width: 220px;">Aksi</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($backups)): ?>
                    <tr id="emptyRow">
                        <td colspan="7">
                            <div class="empty">
                                <?= ui_icon('archive', 'icon-xl') ?>
                                <div class="empty-title">Belum ada berkas cadangan</div>
                                <p class="empty-text">Amankan data website dan database MySQL Anda dengan membuat snapshot instan atau atur jadwal harian.</p>
                                <button type="button" class="btn btn-primary" onclick="openCreateBackupModal()">
                                    <?= ui_icon('plus') ?> Buat cadangan sekarang
                                </button>
                            </div>
                        </td>
                    </tr>
                <?php else: foreach ($backups as $b): 
                    $isDb = ($b['type'] === 'database');
                    $isSynced = ((int)$b['cloud_synced'] === 1);
                    $exists = !empty($b['file_exists']);
                    $createdTime = strtotime(str_contains($b['created_at'], 'Z') ? $b['created_at'] : $b['created_at'] . ' UTC');
                ?>
                    <tr class="backup-row" data-type="<?= e($b['type']) ?>" data-search="<?= strtolower(e($b['name'] . ' ' . $b['filename'] . ' ' . $b['target'])) ?>">
                        <td style="text-align: center;">
                            <?php if ($isDb): ?>
                                <span class="badge badge-plain" style="color: #a855f7;" title="Database MySQL">
                                    <?= ui_icon('database') ?>
                                </span>
                            <?php else: ?>
                                <span class="badge badge-plain" style="color: #3b82f6;" title="Website Files">
                                    <?= ui_icon('globe') ?>
                                </span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <div class="cell-title"><?= e($b['name']) ?></div>
                            <div class="cell-sub"><?= e($b['filename']) ?></div>
                        </td>
                        <td>
                            <?php if ($isDb): ?>
                                <span class="badge badge-neutral no-dot" style="font-family: var(--mono); font-size: 11px;">.sql.gz</span>
                            <?php else: ?>
                                <span class="badge badge-neutral no-dot" style="font-family: var(--mono); font-size: 11px;">.tar.gz</span>
                            <?php endif; ?>
                        </td>
                        <td class="num" style="font-weight: 550; font-size: 13px;">
                            <?= e($b['filesize_h']) ?>
                        </td>
                        <td>
                            <div style="display: flex; align-items: center; gap: 4px; flex-wrap: wrap;">
                                <?php if ($exists): ?>
                                    <span class="badge badge-success no-dot" style="height: 20px; font-size: 11px;">Lokal</span>
                                <?php endif; ?>

                                <?php if ($isSynced): ?>
                                    <span class="badge badge-info no-dot" style="height: 20px; font-size: 11px;" title="Tersinkronkan ke Cloud: <?= e($b['cloud_key'] ?? '') ?>">Cloud</span>
                                <?php elseif ($stats['cloud_configured']): ?>
                                    <button type="button" class="btn btn-ghost btn-sm" onclick="syncBackupToCloud(<?= (int)$b['id'] ?>, this)" title="Unggah ke Cloudflare R2 / S3" style="height: 20px; padding: 0 6px; font-size: 11px;">
                                        <?= ui_icon('upload') ?> Sync
                                    </button>
                                <?php endif; ?>
                            </div>
                        </td>
                        <td>
                            <div class="cell-title" style="font-weight: 500; font-size: 12.5px;"><?= date('d M Y, H:i', $createdTime) ?></div>
                            <div class="cell-sub" style="font-size: 11.5px;"><?= e($b['time_ago']) ?> · <?= e($b['created_by']) ?></div>
                        </td>
                        <td>
                            <div class="cell-actions">
                                <?php if ($exists): ?>
                                    <a href="/api/backups/download?id=<?= (int)$b['id'] ?>" class="btn btn-secondary btn-sm" title="Unduh arsip">
                                        <?= ui_icon('download') ?> Unduh
                                    </a>
                                <?php endif; ?>

                                <button type="button" class="btn btn-secondary btn-sm" onclick="openRestoreModal(<?= htmlspecialchars(json_encode($b), ENT_QUOTES, 'UTF-8') ?>)" title="Pulihkan (Restore)">
                                    <?= ui_icon('refresh') ?> Restore
                                </button>

                                <button type="button" class="btn btn-ghost btn-icon btn-sm text-danger" onclick="confirmDeleteBackup(<?= (int)$b['id'] ?>, '<?= js_arg($b['filename']) ?>', <?= $isSynced ? 'true' : 'false' ?>)" title="Hapus cadangan" aria-label="Hapus cadangan">
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

<!-- ========================================================================= -->
<!-- MODAL 1: BUAT CADANGAN BARU                                               -->
<!-- ========================================================================= -->
<div class="modal-backdrop" id="createBackupModal">
    <div class="modal modal-md" role="dialog" aria-modal="true" aria-labelledby="createBackupTitle">
        <div class="modal-header">
            <div>
                <h2 class="modal-title" id="createBackupTitle">Buat Snapshot Cadangan Baru</h2>
                <p class="modal-subtitle">Kompresi snapshot database MySQL atau seluruh direktori berkas website.</p>
            </div>
            <button type="button" class="icon-btn" onclick="Almusa.closeModal('createBackupModal')" aria-label="Tutup"><?= ui_icon('x') ?></button>
        </div>

        <form id="createBackupForm" onsubmit="submitCreateBackup(event)">
            <div class="modal-body">
                <!-- Pilihan Tipe Backup -->
                <div class="field">
                    <label class="label">Jenis Cadangan</label>
                    <div style="display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 8px;">
                        <label class="backup-type-card active" id="typeCardDb" onclick="selectBackupType('database')">
                            <input type="radio" name="backup_type" value="database" checked style="display:none">
                            <?= ui_icon('database') ?>
                            <div style="font-weight: 600; font-size: 13px; margin-top: 4px;">Database</div>
                            <div class="opt" style="font-size: 11px;">Dump .sql.gz</div>
                        </label>
                        <label class="backup-type-card" id="typeCardSite" onclick="selectBackupType('site')">
                            <input type="radio" name="backup_type" value="site" style="display:none">
                            <?= ui_icon('globe') ?>
                            <div style="font-weight: 600; font-size: 13px; margin-top: 4px;">Website</div>
                            <div class="opt" style="font-size: 11px;">Arsip .tar.gz</div>
                        </label>
                        <label class="backup-type-card" id="typeCardFull" onclick="selectBackupType('full')">
                            <input type="radio" name="backup_type" value="full" style="display:none">
                            <?= ui_icon('archive') ?>
                            <div style="font-weight: 600; font-size: 13px; margin-top: 4px;">Penuh</div>
                            <div class="opt" style="font-size: 11px;">Semua DB + Web</div>
                        </label>
                    </div>
                </div>

                <!-- Target Database Selector -->
                <div class="field" id="targetDbWrap">
                    <label class="label" for="targetDbSelect">Target Database MySQL</label>
                    <select class="select" id="targetDbSelect" name="target_db">
                        <option value="all">★ Semua Database (Full MySQL Dump)</option>
                        <optgroup label="Database Terkelola">
                            <?php foreach ($mysqlDbs as $db): ?>
                                <option value="<?= e($db) ?>"><?= e($db) ?></option>
                            <?php endforeach; ?>
                        </optgroup>
                    </select>
                </div>

                <!-- Target Website Selector -->
                <div class="field" id="targetSiteWrap" style="display: none;">
                    <label class="label" for="targetSiteSelect">Target Direktori Website</label>
                    <select class="select" id="targetSiteSelect" name="target_site">
                        <option value="all">★ Semua Website (/www/wwwroot)</option>
                        <optgroup label="Virtual Hosts Aktif">
                            <?php foreach ($sites as $s): ?>
                                <option value="<?= e($s['domain']) ?>"><?= e($s['domain']) ?> (<?= e($s['docroot']) ?>)</option>
                            <?php endforeach; ?>
                        </optgroup>
                    </select>
                </div>

                <!-- Target Full Info -->
                <div class="field" id="targetFullWrap" style="display: none;">
                    <div class="callout-info" style="font-size: 12.5px;">
                        <?= ui_icon('info') ?> Snapshot penuh mencakup seluruh database MySQL yang aktif dan seluruh folder virtual host di dalam <code style="font-size: 11px;">/www/wwwroot</code>.
                    </div>
                </div>

                <!-- Destination Selector -->
                <div class="field">
                    <label class="label">Tujuan Penyimpanan</label>
                    <div style="display: flex; flex-direction: column; gap: 8px;">
                        <label class="check">
                            <input type="radio" name="destination" value="local" checked>
                            <div>
                                <strong>Penyimpanan Lokal Saja</strong>
                                <div class="help" style="margin-top:2px">Disimpan di direktori server <code style="font-size:11px">/www/backup/</code>.</div>
                            </div>
                        </label>
                        <label class="check">
                            <input type="radio" name="destination" value="both" <?= !$stats['cloud_configured'] ? 'disabled' : '' ?>>
                            <div>
                                <strong>Keduanya (Lokal &amp; Remote Cloud)</strong>
                                <div class="help" style="margin-top:2px">Simpan salinan lokal sekaligus kirim ke Cloudflare R2 / AWS S3. <?= !$stats['cloud_configured'] ? '<span style="color:var(--warn)">(Konfigurasi cloud belum diatur)</span>' : '' ?></div>
                            </div>
                        </label>
                        <label class="check">
                            <input type="radio" name="destination" value="cloud" <?= !$stats['cloud_configured'] ? 'disabled' : '' ?>>
                            <div>
                                <strong>Cloud Saja (Hemat Ruang Disk)</strong>
                                <div class="help" style="margin-top:2px">Arsip diunggah ke cloud, lalu file lokal segera dihapus otomatis.</div>
                            </div>
                        </label>
                    </div>
                </div>

                <!-- Real-time Progress Display -->
                <div id="backupProgressBox" style="display: none; margin-top: 10px;">
                    <div class="callout-info" id="backupProgressText" style="display: flex; align-items: center; gap: 8px; font-size: 13px;">
                        <span class="spinner"></span>
                        <span>Sedang membuat snapshot terkompresi… Mohon tunggu sebentar.</span>
                    </div>
                </div>
            </div>

            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="Almusa.closeModal('createBackupModal')">Batal</button>
                <button type="submit" class="btn btn-primary" id="btnSubmitBackup">
                    <?= ui_icon('zap') ?> Mulai Snapshot
                </button>
            </div>
        </form>
    </div>
</div>

<!-- ========================================================================= -->
<!-- MODAL 2: KONFIGURASI CLOUD R2 / AWS S3                                   -->
<!-- ========================================================================= -->
<div class="modal-backdrop" id="cloudConfigModal">
    <div class="modal modal-md" role="dialog" aria-modal="true" aria-labelledby="cloudConfigTitle">
        <div class="modal-header">
            <div>
                <h2 class="modal-title" id="cloudConfigTitle">Konfigurasi Cloud Storage (R2 / S3)</h2>
                <p class="modal-subtitle">Kirim arsip cadangan secara otomatis ke Cloudflare R2 atau Amazon AWS S3.</p>
            </div>
            <button type="button" class="icon-btn" onclick="Almusa.closeModal('cloudConfigModal')" aria-label="Tutup"><?= ui_icon('x') ?></button>
        </div>

        <form id="cloudConfigForm" onsubmit="submitCloudConfig(event)">
            <div class="modal-body">
                <div class="callout-info" style="font-size: 12.5px; margin-bottom: 14px;">
                    <?= ui_icon('shield') ?> Snapshot diunggah dengan enkripsi aman menggunakan protokol <strong>AWS Signature Version 4 (SigV4)</strong> bawaan tanpa pihak ketiga.
                </div>

                <div class="field-row">
                    <div class="field">
                        <label class="label" for="cloudProvider">Penyedia Layanan</label>
                        <select class="select" name="cloud_storage_provider" id="cloudProvider" onchange="applyProviderPreset(this.value)">
                            <option value="cloudflare_r2" <?= $cloudSettings['provider'] === 'cloudflare_r2' ? 'selected' : '' ?>>Cloudflare R2</option>
                            <option value="aws_s3" <?= $cloudSettings['provider'] === 'aws_s3' ? 'selected' : '' ?>>Amazon AWS S3</option>
                            <option value="other" <?= $cloudSettings['provider'] === 'other' ? 'selected' : '' ?>>S3-Compatible Lainnya</option>
                        </select>
                    </div>

                    <div class="field">
                        <label class="label" for="cloudRegion">Region</label>
                        <input type="text" class="input" name="cloud_storage_region" id="cloudRegion" value="<?= e($cloudSettings['region']) ?>" placeholder="auto / us-east-1">
                    </div>
                </div>

                <div class="field">
                    <label class="label" for="cloudEndpoint">Endpoint S3 URL</label>
                    <input type="url" class="input mono" name="cloud_storage_endpoint" id="cloudEndpoint" value="<?= e($cloudSettings['endpoint']) ?>" placeholder="https://<account_id>.r2.cloudflarestorage.com" required>
                    <div class="help">Format R2: <code style="font-size:11px">https://&lt;ACCOUNT_ID&gt;.r2.cloudflarestorage.com</code></div>
                </div>

                <div class="field">
                    <label class="label" for="cloudBucket">Nama Bucket</label>
                    <input type="text" class="input" name="cloud_storage_bucket" id="cloudBucket" value="<?= e($cloudSettings['bucket']) ?>" placeholder="contoh: almusa-backups" required>
                </div>

                <div class="field-row">
                    <div class="field">
                        <label class="label" for="cloudAccessKey">Access Key ID</label>
                        <input type="text" class="input mono" name="cloud_storage_access_key" id="cloudAccessKey" value="<?= e($cloudSettings['access_key']) ?>" placeholder="Kunci Akses S3" required autocomplete="off">
                    </div>
                    <div class="field">
                        <label class="label" for="cloudSecretKey">Secret Access Key</label>
                        <input type="password" class="input mono" name="cloud_storage_secret_key" id="cloudSecretKey" value="<?= e($cloudSettings['secret_key']) ?>" placeholder="••••••••••••••••" autocomplete="new-password">
                    </div>
                </div>

                <div class="field">
                    <label class="label" for="cloudPrefix">Folder Prefix di Bucket <span class="opt">(Opsional)</span></label>
                    <input type="text" class="input" name="cloud_storage_prefix" id="cloudPrefix" value="<?= e($cloudSettings['prefix']) ?>" placeholder="almusa-backups">
                </div>

                <!-- Test Connection Feedback Banner -->
                <div id="cloudTestResult" style="display: none; margin-top: 10px;"></div>
            </div>

            <div class="modal-footer" style="justify-content: space-between;">
                <button type="button" class="btn btn-secondary" id="btnTestCloud" onclick="testCloudConnection()">
                    <?= ui_icon('zap') ?> Uji Koneksi
                </button>
                <div style="display: flex; gap: 8px;">
                    <button type="button" class="btn btn-secondary" onclick="Almusa.closeModal('cloudConfigModal')">Tutup</button>
                    <button type="submit" class="btn btn-primary">Simpan Pengaturan</button>
                </div>
            </div>
        </form>
    </div>
</div>

<!-- ========================================================================= -->
<!-- MODAL 3: JADWAL OTOMATIS & RETENSI                                       -->
<!-- ========================================================================= -->
<div class="modal-backdrop" id="scheduleModal">
    <div class="modal modal-md" role="dialog" aria-modal="true" aria-labelledby="scheduleTitle">
        <div class="modal-header">
            <div>
                <h2 class="modal-title" id="scheduleTitle">Jadwal Backup Otomatis &amp; Retensi</h2>
                <p class="modal-subtitle">Konfigurasi cron daemon Linux untuk membuat cadangan rutin tanpa intervensi manual.</p>
            </div>
            <button type="button" class="icon-btn" onclick="Almusa.closeModal('scheduleModal')" aria-label="Tutup"><?= ui_icon('x') ?></button>
        </div>

        <form id="scheduleForm" onsubmit="submitScheduleConfig(event)">
            <div class="modal-body">
                <!-- Toggle Aktifkan -->
                <div class="callout-info" style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 16px;">
                    <div>
                        <div style="font-weight: 600; font-size: 13.5px; color: var(--text);">Jadwalkan Cadangan Otomatis</div>
                        <div class="help" style="margin-top: 2px;">Tugas berjalan otomatis di sistem cron Linux (<code style="font-size:11px">/etc/cron.d/almusa-cron</code>).</div>
                    </div>
                    <label class="check" style="margin: 0;">
                        <input type="checkbox" name="backup_auto_enabled" value="1" <?= $scheduleSettings['enabled'] ? 'checked' : '' ?> style="width: 18px; height: 18px;">
                    </label>
                </div>

                <!-- Schedule Presets -->
                <div class="field">
                    <label class="label" for="schedulePreset">Waktu &amp; Frekuensi Eksekusi</label>
                    <select class="select" id="schedulePreset" onchange="applySchedulePreset(this.value)">
                        <option value="0 2 * * *" <?= $scheduleSettings['schedule'] === '0 2 * * *' ? 'selected' : '' ?>>Setiap Hari pk 02:00 Pagi (Disarankan)</option>
                        <option value="0 0 * * *" <?= $scheduleSettings['schedule'] === '0 0 * * *' ? 'selected' : '' ?>>Setiap Hari pk 00:00 Tengah Malam</option>
                        <option value="0 3 * * 0" <?= $scheduleSettings['schedule'] === '0 3 * * 0' ? 'selected' : '' ?>>Setiap Minggu (Minggu pk 03:00)</option>
                        <option value="0 0 1 * *" <?= $scheduleSettings['schedule'] === '0 0 1 * *' ? 'selected' : '' ?>>Setiap Bulan (Tanggal 1 pk 00:00)</option>
                        <option value="custom">Ekspresi Cron Kustom…</option>
                    </select>
                </div>

                <!-- Cron Expression Input -->
                <div class="field" id="customCronWrap" style="<?= in_array($scheduleSettings['schedule'], ['0 2 * * *', '0 0 * * *', '0 3 * * 0', '0 0 1 * *']) ? 'display: none;' : '' ?>">
                    <label class="label" for="customCronInput">Ekspresi Cron Linux (5 Karakter)</label>
                    <input type="text" class="input mono" name="backup_auto_schedule" id="customCronInput" value="<?= e($scheduleSettings['schedule']) ?>" placeholder="* * * * *">
                </div>

                <!-- Target Cadangan Otomatis -->
                <div class="field">
                    <label class="label" for="autoTargetType">Target Pencadangan Otomatis</label>
                    <select class="select" name="backup_auto_target_type" id="autoTargetType">
                        <option value="all_db" <?= $scheduleSettings['target_type'] === 'all_db' ? 'selected' : '' ?>>Semua Database MySQL (Dump Terkompresi)</option>
                        <option value="all_sites" <?= $scheduleSettings['target_type'] === 'all_sites' ? 'selected' : '' ?>>Semua Website (/www/wwwroot)</option>
                        <option value="full" <?= $scheduleSettings['target_type'] === 'full' ? 'selected' : '' ?>>Penuh (Semua Database + Semua Website)</option>
                    </select>
                </div>

                <!-- Tujuan Penyimpanan Otomatis -->
                <div class="field">
                    <label class="label" for="autoDest">Tujuan Penyimpanan</label>
                    <select class="select" name="backup_auto_destination" id="autoDest">
                        <option value="local" <?= $scheduleSettings['destination'] === 'local' ? 'selected' : '' ?>>Lokal Saja (/www/backup)</option>
                        <option value="both" <?= $scheduleSettings['destination'] === 'both' ? 'selected' : '' ?> <?= !$stats['cloud_configured'] ? 'disabled' : '' ?>>Keduanya (Lokal &amp; Cloud R2/S3)</option>
                        <option value="cloud" <?= $scheduleSettings['destination'] === 'cloud' ? 'selected' : '' ?> <?= !$stats['cloud_configured'] ? 'disabled' : '' ?>>Cloud Saja (Hemat Disk Server)</option>
                    </select>
                </div>

                <!-- Kebijakan Retensi Rotasi -->
                <div class="field">
                    <label class="label" for="retentionDays">Masa Retensi Rotasi Otomatis</label>
                    <select class="select" name="backup_retention_days" id="retentionDays">
                        <option value="3" <?= $scheduleSettings['retention_days'] === 3 ? 'selected' : '' ?>>Simpan 3 Hari Terakhir</option>
                        <option value="7" <?= $scheduleSettings['retention_days'] === 7 ? 'selected' : '' ?>>Simpan 7 Hari Terakhir (Disarankan)</option>
                        <option value="14" <?= $scheduleSettings['retention_days'] === 14 ? 'selected' : '' ?>>Simpan 14 Hari Terakhir (2 Minggu)</option>
                        <option value="30" <?= $scheduleSettings['retention_days'] === 30 ? 'selected' : '' ?>>Simpan 30 Hari Terakhir (1 Bulan)</option>
                        <option value="60" <?= $scheduleSettings['retention_days'] === 60 ? 'selected' : '' ?>>Simpan 60 Hari Terakhir (2 Bulan)</option>
                    </select>
                    <div class="help">Arsip lokal yang usianya melampaui masa retensi akan dipangkas secara otomatis untuk mencegah kehabisan kapasitas disk server.</div>
                </div>
            </div>

            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="Almusa.closeModal('scheduleModal')">Batal</button>
                <button type="submit" class="btn btn-primary">Simpan Jadwal</button>
            </div>
        </form>
    </div>
</div>

<!-- ========================================================================= -->
<!-- MODAL 4: KONFIRMASI RESTORE (SAFETY GUARD)                               -->
<!-- ========================================================================= -->
<div class="modal-backdrop" id="restoreModal">
    <div class="modal modal-sm" role="dialog" aria-modal="true" aria-labelledby="restoreModalTitle">
        <div class="modal-header">
            <div>
                <h2 class="modal-title" id="restoreModalTitle">Pemulihan Data (Restore)</h2>
                <p class="modal-subtitle">Pulihkan database atau berkas situs dari snapshot.</p>
            </div>
            <button type="button" class="icon-btn" onclick="Almusa.closeModal('restoreModal')" aria-label="Tutup"><?= ui_icon('x') ?></button>
        </div>

        <div class="modal-body">
            <div class="callout-danger" style="margin-bottom: 14px;">
                <div style="font-weight: 600; margin-bottom: 4px; display: flex; align-items: center; gap: 6px;">
                    <?= ui_icon('alert-triangle') ?> Peringatan Keselamatan Data
                </div>
                <div style="font-size: 12.5px; line-height: 1.45;">
                    Pemulihan ini akan <strong>menimpa data atau berkas aktif saat ini</strong> dengan salinan yang ada di dalam snapshot arsip. Tindakan ini tidak dapat dibatalkan.
                </div>
            </div>

            <div style="background: var(--surface-2); padding: 12px; border-radius: var(--radius-sm); border: 1px solid var(--border); font-size: 12.5px;">
                <div><span class="text-3">Target:</span> <strong id="restoreTargetName">--</strong></div>
                <div style="margin-top: 4px;"><span class="text-3">Berkas:</span> <code id="restoreFileName" style="font-size: 11px;">--</code></div>
                <div style="margin-top: 4px;"><span class="text-3">Ukuran:</span> <span id="restoreFileSize" class="num">--</span></div>
            </div>

            <label class="check" style="margin-top: 14px;">
                <input type="checkbox" id="chkRestoreConfirm" onchange="document.getElementById('btnDoRestore').disabled = !this.checked">
                <span style="font-size: 12.5px;">Saya memahami risiko dan setuju untuk menimpa state target saat ini.</span>
            </label>
        </div>

        <div class="modal-footer">
            <button type="button" class="btn btn-secondary" onclick="Almusa.closeModal('restoreModal')">Batal</button>
            <button type="button" class="btn btn-danger-solid" id="btnDoRestore" disabled onclick="executeRestore()">
                <?= ui_icon('refresh') ?> Mulai Restore
            </button>
        </div>
    </div>
</div>

<style>
.active-pill {
    background: var(--surface-hover) !important;
    color: var(--accent-text) !important;
}
.backup-type-card {
    border: 1px solid var(--border-strong);
    padding: 12px 8px;
    border-radius: var(--radius-sm);
    cursor: pointer;
    text-align: center;
    background: var(--surface);
    transition: all 0.12s ease;
}
.backup-type-card:hover {
    border-color: var(--accent);
    background: var(--surface-hover);
}
.backup-type-card.active {
    border-color: var(--accent);
    background: var(--accent-soft);
    color: var(--accent-text);
}
.code-pill {
    background: var(--surface-2);
    padding: 2px 6px;
    border-radius: 4px;
    border: 1px solid var(--border);
    font-family: var(--mono);
    font-size: 11.5px;
}
</style>

<script>
// Filter backups by type chip
function filterBackups(type, btn) {
    document.querySelectorAll('.btn-group .btn').forEach(function(el) {
        el.classList.remove('active-pill');
        el.style.fontWeight = '400';
    });
    btn.classList.add('active-pill');
    btn.style.fontWeight = '600';

    var rows = document.querySelectorAll('.backup-row');
    rows.forEach(function(row) {
        if (type === 'all' || row.dataset.type === type) {
            row.style.display = '';
        } else {
            row.style.display = 'none';
        }
    });
}

// Live Search
function searchBackups(q) {
    q = q.toLowerCase().trim();
    var rows = document.querySelectorAll('.backup-row');
    rows.forEach(function(row) {
        var text = row.dataset.search || '';
        row.style.display = text.includes(q) ? '' : 'none';
    });
}

function openCreateBackupModal() {
    var p = document.getElementById('backupProgressBox');
    if (p) p.style.display = 'none';
    var b = document.getElementById('btnSubmitBackup');
    if (b) b.disabled = false;
    Almusa.openModal('createBackupModal');
}

function selectBackupType(type) {
    document.querySelectorAll('.backup-type-card').forEach(function(el) { el.classList.remove('active'); });
    if (type === 'database') document.getElementById('typeCardDb').classList.add('active');
    if (type === 'site') document.getElementById('typeCardSite').classList.add('active');
    if (type === 'full') document.getElementById('typeCardFull').classList.add('active');

    var radio = document.querySelector('input[name="backup_type"][value="' + type + '"]');
    if (radio) radio.checked = true;

    document.getElementById('targetDbWrap').style.display = (type === 'database') ? 'block' : 'none';
    document.getElementById('targetSiteWrap').style.display = (type === 'site') ? 'block' : 'none';
    document.getElementById('targetFullWrap').style.display = (type === 'full') ? 'block' : 'none';
}

// Submit Create Backup
async function submitCreateBackup(e) {
    e.preventDefault();
    var type = document.querySelector('input[name="backup_type"]:checked').value;
    var dest = document.querySelector('input[name="destination"]:checked').value;
    var target = '';

    if (type === 'database') {
        target = document.getElementById('targetDbSelect').value;
    } else if (type === 'site') {
        target = document.getElementById('targetSiteSelect').value;
    } else {
        target = 'all';
    }

    var btn = document.getElementById('btnSubmitBackup');
    var progressBox = document.getElementById('backupProgressBox');
    var progressText = document.getElementById('backupProgressText');

    btn.disabled = true;
    progressBox.style.display = 'block';
    progressText.className = 'callout-info';
    progressText.innerHTML = '<span class="spinner"></span> <span>Sedang membuat snapshot terkompresi… Mohon tunggu sebentar.</span>';

    try {
        var reqType = (type === 'full' ? 'full' : (target === 'all' ? (type === 'database' ? 'all_db' : 'all_sites') : type));
        var data = await Almusa.post('/api/backups/create', {
            type: reqType,
            target: target,
            destination: dest
        });

        if (data.success) {
            progressText.className = 'callout-success';
            progressText.innerHTML = '<span style="color:var(--ok)">✓ Berhasil!</span> ' + (data.message || 'Snapshot cadangan selesai.');
            setTimeout(function() {
                Almusa.reload(data.message || 'Snapshot selesai dibuat.', 'success');
            }, 900);
        } else {
            progressText.className = 'callout-danger';
            progressText.innerHTML = '<span style="color:var(--err)">✕ Gagal:</span> ' + (data.error || 'Terjadi kesalahan sistem.');
            btn.disabled = false;
        }
    } catch (err) {
        progressText.className = 'callout-danger';
        progressText.innerHTML = '<span style="color:var(--err)">✕ Gagal:</span> ' + err.message;
        btn.disabled = false;
    }
}

// Sync single backup to Cloud
async function syncBackupToCloud(id, btn) {
    var ok = await Almusa.confirm({
        title: 'Unggah ke Cloud Storage',
        message: 'Kirim salinan snapshot berkas ini ke Cloudflare R2 / AWS S3 sekarang?',
        confirmText: 'Mulai Unggah'
    });
    if (!ok) return;

    var restore = Almusa.busy(btn, 'Sync…');
    try {
        var data = await Almusa.post('/api/backups/sync-cloud', { id: id });
        if (data.success) {
            Almusa.reload(data.message || 'Berhasil diunggah ke cloud.', 'success');
        } else {
            Almusa.toast(data.error || 'Gagal sinkronisasi ke cloud.', 'error');
            restore();
        }
    } catch (err) {
        Almusa.toast('Terjadi kesalahan jaringan.', 'error');
        restore();
    }
}

// Delete backup
async function confirmDeleteBackup(id, filename, isSynced) {
    var ok = await Almusa.confirm({
        title: 'Hapus berkas cadangan',
        message: "Hapus berkas cadangan '" + filename + "'?\n\nTindakan ini akan menghapus arsip fisik dari server.",
        confirmText: 'Hapus berkas',
        danger: true
    });
    if (!ok) return;

    try {
        var data = await Almusa.post('/api/backups/delete', { id: id, delete_remote: isSynced ? 1 : 0 });
        if (data.success) {
            Almusa.reload(data.message || 'Berkas cadangan berhasil dihapus.', 'success');
        } else {
            Almusa.toast(data.error || 'Gagal menghapus berkas.', 'error');
        }
    } catch (err) {
        Almusa.toast('Terjadi kesalahan jaringan.', 'error');
    }
}

// Test Cloud Connection
async function testCloudConnection() {
    var btn = document.getElementById('btnTestCloud');
    var resultBox = document.getElementById('cloudTestResult');
    var restore = Almusa.busy(btn, 'Menguji…');
    resultBox.style.display = 'none';

    try {
        var data = await Almusa.post('/api/backups/test-cloud', {
            endpoint: document.getElementById('cloudEndpoint').value,
            bucket: document.getElementById('cloudBucket').value,
            access_key: document.getElementById('cloudAccessKey').value,
            secret_key: document.getElementById('cloudSecretKey').value,
            region: document.getElementById('cloudRegion').value
        });

        resultBox.style.display = 'block';
        if (data.success) {
            resultBox.className = 'callout-success';
            resultBox.innerHTML = '✓ ' + data.message + ' (Ping: ' + data.latency_ms + ' ms)';
        } else {
            resultBox.className = 'callout-danger';
            resultBox.innerHTML = '✕ ' + (data.error || 'Koneksi gagal.');
        }
    } catch (err) {
        resultBox.style.display = 'block';
        resultBox.className = 'callout-danger';
        resultBox.innerHTML = '✕ Gagal: ' + err.message;
    } finally {
        restore();
    }
}

// Submit Cloud Config
async function submitCloudConfig(e) {
    e.preventDefault();
    var form = e.target;
    var data = {
        cloud_storage_provider: form.cloud_storage_provider.value,
        cloud_storage_region: form.cloud_storage_region.value,
        cloud_storage_endpoint: form.cloud_storage_endpoint.value,
        cloud_storage_bucket: form.cloud_storage_bucket.value,
        cloud_storage_access_key: form.cloud_storage_access_key.value,
        cloud_storage_secret_key: form.cloud_storage_secret_key.value,
        cloud_storage_prefix: form.cloud_storage_prefix.value
    };

    try {
        var res = await Almusa.post('/api/backups/settings/cloud', data);
        if (res.success) {
            Almusa.reload(res.message || 'Pengaturan Cloud berhasil disimpan!', 'success');
        } else {
            Almusa.toast(res.error || 'Gagal menyimpan pengaturan.', 'error');
        }
    } catch (err) {
        Almusa.toast('Terjadi kesalahan jaringan.', 'error');
    }
}

function applyProviderPreset(provider) {
    var regionInput = document.getElementById('cloudRegion');
    var endpointInput = document.getElementById('cloudEndpoint');

    if (provider === 'cloudflare_r2') {
        regionInput.value = 'auto';
        if (!endpointInput.value || endpointInput.value.includes('amazonaws.com')) {
            endpointInput.value = '<?= e($cloudSettings['endpoint'] ?? '') ?>';
        }
    } else if (provider === 'aws_s3') {
        regionInput.value = 'us-east-1';
        endpointInput.value = 'https://s3.us-east-1.amazonaws.com';
    }
}

function applySchedulePreset(val) {
    var customWrap = document.getElementById('customCronWrap');
    var input = document.getElementById('customCronInput');
    if (val === 'custom') {
        customWrap.style.display = 'block';
    } else {
        customWrap.style.display = 'none';
        input.value = val;
    }
}

async function submitScheduleConfig(e) {
    e.preventDefault();
    var form = e.target;
    var data = {
        backup_auto_enabled: form.backup_auto_enabled.checked ? 1 : 0,
        backup_auto_schedule: form.backup_auto_schedule.value,
        backup_auto_target_type: form.backup_auto_target_type.value,
        backup_auto_destination: form.backup_auto_destination.value,
        backup_retention_days: form.backup_retention_days.value
    };

    try {
        var res = await Almusa.post('/api/backups/settings/schedule', data);
        if (res.success) {
            Almusa.reload(res.message || 'Jadwal berhasil disimpan!', 'success');
        } else {
            Almusa.toast(res.error || 'Gagal menyimpan jadwal.', 'error');
        }
    } catch (err) {
        Almusa.toast('Terjadi kesalahan jaringan.', 'error');
    }
}

// Restore Modal
var currentRestoreBackup = null;
function openRestoreModal(backup) {
    currentRestoreBackup = backup;
    document.getElementById('restoreTargetName').textContent = backup.name;
    document.getElementById('restoreFileName').textContent = backup.filename;
    document.getElementById('restoreFileSize').textContent = backup.filesize_h;
    document.getElementById('chkRestoreConfirm').checked = false;
    document.getElementById('btnDoRestore').disabled = true;
    Almusa.openModal('restoreModal');
}

function executeRestore() {
    Almusa.toast('Fitur 1-Click Restore siap dikonfigurasi pada Langkah 3.3.', 'info');
    Almusa.closeModal('restoreModal');
}
</script>
