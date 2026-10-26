<!-- Modal Konfigurasi Nginx Global -->
<div class="modal-backdrop" id="nginxGlobalModal">
    <div class="modal modal-lg" style="max-width: 820px; width: 95%;" role="dialog" aria-modal="true" aria-labelledby="nginxModalTitle">
        <div class="modal-header" style="align-items: center; border-bottom: 1px solid var(--border); padding-bottom: 14px;">
            <div style="display: flex; align-items: center; gap: 12px;">
                <div class="icon-tile" style="width: 40px; height: 40px;"><?= ui_icon('server') ?></div>
                <div>
                    <div style="display: flex; align-items: center; gap: 8px;">
                        <h2 class="modal-title" id="nginxModalTitle">Konfigurasi Nginx Global</h2>
                        <span id="nginxGlobalStatusBadge" class="badge badge-success">Memuat…</span>
                        <span id="nginxGlobalVersion" style="font-size: 11px; color: var(--text-3);"></span>
                    </div>
                    <p class="modal-subtitle" style="margin-top: 2px;">/etc/nginx/nginx.conf · Pengaturan Utama &amp; Optimasi Server</p>
                </div>
            </div>
            <div style="display: flex; align-items: center; gap: 8px;">
                <button type="button" class="btn btn-secondary btn-sm" onclick="nginxTestSyntax()" title="Jalankan uji sintaks (nginx -t)">
                    <?= ui_icon('terminal') ?> Uji Sintaks
                </button>
                <button type="button" class="btn btn-secondary btn-sm" onclick="nginxServiceAction('reload')" title="Reload Nginx tanpa downtime">
                    <?= ui_icon('refresh') ?> Reload
                </button>
                <button type="button" class="icon-btn" onclick="closeNginxModal()" aria-label="Tutup"><?= ui_icon('x') ?></button>
            </div>
        </div>

        <div class="modal-body" style="padding-top: 14px;">
            <!-- Tab Navigation -->
            <div style="display: flex; gap: 6px; border-bottom: 1px solid var(--border); margin-bottom: 16px; padding-bottom: 8px;">
                <button type="button" class="modal-tab-btn active" id="tabBtnNginxQuick" onclick="switchNginxTab('quick')">
                    <?= ui_icon('sliders') ?> Quick Tuning (GUI)
                </button>
                <button type="button" class="modal-tab-btn" id="tabBtnNginxRaw" onclick="switchNginxTab('raw')">
                    <?= ui_icon('file-code') ?> Editor Kode (nginx.conf)
                </button>
                <button type="button" class="modal-tab-btn" id="tabBtnNginxInfo" onclick="switchNginxTab('info')">
                    <?= ui_icon('info') ?> Info & Direktori
                </button>
            </div>

            <!-- Loading Spinner -->
            <div id="nginxModalLoading" style="text-align: center; padding: 40px 0;">
                <div class="text-muted">Memuat konfigurasi Nginx…</div>
            </div>

            <!-- TAB 1: Quick Tuning (GUI) -->
            <div id="nginxTabQuick" style="display: none;">
                <form id="formNginxQuick" onsubmit="saveNginxQuick(event)">
                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px; margin-bottom: 16px;">
                        <!-- Batas Upload -->
                        <div class="field" style="grid-column: span 2;">
                            <label class="label" for="ngClientMaxBody">Batas Ukuran Upload Klien (<code>client_max_body_size</code>)</label>
                            <div style="display: flex; gap: 8px; align-items: center; margin-bottom: 6px;">
                                <input type="text" id="ngClientMaxBody" class="input" style="max-width: 160px;" placeholder="50M" required>
                                <div style="display: flex; gap: 4px;">
                                    <button type="button" class="btn btn-ghost btn-sm" onclick="setNginxUploadPreset('10M')">10M</button>
                                    <button type="button" class="btn btn-ghost btn-sm" onclick="setNginxUploadPreset('50M')">50M</button>
                                    <button type="button" class="btn btn-ghost btn-sm" onclick="setNginxUploadPreset('100M')">100M</button>
                                    <button type="button" class="btn btn-ghost btn-sm" onclick="setNginxUploadPreset('250M')">250M</button>
                                    <button type="button" class="btn btn-ghost btn-sm" onclick="setNginxUploadPreset('500M')">500M</button>
                                    <button type="button" class="btn btn-ghost btn-sm" onclick="setNginxUploadPreset('1G')">1G</button>
                                </div>
                            </div>
                            <div class="help">Mencegah error <strong>413 Request Entity Too Large</strong> saat pengguna mengunggah file media atau database besar.</div>
                        </div>

                        <!-- Worker Processes -->
                        <div class="field">
                            <label class="label" for="ngWorkerProcesses">Worker Processes (<code>worker_processes</code>)</label>
                            <select id="ngWorkerProcesses" class="select">
                                <option value="auto">auto (Sesuai Core CPU)</option>
                                <option value="1">1 Worker</option>
                                <option value="2">2 Workers</option>
                                <option value="4">4 Workers</option>
                                <option value="8">8 Workers</option>
                            </select>
                            <div class="help">Rekomendasi: <code>auto</code> agar menyesuaikan jumlah vCPU server otomatis.</div>
                        </div>

                        <!-- Worker Connections -->
                        <div class="field">
                            <label class="label" for="ngWorkerConnections">Worker Connections (<code>worker_connections</code>)</label>
                            <select id="ngWorkerConnections" class="select">
                                <option value="768">768 Koneksi Simultan</option>
                                <option value="1024">1024 Koneksi Simultan</option>
                                <option value="2048">2048 Koneksi Simultan</option>
                                <option value="4096">4096 Koneksi Simultan</option>
                                <option value="8192">8192 Koneksi Simultan</option>
                            </select>
                            <div class="help">Jumlah koneksi simultan maksimum per worker process.</div>
                        </div>

                        <!-- Keepalive Timeout -->
                        <div class="field">
                            <label class="label" for="ngKeepalive">Keepalive Timeout (<code>keepalive_timeout</code>)</label>
                            <div style="display: flex; align-items: center; gap: 8px;">
                                <input type="number" id="ngKeepalive" class="input" style="max-width: 140px;" min="1" max="600" value="65">
                                <span class="text-muted" style="font-size: 13px;">detik</span>
                            </div>
                            <div class="help">Batas waktu koneksi keep-alive client tetap terbuka (default: 65 detik).</div>
                        </div>

                        <!-- FastCGI Read Timeout -->
                        <div class="field">
                            <label class="label" for="ngFastcgiTimeout">PHP FastCGI Timeout (<code>fastcgi_read_timeout</code>)</label>
                            <div style="display: flex; align-items: center; gap: 8px;">
                                <input type="number" id="ngFastcgiTimeout" class="input" style="max-width: 140px;" min="1" max="3600" value="300">
                                <span class="text-muted" style="font-size: 13px;">detik</span>
                            </div>
                            <div class="help">Mencegah error <strong>504 Gateway Timeout</strong> pada proses PHP durasi panjang.</div>
                        </div>
                    </div>

                    <!-- Switches -->
                    <div style="border-top: 1px solid var(--border); padding-top: 14px; margin-top: 6px; display: flex; flex-direction: column; gap: 10px;">
                        <label class="check" style="cursor: pointer;">
                            <input type="checkbox" id="ngServerTokens" value="1">
                            <span><strong>Sembunyikan Versi Nginx (<code>server_tokens off</code>)</strong> — Meningkatkan keamanan dari pemindaian bot peretas.</span>
                        </label>
                        <label class="check" style="cursor: pointer;">
                            <input type="checkbox" id="ngGzip" value="1" checked>
                            <span><strong>Aktifkan Kompresi Gzip Global (<code>gzip on</code>)</strong> — Memperkecil transfer data CSS/JS/JSON dan mempercepat loading.</span>
                        </label>
                    </div>

                    <div style="margin-top: 20px; display: flex; justify-content: flex-end; gap: 8px;">
                        <button type="submit" class="btn btn-primary" id="btnSaveNginxQuick">
                            <?= ui_icon('save') ?> Simpan Parameter &amp; Reload Nginx
                        </button>
                    </div>
                </form>
            </div>

            <!-- TAB 2: Raw Code Editor -->
            <div id="nginxTabRaw" style="display: none;">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px;">
                    <div style="font-size: 12px; color: var(--text-2); display: flex; align-items: center; gap: 6px;">
                        <?= ui_icon('file-code') ?> <code>/etc/nginx/nginx.conf</code>
                    </div>
                    <div style="display: flex; gap: 6px;">
                        <button type="button" class="btn btn-ghost btn-sm" onclick="nginxTestSyntax()">
                            <?= ui_icon('terminal') ?> Uji Sintaks
                        </button>
                    </div>
                </div>

                <div style="position: relative;">
                    <textarea id="nginxRawEditor" class="input" style="font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace; font-size: 13px; line-height: 1.5; min-height: 380px; width: 100%; tab-size: 4; white-space: pre; background: #0b0f19; color: #38bdf8; border: 1px solid #232832; border-radius: 8px; padding: 12px; resize: vertical;" spellcheck="false"></textarea>
                </div>

                <div class="callout-info" style="margin-top: 10px;">
                    <div style="font-size: 12px; display: flex; gap: 6px; align-items: center;">
                        <?= ui_icon('info') ?>
                        <span><strong>Safety Guard Aktif:</strong> Sebelum konfigurasi diterapkan, sistem otomatis menjalankan <code>nginx -t</code>. Jika ada kesalahan sintaks, sistem akan membatalkan perubahan secara otomatis sehingga Nginx tidak akan crash.</span>
                    </div>
                </div>

                <div style="margin-top: 16px; display: flex; justify-content: flex-end; gap: 8px;">
                    <button type="button" class="btn btn-primary" id="btnSaveNginxRaw" onclick="saveNginxRaw()">
                        <?= ui_icon('save') ?> Simpan &amp; Muat Ulang (Reload)
                    </button>
                </div>
            </div>

            <!-- TAB 3: Info & Direktori -->
            <div id="nginxTabInfo" style="display: none;">
                <div class="card card-flush" style="background: var(--surface-2); border-radius: 8px; padding: 16px; margin-bottom: 16px;">
                    <h3 style="font-size: 14px; font-weight: 600; margin-bottom: 12px;">Informasi Layanan Nginx</h3>
                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px; font-size: 13px;">
                        <div>
                            <div class="text-muted" style="font-size: 12px;">Versi Nginx</div>
                            <div style="font-weight: 500;" id="nginxInfoVersion">-</div>
                        </div>
                        <div>
                            <div class="text-muted" style="font-size: 12px;">Status Layanan</div>
                            <div style="font-weight: 500; color: var(--ok);" id="nginxInfoStatus">-</div>
                        </div>
                        <div>
                            <div class="text-muted" style="font-size: 12px;">File Konfigurasi Utama</div>
                            <div style="font-weight: 500;"><code>/etc/nginx/nginx.conf</code></div>
                        </div>
                        <div>
                            <div class="text-muted" style="font-size: 12px;">Direktori Virtual Host Aktif</div>
                            <div style="font-weight: 500;"><code>/etc/nginx/sites-enabled/</code></div>
                        </div>
                        <div>
                            <div class="text-muted" style="font-size: 12px;">Access Log Global</div>
                            <div style="font-weight: 500;"><code>/var/log/nginx/access.log</code></div>
                        </div>
                        <div>
                            <div class="text-muted" style="font-size: 12px;">Error Log Global</div>
                            <div style="font-weight: 500;"><code>/var/log/nginx/error.log</code></div>
                        </div>
                    </div>
                </div>

                <div style="display: flex; gap: 8px; justify-content: flex-end;">
                    <button type="button" class="btn btn-secondary btn-sm" onclick="nginxServiceAction('restart')">
                        <?= ui_icon('refresh') ?> Restart Nginx Service
                    </button>
                </div>
            </div>
        </div>

        <div class="modal-footer" style="border-top: 1px solid var(--border); padding-top: 12px;">
            <button type="button" class="btn btn-secondary" onclick="closeNginxModal()">Tutup</button>
        </div>
    </div>
