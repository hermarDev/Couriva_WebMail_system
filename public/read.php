<?php
/**
 * Couriva - Read Email Controller & View
 * Displays a message and provides reply, organization, block, and report tools.
 */

define('WEBMAIL_INIT', true);

require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/database.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/app/Models/Email.php';
require_once dirname(__DIR__) . '/app/Models/Attachment.php';
require_once dirname(__DIR__) . '/app/Services/AttachmentService.php';
require_once dirname(__DIR__) . '/app/Services/MailService.php';
require_once dirname(__DIR__) . '/app/Services/OrganizationService.php';
require_once dirname(__DIR__) . '/app/Services/SpamService.php';

require_auth();

$emailId = (int)($_GET['id'] ?? 0);
$userId = (int)$_SESSION['user_id'];

if ($emailId <= 0) {
    set_flash('danger', 'Invalid email requested.');
    header('Location: ' . app_base_url('public/inbox.php'));
    exit;
}

// Fetch email and verify ownership (user must be recipient or sender of sent/draft email)
$stmt = $pdo->prepare("
    SELECT e.*,
           COALESCE(s.first_name, SUBSTRING_INDEX(e.sender_name_snapshot, ' ', 1)) AS sender_first_name,
           COALESCE(s.last_name, SUBSTRING_INDEX(e.sender_name_snapshot, ' ', -1)) AS sender_last_name,
           COALESCE(s.email, e.sender_email_snapshot) AS sender_email,
           r.first_name AS recipient_first_name, r.last_name AS recipient_last_name, r.email AS recipient_email
    FROM emails e
    LEFT JOIN users s ON e.sender_id = s.id
    LEFT JOIN users r ON e.recipient_id = r.id
    WHERE e.id = ? AND (e.recipient_id = ? OR (e.sender_id = ? AND e.recipient_id IS NULL))
    LIMIT 1
");
$stmt->execute([$emailId, $userId, $userId]);
$email = $stmt->fetch();

if (!$email) {
    set_flash('danger', 'Email message not found or access denied.');
    header('Location: ' . app_base_url('public/inbox.php'));
    exit;
}

$mailService = new \App\Services\MailService($pdo);
$organizationService = new \App\Services\OrganizationService($pdo);
$spamService = new \App\Services\SpamService($pdo);

$isSenderSelf = (int)($email['sender_id'] ?? 0) === $userId;
$senderEmail = strtolower(trim((string)($email['sender_email'] ?? '')));

// Handle organization / safety actions before rendering.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $redirectTo = app_base_url('public/read.php?id=' . $emailId);

    if (!verify_csrf_token()) {
        set_flash('danger', 'Security token expired. Please try again.');
    } else {
        $action = $_POST['action'] ?? '';

        try {
            switch ($action) {
                case 'archive':
                    $count = $mailService->batchArchive($userId, [$emailId], true);
                    set_flash($count > 0 ? 'success' : 'warning', $count > 0 ? 'Message archived.' : 'Message could not be archived.');
                    $redirectTo = app_base_url('public/inbox.php');
                    break;

                case 'unarchive':
                    $count = $mailService->batchArchive($userId, [$emailId], false);
                    set_flash($count > 0 ? 'success' : 'warning', $count > 0 ? 'Message moved back to Inbox.' : 'Message could not be moved.');
                    $redirectTo = app_base_url('public/inbox.php');
                    break;

                case 'snooze':
                    $until = trim($_POST['scheduled_at'] ?? '');
                    $count = $mailService->batchSnooze($userId, [$emailId], $until);
                    set_flash($count > 0 ? 'success' : 'warning', $count > 0
                        ? 'Message snoozed until ' . format_user_datetime(date('Y-m-d H:i:s', strtotime($until))) . '.'
                        : 'Message could not be snoozed.');
                    $redirectTo = app_base_url('public/inbox.php');
                    break;

                case 'unsnooze':
                    $count = $mailService->batchUnsnooze($userId, [$emailId]);
                    set_flash($count > 0 ? 'success' : 'warning', $count > 0 ? 'Message returned to Inbox.' : 'Message is not snoozed.');
                    $redirectTo = app_base_url('public/inbox.php');
                    break;

                case 'add_label':
                    $labelId = (int)($_POST['label_id'] ?? 0);
                    $organizationService->assignLabel($userId, [$emailId], $labelId);
                    set_flash('success', 'Label applied.');
                    break;

                case 'remove_label':
                    $labelId = (int)($_POST['label_id'] ?? 0);
                    $organizationService->removeLabel($userId, [$emailId], $labelId);
                    set_flash('success', 'Label removed.');
                    break;

                case 'move_folder':
                    $folderId = (int)($_POST['folder_id'] ?? 0);
                    $organizationService->moveToFolder($userId, [$emailId], $folderId > 0 ? $folderId : null);
                    set_flash('success', 'Message moved.');
                    break;

                case 'block_sender':
                    if ($isSenderSelf || $senderEmail === '') {
                        set_flash('warning', 'You cannot block your own address.');
                    } else {
                        $spamService->blockSender($userId, $senderEmail);
                        set_flash('success', 'Future messages from ' . $senderEmail . ' will go to Spam.');
                    }
                    break;

                case 'unblock_sender':
                    $spamService->unblockSenderByEmail($userId, $senderEmail);
                    set_flash('success', 'Sender unblocked.');
                    break;

                case 'report_spam':
                    $reason = trim($_POST['reason'] ?? '');
                    $spamService->report($userId, $emailId, $reason);
                    set_flash('success', 'Message reported. An administrator will review it.');
                    $redirectTo = app_base_url('public/spam.php');
                    break;

                case 'mark_unread':
                    $stmtUnread = $pdo->prepare("UPDATE emails SET is_read = 0, updated_at = NOW() WHERE id = ? AND recipient_id = ?");
                    $stmtUnread->execute([$emailId, $userId]);
                    set_flash('success', 'Message marked as unread.');
                    $redirectTo = app_base_url('public/inbox.php');
                    break;
            }
        } catch (InvalidArgumentException $e) {
            set_flash('danger', $e->getMessage());
        } catch (Throwable $e) {
            error_log('Read page action failed: ' . $e->getMessage());
            set_flash('danger', 'That action could not be completed.');
        }
    }

    header('Location: ' . $redirectTo);
    exit;
}

