<!-- Modal Pembaruan Almusa Panel (Self-Updater) -->
<div class="modal-backdrop" id="panelUpdateModal">
    <div class="modal" style="max-width: 600px; width: 95%;" role="dialog" aria-modal="true" aria-labelledby="updateModalTitle">
        <div class="modal-header" style="align-items: center; border-bottom: 1px solid var(--border); padding-bottom: 14px;">
            <div style="display: flex; align-items: center; gap: 12px;">
                <div class="icon-tile" style="width: 40px; height: 40px; background: var(--accent-soft); color: var(--accent-text);">
                    <?= ui_icon('arrow-up') ?>
                </div>
                <div>
                    <div style="display: flex; align-items: center; gap: 8px;">
                        <h2 class="modal-title" id="updateModalTitle">Pembaruan Almusa Panel</h2>
                        <span id="updateModalBadge" class="badge badge-neutral">Memeriksa…</span>
                    </div>
                    <p class="modal-subtitle" style="margin-top: 2px;">Sistem pembaruan otomatis &amp; migrasi database non-destruktif</p>
                </div>
            </div>
            <button type="button" class="icon-btn" onclick="closeUpdateModal()" aria-label="Tutup"><?= ui_icon('x') ?></button>
        </div>

        <div class="modal-body" style="padding-top: 16px;">
            <!-- Comparison Grid -->
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px; margin-bottom: 16px;">
                <div class="card" style="padding: 12px; border-radius: var(--radius-sm); background: var(--surface-hover);">
                    <div class="text-muted" style="font-size: 11px; text-transform: uppercase; letter-spacing: 0.05em; margin-bottom: 4px;">Versi Terpasang</div>
                    <div style="display: flex; align-items: baseline; gap: 6px;">
                        <span style="font-size: 18px; font-weight: 700; color: var(--text);" id="modalCurrentVersion">v1.0.0</span>
                        <span style="font-size: 11px; color: var(--text-3);" id="modalCurrentBuild">(20261029)</span>
                    </div>
                </div>
                <div class="card" style="padding: 12px; border-radius: var(--radius-sm); background: var(--surface-hover);">
                    <div class="text-muted" style="font-size: 11px; text-transform: uppercase; letter-spacing: 0.05em; margin-bottom: 4px;">Versi Terbaru di GitHub</div>
                    <div style="display: flex; align-items: baseline; gap: 6px;">
                        <span style="font-size: 18px; font-weight: 700; color: var(--accent-text);" id="modalLatestVersion">v1.0.0</span>
                        <span style="font-size: 11px; color: var(--text-3);" id="modalLatestBuild">(20261029)</span>
                    </div>
                </div>
            </div>

            <!-- Update Status Alert -->
            <div id="updateStatusAlert" style="margin-bottom: 16px;"></div>

            <!-- Changelog Section -->
            <div class="card card-flush" style="margin-bottom: 16px; border: 1px solid var(--border); border-radius: var(--radius-sm); overflow: hidden;">
                <div style="padding: 10px 14px; background: var(--surface-hover); border-bottom: 1px solid var(--border); display: flex; justify-content: space-between; align-items: center;">
                    <span style="font-size: 12px; font-weight: 600; color: var(--text-2);">
                        <?= ui_icon('file-text', 'icon-sm') ?> Catatan Rilis &amp; Changelog
                    </span>
                    <span style="font-size: 11px; color: var(--text-3);" id="modalReleaseDate">2026-10-29</span>
                </div>
                <div style="padding: 12px 14px; max-height: 180px; overflow-y: auto;">
                    <ul id="modalChangelogList" style="margin: 0; padding-left: 20px; font-size: 13px; color: var(--text-2); line-height: 1.6;">
                        <li>Memuat catatan rilis…</li>
                    </ul>
                </div>
            </div>

            <!-- Safety Guarantee Notice -->
            <div style="padding: 10px 12px; border-radius: var(--radius-sm); background: rgba(59, 130, 246, 0.08); border: 1px solid rgba(59, 130, 246, 0.2); font-size: 11.5px; color: var(--text-2); display: flex; gap: 8px; align-items: flex-start;">
                <?= ui_icon('shield', 'icon-sm') ?>
                <div>
                    <strong>Jaminan Keamanan Database:</strong> Database SQLite (<code>data/panel.db</code>) dan file kredensial Anda 100% aman dan tidak akan pernah tertimpa. Cadangan otomatis dibuat sebelum update dieksekusi.
                </div>
            </div>
        </div>

        <div class="modal-footer" style="display: flex; justify-content: space-between; align-items: center; border-top: 1px solid var(--border); padding-top: 14px; margin-top: 14px;">
            <button type="button" class="btn btn-secondary btn-sm" id="btnForceCheckUpdate" onclick="refreshPanelUpdateCheck(true)">
                <?= ui_icon('refresh') ?> Periksa Ulang
            </button>
            <div style="display: flex; gap: 8px;">
                <button type="button" class="btn btn-secondary btn-sm" onclick="closeUpdateModal()">Tutup</button>
                <button type="button" class="btn btn-primary btn-sm" id="btnApplyUpdate" onclick="applyPanelUpdate()" disabled>
                    <?= ui_icon('arrow-up') ?> 1-Click Update Sekarang
                </button>
            </div>
        </div>
    </div>
</div>

<script>
let currentUpdateInfo = null;

window.openUpdateModal = function () {
    const modal = document.getElementById('panelUpdateModal');
    if (!modal) return;
    modal.classList.add('show');
    loadPanelUpdateInfo(false);
};

