<?php

namespace App\Core;

class Router
{
    private static array $routes = [];

    public static function get(string $path, array|callable $handler): void
    {
        self::addRoute('GET', $path, $handler);
    }

    public static function post(string $path, array|callable $handler): void
    {
        self::addRoute('POST', $path, $handler);
    }

    public static function any(string $path, array|callable $handler): void
    {
        self::addRoute('GET', $path, $handler);
        self::addRoute('POST', $path, $handler);
    }

    private static function addRoute(string $method, string $path, array|callable $handler): void
    {
        $normalized = '/' . trim($path, '/');
        if ($normalized !== '/') {
            $normalized = rtrim($normalized, '/');
        }
        self::$routes[$method][$normalized] = $handler;
    }

    public static function dispatch(): void
    {
        Auth::startSession();

        $uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
        $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
        if ($method === 'HEAD') {
            $method = 'GET';
        }
        $normalized = '/' . trim($uri, '/');
        if ($normalized !== '/') {
            $normalized = rtrim($normalized, '/');
        }

        // 1. Static asset bypass (for built-in php server or direct access)
        if (str_starts_with($normalized, '/assets/') || $normalized === '/favicon.ico' || $normalized === '/robots.txt') {
            $root = defined('PANEL_ROOT') ? PANEL_ROOT : dirname(__DIR__, 2);
            $filePath = $root . '/public' . $normalized;
            if (file_exists($filePath) && is_file($filePath)) {
                $ext = pathinfo($filePath, PATHINFO_EXTENSION);
                $mimes = [
                    'css'  => 'text/css; charset=utf-8',
                    'js'   => 'application/javascript; charset=utf-8',
                    'svg'  => 'image/svg+xml',
                    'png'  => 'image/png',
                    'jpg'  => 'image/jpeg',
                    'jpeg' => 'image/jpeg',
                    'webp' => 'image/webp',
                    'ico'  => 'image/x-icon',
                    'woff' => 'font/woff',
                    'woff2'=> 'font/woff2',
                    'ttf'  => 'font/ttf',
                    'txt'  => 'text/plain; charset=utf-8',
                ];
                header('Content-Type: ' . ($mimes[$ext] ?? 'application/octet-stream'));
                if (!empty($_GET['v'])) {
                    header('Cache-Control: public, max-age=31536000, immutable');
                } else {
                    header('Cache-Control: no-cache, no-store, must-revalidate');
                    header('Pragma: no-cache');
                    header('Expires: 0');
                }
                readfile($filePath);
                exit;
            }
        }

        // 2. Fetch secret entrance path
        $root = defined('PANEL_ROOT') ? PANEL_ROOT : dirname(__DIR__, 2);
        $entranceFile = $root . '/data/entrance.txt';
        $entrancePath = '';
        if (file_exists($entranceFile)) {
            $entrancePath = trim((string)file_get_contents($entranceFile));
        }
        if (empty($entrancePath)) {
            $entrancePath = Database::getSetting('entrance_path', 'almusa');
        }
        $entrancePathClean = trim($entrancePath, '/');

        $isEntranceRequest = ($entrancePathClean !== '' && trim($normalized, '/') === $entrancePathClean);

        // 3. Entrance Hit: If visitor accesses the secret entrance token
        if ($isEntranceRequest) {
            Auth::setEntranceVerified(true);
            if (Auth::check()) {
                Response::redirect('/dashboard');
            } else {
                (new \App\Controllers\AuthController())->showLogin();
                exit;
            }
        }

        // 4. Security Guard for Unauthenticated Visitors
        if (!Auth::check()) {
            // Must have passed entrance path first
            if (!Auth::isEntranceVerified()) {
                Response::error(404);
            }

            // Only /login and related auth actions allowed if entrance verified but not logged in
            if ($normalized !== '/login') {
                Response::redirect('/login');
            }
        } else {
            // If logged in and hits /login or /, redirect to dashboard
            if ($normalized === '/login' || $normalized === '/') {
                Response::redirect('/dashboard');
            }
        }

        // 5. CSRF Protection for state-changing POST requests
        if ($method === 'POST') {
            $token = $_POST['_csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? null;
            if (!Auth::verifyToken($token)) {
                if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest'
                    || str_starts_with($normalized, '/api/')) {
                    Response::json(['success' => false, 'error' => 'Invalid or expired CSRF token.'], 403);
                }
                Response::error(403, 'CSRF token mismatch. Please reload the page.');
            }
        }

        // 6. Match and execute route
        $handler = self::$routes[$method][$normalized] ?? null;

        if (!$handler) {
            if (str_starts_with($normalized, '/api/')) {
                Response::json(['success' => false, 'error' => 'API endpoint not found.'], 404);
            }
            Response::error(404, 'Page not found.');
        }

        if (is_callable($handler)) {
            call_user_func($handler);
            exit;
        }

        if (is_array($handler) && count($handler) === 2) {
            [$class, $action] = $handler;
            if (class_exists($class)) {
                $controller = new $class();
                if (method_exists($controller, $action)) {
                    $controller->$action();
                    exit;
                }
            }
        }

        Response::error(500, 'Invalid route handler.');
    }
}