// Mark as read if user is the recipient
if ((int)$email['recipient_id'] === $userId && !$email['is_read']) {
    $updateStmt = $pdo->prepare("UPDATE emails SET is_read = 1 WHERE id = ?");
    $updateStmt->execute([$emailId]);
    $email['is_read'] = 1;
}

// Fetch recipients
$recipientsStmt = $pdo->prepare("SELECT * FROM email_recipients WHERE email_id = ? ORDER BY recipient_type ASC");
$recipientsStmt->execute([$emailId]);
$recipients = $recipientsStmt->fetchAll();

// Fetch attachments
$attStmt = $pdo->prepare("SELECT * FROM attachments WHERE email_id = ?");
$attStmt->execute([$emailId]);
$attachments = $attStmt->fetchAll();

$threadMessages = $mailService->getConversationThread($emailId, $userId);
if (empty($threadMessages)) {
    $email['attachments'] = $attachments;
    $threadMessages = [$email];
}

$emailLabels = $organizationService->getLabelsForEmail($emailId, $userId);
$availableLabels = $organizationService->getLabels($userId);
$customFolders = $organizationService->getFolders($userId);
$emailLabelIds = array_map(static fn (array $label): int => (int)$label['id'], $emailLabels);

$blockedStmt = $pdo->prepare("SELECT id FROM blocked_senders WHERE user_id = ? AND sender_email = ? LIMIT 1");
$blockedStmt->execute([$userId, $senderEmail]);
$blockedId = (int)$blockedStmt->fetchColumn();