</div>

<style>
.modal-tab-btn {
    background: transparent;
    border: none;
    padding: 6px 14px;
    font-size: 13px;
    font-weight: 500;
    color: var(--text-2);
    border-radius: var(--radius);
    cursor: pointer;
    transition: all 0.15s;
    display: inline-flex;
    align-items: center;
    gap: 6px;
}
.modal-tab-btn:hover {
    color: var(--text);
    background: var(--surface-2);
}
.modal-tab-btn.active {
    color: var(--brand);
    background: var(--brand-soft);
    font-weight: 600;
}
</style>

<script>
var nginxGlobalData = null;

async function openNginxModal() {
    Almusa.openModal('nginxGlobalModal');
    switchNginxTab('quick');

    document.getElementById('nginxModalLoading').style.display = 'block';
    document.getElementById('nginxTabQuick').style.display = 'none';
    document.getElementById('nginxTabRaw').style.display = 'none';
    document.getElementById('nginxTabInfo').style.display = 'none';

    try {
        var data = await Almusa.get('/api/nginx/config');
        nginxGlobalData = data;

        document.getElementById('nginxModalLoading').style.display = 'none';
        document.getElementById('nginxTabQuick').style.display = 'block';

        if (data.success) {
            // Header status
            var statusBadge = document.getElementById('nginxGlobalStatusBadge');
            var versionText = document.getElementById('nginxGlobalVersion');
            if (data.status && data.status.is_active) {
                statusBadge.className = 'badge badge-success';
                statusBadge.textContent = 'Aktif (Running)';
            } else {
                statusBadge.className = 'badge badge-danger';
                statusBadge.textContent = 'Nonaktif';
            }
            versionText.textContent = data.status ? data.status.version : '';

            // GUI fields
            var p = data.parsed || {};
            document.getElementById('ngClientMaxBody').value = p.client_max_body_size || '50M';
            document.getElementById('ngWorkerProcesses').value = p.worker_processes || 'auto';
            document.getElementById('ngWorkerConnections').value = p.worker_connections || '1024';
            document.getElementById('ngKeepalive').value = p.keepalive_timeout || '65';
            document.getElementById('ngFastcgiTimeout').value = p.fastcgi_read_timeout || '300';
            document.getElementById('ngServerTokens').checked = (p.server_tokens === 'off');
            document.getElementById('ngGzip').checked = (p.gzip !== 'off');

            // Raw editor
            document.getElementById('nginxRawEditor').value = data.raw || '';

            // Info tab
            document.getElementById('nginxInfoVersion').textContent = data.status ? data.status.version : '-';
            document.getElementById('nginxInfoStatus').textContent = (data.status && data.status.is_active) ? 'Aktif (Berjalan)' : 'Nonaktif';
        } else {
            Almusa.toast(data.error || 'Gagal memuat konfigurasi Nginx.', 'error');
        }
    } catch (e) {
        document.getElementById('nginxModalLoading').style.display = 'none';
        Almusa.toast('Gagal menghubungi server.', 'error');
    }
}

