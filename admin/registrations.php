<?php
/**
 * Couriva - Administrator Account Requests (Retired)
 * Self-registration is retired; accounts are provisioned in Windows Active Directory.
 */

define('WEBMAIL_INIT', true);

require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/database.php';
require_once dirname(__DIR__) . '/includes/auth.php';

require_admin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token()) {
        http_response_code(403);
        set_flash('danger', 'Security token expired. Please try again.');
    } else {
        http_response_code(410);
        set_flash('warning', 'Self-registration approvals are retired. Provision accounts in Windows Active Directory (OU=CourivaUsers,DC=couriva,DC=local).');
    }
}

$pageTitle = 'Account Requests';
require_once dirname(__DIR__) . '/includes/header.php';
?>

<div class="d-flex flex-wrap justify-content-between align-items-center mb-4 gap-2">
    <div>
        <h4 class="fw-bold mb-1"><i class="bi bi-person-check-fill text-primary me-2"></i>Account Requests</h4>
        <p class="text-muted small mb-0">Self-registration approval queue retired</p>
    </div>
    <div class="d-flex gap-2">
        <a href="<?= app_base_url('admin/users.php') ?>" class="btn btn-outline-primary btn-sm d-flex align-items-center gap-1">
            <i class="bi bi-people-fill"></i><span>Manage Users</span>
        </a>
        <a href="<?= app_base_url('admin/index.php') ?>" class="btn btn-outline-secondary btn-sm d-flex align-items-center gap-1">
            <i class="bi bi-arrow-left"></i><span>Dashboard</span>
        </a>
    </div>
</div>

<div class="card shadow-sm border-0">
    <div class="card-body p-4">
        <h5 class="fw-semibold mb-2">Account requests are now managed in Windows Active Directory</h5>
        <p class="text-body-secondary mb-3">
            Public self-registration and the web approval queue have been retired. No new pending requests are accepted through Couriva.
        </p>
        <p class="text-body-secondary mb-0">
            Provision user accounts in Windows Active Directory under <code>OU=CourivaUsers,DC=couriva,DC=local</code>. For emergency break-glass local administrator access, use <code>php bin/create-admin.php</code>.
        </p>
    </div>
</div>

<?php require_once dirname(__DIR__) . '/includes/footer.php'; ?>
