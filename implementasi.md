# Blueprint & Rencana Implementasi Produksi: Almusa Control Panel
> **Dokumen Arsitektur, Standar Keamanan, Optimasi Performa, dan Roadmap Menuju Versi Produksi Siap Pakai (Production-Ready Release)**  
> Versi Dokumen: 2.0.0 · Tanggal Rilis: 30 Oktober 2026 · Target Rilis: v1.1.0 – v2.0.0

> **Status implementasi (audit kode 2 Nov 2026):** ✅ selesai · 🟡 sebagian · ❌ belum.  
> Detail temuan keamanan & prioritas: lihat laporan analisa. Urutan kerja: perbaiki dulu jalur root/hak akses (pemisahan user panel vs website), bypass rate limiter, Adminer, validasi path, dan enkripsi rahasia — baru 2FA & audit log.

---

## 📋 Daftar Isi
1. [Ringkasan Eksekutif & Sasaran Mutu](#1-ringkasan-eksekutif--sasaran-mutu)
2. [Pilar 1: Keamanan Tingkat Tinggi (Enterprise-Grade Security)](#2-pilar-1-keamanan-tingkat-tinggi-enterprise-grade-security)
3. [Pilar 2: Kecepatan & Responsivitas (Ultra-Low Latency & Zero-Lag UI)](#3-pilar-2-kecepatan--responsivitas-ultra-low-latency--zero-lag-ui)
4. [Pilar 3: Portabilitas & Kemudahan Deployment Multi-Server](#4-pilar-3-portabilitas--kemudahan-deployment-multi-server)
5. [Pilar 4: Fitur Unggulan Produksi (Production-Grade Features)](#5-pilar-4-fitur-unggulan-produksi-production-grade-features)
6. [Roadmap & Fase Implementasi](#6-roadmap--fase-implementasi)
7. [Spesifikasi Teknis Modul & Contoh Implementasi Kode](#7-spesifikasi-teknis-modul--contoh-implementasi-kode)

---

## 1. Ringkasan Eksekutif & Sasaran Mutu

Almusa Server Control Panel dirancang dengan filosofi **SLEMP (Simple, Lightweight, Enterprise-ready, Modular, Performant)**. Agar panel ini siap diluncurkan secara publik dan dapat dioperasikan oleh ribuan server pengguna dengan tingkat kepuasan tinggi, arsitektur harus memenuhi target mutu berikut:

| Metrik Kualitas | Target Saat Ini | Target Produksi (v2.0) | Metode Pengukuran |
| :--- | :--- | :--- | :--- |
| **Konsumsi RAM Idle Panel** | ~45 MB | **< 28 MB** | `ps_mem` (Nginx + PHP-FPM Panel + Worker) |
| **Response Time Dashboard API** | ~85 ms | **< 15 ms** | Direct parsing `/proc/*` tanpa sub-shell |
| **Pembaruan Log Task** | Polling HTTP (2s) | **Realtime SSE (< 100ms)** | Server-Sent Events (`text/event-stream`) |
| **Keamanan Autentikasi** | Single Password | **2FA (TOTP RFC 6238) + Brute-force Shield** | Rate Limiting IP & Sliding Cooldown |
| **Integritas Transaksi DB** | Basic WAL | **WAL + Auto-Checkpoint + S3 Cloud Sync** | Atomic Backup & WAL Checkpoint Script |
| **Kompatibilitas Distro** | Ubuntu 22.04+ | **Ubuntu 20.04/22.04/24.04 & Debian 11/12** | Matrix Automated CI/CD Testing |

---

## 2. Pilar 1: Keamanan Tingkat Tinggi (Enterprise-Grade Security)

Sebagai aplikasi yang mengontrol hak akses server (`root`), keamanan adalah prioritas mutlak.

```mermaid
flowchart TD
    Client["Koneksi Browser / Client"] --> Fire["Firewall UFW + Nginx Filter"]
    Fire --> Rate["Rate Limiter (Max 5x Fail / 15 Min)"]
    Rate --> Ent["Secret Entrance Token Verification"]
    Ent --> Auth["Autentikasi: Password (Bcrypt) + 2FA TOTP"]
    Auth --> Session["Session Hardening (Strict, HttpOnly, Fingerprint)"]
    Session --> Audit["Audit Logger (IP, Geo, Action, Result)"]
    Audit --> Core["Controller & Task Daemon (Escaped Shell Only)"]
```

### 2.1. Brute-Force Shield & Sliding-Window Rate Limiting `🟡 SEBAGIAN`
> ✅ `RateLimiter` aktif di login. Catatan: IP klien kini hanya dari `REMOTE_ADDR` (header `X-Forwarded-For` tidak lagi dipercaya). Belum ada limit per-username dan `limit_req` Nginx untuk seluruh endpoint.
- **Mekanisme:** Batasi percobaan login maksimal 5 kali dalam rentang waktu 15 menit per IP address.
- **Implementasi:** Tabel SQLite `login_attempts` (`ip_address`, `attempt_count`, `locked_until`, `last_attempt`).
- **Respon:** Jika melebihi batas, kembalikan HTTP 429 Too Many Requests dengan pesan hitung mundur lockout.

### 2.2. Two-Factor Authentication (2FA / TOTP RFC 6238) `❌ BELUM`
- Menyediakan autentikasi dua faktor berbasis waktu (Google Authenticator, Microsoft Authenticator, Authy, Aegis).
- Menggunakan library murni PHP tanpa dependensi Composer berat (hanya enkripsi HMAC-SHA1 bawaan PHP).
- Menyediakan 8 recovery codes sekali pakai saat pertama kali 2FA diaktifkan.

### 2.3. Session Hardening & Anti-Session Hijacking `🟡 SEBAGIAN`
> ✅ Cookie `HttpOnly` + `SameSite=Strict` + `Secure` (bila HTTPS). ✅ Auto-logout inactivity 30 menit (`Auth::INACTIVITY_TIMEOUT`). ✅ Revokasi sesi lintas perangkat saat password diubah (`session_version`). ✅ Enkripsi master key (`Crypto` libsodium/AES-256-GCM) untuk kredensial & masking DOM form. ❌ Session fingerprinting (User-Agent/IP /24).
- **Cookie Security:** Wajib menggunakan flag `HttpOnly`, `SameSite=Strict`, dan `Secure` (jika HTTPS aktif).
- **Session Fingerprinting:** Ikat ID sesi dengan kombinasi hash `User-Agent` + Blok IP Subnet (`/24`). Jika pengguna mengakses dari jaringan atau browser yang berbeda secara tiba-tiba, sesi otomatis diakhiri (revoke).
- **Auto-Logout Inactivity:** Timeout otomatis setelah 30 menit tidak ada interaksi pengguna di UI.

### 2.4. Audit Logging Terpusat `❌ BELUM`
> Hanya ada `data/panel_security.log` untuk event login. Tabel `audit_logs` belum ada.
Setiap operasi sensitif harus tercatat secara permanen dan tidak dapat dihapus melalui antarmuka web:
- Penambahan/Penghapusan Website & Database
- Reset Password & Penggantian Secret Entrance
- Eksekusi Task CLI & Patch Keamanan
- Informasi yang dicatat: `timestamp`, `user_id`, `action`, `target`, `ip_address`, `user_agent`, `status_code`.

### 2.5. Disposable Token untuk Database Adminer `🟡 SEBAGIAN`
> ✅ SSO token sekali pakai (120 detik) di `DatabaseController::ssoToken`. Akses langsung `adminer-core.php` kini diblokir di Nginx dan fallback auto-login root dihapus (wajib token SSO).
- Berkas `adminer-core.php` tidak boleh diakses publik secara bebas.
- Saat pengguna mengklik *"Buka Adminer"*, panel menghasilkan token sekali pakai yang berlaku selama 5 menit:
  `http://<IP>:<PORT>/adminer/?token=a8f9c1...`
- Token divalidasi oleh session handler panel sebelum antarmuka Adminer dibuka.

---

## 3. Pilar 2: Kecepatan & Responsivitas (Ultra-Low Latency & Zero-Lag UI)

Panel harus terasa instan (*snappy*), bahkan saat dibuka dari koneksi internet seluler atau dijalankan di VPS spek rendah (1 vCPU, 1 GB RAM).

### 3.1. Parsing Langsung Virtual Filesystem Linux (`/proc/*`) `✅ SELESAI`
> Diimplementasikan di `SystemMonitor` (commit `6ee7831`). Angka benchmark ~8ms belum didukung skrip pengukur.
- **Masalah Saat Ini:** Pembacaan CPU, RAM, Disk, dan Traffic IO melalui eksekusi sub-shell shell (`free -m`, `mpstat`, `vmstat`, `netstat`) membebani CPU karena fork process berulang setiap 2 detik.
- **Solusi Produksi:** Baca langsung berkas memori virtual kernel Linux menggunakan `file_get_contents()`:
  - RAM: Baca `/proc/meminfo` (hitung `MemTotal`, `MemAvailable`, `Cached`, `Buffers`).
  - CPU Usage: Baca `/proc/stat` (bandingkan selisih nilai `user`, `nice`, `system`, `idle` antar-interval).
  - Load Average: Baca `/proc/loadavg` (tanpa spawn shell).
  - Network Traffic: Baca `/proc/net/dev`.
- **Hasil Benchmark:** Response time API monitoring berkurang dari ~85ms menjadi **~8ms** (90% lebih efisien!).

### 3.2. Server-Sent Events (SSE) untuk Task Streaming Log `❌ BELUM`
> Masih polling 2 detik. Alternatif lebih ringan: polling dengan offset byte. Jika SSE: wajib cek auth dan `session_write_close()`.
- Gantikan polling AJAX berkala (`setInterval` fetch `/api/tasks`) dengan koneksi persisten SSE:
  `GET /api/tasks/stream?task_id=123`
- Backend mengalirkan (*stream*) baris log baru dari berkas `/www/panel/data/panelExec.log` secara *real-time*.
- Terminal output di dashboard langsung bergerak turun (*auto-scroll*) seketika baris log dicetak oleh bash.

### 3.3. Optimasi Cache Statis & Aset Nginx `🟡 SEBAGIAN`
> ✅ Gzip, `expires 30d`. ❌ Brotli, ❌ `immutable`.
Pada [config/nginx-panel.conf](file:///home/afandi/almusa-panel/config/nginx-panel.conf):
- Aktifkan Gzip/Brotli compression untuk JSON, SVG, CSS, dan JS.
- Berikan header `Cache-Control: public, max-age=2592000, immutable` untuk berkas statis ikon, font, dan stylesheet.

---

## 4. Pilar 3: Portabilitas & Kemudahan Deployment Multi-Server

### 4.1. Idempotent & Self-Healing Installer `[SELESAI / COMPLETED]`
Installer [scripts/install.sh](file:///home/afandi/almusa-panel/scripts/install.sh) telah direfaktor dengan arsitektur tangguh yang aman dijalankan berulang kali (*idempotent*):
- **Deteksi Instalasi Sebelumnya:** Jika `/www/panel/data/panel.db` sudah ada, direktori `data/` diproteksi secara otomatis dari penimpaan file kosong, snapshot SQLite WAL-safe dicadangkan ke `/www/backup/`, dan skema diperbarui via `Database::autoMigrate()` tanpa menyentuh password admin atau kredensial yang ada.
- **Deteksi Konflik Port Cerdas (Self-Healing):** Sebelum bind port, sistem memeriksa status port via `ss -tuln`, `lsof`, dan `fuser`. Jika port sedang digunakan oleh aplikasi lain (bukan milik Nginx Almusa), installer memberikan peringatan dan secara cerdas mengalihkan listen port ke port aman berikutnya (range 64449..65000), serta memperbarui Nginx, database, dan UFW secara otomatis.
- **Pembersihan Bersih (Clean Uninstaller):** Skrip resmi [scripts/uninstall.sh](file:///home/afandi/almusa-panel/scripts/uninstall.sh) dan perintah `mypanel uninstall` telah disediakan untuk membersihkan panel secara total (mengarsipkan snapshot darurat ke `/root/`, menghapus service systemd, konfigurasi Nginx, rule UFW, cron job sistem, sudoers, dan symlink global) tanpa mengganggu website di `/www/wwwroot` maupun database MySQL.
- **Otomasi Multi-Server:** Mendukung opsi CLI `-p/--port <PORT>`, `-e/--entrance <PATH>`, `-y/--yes` (non-interaktif), dan `--force` untuk kemudahan deployment massal via script automation / cloud-init.

### 4.2. Skrip Pemulihan Bencana CLI (Disaster Recovery Mode) `❌ BELUM`
> `mypanel doctor` dan `mypanel repair` belum ada. `mypanel backup` tersedia sebagai pengganti sebagian `backup-now`.
Jika web interface tidak dapat diakses (misal Nginx korup akibat salah edit), sediakan perintah CLI darurat:
```bash
mypanel doctor       # Mendiagnosa socket, port, permission, dan status DB secara otomatis
mypanel repair       # Memperbaiki permission www-data, regenerate socket, dan restart service
mypanel backup-now   # Dump instan SQLite panel dan seluruh MariaDB ke /www/backup
```

---

## 5. Pilar 4: Fitur Unggulan Produksi (Production-Grade Features)

### 5.1. Automated Remote Cloud Backup (S3 / R2 / Google Drive) `🟡 SEBAGIAN`
> ✅ S3Client, BackupEngine, cron backup. ❌ Retensi 7/4/2 (saat ini berbasis hari), ❌ SFTP, ❌ enkripsi secret key.
- Cadangkan database MySQL dan arsip file website secara terjadwal (Cron).
- Integrasikan dengan protokol standar AWS S3 (Cloudflare R2, AWS S3, Wasabi, MinIO) atau SFTP Remote Server.
- Otomasi rotasi retensi: simpan 7 backup harian terakhir, 4 backup mingguan, dan 2 backup bulanan.

### 5.2. Database Management Lanjutan `❌ BELUM`
- **Slow Query Monitor:** Deteksi query MariaDB yang berjalan lambat (> 2 detik).
- **1-Click Optimize & Repair Table:** Menjalankan `OPTIMIZE TABLE` dan `CHECK TABLE` dari dashboard.
- **Export/Import SQL GUI:** Fasilitas import file `.sql` atau `.sql.gz` berukuran besar via chunked upload tanpa terhalang `upload_max_filesize`.

### 5.3. 1-Click Staging & Kloning Website `❌ BELUM`
- Fasilitas membuat replika situs produksi ke sub-domain staging (misal: `staging.domain.com`) lengkap dengan duplikasi file dan database.
- Sinkronisasi instan string URL database untuk CMS populer (WordPress, Laravel `.env`).

### 5.4. Watchdog 2.0 (Proactive Server Healing) `🟡 SEBAGIAN`
> ✅ Auto-restart service, alert disk (ambang 85%). ❌ OOM prevention. Catatan: `drop_caches` otomatis tidak disarankan (menurunkan performa, tidak mencegah OOM).
- **OOM Prevention:** Jika pemakaian RAM server menyentuh 92%, Watchdog secara otomatis membersihkan buffer cache Linux (`echo 3 > /proc/sys/vm/drop_caches`) dan mendaur ulang worker PHP-FPM yang membengkak (*memory leak*).
- **Disk Space Exhaustion Alert:** Peringatan dini di dashboard jika kapasitas partisi `/` tersisa kurang dari 10%.

---

## 6. Roadmap & Fase Implementasi

```mermaid
gantt
    title Roadmap Pengembangan Almusa Panel Menuju Produksi Siap Pakai
    dateFormat  YYYY-MM-DD
    section Fase 1: Hardening
    Brute-Force & Rate Limiting       :done,    des1, 2026-11-01, 5d
    Audit Logger Terpusat             :active,  des2, 2026-11-06, 4d
    Session Fingerprint & Timeout     :         des3, 2026-11-10, 3d
    section Fase 2: Performa
    Direct /proc/ Engine Monitor      :         des4, 2026-11-13, 4d
    Server-Sent Events (SSE) Task Log :         des5, 2026-11-17, 5d
    Disposable Token Adminer          :         des6, 2026-11-22, 3d
    section Fase 3: Pemulihan
    CLI Doctor & Repair Script        :         des7, 2026-11-25, 4d
    S3/R2 Remote Cloud Backup         :         des8, 2026-11-29, 6d
    section Fase 4: Fitur Ekstra
    2FA TOTP RFC 6238                 :         des9, 2026-12-05, 5d
    Staging / Clone Website           :         des10, 2026-12-10, 6d
```

---

## 7. Spesifikasi Teknis Modul & Contoh Implementasi Kode

### 7.1. Skema Basis Data Tambahan (SQLite Migration) `🟡 SEBAGIAN`
> ✅ `login_attempts` (kolom: `attempts`, `last_attempt_at`, `locked_until`). ❌ `audit_logs`, ❌ kolom 2FA `users`, ❌ `backup_destinations` (konfigurasi cloud memakai tabel `settings`).

Tambahkan tabel berikut ke dalam `autoMigrate()` di [app/Core/Database.php](file:///home/afandi/almusa-panel/app/Core/Database.php):

```sql
-- 1. Rate Limiting Brute Force Protection
CREATE TABLE IF NOT EXISTS login_attempts (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    ip_address TEXT NOT NULL,
    attempt_count INTEGER NOT NULL DEFAULT 1,
    locked_until DATETIME DEFAULT NULL,
    last_attempt DATETIME DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_login_attempts_ip ON login_attempts(ip_address);

-- 2. Audit Trails (Append-Only)
CREATE TABLE IF NOT EXISTS audit_logs (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER,
    action TEXT NOT NULL,
    details TEXT,
    ip_address TEXT NOT NULL,
    user_agent TEXT,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_audit_logs_created ON audit_logs(created_at);

-- 3. 2FA Configuration
ALTER TABLE users ADD COLUMN totp_secret TEXT DEFAULT NULL;
ALTER TABLE users ADD COLUMN totp_enabled INTEGER NOT NULL DEFAULT 0;
ALTER TABLE users ADD COLUMN backup_codes TEXT DEFAULT NULL;

-- 4. Cloud Backup Storage Endpoints
CREATE TABLE IF NOT EXISTS backup_destinations (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    provider TEXT NOT NULL DEFAULT 's3', -- 's3', 'r2', 'sftp'
    name TEXT NOT NULL,
    endpoint TEXT,
    bucket TEXT,
    access_key TEXT,
    secret_key TEXT,
    path_prefix TEXT DEFAULT 'almusa-backups',
    status TEXT NOT NULL DEFAULT 'active',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
);
```

### 7.2. Implementasi High-Performance Direct `/proc/*` Parser `✅ SELESAI`
> Fungsi sudah ada, digabung ke `SystemMonitor` (bukan kelas `HighPerformanceMonitor`).

Tambahkan kelas pembaca memori langsung di [app/Core/SystemMonitor.php](file:///home/afandi/almusa-panel/app/Core/SystemMonitor.php):

```php
namespace App\Core;

class HighPerformanceMonitor
{
    /**
     * Membaca statistik RAM langsung dari kernel virtual memory tanpa fork shell.
     * Menghemat waktu eksekusi dari ~40ms menjadi ~0.8ms.
     */
    public static function getFastMemoryStats(): array
    {
        $memInfoFile = '/proc/meminfo';
        if (!is_readable($memInfoFile)) {
            return ['total' => 0, 'free' => 0, 'used' => 0, 'percent' => 0];
        }

        $data = @file_get_contents($memInfoFile);
        $lines = explode("\n", (string)$data);
        $stats = [];

        foreach ($lines as $line) {
            if (empty($line)) continue;
            list($key, $val) = explode(':', $line, 2);
            $stats[trim($key)] = (int)preg_replace('/\D/', '', $val);
        }

        $total = ($stats['MemTotal'] ?? 0) * 1024;
        $available = ($stats['MemAvailable'] ?? ($stats['MemFree'] ?? 0)) * 1024;
        $used = max(0, $total - $available);
        $percent = $total > 0 ? round(($used / $total) * 100, 1) : 0;

        return [
            'total'   => $total,
            'used'    => $used,
            'free'    => $available,
            'percent' => $percent,
        ];
    }

    /**
     * Membaca Load Average secara native
     */
    public static function getFastLoadAvg(): array
    {
        $load = @sys_getloadavg();
        return [
            '1m'  => $load[0] ?? 0.0,
            '5m'  => $load[1] ?? 0.0,
            '15m' => $load[2] ?? 0.0,
        ];
    }
}
```

### 7.3. Implementasi Real-Time Server-Sent Events (SSE) Task Streaming `❌ BELUM`
> Peringatan: contoh kode di bawah belum punya cek autentikasi dan akan mengunci sesi PHP selama stream. Perbaiki sebelum dipakai.

Buat endpoint baru pada `app/Controllers/TaskController.php`:

```php
public function streamLog(): void
{
    // Cegah buffering Nginx dan PHP
    header('Content-Type: text/event-stream');
    header('Cache-Control: no-cache');
    header('Connection: keep-alive');
    header('X-Accel-Buffering: no'); // Mematikan proxy buffering Nginx

    $taskId = (int)($_GET['task_id'] ?? 0);
    $root = defined('PANEL_ROOT') ? PANEL_ROOT : dirname(__DIR__, 2);
    $logFile = $root . '/data/panelExec.log';

    if (!file_exists($logFile)) {
        echo "data: [Log file not initialized yet]\n\n";
        @ob_flush();
        flush();
        return;
    }

    $handle = @fopen($logFile, 'r');
    if (!$handle) return;

    // Lompat ke baris terakhir jika file sangat besar
    fseek($handle, -4096, SEEK_END);

    $timeout = 60; // Timeout stream 60 detik
    $start = time();

    while (time() - $start < $timeout) {
        $line = fgets($handle);
        if ($line !== false) {
            echo "data: " . json_encode(['line' => $line]) . "\n\n";
            @ob_flush();
            flush();
        } else {
            usleep(200000); // 200ms sleep jika belum ada data baru
            // Periksa apakah status task di SQLite sudah 'completed' atau 'failed'
            $pdo = \App\Core\Database::getInstance();
            $stmt = $pdo->prepare("SELECT status FROM tasks WHERE id = :id LIMIT 1");
            $stmt->execute([':id' => $taskId]);
            $status = $stmt->fetchColumn();
            if (in_array($status, ['completed', 'failed'])) {
                echo "event: close\ndata: " . json_encode(['status' => $status]) . "\n\n";
                @ob_flush();
                flush();
                break;
            }
        }
    }

    fclose($handle);
}
```

---

## 8. Kesimpulan & Rekomendasi Eksekusi

Dengan menerapkan arsitektur pada panduan ini, **Almusa Server Control Panel** akan bertransformasi dari sekadar panel server alternatif menjadi **platform orkestrasi server Linux tingkat produksi** yang:
1. **Kebal terhadap serangan umum** (Brute-force lockout, 2FA TOTP, session hijacking protection).
2. **Sangat responsif dan ringan** (tidak memakan CPU server saat idle dan UI real-time tanpa reload).
3. **Aman untuk data pengguna** (tersedia cadangan otomatis lokal & cloud remote S3/R2).
4. **Mandiri & Mudah Dirawat** (Watchdog 2.0 self-healing dan perkakas pemulihan `mypanel doctor`).