function closeNginxModal() {
    Almusa.closeModal('nginxGlobalModal');
}

function switchNginxTab(tabName) {
    document.getElementById('tabBtnNginxQuick').classList.toggle('active', tabName === 'quick');
    document.getElementById('tabBtnNginxRaw').classList.toggle('active', tabName === 'raw');
    document.getElementById('tabBtnNginxInfo').classList.toggle('active', tabName === 'info');

    document.getElementById('nginxTabQuick').style.display = tabName === 'quick' ? 'block' : 'none';
    document.getElementById('nginxTabRaw').style.display = tabName === 'raw' ? 'block' : 'none';
    document.getElementById('nginxTabInfo').style.display = tabName === 'info' ? 'block' : 'none';
}

function setNginxUploadPreset(val) {
    document.getElementById('ngClientMaxBody').value = val;
}

async function saveNginxQuick(e) {
    e.preventDefault();
    var btn = document.getElementById('btnSaveNginxQuick');
    var restore = Almusa.busy(btn, 'Menyimpan…');

    try {
        var data = await Almusa.post('/api/nginx/save', {
            mode: 'quick',
            client_max_body_size: document.getElementById('ngClientMaxBody').value.trim(),
            worker_processes: document.getElementById('ngWorkerProcesses').value,
            worker_connections: document.getElementById('ngWorkerConnections').value,
            keepalive_timeout: document.getElementById('ngKeepalive').value,
            fastcgi_read_timeout: document.getElementById('ngFastcgiTimeout').value,
            server_tokens: document.getElementById('ngServerTokens').checked ? 'off' : 'on',
            gzip: document.getElementById('ngGzip').checked ? 'on' : 'off'
        });

        if (data.success) {
            closeNginxModal();
            openTaskTerminal();
            Almusa.toast(data.message, 'info');
        } else {
            Almusa.toast(data.error || 'Gagal menyimpan konfigurasi.', 'error');
            restore();
        }
    } catch (err) {
        Almusa.toast('Terjadi kesalahan jaringan.', 'error');
        restore();
    }
}

