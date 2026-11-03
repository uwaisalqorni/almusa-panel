<?php
/**
 * Almusa Control Panel - Enterprise Web File Manager & Code Editor View
 * Inspired by SLEMP / aaPanel architecture with multi-file management,
 * clipboard (Copy/Cut/Paste), batch actions, recursive chmod, zip compressor & extractor.
 */
?>

<header class="page-header">
    <div>
        <h1 class="page-title">File Manager</h1>
        <p class="page-desc">Kelola file, struktur direktori, izin hak akses (chmod), ekstrak & kompres zip, serta kelola konfigurasi website.</p>
    </div>
    <div class="page-actions">
        <div class="fm-site-picker">
            <label for="siteSelector" class="sr-only">Pilih Website</label>
            <select id="siteSelector" class="select select-sm" onchange="switchSite(this.value)">
                <option value="0" <?= ((int)($site['id'] ?? 0) === 0 || !empty($site['is_root_storage'])) ? 'selected' : '' ?>>
                    📁 Folder Utama (/www/wwwroot)
                </option>
                <?php foreach ($allSites as $s): ?>
                    <?php if ((int)$s['id'] > 0 && empty($s['is_root_storage'])): ?>
                        <option value="<?= (int)$s['id'] ?>" <?= (int)$s['id'] === (int)$site['id'] ? 'selected' : '' ?>>
                            🌐 <?= e($s['domain']) ?>
                        </option>
                    <?php endif; ?>
                <?php endforeach; ?>
            </select>
        </div>
        <a href="/sites" class="btn btn-secondary btn-sm" title="Kelola Virtual Hosts & Websites">
            <?= ui_icon('globe') ?> Daftar website
        </a>
    </div>
</header>

<?php if ((int)($site['id'] ?? 0) === 0 || !empty($site['is_root_storage'])): ?>
    <div class="alert alert-info mb" style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;">
        <div style="display:flex;align-items:center;gap:10px;">
            <?= ui_icon('info') ?>
            <span>Anda sedang berada di direktori penyimpanan root: <code><?= e($baseDir) ?></code>. Anda dapat mengunggah berkas zip atau membuat folder proyek di sini, lalu menghubungkannya ke domain.</span>
        </div>
        <a href="/sites" class="btn btn-primary btn-sm"><?= ui_icon('plus') ?> Buat website baru</a>
    </div>
<?php endif; ?>

<section class="card card-flush">
    <!-- Top Breadcrumbs & Stats Bar (Inspired by SLEMP / aaPanel) -->
    <div class="fm-crumbs-bar" id="fmCrumbsBar" style="display:flex;justify-content:space-between;align-items:center;padding:10px 16px;background:var(--surface);border-bottom:1px solid var(--border);flex-wrap:wrap;gap:8px;">
        <div style="display:flex;align-items:center;gap:6px;overflow-x:auto;max-width:calc(100% - 280px);">
            <button type="button" class="btn btn-ghost btn-icon btn-sm" id="fmParentBtn" style="display:none;width:26px;height:26px;margin-right:2px;" onclick="goToParent()" title="Naik satu level folder" aria-label="Naik satu folder">
                <?= ui_icon('arrow-up') ?>
            </button>
            <span class="fm-crumb-link" onclick="navigateToPath('')" title="Root direktori">
                <?= ui_icon('folder') ?> <strong><?= e($site['domain']) ?></strong>
            </span>
            <span id="fmCrumbSegments"></span>
        </div>
        <div style="display:flex;align-items:center;gap:12px;font-size:12px;" class="text-muted">
            <span id="fmStatsBadge">Memuat data folder…</span>
            <button type="button" class="btn btn-ghost btn-icon btn-sm" onclick="refreshDirectory()" title="Muat ulang direktori" aria-label="Refresh">
                <?= ui_icon('refresh') ?>
            </button>
        </div>
    </div>

    <!-- Main Actions Toolbar -->
    <div class="fm-bar">
        <div class="fm-actions">
            <button type="button" class="btn btn-primary btn-sm" onclick="openUploadModal()">
                <?= ui_icon('upload') ?> Unggah
            </button>
            <button type="button" class="btn btn-secondary btn-sm" onclick="openNewItemModal('file')">
                <?= ui_icon('file-plus') ?> File baru
            </button>
            <button type="button" class="btn btn-secondary btn-sm" onclick="openNewItemModal('dir')">
                <?= ui_icon('folder-plus') ?> Folder baru
            </button>
            <?php if (!empty($site['id']) && (int)$site['id'] > 0 && empty($site['is_root_storage'])): ?>
                <button type="button" class="btn btn-secondary btn-sm" onclick="openNginxEditor()">
                    <?= ui_icon('sliders') ?> Nginx Conf
                </button>
            <?php endif; ?>
            <span id="fmDiskPill" class="badge badge-neutral" style="font-size:11px;font-family:monospace;padding:5px 8px;">
                <?= ui_icon('disk') ?> Disk: memuat…
            </span>
        </div>

        <div class="fm-search-wrap">
            <?= ui_icon('search') ?>
            <input type="text" id="fmSearchInput" class="input" placeholder="Cari file di folder ini…" oninput="filterFiles(this.value)">
        </div>
    </div>

    <!-- Clipboard Bar (Shown when items are Cut or Copied) -->
    <div id="fmClipboardBar" style="display:none;padding:8px 16px;background:rgba(59, 130, 246, 0.1);border-bottom:1px solid rgba(59, 130, 246, 0.25);font-size:12px;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:8px;">
        <div style="display:flex;align-items:center;gap:8px;">
            <?= ui_icon('clipboard') ?>
            <span id="fmClipboardLabel" class="text-2">Clipboard aktif</span>
        </div>
        <div style="display:flex;gap:8px;">
            <button type="button" class="btn btn-primary btn-sm" onclick="pasteClipboard()">
                <?= ui_icon('save') ?> Tempel di sini (Paste)
            </button>
            <button type="button" class="btn btn-secondary btn-sm" onclick="clearClipboard()">
                Batal
            </button>
        </div>
    </div>

    <!-- Batch Selection Bar (Shown when 1 or more items are checked) -->
    <div id="fmBatchBar" style="display:none;padding:8px 16px;background:var(--surface-hover);border-bottom:1px solid var(--border);font-size:12px;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:8px;">
        <div style="display:flex;align-items:center;gap:10px;">
            <span>Terpilih: <strong id="fmBatchCount">0</strong> item</span>
            <button type="button" class="btn btn-ghost btn-sm" onclick="deselectAll()">Batal pilih</button>
        </div>
        <div style="display:flex;gap:6px;flex-wrap:wrap;">
            <button type="button" class="btn btn-secondary btn-sm" onclick="batchAction('copy')" title="Salin item terpilih">
                <?= ui_icon('copy') ?> Salin
            </button>
            <button type="button" class="btn btn-secondary btn-sm" onclick="batchAction('cut')" title="Potong item terpilih">
                <?= ui_icon('scissors') ?> Potong
            </button>
            <button type="button" class="btn btn-secondary btn-sm" onclick="batchAction('compress')" title="Kompres item terpilih ke .zip">
                <?= ui_icon('archive') ?> Zip
            </button>
            <button type="button" class="btn btn-secondary btn-sm" onclick="batchAction('chmod')" title="Ubah izin hak akses item terpilih">
                <?= ui_icon('key') ?> Chmod
            </button>
            <button type="button" class="btn btn-secondary btn-sm" onclick="batchAction('delete')" title="Hapus item terpilih" style="color:var(--err);">
                <?= ui_icon('trash') ?> Hapus
            </button>
        </div>
    </div>

    <!-- File Table -->
    <div class="card-body" style="padding:0;">
        <div class="table-wrap">
            <table class="table" id="fmTable">
                <thead>
                    <tr>
                        <th style="width:36px;text-align:center;">
                            <input type="checkbox" id="fmSelectAll" onchange="toggleSelectAll(this.checked)" title="Pilih Semua" aria-label="Pilih Semua">
                        </th>
                        <th>Nama</th>
                        <th style="width:110px;">Ukuran</th>
                        <th style="width:160px;">Dimodifikasi</th>
                        <th style="width:80px;">Izin</th>
                        <th style="width:90px;">Pemilik</th>
                        <th class="th-right" style="width:360px;">Aksi</th>
                    </tr>
                </thead>
                <tbody id="fmTableBody">
                    <tr>
                        <td colspan="7" style="text-align:center;padding:40px;color:var(--text-3);">
                            <div style="display:inline-flex;align-items:center;gap:8px;">
                                <svg class="icon" style="animation:spin 1s linear infinite;" aria-hidden="true"><use href="#i-refresh"/></svg>
                                Memuat direktori…
                            </div>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</section>