window.closeUpdateModal = function () {
    const modal = document.getElementById('panelUpdateModal');
    if (!modal) return;
    modal.classList.remove('show');
};

async function loadPanelUpdateInfo(force = false) {
    const badge = document.getElementById('updateModalBadge');
    const alertBox = document.getElementById('updateStatusAlert');
    const changelogUl = document.getElementById('modalChangelogList');
    const applyBtn = document.getElementById('btnApplyUpdate');
    const btnCheck = document.getElementById('btnForceCheckUpdate');

    let restoreCheckBtn = null;
    if (force && btnCheck && window.Almusa && Almusa.busy) {
        restoreCheckBtn = Almusa.busy(btnCheck, 'Mengecek…');
    }

    try {
        const res = await fetch('/api/system/update/check' + (force ? '?force=1' : ''), {
            headers: { 'Accept': 'application/json' }
        });
        const json = await res.json();

        if (restoreCheckBtn) restoreCheckBtn();

        if (!json.success || !json.data) {
            badge.className = 'badge badge-neutral';
            badge.textContent = 'Offline / Gagal';
            alertBox.innerHTML = '<div class="alert alert-warning mb-0" style="font-size:12px;"><?= ui_icon("alert-circle") ?> Tidak dapat memeriksa pembaruan remote saat ini.</div>';
            return;
        }

        const d = json.data;
        currentUpdateInfo = d;

        document.getElementById('modalCurrentVersion').textContent = 'v' + d.current_version;
        document.getElementById('modalCurrentBuild').textContent = '(' + d.current_build + ')';
        document.getElementById('modalLatestVersion').textContent = 'v' + d.latest_version;
        document.getElementById('modalLatestBuild').textContent = '(' + d.latest_build + ')';
        document.getElementById('modalReleaseDate').textContent = d.release_date || '';

        // Render Changelog
        if (Array.isArray(d.changelog) && d.changelog.length > 0) {
            changelogUl.innerHTML = d.changelog.map(item => '<li>' + Almusa.escapeHtml(item) + '</li>').join('');
        } else {
            changelogUl.innerHTML = '<li>Tidak ada catatan rilis khusus untuk versi ini.</li>';
        }

        // Render Status & Actions
        if (d.update_available) {
            badge.className = 'badge badge-warning';
            badge.textContent = 'Pembaruan Tersedia';
            alertBox.innerHTML = `
                <div class="alert alert-info mb-0" style="font-size:12.5px;">
                    <?= ui_icon("arrow-up") ?>
                    <div>Versi baru <strong>v${Almusa.escapeHtml(d.latest_version)}</strong> telah tersedia. Klik tombol di bawah untuk memperbarui secara otomatis.</div>
                </div>
            `;
            applyBtn.disabled = false;
            applyBtn.className = 'btn btn-primary btn-sm';
            applyBtn.innerHTML = '<?= ui_icon("arrow-up") ?> 1-Click Update Sekarang (v' + Almusa.escapeHtml(d.latest_version) + ')';
        } else {
            badge.className = 'badge badge-success';
            badge.textContent = 'Versi Terbaru';
            alertBox.innerHTML = `
                <div class="alert alert-success mb-0" style="font-size:12.5px;">
                    <?= ui_icon("check-circle") ?>
                    <div>Almusa Panel sudah menggunakan versi paling mutakhir (<strong>v${Almusa.escapeHtml(d.current_version)}</strong>).</div>
                </div>
            `;
            applyBtn.disabled = true;
            applyBtn.className = 'btn btn-secondary btn-sm';
            applyBtn.innerHTML = '<?= ui_icon("check-circle") ?> Versi Sudah Paling Baru';
        }
    } catch (err) {
        if (restoreCheckBtn) restoreCheckBtn();
        badge.className = 'badge badge-neutral';
        badge.textContent = 'Error';
        alertBox.innerHTML = '<div class="alert alert-danger mb-0" style="font-size:12px;"><?= ui_icon("alert-circle") ?> Gagal memuat status pembaruan.</div>';
    }
}

window.refreshPanelUpdateCheck = function (force = true) {
    loadPanelUpdateInfo(force);
};

window.applyPanelUpdate = async function () {
    if (!currentUpdateInfo || !currentUpdateInfo.update_available) {
        return;
    }

    const ok = await Almusa.confirm({
        title: 'Mulai Pembaruan Panel?',
        message: 'Panel akan dicadangkan (backup SQLite otomatis), file core diperbarui ke versi v' + currentUpdateInfo.latest_version + ', dan layanan dimuat ulang secara mulus.',
        confirmText: 'Mulai Update Sekarang',
        danger: false
    });

    if (!ok) return;

    const btn = document.getElementById('btnApplyUpdate');
    const restoreBtn = Almusa.busy(btn, 'Menjadwalkan…');

    try {
        const res = await Almusa.post('/api/system/update/apply', {});
        if (restoreBtn) restoreBtn();

        if (res.success) {
            closeUpdateModal();
            Almusa.toast('Pembaruan berhasil dijadwalkan! Membuka terminal eksekusi…', 'success');
            if (window.openTaskTerminal) {
                setTimeout(openTaskTerminal, 300);
            }
        } else {
            Almusa.toast(res.error || 'Gagal memulai pembaruan.', 'error');
        }
    } catch (err) {
        if (restoreBtn) restoreBtn();
        Almusa.toast('Terjadi kesalahan koneksi saat mengirim permintaan update.', 'error');
    }
};
</script>
