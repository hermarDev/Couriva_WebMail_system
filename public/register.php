<?php
/**
 * Couriva - Account Registration Request
 * Public signup creates a PENDING account. An administrator must approve it
 * before the account can sign in.
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
$registered = false;
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

        $passwordPolicy = strlen($password) >= 10
            && preg_match('/[a-z]/', $password)
            && preg_match('/[A-Z]/', $password)
            && preg_match('/[0-9]/', $password)
            && preg_match('/[^A-Za-z0-9]/', $password);

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
                    'status' => 'pending',
                    'signature' => "-- \r\n{$form['first_name']} {$form['last_name']}\r\nCouriva Internal Mail\r\n{$email}",
                    'timezone' => 'UTC',
                    'theme' => 'light',
                    'must_change_password' => 0,
                ]);

                $stmt = $pdo->prepare("UPDATE users SET requested_at = NOW() WHERE id = ?");
                $stmt->execute([$newId]);

                log_activity($newId, 'REGISTRATION_SUBMITTED', 'SUCCESS', 'Pending account request: ' . $email);
                log_activity($newId, 'REGISTRATION_ATTEMPT', 'SUCCESS', 'Registration form submitted.');
                notify_admins(
                    'approval',
                    'New account request',
                    trim($form['first_name'] . ' ' . $form['last_name']) . ' requested ' . $email,
                    'admin/registrations.php'
                );

                $registered = true;
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

$pageTitle = 'Request an Account';
require_once dirname(__DIR__) . '/includes/header.php';
?>

<div class="auth-stage">
    <div class="auth-card auth-card-wide">
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
                    <h1>Request an account</h1>
                    <p>Create a <?= e('@' . app_domain()) ?> account. An administrator will review it before you can sign in.</p>
                </div>
            </div>

            <?php if ($registered): ?>
                <div class="alert alert-success d-flex align-items-start gap-2" role="status">
                    <i class="bi bi-hourglass-split fs-5 mt-1"></i>
                    <div>
                        <div class="fw-semibold">Request submitted</div>
                        <div class="small">Your account is pending administrator approval. You will be able to sign in once it is approved.</div>
                    </div>
                </div>
                <a href="<?= app_base_url('public/login.php') ?>" class="btn btn-primary w-100 py-2 fw-semibold auth-submit">
                    <i class="bi bi-box-arrow-in-right me-1"></i> Back to sign in
                </a>
            <?php else: ?>
                <?php if (!empty($error)): ?>
                    <div class="alert alert-danger d-flex align-items-start gap-2" role="alert">
                        <i class="bi bi-exclamation-triangle-fill fs-5 mt-1"></i>
                        <div class="small"><?= e($error) ?></div>
                    </div>
                <?php endif; ?>

                <form method="POST" action="<?= app_base_url('public/register.php') ?>">
                    <?= csrf_field() ?>

                    <div class="row g-2 mb-3">
                        <div class="col-sm-6">
                            <label for="registerFirstName" class="form-label">First name</label>
                            <input type="text" class="form-control" id="registerFirstName" name="first_name" maxlength="100" required autofocus value="<?= e($form['first_name']) ?>">
                        </div>
                        <div class="col-sm-6">
                            <label for="registerLastName" class="form-label">Last name</label>
                            <input type="text" class="form-control" id="registerLastName" name="last_name" maxlength="100" required value="<?= e($form['last_name']) ?>">
                        </div>
                    </div>

                    <div class="mb-3">
                        <label for="registerUsername" class="form-label">Username</label>
                        <div class="input-group">
                            <input type="text" class="form-control" id="registerUsername" name="username" maxlength="32" required autocomplete="username" value="<?= e($form['username']) ?>" placeholder="firstname.lastname">
                            <span class="input-group-text">@<?= e(app_domain()) ?></span>
                        </div>
                        <div class="form-text">Letters, numbers, dots, hyphens, and underscores.</div>
                    </div>

                    <div class="mb-3">
                        <label for="registerPassword" class="form-label">Password</label>
                        <div class="input-group">
                            <input type="password" class="form-control" id="registerPassword" name="password" required minlength="10" autocomplete="new-password">
                            <button type="button" class="password-toggle" aria-label="Show password" aria-controls="registerPassword" aria-pressed="false"><i class="bi bi-eye" aria-hidden="true"></i></button>
                        </div>
                        <div class="form-text">At least 10 characters with uppercase, lowercase, a number, and a symbol.</div>
                    </div>

                    <div class="mb-3">
                        <label for="registerConfirmPassword" class="form-label">Confirm password</label>
                        <div class="input-group">
                            <input type="password" class="form-control" id="registerConfirmPassword" name="confirm_password" required minlength="10" autocomplete="new-password">
                            <button type="button" class="password-toggle" aria-label="Show confirmation password" aria-controls="registerConfirmPassword" aria-pressed="false"><i class="bi bi-eye" aria-hidden="true"></i></button>
                        </div>
                    </div>

                    <button type="submit" class="btn btn-primary w-100 py-2 fw-semibold auth-submit">
                        <i class="bi bi-person-plus me-1"></i> Submit request
                    </button>
                </form>

                <div class="auth-note mt-3">
                    <i class="bi bi-shield-check"></i>
                    <span>Already approved? <a href="<?= app_base_url('public/login.php') ?>">Sign in</a></span>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php require_once dirname(__DIR__) . '/includes/footer.php'; ?>