<!-- =========================================================================
     MODAL: CODE & CONFIG EDITOR
     ========================================================================= -->
<div class="modal-backdrop" id="codeEditorModal">
    <div class="modal modal-editor" role="dialog" aria-modal="true" aria-labelledby="editorFileTitle">
        <div class="modal-header">
            <div>
                <h2 class="modal-title" id="editorFileTitle">Editor Berkas</h2>
                <p class="modal-subtitle mono" id="editorFilePath" style="font-size:12px;"></p>
            </div>
            <div style="display:flex;align-items:center;gap:8px;">
                <span class="editor-syntax-badge" id="editorSyntaxBadge">TXT</span>
                <button type="button" class="icon-btn" onclick="closeCodeEditor()" aria-label="Tutup"><?= ui_icon('x') ?></button>
            </div>
        </div>

        <div class="editor-container">
            <div class="editor-toolbar">
                <div class="editor-meta">
                    <span id="editorStats">Baris: 1 | Karakter: 0</span>
                    <span style="color:var(--text-3);">•</span>
                    <span style="color:var(--text-3);">Shortcut: <kbd style="background:var(--surface-hover);padding:1px 5px;border-radius:4px;border:1px solid var(--border);">Ctrl + S</kbd> untuk menyimpan</span>
                </div>
                <div style="display:flex;gap:8px;">
                    <button type="button" class="btn btn-secondary btn-sm" onclick="copyEditorContent()">
                        <?= ui_icon('copy') ?> Salin isi
                    </button>
                    <button type="button" class="btn btn-primary btn-sm" id="editorSaveBtn" onclick="saveEditorContent()">
                        <?= ui_icon('save') ?> Simpan perubahan
                    </button>
                </div>
            </div>

            <div class="editor-syntax-err" id="editorSyntaxError" style="display:none;"></div>

            <div class="editor-body-wrap">
                <div class="editor-gutter" id="editorGutter">1</div>
                <textarea id="editorTextarea" class="editor-textarea" spellcheck="false" wrap="off"></textarea>
            </div>

            <div class="editor-footer-status">
                <span id="editorStatusText">Siap</span>
                <span class="mono" style="font-size:11px;">UTF-8</span>
            </div>
        </div>
    </div>
</div>

<!-- =========================================================================
     MODAL: BUAT FILE / FOLDER BARU
     ========================================================================= -->
<div class="modal-backdrop" id="newItemModal">
    <div class="modal modal-sm" role="dialog" aria-modal="true" aria-labelledby="newItemTitle">
        <div class="modal-header">
            <div>
                <h2 class="modal-title" id="newItemTitle">Buat Baru</h2>
                <p class="modal-subtitle" id="newItemSubtitle">Tambahkan file atau folder ke folder aktif.</p>
            </div>
            <button type="button" class="icon-btn" onclick="closeNewItemModal()" aria-label="Tutup"><?= ui_icon('x') ?></button>
        </div>
        <form id="newItemForm" onsubmit="submitNewItem(event)">
            <input type="hidden" id="newItemType" value="file">
            <div class="modal-body">
                <div class="field">
                    <label class="label" for="newItemName" id="newItemLabel">Nama File</label>
                    <input type="text" id="newItemName" class="input" placeholder="contoh: index.php atau .env" required autofocus>
                    <div class="help" id="newItemHelp">Nama file baru tanpa karakter terlarang.</div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeNewItemModal()">Batal</button>
                <button type="submit" class="btn btn-primary" id="newItemSubmitBtn">Buat</button>
            </div>
        </form>
    </div>
</div>

<!-- =========================================================================
     MODAL: GANTI NAMA (RENAME)
     ========================================================================= -->
<div class="modal-backdrop" id="renameModal">
    <div class="modal modal-sm" role="dialog" aria-modal="true" aria-labelledby="renameTitle">
        <div class="modal-header">
            <div>
                <h2 class="modal-title" id="renameTitle">Ganti Nama</h2>
                <p class="modal-subtitle">Ubah nama berkas atau direktori.</p>
            </div>
            <button type="button" class="icon-btn" onclick="closeRenameModal()" aria-label="Tutup"><?= ui_icon('x') ?></button>
        </div>
        <form id="renameForm" onsubmit="submitRename(event)">
            <input type="hidden" id="renameOldPath" value="">
            <div class="modal-body">
                <div class="field">
                    <label class="label" for="renameInputName">Nama Baru</label>
                    <input type="text" id="renameInputName" class="input" required autofocus>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeRenameModal()">Batal</button>
                <button type="submit" class="btn btn-primary" id="renameSubmitBtn">Simpan</button>
            </div>
        </form>
    </div>
</div>

<!-- =========================================================================
     MODAL: PERMISSIONS (CHMOD)
     ========================================================================= -->
<div class="modal-backdrop" id="chmodModal">
    <div class="modal modal-md" role="dialog" aria-modal="true" aria-labelledby="chmodTitle">
        <div class="modal-header">
            <div>
                <h2 class="modal-title" id="chmodTitle">Izin Hak Akses (Chmod)</h2>
                <p class="modal-subtitle mono" id="chmodItemPath"></p>
            </div>
            <button type="button" class="icon-btn" onclick="closeChmodModal()" aria-label="Tutup"><?= ui_icon('x') ?></button>
        </div>
        <form id="chmodForm" onsubmit="submitChmod(event)">
            <input type="hidden" id="chmodTargetRel" value="">
            <div class="modal-body">
                <div style="display:flex;align-items:center;justify-content:center;gap:12px;margin-bottom:16px;">
                    <label class="label" style="margin:0;" for="chmodOctalInput">Nilai Oktal:</label>
                    <input type="text" id="chmodOctalInput" class="input mono" style="width:90px;font-size:18px;text-align:center;font-weight:bold;" maxlength="3" oninput="syncChmodCheckboxes(this.value)">
                </div>

                <div class="table-wrap" style="margin-bottom:16px;">
                    <table class="table" style="text-align:center;font-size:13px;">
                        <thead>
                            <tr>
                                <th style="text-align:left;">Kelompok</th>
                                <th style="text-align:center;">Baca (4)</th>
                                <th style="text-align:center;">Tulis (2)</th>
                                <th style="text-align:center;">Eksekusi (1)</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td style="text-align:left;font-weight:600;">Pemilik (Owner)</td>
                                <td><input type="checkbox" id="cm_u_r" onchange="calcChmodOctal()"></td>
                                <td><input type="checkbox" id="cm_u_w" onchange="calcChmodOctal()"></td>
                                <td><input type="checkbox" id="cm_u_x" onchange="calcChmodOctal()"></td>
                            </tr>
                            <tr>
                                <td style="text-align:left;font-weight:600;">Grup (Group)</td>
                                <td><input type="checkbox" id="cm_g_r" onchange="calcChmodOctal()"></td>
                                <td><input type="checkbox" id="cm_g_w" onchange="calcChmodOctal()"></td>
                                <td><input type="checkbox" id="cm_g_x" onchange="calcChmodOctal()"></td>
                            </tr>
                            <tr>
                                <td style="text-align:left;font-weight:600;">Publik (Public)</td>
                                <td><input type="checkbox" id="cm_o_r" onchange="calcChmodOctal()"></td>
                                <td><input type="checkbox" id="cm_o_w" onchange="calcChmodOctal()"></td>
                                <td><input type="checkbox" id="cm_o_x" onchange="calcChmodOctal()"></td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <div id="chmodRecursiveWrap" class="field" style="margin-bottom:0;">
                    <label style="display:flex;align-items:center;gap:8px;cursor:pointer;font-size:13px;">
                        <input type="checkbox" id="chmodRecursive">
                        <span>Terapkan ke seluruh sub-folder dan berkas di dalamnya (Rekursif)</span>
                    </label>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeChmodModal()">Batal</button>
                <button type="submit" class="btn btn-primary" id="chmodSubmitBtn">Terapkan</button>
            </div>
        </form>
    </div>
