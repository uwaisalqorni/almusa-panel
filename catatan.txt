================================================================================
REKAPITULASI DISKUSI TEKNIS: ALMUSA PANEL, CLOUDFLARE, SSL, DAN SERVER LOKAL
================================================================================
Tanggal  : 29 Oktober 2026
Proyek   : Almusa Server Control Panel (SLEMP Edition)
Topik    : Arsitektur SSL Berbayar, Cloudflare Tunnel, dan Port Management Lokal
================================================================================

DAFTAR ISI:
1. Pemasangan SSL Berbayar dengan Domain Cloudflare
2. Pemasangan SSL Berbayar di Server Lokal (Ubuntu Server)
3. Alokasi Port Nginx & Panel di Server Lokal (Port 80 vs Port 8088 vs Port 64448)
4. Konsep Name-based Virtual Hosting pada Cloudflare Tunnel (Multi-Website Satu Port)
5. Rekomendasi Praktis Implementasi

================================================================================
1. PEMASANGAN SSL BERBAYAR DENGAN DOMAIN CLOUDFLARE
================================================================================
Pertanyaan:
Apakah SSL berbayar bisa dipasang ke panel Almusa yang domainnya sudah terhubung 
ke nameserver Cloudflare? Karena Cloudflare sudah otomatis mendapatkan SSL gratis, 
lalu bagaimana jika menggunakan SSL berbayar?

Analisa & Jawaban:
BISA, SANGAT BISA. Di Almusa Panel sudah tersedia fitur backend khusus:
(File: scripts/ssl_manager.sh action 'custom' / task 'site_custom_ssl').

Arsitektur 2 Lapis SSL saat Domain di Cloudflare:
  [Pengunjung / Browser] --(Jalur 1)--> [Cloudflare Edge] --(Jalur 2)--> [Server Origin / Almusa]

Penjelasan Skenario:
A. Mode Cloudflare Proxied (Awan Oranye / CDN Aktif):
   - Jalur 1 (Browser ke Cloudflare): Menggunakan Universal SSL bawaan Cloudflare.
     Sertifikat gembok hijau yang dilihat browser pengunjung adalah sertifikat Edge Cloudflare.
   - Jalur 2 (Cloudflare ke VPS/Almusa): Menggunakan SSL Berbayar yang Anda pasang di panel.
   - Manfaat Utama: Anda dapat mengaktifkan mode enkripsi Cloudflare: "Full (Strict)".
     Ini adalah standar keamanan tertinggi yang menjamin koneksi Cloudflare ke server 
     Anda 100% aman dan terenkripsi tanpa celah Man-in-the-Middle (MitM).
   - Catatan: Jika ingin sertifikat berbayar Anda (Sectigo/DigiCert dengan nama Anda) 
     langsung terlihat di browser pengunjung saat mode proxy, dibutuhkan fitur 
     Custom Certificate di Cloudflare (Paket Business/Enterprise).

B. Mode Cloudflare DNS Only (Awan Abu-abu / Proxy Nonaktif):
   - Trafik pengunjung langsung menuju IP Server Anda tanpa melalui CDN Cloudflare.
   - Browser pengunjung akan 100% membaca langsung sertifikat SSL berbayar 
     yang terpasang di Almusa Panel.

Keuntungan Memasang SSL Berbayar dibanding Let's Encrypt:
- Tidak bergantung pada validasi Certbot ACME HTTP-01 yang sering gagal/bentrok 
  dengan aturan firewall, redirect HTTPS, atau Bot Fight Mode di Cloudflare.
- Validasi pembelian SSL komersial dilakukan melalui DNS Record / Email.


================================================================================
2. PEMASANGAN SSL BERBAYAR DI SERVER LOKAL (UBUNTU SERVER)
================================================================================
Pertanyaan:
Jika Almusa Panel dipasang di server lokal (Ubuntu Server / Homelab / On-Premise) 
dengan Cloudflare, apakah bisa dipasang SSL berbayar?

Analisa & Jawaban:
BISA, 100% BISA.
Lokasi fisik server (apakah di VPS Datacenter atau komputer lokal di rumah/kantor) 
tidak membatasi fungsi SSL.

Fakta Teknis:
1. Sertifikat SSL terikat pada Nama Domain (FQDN), bukan pada IP fisik atau jenis mesin.
2. Validasi Pembelian:
   Saat membeli SSL komersial, Anda cukup memilih validasi via "DNS Validation 
   (CNAME / TXT Record)". Record tersebut ditambahkan di dashboard DNS Cloudflare. 
   Server lokal Anda bahkan tidak perlu dibuka ke publik saat proses penerbitan SSL.
3. Setelah file sertifikat (.crt/.pem) dan Private Key (.key) didapatkan, 
   Anda tinggal mengunggahnya ke menu Custom SSL di Almusa Panel.

Metode Menghubungkan Server Lokal ke Cloudflare:
- Metode 1 (Paling Direkomendasikan): Cloudflare Tunnel (cloudflared).
  Tidak memerlukan IP Publik Statis dan bebas kendala CGNAT internet rumahan.
- Metode 2: Port Forwarding di Router/Modem (buka port 80/443 ke IP lokal server).

Keuntungan Tambahan di Jaringan Lokal (LAN):
Jika Anda mengakses server melalui Wi-Fi/LAN lokal menggunakan domain tersebut 
(via Local DNS/hosts file), browser di jaringan lokal tidak akan menampilkan 
peringatan bahaya (merah), karena sertifikat yang terpasang adalah sertifikat resmi.


