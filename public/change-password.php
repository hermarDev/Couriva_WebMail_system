<?php
/**
 * Couriva - Forced First-Login / Reset Password Change
 */

define('WEBMAIL_INIT', true);

require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/database.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/app/Models/User.php';

// This page is the only authenticated destination allowed while the
// must_change_password flag is set.
require_auth(true);

$userId = (int)$_SESSION['user_id'];
$userModel = new \App\Models\User($pdo);
$user = $userModel->findById($userId);

if (!$user) {
    perform_logout();
    set_flash('danger', 'Your account could not be loaded. Please contact an administrator.');
    header('Location: ' . app_base_url('public/login.php'));
    exit;
}

if (empty($user['must_change_password'])) {
    header('Location: ' . app_base_url('public/inbox.php'));
    exit;
}

$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token()) {
        $error = 'Security token expired. Please try again.';
    } else {
        $currentPass = $_POST['current_password'] ?? '';
        $newPass = $_POST['new_password'] ?? '';
        $confirmPass = $_POST['confirm_password'] ?? '';

        if (!password_verify($currentPass, $user['password'])) {
            $error = 'Current password is incorrect.';
        } elseif (!is_valid_password_complexity($newPass)) {
            $error = 'New password must be at least 10 characters long and include uppercase, lowercase, a number, and a symbol.';
        } elseif ($newPass === $currentPass) {
            $error = 'New password must be different from the current password.';
        } elseif ($newPass !== $confirmPass) {
            $error = 'New password and confirmation do not match.';
        } else {
            $userModel->updatePassword($userId, $newPass, false);
            $freshUser = $userModel->findById($userId);

            // Keep this session valid; the epoch bump revokes all others.
            $_SESSION['session_epoch'] = (int)($freshUser['session_epoch'] ?? ($_SESSION['session_epoch'] + 1));
            $_SESSION['must_change_password'] = 0;
            $_SESSION['last_activity'] = time();

            log_activity($userId, 'PASSWORD_CHANGED', 'SUCCESS', 'Forced first-login password change completed.');
            set_flash('success', 'Your password has been changed. Welcome to your mailbox.');
            header('Location: ' . app_base_url('public/inbox.php'));
            exit;
        }
    }
}

$pageTitle = 'Change Password';
$bodyClass = 'auth-page';
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
                        <span>Couriva</span>
                    </div>
                    <h1>Change your password</h1>
                    <p>Your account requires a new password before you can open the mailbox.</p>
                </div>
            </div>

                <?= render_inline_alert($error) ?>

                <form method="POST" action="<?= app_base_url('public/change-password.php') ?>">
                    <?= csrf_field() ?>

                    <div class="mb-3">
                        <label for="current_password" class="form-label">Current password</label>
                        <div class="input-group"><input type="password" class="form-control" id="current_password" name="current_password" required autofocus autocomplete="current-password" placeholder="Enter current password"><button type="button" class="password-toggle" aria-label="Show current password" aria-controls="current_password" aria-pressed="false"><i class="bi bi-eye" aria-hidden="true"></i></button></div>
                    </div>

                    <div class="mb-3">
                        <label for="new_password" class="form-label">New password</label>
                        <div class="input-group"><input type="password" class="form-control" id="new_password" name="new_password" required minlength="10" autocomplete="new-password" placeholder="At least 10 characters"><button type="button" class="password-toggle" aria-label="Show new password" aria-controls="new_password" aria-pressed="false"><i class="bi bi-eye" aria-hidden="true"></i></button></div>
                    </div>

                    <div class="mb-4">
                        <label for="confirm_password" class="form-label">Confirm new password</label>
                        <div class="input-group"><input type="password" class="form-control" id="confirm_password" name="confirm_password" required minlength="10" autocomplete="new-password" placeholder="Re-enter new password"><button type="button" class="password-toggle" aria-label="Show confirmation password" aria-controls="confirm_password" aria-pressed="false"><i class="bi bi-eye" aria-hidden="true"></i></button></div>
                    </div>

                    <button type="submit" class="btn btn-primary w-100 py-2 fw-semibold auth-submit">
                        <i class="bi bi-key-fill me-1"></i> Update Password
                    </button>
                </form>

                <div class="text-center mt-3 pt-2 border-top auth-signout">
                    <a href="<?= app_base_url('public/logout.php') ?>" class="text-muted small text-decoration-none">Sign out</a>
            </div>
        </div>
    </div>
</div>

<?php require_once dirname(__DIR__) . '/includes/footer.php'; ?>