</div>

<!-- =========================================================================
     MODAL: KOMPRES KE ZIP
     ========================================================================= -->
<div class="modal-backdrop" id="compressModal">
    <div class="modal modal-sm" role="dialog" aria-modal="true" aria-labelledby="compressTitle">
        <div class="modal-header">
            <div>
                <h2 class="modal-title" id="compressTitle">Kompres Arsip (.zip)</h2>
                <p class="modal-subtitle">Kompres berkas atau folder ke format .zip.</p>
            </div>
            <button type="button" class="icon-btn" onclick="closeCompressModal()" aria-label="Tutup"><?= ui_icon('x') ?></button>
        </div>
        <form id="compressForm" onsubmit="submitCompress(event)">
            <input type="hidden" id="compressSourceItem" value="">
            <div class="modal-body">
                <div class="field">
                    <label class="label">Item yang Dikompres</label>
                    <code id="compressSourceName" style="display:block;padding:8px 12px;font-size:13px;"></code>
                </div>
                <div class="field">
                    <label class="label" for="compressZipName">Nama File .zip</label>
                    <input type="text" id="compressZipName" class="input mono" required>
                    <div class="help">Arsip akan dibuat di dalam direktori aktif saat ini.</div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeCompressModal()">Batal</button>
                <button type="submit" class="btn btn-primary" id="compressSubmitBtn">Mulai Kompres</button>
            </div>
        </form>
    </div>
</div>

<!-- =========================================================================
     MODAL: UNGGAH FILE (CHUNKED UPLOAD 1.5 MB)
     ========================================================================= -->
<div class="modal-backdrop" id="uploadModal">
    <div class="modal modal-md" role="dialog" aria-modal="true" aria-labelledby="uploadTitle">
        <div class="modal-header">
            <div>
                <h2 class="modal-title" id="uploadTitle">Unggah File</h2>
                <p class="modal-subtitle">Unggah berkas atau arsip .zip (didukung auto-chunking untuk file besar > 1 GB).</p>
            </div>
            <button type="button" class="icon-btn" onclick="closeUploadModal()" aria-label="Tutup"><?= ui_icon('x') ?></button>
        </div>
        <form id="uploadForm" onsubmit="submitUpload(event)">
            <div class="modal-body">
                <div class="dropzone" id="uploadDropzone" onclick="document.getElementById('uploadFileInput').click()">
                    <div class="dropzone-icon"><?= ui_icon('upload', 'icon-xl') ?></div>
                    <div class="dropzone-title">Klik atau seret file ke sini untuk mengunggah</div>
                    <div class="dropzone-hint">Mendukung berkas tunggal, multi-berkas, atau arsip .zip</div>
                    <input type="file" id="uploadFileInput" name="files[]" multiple style="display:none;" onchange="handleFileSelect(this)">
                </div>

                <div id="uploadFileList" style="margin-top:16px;max-height:180px;overflow-y:auto;display:none;"></div>
                <div id="uploadProgressWrap" style="margin-top:14px;display:none;">
                    <div style="display:flex;justify-content:space-between;font-size:12px;margin-bottom:6px;">
                        <span id="uploadProgressLabel" class="text-2">Mengunggah…</span>
                        <span id="uploadProgressPct" class="mono text-2">0%</span>
                    </div>
                    <div style="width:100%;height:8px;background:var(--surface-hover);border-radius:999px;overflow:hidden;">
                        <div id="uploadProgressBar" style="width:0%;height:100%;background:var(--primary, #3b82f6);transition:width 0.15s ease;"></div>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeUploadModal()">Batal</button>
                <button type="submit" class="btn btn-primary" id="uploadSubmitBtn" disabled>Mulai unggah</button>
            </div>
        </form>
    </div>
</div>

<!-- =========================================================================
     MODAL: EKSTRAK ARSIP ZIP / TAR (MULTI-ENGINE)
     ========================================================================= -->
<div class="modal-backdrop" id="extractModal">
    <div class="modal modal-sm" role="dialog" aria-modal="true" aria-labelledby="extractTitle">
        <div class="modal-header">
            <div>
                <h2 class="modal-title" id="extractTitle">Ekstrak Arsip</h2>
                <p class="modal-subtitle">Ekstrak seluruh berkas dan atur permission secara otomatis.</p>
            </div>
            <button type="button" class="icon-btn" onclick="closeExtractModal()" aria-label="Tutup"><?= ui_icon('x') ?></button>
        </div>
        <form id="extractForm" onsubmit="submitExtract(event)">
            <input type="hidden" id="extractZipPath" value="">
            <div class="modal-body">
                <div class="field">
                    <label class="label">File Arsip</label>
                    <code id="extractZipName" style="display:block;padding:8px 12px;font-size:13px;"></code>
                </div>
                <div class="field">
                    <label class="label" for="extractDestPath">Direktori Tujuan</label>
                    <input type="text" id="extractDestPath" class="input mono" placeholder="contoh: / atau subfolder">
                    <div class="help">Isi <code>/</code> untuk mengekstrak ke root website, atau kosongkan untuk mengekstrak di folder arsip berada.</div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeExtractModal()">Batal</button>
                <button type="submit" class="btn btn-primary" id="extractSubmitBtn">Ekstrak sekarang</button>
            </div>
        </form>
    </div>
</div>

<script>
var CURRENT_SITE_ID = <?= (int)($site['id'] ?? 0) ?>;
var CURRENT_SITE_DOMAIN = <?= json_encode((string)($site['domain'] ?? '')) ?>;
var CURRENT_PATH = <?= json_encode((string)$initialPath) ?>;
var CURRENT_ITEMS = [];
var ACTIVE_EDITOR_FILE = null;
var ACTIVE_EDITOR_IS_NGINX = false;
var EDITOR_ORIGINAL_CONTENT = '';
var EDITOR_IS_DIRTY = false;
var OPEN_EDIT_INITIAL = <?= json_encode((string)$openEdit) ?>;

// Clipboard State for Copy / Cut (persisted in sessionStorage across site switches)
var CLIPBOARD = (function () {
    try {
        var saved = sessionStorage.getItem('almusa_fm_clipboard');
        if (saved) {
            var parsed = JSON.parse(saved);
            if (parsed && parsed.action && Array.isArray(parsed.items) && parsed.items.length > 0) {
                return parsed;
            }
        }
    } catch (e) {}
    return {
        action: null, // 'copy' or 'cut'
        items: [],    // array of relative paths
        sourceSiteId: CURRENT_SITE_ID
    };
})();

function saveClipboardState() {
    try {
        if (CLIPBOARD && CLIPBOARD.action && CLIPBOARD.items && CLIPBOARD.items.length > 0) {
            sessionStorage.setItem('almusa_fm_clipboard', JSON.stringify(CLIPBOARD));
        } else {
            sessionStorage.removeItem('almusa_fm_clipboard');
        }
    } catch (e) {}
}

function escapeHtml(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
        return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
}

/* ----------------------------------------------------------------- NAVIGATION */
function switchSite(siteId) {
    location.href = '/sites/files?site_id=' + siteId;
}

function navigateToPath(path) {
    CURRENT_PATH = path.replace(/^\/+|\/+$/g, '');
    loadDirectory(CURRENT_PATH);
}

function goToParent() {
    var parts = CURRENT_PATH.split('/').filter(Boolean);
    parts.pop();
    navigateToPath(parts.join('/'));
}

function refreshDirectory() {
    loadDirectory(CURRENT_PATH);
}

