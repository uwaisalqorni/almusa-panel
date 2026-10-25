<!DOCTYPE html>
<html lang="id" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title>Masuk · <?= e($title ?? 'Almusa Server Panel') ?></title>
    <link rel="icon" type="image/svg+xml" href="/assets/favicon.svg">
    <link rel="alternate icon" href="/favicon.ico">
    <script src="<?= asset_url('/assets/js/theme.js') ?>"></script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= asset_url('/assets/css/panel.css') ?>">
</head>
<body>
<?php require __DIR__ . '/../layouts/_icons.php'; ?>

<main class="auth-page">
    <button type="button" class="icon-btn auth-theme" onclick="toggleTheme()" aria-label="Ganti tema" title="Ganti tema terang / gelap">
        <span data-show="dark"><?= ui_icon('sun', 'icon-lg') ?></span>
        <span data-show="light"><?= ui_icon('moon', 'icon-lg') ?></span>
    </button>

    <div class="auth-card">
        <div class="auth-brand">
            <div class="brand-mark"><?= ui_icon('layers') ?></div>
            <div>
                <h1 class="auth-title"><?= e($title ?? 'Almusa Server Panel') ?></h1>
                <p class="auth-sub">Masuk untuk mengelola server Anda</p>
            </div>
        </div>

        <?php if (!empty($error)): ?>
            <div class="alert alert-danger" role="alert">
                <?= ui_icon('alert-circle') ?>
                <span><?= e($error) ?></span>
            </div>
        <?php endif; ?>

        <form method="POST" action="/login" autocomplete="off">
            <input type="hidden" name="_csrf_token" value="<?= e($csrf_token ?? '') ?>">

            <div class="field">
                <label class="label" for="username">Username</label>
                <input type="text" id="username" name="username" class="input" placeholder="admin" autocomplete="username" required autofocus>
            </div>

            <div class="field">
                <label class="label" for="password">Password</label>
                <div class="input-group">
                    <input type="password" id="password" name="password" class="input has-reveal" placeholder="Masukkan password" autocomplete="current-password" required>
                    <button type="button" class="icon-btn reveal" data-toggle-password="password" aria-label="Tampilkan password">
                        <?= ui_icon('eye') ?>
                    </button>
                </div>
            </div>

            <button type="submit" class="btn btn-primary btn-lg btn-block" style="margin-top:8px" <?= !empty($is_locked) ? 'disabled style="margin-top:8px;opacity:0.6;cursor:not-allowed"' : '' ?>>
                <?= !empty($is_locked) ? 'Akses Terkunci' : 'Masuk' ?>
            </button>
        </form>

        <div class="auth-foot">
            <?= ui_icon('lock') ?>
            <span>Akses terlindungi · sesi dienkripsi</span>
        </div>
    </div>
</main>

<script src="<?= asset_url('/assets/js/panel.js') ?>"></script>
</body>
</html>
