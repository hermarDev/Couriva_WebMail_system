<?php
/**
 * Couriva - Administrator Edit User
 */

define('WEBMAIL_INIT', true);

require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/database.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/app/Models/User.php';

require_admin();

$userModel = new \App\Models\User($pdo);
$targetId = (int)($_GET['id'] ?? 0);
$targetUser = $userModel->findById($targetId);

if (!$targetUser) {
    set_flash('danger', 'User not found.');
    header('Location: ' . app_base_url('admin/users.php'));
    exit;
}

$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token()) {
        $error = 'Security token invalid.';
    } else {
        $action = $_POST['action'] ?? '';

        if ($action === 'update_profile') {
            $firstName = trim($_POST['first_name'] ?? '');
            $lastName = trim($_POST['last_name'] ?? '');
            $role = $_POST['role'] ?? 'user';
            $status = $_POST['status'] ?? 'active';
            $timezone = $_POST['timezone'] ?? 'UTC';

            if (empty($firstName) || empty($lastName)) {
                $error = 'First name and last name are required.';
            } elseif (!is_valid_user_role($role) || !is_valid_user_status($status)) {
                $error = 'Invalid role or account status selection.';
            } else {
                $userModel->update($targetId, [
                    'first_name' => $firstName,
                    'last_name'  => $lastName,
                    'role'       => $role,
                    'status'     => $status,
                    'timezone'   => $timezone,
                ]);

                log_activity($_SESSION['user_id'], 'USER_UPDATED', 'SUCCESS', "Updated profile for user ID {$targetId}");
                set_flash('success', "Account details for {$targetUser['email']} updated.");
                header('Location: ' . app_base_url('admin/users.php'));
                exit;
            }
        } elseif ($action === 'reset_password') {
            $newPass = $_POST['new_password'] ?? '';
            if (!empty($targetUser['auth_source']) && $targetUser['auth_source'] === 'ldap') {
                $error = 'This user is managed by Active Directory. Passwords must be reset in the Windows Domain Controller.';
            } elseif (!is_valid_password_complexity($newPass)) {
                $error = 'New password must be at least 10 characters long and include uppercase, lowercase, a number, and a symbol.';
            } else {
                $userModel->updatePassword($targetId, $newPass, true);
                log_activity($_SESSION['user_id'], 'PASSWORD_RESET', 'SUCCESS', "Admin reset password for user ID {$targetId}");
                set_flash('success', "Password successfully reset for {$targetUser['email']}.");
                header('Location: ' . app_base_url('admin/users.php'));
                exit;
            }
        }
    }
}

$pageTitle = 'Edit User: ' . $targetUser['email'];
require_once dirname(__DIR__) . '/includes/header.php';
?>

<div class="row justify-content-center">
    <div class="col-lg-8">
        <div class="d-flex justify-content-between align-items-center mb-3">
            <div>
                <h4 class="fw-bold mb-1"><i class="bi bi-person-gear text-primary me-2"></i>Edit User Account</h4>
                <p class="text-muted small mb-0"><?= e($targetUser['email']) ?> (ID: <?= $targetUser['id'] ?>)</p>
            </div>
            <a href="<?= app_base_url('admin/users.php') ?>" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left me-1"></i> Back to Users</a>
        </div>

        <?= render_inline_alert($error, ['margin' => 'mb-4']) ?>

        <!-- Edit Profile Card -->
        <div class="card shadow-sm border-0 mb-4">
            <div class="card-header bg-body py-3 border-bottom">
                <h6 class="mb-0 fw-bold">Account Information</h6>
            </div>
            <div class="card-body p-4">
                <form method="POST" action="<?= app_base_url('admin/edit-user.php?id=' . $targetUser['id']) ?>">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="update_profile">

                    <div class="row g-3 mb-3">
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">First Name</label>
                            <input type="text" class="form-control" name="first_name" required value="<?= e($targetUser['first_name']) ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Last Name</label>
                            <input type="text" class="form-control" name="last_name" required value="<?= e($targetUser['last_name']) ?>">
                        </div>
                    </div>

                    <div class="row g-3 mb-4">
                        <div class="col-md-4">
                            <label class="form-label fw-semibold">Role</label>
                            <select class="form-select" name="role">
                                <option value="user" <?= $targetUser['role'] === 'user' ? 'selected' : '' ?>>Standard User</option>
                                <option value="admin" <?= $targetUser['role'] === 'admin' ? 'selected' : '' ?>>Administrator</option>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold">Status</label>
                            <select class="form-select" name="status">
                                <option value="active" <?= $targetUser['status'] === 'active' ? 'selected' : '' ?>>Active</option>
                                <option value="disabled" <?= $targetUser['status'] === 'disabled' ? 'selected' : '' ?>>Disabled</option>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold">Timezone</label>
                            <select class="form-select" name="timezone">
                                <?php 
                                $timezones = ['UTC', 'America/New_York', 'Europe/London', 'Asia/Tokyo', 'Asia/Manila'];
                                foreach ($timezones as $tz): ?>
                                    <option value="<?= $tz ?>" <?= $targetUser['timezone'] === $tz ? 'selected' : '' ?>><?= $tz ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <button type="submit" class="btn btn-primary fw-semibold px-4">
                        <i class="bi bi-check-circle me-1"></i> Update User Details
                    </button>
                </form>
            </div>
        </div>

        <!-- Reset Password Card -->
        <div class="card shadow-sm border-0 mb-4">
            <div class="card-header bg-body py-3 border-bottom">
                <h6 class="mb-0 fw-bold"><i class="bi bi-key text-warning me-2"></i>Reset User Password</h6>
            </div>
            <div class="card-body p-4">
                <form method="POST" action="<?= app_base_url('admin/edit-user.php?id=' . $targetUser['id']) ?>">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="reset_password">

                    <div class="mb-3">
                        <label for="adminResetPassword" class="form-label fw-semibold">New Password for <?= e($targetUser['email']) ?></label>
                        <div class="input-group"><input type="password" class="form-control" id="adminResetPassword" name="new_password" required minlength="10" autocomplete="new-password" placeholder="At least 10 characters"><button type="button" class="password-toggle" aria-label="Show new password" aria-controls="adminResetPassword" aria-pressed="false"><i class="bi bi-eye" aria-hidden="true"></i></button></div>
                    </div>

                    <button type="submit" class="btn btn-outline-warning fw-semibold">
                        <i class="bi bi-arrow-repeat me-1"></i> Reset Password
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>

<?php require_once dirname(__DIR__) . '/includes/footer.php'; ?>
