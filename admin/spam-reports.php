<?php
/**
 * Couriva - Administrator Spam Report Review
 */

define('WEBMAIL_INIT', true);

require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/database.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/app/Services/SpamService.php';

require_admin();

$adminId = (int)$_SESSION['user_id'];
$spamService = new \App\Services\SpamService($pdo);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token()) {
        set_flash('danger', 'Security token expired. Please try again.');
    } else {
        $reportId = (int)($_POST['report_id'] ?? 0);
        $decision = $_POST['decision'] ?? '';
        $note = trim($_POST['reviewer_note'] ?? '');

        try {
            if ($spamService->review($adminId, $reportId, $decision, $note)) {
                set_flash('success', $decision === 'resolved'
                    ? 'Report resolved. The sender is blocked for the reporter.'
                    : 'Report dismissed. The message was returned to the reporter\'s Inbox.');
            } else {
                set_flash('warning', 'That report has already been reviewed.');
            }
        } catch (Throwable $e) {
            error_log('Spam report review failed: ' . $e->getMessage());
            set_flash('danger', 'The report could not be updated.');
        }
    }

    header('Location: ' . app_base_url('admin/spam-reports.php'));
    exit;
}

$reports = $spamService->getOpenReports(200);
$viewId = (int)($_GET['view'] ?? 0);
$viewReport = $viewId > 0 ? $spamService->getReport($viewId) : null;
$openCount = 0;
foreach ($reports as $report) {
    if ($report['status'] === 'open') {
        $openCount++;
    }
}

$pageTitle = 'Spam Reports';
require_once dirname(__DIR__) . '/includes/header.php';
?>

<div class="d-flex flex-wrap justify-content-between align-items-center mb-4 gap-2">
    <div>
        <h4 class="fw-bold mb-1"><i class="bi bi-shield-exclamation text-danger me-2"></i>Spam Reports</h4>
        <p class="text-muted small mb-0"><?= $openCount ?> open report<?= $openCount === 1 ? '' : 's' ?> awaiting review</p>
    </div>
    <a href="<?= app_base_url('admin/index.php') ?>" class="btn btn-outline-secondary btn-sm d-flex align-items-center gap-1">
        <i class="bi bi-arrow-left"></i><span>Dashboard</span>
    </a>
</div>

<?php if ($viewReport): ?>
    <div class="card shadow-sm border-0 mb-4">
        <div class="card-header bg-body py-3 border-bottom d-flex justify-content-between align-items-center">
            <h5 class="mb-0 fw-bold">Report #<?= (int)$viewReport['id'] ?> evidence</h5>
            <a href="<?= app_base_url('admin/spam-reports.php') ?>" class="btn btn-sm btn-outline-secondary">Close detail</a>
        </div>
        <div class="card-body p-4">
            <dl class="row small mb-3">
                <dt class="col-sm-3 text-muted">Reporter</dt>
                <dd class="col-sm-9"><?= e(trim($viewReport['reporter_first_name'] . ' ' . $viewReport['reporter_last_name'])) ?> &lt;<?= e($viewReport['reporter_email']) ?>&gt;</dd>
                <dt class="col-sm-3 text-muted">Sender</dt>
                <dd class="col-sm-9"><?= e($viewReport['sender_name_snapshot'] ?: $viewReport['sender_email_snapshot']) ?> &lt;<?= e($viewReport['sender_email_snapshot']) ?>&gt;</dd>
                <dt class="col-sm-3 text-muted">Subject</dt>
                <dd class="col-sm-9 fw-semibold"><?= e($viewReport['subject']) ?></dd>
                <dt class="col-sm-3 text-muted">User reason</dt>
                <dd class="col-sm-9"><?= e($viewReport['reason'] ?: 'No reason supplied.') ?></dd>
                <dt class="col-sm-3 text-muted">Status</dt>
                <dd class="col-sm-9 text-capitalize"><?= e($viewReport['status']) ?></dd>
            </dl>
            <div class="border rounded bg-body-tertiary p-3">
                <div class="small fw-semibold text-muted mb-2">Message evidence (text only)</div>
                <pre class="mb-0 small text-body" style="white-space: pre-wrap; max-height: 320px; overflow: auto;"><?= e(mb_strimwidth(strip_tags((string)$viewReport['body']), 0, 5000, '…')) ?></pre>
            </div>

            <?php if ($viewReport['status'] === 'open'): ?>
                <form method="POST" action="<?= app_base_url('admin/spam-reports.php') ?>" class="mt-4">
                    <?= csrf_field() ?>
                    <input type="hidden" name="report_id" value="<?= (int)$viewReport['id'] ?>">
                    <label for="reviewerNote" class="form-label fw-semibold">Reviewer note (optional)</label>
                    <textarea class="form-control mb-3" id="reviewerNote" name="reviewer_note" rows="2" maxlength="500"></textarea>
                    <div class="d-flex gap-2">
                        <button type="submit" name="decision" value="resolved" class="btn btn-danger">
                            <i class="bi bi-shield-check me-1"></i>Resolve and block sender
                        </button>
                        <button type="submit" name="decision" value="dismissed" class="btn btn-outline-secondary">
                            <i class="bi bi-arrow-counterclockwise me-1"></i>Dismiss and restore
                        </button>
                    </div>
                </form>
            <?php endif; ?>
        </div>
    </div>
<?php endif; ?>

<div class="card shadow-sm border-0">
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead class="bg-body-tertiary small text-uppercase">
                <tr><th>Status</th><th>Reported Message</th><th>Reporter</th><th>Reason</th><th>Filed</th><th class="text-end">Action</th></tr>
            </thead>
            <tbody>
                <?php if (empty($reports)): ?>
                    <tr><td colspan="6" class="text-center py-4 text-muted">No spam reports submitted yet.</td></tr>
                <?php else: ?>
                    <?php foreach ($reports as $report): ?>
                        <tr>
                            <td>
                                <span class="badge <?= $report['status'] === 'open' ? 'bg-warning-subtle text-warning border border-warning-subtle' : 'bg-secondary-subtle text-secondary border' ?> text-capitalize">
                                    <?= e($report['status']) ?>
                                </span>
                            </td>
                            <td>
                                <div class="fw-semibold small"><?= e($report['subject']) ?></div>
                                <div class="text-muted small"><?= e($report['sender_email_snapshot']) ?></div>
                            </td>
                            <td class="small"><?= e(trim($report['reporter_first_name'] . ' ' . $report['reporter_last_name'])) ?><br><span class="text-muted"><?= e($report['reporter_email']) ?></span></td>
                            <td class="small text-muted"><?= e(mb_strimwidth($report['reason'] ?: '—', 0, 80, '…')) ?></td>
                            <td class="small text-muted"><?= date('M d, Y H:i', strtotime($report['created_at'])) ?></td>
                            <td class="text-end">
                                <a href="<?= app_base_url('admin/spam-reports.php?view=' . (int)$report['id']) ?>" class="btn btn-sm btn-outline-primary">
                                    <i class="bi bi-eye me-1"></i>Review
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require_once dirname(__DIR__) . '/includes/footer.php'; ?>