$snoozeOptions = [
    'In 4 hours' => date('Y-m-d H:i:s', time() + 4 * 3600),
    'Tomorrow at 8:00 AM' => (new DateTimeImmutable('tomorrow 08:00', new DateTimeZone(user_timezone())))
        ->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s'),
    'Next week' => (new DateTimeImmutable('+7 days', new DateTimeZone(user_timezone())))
        ->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s'),
];

$pageTitle = $email['subject'] ?: '(No Subject)';
require_once dirname(__DIR__) . '/includes/header.php';
?>

<div class="mb-3 d-flex flex-wrap justify-content-between align-items-center gap-2">
    <div class="d-flex align-items-center gap-2">
        <a href="<?= app_base_url('public/inbox.php') ?>" class="btn btn-light border btn-sm d-flex align-items-center gap-1 shadow-sm">
            <i class="bi bi-arrow-left"></i>
            <span>Back to Mailbox</span>
        </a>
        <?php if (!empty($emailLabels)): ?>
            <div class="d-none d-md-flex flex-wrap gap-1">
                <?php foreach ($emailLabels as $label): ?>
                    <span class="label-chip"><span class="label-dot" style="background-color: <?= e($label['color']) ?>;"></span><?= e($label['name']) ?></span>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

    <!-- Message Actions Toolbar -->
    <div class="d-flex flex-wrap gap-2 align-items-center">
        <!-- Star Toggle -->
        <button type="button" class="btn btn-light border btn-sm star-toggle-btn <?= $email['is_starred'] ? 'starred text-warning' : 'text-secondary' ?>" data-email-id="<?= $email['id'] ?>" data-starred="<?= $email['is_starred'] ? '1' : '0' ?>" title="<?= $email['is_starred'] ? 'Unstar message' : 'Star message' ?>">
            <i class="bi <?= $email['is_starred'] ? 'bi-star-fill text-warning' : 'bi-star' ?>"></i>
        </button>

        <!-- Archive / Unarchive -->
        <form method="POST" action="<?= app_base_url('public/read.php?id=' . $emailId) ?>" class="d-inline">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="<?= !empty($email['is_archived']) ? 'unarchive' : 'archive' ?>">
            <button type="submit" class="btn btn-light border btn-sm d-flex align-items-center gap-1" title="<?= !empty($email['is_archived']) ? 'Move back to Inbox' : 'Archive message' ?>">
                <i class="bi <?= !empty($email['is_archived']) ? 'bi-inbox' : 'bi-archive' ?>"></i>
                <span class="d-none d-sm-inline"><?= !empty($email['is_archived']) ? 'Unarchive' : 'Archive' ?></span>
            </button>
        </form>

        <!-- Snooze -->
        <?php if (!empty($email['snoozed_until']) && strtotime($email['snoozed_until']) > time()): ?>
            <form method="POST" action="<?= app_base_url('public/read.php?id=' . $emailId) ?>" class="d-inline">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="unsnooze">
                <button type="submit" class="btn btn-outline-primary btn-sm d-flex align-items-center gap-1" title="Return to Inbox now">
                    <i class="bi bi-alarm-fill"></i><span class="d-none d-sm-inline">Unsnooze</span>
                </button>
            </form>
        <?php else: ?>
            <div class="dropdown d-inline-block">
                <button class="btn btn-light border btn-sm dropdown-toggle d-flex align-items-center gap-1" type="button" data-bs-toggle="dropdown" aria-expanded="false" title="Snooze message">
                    <i class="bi bi-alarm"></i><span class="d-none d-sm-inline">Snooze</span>
                </button>
                <ul class="dropdown-menu shadow-sm">
                    <?php foreach ($snoozeOptions as $label => $utcValue): ?>
                        <li>
                            <form method="POST" action="<?= app_base_url('public/read.php?id=' . $emailId) ?>">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="snooze">
                                <input type="hidden" name="scheduled_at" value="<?= e($utcValue) ?>">
                                <button type="submit" class="dropdown-item"><?= e($label) ?></button>
                            </form>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <a href="<?= app_base_url('public/compose.php?reply_to=' . $email['id']) ?>" class="btn btn-outline-primary btn-sm d-flex align-items-center gap-1">
            <i class="bi bi-reply-fill"></i>
            <span>Reply</span>
        </a>
        <a href="<?= app_base_url('public/compose.php?reply_all=' . $email['id']) ?>" class="btn btn-outline-secondary btn-sm d-flex align-items-center gap-1">
            <i class="bi bi-reply-all-fill"></i>
            <span class="d-none d-lg-inline">Reply All</span>
        </a>
        <a href="<?= app_base_url('public/compose.php?forward=' . $email['id']) ?>" class="btn btn-outline-secondary btn-sm d-flex align-items-center gap-1">
            <i class="bi bi-forward-fill"></i>
            <span class="d-none d-lg-inline">Forward</span>
        </a>

        <!-- Print Button -->
        <button type="button" class="btn btn-outline-secondary btn-sm d-flex align-items-center gap-1" data-action="print-email" title="Print email">
            <i class="bi bi-printer"></i>
            <span class="d-none d-sm-inline">Print</span>
        </button>

        <!-- Move to Trash -->
        <form method="POST" action="<?= app_base_url('public/trash.php') ?>" class="d-inline" data-confirm="Move this email to trash?">
            <?= csrf_field() ?>
            <input type="hidden" name="email_id" value="<?= $email['id'] ?>">
            <button type="submit" class="btn btn-outline-danger btn-sm d-flex align-items-center gap-1" title="Move to Trash">
                <i class="bi bi-trash-fill"></i>
                <span class="d-none d-sm-inline">Trash</span>
            </button>
        </form>
    </div>
