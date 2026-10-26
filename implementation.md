# Rencana Implementasi & Roadmap Produksi — Almusa Control Panel

Dokumen ini berisi panduan terstruktur, urutan fase pengerjaan (*step-by-step*), dan kriteria pengujian untuk membawa **Almusa Control Panel (SLEMP Edition)** menjadi panel server yang stabil, aman, dan siap pakai di lingkungan *production*.

---

## Ringkasan Fase Pengerjaan

| Fase | Fokus Utama | Estimasi Kompleksitas | Status |
| :--- | :--- | :---: | :---: |
| **Fase 1** | Hardening Fondasi & Keamanan Arsitektur | Tinggi (Kritis) | ✅ Selesai (Completed) |
| **Fase 2** | Fitur Esensial Pengelolaan Hosting | Menengah | ⏳ Siap Dikerjakan |
| **Fase 3** | Otomasi Backup, Cron & Disaster Recovery | Menengah | 📋 Terjadwal |
| **Fase 4** | Monitoring, Health Watchdog & Notifikasi | Rendah-Menengah | 📋 Terjadwal |

---

## 🛡️ Fase 1: Hardening Fondasi & Keamanan (Production Core)

> **Tujuan:** Menghilangkan kelemahan arsitektur kritis (`php -S` dan eksekusi web sebagai `root`), membatasi brute-force, dan memastikan performa tinggi saat diakses bersamaan.

### Langkah 1.1: Migrasi Web Server Panel ke Nginx + PHP-FPM Khusus
- [x] **Latar Belakang:** `php -S` bersifat *single-threaded*, tidak tahan beban konkuren, dan tidak memiliki perlindungan HTTP modern.
- [x] **Tugas:**
  - [x] Buat pool PHP-FPM tersendiri untuk panel di `config/panel-fpm.conf` yang mendengarkan di Unix Socket: `/run/php/almusa-panel.sock`.
  - [x] Buat konfigurasi vhost Nginx internal di `config/nginx-panel.conf` yang mendengarkan di port `64448` (dilink ke `/etc/nginx/sites-enabled/almusa-panel.conf`).
  - [x] Arahkan rute statis `/assets/*` dilayani langsung oleh Nginx (efisiensi caching & zero overhead PHP).
  - [x] Perbarui `mypanel-web.service` agar mengelola pool dedicated PHP-FPM panel secara terisolasi.
- [x] **Verifikasi:**
  - [x] Request bersamaan (stress test 25 concurrent connections) berhasil dieksekusi tanpa hang/freeze (<0.1 detik).

### Langkah 1.2: Pemisahan Hak Akses (Drop Root dari Web Layer)
- [x] **Latar Belakang:** Web server panel saat ini berjalan sebagai `User=root`. Kerentanan pada web app langsung berpotensi mengorbankan seluruh OS.
- [x] **Tugas:**
  - [x] Ubah user eksekusi file web panel menjadi user non-root (`afandi`).
  - [x] Pastikan folder `data/` dimiliki oleh user non-root dengan permission terisolasi (`afandi:afandi`).
  - [x] Pastikan hanya daemon worker background (`mypanel-worker.service` yang menjalankan `task.php`) yang tetap memiliki hak eskalasi root melalui whitelist perintah aman.
- [x] **Verifikasi:**
  - [x] `ps aux | grep php` menunjukkan proses web pool `almusa-panel` berjalan sebagai `afandi`, sedangkan `task.php` berjalan sebagai `root`.

### Langkah 1.3: Rate Limiting & Proteksi Brute-Force Login
- [x] **Tugas:**
  - [x] Buat tabel `login_attempts` di `data/panel.db` (kolom: `ip_address`, `attempts`, `last_attempt_at`, `locked_until`).
  - [x] Buat kelas `App\Core\RateLimiter` dengan dukungan deteksi IP asli (Cloudflare & proxy).
  - [x] Tambahkan proteksi di `App\Controllers\AuthController`:
    - [x] Batasi maksimal 5x percobaan gagal per IP dalam rentang waktu 15 menit.
    - [x] Kunci form login selama 15 menit jika batas terlampaui dan nonaktifkan tombol submit.
    - [x] Reset otomatis counter percobaan ketika login berhasil.
  - [x] Tambahkan pesan error informatif (menampilkan sisa percobaan) dan catat log kejadian ke `data/panel_security.log`.
