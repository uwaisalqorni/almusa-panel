<?php

namespace App\Core;

/**
 * Helper class for safely parsing and updating Laravel .env files
 */
class EnvManager
{
    /**
     * Resolve project root from a site docroot or directory.
     * E.g. /www/wwwroot/bidan-okta/public -> /www/wwwroot/bidan-okta
     */
    public static function resolveProjectRoot(string $path): string
    {
        $path = rtrim($path, '/');
        if (str_ends_with($path, '/public') && file_exists(dirname($path) . '/artisan')) {
            return dirname($path);
        }
        return $path;
    }

    /**
     * Check if a path points to a Laravel project (contains artisan and .env / .env.example)
     */
    public static function isLaravelProject(string $path): bool
    {
        $root = self::resolveProjectRoot($path);
        return file_exists($root . '/artisan');
    }

    /**
     * Get path to .env file for project
     */
    public static function getEnvPath(string $path): string
    {
        $root = self::resolveProjectRoot($path);
        return $root . '/.env';
    }

    /**
     * Extract database configuration from .env
     */
    public static function getDbConfig(string $path): array
    {
        $envFile = self::getEnvPath($path);
        $defaults = [
            'has_env'       => file_exists($envFile),
            'connection'    => 'mysql',
            'host'          => '127.0.0.1',
            'port'          => '3306',
            'database'      => '',
            'username'      => '',
            'password'      => '',
            'is_configured' => false,
        ];

        if (!file_exists($envFile)) {
            return $defaults;
        }

        $lines = file($envFile, FILE_IGNORE_NEW_LINES);
        if (!$lines) {
            return $defaults;
        }

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            if (str_contains($line, '=')) {
                [$k, $v] = explode('=', $line, 2);
                $k = trim($k);
                $v = trim($v);
                // Strip quotes if any
                if ((str_starts_with($v, '"') && str_ends_with($v, '"')) ||
                    (str_starts_with($v, "'") && str_ends_with($v, "'"))) {
                    $v = substr($v, 1, -1);
                }

                if ($k === 'DB_CONNECTION') $defaults['connection'] = $v;
                if ($k === 'DB_HOST') $defaults['host'] = $v;
                if ($k === 'DB_PORT') $defaults['port'] = $v;
                if ($k === 'DB_DATABASE') $defaults['database'] = $v;
                if ($k === 'DB_USERNAME') $defaults['username'] = $v;
                if ($k === 'DB_PASSWORD') $defaults['password'] = $v;
            }
        }

        $defaults['is_configured'] = !empty($defaults['database']) && !empty($defaults['username']);
        return $defaults;
    }

    /**
     * Safely update or insert key-value pairs in .env without altering other lines or comments
     */
    public static function updateEnv(string $path, array $data): bool
    {
        $envFile = self::getEnvPath($path);
        $root = self::resolveProjectRoot($path);

        // If .env doesn't exist but .env.example exists, copy it
        if (!file_exists($envFile)) {
            if (file_exists($root . '/.env.example')) {
                copy($root . '/.env.example', $envFile);
            } else {
                touch($envFile);
            }
        }

        $content = file_get_contents($envFile) ?: '';
        $lines = explode("\n", $content);
        $updatedKeys = [];
        $newLines = [];

        foreach ($lines as $line) {
            $trimmed = trim($line);
            $matched = false;

            // Check if line sets a key (or is a commented out DB key like # DB_HOST=127.0.0.1)
            foreach ($data as $key => $val) {
                if (in_array($key, $updatedKeys, true)) {
                    continue;
                }

                // Match exact active key e.g. DB_DATABASE=...
                $activePattern = '/^' . preg_quote($key, '/') . '\s*=/';
                // Match commented key e.g. # DB_DATABASE=... or #DB_DATABASE=...
                $commentedPattern = '/^#\s*' . preg_quote($key, '/') . '\s*=/';

                if (preg_match($activePattern, $trimmed) || preg_match($commentedPattern, $trimmed)) {
                    $formattedVal = self::formatEnvValue((string)$val);
                    $newLines[] = "{$key}={$formattedVal}";
                    $updatedKeys[] = $key;
                    $matched = true;
                    break;
                }
            }

            if (!$matched) {
                $newLines[] = $line;
            }
        }

        // Any keys that weren't present or replaced, append them
        foreach ($data as $key => $val) {
            if (!in_array($key, $updatedKeys, true)) {
                $formattedVal = self::formatEnvValue((string)$val);
                $newLines[] = "{$key}={$formattedVal}";
            }
        }

        $result = file_put_contents($envFile, implode("\n", $newLines));
        if ($result !== false) {
            @chmod($envFile, 0664);
            // Keep ownership consistent
            $owner = fileowner($root);
            $group = filegroup($root);
            if ($owner !== false && $group !== false) {
                @chown($envFile, $owner);
                @chgrp($envFile, $group);
            }
            return true;
        }

        return false;
    }

    /**
     * Escape and quote environment variable value if it contains special characters
     */
    private static function formatEnvValue(string $val): string
    {
        if ($val === '') {
            return '';
        }

        if (preg_match('/[\s#"\'\\\\$]/', $val)) {
            // Escape double quotes and backslashes
            $escaped = str_replace(['\\', '"', '$'], ['\\\\', '\"', '\$'], $val);
            return "\"{$escaped}\"";
        }

        return $val;
    }
}
