<?php

namespace App\Core;

/**
 * SecurityScanner
 *
 * Internal helper to verify whether Nginx vhost templates and PHP-FPM pool
 * configurations comply with PHP2Shell (CVE-2026-109618 & CVE-2026-109619) hardening standards.
 */
class SecurityScanner
{
    /**
     * Verify whether Nginx vhost template contains essential PHP2Shell mitigations
     */
    public static function verifyNginxTemplate(): array
    {
        $root = defined('PANEL_ROOT') ? PANEL_ROOT : dirname(__DIR__, 2);
        $vhostFile = "{$root}/plugins/nginx/conf/vhost_template.conf";
        $vhostSslFile = "{$root}/plugins/nginx/conf/vhost_ssl_template.conf";

        $checks = [
            'stream_wrapper_blocked'     => false,
            'argument_injection_blocked' => false,
            'upload_php_blocked'         => false,
            'try_files_404_active'       => false,
            'fastcgi_split_path_active'  => false,
        ];

        if (file_exists($vhostFile)) {
            $content = file_get_contents($vhostFile);
            $checks['stream_wrapper_blocked']     = str_contains($content, 'php://') && str_contains($content, 'return 403');
            $checks['argument_injection_blocked'] = (str_contains($content, 'auto_prepend_file') || str_contains($content, '-d[')) && str_contains($content, 'return 403');
            $checks['upload_php_blocked']         = str_contains($content, 'uploads|storage') && str_contains($content, 'deny all');
            $checks['try_files_404_active']       = str_contains($content, 'try_files $uri =404;');
            $checks['fastcgi_split_path_active']  = str_contains($content, 'fastcgi_split_path_info');
        }

        $allPassed = !in_array(false, $checks, true);
        return [
            'valid'   => $allPassed,
            'checks'  => $checks,
            'message' => $allPassed ? 'Nginx vhost templates fully hardened against PHP2Shell.' : 'Nginx vhost template missing mitigations.',
        ];
    }

    /**
     * Verify whether PHP-FPM pool template contains essential PHP2Shell containment
     */
    public static function verifyPhpFpmTemplate(): array
    {
        $root = defined('PANEL_ROOT') ? PANEL_ROOT : dirname(__DIR__, 2);
        $fpmFile = "{$root}/plugins/php/conf/fpm_pool.conf";

        $checks = [
            'open_basedir_active'      => false,
            'disable_functions_active' => false,
            'allow_url_include_off'    => false,
            'enable_dl_off'            => false,
            'expose_php_off'           => false,
            'cgi_fix_pathinfo_zero'    => false,
        ];

        if (file_exists($fpmFile)) {
            $content = file_get_contents($fpmFile);
            $checks['open_basedir_active']      = str_contains($content, 'open_basedir') && str_contains($content, '/www/wwwroot/{{DOMAIN}}/');
            $checks['disable_functions_active'] = str_contains($content, 'disable_functions') && str_contains($content, 'shell_exec') && str_contains($content, 'system');
            $checks['allow_url_include_off']    = str_contains($content, 'allow_url_include') && str_contains($content, 'off');
            $checks['enable_dl_off']            = str_contains($content, 'enable_dl') && str_contains($content, 'off');
            $checks['expose_php_off']           = str_contains($content, 'expose_php') && str_contains($content, 'off');
            $checks['cgi_fix_pathinfo_zero']    = str_contains($content, 'cgi.fix_pathinfo') && str_contains($content, '0');
        }

        $allPassed = !in_array(false, $checks, true);
        return [
            'valid'   => $allPassed,
            'checks'  => $checks,
            'message' => $allPassed ? 'PHP-FPM pool template fully hardened against PHP2Shell.' : 'PHP-FPM pool template missing containment rules.',
        ];
    }
}
