<?php
/**
 * Couriva - Scheduled Send Queue
 * Lists future dispatches, allows cancellation, and lazily delivers due mail
 * when a cron job is not configured. Production deployments should run
 * bin/process_scheduled.php every minute.
 */

define('WEBMAIL_INIT', true);

require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/database.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/app/Models/User.php';
require_once dirname(__DIR__) . '/app/Models/Email.php';
require_once dirname(__DIR__) . '/app/Models/Attachment.php';
require_once dirname(__DIR__) . '/app/Services/AttachmentService.php';
require_once dirname(__DIR__) . '/app/Services/SmtpTransport.php';
require_once dirname(__DIR__) . '/app/Services/MailService.php';
require_once dirname(__DIR__) . '/app/Services/ScheduledMailService.php';

require_auth();

$userId = (int)$_SESSION['user_id'];
$activeFolder = 'scheduled';
$pageTitle = 'Scheduled';
$scheduledService = new \App\Services\ScheduledMailService($pdo);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token()) {
        set_flash('danger', 'Security token expired. Please try again.');
    } else {
        $action = $_POST['action'] ?? '';
        $scheduleId = (int)($_POST['schedule_id'] ?? 0);

        if ($action === 'cancel' && $scheduleId > 0) {
            if ($scheduledService->cancel($userId, $scheduleId)) {
                set_flash('success', 'Scheduled message cancelled. The draft is still in Drafts.');
            } else {
                set_flash('warning', 'That schedule could not be cancelled.');
            }
        } elseif ($action === 'dismiss' && $scheduleId > 0) {
            $stmt = $pdo->prepare("
                DELETE FROM scheduled_emails
                WHERE id = ? AND user_id = ? AND status IN ('failed', 'cancelled', 'sent')
            ");
            $stmt->execute([$scheduleId, $userId]);
            set_flash('success', 'Schedule entry removed.');
        }
    }

    header('Location: ' . app_base_url('public/scheduled.php'));
    exit;
}

// Best-effort fallback when no cron worker is configured. Rate-limited to one
// run per minute per session so page loads stay fast.
$lastRun = (int)($_SESSION['scheduled_last_run'] ?? 0);
if ($lastRun < time() - 60) {
    $_SESSION['scheduled_last_run'] = time();
    try {
        $scheduledService->processDue(5);
    } catch (Throwable $e) {
        error_log('Scheduled page dispatch check failed: ' . $e->getMessage());
    }
}

$schedules = $scheduledService->getForUser($userId);
$pendingCount = 0;
$failedCount = 0;
foreach ($schedules as $schedule) {
    if ($schedule['status'] === 'scheduled') {
        $pendingCount++;
    } elseif ($schedule['status'] === 'failed') {
        $failedCount++;
    }
}

require_once dirname(__DIR__) . '/includes/header.php';
?>

<div class="d-flex flex-wrap justify-content-between align-items-center mb-4 gap-2">
    <div>
        <h4 class="fw-bold mb-1"><i class="bi bi-calendar2-check-fill text-primary me-2"></i>Scheduled</h4>
        <p class="text-muted small mb-0">
            <?= $pendingCount ?> waiting &middot; <?= $failedCount ?> failed
            &middot; Times shown in <?= e(user_timezone()) ?>
        </p>
    </div>
    <a href="<?= app_base_url('public/compose.php') ?>" class="btn btn-primary btn-sm">
        <i class="bi bi-pencil-square me-1"></i>New message
    </a>
</div>

<?php if ($failedCount > 0): ?>
    <div class="alert alert-warning d-flex align-items-start gap-2" role="status">
        <i class="bi bi-exclamation-triangle-fill mt-1"></i>
        <div class="small">A scheduled message could not be delivered. Open the draft to check recipients, then schedule it again.</div>
    </div>
<?php endif; ?>

