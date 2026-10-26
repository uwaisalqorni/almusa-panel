/**
 * Almusa Server Control Panel — frontend core.
 *
 * API publik (window.Almusa):
 *   post(url, data)         → POST form + CSRF, selalu mengembalikan objek JSON
 *   toast(msg, type)        → notifikasi ringan (success | error | info | warning)
 *   confirm(opts)           → Promise<boolean>   (pengganti window.confirm)
 *   prompt(opts)            → Promise<string|null> (pengganti window.prompt)
 *   openModal(id) / closeModal(id)
 *   busy(button, label)     → menampilkan spinner; mengembalikan fungsi restore
 *   flash(msg, type)        → tampilkan toast setelah reload berikutnya
 *   reload(msg, type, ms)   → flash + reload
 *   copy(text)
 */
(function () {
    'use strict';

    var A = {};
    var ICONS = {
        success: 'check-circle',
        error: 'alert-circle',
        warning: 'alert-triangle',
        info: 'info'
    };

    /* ---------------------------------------------------------------- utils */
    function getCsrfToken() {
        var meta = document.querySelector('meta[name="csrf-token"]');
        return meta ? meta.getAttribute('content') : '';
    }

    function el(tag, cls, text) {
        var node = document.createElement(tag);
        if (cls) node.className = cls;
        if (text != null) node.textContent = text;
        return node;
    }

    function icon(name, cls) {
        var svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
        svg.setAttribute('class', 'icon' + (cls ? ' ' + cls : ''));
        svg.setAttribute('aria-hidden', 'true');
        var use = document.createElementNS('http://www.w3.org/2000/svg', 'use');
        use.setAttribute('href', '#i-' + name);
        svg.appendChild(use);
        return svg;
    }

    function escapeHtml(s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }
    A.escapeHtml = escapeHtml;

    /* ----------------------------------------------------------------- HTTP */
    A.post = async function (url, data) {
        var csrf = getCsrfToken();
        var body = new URLSearchParams();
        body.append('_csrf_token', csrf);
        Object.keys(data || {}).forEach(function (k) { body.append(k, data[k]); });

        try {
            var res = await fetch(url, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                    'X-CSRF-TOKEN': csrf,
                    'Accept': 'application/json'
                },
                body: body
            });
            try {
                return await res.json();
            } catch (e) {
                return { success: false, error: 'Respons server tidak valid (HTTP ' + res.status + ').' };
            }
        } catch (e) {
            return { success: false, error: 'Tidak dapat terhubung ke server. Periksa koneksi Anda.' };
        }
    };

    A.get = async function (url) {
        try {
            var res = await fetch(url, {
                headers: { 'Accept': 'application/json' }
            });
            try {
                return await res.json();
            } catch (e) {
                return { success: false, error: 'Respons server tidak valid (HTTP ' + res.status + ').' };
            }
        } catch (e) {
            return { success: false, error: 'Tidak dapat terhubung ke server. Periksa koneksi Anda.' };
        }
    };

    /* ---------------------------------------------------------------- toast */
    function toastStack() {
        var stack = document.getElementById('toastStack');
        if (!stack) {
            stack = el('div', 'toast-stack');
            stack.id = 'toastStack';
            stack.setAttribute('role', 'status');
            stack.setAttribute('aria-live', 'polite');
            document.body.appendChild(stack);
        }
        return stack;
    }

    A.toast = function (message, type, timeout) {
        type = ICONS[type] ? type : 'info';
        var t = el('div', 'toast toast-' + type);
        t.appendChild(icon(ICONS[type]));
        t.appendChild(el('div', 'toast-msg', message));

        var close = el('button', 'icon-btn');
        close.type = 'button';
        close.style.cssText = 'width:22px;height:22px;margin:-2px -4px 0 0';
        close.setAttribute('aria-label', 'Tutup');
        close.appendChild(icon('x'));
        t.appendChild(close);

        function dismiss() {
            t.classList.add('leaving');
            setTimeout(function () { t.remove(); }, 180);
        }
        close.addEventListener('click', dismiss);
        toastStack().appendChild(t);
        setTimeout(dismiss, timeout || (type === 'error' ? 7000 : 4200));
    };

    A.flash = function (message, type) {
        try { sessionStorage.setItem('almusa-flash', JSON.stringify({ m: message, t: type || 'success' })); } catch (e) { /* abaikan */ }
    };

    A.reload = function (message, type, delay) {
        if (message) A.flash(message, type);
        setTimeout(function () { location.reload(); }, delay == null ? 700 : delay);
    };

    /* --------------------------------------------------------------- dialogs */
    var dialogStack = [];

    function buildDialog(o, bodyNodes) {
        var backdrop = el('div', 'modal-backdrop show');
        backdrop.dataset.dynamic = '1';
        var modal = el('div', 'modal modal-sm');
        modal.setAttribute('role', 'dialog');
        modal.setAttribute('aria-modal', 'true');

        var head = el('div', 'modal-header');
        var titles = el('div');
        titles.appendChild(el('h2', 'modal-title', o.title));
        head.appendChild(titles);
        modal.appendChild(head);

        var body = el('div', 'modal-body');
        bodyNodes.forEach(function (n) { body.appendChild(n); });
        modal.appendChild(body);

        var foot = el('div', 'modal-footer');
        var cancel = el('button', 'btn btn-secondary', o.cancelText);
        cancel.type = 'button';
        var ok = el('button', 'btn ' + (o.danger ? 'btn-danger-solid' : 'btn-primary'), o.confirmText);
        ok.type = 'button';
        foot.appendChild(cancel);
        foot.appendChild(ok);
        modal.appendChild(foot);
        backdrop.appendChild(modal);
        document.body.appendChild(backdrop);

        return { backdrop: backdrop, cancel: cancel, ok: ok };
    }

    function runDialog(o, bodyNodes, getValue, validate) {
        return new Promise(function (resolve) {
            var d = buildDialog(o, bodyNodes);
            var previous = document.activeElement;

            function close(result) {
                var i = dialogStack.indexOf(entry);
                if (i > -1) dialogStack.splice(i, 1);
                d.backdrop.remove();
                if (previous && previous.focus) previous.focus();
                resolve(result);
            }
            var entry = { dismiss: function () { close(o.isPrompt ? null : false); } };
            dialogStack.push(entry);

            d.cancel.addEventListener('click', entry.dismiss);
            d.backdrop.addEventListener('mousedown', function (e) { if (e.target === d.backdrop) entry.dismiss(); });
            d.ok.addEventListener('click', function () {
                if (validate && !validate()) return;
                close(getValue());
            });
            d.backdrop.addEventListener('keydown', function (e) {
                if (e.key === 'Enter' && e.target.tagName !== 'BUTTON') { e.preventDefault(); d.ok.click(); }
            });

            var focusTarget = d.backdrop.querySelector('input') || d.ok;
            setTimeout(function () { focusTarget.focus(); }, 30);
        });
    }

    A.confirm = function (opts) {
        var o = Object.assign({ title: 'Konfirmasi', message: '', confirmText: 'Lanjutkan', cancelText: 'Batal', danger: false }, opts || {});
        var p = el('p', 'text-2', o.message);
        p.style.whiteSpace = 'pre-line';
        return runDialog(o, [p], function () { return true; });
    };

    A.prompt = function (opts) {
        var o = Object.assign({
            title: 'Masukkan nilai', message: '', label: '', placeholder: '', type: 'text',
            confirmText: 'Simpan', cancelText: 'Batal', minLength: 0, isPrompt: true
        }, opts || {});

        var nodes = [];
        if (o.message) {
            var p = el('p', 'text-2', o.message);
            p.style.marginBottom = '14px';
            nodes.push(p);
        }
        var field = el('div', 'field');
        if (o.label) field.appendChild(el('label', 'label', o.label));
        var input = el('input', 'input');
        input.type = o.type;
        input.placeholder = o.placeholder;
        input.autocomplete = 'off';
        field.appendChild(input);
        var err = el('div', 'help');
        err.style.color = 'var(--err)';
        err.hidden = true;
        field.appendChild(err);
        nodes.push(field);

        return runDialog(o, nodes, function () { return input.value; }, function () {
            var v = input.value.trim();
            if (!v) { err.textContent = 'Kolom ini wajib diisi.'; err.hidden = false; return false; }
            if (o.minLength && v.length < o.minLength) {
                err.textContent = 'Minimal ' + o.minLength + ' karakter.'; err.hidden = false; return false;
            }
            return true;
        });
    };

    /* ----------------------------------------------------- static modal helpers */
    A.openModal = function (id) {
        var m = document.getElementById(id);
        if (!m) return;
        m.classList.add('show');
        var f = m.querySelector('input:not([type=hidden]):not([type=checkbox]), select, textarea');
        if (f) setTimeout(function () { f.focus(); }, 30);
    };

    A.closeModal = function (id) {
        var m = document.getElementById(id);
        if (m) m.classList.remove('show');
    };

    A.busy = function (btn, label) {
        var original = btn.innerHTML;
        btn.disabled = true;
        btn.textContent = '';
        btn.appendChild(el('span', 'spinner'));
        if (label) btn.appendChild(document.createTextNode(label));
        return function () { btn.disabled = false; btn.innerHTML = original; };
    };

    A.copy = async function (text) {
        try {
            if (navigator.clipboard && window.isSecureContext) {
                await navigator.clipboard.writeText(text);
            } else {
                var ta = el('textarea');
                ta.value = text;
                ta.style.cssText = 'position:fixed;opacity:0';
                document.body.appendChild(ta);
                ta.select();
                document.execCommand('copy');
                ta.remove();
            }
            A.toast('Disalin ke clipboard.', 'success', 1800);
        } catch (e) {
            A.toast('Gagal menyalin teks.', 'error');
        }
    };

    window.Almusa = A;

    /* --------------------------------------------------------------- sidebar */
    window.toggleSidebar = function (force) {
        var open = typeof force === 'boolean' ? force : !document.body.classList.contains('sidebar-open');
        document.body.classList.toggle('sidebar-open', open);
    };

    /* --------------------------------------------------- system stats poller */
    function setMeter(fill, pct) {
        if (!fill) return;
        pct = Math.max(0, Math.min(100, Number(pct) || 0));
        fill.style.width = pct + '%';
        fill.classList.toggle('is-warn', pct >= 75 && pct < 90);
        fill.classList.toggle('is-danger', pct >= 90);
    }

    function setText(id, value) {
        var node = document.getElementById(id);
        if (node && value != null) node.textContent = value;
    }

    async function updateSystemStats() {
        try {
            var res = await fetch('/api/system/stats', { headers: { 'Accept': 'application/json' } });
            if (!res.ok) return;
            var json = await res.json();
            if (!json.success || !json.data) return;
            var d = json.data;

            setText('headerCpuVal', d.cpu.percent + '%');
            setMeter(document.getElementById('headerCpuFill'), d.cpu.percent);
            setText('headerRamVal', d.memory.percent + '%');
            setMeter(document.getElementById('headerRamFill'), d.memory.percent);

            setText('statCpuPercent', d.cpu.percent + '%');
            setMeter(document.getElementById('statCpuFill'), d.cpu.percent);
            setText('statRamPercent', d.memory.percent + '%');
            setMeter(document.getElementById('statRamFill'), d.memory.percent);
            if (d.memory.formatted) setText('statRamSub', d.memory.formatted);
            setText('statDiskPercent', d.disk.percent + '%');
            setMeter(document.getElementById('statDiskFill'), d.disk.percent);

            if (d.load) {
                setText('statLoad1m', d.load['1m']);
                setText('statLoad5m', d.load['5m']);
                setText('statLoad15m', d.load['15m']);
            }
        } catch (e) {
            console.warn('System stats fetch error:', e);
        }
    }

    /* ------------------------------------------------------ task terminal */
    var taskTimer = null;
    var autoScroll = true;
    var lastLog = null;

    function renderLog(text) {
        return text.split('\n').map(function (line) {
            var safe = escapeHtml(line);
            if (/^\[(ERROR|FAIL|FATAL)/i.test(line) || /^E: /.test(line)) return '<span class="log-err">' + safe + '</span>';
            if (/^\[SUCCESS\]/i.test(line)) return '<span class="log-ok">' + safe + '</span>';
            if (/^\[(INFO|System)\]/i.test(line)) return '<span class="log-info">' + safe + '</span>';
            return safe;
        }).join('\n');
    }

    window.openTaskTerminal = function () {
        var modal = document.getElementById('taskTerminalModal');
        if (!modal) return;
        modal.classList.add('show');
        pollTaskStatus();
        if (!taskTimer) taskTimer = setInterval(pollTaskStatus, 2000);
    };

    window.closeTaskTerminal = function () {
        var modal = document.getElementById('taskTerminalModal');
        if (!modal) return;
        modal.classList.remove('show');
        if (taskTimer) { clearInterval(taskTimer); taskTimer = null; }
    };

    window.toggleAutoScroll = function () {
        autoScroll = !autoScroll;
        var btn = document.getElementById('btnToggleScroll');
        if (btn) {
            btn.textContent = autoScroll ? 'Auto-scroll: aktif' : 'Auto-scroll: mati';
            btn.setAttribute('aria-pressed', String(autoScroll));
        }
    };

    window.clearTerminalLog = async function () {
        var ok = await A.confirm({
            title: 'Kosongkan log?',
            message: 'Riwayat log eksekusi terminal akan dihapus.',
            confirmText: 'Kosongkan',
            danger: true
        });
        if (!ok) return;
        var json = await A.post('/api/tasks/clear-log');
        if (json.success) {
            var terminal = document.getElementById('terminalLogBody');
            lastLog = '';
            if (terminal) terminal.innerHTML = '<span class="log-dim">[System] Log dikosongkan.</span>';
        } else {
            A.toast(json.error || 'Gagal membersihkan log.', 'error');
        }
    };

    async function pollTaskStatus() {
        try {
            var res = await fetch('/api/tasks/status', { headers: { 'Accept': 'application/json' } });
            if (!res.ok) return;
            var data = await res.json();
            if (!data.success) return;

            var bell = document.getElementById('taskBellBtn');
            var pending = document.getElementById('pendingTaskBadge');
            var spinner = document.getElementById('runningTaskSpinner');
            var busy = data.pending_count > 0 || data.running_task;

            if (bell) bell.classList.toggle('active', !!busy);
            if (pending) {
                pending.style.display = busy ? 'inline-grid' : 'none';
                if (busy) pending.textContent = data.pending_count + (data.running_task ? 1 : 0);
            }
            if (spinner) spinner.style.display = data.running_task ? 'inline-block' : 'none';

            var title = document.getElementById('terminalTaskTitle');
            var badge = document.getElementById('terminalStatusBadge');
            var body = document.getElementById('terminalLogBody');

            if (title) {
                if (data.running_task) title.textContent = data.running_task.title || 'Menjalankan tugas…';
                else if (data.last_task) title.textContent = data.last_task.title || 'Eksekusi terakhir';
                else title.textContent = 'Terminal eksekusi';
            }

            if (badge) {
                if (data.running_task) {
                    badge.className = 'badge badge-warning no-dot';
                    badge.innerHTML = '<span class="spinner" style="width:11px;height:11px;border-width:1.5px"></span> Berjalan';
                } else if (data.last_task) {
                    var done = data.last_task.status === 'completed';
                    badge.className = 'badge ' + (done ? 'badge-success' : 'badge-danger');
                    badge.textContent = done ? 'Selesai' : 'Gagal';
                } else {
                    badge.className = 'badge badge-neutral';
                    badge.textContent = 'Idle';
                }
            }

            if (body) {
                var log = data.log || '[System] Belum ada proses eksekusi yang berjalan.';
                if (log !== lastLog) {
                    lastLog = log;
                    body.innerHTML = renderLog(log);
                    if (autoScroll) body.scrollTop = body.scrollHeight;
                }
            }
        } catch (e) {
            console.warn('Task status polling error:', e);
        }
    }

    /* ------------------------------------------------- delegated UI behaviour */
    document.addEventListener('click', function (e) {
        var t = e.target;

        // Tampil/sembunyikan nilai rahasia di tabel
        var reveal = t.closest('[data-reveal]');
        if (reveal) {
            var box = reveal.closest('.secret');
            var txt = box.querySelector('.secret-text');
            var shown = box.classList.toggle('is-shown');
            txt.textContent = shown ? box.dataset.secret : '••••••••••';
            var use = reveal.querySelector('use');
            if (use) use.setAttribute('href', shown ? '#i-eye-off' : '#i-eye');
            return;
        }

        // Tampil/sembunyikan password pada input
        var toggle = t.closest('[data-toggle-password]');
        if (toggle) {
            var input = document.getElementById(toggle.getAttribute('data-toggle-password'));
            if (input) {
                var show = input.type === 'password';
                input.type = show ? 'text' : 'password';
                var u = toggle.querySelector('use');
                if (u) u.setAttribute('href', show ? '#i-eye-off' : '#i-eye');
            }
            return;
        }

        var copy = t.closest('[data-copy]');
        if (copy) { A.copy(copy.getAttribute('data-copy')); return; }

        // Klik pada latar modal statis menutup modal
        if (t.classList && t.classList.contains('modal-backdrop') && t.classList.contains('show') && !t.dataset.dynamic) {
            closeBackdrop(t);
            return;
        }

        // Tutup sidebar mobile setelah navigasi
        if (t.closest('.nav-item') || t.closest('.scrim')) window.toggleSidebar(false);
    });

    function closeBackdrop(b) {
        if (b.id === 'taskTerminalModal') window.closeTaskTerminal();
        else b.classList.remove('show');
    }

    document.addEventListener('keydown', function (e) {
        if (e.key !== 'Escape') return;
        if (dialogStack.length) { dialogStack[dialogStack.length - 1].dismiss(); return; }
        var open = document.querySelectorAll('.modal-backdrop.show:not([data-dynamic])');
        if (open.length) { closeBackdrop(open[open.length - 1]); return; }
        window.toggleSidebar(false);
    });

    document.addEventListener('DOMContentLoaded', function () {
        try {
            var raw = sessionStorage.getItem('almusa-flash');
            if (raw) {
                sessionStorage.removeItem('almusa-flash');
                var f = JSON.parse(raw);
                A.toast(f.m, f.t);
            }
        } catch (e) { /* abaikan */ }

        if (document.getElementById('taskBellBtn')) {
            pollTaskStatus();
            setInterval(pollTaskStatus, 5000);
        }
        if (document.getElementById('headerCpuVal')) {
            updateSystemStats();
            setInterval(updateSystemStats, 4000);
        }
    });
})();
