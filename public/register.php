<?php
/**
 * Couriva - Account Registration (Retired)
 * Mailbox accounts are provisioned in Windows Active Directory.
 */

define('WEBMAIL_INIT', true);

require_once dirname(__DIR__) . '/includes/functions.php';

http_response_code(410);
if (PHP_SAPI !== 'cli') {
    send_security_headers();
}
?>
<!DOCTYPE html>
<html lang="en" data-bs-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="color-scheme" content="light dark">
    <script>
        (function () {
            var pref = 'system';
            try {
                var stored = localStorage.getItem('webmail_theme');
                if (stored === 'light' || stored === 'dark' || stored === 'system') pref = stored;
            } catch (e) {}
            var resolved = (pref === 'system')
                ? (window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light')
                : (pref === 'dark' ? 'dark' : 'light');
            var meta = document.querySelector('meta[name="color-scheme"]');
            if (meta) meta.setAttribute('content', pref === 'system' ? 'light dark' : resolved);
            document.documentElement.setAttribute('data-bs-theme', resolved);
        })();
    </script>
    <title>Self-Registration Unavailable — <?= e(app_name()) ?></title>
    <link rel="icon" type="image/svg+xml" href="<?= app_base_url('assets/brand/couriva-favicon.svg') ?>">
    <link href="<?= app_base_url('assets/css/bootstrap.min.css') ?>" rel="stylesheet">
    <link href="<?= app_base_url('assets/css/bootstrap-icons.css') ?>" rel="stylesheet">
    <link href="<?= app_base_url('assets/css/icon-fallback.css') ?>" rel="stylesheet">
    <link href="<?= app_base_url('assets/css/style.css') ?>" rel="stylesheet">
</head>
<body class="auth-page">
<div class="container py-4">
    <div class="auth-stage">
        <div class="auth-card">
            <div class="auth-card-body">
                <div class="auth-heading">
                    <div class="auth-wordmark">
                        <div class="brand-monogram-container">
                            <img src="<?= app_base_url('assets/brand/couriva-logo-black.svg') ?>" class="couriva-logo-symbol couriva-logo-light" alt="Couriva" width="32" height="32">
                            <img src="<?= app_base_url('assets/brand/couriva-logo-white.svg') ?>" class="couriva-logo-symbol couriva-logo-dark" alt="Couriva" width="32" height="32">
                        </div>
                        <span><?= e(app_name()) ?></span>
                    </div>
                    <h1>Self-registration unavailable</h1>
                    <p>Public account registration has been retired.</p>
                </div>

                <div class="alert alert-info mb-4" role="status">
                    Couriva accounts are provisioned in Windows Active Directory. Contact your administrator to request mailbox access or reset your domain password.
                </div>

                <a href="<?= app_base_url('public/login.php') ?>" class="btn btn-primary w-100 fw-semibold auth-submit text-decoration-none">
                    Return to Login
                </a>

                <div class="auth-note mt-3">
                    <span>&copy; <?= date('Y') ?> <?= e(app_name()) ?> &middot; Internal mail for <?= e('@' . app_domain()) ?></span>
                </div>
            </div>
        </div>
    </div>
</div>
</body>
</html>