<div class="card shadow-sm border-0">
    <?php if (empty($schedules)): ?>
        <div class="empty-state-container">
            <i class="bi bi-calendar2-plus empty-state-icon"></i>
            <div class="empty-state-title">Nothing scheduled</div>
            <div class="empty-state-subtitle">From Compose, choose “Schedule send” to deliver a message later.</div>
        </div>
    <?php else: ?>
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0 scheduled-table">
                <thead class="table-light small text-uppercase">
                    <tr>
                        <th>Status</th>
                        <th>Subject</th>
                        <th>Scheduled For</th>
                        <th>Created</th>
                        <th class="text-end">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($schedules as $schedule): ?>
                        <?php
                        $status = $schedule['status'];
                        $badge = match ($status) {
                            'scheduled' => 'bg-primary-subtle text-primary border border-primary-subtle',
                            'processing' => 'bg-info-subtle text-info border border-info-subtle',
                            'sent' => 'bg-success-subtle text-success border border-success-subtle',
                            'failed' => 'bg-danger-subtle text-danger border border-danger-subtle',
                            'cancelled' => 'bg-secondary-subtle text-secondary border',
                            default => 'bg-secondary-subtle text-secondary border',
                        };
                        ?>
                        <tr>
                            <td><span class="badge <?= e($badge) ?> text-capitalize"><?= e($status) ?></span></td>
                            <td>
                                <div class="fw-semibold small text-truncate" style="max-width: 320px;">
                                    <?= e($schedule['subject']) ?>
                                </div>
                                <?php if (!empty($schedule['last_error'])): ?>
                                    <div class="text-danger small"><?= e($schedule['last_error']) ?></div>
                                <?php endif; ?>
                            </td>
                            <td class="small">
                                <div><?= e(format_user_datetime($schedule['scheduled_at'])) ?></div>
                                <div class="text-muted" style="font-size: .75rem;"><?= e(user_timezone()) ?></div>
                            </td>
                            <td class="small text-muted"><?= e(format_user_datetime($schedule['created_at'], 'M d, Y')) ?></td>
                            <td class="text-end text-nowrap">
                                <?php if (in_array($status, ['scheduled', 'failed'], true) && !empty($schedule['draft_email_id'])): ?>
                                    <a href="<?= app_base_url('public/compose.php?draft_id=' . (int)$schedule['draft_email_id']) ?>" class="btn btn-sm btn-outline-secondary" title="Edit draft">
                                        <i class="bi bi-pencil"></i>
                                    </a>
                                <?php elseif ($status === 'sent' && !empty($schedule['sent_email_id'])): ?>
                                    <a href="<?= app_base_url('public/read.php?id=' . (int)$schedule['sent_email_id']) ?>" class="btn btn-sm btn-outline-secondary" title="Open sent message">
                                        <i class="bi bi-envelope-open"></i>
                                    </a>
                                <?php endif; ?>

                                <?php if ($status === 'scheduled'): ?>
                                    <form method="POST" action="<?= app_base_url('public/scheduled.php') ?>" class="d-inline" data-confirm="Cancel this scheduled message? The draft will remain in Drafts.">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="cancel">
                                        <input type="hidden" name="schedule_id" value="<?= (int)$schedule['id'] ?>">
                                        <button type="submit" class="btn btn-sm btn-outline-danger" title="Cancel">
                                            <i class="bi bi-x-lg"></i>
                                        </button>
                                    </form>
                                <?php elseif (in_array($status, ['failed', 'cancelled', 'sent'], true)): ?>
                                    <form method="POST" action="<?= app_base_url('public/scheduled.php') ?>" class="d-inline" data-confirm="Remove this schedule entry?">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="dismiss">
                                        <input type="hidden" name="schedule_id" value="<?= (int)$schedule['id'] ?>">
                                        <button type="submit" class="btn btn-sm btn-outline-secondary" title="Remove entry">
                                            <i class="bi bi-trash3"></i>
                                        </button>
                                    </form>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<p class="text-muted small mt-3 mb-0">
    <i class="bi bi-info-circle me-1"></i>
    Scheduled delivery runs automatically. Administrators can also run <code>php bin/process_scheduled.php</code> every minute.
</p>

<?php require_once dirname(__DIR__) . '/includes/footer.php'; ?>
