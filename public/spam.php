<?php
/**
 * Couriva - Spam Folder View
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

require_auth();

$userId = (int)$_SESSION['user_id'];
$activeFolder = 'spam';
$pageTitle = 'Spam Messages';

$mailService = new \App\Services\MailService($pdo);
$searchResult = $mailService->searchEmails($userId, 'spam', ['q' => trim($_GET['q'] ?? '')], 1, 100);
$spamEmails = $searchResult['emails'];

require_once dirname(__DIR__) . '/includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <div>
        <h4 class="mb-0 fw-bold"><i class="bi bi-exclamation-octagon-fill text-danger me-2"></i>Spam</h4>
        <span class="text-muted small"><?= count($spamEmails) ?> junk / suspicious message(s)</span>
    </div>

    <div class="d-flex gap-2">
        <a href="<?= app_base_url('public/spam.php') ?>" class="btn btn-light border btn-sm" title="Refresh">
            <i class="bi bi-arrow-clockwise"></i>
        </a>
    </div>
</div>

<div class="card shadow-sm border-0">
    <!-- Toolbar -->
    <div class="card-header bg-body py-2 d-flex align-items-center justify-content-between border-bottom">
        <div class="d-flex align-items-center gap-2">
            <div class="form-check m-0">
                <input class="form-check-input" type="checkbox" id="selectAllEmails" title="Select all">
            </div>
            
            <div id="batchActionsToolbar" style="display: none;" class="align-items-center gap-2">
                <span class="badge bg-primary rounded-pill me-1" id="selectedCountBadge">0</span>
                <button type="button" class="btn btn-outline-success btn-sm batch-action-btn" data-action="restore" title="Not Spam (Move to Inbox)">
                    <i class="bi bi-shield-check"></i> <span class="d-none d-sm-inline ms-1">Not Spam</span>
                </button>
                <button type="button" class="btn btn-outline-danger btn-sm batch-action-btn" data-action="permanent_delete" title="Delete Permanently">
                    <i class="bi bi-x-lg"></i> <span class="d-none d-sm-inline ms-1">Delete</span>
                </button>
            </div>
        </div>

        <form method="GET" action="<?= app_base_url('public/spam.php') ?>" class="d-flex gap-1" style="max-width: 280px;">
            <input type="text" name="q" class="form-control form-control-sm" placeholder="Search spam..." value="<?= e($_GET['q'] ?? '') ?>">
            <button type="submit" class="btn btn-sm btn-outline-secondary"><i class="bi bi-search"></i></button>
        </form>
    </div>

    <div class="mail-list-container">
        <?php if (empty($spamEmails)): ?>
            <div class="empty-state-container">
                <i class="bi bi-shield-check empty-state-icon text-success"></i>
                <div class="empty-state-title">No spam messages</div>
                <div class="empty-state-subtitle">Your spam folder is clean.</div>
            </div>
        <?php else: ?>
            <?php foreach ($spamEmails as $mail): ?>
                <div class="mail-item-row <?= $mail['is_read'] ? 'read' : 'unread' ?>" data-email-id="<?= $mail['id'] ?>">
                    <div class="form-check m-0 me-2" data-stop-propagation>
                        <input class="form-check-input email-select-checkbox" type="checkbox" value="<?= $mail['id'] ?>">
                    </div>

                    <span class="mail-sender text-danger">
                        <?= e(trim(($mail['sender_first_name'] ?? '') . ' ' . ($mail['sender_last_name'] ?? ''))) ?: e($mail['sender_email']) ?>
                    </span>

                    <a href="<?= app_base_url('public/read.php?id=' . $mail['id']) ?>" class="mail-subject-snippet text-decoration-none">
                        <span class="mail-subject">[SPAM] <?= e($mail['subject']) ?: '(No Subject)' ?></span>
                        <span class="mail-snippet">&mdash; <?= e(mb_strimwidth(strip_tags($mail['body'] ?? ''), 0, 85, '...')) ?></span>
                    </a>

                    <div class="mail-date me-2">
                        <?= date('M d', strtotime($mail['created_at'])) ?>
                    </div>

                    <!-- Quick Hover Actions -->
                    <div class="mail-quick-actions" data-stop-propagation>
                        <button type="button" class="btn btn-sm btn-light border-0 quick-action-btn quick-not-spam-btn text-success" data-email-id="<?= $mail['id'] ?>" title="Not spam (move to Inbox)">
                            <i class="bi bi-shield-check"></i>
                        </button>
                        <button type="button" class="btn btn-sm btn-light border-0 quick-action-btn quick-perm-delete-btn text-danger" data-email-id="<?= $mail['id'] ?>" title="Delete permanently">
                            <i class="bi bi-x-lg"></i>
                        </button>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>

<?php require_once dirname(__DIR__) . '/includes/footer.php'; ?>
