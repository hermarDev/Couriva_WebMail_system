<?php
/**
 * Couriva - Administrator Create User (Disabled)
 * User provisioning is managed in Windows Active Directory or via CLI.
 */

define('WEBMAIL_INIT', true);

require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/database.php';
require_once dirname(__DIR__) . '/includes/auth.php';

require_admin();

$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    http_response_code(403);
    if (!verify_csrf_token()) {
        $error = 'Security token invalid.';
    } else {
        $error = 'Web UI user creation is disabled. Provision users in Windows Active Directory (OU=CourivaUsers,DC=couriva,DC=local) or use php bin/create-admin.php for emergency local administrator access.';
    }
}

$pageTitle = 'Create New User';
require_once dirname(__DIR__) . '/includes/header.php';
?>

<div class="row justify-content-center">
    <div class="col-lg-8">
        <div class="d-flex justify-content-between align-items-center mb-3">
            <div>
                <h4 class="fw-bold mb-1"><i class="bi bi-shield-lock-fill text-primary me-2"></i>Create New User</h4>
                <p class="text-muted small mb-0">Account provisioning policy for Couriva</p>
            </div>
            <a href="<?= app_base_url('admin/users.php') ?>" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left me-1"></i> Back to Users</a>
        </div>

        <?= render_inline_alert($error, ['margin' => 'mb-4']) ?>

        <div class="card shadow-sm border-0 mb-4">
            <div class="card-body p-4">
                <h5 class="fw-semibold mb-2">Web UI user creation is disabled</h5>
                <p class="text-body-secondary mb-3">
                    Couriva accounts are managed centrally in Windows Active Directory rather than created through the web interface.
                </p>
                <ul class="mb-4 text-body-secondary">
                    <li class="mb-2"><strong>Domain users:</strong> Provision accounts in Windows Active Directory under <code>OU=CourivaUsers,DC=couriva,DC=local</code>. Mailboxes sync automatically on first sign-in.</li>
                    <li><strong>Break-glass local administrator:</strong> For emergency local admin provisioning when AD DS is unreachable, run <code>php bin/create-admin.php</code> on the server CLI.</li>
                </ul>
                <a href="<?= app_base_url('admin/users.php') ?>" class="btn btn-primary fw-semibold">
                    <i class="bi bi-people-fill me-1"></i> Back to Users
                </a>
            </div>
        </div>
    </div>
</div>

<?php require_once dirname(__DIR__) . '/includes/footer.php'; ?>
