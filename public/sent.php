<?php
/**
 * Couriva - Sent Mail View
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
$activeFolder = 'sent';
$pageTitle = 'Sent Mail';

$perPage = 25;
$page = max(1, (int)($_GET['page'] ?? 1));

$criteria = [
    'q'              => trim($_GET['q'] ?? ''),
    'date_from'      => trim($_GET['date_from'] ?? ''),
    'date_to'        => trim($_GET['date_to'] ?? ''),
    'has_attachment' => $_GET['has_attachment'] ?? '',
];

$mailService = new \App\Services\MailService($pdo);
$searchResult = $mailService->searchEmails($userId, 'sent', $criteria, $page, $perPage);

$totalEmails = $searchResult['total'];
$totalPages = $searchResult['total_pages'];
$emails = $searchResult['emails'];

require_once dirname(__DIR__) . '/includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <div>
        <h4 class="mb-0 fw-bold"><i class="bi bi-send-fill text-primary me-2"></i>Sent Mail</h4>
        <span class="text-muted small">Showing <?= count($emails) ?> of <?= $totalEmails ?> sent messages</span>
    </div>

    <div class="d-flex gap-2 align-items-center">
        <!-- Reading Pane Split View Toggle -->
        <div class="btn-group btn-group-sm" id="viewModeToggleGroup" role="group" aria-label="View Mode">
            <button type="button" class="btn btn-outline-secondary active" id="viewModeListBtn" title="List View Only">
                <i class="bi bi-view-list"></i> <span class="d-none d-md-inline ms-1">List</span>
            </button>
            <button type="button" class="btn btn-outline-secondary" id="viewModeSplitBtn" title="Split Reading Pane">
                <i class="bi bi-layout-split"></i> <span class="d-none d-md-inline ms-1">Split Pane</span>
            </button>
        </div>

        <button class="btn btn-outline-secondary btn-sm" type="button" data-bs-toggle="collapse" data-bs-target="#filterPanel" aria-controls="filterPanel" aria-expanded="<?= (!empty($criteria['date_from']) || !empty($criteria['date_to']) || $criteria['has_attachment'] !== '') ? 'true' : 'false' ?>" title="Advanced Search">
            <i class="bi bi-funnel"></i> <span class="d-none d-sm-inline">Filters</span>
        </button>
        <a href="<?= app_base_url('public/sent.php') ?>" class="btn btn-light border btn-sm" title="Refresh">
            <i class="bi bi-arrow-clockwise"></i>
        </a>
    </div>
</div>

<!-- Advanced Search Filters Panel (Collapsible) -->
<div class="collapse <?= (!empty($criteria['date_from']) || !empty($criteria['date_to']) || $criteria['has_attachment'] !== '') ? 'show' : '' ?> mb-3" id="filterPanel">
    <div class="card card-body bg-body-tertiary border-0 shadow-sm p-3">
        <form method="GET" action="<?= app_base_url('public/sent.php') ?>" class="row g-2 align-items-center">
            <div class="col-sm-6 col-lg-4">
                <label for="sentSearch" class="form-label small mb-1">Search</label>
                <input id="sentSearch" type="text" name="q" class="form-control form-control-sm" placeholder="Search messages" value="<?= e($criteria['q']) ?>">
            </div>
            <div class="col-sm-6 col-lg-4">
                <label for="sentFrom" class="form-label small mb-1">From date</label>
                <input id="sentFrom" type="date" name="date_from" class="form-control form-control-sm" value="<?= e($criteria['date_from']) ?>">
            </div>
            <div class="col-sm-6 col-lg-4">
                <label for="sentTo" class="form-label small mb-1">To date</label>
                <input id="sentTo" type="date" name="date_to" class="form-control form-control-sm" value="<?= e($criteria['date_to']) ?>">
            </div>
            <div class="col-12 d-flex gap-2">
                <button type="submit" class="btn btn-primary btn-sm">Apply filters</button>
                <a href="<?= app_base_url('public/sent.php') ?>" class="btn btn-outline-secondary btn-sm">Clear</a>
            </div>
        </form>
    </div>
</div>

<!-- Main Split / List Layout Container -->
<div class="row g-3" id="mailViewContainer">
    <div class="col-12" id="mailListCol">
        <div class="card shadow-sm border-0">
            <!-- Toolbar -->
            <div class="card-header bg-body py-2 d-flex align-items-center justify-content-between border-bottom position-sticky top-0 z-3">
                <div class="d-flex align-items-center gap-2">
                    <div class="form-check m-0">
                        <input class="form-check-input" type="checkbox" id="selectAllEmails" title="Select all">
                    </div>
                    
                    <div id="batchActionsToolbar" style="display: none;" class="align-items-center gap-2">
                        <span class="badge bg-primary rounded-pill me-1" id="selectedCountBadge">0</span>
                        <button type="button" class="btn btn-outline-secondary btn-sm batch-action-btn" data-action="trash" title="Move to Trash">
                            <i class="bi bi-trash"></i> <span class="d-none d-sm-inline ms-1">Trash</span>
                        </button>
                        <button type="button" class="btn btn-outline-secondary btn-sm batch-action-btn" data-action="star" title="Star Selected">
                            <i class="bi bi-star"></i>
                        </button>
                    </div>
                </div>

                <?php if ($totalPages > 1): ?>
                    <div class="small text-muted">
                        Page <?= $page ?> of <?= $totalPages ?>
                        <div class="btn-group btn-group-sm ms-2">
                            <a href="?<?= e(mail_pagination_query(max(1, $page - 1), $criteria)) ?>" class="btn btn-light border <?= $page <= 1 ? 'disabled' : '' ?>" aria-label="Previous page"><i class="bi bi-chevron-left"></i></a>
                            <a href="?<?= e(mail_pagination_query(min($totalPages, $page + 1), $criteria)) ?>" class="btn btn-light border <?= $page >= $totalPages ? 'disabled' : '' ?>" aria-label="Next page"><i class="bi bi-chevron-right"></i></a>
                        </div>
                    </div>
                <?php endif; ?>
            </div>

            <div class="mail-list-container" id="mailListItems">
                <?php if (empty($emails)): ?>
                    <div class="empty-state-container">
                        <i class="bi bi-send empty-state-icon"></i>
                        <div class="empty-state-title">No sent messages yet</div>
                        <div class="empty-state-subtitle">Messages you send will appear here.</div>
                    </div>
                <?php else: ?>
                    <?php foreach ($emails as $mail): ?>
                        <div class="mail-item-row read" data-email-id="<?= $mail['id'] ?>">
                            <!-- Checkbox -->
                            <div class="form-check m-0 me-2" data-stop-propagation>
                                <input class="form-check-input email-select-checkbox" type="checkbox" value="<?= $mail['id'] ?>">
                            </div>

                            <button type="button" class="star-btn star-toggle-btn me-2 <?= $mail['is_starred'] ? 'starred' : '' ?>" data-email-id="<?= $mail['id'] ?>" data-starred="<?= $mail['is_starred'] ? '1' : '0' ?>" title="<?= $mail['is_starred'] ? 'Unstar' : 'Star' ?>">
                                <i class="bi <?= $mail['is_starred'] ? 'bi-star-fill text-warning' : 'bi-star text-secondary' ?>"></i>
                            </button>

                            <a href="<?= app_base_url('public/read.php?id=' . $mail['id']) ?>" class="mail-sender text-decoration-none">
                                To: <?= e($mail['to_recipients'] ?: 'Unknown') ?>
                            </a>

                            <a href="<?= app_base_url('public/read.php?id=' . $mail['id']) ?>" class="mail-subject-snippet text-decoration-none">
                                <span class="mail-subject"><?= e($mail['subject']) ?: '(No Subject)' ?></span>
                                <span class="mail-snippet">&mdash; <?= e(mb_strimwidth(strip_tags($mail['body'] ?? ''), 0, 85, '...')) ?></span>
                            </a>

                            <?php if (($mail['attachment_count'] ?? 0) > 0): ?>
                                <span class="text-muted me-3" title="<?= $mail['attachment_count'] ?> attachment(s)">
                                    <i class="bi bi-paperclip"></i>
                                </span>
                            <?php endif; ?>

                            <div class="mail-date me-2">
                                <?= date('M d', strtotime($mail['created_at'])) ?>
                            </div>

                            <!-- Quick Action Hover Icons -->
                            <div class="mail-quick-actions" data-stop-propagation>
                                <button type="button" class="btn btn-sm btn-light border-0 quick-action-btn quick-trash-btn text-danger" data-email-id="<?= $mail['id'] ?>" title="Move to trash">
                                    <i class="bi bi-trash"></i>
                                </button>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Reading Pane Column (Split View) -->
    <div class="col-lg-6 d-none" id="readingPaneCol">
        <div class="card shadow-sm border-0 sticky-top reading-pane-card" style="top: 75px; max-height: calc(100vh - 100px); overflow-y: auto;" id="readingPaneCard">
            <div class="card-body p-4 text-center text-muted py-5" id="readingPaneContent">
                <i class="bi bi-envelope-open display-4 d-block mb-3 text-secondary opacity-50"></i>
                <h5 class="fw-semibold">Select an email to read</h5>
                <p class="small text-muted">Click any sent conversation to preview it here.</p>
            </div>
        </div>
    </div>
</div>

<?php require_once dirname(__DIR__) . '/includes/footer.php'; ?>
