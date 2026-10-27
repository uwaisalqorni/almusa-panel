<?php

namespace App\Services;

use App\Core\Database;

class NotificationService
{
    /**
     * Check if Telegram notifications are enabled and configured
     */
    public static function isTelegramConfigured(): bool
    {
        $enabled = Database::getSetting('notif_telegram_enabled', '0');
        $token   = Database::getSetting('notif_telegram_bot_token', '');
        $chatId  = Database::getSetting('notif_telegram_chat_id', '');

        return ($enabled === '1' && !empty($token) && !empty($chatId));
    }

    /**
     * Check if Discord notifications are enabled and configured
     */
    public static function isDiscordConfigured(): bool
    {
        $enabled = Database::getSetting('notif_discord_enabled', '0');
        $webhook = Database::getSetting('notif_discord_webhook_url', '');

        return ($enabled === '1' && !empty($webhook));
    }

    /**
     * Send HTML formatted message to Telegram Bot
     *
     * @param string $htmlMessage Message formatted in Telegram HTML
     * @param string|null $customToken Override token (e.g. for testing unsaved input)
     * @param string|null $customChatId Override chat ID
     * @return array{success: bool, message?: string, error?: string}
     */
    public static function sendTelegram(string $htmlMessage, ?string $customToken = null, ?string $customChatId = null): array
    {
        $token = $customToken ?: Database::getSetting('notif_telegram_bot_token', '');
        $chatId = $customChatId ?: Database::getSetting('notif_telegram_chat_id', '');

        if (empty($token) || empty($chatId)) {
            return [
                'success' => false,
                'error'   => 'Telegram Bot Token atau Chat ID belum dikonfigurasi.',
            ];
        }

        $apiUrl = "https://api.telegram.org/bot" . trim($token) . "/sendMessage";

        $postData = [
            'chat_id'                  => trim($chatId),
            'text'                     => $htmlMessage,
            'parse_mode'               => 'HTML',
            'disable_web_page_preview' => 'true',
        ];

        $ch = curl_init($apiUrl);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => http_build_query($postData),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 12,
            CURLOPT_CONNECTTIMEOUT => 6,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);

        $rawResponse = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr = curl_error($ch);
        curl_close($ch);

        if ($curlErr) {
            self::log("ERROR", "Gagal mengirim notifikasi Telegram: {$curlErr}");
            return [
                'success' => false,
                'error'   => "Gagal terhubung ke Telegram API: {$curlErr}",
            ];
        }

        $json = @json_decode((string)$rawResponse, true);
        if ($httpCode === 200 && !empty($json['ok'])) {
            self::log("INFO", "Notifikasi Telegram berhasil dikirim ke Chat ID {$chatId}");
            return [
                'success' => true,
                'message' => 'Notifikasi Telegram berhasil terkirim!',
            ];
        }

        $errorDesc = $json['description'] ?? ("HTTP {$httpCode}: " . substr((string)$rawResponse, 0, 120));
        self::log("WARN", "Telegram API menolak pengiriman (HTTP {$httpCode}): {$errorDesc}");

