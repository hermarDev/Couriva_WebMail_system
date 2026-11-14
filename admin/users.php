<?php
/**
 * Couriva - Administrator User Management
 */

define('WEBMAIL_INIT', true);

require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/database.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/app/Models/User.php';

require_admin();

$userModel = new \App\Models\User($pdo);

// Handle POST actions: create_user, update_user, toggle_status, delete
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (verify_csrf_token()) {
        $action = $_POST['action'] ?? '';
        $targetId = (int)($_POST['user_id'] ?? 0);

        if ($action === 'create_user') {
            $email = trim($_POST['email'] ?? '');
            $password = $_POST['password'] ?? '';
            $firstName = trim($_POST['first_name'] ?? '');
            $lastName = trim($_POST['last_name'] ?? '');
            $role = $_POST['role'] ?? 'user';
            $status = $_POST['status'] ?? 'active';
            $timezone = $_POST['timezone'] ?? 'UTC';

            if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                set_flash('danger', 'A valid email address is required.');
            } elseif (!is_valid_password_complexity($password)) {
                set_flash('danger', 'Password must be at least 10 characters and include uppercase, lowercase, number, and symbol.');
            } elseif (empty($firstName) || empty($lastName)) {
                set_flash('danger', 'First name and last name are required.');
            } elseif (!is_valid_user_role($role) || !is_valid_user_status($status)) {
                set_flash('danger', 'Invalid role or account status selection.');
            } elseif ($userModel->findByEmail($email)) {
                set_flash('danger', 'A user account with this email address already exists.');
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
                set_flash('success', "User account {$email} has been provisioned.");
            }
        } elseif ($action === 'update_user' && $targetId > 0) {
            $firstName = trim($_POST['first_name'] ?? '');
            $lastName = trim($_POST['last_name'] ?? '');
            $role = $_POST['role'] ?? 'user';
            $status = $_POST['status'] ?? 'active';
            $timezone = $_POST['timezone'] ?? 'UTC';

            if (empty($firstName) || empty($lastName)) {
                set_flash('danger', 'First name and last name are required.');
            } elseif (!is_valid_user_role($role) || !is_valid_user_status($status)) {
                set_flash('danger', 'Invalid role or account status selection.');
            } else {
                $userModel->update($targetId, [
                    'first_name' => $firstName,
                    'last_name'  => $lastName,
                    'role'       => $role,
                    'status'     => $status,
                    'timezone'   => $timezone,
                ]);
                log_activity($_SESSION['user_id'], 'USER_UPDATED', 'SUCCESS', "Updated details for user ID {$targetId}");
                set_flash('success', "User details updated successfully.");
            }
        } elseif ($targetId > 0 && $targetId !== (int)$_SESSION['user_id']) {
            if ($action === 'toggle_status') {
                $target = $userModel->findById($targetId);
                if ($target) {
                    $newStatus = $target['status'] === 'active' ? 'disabled' : 'active';
                    $userModel->update($targetId, ['status' => $newStatus]);
                    log_activity($_SESSION['user_id'], 'USER_STATUS_CHANGED', 'SUCCESS', "User ID {$targetId} status changed to {$newStatus}");
                    set_flash('success', "User account {$target['email']} is now {$newStatus}.");
                }
            } elseif ($action === 'delete') {
                $userModel->delete($targetId);
                log_activity($_SESSION['user_id'], 'USER_DELETED', 'SUCCESS', "User ID {$targetId} deleted");
                set_flash('success', "User account permanently deleted.");
            }
        }
    }
    header('Location: ' . app_base_url('admin/users.php'));
    exit;
}

$users = $userModel->getAll(100, 0);

$pageTitle = 'Manage Users';
require_once dirname(__DIR__) . '/includes/header.php';
?>

<div class="d-flex flex-wrap justify-content-between align-items-center mb-4 gap-2">
    <div>
        <h4 class="fw-bold mb-1"><i class="bi bi-people-fill text-primary me-2"></i>User Governance</h4>
        <p class="text-muted small mb-0">Create, monitor, edit, and configure system accounts</p>
    </div>
    <div class="d-flex gap-2">
        <a href="<?= app_base_url('admin/index.php') ?>" class="btn btn-outline-secondary btn-sm d-flex align-items-center gap-1">
            <i class="bi bi-arrow-left"></i>
            <span>Dashboard</span>
        </a>
        <button type="button" class="btn btn-primary btn-sm d-flex align-items-center gap-1 shadow-sm" data-bs-toggle="modal" data-bs-target="#createUserModal">
            <i class="bi bi-person-plus-fill"></i>
            <span>Add New User</span>
        </button>
    </div>
