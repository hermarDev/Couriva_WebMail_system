<?php
/**
 * Couriva - Trash Folder View & Actions
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
$activeFolder = 'trash';
$pageTitle = 'Trash';

// Handle POST actions (Move to trash, restore, empty trash, permanent delete)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (verify_csrf_token()) {
        $action = $_POST['action'] ?? 'trash';
        $emailId = (int)($_POST['email_id'] ?? 0);

        if ($action === 'empty_trash') {
            $stmt = $pdo->prepare("DELETE FROM emails WHERE (recipient_id = ? OR (sender_id = ? AND recipient_id IS NULL)) AND (folder = 'trash' OR deleted_at IS NOT NULL)");
            $stmt->execute([$userId, $userId]);
            set_flash('success', 'Trash has been emptied.');
        } elseif ($action === 'restore' && $emailId > 0) {
            $stmt = $pdo->prepare("UPDATE emails SET folder = 'inbox', deleted_at = NULL WHERE id = ? AND (recipient_id = ? OR (sender_id = ? AND recipient_id IS NULL))");
            $stmt->execute([$emailId, $userId, $userId]);
            set_flash('success', 'Email restored to Inbox.');
        } elseif ($action === 'permanent_delete' && $emailId > 0) {
            $stmt = $pdo->prepare("DELETE FROM emails WHERE id = ? AND (recipient_id = ? OR (sender_id = ? AND recipient_id IS NULL))");
            $stmt->execute([$emailId, $userId, $userId]);
            set_flash('success', 'Email permanently deleted.');
        } elseif ($emailId > 0) {
            // Default soft delete / move to trash
            $stmt = $pdo->prepare("UPDATE emails SET folder = 'trash', deleted_at = NOW() WHERE id = ? AND (recipient_id = ? OR (sender_id = ? AND recipient_id IS NULL))");
            $stmt->execute([$emailId, $userId, $userId]);
            set_flash('info', 'Message moved to Trash.');
        }
    }
    header('Location: ' . app_base_url('public/trash.php'));
    exit;
}

$mailService = new \App\Services\MailService($pdo);
$searchResult = $mailService->searchEmails($userId, 'trash', ['q' => trim($_GET['q'] ?? '')], 1, 100);
$trashEmails = $searchResult['emails'];

require_once dirname(__DIR__) . '/includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <div>
        <h4 class="mb-0 fw-bold"><i class="bi bi-trash-fill text-muted me-2"></i>Trash</h4>
        <span class="text-muted small"><?= count($trashEmails) ?> message(s) in trash</span>
    </div>

    <div class="d-flex gap-2">
        <?php if (!empty($trashEmails)): ?>
            <form method="POST" action="<?= app_base_url('public/trash.php') ?>" data-confirm="Empty all items from Trash? This cannot be undone.">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="empty_trash">
                <button type="submit" class="btn btn-outline-danger btn-sm">
                    <i class="bi bi-trash3 me-1"></i> Empty Trash
                </button>
            </form>
        <?php endif; ?>
        <a href="<?= app_base_url('public/trash.php') ?>" class="btn btn-light border btn-sm" title="Refresh">
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
                <button type="button" class="btn btn-outline-success btn-sm batch-action-btn" data-action="restore" title="Restore to Inbox">
                    <i class="bi bi-arrow-counterclockwise"></i> <span class="d-none d-sm-inline ms-1">Restore</span>
                </button>
                <button type="button" class="btn btn-outline-danger btn-sm batch-action-btn" data-action="permanent_delete" title="Delete Permanently">
                    <i class="bi bi-x-lg"></i> <span class="d-none d-sm-inline ms-1">Delete Permanently</span>
                </button>
            </div>
        </div>

        <form method="GET" action="<?= app_base_url('public/trash.php') ?>" class="d-flex gap-1" style="max-width: 280px;">
            <input type="text" name="q" class="form-control form-control-sm" placeholder="Search trash..." value="<?= e($_GET['q'] ?? '') ?>">
            <button type="submit" class="btn btn-sm btn-outline-secondary"><i class="bi bi-search"></i></button>
        </form>
    </div>

    <div class="mail-list-container">
        <?php if (empty($trashEmails)): ?>
            <div class="empty-state-container">
                <i class="bi bi-trash2 empty-state-icon"></i>
                <div class="empty-state-title">Trash is empty</div>
                <div class="empty-state-subtitle">Messages moved to trash will appear here.</div>
            </div>
        <?php else: ?>
            <?php foreach ($trashEmails as $mail): ?>
                <div class="mail-item-row read" data-email-id="<?= $mail['id'] ?>">
                    <div class="form-check m-0 me-2" data-stop-propagation>
                        <input class="form-check-input email-select-checkbox" type="checkbox" value="<?= $mail['id'] ?>">
                    </div>

                    <span class="mail-sender text-muted">
                        <?= e(trim(($mail['sender_first_name'] ?? '') . ' ' . ($mail['sender_last_name'] ?? ''))) ?: e($mail['sender_email']) ?>
                    </span>

                    <span class="mail-subject-snippet text-muted">
                        <span class="mail-subject text-decoration-line-through"><?= e($mail['subject']) ?: '(No Subject)' ?></span>
                        <span class="mail-snippet">&mdash; <?= e(mb_strimwidth(strip_tags($mail['body'] ?? ''), 0, 85, '...')) ?></span>
                    </span>

                    <div class="mail-date me-2">
                        <?= date('M d', strtotime($mail['created_at'])) ?>
                    </div>

                    <!-- Quick Hover Actions -->
                    <div class="mail-quick-actions" data-stop-propagation>
                        <button type="button" class="btn btn-sm btn-light border-0 quick-action-btn quick-restore-btn text-success" data-email-id="<?= $mail['id'] ?>" title="Restore to Inbox">
                            <i class="bi bi-arrow-counterclockwise"></i>
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