/* ----------------------------------------------------------- LOAD DIRECTORY */
async function loadDirectory(path) {
    var tbody = document.getElementById('fmTableBody');
    tbody.innerHTML = '<tr><td colspan="7" style="text-align:center;padding:40px;color:var(--text-3);">' +
        '<div style="display:inline-flex;align-items:center;gap:8px;">' +
        '<svg class="icon" style="animation:spin 1s linear infinite;" aria-hidden="true"><use href="#i-refresh"/></svg> ' +
        'Memuat direktori…</div></td></tr>';

    try {
        var res = await Almusa.get('/api/files/list?site_id=' + CURRENT_SITE_ID + '&path=' + encodeURIComponent(path));
        if (!res.success) {
            tbody.innerHTML = '<tr><td colspan="7" style="text-align:center;padding:32px;color:var(--err);">' +
                escapeHtml(res.error || 'Gagal memuat direktori.') + '</td></tr>';
            Almusa.toast(res.error || 'Gagal memuat direktori.', 'error');
            return;
        }

        CURRENT_PATH = res.current_path || '';
        CURRENT_ITEMS = res.items || [];
        renderBreadcrumbs(res.breadcrumbs || [], res.parent_path);
        renderStats(res.stats);
        renderTable(CURRENT_ITEMS);

        var searchInput = document.getElementById('fmSearchInput');
        if (searchInput && searchInput.value.trim() !== '') {
            filterFiles(searchInput.value);
        }

        updateBatchBar();
        updateClipboardBar();

        var newUrl = '/sites/files?site_id=' + CURRENT_SITE_ID + (CURRENT_PATH ? ('&path=' + encodeURIComponent(CURRENT_PATH)) : '');
        history.replaceState({ path: CURRENT_PATH }, '', newUrl);

    } catch (e) {
        console.error('loadDirectory error:', e);
        var msg = (e && e.message) ? ('Kesalahan: ' + escapeHtml(e.message)) : 'Kesalahan jaringan saat memuat direktori.';
        tbody.innerHTML = '<tr><td colspan="7" style="text-align:center;padding:32px;color:var(--err);">' + msg + '</td></tr>';
    }
}

function renderBreadcrumbs(crumbs, parentPath) {
    var parentBtn = document.getElementById('fmParentBtn');
    if (parentBtn) {
        parentBtn.style.display = (parentPath !== null && parentPath !== undefined) ? 'inline-grid' : 'none';
    }

    var container = document.getElementById('fmCrumbSegments');
    container.innerHTML = '';

    crumbs.forEach(function (crumb, idx) {
        var sep = document.createElement('span');
        sep.className = 'fm-crumb-sep';
        sep.textContent = '>';
        container.appendChild(sep);

        var isLast = (idx === crumbs.length - 1);
        if (isLast) {
            var curr = document.createElement('span');
            curr.className = 'fm-crumb-current';
            curr.textContent = crumb.name;
            container.appendChild(curr);
        } else {
            var link = document.createElement('span');
            link.className = 'fm-crumb-link';
            link.textContent = crumb.name;
            link.onclick = function () { navigateToPath(crumb.path); };
            container.appendChild(link);
        }
    });
}

function renderStats(stats) {
    var badge = document.getElementById('fmStatsBadge');
    var diskPill = document.getElementById('fmDiskPill');
    if (stats) {
        if (badge) {
            badge.textContent = '(Total ' + stats.dir_count + ' direktori & ' + stats.file_count + ' berkas, Ukuran: ' + stats.total_size_fmt + ')';
        }
        if (diskPill) {
            diskPill.innerHTML = '<svg class="icon" aria-hidden="true" style="width:12px;height:12px;"><use href="#i-disk"/></svg> Sisa: ' + stats.disk_free_fmt + ' / ' + stats.disk_total_fmt;
        }
    }
}

/* ----------------------------------------------------------- TABLE RENDERING */
function renderTable(items) {
    var tbody = document.getElementById('fmTableBody');
    tbody.innerHTML = '';

    var selectAll = document.getElementById('fmSelectAll');
    if (selectAll) selectAll.checked = false;

    if (items.length === 0) {
        tbody.innerHTML = '<tr><td colspan="7" style="text-align:center;padding:48px 16px;color:var(--text-3);">' +
            '<div style="margin-bottom:8px;"><svg class="icon" style="width:40px;height:40px;opacity:0.4;" aria-hidden="true"><use href="#i-folder"/></svg></div>' +
            '<div style="font-weight:500;font-size:14px;color:var(--text-2);">Folder ini masih kosong</div>' +
            '<div style="font-size:12px;margin-top:4px;">Gunakan tombol Unggah atau File/Folder baru di atas untuk mulai menambahkan berkas.</div>' +
            '</td></tr>';
        return;
    }

    items.forEach(function (item) {
        var tr = document.createElement('tr');
        tr.className = 'fm-item-row';
        tr.dataset.name = item.name;
        tr.dataset.path = item.path;

        // 1. Checkbox
        var tdCheck = document.createElement('td');
        tdCheck.style.textAlign = 'center';
        var chk = document.createElement('input');
        chk.type = 'checkbox';
        chk.className = 'fm-row-checkbox';
        chk.dataset.path = item.path;
        chk.onchange = updateBatchBar;
        tdCheck.appendChild(chk);
        tr.appendChild(tdCheck);

        // 2. Name & Icon
        var tdName = document.createElement('td');
        var nameWrap = document.createElement('span');
        nameWrap.className = 'fm-item-name';

        var iconSymbol = '#i-file';
        var iconColorClass = 'fm-icon-file';
        if (item.is_dir) {
            iconSymbol = '#i-folder';
            iconColorClass = 'fm-icon-folder';
        } else if (item.is_archive) {
            iconSymbol = '#i-archive';
            iconColorClass = 'fm-icon-archive';
        } else if (item.syntax === 'env') {
            iconSymbol = '#i-lock';
            iconColorClass = 'fm-icon-env';
        } else if (item.is_editable) {
            iconSymbol = '#i-file-code';
            iconColorClass = 'fm-icon-code';
        }

        nameWrap.innerHTML = '<svg class="icon ' + iconColorClass + '" aria-hidden="true"><use href="' + iconSymbol + '"/></svg>' +
            '<span class="truncate" style="max-width:320px;">' + escapeHtml(item.name) + '</span>';

        if (item.is_dir) {
            nameWrap.onclick = function () { navigateToPath(item.path); };
        } else if (item.is_editable) {
            nameWrap.onclick = function () { openFileEditor(item.path); };
            nameWrap.title = 'Klik untuk mengedit berkas';
        } else if (item.is_archive) {
            nameWrap.onclick = function () { openExtractModal(item.path, item.name); };
            nameWrap.title = 'Klik untuk mengekstrak arsip';
        }
        tdName.appendChild(nameWrap);
        tr.appendChild(tdName);

        // 3. Size
        var tdSize = document.createElement('td');
        tdSize.className = 'mono';
        tdSize.style.fontSize = '12px';
        tdSize.style.color = item.is_dir ? 'var(--text-3)' : 'var(--text-2)';
        tdSize.textContent = item.size;
        tr.appendChild(tdSize);

        // 4. Mtime
        var tdMtime = document.createElement('td');
        tdMtime.className = 'mono text-muted';
        tdMtime.style.fontSize = '12px';
        tdMtime.textContent = item.mtime_fmt;
        tr.appendChild(tdMtime);

        // 5. Perms (Clickable to chmod directly)
        var tdPerms = document.createElement('td');
        var permsBtn = document.createElement('a');
        permsBtn.href = 'javascript:void(0)';
        permsBtn.className = 'mono';
        permsBtn.style.fontSize = '12px';
        permsBtn.textContent = item.perms_num || item.perms;
        permsBtn.title = 'Klik untuk mengubah izin (chmod)';
        permsBtn.onclick = function () { openChmodModal(item.path, item.perms_num || item.perms, item.is_dir); };
        tdPerms.appendChild(permsBtn);
        tr.appendChild(tdPerms);

        // 6. Owner
        var tdOwner = document.createElement('td');
        tdOwner.className = 'mono text-muted';
        tdOwner.style.fontSize = '12px';
        tdOwner.textContent = item.owner || 'www-data';
        tr.appendChild(tdOwner);

        // 7. Actions (Clean Link Style separated by | matching aaPanel / SLEMP)
        var tdActions = document.createElement('td');
        tdActions.className = 'th-right';
        tdActions.style.whiteSpace = 'nowrap';
        tdActions.style.fontSize = '12px';

        var actionCount = 0;
        function appendActionSep() {
            if (actionCount > 0) {
                var sep = document.createElement('span');
                sep.style.color = 'var(--border)';
                sep.style.margin = '0 4px';
                sep.textContent = '|';
                tdActions.appendChild(sep);
            }
            actionCount++;
        }

        function appendActionBtn(label, onClickFn, styleText) {
            appendActionSep();
            var a = document.createElement('a');
            a.href = 'javascript:void(0)';
            if (styleText) a.style.cssText = styleText;
            a.textContent = label;
            a.addEventListener('click', function (ev) {
                ev.preventDefault();
                ev.stopPropagation();
                onClickFn();
            });
            tdActions.appendChild(a);
        }

        // Edit
        if (item.is_editable) {
            appendActionBtn('Edit', function () {
                openFileEditor(item.path);
            });
        }

        // Copy
        appendActionBtn('Salin', function () {
            singleCopy(item.path);
        });

        // Cut
        appendActionBtn('Potong', function () {
            singleCut(item.path);
        });

        // Rename
        appendActionBtn('Rename', function () {
            openRenameModal(item.path, item.name);
        });

        // Chmod
        appendActionBtn('Chmod', function () {
            openChmodModal(item.path, item.perms_num || item.perms, !!item.is_dir);
        });

        // Extract or Zip
        if (item.is_archive) {
            appendActionBtn('Ekstrak', function () {
                openExtractModal(item.path, item.name);
            }, 'font-weight:600;color:var(--primary, #3b82f6);');
        } else {
            appendActionBtn('Zip', function () {
                openCompressModal(item.path, item.name);
            });
        }

        // Download
        if (!item.is_dir) {
            appendActionSep();
            var dlLink = document.createElement('a');
            dlLink.href = '/api/files/download?site_id=' + encodeURIComponent(CURRENT_SITE_ID) + '&file=' + encodeURIComponent(item.path);
            dlLink.setAttribute('download', item.name);
            dlLink.textContent = 'Unduh';
            tdActions.appendChild(dlLink);
        }

        // Delete
        appendActionBtn('Hapus', function () {
            deleteItem(item.path, !!item.is_dir);
        }, 'color:var(--err, #ef4444);');

        tr.appendChild(tdActions);

        tbody.appendChild(tr);
    });
}