- [x] **Verifikasi:**
  - [x] Uji POST ke `/login` 5x berturut-turut dengan password salah; percobaan ke-5 langsung mengunci akses selama 15 menit dan mematikan tombol submit. Log tercatat rapi di `data/panel_security.log`.

### Langkah 1.4: Real IP Forwarding dari Cloudflare
- [x] **Tugas:**
  - [x] Tambahkan konfigurasi Nginx `set_real_ip_from` untuk daftar CIDR IP Cloudflare resmi (IPv4 & IPv6) serta subnet proxy tunnel lokal di `config/cloudflare-realip.conf`.
  - [x] Set `real_ip_header CF-Connecting-IP;` dan `real_ip_recursive on;` di `/etc/nginx/conf.d/cloudflare-realip.conf` agar berlaku global untuk panel dan semua website.
  - [x] Perbarui `scripts/install.sh` agar otomatis menautkan konfigurasi real IP saat instalasi baru.
- [x] **Verifikasi:**
  - [x] Diuji dengan request online via Cloudflare; log Nginx (`/var/log/nginx/almusa_access.log`) mencatat alamat IP asli pengunjung secara presisi (IPv6 asli klien dan IPv4 terdeteksi akurat).

---

## 🧰 Fase 2: Fitur Esensial Pengelolaan Hosting

> **Tujuan:** Mempermudah deployment dan pemeliharaan website tanpa harus login SSH ke terminal manual.

### Langkah 2.1: Web File Manager & Config Editor
- [x] **Tugas:**
  - [x] Tambahkan tombol dan navigasi "File Manager" pada baris website di `/sites` dan menu navigasi sidebar (`/sites/files`).
  - [x] Tampilkan struktur file di `/www/wwwroot/{domain}` dengan navigasi folder ringan (breadcrumbs, pencarian realtime, icon berkas spesifik, ukuran, izin, tanggal).
  - [x] Fitur inti:
    - [x] **Editor Teks**: Mengedit file `.env`, `nginx.conf`, `index.php`, `.htaccess` lengkap dengan line numbering, indentasi Tab (4 spaces), deteksi sintaks, dan shortcut `Ctrl + S`.
    - [x] **Proteksi Sintaks Nginx**: Pengujian `nginx -t` otomatis sebelum simpan; otomatis rollback jika sintaks salah dan tampilkan pesan error baris tanpa merusak web server.
    - [x] **Upload & Extract**: Unggah berkas/banyak berkas dan drag & drop, serta ekstrak file `.zip` (dengan mitigasi Zip-Slip traversal).
    - [x] **File Permissions**: Set kepemilikan dan hak akses otomatis (`chown -R afandi:www-data`, dir `0755`, file `0644`) setelah extract.
- [x] **Verifikasi:**
  - [x] Diuji dengan membuat file `.env.almusa_test`, menyimpan konten kustom, dan memverifikasi langsung di disk server (`/www/wwwroot/bidan-okta/.env.almusa_test`).
  - [x] Diuji pengunggahan `test_archive.zip` dan ekstraksi otomatis dengan penyesuaian permission `0644` pada file dan `0755` pada direktori.
  - [x] Diuji proteksi path traversal (`../../../../etc/passwd` menghasilkan 404 Access Denied).
  - [x] Diuji validasi sintaks Nginx (sintaks invalid langsung ditolak dengan status 400 dan pesan error spesifik, konfigurasi asli tetap aman).

### Langkah 2.2: Database Web GUI (Embedded Adminer)
- [x] **Tugas:**
  - [x] Pasang engine Adminer di direktori terisolasi (`/www/panel/public/adminer/index.php` dan `adminer-core.php`) dengan custom CSS theme flat & modern (`adminer.css`).
  - [x] Tambahkan autentikasi auto-login sekali klik (*single-sign-on token*) via endpoint `/api/databases/sso-token` dan tombol "Kelola Data" per baris database serta tombol utama "Web GUI Database (Adminer)" di `/databases`.
  - [x] Lindungi rute Adminer dengan session guard ketat (`Auth::check()`); akses langsung tanpa session panel ditolak dengan HTTP 403 Forbidden.
