/**
 * Almusa Panel - Theme bootstrap.
 * Dimuat sinkron di <head> agar tema terpasang sebelum halaman dirender (tanpa flash).
 */
(function () {
    'use strict';

    var KEY = 'almusa-theme';

    function preferred() {
        try {
            var saved = localStorage.getItem(KEY);
            if (saved === 'light' || saved === 'dark') return saved;
        } catch (e) { /* storage tidak tersedia */ }
        return window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
    }

    function apply(theme) {
        document.documentElement.setAttribute('data-theme', theme);
    }

    apply(preferred());

    window.toggleTheme = function () {
        var next = document.documentElement.getAttribute('data-theme') === 'dark' ? 'light' : 'dark';
        apply(next);
        try { localStorage.setItem(KEY, next); } catch (e) { /* abaikan */ }
    };
})();
