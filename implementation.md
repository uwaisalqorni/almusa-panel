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
- [ ] **Tugas:**
  - Tambahkan sub-menu atau modal "File Manager" pada baris website di `/sites`.
  - Tampilkan struktur file di `/www/wwwroot/{domain}` dengan navigasi folder ringan.
  - Fitur inti:
    - **Editor Teks**: Mengedit file `.env`, `nginx.conf`, `index.php`, `.htaccess`.
    - **Upload & Extract**: Unggah file zip dan ekstrak langsung di folder tujuan.
    - **File Permissions**: Set kepemilikan otomatis `chown -R www-data:www-data` setelah extract.
- [ ] **Verifikasi:**
  - Buat file `.env` baru pada salah satu website dan simpan; verifikasi file tersimpan dengan benar di server.

### Langkah 2.2: Database Web GUI (Embedded Adminer)
- [ ] **Tugas:**
  - Pasang file `adminer.php` di direktori terisolasi (`/www/panel/public/adminer/index.php`).
  - Tambahkan autentikasi auto-login sekali klik (*single-sign-on token*) dari menu `/databases`.
  - Lindungi rute Adminer agar hanya bisa diakses saat session admin panel aktif.
- [ ] **Verifikasi:**
  - Klik tombol "Kelola Data" pada salah satu database, jendela Adminer langsung terbuka ke database bersangkutan tanpa meminta password ulang.

### Langkah 2.3: Let's Encrypt / Certbot 1-Click SSL
- [ ] **Tugas:**
  - Buat endpoint API `/api/sites/issue-ssl` (`site_id`, `provider=letsencrypt`).
  - Tambahkan skrip task worker untuk menjalankan Certbot:
    ```bash
    certbot certonly --webroot -w /www/wwwroot/{domain} -d {domain} --non-interactive --agree-tos -m admin@{domain}
    ```
  - Otomatis injeksi blok konfigurasi SSL port 443 pada template Nginx virtual host.
  - Daftarkan timer systemd untuk auto-renewal (`certbot renew`).
- [ ] **Verifikasi:**
  - Domain dengan A-record langsung dapat menerbitkan sertifikat SSL yang valid dan redirect otomatis HTTP -> HTTPS.

### Langkah 2.4: Log Viewer Realtime per Website
- [ ] **Tugas:**
  - Tambahkan modal "Lihat Log" pada setiap website di menu `/sites`.
  - Menampilkan 50 baris terakhir dari:
    - `/var/log/nginx/{domain}_access.log`
    - `/var/log/nginx/{domain}_error.log`
  - Tombol auto-refresh dan tombol bersihkan log.
- [ ] **Verifikasi:**
  - Munculkan error 404/500 buatan pada website target, log langsung terbaca di modal panel.

---

## 💾 Fase 3: Otomasi Backup, Cron & Disaster Recovery

> **Tujuan:** Menjamin keandalan data website dan memfasilitasi tugas otomatis framework modern (seperti Laravel & WordPress).

### Langkah 3.1: Cron Job GUI Manager
- [ ] **Tugas:**
  - Buat menu navigasi baru: **Cron Jobs** (`/cron`).
  - Tabel daftar cron yang aktif di sistem (`/etc/cron.d/almusa-cron` atau crontab `www-data`).
  - Form tambah tugas:
    - Pilihan interval visual (Setiap menit, Setiap jam, Setiap hari jam 00:00, atau Custom Cron Expression).
    - Perintah shell (misal: `/usr/bin/php8.3 /www/wwwroot/app/artisan schedule:run`).
  - Tombol eksekusi manual untuk menguji script.
- [ ] **Verifikasi:**
  - Jadwalkan script echo ke file temporary; verifikasi file terbuat sesuai jadwal cron.

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