- [x] **Verifikasi:**
  - [x] Akses tanpa login ke `/adminer/` langsung ditolak dengan status 403 Forbidden dan tampilan kartu peringatan Almusa.
  - [x] Klik tombol "Kelola Data" pada database menghasilkan token SSO sekali pakai, membuka Adminer di tab baru, dan langsung terotentikasi ke database bersangkutan tanpa meminta password.
  - [x] Akses langsung ke `/adminer/` bagi admin yang sudah login otomatis menghubungkan sesi root/aktif dengan tombol kembali `← Almusa Panel`.
  - [x] Penyempurnaan styling dark theme modern (mengatasi kotak input putih tanpa atribut `type`, penataan tabel kolom, badge partition, hiding versi mentah, dan dynamic cache busting `?v={filemtime}`).

### Langkah 2.3: Let's Encrypt / Certbot 1-Click SSL
- [x] **Tugas:**
  - [x] Buat endpoint API `/api/sites/issue-ssl`, `/api/sites/disable-ssl`, `/api/sites/renew-ssl`, dan `/api/sites/ssl-status`.
  - [x] Tambahkan skrip task worker `scripts/ssl_manager.sh` (`issue`, `disable`, `renew`, `status`) yang otomatis menginstal `certbot` & `python3-certbot-nginx` jika belum ada di sistem, menyiapkan webroot ACME challenge, dan menjalankan:
    ```bash
    certbot certonly --webroot -w "$DOCROOT" -d "$DOMAIN" --non-interactive --agree-tos -m "$EMAIL" --keep-until-expiring
    ```
  - [x] Otomatis injeksi template virtual host SSL port 443 (`plugins/nginx/conf/vhost_ssl_template.conf`) dengan validasi `nginx -t`, redirect HTTP 301 ke HTTPS, serta mempertahankan port 8088 untuk kompatibilitas reverse proxy & Cloudflare Tunnel.
  - [x] Daftarkan timer systemd `certbot.timer` dan deploy hook `/etc/letsencrypt/renewal-hooks/deploy/01-almusa-reload-nginx.sh` untuk auto-renewal dan auto-reload Nginx.
  - [x] Bangun GUI modal modern pada menu `/sites` (badge HTTPS di tabel, tombol shield icon, status sertifikat aktif, sisa masa berlaku, form 1-click issue, renew, dan opsi cabut/disable SSL).
- [x] **Verifikasi:**
  - [x] Endpoint status `/api/sites/ssl-status` merespons akurat JSON info sertifikat.
  - [x] Validasi domain FQDN otomatis mencegah request invalid ke Let's Encrypt.
  - [x] Worker mengeksekusi instalasi certbot secara otomatis dan `certbot.timer` aktif berjalan di sistem (run twice daily).

### Langkah 2.4: Pengelolaan & Konfigurasi Nginx Global (`/etc/nginx/nginx.conf`)
- [x] **Tugas:**
  - [x] Buat backend controller dan endpoint API `/api/nginx/config`, `/api/nginx/save`, `/api/nginx/test`, dan `/api/nginx/service`.
  - [x] Buat task worker script `scripts/nginx_manager.sh` dengan sistem **Safety Guard** (validasi otomatis `nginx -t` sebelum diterapkan, auto-rollback jika sintaks salah, dan zero-downtime reload).
  - [x] Buat modal pengelolaan Nginx global (`_nginx_modal.php`) dengan 3 tab:
    - **Tab Quick Tuning (GUI)**: Form batas upload (`client_max_body_size`), `server_tokens off`, `gzip on`, `keepalive_timeout`, `worker_processes`, `worker_connections`, dan `fastcgi_read_timeout`.
    - **Tab Raw Code Editor**: Editor kode langsung untuk `/etc/nginx/nginx.conf` dengan dark mode monospace font dan safety guard.
    - **Tab Info Layanan & Log**: Status proses Nginx, versi, tombol reload/restart, dan direktori penting.
  - [x] Pasang tombol "Kelola Nginx" di App Store (`/plugins`) dan interaksi klik pada layanan Nginx di Dashboard (`/dashboard`).