async function saveNginxRaw() {
    var content = document.getElementById('nginxRawEditor').value.trim();
    if (!content) {
        Almusa.toast('Isi konfigurasi tidak boleh kosong.', 'error');
        return;
    }

    var btn = document.getElementById('btnSaveNginxRaw');
    var restore = Almusa.busy(btn, 'Menyimpan…');

    try {
        var data = await Almusa.post('/api/nginx/save', {
            mode: 'raw',
            content: content
        });

        if (data.success) {
            closeNginxModal();
            openTaskTerminal();
            Almusa.toast(data.message, 'info');
        } else {
            Almusa.toast(data.error || 'Gagal menyimpan konfigurasi.', 'error');
            restore();
        }
    } catch (err) {
        Almusa.toast('Terjadi kesalahan jaringan.', 'error');
        restore();
    }
}

async function nginxTestSyntax() {
    var content = (document.getElementById('nginxTabRaw').style.display !== 'none')
        ? document.getElementById('nginxRawEditor').value.trim()
        : '';

    try {
        var data = await Almusa.post('/api/nginx/test', { content: content });
        if (data.success) {
            openTaskTerminal();
            Almusa.toast(data.message, 'info');
        } else {
            Almusa.toast(data.error || 'Pengujian sintaks gagal dimulai.', 'error');
        }
    } catch (err) {
        Almusa.toast('Terjadi kesalahan jaringan.', 'error');
    }
}

async function nginxServiceAction(action) {
    try {
        var data = await Almusa.post('/api/nginx/service', { command: action });
        if (data.success) {
            openTaskTerminal();
            Almusa.toast(data.message, 'info');
        } else {
            Almusa.toast(data.error || 'Gagal mengirim perintah layanan.', 'error');
        }
    } catch (err) {
        Almusa.toast('Terjadi kesalahan jaringan.', 'error');
    }
}
</script>
