# Rencana Implementasi Jangka Panjang: Arsitektur PHP Agnostik & Dukungan PHP 8.5
**Almusa Server Control Panel**  
*Dokumen Rencana Teknis & Panduan Arsitektur Masa Depan (Future-Proof Roadmap)*  
*Target OS: Ubuntu 20.04 / 22.04 / 24.04 / 26.04 LTS, Debian 11 / 12 / 13*

---

## 1. Latar Belakang & Filosofi Arsitektur

### 1.1 Masalah yang Dihadapi Saat Ini
Pada instalasi Almusa Panel di sistem operasi generasi baru seperti **Ubuntu 26.04.1 LTS (`resolute`)**, proses instalasi mengalami kegagalan pada Web UI (`mypanel-web.service` berstatus `STOPPED`, error `status=203/EXEC`). Hal ini terjadi karena:
1. Installer mengasumsikan dan memaksakan runtime tunggal (`PANEL_PHP_VER="8.3"`).
2. PPA eksternal pihak ketiga (`ppa:ondrej/php`) belum menerbitkan berkas *Release* untuk codename `resolute`, sehingga ditolak oleh mekanisme keamanan APT.
3. APT secara otomatis mengeksekusi *fallback* paket generik `php-fpm` yang memasang **PHP 8.5** bawaan resmi Ubuntu 26.04.
4. Skrip installer gagal mendeteksi binary `/usr/sbin/php-fpm8.5` karena melakukan pengecekan `command -v php-fpm` (Ubuntu/Debian tidak menyediakan symlink generic `php-fpm`), lalu jatuh ke *fallback hardcoded* string `/usr/sbin/php-fpm8.3` yang tidak ada di disk.

### 1.2 Prinsip Arsitektur Jangka Panjang: *Dual-Plane Architecture*
Untuk menjamin Almusa Panel dapat diinstal dan berjalan mulus selama 5–10 tahun ke depan di berbagai varian Linux, arsitektur panel harus memisahkan dua ranah operasi secara tegas:

```
┌─────────────────────────────────────────────────────────────────────────────┐
│                            HOST SERVER (LINUX)                              │
└──────────────────────────────────────┬──────────────────────────────────────┘
                                       │
         ┌─────────────────────────────┴─────────────────────────────┐
         ▼                                                           ▼
┌─────────────────────────────────┐         ┌─────────────────────────────────┐
│ CONTROL PLANE: PANEL CORE       │         │ DATA PLANE: TENANT WEBSITES     │
├─────────────────────────────────┤         ├─────────────────────────────────┤
│ • Komponen: Web UI, API, Task   │         │ • Komponen: Vhost Nginx, Socket │
│   Worker Daemon (mypanel-worker)│           PHP-FPM Klien, MySQL/MariaDB    │
│ • Database: SQLite / Internal   │         │ • Target: WordPress, Laravel,   │
│ • Prinsip: OS-Native First      │           Custom Web Apps                 │
│ • Runtime: Berjalan otomatis di │         │ • Prinsip: Multi-PHP On-Demand  │
│   PHP default bawaan distro     │         │ • Runtime: Pilihan multi-versi  │
│   (PHP 8.1 / 8.3 / 8.5 / dst)   │           (PHP 7.4 s/d 8.5)               │
│ • Dependensi: 0 PPA eksternal   │         │ • Dependensi: PPA jika didukung,│
│   (Zero External PPA for Panel) │           atau repo distro bawaan         │
└─────────────────────────────────┘         └─────────────────────────────────┘
```

1. **Control Plane (Agnostik & OS-Native):**
   * Web UI dan background worker panel **tidak boleh bergantung pada PPA pihak ketiga**.
   * Panel harus langsung memakai paket PHP bawaan repositori resmi distro host (PHP 8.1 di Ubuntu 22.04, PHP 8.3 di Ubuntu 24.04, PHP 8.5 di Ubuntu 26.04).
   * Web UI Almusa Panel terbukti sangat ringan, bersih, dan 100% kompatibel dengan PHP 8.1 hingga 8.5.
2. **Data Plane (Fleksibel & Multi-Versi):**
   * Website milik klien tetap diberikan kebebasan memilih versi PHP (`7.4` s/d `8.5`) melalui fitur Multi-PHP Manager.
   * Jika PPA pihak ketiga tersedia untuk distro tersebut, user dapat memasang multi-versi. Jika belum tersedia, sistem memberikan notifikasi yang informatif (*graceful handling*).

---

## 2. Matrix Dukungan OS & Status Runtime PHP (2026–2030)

