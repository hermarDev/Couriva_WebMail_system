<?php
/**
 * Couriva - Administrator Create User
 */

define('WEBMAIL_INIT', true);

require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/database.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/app/Models/User.php';

require_admin();

$userModel = new \App\Models\User($pdo);
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token()) {
        $error = 'Security token invalid.';
    } else {
        $email = trim($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';
        $firstName = trim($_POST['first_name'] ?? '');
        $lastName = trim($_POST['last_name'] ?? '');
        $role = $_POST['role'] ?? 'user';
        $status = $_POST['status'] ?? 'active';
        $timezone = $_POST['timezone'] ?? 'UTC';

        if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'A valid email address is required.';
        } elseif (!is_valid_password_complexity($password)) {
            $error = 'Password must be at least 10 characters and include uppercase, lowercase, number, and symbol.';
        } elseif (empty($firstName) || empty($lastName)) {
            $error = 'First name and last name are required.';
        } elseif (!is_valid_user_role($role) || !is_valid_user_status($status)) {
            $error = 'Invalid role or account status selection.';
        } elseif ($userModel->findByEmail($email)) {
            $error = 'A user account with this email address already exists.';
        } else {
            $newId = $userModel->create([
                'email'      => $email,
                'password'   => $password,
                'first_name' => $firstName,
                'last_name'  => $lastName,
                'role'       => $role,
                'status'     => $status,
                'timezone'   => $timezone,
                'must_change_password' => 1,
            ]);

            log_activity($_SESSION['user_id'], 'USER_CREATED', 'SUCCESS', "Created new user account: {$email} (ID: {$newId})");
            set_flash('success', "User account {$email} has been created.");
            header('Location: ' . app_base_url('admin/users.php'));
            exit;
        }
    }
}

$pageTitle = 'Create New User';
require_once dirname(__DIR__) . '/includes/header.php';
?>

<div class="row justify-content-center">
    <div class="col-lg-8">
        <div class="d-flex justify-content-between align-items-center mb-3">
            <div>
                <h4 class="fw-bold mb-1"><i class="bi bi-person-plus-fill text-primary me-2"></i>Create New User</h4>
                <p class="text-muted small mb-0">Provision a new account on the webmail system</p>
            </div>
            <a href="<?= app_base_url('admin/users.php') ?>" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left me-1"></i> Back to Users</a>
        </div>

        <?= render_inline_alert($error, ['margin' => 'mb-4']) ?>

        <div class="card shadow-sm border-0 mb-4">
            <div class="card-body p-4">
                <form method="POST" action="<?= app_base_url('admin/create-user.php') ?>">
                    <?= csrf_field() ?>

                    <div class="row g-3 mb-3">
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">First Name *</label>
                            <input type="text" class="form-control" name="first_name" required value="<?= e($_POST['first_name'] ?? '') ?>" placeholder="Enter first name">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Last Name *</label>
                            <input type="text" class="form-control" name="last_name" required value="<?= e($_POST['last_name'] ?? '') ?>" placeholder="Enter last name">
                        </div>
                    </div>

                    <div class="row g-3 mb-3">
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Email Address *</label>
                            <input type="email" class="form-control" name="email" required value="<?= e($_POST['email'] ?? '') ?>" placeholder="username@couriva.local">
                        </div>
                        <div class="col-md-6">
                            <label for="createUserPassword" class="form-label fw-semibold">Password *</label>
                            <div class="input-group"><input type="password" class="form-control" id="createUserPassword" name="password" required minlength="10" autocomplete="new-password" placeholder="At least 10 characters"><button type="button" class="password-toggle" aria-label="Show password" aria-controls="createUserPassword" aria-pressed="false"><i class="bi bi-eye" aria-hidden="true"></i></button></div>
                        </div>
                    </div>

                    <div class="row g-3 mb-4">
                        <div class="col-md-4">
                            <label class="form-label fw-semibold">Role</label>
                            <select class="form-select" name="role">
                                <option value="user" selected>Standard User</option>
                                <option value="admin">Administrator</option>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold">Status</label>
                            <select class="form-select" name="status">
                                <option value="active" selected>Active</option>
                                <option value="disabled">Disabled</option>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold">Timezone</label>
                            <select class="form-select" name="timezone">
                                <option value="UTC" selected>UTC</option>
                                <option value="America/New_York">America/New_York</option>
                                <option value="Europe/London">Europe/London</option>
                                <option value="Asia/Tokyo">Asia/Tokyo</option>
                                <option value="Asia/Manila">Asia/Manila</option>
                            </select>
                        </div>
                    </div>

                    <button type="submit" class="btn btn-primary fw-semibold px-4">
                        <i class="bi bi-person-check-fill me-1"></i> Provision Account
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>

<?php require_once dirname(__DIR__) . '/includes/footer.php'; ?>
