<?php

namespace App\Core;

class Crypto
{
    private const PREFIX = 'enc:v1:';
    private static ?string $key = null;

    /**
     * Resolves the encryption master key (32 bytes).
     * If data/secret.key doesn't exist, it generates one automatically with mode 0600.
     */
    public static function getKey(): string
    {
        if (self::$key !== null) {
            return self::$key;
        }

        $root = defined('PANEL_ROOT') ? PANEL_ROOT : dirname(__DIR__, 2);
        $keyFile = $root . '/data/secret.key';

        if (file_exists($keyFile)) {
            $raw = @file_get_contents($keyFile);
            if ($raw !== false && strlen($raw) === 32) {
                self::$key = $raw;
                return self::$key;
            }
        }

        // Generate high-entropy 256-bit master key
        $newKey = random_bytes(32);
        @file_put_contents($keyFile, $newKey, LOCK_EX);
        Database::fixPermissions($keyFile, 0600);

        self::$key = $newKey;
        return self::$key;
    }

    /**
     * Encrypts plaintext string using Sodium (XChaCha20-Poly1305) or AES-256-GCM fallback.
     */
    public static function encrypt(string $plain): string
    {
        if ($plain === '') {
            return '';
        }

        // Avoid double encryption
        if (self::isEncrypted($plain)) {
            return $plain;
        }

        $key = self::getKey();

        if (extension_loaded('sodium')) {
            $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
            $ciphertext = sodium_crypto_secretbox($plain, $nonce, $key);
            return self::PREFIX . 'sod:' . base64_encode($nonce . $ciphertext);
        }

        // Fallback to OpenSSL AES-256-GCM
        $iv = random_bytes(12);
        $tag = '';
        $ciphertext = openssl_encrypt($plain, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
        return self::PREFIX . 'gcm:' . base64_encode($iv . $tag . $ciphertext);
    }

    /**
     * Decrypts ciphertext. If the input is not encrypted, returns as-is for backward compatibility.
     */
    public static function decrypt(?string $payload): ?string
    {
        if (empty($payload)) {
            return $payload;
        }

        if (!str_starts_with($payload, self::PREFIX)) {
            return $payload;
        }

        $body = substr($payload, strlen(self::PREFIX));
        $key = self::getKey();

        if (str_starts_with($body, 'sod:')) {
            $raw = base64_decode(substr($body, 4), true);
            if ($raw === false || strlen($raw) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
                return null;
            }
            $nonce = substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
            $cipher = substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
            if (extension_loaded('sodium')) {
                $decrypted = sodium_crypto_secretbox_open($cipher, $nonce, $key);
                return $decrypted !== false ? $decrypted : null;
            }
            return null;
        }

        if (str_starts_with($body, 'gcm:')) {
            $raw = base64_decode(substr($body, 4), true);
            if ($raw === false || strlen($raw) <= 28) {
                return null;
            }
            $iv = substr($raw, 0, 12);
            $tag = substr($raw, 12, 16);
            $cipher = substr($raw, 28);
            $decrypted = openssl_decrypt($cipher, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
            return $decrypted !== false ? $decrypted : null;
        }

        return null;
    }

    /**
     * Checks if a string is encrypted with Crypto::PREFIX.
     */
    public static function isEncrypted(?string $value): bool
    {
        return !empty($value) && str_starts_with($value, self::PREFIX);
    }
}
