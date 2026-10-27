<header class="page-header">
    <div>
        <h1 class="page-title"><?= e($title) ?></h1>
        <p class="page-desc">Server memantau dan memulihkan diri sendiri secara mandiri jika ada layanan yang mati mendadak.</p>
    </div>
    <div class="page-actions">
        <button type="button" class="btn btn-secondary" onclick="openRawLogModal()">
            <?= ui_icon('file-text') ?> Log Insiden (.log)
        </button>
        <button type="button" class="btn btn-primary" id="btnRunWatchdog" onclick="executeWatchdogCheck()">
            <?= ui_icon('refresh') ?> Jalankan Pengecekan
        </button>
    </div>
</header>

<!-- ========================================================================= -->
<!-- 1. STATS METRICS ROW (STANDAR GRID-STATS)                                 -->
<!-- ========================================================================= -->
<section class="grid grid-stats" aria-label="Statistik Kesehatan Watchdog">
    <!-- Stat 1: Daemon Status -->
    <div class="card stat">
        <div class="stat-head">
            <span class="stat-label">Daemon Watchdog</span>
            <?= ui_icon('activity', 'icon-lg') ?>
        </div>
        <div class="stat-value" style="font-size: 19px; font-weight: 700; color: var(--ok); display: flex; align-items: center; gap: 6px;">
            <span class="dot dot-ok"></span> Aktif
        </div>
        <div class="stat-meta">
            <?php if ($timerActive): ?>
                Systemd Timer · 1 Menit
            <?php elseif ($cronActive): ?>
                System Cron · 1 Menit
            <?php else: ?>
                Standby Mode
            <?php endif; ?>
        </div>
    </div>

    <!-- Stat 2: Monitored Services -->
    <div class="card stat">
        <div class="stat-head">
            <span class="stat-label">Layanan Terpantau</span>
            <?= ui_icon('server', 'icon-lg') ?>
        </div>
        <div class="stat-value num"><?= count($services) ?></div>
        <div class="stat-meta">Nginx, MariaDB, PHP-FPM, Worker</div>
    </div>

    <!-- Stat 3: Auto-Heal Count -->
    <?php
    $recoveredCount = count(array_filter($incidents, fn($i) => $i['recovery_status'] === 'recovered'));
    ?>
    <div class="card stat">
        <div class="stat-head">
            <span class="stat-label">Insiden Terpulihkan</span>
            <?= ui_icon('shield', 'icon-lg') ?>
        </div>
        <div class="stat-value num text-success"><?= $recoveredCount ?></div>
        <div class="stat-meta">100% Berhasil direstart otomatis</div>
    </div>

    <!-- Stat 4: Server Integrity -->
    <div class="card stat">
        <div class="stat-head">
            <span class="stat-label">Integritas Server</span>
            <?= ui_icon('zap', 'icon-lg') ?>
        </div>
        <div class="stat-value" style="font-size: 20px; color: var(--accent-text);">100% Siap</div>
        <div class="stat-meta">Auto-restart instan saat service crash</div>
    </div>
</section>

<!-- ========================================================================= -->
<!-- 2. GRID STATUS LAYANAN TERPANTAU                                          -->
<!-- ========================================================================= -->
<section class="card" style="margin-bottom: 24px;" aria-labelledby="monitoredServicesTitle">
    <div class="card-header">
        <div>
            <h2 class="card-title" id="monitoredServicesTitle">Layanan Inti dalam Pengawasan</h2>
            <p class="card-desc">Watchdog memonitor proses setiap 60 detik dan segera merestart service jika terdeteksi crash/mati.</p>
        </div>
        <span class="badge badge-accent" style="font-size: 11px; padding: 3px 8px;">
            <?= count($services) ?> Layanan Terlindungi
        </span>
    </div>

    <div class="card-body">
        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(320px, 1fr)); gap: 14px;">
            <?php foreach ($services as $svc): 
                $isActive = ($svc['status'] === 'active');
            ?>
                <div class="watchdog-service-card" style="background: var(--surface-2); border: 1px solid var(--border); border-radius: var(--radius-sm); padding: 14px 16px; display: flex; flex-direction: column; justify-content: space-between; gap: 10px; transition: border-color 0.15s, background 0.15s;">
                    <div>
                        <div style="display: flex; justify-content: space-between; align-items: center; gap: 8px;">
                            <div style="display: flex; align-items: center; gap: 10px;">
                                <div class="icon-tile" style="width: 32px; height: 32px; border-radius: var(--radius-xs);">
                                    <?= ui_icon($svc['icon']) ?>
                                </div>
                                <div>
                                    <div class="cell-title" style="font-weight: 600; font-size: 14px;">
                                        <?= e($svc['name']) ?>
                                    </div>
                                    <div class="cell-sub" style="font-size: 11.5px; margin-top: 1px;">
                                        <?= e($svc['label']) ?>
                                    </div>
                                </div>
                            </div>
                            <?php if ($isActive): ?>
                                <span class="badge badge-success"><span class="dot dot-ok"></span> Berjalan</span>
                            <?php else: ?>
                                <span class="badge badge-danger"><span class="dot dot-err"></span> <?= e($svc['status']) ?></span>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div style="border-top: 1px solid var(--border); padding-top: 8px; display: flex; justify-content: space-between; align-items: center; font-size: 11.5px; color: var(--text-3);">
                        <span class="truncate" style="max-width: 210px;" title="<?= e($svc['meta']) ?>">
                            <?= e($svc['meta']) ?>
                        </span>
                        <span class="badge badge-neutral" style="font-size: 10px; padding: 2px 6px;">Auto-Heal: Restart</span>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</section>

