<?php

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Database;
use App\Core\Response;

class AuthController
{
    public function showLogin(): void
    {
        if (Auth::check()) {
            Response::redirect('/dashboard');
        }

        $panelTitle = Database::getSetting('panel_title', 'Almusa Server Panel');
        Response::view('auth/login', [
            'title'      => $panelTitle,
            'csrf_token' => Auth::token(),
            'error'      => $_SESSION['auth_error'] ?? null,
        ], null);
        unset($_SESSION['auth_error']);
    }

    public function login(): void
    {
        $username = trim($_POST['username'] ?? '');
        $password = (string)($_POST['password'] ?? '');

        if (empty($username) || empty($password)) {
            $_SESSION['auth_error'] = 'Silakan masukkan username dan password.';
            Response::redirect('/login');
        }

        if (Auth::attempt($username, $password)) {
            unset($_SESSION['auth_error']);
            Response::redirect('/dashboard');
        } else {
            $_SESSION['auth_error'] = 'Kombinasi username atau password salah.';
            Response::redirect('/login');
        }
    }

    public function logout(): void
    {
        Auth::logout();
        Response::redirect('/login');
    }
}