| Distribusi Linux | Codename | PHP Bawaan Distro (Native) | Status PPA Ondrej | Dukungan Almusa Panel Web UI |
| :--- | :--- | :--- | :--- | :--- |
| **Ubuntu 20.04 LTS** | `focal` | PHP 7.4 | Stabil | Didukung (PHP 7.4 / 8.x via PPA) |
| **Ubuntu 22.04 LTS** | `jammy` | PHP 8.1 | Stabil | Didukung Native (PHP 8.1) |
| **Ubuntu 24.04 LTS** | `noble` | PHP 8.3 | Stabil | Didukung Native (PHP 8.3) |
| **Ubuntu 26.04 LTS** | `resolute` | **PHP 8.5** | Dalam Proses Transisi | **Didukung Native (PHP 8.5)** |
| **Debian 11** | `bullseye` | PHP 7.4 | Stabil | Didukung Native / Sury |
| **Debian 12** | `bookworm` | PHP 8.2 | Stabil | Didukung Native / Sury |
| **Debian 13** | `trixie` | PHP 8.4 | Stabil | Didukung Native / Sury |

---

## 3. Rencana Perubahan Kode per Komponen (Phase by Phase)

### Fase 1: Perbaikan Skrip Installer Utama (`scripts/install.sh`)

#### 1.1 Probe Ketersediaan PPA Cerdas (*Smart PPA Discovery*)
Skrip installer tidak boleh lagi mengeksekusi `add-apt-repository -y ppa:ondrej/php` secara membabi buta. Skrip harus mengecek apakah URL Release benar-benar ada di Launchpad sebelum mendaftarkannya:

```bash
# Lokasi: scripts/install.sh (Bagian pendaftaran PPA Ondrej Sury)
CODENAME="${VERSION_CODENAME:-}"
if [ -z "$CODENAME" ]; then
    CODENAME="$(lsb_release -sc 2>/dev/null || echo 'noble')"
fi

USE_ONDREJ_PPA=false
if [ "$OS" = "ubuntu" ]; then
    rm -f /etc/apt/sources.list.d/php.list
    echo -e "${BLUE}[*] Memeriksa ketersediaan PPA ondrej/php untuk Ubuntu '${CODENAME}'...${NC}"
    if curl -fsSLI "https://ppa.launchpadcontent.net/ondrej/php/ubuntu/dists/${CODENAME}/Release" >/dev/null 2>&1; then
        echo -e "${GREEN}[✔] PPA ondrej/php mendukung '${CODENAME}'. Mendaftarkan PPA...${NC}"
        add-apt-repository -y ppa:ondrej/php 2>/dev/null || true
        USE_ONDREJ_PPA=true
    else
        echo -e "${YELLOW}[!] PPA ondrej/php belum memiliki build untuk Ubuntu '${CODENAME}'.${NC}"
        echo -e "${BLUE}[*] Menggunakan paket runtime PHP resmi bawaan Ubuntu ${CODENAME}...${NC}"
        rm -f /etc/apt/sources.list.d/*ondrej* 2>/dev/null || true
    fi
elif [ "$OS" = "debian" ]; then
    # Debian Sury Keyring & Repo
    mkdir -p /usr/share/keyrings
    if [ ! -s /usr/share/keyrings/debsuryorg-archive-keyring.gpg ]; then
        curl -sSL https://packages.sury.org/php/apt.gpg -o /usr/share/keyrings/debsuryorg-archive-keyring.gpg || true
    fi
    echo "deb [signed-by=/usr/share/keyrings/debsuryorg-archive-keyring.gpg] https://packages.sury.org/php/ ${CODENAME} main" > /etc/apt/sources.list.d/php.list
fi
apt-get update -y || true
```

#### 1.2 Instalasi Paket Runtime Agnostik
Jika PPA tersedia, installer memasang PHP 8.3/versi LTS. Jika PPA tidak tersedia (misal di Ubuntu 26.04), installer langsung memasang paket generic bawaan distro (`php-fpm`, `php-cli`, dll.):

```bash
# Lokasi: scripts/install.sh (Instalasi paket PHP)
echo -e "${BLUE}[*] Memasang runtime engine panel...${NC}"
INSTALL_SUCCESS=false

# Coba pasang PHP 8.3 jika paketnya ada (prioritas kompatibilitas legacy)
if [ "$USE_ONDREJ_PPA" = true ] && apt-cache show php8.3-fpm >/dev/null 2>&1; then
    DEBIAN_FRONTEND=noninteractive apt-get install -y \
        php8.3-fpm php8.3-cli php8.3-sqlite3 php8.3-mysql php8.3-curl \
        php8.3-mbstring php8.3-xml php8.3-zip && INSTALL_SUCCESS=true
fi

# Fallback otomatis ke paket PHP native bawaan distro
if [ "$INSTALL_SUCCESS" != true ]; then
    echo -e "${BLUE}[*] Memasang paket PHP bawaan repositori resmi sistem operasi...${NC}"
    DEBIAN_FRONTEND=noninteractive apt-get install -y \
        php-fpm php-cli php-sqlite3 php-mysql php-curl php-mbstring php-xml php-zip || true
fi
```