</div>

<!-- Organize / Safety Panel -->
<div class="card shadow-sm border-0 mb-3">
    <div class="card-header bg-body py-2 border-bottom d-flex justify-content-between align-items-center">
        <button class="btn btn-sm btn-link text-decoration-none text-body fw-semibold p-0" type="button" data-bs-toggle="collapse" data-bs-target="#organizePanel" aria-expanded="false" aria-controls="organizePanel">
            <i class="bi bi-tags me-1 text-primary"></i>Organize &amp; safety
        </button>
        <div class="d-flex gap-2">
            <?php if (!$isSenderSelf && $senderEmail !== ''): ?>
                <?php if ($blockedId > 0): ?>
                    <form method="POST" action="<?= app_base_url('public/read.php?id=' . $emailId) ?>" class="d-inline">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="unblock_sender">
                        <button type="submit" class="btn btn-sm btn-outline-secondary"><i class="bi bi-person-check me-1"></i>Unblock sender</button>
                    </form>
                <?php else: ?>
                    <form method="POST" action="<?= app_base_url('public/read.php?id=' . $emailId) ?>" class="d-inline" data-confirm="Block <?= e($senderEmail) ?>? Future messages will go to Spam.">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="block_sender">
                        <button type="submit" class="btn btn-sm btn-outline-secondary"><i class="bi bi-slash-circle me-1"></i>Block sender</button>
                    </form>
                <?php endif; ?>
                <button type="button" class="btn btn-sm btn-outline-danger" data-bs-toggle="modal" data-bs-target="#reportSpamModal">
                    <i class="bi bi-shield-exclamation me-1"></i>Report spam
                </button>
            <?php endif; ?>
        </div>
    </div>
    <div class="collapse" id="organizePanel">
        <div class="card-body p-4">
            <div class="row g-4">
                <div class="col-lg-5">
                    <h6 class="fw-bold mb-2"><i class="bi bi-tag text-primary me-1"></i>Labels</h6>
                    <?php if (empty($availableLabels)): ?>
                        <p class="small text-muted mb-2">No labels yet. Create one in Settings.</p>
                    <?php else: ?>
                        <div class="d-flex flex-wrap gap-2 mb-3">
                            <?php foreach ($emailLabels as $label): ?>
                                <form method="POST" action="<?= app_base_url('public/read.php?id=' . $emailId) ?>" class="d-inline">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="remove_label">
                                    <input type="hidden" name="label_id" value="<?= (int)$label['id'] ?>">
                                    <button type="submit" class="label-chip label-chip-remove" title="Remove label">
                                        <span class="label-dot" style="background-color: <?= e($label['color']) ?>;"></span><?= e($label['name']) ?><i class="bi bi-x ms-1"></i>
                                    </button>
                                </form>
                            <?php endforeach; ?>
                        </div>
                        <?php $remainingLabels = array_filter($availableLabels, static fn (array $label): bool => !in_array((int)$label['id'], $emailLabelIds, true)); ?>
                        <?php if (!empty($remainingLabels)): ?>
                            <form method="POST" action="<?= app_base_url('public/read.php?id=' . $emailId) ?>" class="d-flex gap-2">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="add_label">
                                <select name="label_id" class="form-select form-select-sm" aria-label="Choose label">
                                    <?php foreach ($remainingLabels as $label): ?>
                                        <option value="<?= (int)$label['id'] ?>"><?= e($label['name']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <button type="submit" class="btn btn-sm btn-outline-primary text-nowrap">Apply label</button>
                            </form>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>

                <div class="col-lg-4">
                    <h6 class="fw-bold mb-2"><i class="bi bi-folder2 text-primary me-1"></i>Folder</h6>
                    <?php if (empty($customFolders)): ?>
                        <p class="small text-muted mb-0">No custom folders yet. Create one in Settings.</p>
                    <?php else: ?>
                        <form method="POST" action="<?= app_base_url('public/read.php?id=' . $emailId) ?>" class="d-flex gap-2">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="move_folder">
                            <select name="folder_id" class="form-select form-select-sm" aria-label="Choose folder">
                                <option value="0">No custom folder</option>
                                <?php foreach ($customFolders as $folder): ?>
                                    <option value="<?= (int)$folder['id'] ?>" <?= (int)($email['custom_folder_id'] ?? 0) === (int)$folder['id'] ? 'selected' : '' ?>><?= e($folder['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <button type="submit" class="btn btn-sm btn-outline-primary text-nowrap">Move</button>
                        </form>
                        <div class="form-text">Custom folders keep a message out of Inbox without deleting it.</div>
                    <?php endif; ?>
                </div>

                <div class="col-lg-3">
                    <h6 class="fw-bold mb-2"><i class="bi bi-shield-check text-primary me-1"></i>Sender</h6>
                    <div class="small text-muted mb-2 text-break"><?= e($senderEmail) ?></div>
                    <?php if ($blockedId > 0): ?>
                        <span class="badge bg-danger-subtle text-danger border border-danger-subtle">Blocked</span>
                    <?php elseif (!$isSenderSelf): ?>
                        <span class="badge bg-body-secondary text-secondary border">Allowed</span>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Conversation Subject Title Header -->
<div class="d-flex justify-content-between align-items-center mb-3">
    <div class="d-flex align-items-center gap-2">
        <h4 class="fw-bold mb-0 text-body"><?= e($email['subject']) ?: '(No Subject)' ?></h4>
        <span class="badge bg-secondary-subtle text-secondary border border-secondary-subtle text-uppercase">
            <?= e(!empty($email['is_archived']) ? 'archived' : $email['folder']) ?>
        </span>
        <?php if (count($threadMessages) > 1): ?>
            <span class="badge rounded-pill bg-primary-subtle text-primary border border-primary-subtle">
                <?= count($threadMessages) ?> messages
            </span>
        <?php endif; ?>
    </div>
</div>

<!-- Gmail Conversation Thread Stack -->
<div class="thread-container mb-4">
    <?php
    $lastIndex = count($threadMessages) - 1;
    foreach ($threadMessages as $index => $msg):
        $isCurrent = (int)$msg['id'] === $emailId;
        $isLast = ($index === $lastIndex);
        $isCollapsed = (!$isCurrent && !$isLast && count($threadMessages) > 1);
        $msgAttachments = $msg['attachments'] ?? [];
        $allowRemoteImages = isset($_GET['images']) && $_GET['images'] === '1';
        $sanitizedBody = sanitize_email_html($msg['body'], $allowRemoteImages);
        $hasBlockedImages = str_contains($sanitizedBody, 'data-blocked-src=');
        $emailBodyDocument = '<!doctype html><html><head><meta charset="utf-8"><base target="_blank"><style>'
            . 'html,body{margin:0;padding:0;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,"Helvetica Neue",Arial,sans-serif;font-size:15px;line-height:1.6;background:transparent;color:inherit;overflow-wrap:anywhere}'
            . '@media (prefers-color-scheme: dark){html,body{color:#f8fafc;background:#111827}a{color:#60a5fa}}'
            . 'img{max-width:100%;height:auto}table{max-width:100%;border-collapse:collapse}'
            . 'a{color:#0d6efd}'
            . '</style></head><body>' . $sanitizedBody . '</body></html>';
        $senderFullName = trim(($msg['sender_first_name'] ?? '') . ' ' . ($msg['sender_last_name'] ?? '')) ?: ($msg['sender_email'] ?? 'Unknown');
        $avatarLetter = strtoupper(substr($msg['sender_first_name'] ?: ($msg['sender_email'] ?? 'U'), 0, 1));
        $snippet = mb_strimwidth(trim(strip_tags($msg['body'])), 0, 110, '...');
    ?>
    <div class="card shadow-sm border mb-3 thread-message-card email-reader-card <?= $isCollapsed ? 'collapsed' : '' ?>" id="message-<?= (int)$msg['id'] ?>" data-message-id="<?= (int)$msg['id'] ?>">
        <!-- Thread Item Header -->
        <div class="card-header bg-body p-3 thread-card-header d-flex align-items-center justify-content-between gap-2" role="button" title="Click to expand/collapse message">
            <div class="d-flex align-items-center gap-3 text-truncate flex-grow-1">
                <div class="avatar-circle bg-primary text-white d-flex align-items-center justify-content-center rounded-circle fw-bold shadow-sm flex-shrink-0" style="width: 38px; height: 38px; font-size: 1rem;">
                    <?= $avatarLetter ?>
                </div>
                <div class="text-truncate">
                    <span class="fw-bold text-body fs-6 me-2"><?= e($senderFullName) ?></span>
                    <span class="thread-snippet text-muted d-none d-md-inline"><?= e($snippet) ?></span>
                </div>
            </div>
            <div class="d-flex align-items-center gap-2 flex-shrink-0 text-muted small">
                <span><?= date('M d, Y h:i A', strtotime($msg['created_at'])) ?></span>
                <?php if (!empty($msgAttachments)): ?>
                    <i class="bi bi-paperclip" title="<?= count($msgAttachments) ?> attachment(s)"></i>
                <?php endif; ?>
                <i class="bi bi-chevron-down thread-expand-icon"></i>
            </div>
        </div>

        <!-- Thread Item Body -->
        <div class="thread-card-body">
            <div class="px-4 py-2 border-bottom bg-body-subtle text-muted small d-flex flex-wrap align-items-center gap-2">
                <span><strong>From:</strong> <?= e($senderFullName) ?> &lt;<?= e($msg['sender_email']) ?>&gt;</span>
                <?php if (!empty($msg['recipient_email'])): ?>
                    <span><strong>To:</strong> <?= e($msg['recipient_email']) ?></span>
                <?php endif; ?>
            </div>

            <?php if ($hasBlockedImages): ?>
                <div class="card-body border-bottom bg-body-tertiary d-flex flex-wrap align-items-center justify-content-between gap-2 py-2 px-4">
                    <div class="small text-muted">
                        <i class="bi bi-shield-lock me-1"></i>
                        Remote images are blocked to protect your privacy.
                    </div>
                    <a href="<?= app_base_url('public/read.php?id=' . (int)$msg['id'] . '&images=1') ?>" class="btn btn-sm btn-outline-primary py-0">
                        <i class="bi bi-image me-1"></i> Load images
                    </a>
                </div>
            <?php endif; ?>

            <iframe
                class="card-body p-4 email-body-content border-0 w-100"
                title="Email message"
                sandbox="allow-popups allow-popups-to-escape-sandbox"
                referrerpolicy="no-referrer"
                srcdoc="<?= e($emailBodyDocument) ?>"
                style="min-height: 140px; height: 30vh; font-size: 1.05rem; line-height: 1.65;"></iframe>

            <!-- Attachments Section -->
            <?php if (!empty($msgAttachments)): ?>
                <div class="card-footer bg-body-tertiary p-3 border-top">
                    <h6 class="fw-bold mb-2 d-flex align-items-center gap-2 small">
                        <i class="bi bi-paperclip text-primary fs-6"></i>
                        <span>Attachments (<?= count($msgAttachments) ?>)</span>
                    </h6>
                    <div class="row g-2">
                        <?php foreach ($msgAttachments as $att): ?>
                            <div class="col-sm-6 col-md-4 col-lg-3">
                                <div class="card h-100 border shadow-sm attachment-card p-2 d-flex flex-row align-items-center gap-2">
                                    <i class="<?= get_attachment_icon_class($att['mime_type'], $att['original_filename']) ?> fs-2"></i>
                                    <div class="flex-grow-1 text-truncate">
                                        <div class="fw-semibold text-truncate small" title="<?= e($att['original_filename']) ?>">
                                            <?= e($att['original_filename']) ?>
                                        </div>
                                        <div class="text-muted small" style="font-size: 0.75rem;">
                                            <?= format_bytes((int)$att['filesize']) ?>
                                        </div>
                                    </div>
                                    <a href="<?= app_base_url('public/download.php?id=' . $att['id']) ?>" class="btn btn-sm btn-outline-primary py-1 px-2" title="Download <?= e($att['original_filename']) ?>">
                                        <i class="bi bi-download"></i>
                                    </a>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>
    <?php endforeach; ?>
</div>

<!-- Gmail-Style Inline Quick Reply Container -->
<div id="inlineReplyContainer" class="inline-reply-box card shadow-sm border p-3 mt-4">
    <div class="d-flex align-items-center gap-2" id="inlineReplyPrompt">
        <a href="<?= app_base_url('public/compose.php?reply_to=' . $emailId) ?>" class="reply-trigger-pill text-decoration-none">
            <i class="bi bi-reply-fill"></i>
            <span>Reply</span>
        </a>
        <a href="<?= app_base_url('public/compose.php?reply_to=' . $emailId . '&reply_all=1') ?>" class="reply-trigger-pill text-decoration-none">
            <i class="bi bi-reply-all-fill"></i>
            <span>Reply all</span>
        </a>
        <a href="<?= app_base_url('public/compose.php?forward=' . $emailId) ?>" class="reply-trigger-pill text-decoration-none">
            <i class="bi bi-forward-fill"></i>
            <span>Forward</span>
        </a>
    </div>
</div>

<!-- Report Spam Modal -->
<?php if (!$isSenderSelf && $senderEmail !== ''): ?>
    <div class="modal fade" id="reportSpamModal" tabindex="-1" aria-labelledby="reportSpamModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="POST" action="<?= app_base_url('public/read.php?id=' . $emailId) ?>">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="report_spam">
                    <div class="modal-header">
                        <h5 class="modal-title" id="reportSpamModalLabel">Report this message as spam</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <p class="small text-muted">The message moves to Spam and an administrator reviews the report. The sender may be blocked for your mailbox.</p>
                        <label for="reportReason" class="form-label">Reason (optional)</label>
                        <textarea class="form-control" id="reportReason" name="reason" rows="3" maxlength="500" placeholder="Why is this message spam?"></textarea>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-danger"><i class="bi bi-shield-exclamation me-1"></i>Report spam</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
<?php endif; ?>

<?php require_once dirname(__DIR__) . '/includes/footer.php'; ?>