</div>

<div class="card shadow-sm border-0 mb-4">
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead class="bg-body-tertiary small text-uppercase">
                <tr>
                    <th>ID</th>
                    <th>User</th>
                    <th>Email Address</th>
                    <th>Role</th>
                    <th>Status</th>
                    <th>Timezone</th>
                    <th>Created</th>
                    <th class="text-end">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($users as $u): ?>
                    <tr>
                        <td class="text-muted small"><?= $u['id'] ?></td>
                        <td class="fw-semibold">
                            <span class="badge rounded-pill bg-primary-subtle text-primary border me-1" style="width: 28px; height: 28px; display: inline-flex; align-items: center; justify-content: center;">
                                <?= strtoupper(substr($u['first_name'], 0, 1)) ?>
                            </span>
                            <?= e($u['first_name'] . ' ' . $u['last_name']) ?>
                            <?php if ($u['id'] == $_SESSION['user_id']): ?>
                                <span class="badge bg-secondary-subtle text-secondary ms-1">You</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <a href="<?= app_base_url('public/compose.php?to=' . urlencode($u['email'])) ?>" class="text-decoration-none">
                                <?= e($u['email']) ?>
                            </a>
                        </td>
                        <td>
                            <?php if ($u['role'] === 'admin'): ?>
                                <span class="badge bg-dark-subtle text-dark border border-dark-subtle">ADMIN</span>
                            <?php else: ?>
                                <span class="badge bg-secondary-subtle text-secondary border border-secondary-subtle">USER</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($u['status'] === 'active'): ?>
                                <span class="badge bg-success-subtle text-success border border-success-subtle">Active</span>
                            <?php else: ?>
                                <span class="badge bg-danger-subtle text-danger border border-danger-subtle">Disabled</span>
                            <?php endif; ?>
                        </td>
                        <td class="small text-muted"><?= e($u['timezone']) ?></td>
                        <td class="small text-muted"><?= date('Y-m-d', strtotime($u['created_at'])) ?></td>
                        <td class="text-end text-nowrap">
                            <div class="dropdown d-inline-block">
                                <button class="btn btn-sm btn-light border dropdown-toggle py-1 px-2" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                                    Actions
                                </button>
                                <ul class="dropdown-menu dropdown-menu-end shadow-sm">
                                    <li>
                                        <button type="button" class="dropdown-item edit-user-modal-btn"
                                            data-id="<?= $u['id'] ?>"
                                            data-firstname="<?= e($u['first_name']) ?>"
                                            data-lastname="<?= e($u['last_name']) ?>"
                                            data-email="<?= e($u['email']) ?>"
                                            data-role="<?= e($u['role']) ?>"
                                            data-status="<?= e($u['status']) ?>"
                                            data-timezone="<?= e($u['timezone']) ?>">
                                            <i class="bi bi-pencil me-2 text-primary"></i> Edit in Modal
                                        </button>
                                    </li>
                                    <li>
                                        <a class="dropdown-item" href="<?= app_base_url('admin/edit-user.php?id=' . $u['id']) ?>">
                                            <i class="bi bi-box-arrow-up-right me-2 text-secondary"></i> Dedicated Edit Page
                                        </a>
                                    </li>
                                    <?php if ($u['id'] != $_SESSION['user_id']): ?>
                                        <li><hr class="dropdown-divider"></li>
                                        <li>
                                            <form method="POST" action="<?= app_base_url('admin/users.php') ?>">
                                                <?= csrf_field() ?>
                                                <input type="hidden" name="action" value="toggle_status">
                                                <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
                                                <button type="submit" class="dropdown-item <?= $u['status'] === 'active' ? 'text-warning' : 'text-success' ?>">
                                                    <i class="bi <?= $u['status'] === 'active' ? 'bi-person-slash' : 'bi-person-check' ?> me-2"></i>
                                                    <?= $u['status'] === 'active' ? 'Disable Account' : 'Activate Account' ?>
                                                </button>
                                            </form>
                                        </li>
                                        <li>
                                            <form method="POST" action="<?= app_base_url('admin/users.php') ?>" data-confirm="Permanently delete this user account?">
                                                <?= csrf_field() ?>
                                                <input type="hidden" name="action" value="delete">
                                                <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
                                                <button type="submit" class="dropdown-item text-danger">
                                                    <i class="bi bi-trash me-2"></i> Delete Account
                                                </button>
                                            </form>
                                        </li>
                                    <?php endif; ?>
                                </ul>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Modal: Create New User -->
