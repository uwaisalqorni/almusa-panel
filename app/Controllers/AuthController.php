<?php

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Database;
use App\Core\RateLimiter;
use App\Core\Response;

class AuthController
{
    public function showLogin(): void
    {
        if (Auth::check()) {
            Response::redirect('/dashboard');
        }

        // Cek apakah IP klien sedang terkunci karena brute-force
        $limit = RateLimiter::check();
        if ($limit['locked']) {
            $minutes = (int)ceil($limit['remaining_seconds'] / 60);
            $_SESSION['auth_error'] = "Terlalu banyak percobaan login gagal. Akses dari IP Anda dikunci sementara selama {$minutes} menit.";
        }

        $panelTitle = Database::getSetting('panel_title', 'Almusa Server Panel');
        Response::view('auth/login', [
            'title'      => $panelTitle,
            'csrf_token' => Auth::token(),
            'error'      => $_SESSION['auth_error'] ?? null,
            'is_locked'  => $limit['locked'],
        ], null);
        unset($_SESSION['auth_error']);
    }

    public function login(): void
    {
        // 1. Validasi status lockout sebelum memproses autentikasi
        $limit = RateLimiter::check();
        if ($limit['locked']) {
            $minutes = (int)ceil($limit['remaining_seconds'] / 60);
            $_SESSION['auth_error'] = "Akses login dari IP Anda sedang dikunci. Silakan coba lagi dalam {$minutes} menit.";
            Response::redirect('/login');
        }

        $username = trim($_POST['username'] ?? '');
        $password = (string)($_POST['password'] ?? '');

        if (empty($username) || empty($password)) {
            $_SESSION['auth_error'] = 'Silakan masukkan username dan password.';
            Response::redirect('/login');
        }

        // 2. Coba autentikasi
        if (Auth::attempt($username, $password)) {
            // Berhasil login: reset catatan percobaan gagal untuk IP ini
            RateLimiter::recordSuccess($username);
            unset($_SESSION['auth_error']);
            Response::redirect('/dashboard');
        } else {
            // Gagal login: catat kegagalan dan hitung batas
            $result = RateLimiter::recordFailedAttempt($username);
            if ($result['locked']) {
                $_SESSION['auth_error'] = "Batas percobaan terlampaui. Akses dari IP Anda dikunci selama {$result['locked_minutes']} menit demi keamanan.";
            } else {
                $_SESSION['auth_error'] = "Username atau password salah. Sisa percobaan: {$result['remaining_attempts']} kali.";
            }
            Response::redirect('/login');
        }
    }

    public function logout(): void
    {
        Auth::logout();
        Response::redirect('/login');
    }
}