function jsArg(str) {
    return escapeHtml(JSON.stringify(String(str)));
}

function filterFiles(query) {
    var q = query.trim().toLowerCase();
    var rows = document.querySelectorAll('#fmTableBody tr.fm-item-row');
    rows.forEach(function (row) {
        var name = (row.dataset.name || '').toLowerCase();
        var visible = (name.indexOf(q) !== -1);
        row.style.display = visible ? '' : 'none';
        if (!visible) {
            var chk = row.querySelector('.fm-row-checkbox');
            if (chk && chk.checked) {
                chk.checked = false;
            }
        }
    });
    updateBatchBar();
}

/* ----------------------------------------------------------- SELECTION & BATCH */
function toggleSelectAll(checked) {
    var rows = document.querySelectorAll('#fmTableBody tr.fm-item-row');
    rows.forEach(function (row) {
        var chk = row.querySelector('.fm-row-checkbox');
        if (!chk) return;
        if (checked) {
            if (row.style.display !== 'none') {
                chk.checked = true;
            }
        } else {
            chk.checked = false;
        }
    });
    updateBatchBar();
}

function deselectAll() {
    var selectAll = document.getElementById('fmSelectAll');
    if (selectAll) selectAll.checked = false;
    toggleSelectAll(false);
}

function getSelectedPaths() {
    var checked = document.querySelectorAll('.fm-row-checkbox:checked');
    var paths = [];
    checked.forEach(function (c) { paths.push(c.dataset.path); });
    return paths;
}

function updateBatchBar() {
    var selected = getSelectedPaths();
    var bar = document.getElementById('fmBatchBar');
    var count = document.getElementById('fmBatchCount');
    if (!bar) return;

    if (selected.length > 0) {
        bar.style.display = 'flex';
        count.textContent = selected.length;
    } else {
        bar.style.display = 'none';
    }
}

async function batchAction(action) {
    var selected = getSelectedPaths();
    if (selected.length === 0) return;

    if (action === 'delete') {
        var ok = await Almusa.confirm({
            title: 'Hapus ' + selected.length + ' Item Terpilih',
            message: 'Apakah Anda yakin ingin menghapus ' + selected.length + ' item terpilih?\nTindakan ini tidak dapat dibatalkan.',
            confirmText: 'Ya, hapus permanen',
            danger: true
        });
        if (!ok) return;

        try {
            var res = await Almusa.post('/api/files/batch', {
                site_id: CURRENT_SITE_ID,
                action: 'delete',
                items: selected
            });
            if (res.success) {
                Almusa.toast(res.message || 'Item terpilih berhasil dihapus.', 'success');
                refreshDirectory();
            } else {
                Almusa.toast(res.error || 'Gagal menghapus item.', 'error');
            }
        } catch (e) {
            Almusa.toast('Kesalahan jaringan.', 'error');
        }
    } else if (action === 'copy' || action === 'cut') {
        CLIPBOARD = {
            action: action,
            items: selected,
            sourceSiteId: CURRENT_SITE_ID
        };
        saveClipboardState();
        updateClipboardBar();
        Almusa.toast(selected.length + ' item dimasukkan ke clipboard (' + (action === 'copy' ? 'Salin' : 'Potong') + '). Navigasikan ke folder tujuan lalu klik Tempel.', 'info');
        deselectAll();
    } else if (action === 'chmod') {
        openChmodModal(selected[0], '755', false, selected);
    } else if (action === 'compress') {
        var firstBase = (selected[0] || '').split('/').pop() || 'archive';
        openCompressModal(selected[0], firstBase, selected);
    }
}

/* ----------------------------------------------------------- CLIPBOARD (COPY/CUT/PASTE) */
function singleCopy(path) {
    CLIPBOARD = { action: 'copy', items: [path], sourceSiteId: CURRENT_SITE_ID };
    saveClipboardState();
    updateClipboardBar();
    Almusa.toast('"' + path.split('/').pop() + '" disalin ke clipboard. Buka folder tujuan lalu klik Tempel.', 'info');
}

function singleCut(path) {
    CLIPBOARD = { action: 'cut', items: [path], sourceSiteId: CURRENT_SITE_ID };
    saveClipboardState();
    updateClipboardBar();
    Almusa.toast('"' + path.split('/').pop() + '" dipotong ke clipboard. Buka folder tujuan lalu klik Tempel.', 'info');
}

function updateClipboardBar() {
    var bar = document.getElementById('fmClipboardBar');
    var label = document.getElementById('fmClipboardLabel');
    if (!bar) return;

    if (CLIPBOARD && CLIPBOARD.action && CLIPBOARD.items.length > 0) {
        bar.style.display = 'flex';
        var actName = CLIPBOARD.action === 'copy' ? 'Salin' : 'Pindahkan (Cut)';
        label.innerHTML = '📋 Clipboard: <strong>' + CLIPBOARD.items.length + ' item</strong> disiapkan untuk di-<strong>' + actName + '</strong> ke: <code>/' + escapeHtml(CURRENT_PATH || '') + '</code>';
    } else {
        bar.style.display = 'none';
    }
}

function clearClipboard() {
    CLIPBOARD = { action: null, items: [], sourceSiteId: CURRENT_SITE_ID };
    saveClipboardState();
    updateClipboardBar();
}

async function pasteClipboard() {
    if (!CLIPBOARD || !CLIPBOARD.action || CLIPBOARD.items.length === 0) return;

    var action = CLIPBOARD.action === 'copy' ? 'copy' : 'move';
    try {
        var res = await Almusa.post('/api/files/batch', {
            site_id: CURRENT_SITE_ID,
            source_site_id: CLIPBOARD.sourceSiteId != null ? CLIPBOARD.sourceSiteId : CURRENT_SITE_ID,
            action: action,
            items: CLIPBOARD.items,
            destination: CURRENT_PATH
        });

        if (res.success) {
            Almusa.toast(res.message || 'Operasi tempel berhasil.', 'success');
            clearClipboard();
            refreshDirectory();
        } else {
            Almusa.toast(res.error || 'Gagal menempelkan item.', 'error');
        }
    } catch (e) {
        Almusa.toast('Kesalahan jaringan saat menempelkan berkas.', 'error');
    }
}

/* ----------------------------------------------------------- RENAME MODAL */
function openRenameModal(oldPath, oldName) {
    document.getElementById('renameOldPath').value = oldPath;
    document.getElementById('renameInputName').value = oldName;
    Almusa.openModal('renameModal');
    setTimeout(function () { document.getElementById('renameInputName').focus(); }, 60);
}

function closeRenameModal() {
    Almusa.closeModal('renameModal');
}