        return [
            'success' => false,
            'error'   => "Telegram API Error ({$httpCode}): {$errorDesc}",
        ];
    }

    /**
     * Send rich embed message to Discord Webhook
     */
    public static function sendDiscord(string $title, string $description, string $level = 'info', ?string $customWebhook = null): array
    {
        $webhook = $customWebhook ?: Database::getSetting('notif_discord_webhook_url', '');
        if (empty($webhook)) {
            return [
                'success' => false,
                'error'   => 'Discord Webhook URL belum dikonfigurasi.',
            ];
        }

        // Color mapping for Discord Embeds
        $color = match ($level) {
            'danger', 'error' => 0xEF4444, // Red
            'warning', 'warn' => 0xF59E0B, // Amber
            'success'         => 0x10B981, // Emerald
            default           => 0x3B82F6, // Blue
        };

        $serverName = gethostname() ?: 'Almusa Server';
        $serverIp = $_SERVER['SERVER_ADDR'] ?? gethostbyname(gethostname());

        $payload = [
            'username'   => 'Almusa Server Watchdog',
            'avatar_url' => 'https://raw.githubusercontent.com/twbs/icons/main/icons/shield-shaded.png',
            'embeds'     => [
                [
                    'title'       => $title,
                    'description' => $description,
                    'color'       => $color,
                    'timestamp'   => date('c'),
                    'footer'      => [
                        'text' => "Almusa Panel • {$serverName} ({$serverIp})",
                    ],
                ],
            ],
        ];

        $ch = curl_init(trim($webhook));
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($payload),
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 12,
            CURLOPT_CONNECTTIMEOUT => 6,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);

        $rawResponse = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr = curl_error($ch);
        curl_close($ch);

        if ($curlErr) {
            self::log("ERROR", "Gagal mengirim notifikasi Discord: {$curlErr}");
            return [
                'success' => false,
                'error'   => "Gagal terhubung ke Discord Webhook: {$curlErr}",
            ];
        }

        if ($httpCode >= 200 && $httpCode < 300) {
            self::log("INFO", "Notifikasi Discord Webhook berhasil dikirim.");
            return [
                'success' => true,
                'message' => 'Notifikasi Discord berhasil terkirim!',
            ];
        }

        self::log("WARN", "Discord Webhook menolak request (HTTP {$httpCode}): {$rawResponse}");
        return [
            'success' => false,
            'error'   => "Discord Webhook Error (HTTP {$httpCode}): " . substr((string)$rawResponse, 0, 100),
        ];
    }

    /**
     * Dispatch alert to all active channels
     */
    public static function dispatchAlert(string $title, string $telegramHtml, string $discordText, string $level = 'warning'): void
    {
        if (self::isTelegramConfigured()) {
            self::sendTelegram($telegramHtml);
        }

        if (self::isDiscordConfigured()) {
            self::sendDiscord($title, $discordText, $level);
        }
    }

    /**
     * TRIGGER 1: Service Down & Auto-Healing Restart Alert
     */
    public static function sendServiceIncidentAlert(string $service, string $oldStatus, bool $recovered, ?string $output = null): void
    {
        // Check if trigger is enabled in settings (default '1')
        if (Database::getSetting('notif_trigger_service_restart', '1') !== '1') {
            return;
        }

        $serverName = gethostname() ?: 'Almusa Server';
        $now = date('Y-m-d H:i:s');

        $resultBadge = $recovered
            ? "✅ <b>BERHASIL DIPULIHKAN</b> (active)"
            : "❌ <b>GAGAL PULIH</b> (memerlukan tindakan manual)";

        $level = $recovered ? 'warning' : 'danger';
        $title = $recovered
            ? "🚨 [ALMUSA WATCHDOG] Service '{$service}' Berhasil Dipulihkan"
            : "🔥 [ALMUSA ALERT] Service '{$service}' Gagal Pulih!";

        // Telegram HTML
        $tgMessage = "<b>" . ($recovered ? "🚨 [ALMUSA WATCHDOG] Insiden Terdeteksi" : "🔥 [ALMUSA DARURAT] Service Down") . "</b>\n";
        $tgMessage .= "━━━━━━━━━━━━━━━━━━━━\n";
        $tgMessage .= "<b>Layanan:</b> <code>{$service}</code>\n";
        $tgMessage .= "<b>Status Awal:</b> <code>{$oldStatus}</code>\n";
        $tgMessage .= "<b>Tindakan:</b> Auto-healing (<code>systemctl restart {$service}</code>)\n";
        $tgMessage .= "<b>Status Pemulihan:</b> {$resultBadge}\n";
        if (!empty($output)) {
            $tgMessage .= "<b>Output:</b> <code>" . htmlspecialchars(substr($output, 0, 150)) . "</code>\n";
        }
        $tgMessage .= "<b>Waktu:</b> {$now}\n";
        $tgMessage .= "━━━━━━━━━━━━━━━━━━━━\n";
        $tgMessage .= "<i>Server: {$serverName}</i>";

        // Discord plain text description
        $dcMessage = "**Layanan:** `{$service}`\n";
        $dcMessage .= "**Status Awal:** `{$oldStatus}`\n";
        $dcMessage .= "**Tindakan:** `systemctl restart {$service}`\n";
        $dcMessage .= "**Hasil:** " . ($recovered ? "✅ Berhasil Dipulihkan (active)" : "❌ Gagal Pulih") . "\n";
        $dcMessage .= "**Waktu:** {$now}";

        self::dispatchAlert($title, $tgMessage, $dcMessage, $level);
    }

    /**
     * TRIGGER 2: Disk Storage Usage Exceeds 85%
     */
    public static function sendDiskAlert(int $percent, string $used, string $total): void
    {
        if (Database::getSetting('notif_trigger_disk_usage', '1') !== '1') {
            return;
        }

        $serverName = gethostname() ?: 'Almusa Server';
        $now = date('Y-m-d H:i:s');
        $title = "⚠️ [ALMUSA MONITOR] Peringatan Kapasitas Disk Storage ({$percent}%)";

        $tgMessage = "⚠️ <b>[ALMUSA MONITOR] Kapasitas Disk Kritis</b>\n";
        $tgMessage .= "━━━━━━━━━━━━━━━━━━━━\n";
        $tgMessage .= "<b>Partisi:</b> <code>/</code> (Root)\n";
        $tgMessage .= "<b>Penggunaan:</b> <b>{$percent}%</b> (Ambang batas: 85%)\n";
        $tgMessage .= "<b>Kapasitas Terpakai:</b> {$used} / {$total}\n";
        $tgMessage .= "<b>Waktu:</b> {$now}\n";
        $tgMessage .= "━━━━━━━━━━━━━━━━━━━━\n";
        $tgMessage .= "<i>Perhatian: Harap segera bersihkan log usang atau file backup lama untuk mencegah server kehabisan ruang disk.</i>\n";
        $tgMessage .= "<i>Server: {$serverName}</i>";

        $dcMessage = "**Partisi:** `/` (Root)\n";
        $dcMessage .= "**Penggunaan:** **{$percent}%** (Ambang batas: 85%)\n";
        $dcMessage .= "**Kapasitas:** {$used} / {$total}\n";
        $dcMessage .= "**Waktu:** {$now}\n\n";
        $dcMessage .= "*Harap segera periksa dan bersihkan file cadangan/log usang.*";

        self::dispatchAlert($title, $tgMessage, $dcMessage, 'warning');
    }

    /**
     * TRIGGER 3: Brute-Force Login Attempt Warning (5x Consecutive Failures)
     */
    public static function sendBruteForceAlert(string $ip, string $targetUsername, int $attempts): void
    {
        if (Database::getSetting('notif_trigger_brute_force', '1') !== '1') {
            return;
        }

        $serverName = gethostname() ?: 'Almusa Server';
        $now = date('Y-m-d H:i:s');
        $title = "🛡️ [ALMUSA KEAMANAN] Indikasi Serangan Brute-Force dari IP {$ip}";

        $tgMessage = "🛡️ <b>[ALMUSA KEAMANAN] Indikasi Serangan Brute-Force</b>\n";
        $tgMessage .= "━━━━━━━━━━━━━━━━━━━━\n";
        $tgMessage .= "<b>Alamat IP:</b> <code>{$ip}</code>\n";
        $tgMessage .= "<b>Target Username:</b> <code>" . htmlspecialchars($targetUsername) . "</code>\n";
        $tgMessage .= "<b>Percobaan Gagal:</b> {$attempts} kali berturut-turut\n";
        $tgMessage .= "<b>Tindakan Keamanan:</b> 🔒 <b>IP DIBLOKIR SEMENTARA (15 Menit)</b>\n";
        $tgMessage .= "<b>Waktu Insiden:</b> {$now}\n";
        $tgMessage .= "━━━━━━━━━━━━━━━━━━━━\n";
        $tgMessage .= "<i>Akses dari alamat IP ini telah dibekukan otomatis oleh RateLimiter panel.</i>\n";
        $tgMessage .= "<i>Server: {$serverName}</i>";

        $dcMessage = "**Alamat IP:** `{$ip}`\n";
        $dcMessage .= "**Target Username:** `{$targetUsername}`\n";
        $dcMessage .= "**Percobaan Gagal:** {$attempts} kali berturut-turut\n";
        $dcMessage .= "**Tindakan:** 🔒 IP dikunci selama 15 menit\n";
        $dcMessage .= "**Waktu:** {$now}";

        self::dispatchAlert($title, $tgMessage, $dcMessage, 'danger');
    }

    /**
     * Send test notification to Telegram
     */
    public static function testTelegram(?string $token = null, ?string $chatId = null): array
    {
        $serverName = gethostname() ?: 'Almusa Server';
        $serverIp = $_SERVER['SERVER_ADDR'] ?? gethostbyname(gethostname());
        $now = date('Y-m-d H:i:s');

        $message = "🤖 <b>[ALMUSA PANEL] Uji Coba Notifikasi Telegram</b>\n";
        $message .= "━━━━━━━━━━━━━━━━━━━━\n";
        $message .= "Koneksi bot Telegram ke <b>Almusa Server Control Panel</b> berhasil terhubung dengan sempurna!\n\n";
        $message .= "<b>Nama Host:</b> <code>{$serverName}</code>\n";
        $message .= "<b>IP Server:</b> <code>{$serverIp}</code>\n";
        $message .= "<b>Waktu Uji:</b> {$now}\n";
        $message .= "━━━━━━━━━━━━━━━━━━━━\n";
        $message .= "<i>Notifikasi darurat insiden layanan (Watchdog), disk storage penuh, dan keamanan brute-force akan otomatis dikirimkan ke chat ini.</i>";

        return self::sendTelegram($message, $token, $chatId);
    }

    /**
     * Send test notification to Discord
     */
    public static function testDiscord(?string $webhook = null): array
    {
        $title = "✅ [ALMUSA PANEL] Uji Coba Discord Webhook Berhasil!";
        $desc = "Integrasi Discord Webhook dengan Almusa Server Control Panel telah aktif dan siap menerima peringatan darurat sistem.";

        return self::sendDiscord($title, $desc, 'success', $webhook);
    }

    /**
     * Log notification events to data/panel_notification.log
     */
    public static function log(string $level, string $message): void
    {
        $root = defined('PANEL_ROOT') ? PANEL_ROOT : dirname(__DIR__, 2);
        $file = $root . '/data/panel_notification.log';
        $timestamp = date('Y-m-d H:i:s');
        $line = "[{$timestamp}] [{$level}] {$message}\n";
        @file_put_contents($file, $line, FILE_APPEND | LOCK_EX);
    }
}
