<?php
/**
 * Almusa Control Panel - Web File Manager & Code Editor View
 */
?>

<header class="page-header">
    <div>
        <h1 class="page-title">File Manager</h1>
        <p class="page-desc">Kelola file, edit konfigurasi (termasuk .env & Nginx), unggah file, dan ekstrak arsip zip.</p>
    </div>
    <div class="page-actions">
        <div class="fm-site-picker">
            <label for="siteSelector" class="sr-only">Pilih Website</label>
            <select id="siteSelector" class="select select-sm" onchange="switchSite(this.value)">
                <?php foreach ($allSites as $s): ?>
                    <option value="<?= (int)$s['id'] ?>" <?= (int)$s['id'] === (int)$site['id'] ? 'selected' : '' ?>>
                        <?= e($s['domain']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <a href="/sites" class="btn btn-secondary btn-sm">
            <?= ui_icon('globe') ?> Daftar website
        </a>
    </div>
</header>

<section class="card card-flush">
    <!-- Action Toolbar -->
    <div class="fm-bar">
        <div class="fm-actions">
            <button type="button" class="btn btn-primary btn-sm" onclick="openNewItemModal('file')">
                <?= ui_icon('file-plus') ?> File baru
            </button>
            <button type="button" class="btn btn-secondary btn-sm" onclick="openNewItemModal('dir')">
                <?= ui_icon('folder-plus') ?> Folder baru
            </button>
            <button type="button" class="btn btn-secondary btn-sm" onclick="openUploadModal()">
                <?= ui_icon('upload') ?> Unggah
            </button>
            <button type="button" class="btn btn-secondary btn-sm" onclick="openNginxEditor()">
                <?= ui_icon('sliders') ?> Nginx Conf
            </button>
            <button type="button" class="btn btn-ghost btn-icon btn-sm" onclick="refreshDirectory()" title="Muat ulang daftar file" aria-label="Refresh">
                <?= ui_icon('refresh') ?>
            </button>
        </div>

        <div class="fm-search-wrap">
            <?= ui_icon('search') ?>
            <input type="text" id="fmSearchInput" class="input" placeholder="Cari file di folder ini…" oninput="filterFiles(this.value)">
        </div>
    </div>

    <!-- Breadcrumbs & Path Navigator -->
    <div class="fm-crumbs-bar" id="fmCrumbsBar">
        <button type="button" class="btn btn-ghost btn-icon btn-sm" id="fmParentBtn" style="display:none;width:24px;height:24px;margin-right:4px;" onclick="goToParent()" title="Naik satu level" aria-label="Naik satu folder">
            <?= ui_icon('arrow-up') ?>
        </button>
        <span class="fm-crumb-link" onclick="navigateToPath('')" title="Root website">
            <?= ui_icon('folder') ?> <strong><?= e($site['domain']) ?></strong>
        </span>
        <span id="fmCrumbSegments"></span>
    </div>

    <!-- File Table -->
    <div class="card-body" style="padding:0;">
        <div class="table-wrap">
            <table class="table" id="fmTable">
                <thead>
                    <tr>
                        <th>Nama</th>
                        <th style="width:110px;">Ukuran</th>
                        <th style="width:90px;">Izin</th>
                        <th style="width:160px;">Dimodifikasi</th>
                        <th class="th-right" style="width:140px;">Aksi</th>
                    </tr>
                </thead>
                <tbody id="fmTableBody">
                    <tr>
                        <td colspan="5" style="text-align:center;padding:40px;color:var(--text-3);">
                            <?= ui_icon('refresh') ?> Memuat direktori…
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

            <!-- Error Banner (e.g. for Nginx syntax failure) -->
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
                    <input type="text" id="newItemName" class="input" placeholder="contoh: .env atau index.php" required autofocus>
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
     MODAL: UNGGAH FILE
     ========================================================================= -->
<div class="modal-backdrop" id="uploadModal">
    <div class="modal modal-md" role="dialog" aria-modal="true" aria-labelledby="uploadTitle">
        <div class="modal-header">
            <div>
                <h2 class="modal-title" id="uploadTitle">Unggah File</h2>
                <p class="modal-subtitle">Unggah berkas atau arsip .zip ke folder aktif.</p>
            </div>
            <button type="button" class="icon-btn" onclick="closeUploadModal()" aria-label="Tutup"><?= ui_icon('x') ?></button>
        </div>
        <form id="uploadForm" onsubmit="submitUpload(event)">
            <div class="modal-body">
                <div class="dropzone" id="uploadDropzone" onclick="document.getElementById('uploadFileInput').click()">
                    <div class="dropzone-icon"><?= ui_icon('upload', 'icon-xl') ?></div>
                    <div class="dropzone-title">Klik atau seret file ke sini untuk mengunggah</div>
                    <div class="dropzone-hint">Mendukung berkas tunggal, banyak berkas, atau arsip .zip</div>
                    <input type="file" id="uploadFileInput" name="files[]" multiple style="display:none;" onchange="handleFileSelect(this)">
                </div>

                <div id="uploadFileList" style="margin-top:16px;max-height:180px;overflow-y:auto;display:none;"></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeUploadModal()">Batal</button>
                <button type="submit" class="btn btn-primary" id="uploadSubmitBtn" disabled>Mulai unggah</button>
            </div>
        </form>
    </div>
</div>

<!-- =========================================================================
     MODAL: EKSTRAK ARSIP ZIP
     ========================================================================= -->
<div class="modal-backdrop" id="extractModal">
    <div class="modal modal-sm" role="dialog" aria-modal="true" aria-labelledby="extractTitle">
        <div class="modal-header">
            <div>
                <h2 class="modal-title" id="extractTitle">Ekstrak Arsip ZIP</h2>
                <p class="modal-subtitle">Ekstrak seluruh berkas dan atur permission secara otomatis.</p>
            </div>
            <button type="button" class="icon-btn" onclick="closeExtractModal()" aria-label="Tutup"><?= ui_icon('x') ?></button>
        </div>
        <form id="extractForm" onsubmit="submitExtract(event)">
            <input type="hidden" id="extractZipPath" value="">
            <div class="modal-body">
                <div class="field">
                    <label class="label">File ZIP</label>
                    <code id="extractZipName" style="display:block;padding:8px 12px;font-size:13px;"></code>
                </div>
                <div class="field">
                    <label class="label" for="extractDestPath">Direktori Tujuan</label>
                    <input type="text" id="extractDestPath" class="input" placeholder="Kosongkan untuk mengekstrak di folder ini">
                    <div class="help">Relatif terhadap root folder website.</div>
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
var CURRENT_SITE_ID = <?= (int)$site['id'] ?>;
var CURRENT_PATH = <?= json_encode((string)$initialPath) ?>;
var CURRENT_ITEMS = [];
var ACTIVE_EDITOR_FILE = null;
var ACTIVE_EDITOR_IS_NGINX = false;
var OPEN_EDIT_INITIAL = <?= json_encode((string)$openEdit) ?>;

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
    tbody.innerHTML = '<tr><td colspan="5" style="text-align:center;padding:32px;color:var(--text-3);">' +
        '<div style="display:inline-flex;align-items:center;gap:8px;">' +
        '<svg class="icon" style="animation:spin 1s linear infinite;" aria-hidden="true"><use href="#i-refresh"/></svg> ' +
        'Memuat direktori…</div></td></tr>';

    try {
        var res = await Almusa.get('/api/files/list?site_id=' + CURRENT_SITE_ID + '&path=' + encodeURIComponent(path));
        if (!res.success) {
            tbody.innerHTML = '<tr><td colspan="5" style="text-align:center;padding:32px;color:var(--err);">' +
                escapeHtml(res.error || 'Gagal memuat direktori.') + '</td></tr>';
            Almusa.toast(res.error || 'Gagal memuat direktori.', 'error');
            return;
        }

        CURRENT_PATH = res.current_path || '';
        CURRENT_ITEMS = res.items || [];
        renderBreadcrumbs(res.breadcrumbs || [], res.parent_path);
        renderTable(CURRENT_ITEMS);

        // Update URL query state without reload
        var newUrl = '/sites/files?site_id=' + CURRENT_SITE_ID + (CURRENT_PATH ? ('&path=' + encodeURIComponent(CURRENT_PATH)) : '');
        history.replaceState({ path: CURRENT_PATH }, '', newUrl);

    } catch (e) {
        console.error('loadDirectory error:', e);
        var msg = (e && e.message) ? ('Kesalahan: ' + escapeHtml(e.message)) : 'Kesalahan jaringan saat memuat direktori.';
        tbody.innerHTML = '<tr><td colspan="5" style="text-align:center;padding:32px;color:var(--err);">' + msg + '</td></tr>';
    }
}

