<?php

/**
 * View helpers (dipakai oleh file di views/).
 */

if (!function_exists('e')) {
    /**
     * Escape nilai untuk output HTML (juga aman dipakai di dalam atribut).
     */
    function e(mixed $value): string
    {
        return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

if (!function_exists('ui_icon')) {
    /**
     * Render ikon dari sprite SVG (views/layouts/_icons.php).
     */
    function ui_icon(string $name, string $class = ''): string
    {
        $cls = trim('icon ' . $class);
        return '<svg class="' . e($cls) . '" aria-hidden="true"><use href="#i-' . e($name) . '"/></svg>';
    }
}

if (!function_exists('js_arg')) {
    /**
     * Encode nilai PHP menjadi argumen JavaScript yang aman di dalam atribut onclick="...".
     */
    function js_arg(mixed $value): string
    {
        return e(json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }
}

if (!function_exists('asset_url')) {
    /**
     * Menghasilkan URL aset statis dengan cache-busting timestamp otomatis.
     * Mencegah caching usang di Cloudflare CDN dan browser.
     */
    function asset_url(string $path): string
    {
        $root = defined('PANEL_ROOT') ? PANEL_ROOT : dirname(__DIR__);
        $file = $root . '/public' . $path;
        $ver = file_exists($file) ? (string)filemtime($file) : '2.1.0';
        return $path . '?v=' . $ver;
    }
}