- [x] **Verifikasi:**
  - [x] Pengujian sintaks via API `/api/nginx/test` dan terminal berjalan lancar (`nginx: configuration file test is successful`).
  - [x] Pengujian mode Quick Tuning berhasil memperbarui `client_max_body_size` ke `100M`, `worker_connections` ke `2048`, dan `server_tokens` ke `off`.
  - [x] Pengujian sintaks salah sengaja (broken syntax) berhasil dicegah oleh Safety Guard, otomatis di-rollback ke konfigurasi sebelumnya, dan Nginx tetap berjalan tanpa downtime.

### Langkah 2.5: Log Viewer Realtime per Website
- [x] **Tugas:**
  - [x] Tambahkan tombol & modal "Lihat Log" pada setiap website di menu `/sites`.
  - [x] Menampilkan 50, 100, 200, atau 500 baris terakhir dari:
    - `/var/log/nginx/{domain}_access.log` (Access Log dengan pewarnaan status HTTP 2xx hijau, 3xx cyan, 4xx oranye, 5xx merah)
    - `/var/log/nginx/{domain}_error.log` (Error Log dengan penandaan level [error], [warn], [notice])
  - [x] Fitur Auto-refresh realtime (polling cerdas tiap 3 detik dengan indikator lampu berkedip dan auto-scroll pintar).
  - [x] Fitur Bersihkan Log (truncate file log secara aman via task queue worker / direct truncate).
  - [x] Antarmuka console bergaya dark/terminal dengan font monospace, ukuran file, jumlah baris, dan penunjuk waktu update.
- [x] **Verifikasi:**
  - [x] Endpoint API `/api/sites/logs` dan `/api/sites/clear-log` berhasil merespons data log dalam format JSON.
  - [x] Simulasi request 404 (`GET /test-realtime-log-viewer-404-check`) pada `http://127.0.0.1:8088/` (Host: bidan-okta) terdeteksi secara instan di log viewer panel.
  - [x] Pengujian fitur "Kosongkan" berhasil mengosongkan log menjadi 0 bytes, dan request baru setelahnya langsung tercatat kembali secara realtime.

---

## 💾 Fase 3: Otomasi Backup, Cron & Disaster Recovery

> **Tujuan:** Menjamin keandalan data website dan memfasilitasi tugas otomatis framework modern (seperti Laravel & WordPress).

### Langkah 3.1: Cron Job GUI Manager
- [x] **Tugas:**
  - [x] Buat menu navigasi baru: **Cron Jobs** (`/cron`) di sidebar panel dengan ikon jam (`clock`).
  - [x] Tabel daftar cron terintegrasi langsung dengan konfigurasi sistem Linux (`/etc/cron.d/almusa-cron`).
  - [x] Form tambah/edit tugas:
    - Pilihan interval visual: Setiap menit (`* * * * *`), Setiap 5/15/30 menit, Setiap jam, Setiap hari (00:00), Setiap minggu, Setiap bulan, serta Ekspresi Kustom dengan preview deskripsi otomatis.
    - Template preset cepat framework: Laravel Scheduler (`php artisan schedule:run`), WordPress WP-Cron (`wp-cron.php`), dan Pembersih Cache Harian.
    - Pilihan user pelaksana (`afandi`, `www-data`, `root`) dan mode penyimpanan log output.
  - [x] Tombol eksekusi manual ("Jalankan Sekarang") untuk menguji script seketika lengkap dengan popup durasi dan hasil output terminal.
  - [x] Modal Viewer Log Output & tombol kosongkan log riwayat tugas cron.
- [x] **Verifikasi:**
  - [x] Pembuatan tugas cron uji coba berhasil menulis dan mensinkronkan `/etc/cron.d/almusa-cron` dengan permission `0644 root:root`.
  - [x] Tombol eksekusi manual berhasil mengeksekusi perintah shell dalam 0.01 detik (Exit Code 0).
  - [x] Daemon sistem cron Linux (`cron.service`) terkonfirmasi di `journalctl` mengeksekusi tugas otomatis setiap menit secara akurat dan mencatat outputnya ke `data/cron_logs/cron_1.log`.
  - [x] Pengujian jeda/aktif (toggle) berhasil memperbarui `/etc/cron.d/almusa-cron` seketika.