<!-- ========================================================================= -->
<!-- 3. RIWAYAT INSIDEN & PEMULIHAN OTOMATIS                                   -->
<!-- ========================================================================= -->
<section class="card card-flush" aria-labelledby="incidentHistoryTitle">
    <div class="card-header">
        <div>
            <h2 class="card-title" id="incidentHistoryTitle">Riwayat Insiden & Auto-Healing</h2>
            <p class="card-desc">Catatan rekaman setiap kali ada gangguan layanan yang terdeteksi dan dipulihkan otomatis oleh Watchdog.</p>
        </div>
        <?php if (!empty($incidents)): ?>
            <button type="button" class="btn btn-ghost btn-sm text-danger" onclick="clearIncidentHistory()">
                <?= ui_icon('trash') ?> Bersihkan Riwayat
            </button>
        <?php endif; ?>
    </div>

    <div class="card-body">
        <?php if (empty($incidents)): ?>
            <div class="empty-state" style="padding: 48px 16px;">
                <div class="empty-icon text-success"><?= ui_icon('check-circle', 'icon-xl') ?></div>
                <h3 class="empty-title">Tidak Ada Insiden</h3>
                <p class="empty-desc">Seluruh layanan terpantau sehat dan berjalan stabil tanpa gangguan.</p>
            </div>
        <?php else: ?>
            <div class="table-wrap">
                <table class="table">
                    <thead>
                        <tr>
                            <th style="width: 175px;">Waktu Kejadian</th>
                            <th style="width: 155px;">Layanan</th>
                            <th style="width: 140px;">Jenis Insiden</th>
                            <th style="width: 160px;">Tindakan Auto-Heal</th>
                            <th style="width: 140px;">Status Pemulihan</th>
                            <th>Detail Informasi</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($incidents as $inc): 
                        $isRecovered = ($inc['recovery_status'] === 'recovered');
                        $svcName = $inc['service_name'];
                        $icon = 'shield';
                        if ($svcName === 'nginx') $icon = 'server';
                        elseif (str_contains($svcName, 'mariadb') || str_contains($svcName, 'mysql')) $icon = 'database';
                        elseif (str_starts_with($svcName, 'php')) $icon = 'zap';
                        elseif ($svcName === 'cloudflared') $icon = 'cloud';
                        $timestamp = strtotime($inc['created_at']);
                    ?>
                        <tr>
                            <td>
                                <div class="cell-title num" style="font-weight: 500; font-size: 12.5px;">
                                    <?= date('d M Y, H:i:s', $timestamp) ?>
                                </div>
                                <div class="cell-sub" style="font-size: 11.5px;"><?= e($inc['time_ago'] ?? '') ?></div>
                            </td>
                            <td>
                                <div style="display: flex; align-items: center; gap: 8px;">
                                    <span class="icon-tile" style="width: 24px; height: 24px; border-radius: 4px;">
                                        <?= ui_icon($icon) ?>
                                    </span>
                                    <code style="font-size: 12.5px; font-weight: 600; color: var(--text);"><?= e($svcName) ?></code>
                                </div>
                            </td>
                            <td>
                                <?php if ($inc['event_type'] === 'service_down'): ?>
                                    <span class="badge badge-warning">Layanan Mati</span>
                                <?php elseif ($inc['event_type'] === 'disk_high'): ?>
                                    <span class="badge badge-danger">Disk > 85%</span>
                                <?php else: ?>
                                    <span class="badge badge-neutral"><?= e($inc['event_type']) ?></span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <code style="font-size: 11px; padding: 3px 6px; background: var(--surface-3); border-radius: var(--radius-xs); border: 1px solid var(--border); font-family: var(--font-mono);">
                                    <?= e($inc['action_taken']) ?>
                                </code>
                            </td>
                            <td>
                                <?php if ($isRecovered): ?>
                                    <span class="badge badge-success"><span class="dot dot-ok"></span> Pulih Berhasil</span>
                                <?php elseif ($inc['recovery_status'] === 'warning'): ?>
                                    <span class="badge badge-warning">Peringatan</span>
                                <?php else: ?>
                                    <span class="badge badge-danger"><span class="dot dot-err"></span> Gagal Pulih</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <div class="cell-sub" style="font-size: 12px; color: var(--text-2); line-height: 1.45; word-break: break-all;">
                                    <?= e($inc['details']) ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</section>