<div class="modal fade" id="createUserModal" tabindex="-1" aria-labelledby="createUserModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <form method="POST" action="<?= app_base_url('admin/users.php') ?>" class="modal-content">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="create_user">
            <div class="modal-header">
                <h5 class="modal-title" id="createUserModalLabel"><i class="bi bi-person-plus text-primary me-2"></i>Provision New Account</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body p-4">
                <div class="row g-3 mb-3">
                    <div class="col-md-6">
                        <label class="form-label fw-semibold">First Name *</label>
                        <input type="text" class="form-control" name="first_name" required placeholder="Enter first name">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-semibold">Last Name *</label>
                        <input type="text" class="form-control" name="last_name" required placeholder="Enter last name">
                    </div>
                </div>

                <div class="row g-3 mb-3">
                    <div class="col-md-6">
                        <label class="form-label fw-semibold">Email Address *</label>
                        <input type="email" class="form-control" name="email" required placeholder="username@couriva.local">
                    </div>
                    <div class="col-md-6">
                        <label for="modalCreatePassword" class="form-label fw-semibold">Password *</label>
                        <div class="input-group"><input type="password" class="form-control" id="modalCreatePassword" name="password" required minlength="10" autocomplete="new-password" placeholder="At least 10 characters"><button type="button" class="password-toggle" aria-label="Show password" aria-controls="modalCreatePassword" aria-pressed="false"><i class="bi bi-eye" aria-hidden="true"></i></button></div>
                    </div>
                </div>

                <div class="row g-3">
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
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-light border" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-primary px-4">Create Account</button>
            </div>
        </form>
    </div>
</div>

<!-- Modal: Edit User -->
<div class="modal fade" id="editUserModal" tabindex="-1" aria-labelledby="editUserModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <form method="POST" action="<?= app_base_url('admin/users.php') ?>" class="modal-content" id="editUserForm">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="update_user">
            <input type="hidden" name="user_id" id="modalEditUserId">
            <div class="modal-header">
                <h5 class="modal-title" id="editUserModalLabel"><i class="bi bi-person-gear text-primary me-2"></i>Edit Account Details</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body p-4">
                <div class="mb-3">
                    <label class="form-label fw-semibold">Email Address</label>
                    <input type="email" class="form-control bg-body-tertiary" id="modalEditUserEmail" readonly>
                </div>

                <div class="row g-3 mb-3">
                    <div class="col-md-6">
                        <label class="form-label fw-semibold">First Name *</label>
                        <input type="text" class="form-control" name="first_name" id="modalEditUserFirstName" required>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-semibold">Last Name *</label>
                        <input type="text" class="form-control" name="last_name" id="modalEditUserLastName" required>
                    </div>
                </div>

                <div class="row g-3">
                    <div class="col-md-4">
                        <label class="form-label fw-semibold">Role</label>
                        <select class="form-select" name="role" id="modalEditUserRole">
                            <option value="user">Standard User</option>
                            <option value="admin">Administrator</option>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label fw-semibold">Status</label>
                        <select class="form-select" name="status" id="modalEditUserStatus">
                            <option value="active">Active</option>
                            <option value="disabled">Disabled</option>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label fw-semibold">Timezone</label>
                        <select class="form-select" name="timezone" id="modalEditUserTimezone">
                            <option value="UTC">UTC</option>
                            <option value="America/New_York">America/New_York</option>
                            <option value="Europe/London">Europe/London</option>
                            <option value="Asia/Tokyo">Asia/Tokyo</option>
                            <option value="Asia/Manila">Asia/Manila</option>
                        </select>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-light border" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-primary px-4">Save Changes</button>
            </div>
        </form>
    </div>
</div>

<?php require_once dirname(__DIR__) . '/includes/footer.php'; ?>
