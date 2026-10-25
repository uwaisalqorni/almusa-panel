<?php

namespace App\Core;

class Response
{
    public static function json(mixed $data, int $status = 200, array $headers = []): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        foreach ($headers as $key => $val) {
            header("{$key}: {$val}");
        }
        echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }

    public static function redirect(string $url): void
    {
        header("Location: {$url}");
        exit;
    }

    public static function error(int $code = 404, string $message = 'Not Found'): void
    {
        http_response_code($code);
        if ($code === 404) {
            header('Content-Type: text/html; charset=utf-8');
            echo '<!DOCTYPE html><html><head><title>404 Not Found</title></head><body><h1>Not Found</h1><p>The requested URL was not found on this server.</p><hr><address>Apache/2.4.52 (Ubuntu) Server at 127.0.0.1 Port 80</address></body></html>';
            exit;
        }

        echo "<h1>Error {$code}</h1><p>" . htmlspecialchars($message) . "</p>";
        exit;
    }

    public static function view(string $viewPath, array $data = [], ?string $layout = 'app'): void
    {
        $root = defined('PANEL_ROOT') ? PANEL_ROOT : dirname(__DIR__, 2);
        $file = $root . '/views/' . ltrim($viewPath, '/') . '.php';

        if (!file_exists($file)) {
            self::error(500, "View template [{$viewPath}] not found.");
        }

        // Extract variables for view
        extract($data, EXTR_SKIP);

        ob_start();
        require $file;
        $content = ob_get_clean();

        if ($layout === null) {
            echo $content;
            exit;
        }

        $layoutFile = $root . '/views/layouts/' . $layout . '.php';
        if (!file_exists($layoutFile)) {
            // Fallback: output content directly
            echo $content;
            exit;
        }

        require $layoutFile;
        exit;
    }
}