function renderBreadcrumbs(crumbs, parentPath) {
    var parentBtn = document.getElementById('fmParentBtn');
    if (parentBtn) {
        parentBtn.style.display = (parentPath !== null) ? 'inline-grid' : 'none';
    }

    var container = document.getElementById('fmCrumbSegments');
    container.innerHTML = '';

    crumbs.forEach(function (crumb, idx) {
        var sep = document.createElement('span');
        sep.className = 'fm-crumb-sep';
        sep.textContent = '/';
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

function getFileIcon(item) {
    if (item.is_dir) return '<svg class="icon fm-icon-folder" aria-hidden="true"><use href="#i-folder"/></svg>';
    if (item.is_archive) return '<svg class="icon fm-icon-archive" aria-hidden="true"><use href="#i-archive"/></svg>';
    if (item.name === '.env' || item.name.startsWith('.env.')) return '<svg class="icon fm-icon-env" aria-hidden="true"><use href="#i-sliders"/></svg>';
    if (item.is_editable) return '<svg class="icon fm-icon-code" aria-hidden="true"><use href="#i-file-code"/></svg>';
    return '<svg class="icon fm-icon-file" aria-hidden="true"><use href="#i-file-text"/></svg>';
}

function renderTable(items) {
    var tbody = document.getElementById('fmTableBody');
    if (!items || items.length === 0) {
        tbody.innerHTML = '<tr><td colspan="5" style="text-align:center;padding:48px 16px;color:var(--text-3);">' +
            '<div style="font-size:14px;font-weight:500;margin-bottom:4px;">Folder ini kosong</div>' +
            '<div style="font-size:12px;">Unggah file atau buat file baru untuk memulai.</div>' +
            '</td></tr>';
        return;
    }

    tbody.innerHTML = '';
    items.forEach(function (item) {
        var tr = document.createElement('tr');
        tr.className = 'fm-item-row';
        tr.dataset.name = item.name.toLowerCase();

        // 1. Name & Icon
        var tdName = document.createElement('td');
        var nameWrap = document.createElement('span');
        nameWrap.className = 'fm-item-name';
        nameWrap.innerHTML = getFileIcon(item) + '<span>' + escapeHtml(item.name) + '</span>';

        if (item.is_dir) {
            nameWrap.onclick = function () { navigateToPath(item.path); };
        } else if (item.is_editable) {
            nameWrap.onclick = function () { openFileEditor(item.path); };
        } else if (item.is_archive) {
            nameWrap.onclick = function () { openExtractModal(item.path, item.name); };
        }
        tdName.appendChild(nameWrap);
        tr.appendChild(tdName);

        // 2. Size
        var tdSize = document.createElement('td');
        tdSize.className = 'mono';
        tdSize.style.fontSize = '12px';
        tdSize.style.color = item.is_dir ? 'var(--text-3)' : 'var(--text-2)';
        tdSize.textContent = item.size;
        tr.appendChild(tdSize);

        // 3. Perms
        var tdPerms = document.createElement('td');
        tdPerms.innerHTML = '<code style="font-size:11px;">' + escapeHtml(item.perms) + '</code>';
        tr.appendChild(tdPerms);

        // 4. Mtime
        var tdMtime = document.createElement('td');
        tdMtime.className = 'mono text-muted';
        tdMtime.style.fontSize = '12px';
        tdMtime.textContent = item.mtime_fmt;
        tr.appendChild(tdMtime);

        // 5. Actions
        var tdActions = document.createElement('td');
        tdActions.className = 'th-right';
        var actionsDiv = document.createElement('div');
        actionsDiv.className = 'cell-actions';
        actionsDiv.style.justifyContent = 'flex-end';

        if (item.is_editable) {
            var btnEdit = document.createElement('button');
            btnEdit.type = 'button';
            btnEdit.className = 'btn btn-ghost btn-icon btn-sm';
            btnEdit.title = 'Edit file';
            btnEdit.innerHTML = '<svg class="icon" aria-hidden="true"><use href="#i-edit"/></svg>';
            btnEdit.onclick = function () { openFileEditor(item.path); };
            actionsDiv.appendChild(btnEdit);
        }

        if (item.is_archive) {
            var btnExtract = document.createElement('button');
            btnExtract.type = 'button';
            btnExtract.className = 'btn btn-ghost btn-icon btn-sm';
            btnExtract.title = 'Ekstrak zip';
            btnExtract.innerHTML = '<svg class="icon" aria-hidden="true"><use href="#i-unzip"/></svg>';
            btnExtract.onclick = function () { openExtractModal(item.path, item.name); };
            actionsDiv.appendChild(btnExtract);
        }

        if (!item.is_dir) {
            var btnDown = document.createElement('a');
            btnDown.className = 'btn btn-ghost btn-icon btn-sm';
            btnDown.title = 'Unduh file';
            btnDown.href = '/api/files/download?site_id=' + CURRENT_SITE_ID + '&file=' + encodeURIComponent(item.path);
            btnDown.innerHTML = '<svg class="icon" aria-hidden="true"><use href="#i-download"/></svg>';
            actionsDiv.appendChild(btnDown);
        }

        var btnDel = document.createElement('button');
        btnDel.type = 'button';
        btnDel.className = 'btn btn-ghost btn-icon btn-sm';
        btnDel.title = 'Hapus';
        btnDel.innerHTML = '<svg class="icon" aria-hidden="true"><use href="#i-trash"/></svg>';
        btnDel.onclick = function () { deleteItem(item.path, item.is_dir); };
        actionsDiv.appendChild(btnDel);

        tdActions.appendChild(actionsDiv);
        tr.appendChild(tdActions);

        tbody.appendChild(tr);
    });
}

function filterFiles(query) {
    var q = query.trim().toLowerCase();
    var rows = document.querySelectorAll('#fmTableBody tr.fm-item-row');
    rows.forEach(function (row) {
        var name = row.dataset.name || '';
        row.style.display = (name.indexOf(q) !== -1) ? '' : 'none';
    });
}

/* ----------------------------------------------------------- CODE EDITOR */
function updateEditorStats() {
    var textarea = document.getElementById('editorTextarea');
    var val = textarea.value;
    var lines = val.split('\n');
    var lineCount = lines.length;
    var charCount = val.length;

    document.getElementById('editorStats').textContent = 'Baris: ' + lineCount + ' | Karakter: ' + charCount;

    // Update gutter line numbers
    var gutter = document.getElementById('editorGutter');
    var nums = [];
    for (var i = 1; i <= lineCount; i++) {
        nums.push(i);
    }
    gutter.textContent = nums.join('\n');
}

async function openFileEditor(filePath) {
    ACTIVE_EDITOR_FILE = filePath;
    ACTIVE_EDITOR_IS_NGINX = false;
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
    document.getElementById('editorFileTitle').textContent = 'Konfigurasi Nginx (Virtual Host)';
    document.getElementById('editorFilePath').textContent = '/etc/nginx/sites-available/' + document.getElementById('siteSelector').selectedOptions[0].text.trim() + '.conf';
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
        document.getElementById('editorStatusText').textContent = 'Konfigurasi aktif';
        updateEditorStats();
        textarea.focus();
    } catch (e) {
        Almusa.toast('Gagal memuat konfigurasi Nginx.', 'error');
        Almusa.closeModal('codeEditorModal');
    }
}

function closeCodeEditor() {
    Almusa.closeModal('codeEditorModal');
    ACTIVE_EDITOR_FILE = null;
    ACTIVE_EDITOR_IS_NGINX = false;
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
            Almusa.toast(res.message || 'File berhasil disimpan.', 'success');
            document.getElementById('editorStatusText').textContent = 'Tersimpan ' + new Date().toLocaleTimeString();
            restore();
            refreshDirectory();
        } else {
            Almusa.toast('Gagal menyimpan file.', 'error');
            if (res.error) {
                errBox.textContent = res.error;
                errBox.style.display = 'block';
            }
            restore();
        }
    } catch (e) {
        Almusa.toast('Kesalahan jaringan saat menyimpan file.', 'error');
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

    textarea.addEventListener('input', updateEditorStats);

    // Sync scroll between textarea and line numbers gutter
    textarea.addEventListener('scroll', function () {
        gutter.scrollTop = textarea.scrollTop;
    });

    // Support Tab key to insert 4 spaces
    textarea.addEventListener('keydown', function (e) {
        if (e.key === 'Tab') {
            e.preventDefault();
            var start = this.selectionStart;
            var end = this.selectionEnd;
            this.value = this.value.substring(0, start) + '    ' + this.value.substring(end);
            this.selectionStart = this.selectionEnd = start + 4;
            updateEditorStats();
        }

        // Ctrl + S / Cmd + S to save
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
    document.getElementById('newItemName').placeholder = isDir ? 'contoh: config atau assets' : 'contoh: .env atau index.php';
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
            restore();
        }
    } catch (err) {
        Almusa.toast('Kesalahan jaringan.', 'error');
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

/* ----------------------------------------------------------- UPLOAD MODAL */
var selectedFiles = [];

function openUploadModal() {
    selectedFiles = [];
    document.getElementById('uploadFileInput').value = '';
    document.getElementById('uploadFileList').innerHTML = '';
    document.getElementById('uploadFileList').style.display = 'none';
    document.getElementById('uploadSubmitBtn').disabled = true;
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

    var formData = new FormData();
    formData.append('site_id', CURRENT_SITE_ID);
    formData.append('path', CURRENT_PATH);
    formData.append('_csrf_token', document.querySelector('meta[name="csrf-token"]').getAttribute('content'));

    selectedFiles.forEach(function (f) {
        formData.append('files[]', f);
    });

    try {
        var res = await fetch('/api/files/upload', {
            method: 'POST',
            body: formData,
            headers: {
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute('content'),
                'Accept': 'application/json'
            }
        });
        var data = await res.json();
        if (data.success) {
            closeUploadModal();
            Almusa.toast(data.message || 'File berhasil diunggah.', 'success');
            refreshDirectory();
        } else {
            Almusa.toast(data.error || 'Gagal mengunggah file.', 'error');
            restore();
        }
    } catch (err) {
        Almusa.toast('Terjadi kesalahan saat mengunggah file.', 'error');
        restore();
    }
}

/* ----------------------------------------------------------- EXTRACT ZIP */
function openExtractModal(zipPath, zipName) {
    document.getElementById('extractZipPath').value = zipPath;
    document.getElementById('extractZipName').textContent = zipName;
    document.getElementById('extractDestPath').value = CURRENT_PATH;
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
            Almusa.toast(res.error || 'Gagal mengekstrak arsip zip.', 'error');
            restore();
        }
    } catch (err) {
        Almusa.toast('Kesalahan jaringan.', 'error');
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
