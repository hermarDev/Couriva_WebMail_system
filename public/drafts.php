<?php
/**
 * Couriva - Drafts View
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
$activeFolder = 'drafts';
$pageTitle = 'Drafts';

$mailService = new \App\Services\MailService($pdo);
$searchResult = $mailService->searchEmails($userId, 'draft', ['q' => trim($_GET['q'] ?? '')], 1, 100);
$drafts = $searchResult['emails'];

require_once dirname(__DIR__) . '/includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <div>
        <h4 class="mb-0 fw-bold"><i class="bi bi-file-earmark-text-fill text-secondary me-2"></i>Drafts</h4>
        <span class="text-muted small"><?= count($drafts) ?> draft(s) saved</span>
    </div>

    <div class="d-flex gap-2">
        <a href="<?= app_base_url('public/drafts.php') ?>" class="btn btn-light border btn-sm" title="Refresh">
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
                <button type="button" class="btn btn-outline-danger btn-sm batch-action-btn" data-action="permanent_delete" title="Discard Drafts">
                    <i class="bi bi-trash"></i> <span class="d-none d-sm-inline ms-1">Discard Drafts</span>
                </button>
            </div>
        </div>

        <form method="GET" action="<?= app_base_url('public/drafts.php') ?>" class="d-flex gap-1" style="max-width: 280px;">
            <input type="text" name="q" class="form-control form-control-sm" placeholder="Search drafts..." value="<?= e($_GET['q'] ?? '') ?>">
            <button type="submit" class="btn btn-sm btn-outline-secondary"><i class="bi bi-search"></i></button>
        </form>
    </div>

    <div class="mail-list-container">
        <?php if (empty($drafts)): ?>
            <div class="empty-state-container">
                <i class="bi bi-file-earmark-text empty-state-icon"></i>
                <div class="empty-state-title">No drafts</div>
                <div class="empty-state-subtitle">Start composing a message to create one.</div>
            </div>
        <?php else: ?>
            <?php foreach ($drafts as $draft): ?>
                <div class="mail-item-row read" data-email-id="<?= $draft['id'] ?>">
                    <div class="form-check m-0 me-2" data-stop-propagation>
                        <input class="form-check-input email-select-checkbox" type="checkbox" value="<?= $draft['id'] ?>">
                    </div>

                    <a href="<?= app_base_url('public/compose.php?draft_id=' . $draft['id']) ?>" class="mail-sender text-danger fw-semibold text-decoration-none">
                        <i class="bi bi-pencil-fill me-1 small"></i>Draft
                    </a>

                    <a href="<?= app_base_url('public/compose.php?draft_id=' . $draft['id']) ?>" class="mail-subject-snippet text-decoration-none">
                        <span class="mail-subject"><?= e($draft['subject']) ?: '(Untitled Draft)' ?></span>
                        <span class="mail-snippet">&mdash; <?= e(mb_strimwidth(strip_tags($draft['body'] ?? ''), 0, 85, '...')) ?></span>
                    </a>

                    <div class="mail-date me-2">
                        <?= date('M d', strtotime($draft['updated_at'])) ?>
                    </div>

                    <!-- Quick Trash Action -->
                    <div class="mail-quick-actions" data-stop-propagation>
                        <button type="button" class="btn btn-sm btn-light border-0 quick-action-btn quick-trash-btn text-danger" data-email-id="<?= $draft['id'] ?>" title="Discard Draft">
                            <i class="bi bi-trash"></i>
                        </button>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>

<?php require_once dirname(__DIR__) . '/includes/footer.php'; ?>