================================================================================
3. ALOKASI PORT NGINX & WEBSITE DI SERVER LOKAL
================================================================================
Pertanyaan:
Apakah jika diinstall di Ubuntu lokal server, Nginx atau website tersebut mendapat port 80?

Analisa & Jawaban:
Almusa Panel membagi port secara terisolasi untuk keamanan dan stabilitas:

1. Port Dashboard Panel Almusa:
   - Panel TIDAK menggunakan port 80.
   - Panel berjalan di port khusus: 64448 (dikonfigurasi di config/nginx-panel.conf).
   - Akses URL: http://IP-Lokal:64448/
   - Alasan: Agar dashboard panel administrator tidak terekspos di port standar web 
     dan tidak bentrok dengan website yang dihosting.

2. Port untuk Website (Virtual Host) di Almusa Panel:
   A. Website Tanpa SSL (HTTP):
      - Template default: plugins/nginx/conf/vhost_template.conf
      - Menggunakan: Port 8088 (listen 8088;)
      - Alasan Port 8088: Didesain khusus agar server lokal yang menjalankan aplikasi 
        seperti CasaOS, Pi-hole, atau Docker container lain di port 80 TIDAK bentrok. 
        Port ini juga menjadi target default service Cloudflare Quick Tunnel.
   
   B. Website dengan SSL Aktif (HTTPS):
      - Template: plugins/nginx/conf/vhost_ssl_template.conf
      - Nginx mendengarkan 3 port sekaligus:
        * Port 80   : Untuk HTTP-to-HTTPS redirect (301) & ACME Challenge.
        * Port 443  : Untuk trafik HTTPS utama (enkripsi SSL).
        * Port 8088 : Tetap dibuka untuk kompatibilitas Cloudflare Tunnel.

3. Jika Ingin Website Mendapat Port 80 Langsung:
   Jika server Ubuntu lokal murni (tidak ada CasaOS/Apache lain di port 80), 
   Anda dapat mengubah baris 'listen 8088;' pada vhost_template.conf menjadi:
   listen 80;
   listen [::]:80;
   listen 8088;


================================================================================
4. KONSEP NAME-BASED VIRTUAL HOSTING PADA CLOUDFLARE TUNNEL (MULTI-WEBSITE 1 PORT)
================================================================================
Pertanyaan:
Berarti port 8088 itu untuk koneksi ke Cloudflare ya setiap deploy website baru? 
Bukan setiap website beda-beda port??

Analisa & Jawaban:
BENAR SEKALI. Semua website menggunakan PORT YANG SAMA (port 8088). 
Anda TIDAK PERLU membuat port berbeda-beda untuk tiap website baru.

Cara Nginx Membedakan Banyak Website di Satu Port (Name-based Virtual Hosting):
Setiap request HTTP yang dikirimkan oleh browser atau Cloudflare membawa identitas 
header yang disebut "Host Header".
- Pengunjung membuka toko.com -> Paket membawa 'Host: toko.com' -> Nginx mengarahkan ke /www/wwwroot/toko.com
- Pengunjung membuka blog.com -> Paket membawa 'Host: blog.com' -> Nginx mengarahkan ke /www/wwwroot/blog.com
Keduanya sama-sama masuk melalui port 8088 di server lokal.

Cara Kerja Integrasi Cloudflare Tunnel di Almusa Panel:
(Lihat: app/Controllers/TunnelController.php & plugins/cloudflared/CloudflareApi.php)
1. Setiap kali website baru didaftarkan ke Tunnel, panel menambahkan "Ingress Rule" 
   di Cloudflare dengan target service lokal: http://127.0.0.1:8088.
2. Panel otomatis menambahkan konfigurasi 'httpHostHeader = domain_anda'.
3. Cloudflare Tunnel meneruskan trafik publik (dari port 80 & 443 di internet) 
   ke port 8088 lokal lengkap dengan nama domain aslinya.

Keuntungan Arsitektur Ini:
1. Anda bisa deploy puluhan hingga ratusan website tanpa takut kehabisan port.
2. Pengunjung di internet tetap mengakses alamat bersih: https://domainanda.com 
   tanpa perlu mengetik nomor port.
3. Manajemen firewall dan sistem sangat bersih dan terstruktur.


================================================================================
5. RINGKASAN REKOMENDASI UNTUK SERVER LOKAL + CLOUDFLARE
================================================================================
1. Untuk Akses Publik:
   Gunakan fitur Cloudflare Tunnel yang sudah terintegrasi di Almusa Panel. 
   Trafik publik otomatis terenkripsi dan diarahkan ke port 8088 lokal.
2. Untuk Pilihan Sertifikat SSL:
   - Jika ingin hemat biaya & tidak repot perpanjang tahunan:
     Gunakan "Cloudflare Origin CA Certificate" (Gratis di dashboard Cloudflare, 
     masa aktif s/d 15 tahun), lalu pasang via menu Custom SSL di Almusa Panel. 
     Ubah mode Cloudflare ke "Full (Strict)".
   - Jika ada kewajiban audit/kebijakan khusus:
     Beli SSL komersial, validasi via DNS Cloudflare, dan pasang via Custom SSL 
     di Almusa Panel. Keduanya berfungsi sempurna.
================================================================================