#### 1.3 Deteksi Binary FPM & CLI Dinamis (*Zero Hardcoded Path*)
Mengganti logika deteksi rapuh pada baris 224–225:

```bash
# Lokasi: scripts/install.sh (baris 224-227)
# 1. Deteksi binary PHP CLI
PHP_CLI_BIN=$(command -v php || find /usr/bin -maxdepth 1 \( -type f -o -type l \) -name "php[0-9]*" 2>/dev/null | sort -V | tail -n 1)

# 2. Deteksi binary PHP-FPM nyata di /usr/sbin
PHP_FPM_BIN=$(find /usr/sbin -maxdepth 1 \( -type f -o -type l \) -name "php-fpm[0-9]*" 2>/dev/null | sort -V | tail -n 1)
if [ -z "$PHP_FPM_BIN" ]; then
    PHP_FPM_BIN=$(command -v php-fpm || true)
fi

# Jika masih kosong, cari alternatif di seluruh PATH
if [ -z "$PHP_FPM_BIN" ] || [ ! -x "$PHP_FPM_BIN" ]; then
    echo -e "${RED}[ERROR] Binary PHP-FPM tidak ditemukan di sistem! Pastikan paket php-fpm terpasang.${NC}"
    exit 1
fi

echo -e "${GREEN}[✔] Terdeteksi PHP-FPM binary: ${PHP_FPM_BIN}${NC}"
echo -e "${GREEN}[✔] Terdeteksi PHP CLI binary : ${PHP_CLI_BIN}${NC}"
```

#### 1.4 Pembaruan Restart Service Systemd
Pada baris 641–643 di `scripts/install.sh`:
```bash
# Restart FPM service default sistem jika ada
DETECTED_PHP_VER=$(${PHP_CLI_BIN} -r 'echo PHP_MAJOR_VERSION . "." . PHP_MINOR_VERSION;' 2>/dev/null || echo "")
if [ -n "$DETECTED_PHP_VER" ] && systemctl list-unit-files "php${DETECTED_PHP_VER}-fpm.service" >/dev/null 2>&1; then
    systemctl restart "php${DETECTED_PHP_VER}-fpm" 2>/dev/null || true
fi
```

---

### Fase 2: Pembaruan Updater Panel (`scripts/update_panel.sh`)

Pada skrip pembaruan diri (*self-updater*), ganti pemanggilan kaku `php8.3`:
```bash
# Lokasi: scripts/update_panel.sh (baris 20-24)
# Deteksi binary PHP yang valid secara dinamis
PHP_BIN=$(command -v php || find /usr/bin -maxdepth 1 \( -type f -o -type l \) -name "php[0-9]*" 2>/dev/null | sort -V | tail -n 1)
if [ -z "$PHP_BIN" ] || [ ! -x "$PHP_BIN" ]; then
    PHP_BIN="/usr/bin/php"
fi

php() {
    "$PHP_BIN" "$@"
}
```

---

### Fase 3: Pembersihan Konfigurasi FPM (`config/panel-fpm.conf`)

Hapus opsi usang yang tidak lagi didukung di PHP 8 modern:
```ini
; Lokasi: config/panel-fpm.conf baris 35-43
; OPcache Performance Optimization for Panel
php_admin_value[opcache.enable] = 1
php_admin_value[opcache.enable_cli] = 0
php_admin_value[opcache.memory_consumption] = 32
php_admin_value[opcache.interned_strings_buffer] = 8
php_admin_value[opcache.max_accelerated_files] = 2000
php_admin_value[opcache.revalidate_freq] = 60
; HAPUS BARIS INI: php_admin_value[opcache.fast_shutdown] = 1 (sudah usang di PHP modern)
php_admin_value[opcache.save_comments] = 1
```

---

### Fase 4: Peningkatan Multi-PHP Installer Plugin (`plugins/php/install.sh`)

Ketika pengguna ingin memasang versi PHP tertentu untuk website di server Ubuntu 26.04:
1. Skrip `ensure_php_repo()` mengecek apakah codename OS memiliki Release di PPA.
2. Jika belum ada (misal `resolute`), skrip secara cerdas meminjam suite LTS stabil sebelumnya (`noble`):
```bash
# Lokasi: plugins/php/install.sh
if [ "$DISTRO_ID" = "ubuntu" ]; then
    rm -f /etc/apt/sources.list.d/php.list
    if curl -fsSLI "https://ppa.launchpadcontent.net/ondrej/php/ubuntu/dists/${CODENAME}/Release" >/dev/null 2>&1; then
        add-apt-repository -y ppa:ondrej/php 2>/dev/null || true
    else
        echo "[INFO] PPA ondrej/php belum rilis untuk '${CODENAME}'. Menggunakan upstream LTS 'noble'..."
        cat << 'EOF' > /etc/apt/sources.list.d/ondrej-php-fallback.sources
Types: deb
URIs: https://ppa.launchpadcontent.net/ondrej/php/ubuntu/
Suites: noble
Components: main
Signed-By: /usr/share/keyrings/debsuryorg-archive-keyring.gpg
EOF
        mkdir -p /usr/share/keyrings
        curl -sSL https://packages.sury.org/php/apt.gpg -o /usr/share/keyrings/debsuryorg-archive-keyring.gpg 2>/dev/null || true
    fi
fi
```

