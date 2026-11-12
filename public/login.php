<?php
/**
 * Couriva - Login Page
 */

define('WEBMAIL_INIT', true);

require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/database.php';
require_once dirname(__DIR__) . '/includes/auth.php';

// Redirect if already authenticated
if (is_authenticated()) {
    header('Location: ' . app_base_url('public/inbox.php'));
    exit;
}

$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token()) {
        $error = 'Session security token expired. Please try again.';
    } else {
        $email = $_POST['email'] ?? '';
        $password = $_POST['password'] ?? '';

        $result = attempt_login($email, $password);
        if ($result['success']) {
            if (!empty($_SESSION['must_change_password'])) {
                set_flash('warning', 'You must change your password before continuing.');
                header('Location: ' . app_base_url('public/change-password.php'));
                exit;
            }
            set_flash('success', 'Welcome back, ' . e($_SESSION['user_full_name']) . '!');
            header('Location: ' . app_base_url('public/inbox.php'));
            exit;
        } else {
            $error = $result['message'];
        }
    }
}

$pageTitle = 'Login';
$bodyClass = 'auth-page';
require_once dirname(__DIR__) . '/includes/header.php';
?>

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
                <h1>Login to your Couriva workspace</h1>
            </div>

            <?= render_inline_alert($error) ?>

            <form method="POST" action="<?= app_base_url('public/login.php') ?>">
                <?= csrf_field() ?>

                <div class="mb-3">
                    <label for="email" class="form-label">Email address</label>
                    <input type="email" class="form-control" id="email" name="email" required autofocus value="<?= e($_POST['email'] ?? '') ?>" placeholder="name@couriva.local" autocomplete="email">
                </div>

                <div class="mb-3">
                    <label for="password" class="form-label">Password</label>
                    <div class="input-group">
                        <input type="password" class="form-control" id="password" name="password" required autocomplete="current-password">
                        <button type="button" class="password-toggle" aria-label="Show password" aria-controls="password" aria-pressed="false"><i class="bi bi-eye" aria-hidden="true"></i></button>
                    </div>
                    <p class="auth-recovery-note">Password resets are managed by a Couriva administrator.</p>
                </div>

                <button type="submit" class="btn btn-primary w-100 fw-semibold auth-submit">Login</button>

                <div class="auth-note">
                    <span>Don't have an account? <a href="<?= app_base_url('public/register.php') ?>">Create account</a></span>
                </div>
            </form>
        </div>
    </div>
</div>

<script src="<?= app_base_url('assets/js/auth-signin.js') ?>"></script>
<?php require_once dirname(__DIR__) . '/includes/footer.php'; ?>
