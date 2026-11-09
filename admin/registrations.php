<?php
/**
 * Couriva - Administrator Account Requests
 * Review self-registered PENDING accounts and record approval decisions.
 */

define('WEBMAIL_INIT', true);

require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/database.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/app/Services/AccountService.php';

require_admin();

$adminId = (int)$_SESSION['user_id'];
$accountService = new \App\Services\AccountService($pdo);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token()) {
        set_flash('danger', 'Security token expired. Please try again.');
    } else {
        $action = $_POST['action'] ?? '';
        $targetId = (int)($_POST['user_id'] ?? 0);
        $reason = trim($_POST['reason'] ?? '');

        try {
            if ($action === 'approve') {
                $result = $accountService->approve($adminId, $targetId);
            } elseif ($action === 'reject') {
                $result = $accountService->reject($adminId, $targetId, $reason);
            } else {
                $result = ['success' => false, 'message' => 'Unsupported action.'];
            }

            set_flash($result['success'] ? 'success' : 'warning', $result['message']);
        } catch (Throwable $e) {
            error_log('Account review failed: ' . $e->getMessage());
            set_flash('danger', 'The account request could not be updated.');
        }
    }

    header('Location: ' . app_base_url('admin/registrations.php'));
    exit;
}

$pending = $accountService->listPending();
$reviewed = $accountService->listReviewed();

$pageTitle = 'Account Requests';
require_once dirname(__DIR__) . '/includes/header.php';
?>

<div class="d-flex flex-wrap justify-content-between align-items-center mb-4 gap-2">
    <div>
        <h4 class="fw-bold mb-1"><i class="bi bi-person-check-fill text-primary me-2"></i>Account Requests</h4>
        <p class="text-muted small mb-0">Review <?= count($pending) ?> pending registration request<?= count($pending) === 1 ? '' : 's' ?></p>
    </div>
    <a href="<?= app_base_url('admin/index.php') ?>" class="btn btn-outline-secondary btn-sm d-flex align-items-center gap-1">
        <i class="bi bi-arrow-left"></i><span>Dashboard</span>
    </a>
</div>

<div class="card shadow-sm border-0 mb-4">
    <div class="card-header bg-body py-3 border-bottom">
        <h5 class="mb-0 fw-bold"><i class="bi bi-hourglass-split text-warning me-2"></i>Pending Approval</h5>
    </div>
    <?php if (empty($pending)): ?>
        <div class="card-body p-4 text-center text-muted">
            <i class="bi bi-check2-circle display-5 d-block mb-2 text-success"></i>
            <div class="fw-semibold">No pending requests</div>
            <div class="small">New account requests will appear here.</div>
        </div>
    <?php else: ?>
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light small text-uppercase">
                    <tr>
                        <th>Applicant</th>
                        <th>Requested Address</th>
                        <th>Requested</th>
                        <th class="text-end">Decision</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($pending as $request): ?>
                        <tr>
                            <td>
                                <div class="fw-semibold"><?= e($request['first_name'] . ' ' . $request['last_name']) ?></div>
                                <div class="small text-muted">User ID <?= (int)$request['id'] ?></div>
                            </td>
                            <td class="font-monospace small"><?= e($request['email']) ?></td>
                            <td class="small text-muted">
                                <?= date('M d, Y H:i', strtotime($request['requested_at'] ?: $request['created_at'])) ?>
                            </td>
                            <td class="text-end text-nowrap">
                                <form method="POST" action="<?= app_base_url('admin/registrations.php') ?>" class="d-inline">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="approve">
                                    <input type="hidden" name="user_id" value="<?= (int)$request['id'] ?>">
                                    <button type="submit" class="btn btn-sm btn-success">
                                        <i class="bi bi-check-lg me-1"></i>Approve
                                    </button>
                                </form>
                                <button type="button" class="btn btn-sm btn-outline-danger" data-bs-toggle="modal" data-bs-target="#rejectModal"
                                    data-user-id="<?= (int)$request['id'] ?>"
                                    data-user-email="<?= e($request['email']) ?>">
                                    <i class="bi bi-x-lg me-1"></i>Reject
                                </button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<div class="card shadow-sm border-0">
    <div class="card-header bg-body py-3 border-bottom">
        <h5 class="mb-0 fw-bold"><i class="bi bi-clock-history text-secondary me-2"></i>Recent Declined / Suspended</h5>
    </div>
    <?php if (empty($reviewed)): ?>
        <div class="card-body p-3 text-muted small">No declined or suspended accounts.</div>
    <?php else: ?>
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light small text-uppercase">
                    <tr><th>Account</th><th>Status</th><th>Reviewed By</th><th>Reason</th><th>Reviewed</th></tr>
                </thead>
                <tbody>
                    <?php foreach ($reviewed as $account): ?>
                        <tr>
                            <td class="small"><?= e($account['first_name'] . ' ' . $account['last_name']) ?><br><span class="font-monospace text-muted"><?= e($account['email']) ?></span></td>
                            <td><span class="badge bg-danger-subtle text-danger border border-danger-subtle"><?= e(user_status_label($account['status'])) ?></span></td>
                            <td class="small text-muted"><?= e($account['reviewer_email'] ?? 'System') ?></td>
                            <td class="small text-muted"><?= e($account['rejection_reason'] ?? '—') ?></td>
                            <td class="small text-muted"><?= $account['reviewed_at'] ? date('M d, Y H:i', strtotime($account['reviewed_at'])) : '—' ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<!-- Reject confirmation modal -->
<div class="modal fade" id="rejectModal" tabindex="-1" aria-labelledby="rejectModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" action="<?= app_base_url('admin/registrations.php') ?>">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="reject">
                <input type="hidden" name="user_id" id="rejectUserId" value="">
                <div class="modal-header">
                    <h5 class="modal-title" id="rejectModalLabel">Reject account request</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <p class="small text-muted mb-3">Declining <strong id="rejectUserEmail"></strong> prevents sign-in. The reason is shown to the applicant.</p>
                    <label for="rejectReason" class="form-label">Reason (optional)</label>
                    <textarea class="form-control" id="rejectReason" name="reason" rows="3" maxlength="500" placeholder="Explain the decision..."></textarea>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-danger">Reject request</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php require_once dirname(__DIR__) . '/includes/footer.php'; ?>
