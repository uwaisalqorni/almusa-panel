/**
 * Almusa Server Control Panel - Core Frontend Interaction Script
 */

(function () {
    'use strict';

    // Get CSRF Token from meta tag
    const getCsrfToken = () => {
        const meta = document.querySelector('meta[name="csrf-token"]');
        return meta ? meta.getAttribute('content') : '';
    };

    // System Monitor Real-Time Poller
    let statsTimer = null;
    const updateSystemStats = async () => {
        try {
            const res = await fetch('/api/system/stats', {
                headers: { 'Accept': 'application/json' }
            });
            if (!res.ok) return;
            const json = await res.json();
            if (json.success && json.data) {
                const data = json.data;

                // CPU
                const cpuVal = document.getElementById('statCpuPercent');
                const cpuFill = document.getElementById('statCpuFill');
                const cpuHeaderVal = document.getElementById('headerCpuVal');
                if (cpuVal) cpuVal.textContent = data.cpu.percent + '%';
                if (cpuFill) {
                    cpuFill.style.width = data.cpu.percent + '%';
                    cpuFill.className = 'gauge-fill' + (data.cpu.percent > 85 ? ' warning' : '');
                }
                if (cpuHeaderVal) cpuHeaderVal.textContent = data.cpu.percent + '%';

                // RAM
                const ramVal = document.getElementById('statRamPercent');
                const ramFill = document.getElementById('statRamFill');
                const ramSub = document.getElementById('statRamSub');
                const ramHeaderVal = document.getElementById('headerRamVal');
                if (ramVal) ramVal.textContent = data.memory.percent + '%';
                if (ramFill) {
                    ramFill.style.width = data.memory.percent + '%';
                    ramFill.className = 'gauge-fill' + (data.memory.percent > 85 ? ' warning' : '');
                }
                if (ramSub && data.memory.formatted) ramSub.textContent = data.memory.formatted;
                if (ramHeaderVal) ramHeaderVal.textContent = data.memory.percent + '%';

                // Disk
                const diskVal = document.getElementById('statDiskPercent');
                const diskFill = document.getElementById('statDiskFill');
                if (diskVal) diskVal.textContent = data.disk.percent + '%';
                if (diskFill) {
                    diskFill.style.width = data.disk.percent + '%';
                    diskFill.className = 'gauge-fill' + (data.disk.percent > 90 ? ' warning' : '');
                }

                // Load Average
                const load1 = document.getElementById('statLoad1m');
                const load5 = document.getElementById('statLoad5m');
                const load15 = document.getElementById('statLoad15m');
                if (load1) load1.textContent = data.load['1m'];
                if (load5) load5.textContent = data.load['5m'];
                if (load15) load15.textContent = data.load['15m'];
            }
        } catch (e) {
            console.warn('System stats fetch error:', e);
        }
    };

    // Task Terminal Modal & Real-time Task Poller
    let taskTimer = null;
    let autoScroll = true;

    window.openTaskTerminal = function () {
        const modal = document.getElementById('taskTerminalModal');
        if (modal) {
            modal.classList.add('show');
            pollTaskStatus(true);
            if (!taskTimer) {
                taskTimer = setInterval(() => pollTaskStatus(false), 2000);
            }
        }
    };

    window.closeTaskTerminal = function () {
        const modal = document.getElementById('taskTerminalModal');
        if (modal) {
            modal.classList.remove('show');
            if (taskTimer) {
                clearInterval(taskTimer);
                taskTimer = null;
            }
        }
    };

    window.toggleAutoScroll = function () {
        autoScroll = !autoScroll;
        const btn = document.getElementById('btnToggleScroll');
        if (btn) {
            btn.textContent = autoScroll ? 'Auto-scroll: ON' : 'Auto-scroll: OFF';
            btn.className = autoScroll ? 'btn btn-secondary btn-sm' : 'btn btn-sm btn-secondary text-muted';
        }
    };

    window.clearTerminalLog = async function () {
        if (!confirm('Kosongkan riwayat log eksekusi terminal?')) return;
        try {
            const res = await fetch('/api/tasks/clear-log', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                    'X-CSRF-TOKEN': getCsrfToken()
                },
                body: '_csrf_token=' + encodeURIComponent(getCsrfToken())
            });
            const json = await res.json();
            if (json.success) {
                const terminal = document.getElementById('terminalLogBody');
                if (terminal) terminal.textContent = '[System] Log dikosongkan.\n';
            }
        } catch (e) {
            alert('Gagal membersihkan log.');
        }
    };

    const pollTaskStatus = async (initial = false) => {
        try {
            const res = await fetch('/api/tasks/status', {
                headers: { 'Accept': 'application/json' }
            });
            if (!res.ok) return;
            const data = await res.json();
            if (!data.success) return;

            // Update topbar task indicator
            const bellBtn = document.getElementById('taskBellBtn');
            const pendingBadge = document.getElementById('pendingTaskBadge');
            const runningSpinner = document.getElementById('runningTaskSpinner');

            if (data.pending_count > 0 || data.running_task) {
                if (bellBtn) bellBtn.classList.add('active');
                if (pendingBadge) {
                    pendingBadge.textContent = data.pending_count + (data.running_task ? 1 : 0);
                    pendingBadge.style.display = 'inline-block';
                }
                if (runningSpinner) runningSpinner.style.display = data.running_task ? 'inline-block' : 'none';
            } else {
                if (bellBtn) bellBtn.classList.remove('active');
                if (pendingBadge) pendingBadge.style.display = 'none';
                if (runningSpinner) runningSpinner.style.display = 'none';
            }

            // Update Terminal modal elements
            const terminalTitle = document.getElementById('terminalTaskTitle');
            const terminalStatusBadge = document.getElementById('terminalStatusBadge');
            const terminalBody = document.getElementById('terminalLogBody');

            if (terminalTitle) {
                if (data.running_task) {
                    terminalTitle.textContent = data.running_task.title || 'Menjalankan tugas...';
                } else if (data.last_task) {
                    terminalTitle.textContent = 'Selesai: ' + (data.last_task.title || 'Eksekusi Terakhir');
                } else {
                    terminalTitle.textContent = 'Panel Terminal Log';
                }
            }

            if (terminalStatusBadge) {
                if (data.running_task) {
                    terminalStatusBadge.className = 'badge badge-warning';
                    terminalStatusBadge.innerHTML = '<span class="badge-spinner"></span> Sedang Berjalan';
                } else if (data.last_task) {
                    if (data.last_task.status === 'completed') {
                        terminalStatusBadge.className = 'badge badge-success';
                        terminalStatusBadge.textContent = 'Sukses';
                    } else {
                        terminalStatusBadge.className = 'badge badge-danger';
                        terminalStatusBadge.textContent = 'Gagal';
                    }
                } else {
                    terminalStatusBadge.className = 'badge badge-neutral';
                    terminalStatusBadge.textContent = 'Idle';
                }
            }

            if (terminalBody) {
                const newLog = data.log || '[System] Belum ada proses eksekusi task yang berjalan.';
                if (terminalBody.textContent !== newLog) {
                    terminalBody.textContent = newLog;
                    if (autoScroll) {
                        terminalBody.scrollTop = terminalBody.scrollHeight;
                    }
                }
            }
        } catch (e) {
            console.warn('Task status polling error:', e);
        }
    };

    // Close modal on escape key
    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape') {
            closeTaskTerminal();
        }
    });

    // Initialize on DOM ready
    document.addEventListener('DOMContentLoaded', () => {
        // Run initial tasks and stats check
        pollTaskStatus(true);
        setInterval(() => pollTaskStatus(false), 5000);

        if (document.getElementById('statCpuPercent')) {
            updateSystemStats();
            statsTimer = setInterval(updateSystemStats, 4000);
        }
    });
})();