async function submitRename(e) {
    e.preventDefault();
    var oldPath = document.getElementById('renameOldPath').value;
    var newName = document.getElementById('renameInputName').value.trim();
    var btn = document.getElementById('renameSubmitBtn');
    var restore = Almusa.busy(btn, 'Menyimpan…');

    try {
        var res = await Almusa.post('/api/files/rename', {
            site_id: CURRENT_SITE_ID,
            path: oldPath,
            new_name: newName
        });
        if (res.success) {
            closeRenameModal();
            Almusa.toast(res.message || 'Nama berhasil diubah.', 'success');
            refreshDirectory();
        } else {
            Almusa.toast(res.error || 'Gagal mengubah nama.', 'error');
        }
    } catch (e) {
        Almusa.toast('Kesalahan jaringan.', 'error');
    } finally {
        restore();
    }
}

/* ----------------------------------------------------------- CHMOD MODAL */
var CHMOD_BATCH_ITEMS = null;

function openChmodModal(path, currentPerms, isDir, batchItems) {
    CHMOD_BATCH_ITEMS = batchItems || null;
    document.getElementById('chmodTargetRel').value = path;
    document.getElementById('chmodItemPath').textContent = batchItems ? (batchItems.length + ' item terpilih') : ('/' + path);
    document.getElementById('chmodRecursiveWrap').style.display = (isDir || batchItems) ? 'block' : 'none';
    document.getElementById('chmodRecursive').checked = false;

    var num = String(currentPerms || '755').replace(/^0+/, '');
    if (num.length > 3) num = num.substring(num.length - 3);
    document.getElementById('chmodOctalInput').value = num || '755';
    syncChmodCheckboxes(num || '755');

    Almusa.openModal('chmodModal');
}

function closeChmodModal() {
    Almusa.closeModal('chmodModal');
    CHMOD_BATCH_ITEMS = null;
}

function syncChmodCheckboxes(octalStr) {
    var s = String(octalStr || '').padStart(3, '0');
    var u = parseInt(s.charAt(0)) || 0;
    var g = parseInt(s.charAt(1)) || 0;
    var o = parseInt(s.charAt(2)) || 0;

    document.getElementById('cm_u_r').checked = (u & 4) !== 0;
    document.getElementById('cm_u_w').checked = (u & 2) !== 0;
    document.getElementById('cm_u_x').checked = (u & 1) !== 0;

    document.getElementById('cm_g_r').checked = (g & 4) !== 0;
    document.getElementById('cm_g_w').checked = (g & 2) !== 0;
    document.getElementById('cm_g_x').checked = (g & 1) !== 0;

    document.getElementById('cm_o_r').checked = (o & 4) !== 0;
    document.getElementById('cm_o_w').checked = (o & 2) !== 0;
    document.getElementById('cm_o_x').checked = (o & 1) !== 0;
}

function calcChmodOctal() {
    var u = (document.getElementById('cm_u_r').checked ? 4 : 0) +
            (document.getElementById('cm_u_w').checked ? 2 : 0) +
            (document.getElementById('cm_u_x').checked ? 1 : 0);

    var g = (document.getElementById('cm_g_r').checked ? 4 : 0) +
            (document.getElementById('cm_g_w').checked ? 2 : 0) +
            (document.getElementById('cm_g_x').checked ? 1 : 0);

    var o = (document.getElementById('cm_o_r').checked ? 4 : 0) +
            (document.getElementById('cm_o_w').checked ? 2 : 0) +
            (document.getElementById('cm_o_x').checked ? 1 : 0);

    var octal = '' + u + g + o;
    document.getElementById('chmodOctalInput').value = octal;
}

async function submitChmod(e) {
    e.preventDefault();
    var path = document.getElementById('chmodTargetRel').value;
    var perms = document.getElementById('chmodOctalInput').value.trim();
    var recursive = document.getElementById('chmodRecursive').checked ? 1 : 0;
    var btn = document.getElementById('chmodSubmitBtn');
    var restore = Almusa.busy(btn, 'Menerapkan…');

    try {
        var res;
        if (CHMOD_BATCH_ITEMS && CHMOD_BATCH_ITEMS.length > 0) {
            res = await Almusa.post('/api/files/batch', {
                site_id: CURRENT_SITE_ID,
                action: 'chmod',
                items: CHMOD_BATCH_ITEMS,
                perms: perms,
                recursive: recursive
            });
        } else {
            res = await Almusa.post('/api/files/chmod', {
                site_id: CURRENT_SITE_ID,
                path: path,
                perms: perms,
                recursive: recursive
            });
        }

        if (res.success) {
            closeChmodModal();
            Almusa.toast(res.message || 'Izin berhasil diubah.', 'success');
            refreshDirectory();
        } else {
            Almusa.toast(res.error || 'Gagal mengubah izin.', 'error');
        }
    } catch (e) {
        Almusa.toast('Kesalahan jaringan.', 'error');
    } finally {
        restore();
    }
}

/* ----------------------------------------------------------- COMPRESS MODAL */
var COMPRESS_BATCH_ITEMS = null;

function openCompressModal(itemPath, itemName, batchItems) {
    COMPRESS_BATCH_ITEMS = batchItems || null;
    document.getElementById('compressSourceItem').value = itemPath || '';
    if (batchItems && batchItems.length > 1) {
        document.getElementById('compressSourceName').textContent = batchItems.length + ' item terpilih';
        document.getElementById('compressZipName').value = 'archive_' + new Date().toISOString().slice(0, 10).replace(/-/g, '') + '.zip';
    } else {
        document.getElementById('compressSourceName').textContent = itemName;
        var defaultZip = (itemName.replace(/\.[^/.]+$/, '') || itemName || 'archive') + '.zip';
        document.getElementById('compressZipName').value = defaultZip;
    }
    Almusa.openModal('compressModal');
    setTimeout(function () { document.getElementById('compressZipName').focus(); }, 60);
}

function closeCompressModal() {
    Almusa.closeModal('compressModal');
    COMPRESS_BATCH_ITEMS = null;
}

async function submitCompress(e) {
    e.preventDefault();
    var item = document.getElementById('compressSourceItem').value;
    var zipName = document.getElementById('compressZipName').value.trim();
    var btn = document.getElementById('compressSubmitBtn');
    var restore = Almusa.busy(btn, 'Mengompres…');

    try {
        var payload = {
            site_id: CURRENT_SITE_ID,
            zip_name: zipName
        };
        if (COMPRESS_BATCH_ITEMS && COMPRESS_BATCH_ITEMS.length > 0) {
            payload.items = COMPRESS_BATCH_ITEMS;
        } else {
            payload.item = item;
        }

        var res = await Almusa.post('/api/files/compress', payload);
        if (res.success) {
            closeCompressModal();
            Almusa.toast(res.message || 'Arsip berhasil dibuat.', 'success');
            refreshDirectory();
        } else {
            Almusa.toast(res.error || 'Gagal membuat arsip.', 'error');
        }
    } catch (e) {
        Almusa.toast('Kesalahan jaringan saat mengompres.', 'error');
    } finally {
        restore();
    }
}

/* ----------------------------------------------------------- CODE EDITOR */
function updateEditorStats() {
    var textarea = document.getElementById('editorTextarea');
    var val = textarea.value;
    var lines = val.split('\n');
    var lineCount = lines.length;
    var charCount = val.length;

    document.getElementById('editorStats').textContent = 'Baris: ' + lineCount + ' | Karakter: ' + charCount;

    var gutter = document.getElementById('editorGutter');
    var nums = [];
    for (var i = 1; i <= lineCount; i++) {
        nums.push(i);
    }
    gutter.textContent = nums.join('\n');

    EDITOR_IS_DIRTY = (val !== EDITOR_ORIGINAL_CONTENT);
}