<!-- ========================================================================= -->
<!-- MODAL: RAW LOG INCIDENT VIEWER                                            -->
<!-- ========================================================================= -->
<div class="modal-backdrop" id="rawLogModal">
    <div class="modal modal-lg" role="dialog" aria-modal="true" aria-labelledby="rawLogModalTitle">
        <div class="modal-header">
            <div>
                <h2 class="modal-title" id="rawLogModalTitle">Log Insiden Teks (data/panel_incident.log)</h2>
                <p class="modal-subtitle">Rekaman mentah insiden sistem yang ditulis langsung oleh watchdog.</p>
            </div>
            <button type="button" class="icon-btn" onclick="Almusa.closeModal('rawLogModal')" aria-label="Tutup"><?= ui_icon('x') ?></button>
        </div>

        <div class="modal-body">
            <pre id="rawLogContent" style="background: var(--code-bg); color: var(--code-text); padding: 14px; border-radius: var(--radius-sm); font-size: 12px; font-family: var(--font-mono); max-height: 420px; overflow-y: auto; white-space: pre-wrap; word-break: break-all; border: 1px solid var(--border);">Memuat log...</pre>
        </div>

        <div class="modal-footer">
            <button type="button" class="btn btn-secondary" onclick="copyLogContent()">
                <?= ui_icon('copy') ?> Salin Log
            </button>
            <button type="button" class="btn btn-primary" onclick="Almusa.closeModal('rawLogModal')">Tutup</button>
        </div>
    </div>
</div>

<style>
.watchdog-service-card:hover {
    border-color: var(--border-strong) !important;
    background: var(--surface-hover) !important;
}
</style>

<script>
// Run manual watchdog check
async function executeWatchdogCheck() {
    const btn = document.getElementById('btnRunWatchdog');
    const restoreBtn = Almusa.busy(btn, 'Memeriksa...');

    try {
        const res = await Almusa.post('/api/watchdog/run', {});
        if (res.success) {
            Almusa.toast(res.summary || 'Pemeriksaan watchdog selesai!', 'success');
            setTimeout(() => location.reload(), 900);
        } else {
            if (typeof restoreBtn === 'function') restoreBtn();
            else if (Almusa.unbusy) Almusa.unbusy(btn);
            Almusa.toast(res.message || res.error || 'Gagal menjalankan watchdog.', 'error');
        }
    } catch (err) {
        if (typeof restoreBtn === 'function') restoreBtn();
        else if (Almusa.unbusy) Almusa.unbusy(btn);
        Almusa.toast('Terjadi kesalahan jaringan.', 'error');
    }
}

// Open raw incident log modal
async function openRawLogModal() {
    const pre = document.getElementById('rawLogContent');
    pre.textContent = 'Memuat log...';
    Almusa.openModal('rawLogModal');

    try {
        const res = await Almusa.get('/api/watchdog/raw-log');
        pre.textContent = res.content || 'Belum ada catatan insiden.';
    } catch (err) {
        pre.textContent = 'Gagal memuat berkas log.';
    }
}

// Copy raw log content to clipboard
function copyLogContent() {
    const pre = document.getElementById('rawLogContent');
    navigator.clipboard.writeText(pre.textContent).then(() => {
        Almusa.toast('Isi log berhasil disalin ke clipboard!', 'success');
    }).catch(() => {
        Almusa.toast('Gagal menyalin log.', 'error');
    });
}

// Clear incident history
async function clearIncidentHistory() {
    if (!confirm('Apakah Anda yakin ingin membersihkan riwayat insiden Watchdog?')) {
        return;
    }

    try {
        const res = await Almusa.post('/api/watchdog/clear-log', {});
        if (res.success) {
            Almusa.toast(res.message, 'success');
            setTimeout(() => location.reload(), 800);
        } else {
            Almusa.toast('Gagal membersihkan riwayat.', 'error');
        }
    } catch (err) {
        Almusa.toast('Terjadi kesalahan jaringan.', 'error');
    }
}
</script>
