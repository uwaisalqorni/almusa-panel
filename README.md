# Almusa Server Control Panel (PANEL-PHP Edition)

> **Lightweight, Modular & Secure Standalone Linux Server Control Panel**  
> Dibangun murni menggunakan **PHP Standalone (CLI & Built-in Engine)**, **SQLite3 (PDO WAL Mode)**, **Modular Bash Scripts**, serta integrasi bawaan **Cloudflare Tunnel (`cloudflared`)**.

---

## 📌 Daftar Isi
1. [Fitur Unggulan](#-fitur-unggulan)
2. [Prinsip Keamanan & Arsitektur](#-prinsip-keamanan--arsitektur)
3. [Persyaratan Sistem](#-persyaratan-sistem)
4. [Panduan Instalasi Cepat](#-panduan-instalasi-cepat)
5. [Cara Akses Panel Pertama Kali](#-cara-akses-panel-pertama-kali)
6. [Panduan Penggunaan Fitur Web Panel](#-panduan-penggunaan-fitur-web-panel)
   - [Dashboard & Metrik Server](#1-dashboard--metrik-server)
   - [Websites & Switch PHP Instan](#2-manajemen-website--virtual-host)
   - [Database MySQL & MariaDB](#3-manajemen-database-mysql--mariadb)
   - [App Store & Multi-Versi PHP](#4-app-store--multi-versi-php)
   - [Cloudflare Tunnel (Quick & Custom Domain)](#5-cloudflare-tunnel-integration)
   - [Terminal Log Modal Real-Time](#6-terminal-eksekusi-real-time)
7. [Sistem Panel Self-Updater (1-Click & CLI)](#-sistem-panel-self-updater)
8. [Panduan Perintah CLI (`mypanel`)](#-panduan-perintah-cli-mypanel)
9. [Struktur Direktori Sistem](#-struktur-direktori-sistem)
10. [Panduan Uninstal](#-panduan-uninstal)

---

## 🌟 Fitur Unggulan

- **Panel Self-Updater Aman (1-Click & CLI):** Notifikasi pembaruan otomatis di dashboard UI, 1-Click Update dengan live log streaming, perintah terminal `mypanel update`, pencadangan atomik database SQLite (WAL-safe), serta migrasi otomatis non-destruktif tanpa risiko menimpa data user.
- **Standalone Engine Terisolasi:** Berjalan pada port TCP khusus (default: `64448`) dengan database embedded SQLite3. Jika Nginx atau MySQL pengguna mengalami *crash*, panel kontrol tetap 100% aktif dan dapat diakses.
- **Zero Web-Root RCE (Privilege Separation):** Request HTTP dari antarmuka web **tidak pernah** menjalankan `sudo` secara langsung. Web controller hanya memvalidasi input lalu memasukkan tugas ke antrean SQLite `tasks`. Daemon root (`task.php`) mengeksekusi script yang telah di-whitelist secara terisolasi.
- **Dynamic Security Entrance Guard:** URL login diproteksi token rahasia (contoh: `/bhmbjoam`). Scanner bot yang mencoba mengakses `/` atau `/login` langsung menerima respons `404 Not Found`.
- **Switch Versi PHP per-Domain Instan:** Jalankan PHP 7.4, 8.0, 8.1, 8.2, 8.3, dan 8.4 secara berdampingan. Anda dapat mengganti versi PHP website aktif hanya dengan 1 kali klik tanpa *downtime*.
- **Cloudflare Tunnel Bawaan:**
  - **Quick Tunnel (.trycloudflare.com):** 1-klik online tanpa perlu akun atau konfigurasi DNS.
  - **Custom Domain (Zero Trust API):** Otomatisasi ingress rule dan pembuatan DNS CNAME ter-proxy langsung dari panel.
- **Strict PDO & UTF8MB4:** Provisioning MySQL menggunakan native prepared statements dengan standar charset `utf8mb4` dan `utf8mb4_unicode_ci`.
- **Monitoring Hardware Real-Time:** Gauge pemantauan CPU %, RAM, Disk Storage, dan Load Average langsung dari `/proc/stat` tanpa dependensi eksternal.

---

## 🛡️ Prinsip Keamanan & Arsitektur

```text
[ Browser Klien ]
       │  (HTTP Request + CSRF Token)
       ▼
[ Public Web Interface ]  ──(Port 64448 - Router & Auth Guard)
       │
       ▼  (Validasi Regex Ketat & Prepared Statement)
[ SQLite Database: data/panel.db ]  ──(Status: pending)
       ▲
       │  (Polling Queue setiap detik)
[ Root Worker Daemon: task.php ]  ──(Systemd Service)
       │
       ▼  (escapeshellarg & Whitelisted Action)
[ Modular Bash Scripts / Plugins ]  ──> [ /tmp/panelExec.log ]
```

---

## 💻 Persyaratan Sistem

- **Sistem Operasi:**
  - Ubuntu 20.04 / 22.04 / 24.04 LTS (Rekomendasi)
  - Debian 11 / 12
- **Akses:** Pengguna dengan hak akses `root` atau `sudo`.
- **Arsitektur:** `x86_64` (amd64) atau `aarch64` (arm64).
- **Kapasitas Minimal:** RAM 512 MB, Disk 5 GB.

---

## 🚀 Panduan Instalasi Cepat

### 1. Jalankan Master Installer

#### Opsi A: Instalasi Otomatis (Direkomendasikan untuk Linux VPS)
```bash
curl -fsSL https://bidanokta.xyz/almusa.sh | sudo bash
```

#### Opsi B: Instalasi Manual dari Sumber (GitHub)
Di server VPS atau mesin lokal Anda, clone repositori dan jalankan master installer sebagai `root`:
```bash
git clone https://github.com/uwaisalqorni/almusa-panel.git
cd almusa-panel
sudo bash scripts/install.sh
```

### 2. Apa yang Dilakukan oleh Installer?
1. Menginstal paket minimal sistem yang dibutuhkan engine: `git`, `curl`, `sqlite3`, `nginx`, serta `php-fpm` & `php-cli` yang sesuai dengan rilis Linux aktif.
2. Mendaftarkan repositori `ppa:ondrej/php` agar siap memasang multi-versi PHP kapan saja.
3. Menyiapkan struktur direktori `/www/{panel,server,wwwroot,backup}` dengan standar hak akses aman (`chmod 2775 /www/panel/data` milik grup `www-data`).
4. Menginisialisasi basis data SQLite, membuat kredensial admin default, dan membuat token secret entrance acak.
5. Mendaftarkan dan mengaktifkan 2 unit service `systemd`:
   - `mypanel-web.service`: Web interface panel (Dedicated PHP-FPM) berjalan otomatis saat server *boot* dengan `RuntimeDirectory=php`.
   - `mypanel-worker.service`: Daemon eksekusi tugas background root.
6. Membuka port listen panel (`64448`) pada firewall UFW jika firewall aktif.
7. Membuat symlink global perintah `/usr/local/bin/mypanel` & `/usr/local/bin/almusa`.

---

## 🔑 Cara Akses Panel Pertama Kali

Setelah instalasi selesai, installer akan menampilkan ringkasan akses login:

```text
======================================================
        ALMUSA SERVER CONTROL PANEL CREDENTIALS        
======================================================
Default Entrance Path: /65b0d49b
Default Username     : admin
Default Password     : tCuXfDysdJh#
Default Port         : 64448
Login URL Format     : http://<IP_SERVER>:64448/65b0d49b
======================================================
```

> [!IMPORTANT]
> **Penting Mengenai Security Entrance:**
> Jika Anda membuka `http://<IP_SERVER>:64448/` atau `http://<IP_SERVER>:64448/login` secara langsung, server akan merespons **404 Not Found**. Anda **wajib** mengakses tautan lengkap beserta token entrance (misal: `http://<IP_SERVER>:64448/65b0d49b`) pada browser pertama kali.

Untuk melihat kembali kredensial atau URL akses kapan saja via SSH:
```bash
mypanel default
```

---

## 🖥️ Panduan Penggunaan Fitur Web Panel

### 1. Dashboard & Metrik Server
- **Hardware Gauges:** Menampilkan pemantauan real-time CPU %, RAM MB/GB, Disk Free %, Load Average (1m, 5m, 15m), dan Uptime sistem.
- **Status Layanan Utama:** Memantau apakah Nginx, MySQL/MariaDB, Cloudflare Tunnel, dan masing-masing pool PHP-FPM dalam kondisi *Running* atau *Inactive*.
- **Aksi Cepat:** Pintasan cepat untuk menambah website, membuat database, dan membuka terminal eksekusi.

### 2. Manajemen Website / Virtual Host
- **Tambah Website Baru:**
  1. Klik tombol **Tambah Website Baru**.
  2. Masukkan nama domain (contoh: `proyek-saya.com` atau `app.local`).
  3. Tentukan document root (default: `/www/wwwroot/<domain>`).
  4. Pilih versi PHP yang diinginkan (`7.4` s/d `8.4`).
  5. Pilih template URL Rewrite (`default`, `laravel`, `wordpress`, atau `thinkphp`).
  6. Panel akan secara otomatis menyusun Virtual Host Nginx, membuat file `index.php` default, dan me-reload Nginx tanpa *downtime*.
- **Switch Versi PHP Instan:**
  - Pada baris website di tabel, ganti dropdown versi PHP ke versi lain. Nginx akan langsung memperbarui socket FastCGI dan me-reload konfigurasi secara instan.

### 3. Manajemen Database (MySQL / MariaDB)
- **Buat Database & Pengguna:**
  1. Buka menu **Databases** -> klik **Tambah Database Baru**.
  2. Masukkan nama database, username, password, dan charset (direkomendasikan: `utf8mb4`).
  3. Panel akan mengeksekusi `CREATE DATABASE`, `CREATE USER`, dan `GRANT ALL PRIVILEGES` menggunakan koneksi aman root PDO.
- **Ubah Password:** Klik tombol **Ganti Pass** pada baris database untuk mengubah kata sandi pengguna seketika (`ALTER USER`).
- **Backup Snapshot:** Klik **Backup** untuk melakukan dump database otomatis terkompresi ke direktori `/www/backup/db_<nama>_<tanggal>.sql.gz`.

### 4. App Store & Multi-Versi PHP
- **Instalasi Service Inti:** Pasang Nginx Web Server, MariaDB Database Engine, atau Cloudflared Daemon dengan 1 klik.
- **Multi-Versi PHP:** Pasang versi PHP spesifik (`7.4`, `8.0`, `8.1`, `8.2`, `8.3`, `8.4`). Setiap versi berjalan pada pool socket FPM masing-masing di `/run/php/php<ver>-fpm.sock`.
- **Ekstensi PHP 1-Klik:** Pasang ekstensi tambahan seperti `redis`, `imagick`, `memcached`, `xdebug`, `soap`, `intl`, atau `zip` langsung per-versi PHP.

### 5. Cloudflare Tunnel Integration
- **Mode A — 1-Click Quick Tunnel (trycloudflare.com):**
  - Pada menu Websites atau Tunnels, klik tombol **Quick Online**.
  - Daemon `cloudflared` akan membuat terowongan HTTPS publik otomatis tanpa memerlukan akun Cloudflare. URL live (misal: `https://contoh-acak.trycloudflare.com`) akan tampil di tabel.
- **Mode B — Custom Subdomain (Zero Trust API):**
  1. Buka menu **Pengaturan Panel**, masukkan `Cloudflare API Token`, `Account ID`, dan `Tunnel ID`.
  2. Buka menu **Cloudflare Tunnel** -> klik **Publikasikan ke Custom Domain**.
  3. Pilih website lokal dan masukkan target subdomain (contoh: `api.proyek-saya.id`).
  4. Panel otomatis mendaftarkan Ingress Rule dan membuat DNS CNAME ter-proxy di Cloudflare.

### 6. Terminal Eksekusi Real-Time
- Setiap aksi sistem (instalasi PHP, penambahan Virtual Host, dump database) dikirim ke antrean background daemon.
- Klik tombol **Terminal** di pojok kanan atas navbar untuk melihat jendela terminal gelap live streaming dari log `data/panelExec.log`.
- Dilengkapi tombol **Auto-scroll ON/OFF** dan **Kosongkan Log**.

---

## 🔄 Sistem Panel Self-Updater

Almusa Panel dilengkapi sistem pembaruan mandiri (*self-updater*) yang aman dan teruji, menjamin **data SQLite (`data/panel.db`) tidak akan pernah tertimpa atau hilang**:

1. **Notifikasi Otomatis di UI:**
   - Tombol badge di navigasi atas (*topbar*) dan menu samping (*sidebar*) otomatis menampilkan status versi (contoh: `v1.0.0`) atau indikator menyala **"Update Tersedia (vX.X.X)"**.
   - Dilengkapi sistem cache SQLite 6 jam agar tidak membebani kecepatan *loading* dashboard.
2. **1-Click Update di Web UI:**
   - Klik badge versi untuk membuka modal pembaruan, memeriksa changelog rilis terbaru, dan klik **"1-Click Update Sekarang"**.
   - Tugas pembaruan diproses oleh antrean background daemon dan progresnya terpantau secara *real-time* via terminal log modal.
3. **Pencadangan Atomik (Atomic SQLite WAL Backup):**
   - Sebelum kode diperbarui, sistem otomatis mencadangkan file basis data menggunakan SQLite Online Backup API ke direktori `/www/backup/panel_db_backup_<tanggal>.db`.
4. **Detached Background Restart:**
   - Layanan systemd (`mypanel-web` dan `mypanel-worker`) dimuat ulang dengan jeda singkat di background sehingga daemon menyelesaikan penulisan log eksekusi secara tuntas sebelum me-restart.

> 📖 **Dokumentasi Teknis Lengkap:** Lihat panduan mendalam arsitektur, proteksi data, dan panduan rilis di [docs/SELF_UPDATER.md](docs/SELF_UPDATER.md).

---

## ⌨️ Panduan Perintah CLI (`mypanel`)

Panel dilengkapi perintah CLI bawaan yang dapat dipanggil langsung dari terminal SSH:

```bash
mypanel [perintah]
# atau
almusa [perintah]
```

| Perintah | Deskripsi |
| :--- | :--- |
| `mypanel update` | **Memperbarui Almusa Panel ke versi terbaru secara aman (Git fetch, backup DB, migrasi)** |
| `mypanel start` | Menjalankan seluruh service panel (Web interface & Worker daemon) |
| `mypanel stop` | Menghentikan seluruh service panel |
| `mypanel restart` | Me-restart seluruh service panel |
| `mypanel status` | Memeriksa status keaktifan Web Interface dan Worker Daemon |
| `mypanel default` | Menampilkan ringkasan informasi login (URL, entrance path, user, password, port) |
| `mypanel backup` | Manajemen snapshot cadangan database, situs, dan full server |
| `mypanel watchdog` | Status dan pengujian daemon pemantau kesehatan server (Watchdog) |
| `mypanel` *(tanpa argumen)* | Membuka **menu interaktif bernomor 1–9** di terminal |

### Menu Interaktif SSH:
Jika Anda menjalankan perintah `mypanel` tanpa argumen, akan muncul menu interaktif:
```text
================================================================
            ALMUSA SERVER CONTROL PANEL (SLEMP EDITION)          
================================================================
Silakan pilih nomor menu:
 1) Restart Panel
 2) Stop Panel
 3) Start Panel
 4) Status Layanan
 5) Tampilkan Informasi Login & URL (Default)
 6) Reset Password Admin
 7) Ganti Port Panel
 8) Ganti Secret Entrance Path
 9) Update Almusa Panel ke Versi Terbaru
 0) Keluar
```

---

## ⚡ Panduan Tuning Performa Maksimal (Nginx & PHP-FPM High Performance)

Agar server VPS Anda mampu menangani ribuan kunjungan bersamaan (*high concurrency*) dengan penggunaan RAM dan CPU yang tetap rendah (*ultra-lightweight*), terapkan rekomendasi tuning berikut untuk Nginx dan PHP-FPM.

### 1. Optimasi Global Nginx (`/etc/nginx/nginx.conf`)
Buka file konfigurasi global Nginx dengan editor Anda (`sudo nano /etc/nginx/nginx.conf`) dan terapkan parameter berikut pada blok `main` dan `http`:

```nginx
user www-data;
# Deteksi jumlah core CPU secara otomatis
worker_processes auto;
# Batas maksimal file descriptor yang dapat dibuka oleh proses worker
worker_rlimit_nofile 65535;

events {
    # Jumlah koneksi simultan per worker process
    worker_connections 4096;
    # Menerima seluruh koneksi baru secara berantai
    multi_accept on;
    use epoll;
}

http {
    ##
    # Efisiensi I/O & Jaringan (Zero-Copy Transfer)
    ##
    sendfile on;
    tcp_nopush on;
    tcp_nodelay on;

    ##
    # Timeout & Keepalive (Mencegah resource hung)
    ##
    keepalive_timeout 65;
    keepalive_requests 1000;
    reset_timedout_connection on;
    client_body_timeout 15;
    client_header_timeout 15;
    send_timeout 15;

    ##
    # Buffer Sizes (Mencegah buffering disk sementara)
    ##
    client_body_buffer_size 128k;
    client_max_body_size 50M;
    client_header_buffer_size 1k;
    large_client_header_buffers 4 8k;

    ##
    # Kompresi Gzip Dinamis (Menghemat bandwidth s/d 70%)
    ##
    gzip on;
    gzip_vary on;
    gzip_proxied any;
    gzip_comp_level 5;
    gzip_min_length 256;
    gzip_types
        application/atom+xml
        application/javascript
        application/json
        application/rss+xml
        application/vnd.ms-fontobject
        application/x-font-ttf
        application/x-web-app-manifest+json
        application/xhtml+xml
        application/xml
        font/opentype
        image/svg+xml
        image/x-icon
        text/css
        text/plain
        text/x-component;

    ##
    # Cache File Descriptor Statis
    ##
    open_file_cache max=10000 inactive=20s;
    open_file_cache_valid 30s;
    open_file_cache_min_uses 2;
    open_file_cache_errors on;
}
```

---

### 2. Tuning Pool PHP-FPM Berdasarkan Kapasitas RAM Server
Konfigurasi default PHP-FPM sering kali terlalu boros untuk VPS kecil atau terlalu kecil untuk server besar. Sesuaikan pool PHP situs Anda (`/etc/php/8.x/fpm/pool.d/www.conf`) sesuai kapasitas RAM fisik server:

#### Rumus Perhitungan `pm.max_children`:
$$\text{Max Children} = \frac{\text{RAM yang Dialokasikan untuk PHP}}{\text{Rata-rata RAM per PHP Child Process (biasanya 35MB–50MB)}}$$

#### Tabel Rekomendasi Profil PHP-FPM:

| Kapasitas RAM VPS | Mode PM | `pm.max_children` | `pm.start_servers` | `pm.min_spare_servers` | `pm.max_spare_servers` | `pm.max_requests` |
| :--- | :--- | :--- | :--- | :--- | :--- | :--- |
| **VPS 1 GB RAM** | `dynamic` | **10** | 2 | 1 | 3 | 500 |
| **VPS 2 GB RAM** | `dynamic` | **25** | 4 | 2 | 6 | 1000 |
| **VPS 4 GB RAM** | `dynamic` | **50** | 8 | 4 | 12 | 1500 |
| **VPS 8 GB RAM** | `dynamic` | **100** | 16 | 8 | 24 | 2000 |
| **VPS 16 GB+ RAM**| `static` | **150+** | - | - | - | 3000 |

> [!TIP]
> **Tuning Khusus Dedicated Pool Almusa Panel (`/www/panel/config/panel-fpm.conf`):**
> Almusa Panel telah dirancang sangat hemat resource. Pool panel hanya membutuhkan alokasi kecil:
> ```ini
> pm = dynamic
> pm.max_children = 10
> pm.start_servers = 2
> pm.min_spare_servers = 1
> pm.max_spare_servers = 3
> pm.max_requests = 500
> ```

---

### 3. Konfigurasi Zend OPcache (Kecepatan Eksekusi Maksimal)
Zend OPcache menyimpan bytecode PHP yang telah dikompilasi di dalam RAM bersama (*shared memory*), sehingga PHP tidak perlu membaca dan meng-compile script dari hard drive pada setiap request.

Edit file konfigurasi OPcache (`/etc/php/8.x/fpm/conf.d/10-opcache.ini` atau `/etc/php/8.x/cli/conf.d/10-opcache.ini`):

```ini
[opcache]
; Aktifkan OPcache untuk PHP-FPM
opcache.enable = 1
; Nonaktifkan di CLI kecuali dibutuhkan (menghemat RAM CLI)
opcache.enable_cli = 0

; Alokasi memori RAM untuk bytecode cache (128MB direkomendasikan untuk situs WordPress/Laravel)
opcache.memory_consumption = 128

; Alokasi memori untuk string yang sering digunakan
opcache.interned_strings_buffer = 16

; Jumlah maksimal script PHP yang dapat disimpan di cache
opcache.max_accelerated_files = 10000

; Frekuensi pengecekan perubahan file dalam detik (0 di production jika tidak sering update code)
opcache.revalidate_freq = 60
opcache.validate_timestamps = 1

; Optimasi shutdown cepat untuk membebaskan memory
opcache.fast_shutdown = 1

; Pertahankan komentar docblock untuk framework modern (Laravel/Symfony)
opcache.save_comments = 1

; Aktifkan JIT (Just-In-Time) Compiler di PHP 8.2 / 8.3 / 8.4
opcache.jit = 1255
opcache.jit_buffer_size = 64M
```

---

### 4. Optimasi Nginx FastCGI Buffering (Respons Cepat Anti Lag)
Pada virtual host Nginx website Anda (`/etc/nginx/sites-available/<domain>.conf`), pastikan blok `location ~ \.php$` memiliki buffer FastCGI yang cukup agar Nginx tidak menulis respon PHP ke disk swap:

```nginx
location ~ \.php$ {
    fastcgi_split_path_info ^(.+\.php)(/.+)$;
    fastcgi_pass unix:/run/php/php8.3-fpm.sock;
    fastcgi_index index.php;
    fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
    include fastcgi_params;

    # Buffer FastCGI agar transmisi payload cepat tanpa bottleneck disk
    fastcgi_buffer_size 32k;
    fastcgi_buffers 16 16k;
    fastcgi_busy_buffers_size 64k;
    fastcgi_temp_file_write_size 64k;
    
    # Timeout operasi PHP panjang (cth: eksekusi backup atau laporan)
    fastcgi_read_timeout 300;
    fastcgi_connect_timeout 60;
    fastcgi_send_timeout 300;
    
    # Sembunyikan header versi PHP untuk keamanan
    fastcgi_hide_header X-Powered-By;
}
```

---

### 5. Cara Validasi Sintaks & Reload Tanpa Downtime
Setelah memperbarui konfigurasi di atas, selalu uji validitas sintaks sebelum me-reload service:

```bash
# 1. Uji konfigurasi Nginx
sudo nginx -t

# 2. Uji konfigurasi PHP-FPM
sudo php-fpm8.3 -t

# 3. Reload layanan secara halus (Zero-Downtime Reload)
sudo systemctl reload nginx
sudo systemctl reload php8.3-fpm
sudo systemctl reload mypanel-web
```

---

## 📂 Struktur Direktori Sistem

Semua file panel kontrol terisolasi secara teratur:

```text
/www/
├── panel/                          # Root Aplikasi Panel Kontrol
│   ├── app/                        # Arsitektur MVC & Core PHP
│   │   ├── Core/
│   │   │   ├── Database.php        # Singleton PDO SQLite (Strict & Auto-Migration)
│   │   │   ├── Router.php          # Router & Dynamic Entrance Guard (404 Bot Blocker)
│   │   │   ├── Auth.php            # Session Hardening, Bcrypt & Token CSRF
│   │   │   ├── Updater.php         # Self-Updater Engine (GitHub Releases, Cache 6 Jam)
│   │   │   ├── Response.php        # JSON & View Renderer
│   │   │   └── SystemMonitor.php   # Pembaca Metrik Linux (/proc/stat, /proc/meminfo)
│   │   └── Controllers/
│   │       ├── AuthController.php
│   │       ├── DashboardController.php # Stats, Settings, & Update API
│   │       ├── SiteController.php      # Virtual Host & PHP Switcher
│   │       ├── DatabaseController.php  # MySQL UTF8MB4 & User Provisioning
│   │       ├── PluginController.php    # App Store & Extension Installer
│   │       ├── TunnelController.php    # Cloudflare Quick & Zero Trust Tunnel
│   │       └── TaskController.php      # Streaming Log data/panelExec.log
│   ├── data/
│   │   ├── panel.db                # Basis Data SQLite (chmod 600, WAL Mode)
│   │   ├── entrance.txt            # Token Path Rahasia Masuk
│   │   ├── secret.key              # Kunci Enkripsi Kredensial
│   │   └── initial_credentials.txt # Catatan Kredensial Awal
│   ├── docs/                       # Dokumentasi Arsitektur & Panduan
│   │   ├── SELF_UPDATER.md         # Panduan Lengkap Sistem Self-Updater
│   │   └── ARSITEKTUR_SSL.md       # Panduan Teknis SSL & Cloudflare Tunnel
│   ├── public/                     # Document Root Server Built-in
│   │   ├── index.php               # Front Controller
│   │   └── assets/                 # CSS & Javascript Interaktif
│   ├── views/                      # Template Tampilan Antarmuka
│   │   └── layouts/
│   │       ├── app.php             # Master Layout (Topbar Version Badge)
│   │       └── _update_modal.php   # Dialog Pembaruan 1-Click & Changelog
│   ├── plugins/                    # Modular Service Installers (Nginx, PHP, MySQL, Cloudflared)
│   ├── rewrite/nginx/              # Template URL Rewrite (Default, Laravel, WordPress, ThinkPHP)
│   ├── scripts/
│   │   ├── install.sh              # Master Bootstrap Installer
│   │   ├── uninstall.sh            # Safe Uninstaller dengan Pencadangan SQLite
│   │   ├── update_panel.sh         # Eksekutor Pembaruan Mandiri (Atomic Backup & Detached Restart)
│   │   └── vhost_manager.sh        # Generator Nginx Virtual Host
│   ├── cli.sh                      # Helper CLI (symlinked ke /usr/local/bin/mypanel & almusa)
│   ├── version.json                # Metadata Versi, Build & Changelog
│   └── task.php                    # Daemon Worker Root (Task Queue Poller)
├── server/                         # Runtime config, socket, dan file PID
├── wwwroot/                        # Direktori utama website yang dihosting
└── backup/                         # Direktori arsip cadangan (snapshot DB pre-update & dump SQL)
```

---

## 🗑️ Panduan Uninstal

Jika Anda ingin mencopot pemasangan Almusa Control Panel dari server:

```bash
sudo bash /www/panel/scripts/uninstall.sh
```
Atau tanpa prompt konfirmasi:
```bash
sudo bash /www/panel/scripts/uninstall.sh -y
```

**Hal yang dilakukan script uninstaller:**
1. Menghentikan dan menonaktifkan unit service `mypanel-web` dan `mypanel-worker`.
2. Menghapus file unit di `/etc/systemd/system/`.
3. Menghapus symlink perintah `/usr/local/bin/mypanel`.
4. **Mencadangkan basis data SQLite secara aman** ke `/root/almusa_panel_backup_<tanggal>.tar.gz`.
5. Menghapus folder `/www/panel`. File proyek website di `/www/wwwroot` dan database MySQL Anda **tetap aman dan tidak terhapus**.

---

<p align="center">
  Dibuat dengan ❤️ untuk kemudahan pengelolaan server Linux mandiri yang aman dan berkecepatan tinggi.
</p>