async function openFileEditor(filePath) {
    ACTIVE_EDITOR_FILE = filePath;
    ACTIVE_EDITOR_IS_NGINX = false;
    EDITOR_ORIGINAL_CONTENT = '';
    EDITOR_IS_DIRTY = false;

    document.getElementById('editorFileTitle').textContent = filePath.split('/').pop();
    document.getElementById('editorFilePath').textContent = '/' + filePath;
    document.getElementById('editorSyntaxError').style.display = 'none';
    document.getElementById('editorStatusText').textContent = 'Memuat file…';

    Almusa.openModal('codeEditorModal');

    try {
        var res = await Almusa.get('/api/files/read?site_id=' + CURRENT_SITE_ID + '&file=' + encodeURIComponent(filePath));
        if (!res.success) {
            Almusa.toast(res.error || 'Gagal membaca file.', 'error');
            Almusa.closeModal('codeEditorModal');
            return;
        }

        var textarea = document.getElementById('editorTextarea');
        textarea.value = res.content || '';
        EDITOR_ORIGINAL_CONTENT = textarea.value;
        EDITOR_IS_DIRTY = false;
        document.getElementById('editorSyntaxBadge').textContent = (res.syntax || 'txt').toUpperCase();
        document.getElementById('editorStatusText').textContent = 'Siap (' + (res.size || 0) + ' bytes)';
        updateEditorStats();
        textarea.focus();
    } catch (e) {
        Almusa.toast('Gagal memuat file.', 'error');
        Almusa.closeModal('codeEditorModal');
    }
}

async function openNginxEditor() {
    ACTIVE_EDITOR_FILE = 'nginx.conf';
    ACTIVE_EDITOR_IS_NGINX = true;
    EDITOR_ORIGINAL_CONTENT = '';
    EDITOR_IS_DIRTY = false;

    var cleanDomain = CURRENT_SITE_DOMAIN || document.getElementById('siteSelector').selectedOptions[0].text.replace(/^[^\w.-]+/, '').trim();
    document.getElementById('editorFileTitle').textContent = 'Konfigurasi Nginx (Virtual Host)';
    document.getElementById('editorFilePath').textContent = '/etc/nginx/sites-available/' + cleanDomain + '.conf';
    document.getElementById('editorSyntaxBadge').textContent = 'NGINX';
    document.getElementById('editorSyntaxError').style.display = 'none';
    document.getElementById('editorStatusText').textContent = 'Memuat konfigurasi Nginx…';

    Almusa.openModal('codeEditorModal');

    try {
        var res = await Almusa.get('/api/files/read?site_id=' + CURRENT_SITE_ID + '&is_nginx=1');
        if (!res.success) {
            Almusa.toast(res.error || 'Gagal membaca konfigurasi Nginx.', 'error');
            Almusa.closeModal('codeEditorModal');
            return;
        }

        var textarea = document.getElementById('editorTextarea');
        textarea.value = res.content || '';
        EDITOR_ORIGINAL_CONTENT = textarea.value;
        EDITOR_IS_DIRTY = false;
        document.getElementById('editorStatusText').textContent = 'Konfigurasi aktif';
        updateEditorStats();
        textarea.focus();
    } catch (e) {
        Almusa.toast('Gagal memuat konfigurasi Nginx.', 'error');
        Almusa.closeModal('codeEditorModal');
    }
}

async function closeCodeEditor() {
    if (EDITOR_IS_DIRTY) {
        var discard = await Almusa.confirm({
            title: 'Perubahan Belum Disimpan',
            message: 'Ada perubahan pada editor yang belum disimpan. Tutup tanpa menyimpan?',
            confirmText: 'Tutup tanpa menyimpan',
            danger: true
        });
        if (!discard) return;
    }
    Almusa.closeModal('codeEditorModal');
    ACTIVE_EDITOR_FILE = null;
    ACTIVE_EDITOR_IS_NGINX = false;
    EDITOR_IS_DIRTY = false;
}

async function saveEditorContent() {
    var saveBtn = document.getElementById('editorSaveBtn');
    var restore = Almusa.busy(saveBtn, 'Menyimpan…');
    var content = document.getElementById('editorTextarea').value;
    var errBox = document.getElementById('editorSyntaxError');
    errBox.style.display = 'none';

    var payload = {
        site_id: CURRENT_SITE_ID,
        content: content
    };

    if (ACTIVE_EDITOR_IS_NGINX) {
        payload.is_nginx = 1;
    } else {
        payload.file = ACTIVE_EDITOR_FILE;
    }

    try {
        var res = await Almusa.post('/api/files/save', payload);
        if (res.success) {
            EDITOR_ORIGINAL_CONTENT = content;
            EDITOR_IS_DIRTY = false;
            Almusa.toast(res.message || 'File berhasil disimpan.', 'success');
            document.getElementById('editorStatusText').textContent = 'Tersimpan ' + new Date().toLocaleTimeString();
            refreshDirectory();
        } else {
            Almusa.toast('Gagal menyimpan file.', 'error');
            if (res.error) {
                errBox.textContent = res.error;
                errBox.style.display = 'block';
            }
        }
    } catch (e) {
        Almusa.toast('Kesalahan jaringan saat menyimpan file.', 'error');
    } finally {
        restore();
    }
}

function copyEditorContent() {
    var val = document.getElementById('editorTextarea').value;
    Almusa.copy(val);
}

// Bind Textarea Events (Tab indentation, Line counter sync, Ctrl+S)
(function initEditorTextarea() {
    var textarea = document.getElementById('editorTextarea');
    var gutter = document.getElementById('editorGutter');

    textarea.addEventListener('input', function () {
        updateEditorStats();
        if (EDITOR_IS_DIRTY) {
            document.getElementById('editorStatusText').textContent = 'Belum disimpan • Tekan Ctrl+S untuk menyimpan';
        }
    });

    textarea.addEventListener('scroll', function () {
        gutter.scrollTop = textarea.scrollTop;
    });

    textarea.addEventListener('keydown', function (e) {
        if (e.key === 'Tab') {
            e.preventDefault();
            var start = this.selectionStart;
            var end = this.selectionEnd;
            this.value = this.value.substring(0, start) + '    ' + this.value.substring(end);
            this.selectionStart = this.selectionEnd = start + 4;
            updateEditorStats();
        }

        if ((e.ctrlKey || e.metaKey) && (e.key === 's' || e.key === 'S')) {
            e.preventDefault();
            saveEditorContent();
        }
    });
})();

/* ----------------------------------------------------------- NEW ITEM MODAL */
function openNewItemModal(type) {
    document.getElementById('newItemType').value = type;
    document.getElementById('newItemName').value = '';
    var isDir = (type === 'dir');
    document.getElementById('newItemTitle').textContent = isDir ? 'Folder Baru' : 'File Baru';
    document.getElementById('newItemSubtitle').textContent = 'Akan dibuat di direktori: /' + CURRENT_PATH;
    document.getElementById('newItemLabel').textContent = isDir ? 'Nama Folder' : 'Nama File';
    document.getElementById('newItemName').placeholder = isDir ? 'contoh: config atau assets' : 'contoh: index.php atau .env';
    document.getElementById('newItemHelp').textContent = isDir ? 'Folder baru untuk mengelompokkan berkas.' : 'File baru dapat diedit langsung setelah dibuat.';
    Almusa.openModal('newItemModal');
    setTimeout(function () { document.getElementById('newItemName').focus(); }, 60);
}

function closeNewItemModal() {
    Almusa.closeModal('newItemModal');
}

async function submitNewItem(e) {
    e.preventDefault();
    var name = document.getElementById('newItemName').value.trim();
    var type = document.getElementById('newItemType').value;
    var btn = document.getElementById('newItemSubmitBtn');
    var restore = Almusa.busy(btn, 'Membuat…');

    try {
        var res = await Almusa.post('/api/files/create', {
            site_id: CURRENT_SITE_ID,
            path: CURRENT_PATH,
            name: name,
            type: type
        });

        if (res.success) {
            closeNewItemModal();
            Almusa.toast(res.message || 'Item berhasil dibuat.', 'success');
            refreshDirectory();
            if (type === 'file') {
                var newFilePath = (CURRENT_PATH ? (CURRENT_PATH + '/') : '') + name;
                openFileEditor(newFilePath);
            }
        } else {
            Almusa.toast(res.error || 'Gagal membuat item.', 'error');
        }
    } catch (err) {
        Almusa.toast('Kesalahan jaringan.', 'error');
    } finally {
        restore();
    }
}