### Langkah 3.2: Automated Database & Site Backup Engine
- [ ] **Tugas:**
  - Tambahkan menu **Backups** (`/backups`).
  - Pembuatan snapshot terkompresi:
    - Database dump (`mysqldump -u root -p {db_name} | gzip`).
    - File direktori website (`tar -czf site_backup_{date}.tar.gz`).
  - Opsi penyimpanan:
    - Lokal: `/www/backup/` (dengan retensi rotasi otomatis, misal: simpan 7 hari terakhir).
    - Remote Cloud: Dukungan Cloudflare R2 / AWS S3 via AWS CLI atau S3 PHP SDK sederhana.
- [ ] **Verifikasi:**
  - Jalankan backup terjadwal; file snapshot terarsip rapi dengan nama berbasis timestamp.

### Langkah 3.3: 1-Click Site & Database Restore
- [ ] **Tugas:**
  - Tampilkan tombol "Restore" di sebelah setiap file backup yang tersimpan.
  - Worker membaca file `.sql.gz` atau `.tar.gz` dan mengembalikan state data.
  - Dialog konfirmasi bahaya sebelum eksekusi (*safety guard*).
- [ ] **Verifikasi:**
  - Hapus salah satu tabel uji coba, lakukan restore dari backup; tabel kembali normal.

---

## 📈 Fase 4: Monitoring, Health Watchdog & Notifikasi

> **Tujuan:** Server dapat memulihkan diri sendiri (*self-healing*) dan memberi kabar darurat jika ada kendala.

### Langkah 4.1: Service Watchdog & Auto-Heal Daemon
- [ ] **Tugas:**
  - Buat skrip cron / systemd timer watchdog (`watchdog.php` berjalan setiap 1 menit):
    - Cek status `systemctl is-active nginx`.
    - Cek status `systemctl is-active mariadb`.
    - Cek status `systemctl is-active php8.3-fpm` & versi lain.
    - Cek status `cloudflared`.
  - Jika salah satu service mati mendadak (*failed/inactive*), watchdog langsung mengeksekusi `systemctl restart {service}` dan mencatat log kejadian ke `data/panel_incident.log`.
- [ ] **Verifikasi:**
  - Matikan manual Nginx via terminal (`sudo systemctl stop nginx`); dalam 1-2 menit service harus menyala kembali secara otomatis.

### Langkah 4.2: Notifikasi Insiden (Telegram / Discord Webhook)
- [ ] **Tugas:**
  - Tambahkan seksi pengaturan di `/settings`: **Notifikasi Darurat**.
  - Opsi: Telegram Bot Token & Chat ID, atau Discord Webhook URL.
  - Trigger notifikasi:
    - Service mengalami restart otomatis oleh Watchdog.
    - Penggunaan disk storage `/` melebihi ambang batas 85%.
    - Terjadi 5x percobaan login gagal beruntun (indikasi brute-force).
- [ ] **Verifikasi:**
  - Klik tombol "Kirim Notifikasi Uji Coba" di menu pengaturan; pesan uji coba masuk ke grup Telegram/Discord.

---

## 🏁 Checklist Kesiapan Go-Live (Production Gate)

Sebelum server dipublikasikan untuk klien/pengguna luas:
- [ ] `php -S` sudah diganti dengan Nginx + PHP-FPM pool socket.
- [ ] User web layer bukan `root`.
- [ ] Port panel (64448) dilindungi firewall (UFW) atau hanya diakses via Cloudflare Zero Trust Access.
- [ ] Default password admin sudah diganti dengan password kuat (min. 12 karakter acak).
- [ ] Secret Entrance Path unik sudah diaktifkan (misal: `/panel-aman-2026`).
- [ ] Backup otomatis database berjalan minimal 1x sehari.
- [ ] Seluruh repository kode di-push dan tersinkronisasi bersih di branch `main`.
