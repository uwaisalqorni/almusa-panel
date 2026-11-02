Laporan Analisa Issue: Kegagalan Instalasi php8.3 & Web UI STOPPED pada Ubuntu 26.04 LTS (resolute)

Tanggal Analisa: 2 November 2026
Lingkungan Terdeteksi: Ubuntu 26.04.1 LTS (Resolute Raccoon, VERSION_CODENAME=resolute)
Status Service Saat Ini: mypanel-web.service gagal start (code=exited, status=203/EXEC mencari /usr/sbin/php-fpm8.3)
Binary Terpasang di Server: /usr/bin/php8.5 dan /usr/sbin/php-fpm8.5

1. Jawaban Singkat: Apakah Ini Masalah dari Server Sebelumnya?

TIDAK. Masalah ini 100% bukan disebabkan oleh sisa instalasi atau konfigurasi dari server sebelumnya.

Penyebab utamanya adalah server baru ini menggunakan Ubuntu 26.04.1 LTS (resolute), yang memicu rantai 4 efek domino pada scripts/install.sh:

PPA ondrej/php belum mendukung Ubuntu resolute: Maintainer PPA Ondřej Surý belum menerbitkan file Release untuk codename resolute di Launchpad, sehingga APT menolak repositori tersebut.
Mengapa tidak bisa mengambil PHP 8.3? Karena PPA ondrej/php ditolak, APT hanya membaca repositori resmi bawaan Ubuntu 26.04 (ubuntu.sources). Repositori resmi Ubuntu hanya menyediakan 1 versi PHP bawaan per rilis OS, dan untuk Ubuntu 26.04 (resolute) versi bawaannya adalah PHP 8.5 (paket php8.3-* tidak ada di repo resmi Ubuntu 26.04).
Mengapa otomatis menginstal PHP 8.5? Karena perintah apt-get install php8.3-* gagal, blok fallback || apt-get install php-fpm php-cli ... di scripts/install.sh (baris 221–222) dieksekusi. Di Ubuntu 26.04, paket generik php-fpm otomatis menarik php8.5-fpm.
Mengapa Web UI menjadi STOPPED? Karena bug deteksi binary FPM di scripts/install.sh (baris 224) yang gagal mengenali /usr/sbin/php-fpm8.5 dan tetap menulis /usr/sbin/php-fpm8.3 (yang tidak ada di disk) ke dalam /etc/systemd/system/mypanel-web.service.
2. Alur Kronologi Kejadian (Efek Domino)
3. Analisa Teknis Mendalam per Tahap
A. Bukti pada Konfigurasi APT Server Saat Ini

Saat installer menjalankan add-apt-repository -y ppa:ondrej/php pada scripts/install.sh baris 201, terbentuk berkas /etc/apt/sources.list.d/ondrej-ubuntu-php-resolute.sources:

yaml
Types: deb
URIs: https://ppa.launchpadcontent.net/ondrej/php/ubuntu/
Suites: resolute
Components: main

Karena server Launchpad ondrej/php belum memiliki direktori dists/resolute/, APT memblokir repo tersebut. Selama file .sources dengan Suites: resolute ini masih ada, setiap perintah apt-get update di server juga akan terus menampilkan pesan error does not have a Release file.

B. Mengapa mypanel-worker Jalan (ACTIVE), Sedangkan mypanel-web Mati (STOPPED)?

Perhatikan kode deteksi binary di scripts/install.sh baris 224–225:

bash
PHP_FPM_BIN=$(command -v php-fpm8.3 || command -v php-fpm || echo "/usr/sbin/php-fpm8.3")
PHP_CLI_BIN=$(command -v php8.3 || command -v php || echo "/usr/bin/php8.3")

Hasil pengecekan berkas binary nyata di server Anda saat ini:

/usr/bin/php 
→
→ ADA (symlink ke /etc/alternatives/php 
→
→ /usr/bin/php8.5). Maka PHP_CLI_BIN terisi /usr/bin/php, sehingga Worker Daemon (mypanel-worker.service) berjalan normal.
/usr/sbin/php-fpm 
→
→ TIDAK ADA! Di Debian/Ubuntu, paket php8.5-fpm menaruh binary dengan nama versi eksplisit /usr/sbin/php-fpm8.5 dan tidak pernah membuat symlink /usr/sbin/php-fpm tanpa angka versi.
Akibatnya:
command -v php-fpm8.3 
→
→ Gagal (tidak ada).
command -v php-fpm 
→
→ Gagal (tidak ada).
Jatuh ke fallback ke-3: echo "/usr/sbin/php-fpm8.3".

Hasil pemeriksaan systemctl status mypanel-web.service di server Anda membuktikan hal ini:

text
Process: 23456 ExecStart=/usr/sbin/php-fpm8.3 -F -y /www/panel/config/panel-fpm.conf (code=exited, status=203/EXEC)

Kode status=203/EXEC pada systemd berarti file binary /usr/sbin/php-fpm8.3 tidak ditemukan di sistem.

NOTE

Hasil uji konfigurasi /usr/sbin/php-fpm8.5 -t -y /www/panel/config/panel-fpm.conf di server Anda menunjukkan: NOTICE: configuration file /www/panel/config/panel-fpm.conf test is successful Artinya engine panel Almusa sebenarnya 100% siap berjalan di atas /usr/sbin/php-fpm8.5 begitu path ExecStart diperbaiki!

4. Dua Opsi Solusi Perbaikan

Anda memiliki 2 pilihan strategi tergantung apakah Anda ingin:

Opsi 1 (Direkomendasikan): Panel langsung berjalan menggunakan php-fpm8.5 bawaan Ubuntu 26.04 DAN/ATAU tetap bisa menginstal multi-versi PHP (8.3, 8.2, dll.) dengan mengarahkan suite PPA ondrej/php ke noble (Ubuntu 24.04 LTS).
Opsi 2: Memperbaiki kode sumber scripts/install.sh dan plugins/php/install.sh agar masalah ini tidak pernah terulang lagi saat install baru di Ubuntu versi berapa pun.
I. Solusi Cepat (Hotfix di Terminal Server Saat Ini)

Jalankan perintah berikut di terminal sebagai root (atau dengan sudo) untuk menyalakan Web UI sekarang juga:

bash
# 1. Perbaiki ExecStart mypanel-web.service agar memakai /usr/sbin/php-fpm8.5 yang tersedia
sudo sed -i 's|/usr/sbin/php-fpm8.3|/usr/sbin/php-fpm8.5|g' /etc/systemd/system/mypanel-web.service
# 2. Reload systemd dan start kembali mypanel-web
sudo systemctl daemon-reload
sudo systemctl restart mypanel-web.service
# 3. Verifikasi status panel (Web Interface kini akan ACTIVE)
mypanel status

Jika Anda juga ingin tetap memasang PHP 8.3 dari PPA ondrej/php pada Ubuntu resolute, ubah suite PPA dari resolute ke noble (24.04 LTS):

bash
sudo sed -i 's/Suites: resolute/Suites: noble/g' /etc/apt/sources.list.d/ondrej-ubuntu-php-resolute.sources
sudo apt-get update

(Catatan: Jika Ubuntu 26.04 memiliki versi library sistem libicu/libssl yang lebih baru dari paket noble, gunakan repositori bawaan Ubuntu 26.04 dengan menghapus /etc/apt/sources.list.d/ondrej-ubuntu-php-resolute.sources agar apt-get update bersih tanpa error).

II. Rekomendasi Perbaikan Permanen pada Kode Sumber (scripts/install.sh & Panel)

Berikut adalah daftar perubahan kode yang perlu diterapkan pada repositori Almusa Panel:

1. Perbaikan Deteksi Binary FPM Dinamis di scripts/install.sh (baris 224–225)

Ubah logika penentuan PHP_FPM_BIN agar mencari binary php-fpm* yang benar-benar ada di /usr/sbin:

bash
# Prioritaskan php-fpm8.3 jika ada; jika tidak, ambil binary php-fpm8.x/php-fpm terbaru yang terinstal di /usr/sbin
PHP_FPM_BIN=$(command -v php-fpm8.3 \
    || find /usr/sbin -maxdepth 1 \( -type f -o -type l \) -name "php-fpm[0-9]*" 2>/dev/null | sort -V | tail -n 1 \
    || command -v php-fpm \
    || echo "/usr/sbin/php-fpm8.3")
PHP_CLI_BIN=$(command -v php8.3 \
    || command -v php \
    || find /usr/bin -maxdepth 1 \( -type f -o -type l \) -name "php[0-9]*" 2>/dev/null | sort -V | tail -n 1 \
    || echo "/usr/bin/php")
2. Validasi Ketersediaan Suite PPA ondrej/php di scripts/install.sh (baris 199–209) & plugins/php/install.sh (baris 40–50)

Jangan biarkan file .sources PPA yang belum rilis merusak apt-get update:

bash
if [ "$OS" = "ubuntu" ]; then
    rm -f /etc/apt/sources.list.d/php.list
    # Cek apakah codename Ubuntu saat ini sudah tersedia di Launchpad ondrej/php
    if curl -fsSLI "https://ppa.launchpadcontent.net/ondrej/php/ubuntu/dists/${CODENAME}/Release" >/dev/null 2>&1; then
        add-apt-repository -y ppa:ondrej/php 2>/dev/null || true
    else
        echo -e "${YELLOW}[!] PPA ondrej/php belum menyediakan Release untuk Ubuntu '${CODENAME}'.${NC}"
        echo -e "${BLUE}[*] Menggunakan paket PHP resmi bawaan Ubuntu ${CODENAME}...${NC}"
        rm -f /etc/apt/sources.list.d/*ondrej* 2>/dev/null || true
    fi
fi
3. Perbaikan Restart Service FPM di scripts/install.sh (baris 641–643)

Jangan hardcode php8.3-fpm, melainkan restart service FPM yang terdeteksi:

bash
ACTIVE_PHP_VER=$(${PHP_CLI_BIN} -r 'echo PHP_MAJOR_VERSION . "." . PHP_MINOR_VERSION;' 2>/dev/null || echo "8.3")
if systemctl list-unit-files "php${ACTIVE_PHP_VER}-fpm.service" >/dev/null 2>&1; then
    systemctl restart "php${ACTIVE_PHP_VER}-fpm" 2>/dev/null || true
fi
4. Sinkronisasi Daftar Versi PHP 8.5 di app/Controllers/DashboardController.php (baris 36) & Default Website Baru di app/Controllers/SiteController.php
Di DashboardController.php baris 36, tambahkan '8.5' ke $phpVersions = ['7.4', '8.0', '8.1', '8.2', '8.3', '8.4', '8.5']; agar indikator chip PHP 8.5 tampil menyala di Dashboard.
Di  config/panel-fpm.conf baris 42, hapus php_admin_value[opcache.fast_shutdown] = 1 karena direktif ini sudah dihapus di PHP modern.