/* ----------------------------------------------------------- DELETE ITEM */
async function deleteItem(relPath, isDir) {
    var ok = await Almusa.confirm({
        title: isDir ? 'Hapus Folder' : 'Hapus File',
        message: 'Apakah Anda yakin ingin menghapus "' + relPath + '"?\n' + (isDir ? 'Seluruh isi folder akan terhapus permanen.' : 'Tindakan ini tidak dapat dibatalkan.'),
        confirmText: 'Ya, hapus permanen',
        danger: true
    });

    if (!ok) return;

    try {
        var res = await Almusa.post('/api/files/delete', {
            site_id: CURRENT_SITE_ID,
            path: relPath
        });

        if (res.success) {
            Almusa.toast(res.message || 'Item dihapus.', 'success');
            refreshDirectory();
        } else {
            Almusa.toast(res.error || 'Gagal menghapus item.', 'error');
        }
    } catch (e) {
        Almusa.toast('Kesalahan jaringan saat menghapus.', 'error');
    }
}

/* ----------------------------------------------------------- UPLOAD MODAL (CHUNKED 1.5MB) */
var selectedFiles = [];

function openUploadModal() {
    selectedFiles = [];
    document.getElementById('uploadFileInput').value = '';
    document.getElementById('uploadFileList').innerHTML = '';
    document.getElementById('uploadFileList').style.display = 'none';
    var progWrap = document.getElementById('uploadProgressWrap');
    if (progWrap) progWrap.style.display = 'none';
    var btn = document.getElementById('uploadSubmitBtn');
    btn.disabled = true;
    btn.textContent = 'Mulai unggah';
    Almusa.openModal('uploadModal');
}

function closeUploadModal() {
    Almusa.closeModal('uploadModal');
    selectedFiles = [];
}

function handleFileSelect(input) {
    if (!input.files || input.files.length === 0) return;
    selectedFiles = Array.from(input.files);
    renderSelectedFiles();
}

function renderSelectedFiles() {
    var list = document.getElementById('uploadFileList');
    var btn = document.getElementById('uploadSubmitBtn');
    if (selectedFiles.length === 0) {
        list.style.display = 'none';
        btn.disabled = true;
        return;
    }

    list.innerHTML = '';
    list.style.display = 'block';
    selectedFiles.forEach(function (f) {
        var item = document.createElement('div');
        item.style.cssText = 'display:flex;justify-content:space-between;padding:6px 10px;background:var(--surface-hover);border-radius:6px;margin-bottom:6px;font-size:12px;';
        item.innerHTML = '<span class="truncate" style="max-width:280px;font-weight:500;">' + escapeHtml(f.name) + '</span>' +
            '<span class="mono text-muted">' + (f.size > 1048576 ? (f.size / 1048576).toFixed(1) + ' MB' : (f.size / 1024).toFixed(1) + ' KB') + '</span>';
        list.appendChild(item);
    });

    btn.disabled = false;
    btn.textContent = 'Unggah ' + selectedFiles.length + ' file';
}

(function initDropzone() {
    var dropzone = document.getElementById('uploadDropzone');
    if (!dropzone) return;

    ['dragenter', 'dragover'].forEach(function (name) {
        dropzone.addEventListener(name, function (e) {
            e.preventDefault();
            e.stopPropagation();
            dropzone.classList.add('dragover');
        });
    });

    ['dragleave', 'drop'].forEach(function (name) {
        dropzone.addEventListener(name, function (e) {
            e.preventDefault();
            e.stopPropagation();
            dropzone.classList.remove('dragover');
        });
    });

    dropzone.addEventListener('drop', function (e) {
        var dt = e.dataTransfer;
        if (dt && dt.files && dt.files.length > 0) {
            selectedFiles = Array.from(dt.files);
            renderSelectedFiles();
        }
    });
})();

async function submitUpload(e) {
    e.preventDefault();
    if (selectedFiles.length === 0) return;

    var btn = document.getElementById('uploadSubmitBtn');
    var restore = Almusa.busy(btn, 'Mengunggah…');
    var csrfToken = document.querySelector('meta[name="csrf-token"]').getAttribute('content');

    var progWrap = document.getElementById('uploadProgressWrap');
    var progBar = document.getElementById('uploadProgressBar');
    var progPct = document.getElementById('uploadProgressPct');
    var progLabel = document.getElementById('uploadProgressLabel');
    if (progWrap) {
        progWrap.style.display = 'block';
        progBar.style.width = '0%';
        progPct.textContent = '0%';
    }

    var CHUNK_SIZE = 1536 * 1024; // 1.5 MB per chunk
    var totalBytes = selectedFiles.reduce(function (acc, f) { return acc + (f.size || 0); }, 0);
    var uploadedBytes = 0;

    try {
        for (var fIdx = 0; fIdx < selectedFiles.length; fIdx++) {
            var file = selectedFiles[fIdx];
            var totalChunks = Math.max(1, Math.ceil(file.size / CHUNK_SIZE));

            for (var chunkIndex = 0; chunkIndex < totalChunks; chunkIndex++) {
                var start = chunkIndex * CHUNK_SIZE;
                var end = Math.min(file.size, start + CHUNK_SIZE);
                var chunkBlob = file.slice(start, end);

                var formData = new FormData();
                formData.append('site_id', CURRENT_SITE_ID);
                formData.append('path', CURRENT_PATH);
                formData.append('chunk_index', chunkIndex);
                formData.append('total_chunks', totalChunks);
                formData.append('file_name', file.name);
                formData.append('_csrf_token', csrfToken);
                formData.append('file', chunkBlob, file.name);

                var uploadUrl = '/api/files/upload?site_id=' + encodeURIComponent(CURRENT_SITE_ID) + '&path=' + encodeURIComponent(CURRENT_PATH);
                var res = await fetch(uploadUrl, {
                    method: 'POST',
                    body: formData,
                    headers: {
                        'X-CSRF-TOKEN': csrfToken,
                        'Accept': 'application/json'
                    }
                });

                var data = null;
                try {
                    data = await res.json();
                } catch (parseErr) {
                    throw new Error('Respons server tidak valid (HTTP ' + res.status + ').');
                }

                if (!res.ok || !data || !data.success) {
                    throw new Error((data && data.error) ? data.error : ('Gagal mengunggah file (HTTP ' + res.status + ').'));
                }

                uploadedBytes += (end - start);
                var pct = totalBytes > 0 ? Math.min(100, Math.round((uploadedBytes / totalBytes) * 100)) : 100;
                if (progWrap) {
                    progBar.style.width = pct + '%';
                    progPct.textContent = pct + '% (' + (uploadedBytes / 1048576).toFixed(1) + ' / ' + (totalBytes / 1048576).toFixed(1) + ' MB)';
                    progLabel.textContent = 'Mengunggah ' + file.name + '…';
                }
            }
        }

        closeUploadModal();
        Almusa.toast(selectedFiles.length + ' file berhasil diunggah.', 'success');
        refreshDirectory();
    } catch (err) {
        Almusa.toast((err && err.message) ? err.message : 'Terjadi kesalahan saat mengunggah file.', 'error');
    } finally {
        restore();
    }
}

/* ----------------------------------------------------------- EXTRACT ARCHIVE */
function openExtractModal(zipPath, zipName) {
    document.getElementById('extractZipPath').value = zipPath;
    document.getElementById('extractZipName').textContent = zipName;
    document.getElementById('extractDestPath').value = CURRENT_PATH || '/';
    Almusa.openModal('extractModal');
}

function closeExtractModal() {
    Almusa.closeModal('extractModal');
}

async function submitExtract(e) {
    e.preventDefault();
    var zipPath = document.getElementById('extractZipPath').value;
    var dest = document.getElementById('extractDestPath').value.trim();
    var btn = document.getElementById('extractSubmitBtn');
    var restore = Almusa.busy(btn, 'Mengekstrak…');

    try {
        var res = await Almusa.post('/api/files/extract', {
            site_id: CURRENT_SITE_ID,
            file: zipPath,
            destination: dest
        });

        if (res.success) {
            closeExtractModal();
            Almusa.toast(res.message || 'Arsip berhasil diekstrak.', 'success');
            refreshDirectory();
        } else {
            Almusa.toast(res.error || 'Gagal mengekstrak arsip.', 'error');
        }
    } catch (err) {
        Almusa.toast('Kesalahan jaringan saat mengekstrak.', 'error');
    } finally {
        restore();
    }
}

/* ----------------------------------------------------------- INITIAL LOAD */
document.addEventListener('DOMContentLoaded', function () {
    loadDirectory(CURRENT_PATH);

    if (OPEN_EDIT_INITIAL === 'nginx') {
        setTimeout(openNginxEditor, 300);
    }
});
</script>
