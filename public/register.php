<?php
/**
 * Couriva - Account Registration
 * Public signup creates an active Couriva mailbox.
 */

define('WEBMAIL_INIT', true);

require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/database.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/app/Models/User.php';

if (is_authenticated()) {
    header('Location: ' . app_base_url('public/inbox.php'));
    exit;
}

$userModel = new \App\Models\User($pdo);
$error = null;
$form = [
    'first_name' => '',
    'last_name' => '',
    'username' => '',
];

/**
 * Database-backed throttle: limit registration attempts per client IP.
 */
function registration_is_throttled(PDO $pdo): bool
{
    $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
    try {
        $stmt = $pdo->prepare("
            SELECT COUNT(*) FROM system_logs
            WHERE ip_address = ? AND action = 'REGISTRATION_ATTEMPT'
              AND created_at >= DATE_SUB(NOW(), INTERVAL 15 MINUTE)
        ");
        $stmt->execute([$ip]);
        return (int)$stmt->fetchColumn() >= 10;
    } catch (Throwable $e) {
        return false;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token()) {
        $error = 'Session security token expired. Please try again.';
    } elseif (registration_is_throttled($pdo)) {
        $error = 'Too many registration attempts. Please wait 15 minutes and try again.';
    } else {
        $form['first_name'] = trim($_POST['first_name'] ?? '');
        $form['last_name'] = trim($_POST['last_name'] ?? '');
        $form['username'] = strtolower(trim($_POST['username'] ?? ''));
        $password = $_POST['password'] ?? '';
        $confirm = $_POST['confirm_password'] ?? '';

        $username = preg_replace('/@.*$/', '', $form['username']) ?? '';
        $form['username'] = $username;
        $email = couriva_address($username);

        $passwordPolicy = is_valid_password_complexity($password);

        if (mb_strlen($form['first_name']) < 1 || mb_strlen($form['first_name']) > 100
            || mb_strlen($form['last_name']) < 1 || mb_strlen($form['last_name']) > 100) {
            $error = 'Enter your first and last name.';
        } elseif (!preg_match('/^[a-z0-9][a-z0-9._-]{1,30}[a-z0-9]$/', $username)) {
            $error = 'Choose a username of 3–32 characters using letters, numbers, dots, hyphens, or underscores.';
        } elseif (!$passwordPolicy) {
            $error = 'Password must be at least 10 characters and include uppercase, lowercase, a number, and a symbol.';
        } elseif ($password !== $confirm) {
            $error = 'Password and confirmation do not match.';
        } elseif ($userModel->findByEmail($email)) {
            $error = 'An account or request already exists for ' . $email . '.';
        } else {
            try {
                $newId = $userModel->create([
                    'email' => $email,
                    'password' => $password,
                    'first_name' => $form['first_name'],
                    'last_name' => $form['last_name'],
                    'role' => 'user',
                    'status' => 'active',
                    'signature' => "-- \r\n{$form['first_name']} {$form['last_name']}\r\nCouriva Internal Mail\r\n{$email}",
                    'timezone' => 'UTC',
                    'theme' => 'light',
                    'must_change_password' => 0,
                ]);

                $stmt = $pdo->prepare("UPDATE users SET requested_at = NOW() WHERE id = ?");
                $stmt->execute([$newId]);

                log_activity($newId, 'REGISTRATION_SUCCESS', 'SUCCESS', 'Active mailbox created: ' . $email);
                set_flash('success', 'Your Couriva mailbox (' . $email . ') is ready! Sign in below.');
                header('Location: ' . app_base_url('public/login.php?created=' . urlencode($email)));
                exit;
            } catch (Throwable $e) {
                error_log('Registration failed: ' . $e->getMessage());
                $error = 'Your request could not be submitted. Please try again later.';
            }
        }

        if ($error !== null) {
            @log_activity(null, 'REGISTRATION_ATTEMPT', 'FAILED', 'Registration form validation failed.');
        }
    }
}

$pageTitle = 'Create your Couriva account';
$bodyClass = 'auth-page';
require_once dirname(__DIR__) . '/includes/header.php';
?>

<div class="auth-stage">
    <div class="auth-card auth-card-wide">
        <div class="auth-card-body">
            <nav class="auth-nav-pills" aria-label="Account actions">
                <a href="<?= app_base_url('public/login.php') ?>" class="auth-nav-pill">Sign in</a>
                <a href="<?= app_base_url('public/register.php') ?>" class="auth-nav-pill active" aria-current="page">Create account</a>
            </nav>

            <div class="auth-heading">
                <div class="auth-wordmark">
                    <img src="<?= app_base_url('assets/brand/couriva-logo-black.svg') ?>" class="couriva-logo-symbol couriva-logo-light" alt="Couriva" width="24" height="24">
                    <img src="<?= app_base_url('assets/brand/couriva-logo-white.svg') ?>" class="couriva-logo-symbol couriva-logo-dark" alt="Couriva" width="24" height="24">
                    <span><?= e(app_name()) ?></span>
                </div>
                <h1>Create your Couriva account</h1>
                <p>Choose a username for your @<?= e(app_domain()) ?> mailbox, then create your password.</p>
            </div>

            <?php if (!empty($error)): ?>
                <div class="alert alert-danger" role="alert">
                    <?= e($error) ?>
                </div>
            <?php endif; ?>

            <form method="POST" action="<?= app_base_url('public/register.php') ?>">
                <?= csrf_field() ?>

                <div class="row g-3 mb-3">
                    <div class="col-sm-6">
                        <label for="registerFirstName" class="form-label">First name</label>
                        <input type="text" class="form-control" id="registerFirstName" name="first_name" maxlength="100" autocomplete="given-name" required autofocus value="<?= e($form['first_name']) ?>">
                    </div>
                    <div class="col-sm-6">
                        <label for="registerLastName" class="form-label">Last name</label>
                        <input type="text" class="form-control" id="registerLastName" name="last_name" maxlength="100" autocomplete="family-name" required value="<?= e($form['last_name']) ?>">
                    </div>
                </div>

                <div class="mb-3">
                    <label for="registerUsername" class="form-label">Username</label>
                    <div class="input-group">
                        <input type="text" class="form-control" id="registerUsername" name="username" maxlength="32" autocomplete="username" required pattern="^[a-z0-9][a-z0-9._-]{1,30}[a-z0-9]$" placeholder="firstname.lastname" value="<?= e($form['username']) ?>">
                        <span class="input-group-text">@<?= e(app_domain()) ?></span>
                    </div>
                    <div class="form-text">Use 3–32 letters, numbers, dots, hyphens, or underscores.</div>
                </div>

                <div class="mb-3">
                    <label for="registerPassword" class="form-label">Password</label>
                    <div class="input-group">
                        <input type="password" class="form-control" id="registerPassword" name="password" required minlength="10" autocomplete="new-password" aria-describedby="registerPasswordRules">
                        <button type="button" class="password-toggle" aria-label="Show password" aria-controls="registerPassword" aria-pressed="false"><i class="bi bi-eye" aria-hidden="true"></i></button>
                    </div>
                    <ul id="registerPasswordRules" class="auth-password-rules" aria-label="Password requirements">
                        <li class="auth-password-rule" data-rule="length"><span>At least 10 characters</span><span class="auth-password-rule-status">Not met</span></li>
                        <li class="auth-password-rule" data-rule="uppercase"><span>One uppercase letter</span><span class="auth-password-rule-status">Not met</span></li>
                        <li class="auth-password-rule" data-rule="lowercase"><span>One lowercase letter</span><span class="auth-password-rule-status">Not met</span></li>
                        <li class="auth-password-rule" data-rule="number"><span>One number</span><span class="auth-password-rule-status">Not met</span></li>
                        <li class="auth-password-rule" data-rule="symbol"><span>One symbol</span><span class="auth-password-rule-status">Not met</span></li>
                    </ul>
                </div>

                <div class="mb-3">
                    <label for="registerConfirmPassword" class="form-label">Confirm password</label>
                    <div class="input-group">
                        <input type="password" class="form-control" id="registerConfirmPassword" name="confirm_password" required minlength="10" autocomplete="new-password" aria-describedby="registerPasswordMatch">
                        <button type="button" class="password-toggle" aria-label="Show confirmation password" aria-controls="registerConfirmPassword" aria-pressed="false"><i class="bi bi-eye" aria-hidden="true"></i></button>
                    </div>
                    <div id="registerPasswordMatch" class="auth-password-match" role="status" aria-live="polite" hidden></div>
                </div>

                <button type="submit" class="btn btn-primary w-100 py-2 fw-semibold auth-submit">
                    <span class="auth-submit-label">Create account</span>
                </button>
            </form>

            <div class="auth-note mt-3">
                <span>Already have an account? <a href="<?= app_base_url('public/login.php') ?>">Sign in</a></span>
            </div>
        </div>
    </div>
</div>

<script src="<?= app_base_url('assets/js/auth-registration.js') ?>"></script>
<?php require_once dirname(__DIR__) . '/includes/footer.php'; ?>