---

### Fase 5: Sinkronisasi Layanan Pemantau (*Watchdog & Monitoring*)

Pastikan komponen pemantau sistem mengenali layanan PHP 8.5 dan PHP masa depan:
* [`app/Controllers/WatchdogController.php`](file:///d:/master%20panel%20almusa/almusa-panel/app/Controllers/WatchdogController.php): Sudah terdaftar label `'php8.5-fpm' => 'PHP 8.5 FastCGI Process Manager'` dan socket `/run/php/php8.5-fpm.sock`.
* [`app/Services/WatchdogService.php`](file:///d:/master%20panel%20almusa/almusa-panel/app/Services/WatchdogService.php): Sudah terdaftar kandidat pemantauan `php8.5-fpm`.
* [`app/Controllers/SiteController.php`](file:///d:/master%20panel%20almusa/almusa-panel/app/Controllers/SiteController.php) & [`DashboardController.php`](file:///d:/master%20panel%20almusa/almusa-panel/app/Controllers/DashboardController.php): Sudah memuat opsi versi `['7.4', '8.0', '8.1', '8.2', '8.3', '8.4', '8.5']`.

---

## 4. Panduan Verifikasi & Pengujian Mutu (QA Checklist)

Setelah implementasi diterapkan, lakukan pengujian bertahap:

- [ ] **Uji Instalasi Bersih (Clean Install) di Ubuntu 26.04:**
  * Menjalankan `sudo bash scripts/install.sh`.
  * Memastikan tidak ada pesan error `E: The repository ... does not have a Release file`.
  * Memastikan `PHP_FPM_BIN` terdeteksi `/usr/sbin/php-fpm8.5`.
  * Memastikan `mypanel-web.service` berstatus `active (running)`.
  * Memastikan `mypanel-worker.service` berstatus `active (running)`.
  * Memastikan `mypanel status` menampilkan kedua service berstatus **ACTIVE**.
- [ ] **Uji Akses Web UI:**
  * Login ke Web UI melalui browser port 64448 (atau port yang dipilih).
  * Dashboard memuat statistik CPU, RAM, Disk, dan Uptime dengan benar.
  * Navigasi antar halaman (Website, Database, File Manager, Terminal) berjalan tanpa notice/warning PHP.
- [ ] **Uji Operasi MySQL / Database:**
  * Menambah basis data dan pengguna MySQL melalui menu Database.
  * Memastikan koneksi PDO PHP 8.5 ke MariaDB/MySQL berjalan sukses.
- [ ] **Uji Pembuatan Website Klien (Vhost & Multi-PHP):**
  * Membuat website baru dengan target PHP 8.5.
  * Beralih versi PHP website (*switch PHP*) ke versi lain yang tersedia.
  * Memverifikasi file `/run/php/php8.5-fpm.sock` merespons permintaan HTTP Nginx.
- [ ] **Uji Mundur (Backward Compatibility) di Ubuntu 22.04 & 24.04:**
  * Memastikan installer tetap 100% mulus saat dijalankan di Ubuntu 22.04 (PHP 8.1) dan Ubuntu 24.04 (PHP 8.3).

---

## 5. Rencana Pemulihan & Rollback Plan

Jika dalam situasi tertentu terdapat kendala pada server yang telah terpasang:
1. **Rollback Service Web Panel ke Versi PHP Tertentu:**
   ```bash
   # Ganti path binary di service unit systemd
   sudo sed -i 's|/usr/sbin/php-fpm[0-9.]*|/usr/sbin/php-fpm8.3|g' /etc/systemd/system/mypanel-web.service
   sudo systemctl daemon-reload
   sudo systemctl restart mypanel-web.service
   ```
2. **Pembersihan Sumber APT PPA Bermasalah:**
   ```bash
   sudo rm -f /etc/apt/sources.list.d/*ondrej*
   sudo apt-get update
   ```
3. **Pencadangan Data Basis Data Panel:**
   Seluruh data konfigurasi panel tersimpan aman di `/www/panel/data/panel.db` (SQLite) dengan snapshot otomatis yang dibuat sebelum setiap proses instalasi/pembaruan dijalankan.

