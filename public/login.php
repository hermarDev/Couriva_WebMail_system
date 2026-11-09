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

$pageTitle = 'Sign In';
require_once dirname(__DIR__) . '/includes/header.php';
?>

<div class="auth-stage">
    <div class="auth-card">
        <div class="auth-card-body">
            <div class="auth-heading">
                <div>
                    <div class="auth-wordmark d-flex align-items-center gap-2">
                        <div class="brand-monogram-container" style="width: 24px; height: 24px;">
                            <img src="<?= app_base_url('assets/brand/couriva-logo-black.svg') ?>" class="couriva-logo-symbol couriva-logo-light" alt="Couriva" width="24" height="24">
                            <img src="<?= app_base_url('assets/brand/couriva-logo-white.svg') ?>" class="couriva-logo-symbol couriva-logo-dark" alt="Couriva" width="24" height="24">
                        </div>
                        <span><?= e(app_name()) ?></span>
                    </div>
                    <h1>Sign in</h1>
                    <p>Sign in to continue to your Couriva inbox.</p>
                </div>
            </div>

                <?php if (!empty($error)): ?>
                    <div class="alert alert-danger shadow-sm mb-3 d-flex align-items-center" role="alert">
                        <i class="bi bi-exclamation-triangle-fill me-2 fs-5 flex-shrink-0"></i>
                        <div class="small"><?= e($error) ?></div>
                    </div>
                <?php endif; ?>

                <form method="POST" action="<?= app_base_url('public/login.php') ?>">
                    <?= csrf_field() ?>

                    <div class="mb-3">
                        <label for="email" class="form-label">Email address or username</label>
                        <div class="input-group">
                            <span class="input-group-text"><i class="bi bi-at"></i></span>
                            <input type="text" class="form-control" id="email" name="email" required autofocus value="<?= e($_POST['email'] ?? '') ?>" placeholder="name@<?= e(app_domain()) ?>" autocomplete="username">
                        </div>
                    </div>

                    <div class="mb-3">
                        <div class="d-flex justify-content-between align-items-center mb-1">
                            <label for="password" class="form-label mb-0">Password</label>
                        </div>
                        <div class="input-group">
                            <input type="password" class="form-control" id="password" name="password" required autocomplete="current-password" placeholder="Enter your password">
                            <button type="button" class="password-toggle" aria-label="Show password" aria-controls="password" aria-pressed="false"><i class="bi bi-eye" aria-hidden="true"></i></button>
                        </div>
                    </div>

                    <button type="submit" class="btn btn-primary w-100 py-2 fw-semibold mb-3 auth-submit">
                        <i class="bi bi-box-arrow-in-right me-1"></i> Sign In
                    </button>

                    <div class="auth-note">
                        <i class="bi bi-shield-check"></i>
                        <span>Accounts require administrator approval. <a href="<?= app_base_url('public/register.php') ?>">Request an account</a></span>
                    </div>
                </form>
        </div>
    </div>
</div>

<?php require_once dirname(__DIR__) . '/includes/footer.php'; ?>
