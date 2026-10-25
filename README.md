# Almusa Server Control Panel (SLEMP-PHP Edition)

> **Lightweight, Modular & Secure Standalone Linux Server Control Panel**  
> Terinspirasi oleh arsitektur **basoro/slemp** dan **aaPanel**, dibangun murni menggunakan **PHP Standalone (CLI & Built-in Engine)**, **SQLite3 (PDO WAL Mode)**, **Modular Bash Scripts**, serta integrasi bawaan **Cloudflare Tunnel (`cloudflared`)**.

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
7. [Panduan Perintah CLI (`mypanel`)](#-panduan-perintah-cli-mypanel)
8. [Struktur Direktori Sistem](#-struktur-direktori-sistem)
9. [Panduan Uninstal](#-panduan-uninstal)

---

## 🌟 Fitur Unggulan

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
Di server VPS atau mesin lokal Anda, masuk ke direktori proyek dan jalankan script installer sebagai `root`:

```bash
cd /home/afandi/almusa-panel
sudo bash scripts/install.sh
```

### 2. Apa yang Dilakukan oleh Installer?
1. Menginstal paket minimal sistem yang dibutuhkan engine: `curl`, `sqlite3`, `php-cli`, `php-sqlite3`, `php-curl`, `php-mbstring`, `php-xml`.
2. Mendaftarkan repositori `ppa:ondrej/php` agar App Store siap memasang multi-versi PHP kapan saja.
3. Menyiapkan struktur direktori `/www/{panel,server,wwwroot,backup}` dengan izin ketat (`chmod 700 /www/panel/data`).
4. Menginisialisasi basis data SQLite, membuat kredensial admin default, dan membuat token secret entrance acak.
5. Mendaftarkan dan mengaktifkan 2 unit service `systemd`:
   - `mypanel-web.service`: Web interface panel berjalan otomatis saat server *boot*.
   - `mypanel-worker.service`: Daemon eksekusi tugas background root.
6. Membuat symlink global perintah `/usr/local/bin/mypanel`.

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
- Klik tombol **Terminal** di pojok kanan atas navbar untuk melihat jendela terminal gelap live streaming dari log `/tmp/panelExec.log`.
- Dilengkapi tombol **Auto-scroll ON/OFF** dan **Kosongkan Log**.

---

## ⌨️ Panduan Perintah CLI (`mypanel`)

Panel dilengkapi perintah CLI bawaan yang dapat dipanggil langsung dari terminal SSH:

```bash
mypanel [perintah]
```

| Perintah | Deskripsi |
| :--- | :--- |
| `mypanel start` | Menjalankan seluruh service panel (Web interface & Worker daemon) |
| `mypanel stop` | Menghentikan seluruh service panel |
| `mypanel restart` | Me-restart seluruh service panel |
| `mypanel status` | Memeriksa status keaktifan Web Interface dan Worker Daemon |
| `mypanel default` | Menampilkan ringkasan informasi login (URL, entrance path, user, password, port) |
| `mypanel` *(tanpa argumen)* | Membuka **menu interaktif bernomor 1–8** di terminal |

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
 0) Keluar
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
│   │   │   ├── Response.php        # JSON & View Renderer
│   │   │   └── SystemMonitor.php   # Pembaca Metrik Linux (/proc/stat, /proc/meminfo)
│   │   └── Controllers/
│   │       ├── AuthController.php
│   │       ├── DashboardController.php
│   │       ├── SiteController.php      # Virtual Host & PHP Switcher
│   │       ├── DatabaseController.php  # MySQL UTF8MB4 & User Provisioning
│   │       ├── PluginController.php    # App Store & Extension Installer
│   │       ├── TunnelController.php    # Cloudflare Quick & Zero Trust Tunnel
│   │       └── TaskController.php      # Streaming Log /tmp/panelExec.log
│   ├── data/
│   │   ├── panel.db                # Basis Data SQLite (chmod 600)
│   │   ├── entrance.txt            # Token Path Rahasia Masuk
│   │   └── initial_credentials.txt # Catatan Kredensial Awal
│   ├── public/                     # Document Root Server Built-in
│   │   ├── index.php               # Front Controller
│   │   └── assets/                 # CSS & Javascript Interaktif
│   ├── views/                      # Template Tampilan Antarmuka
│   ├── plugins/                    # Modular Service Installers (Nginx, PHP, MySQL, Cloudflared)
│   ├── rewrite/nginx/              # Template URL Rewrite (Default, Laravel, WordPress, ThinkPHP)
│   ├── scripts/
│   │   ├── install.sh              # Master Bootstrap Installer
│   │   ├── uninstall.sh            # Safe Uninstaller dengan Pencadangan SQLite
│   │   └── vhost_manager.sh        # Generator Nginx Virtual Host
│   ├── cli.sh                      # Helper CLI (symlinked ke /usr/local/bin/mypanel)
│   └── task.php                    # Daemon Worker Root (Task Queue Poller)
├── server/                         # Runtime config, socket, dan file PID
├── wwwroot/                        # Direktori utama website yang dihosting
└── backup/                         # Direktori arsip cadangan (.sql.gz & snapshot situs)
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
