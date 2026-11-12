<?php
/**
 * Couriva - Inbox & Folder View Controller
 * Integrates MailService advanced search, pagination, and batch actions
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
require_once dirname(__DIR__) . '/app/Services/OrganizationService.php';

require_auth();

$userId = (int)$_SESSION['user_id'];
$requestedFolder = $_GET['folder'] ?? 'inbox';
$organizationService = new \App\Services\OrganizationService($pdo);
$activeLabelId = (int)($_GET['label'] ?? 0);
$activeCustomFolderId = (int)($_GET['id'] ?? 0);
$activeLabel = null;
$activeCustomFolder = null;

$allowedFolders = ['inbox', 'starred', 'archive', 'snoozed', 'label', 'custom'];
$activeFolder = in_array($requestedFolder, $allowedFolders, true) ? $requestedFolder : 'inbox';

if ($activeFolder === 'label') {
    $activeLabel = $organizationService->findLabel($userId, $activeLabelId);
    if (!$activeLabel) {
        set_flash('danger', 'Label not found.');
        header('Location: ' . app_base_url('public/inbox.php'));
        exit;
    }
}

if ($activeFolder === 'custom') {
    $activeCustomFolder = $organizationService->findFolder($userId, $activeCustomFolderId);
    if (!$activeCustomFolder) {
        set_flash('danger', 'Folder not found.');
        header('Location: ' . app_base_url('public/inbox.php'));
        exit;
    }
}

$pageTitle = match ($activeFolder) {
    'starred' => 'Starred Messages',
    'archive' => 'Archive',
    'snoozed' => 'Snoozed',
    'label' => $activeLabel['name'] ?? 'Label',
    'custom' => $activeCustomFolder['name'] ?? 'Folder',
    default => 'Inbox',
};

// Pagination parameters
$perPage = 25;
$page = max(1, (int)($_GET['page'] ?? 1));

// Advanced search criteria
$criteria = [
    'q'              => trim($_GET['q'] ?? ''),
    'date_from'      => trim($_GET['date_from'] ?? ''),
    'date_to'        => trim($_GET['date_to'] ?? ''),
    'has_attachment' => $_GET['has_attachment'] ?? '',
    'is_unread'      => $_GET['is_unread'] ?? '',
    'is_starred'     => $_GET['is_starred'] ?? '',
    'label_id'       => $activeLabelId,
    'folder_id'      => $activeCustomFolderId,
];

$mailService = new \App\Services\MailService($pdo);
$searchResult = $mailService->searchEmails($userId, $activeFolder, $criteria, $page, $perPage);

$totalEmails = $searchResult['total'];
$totalPages = $searchResult['total_pages'];
$emails = $searchResult['emails'];

require_once dirname(__DIR__) . '/includes/header.php';
?>

<?php
$folderIcon = match ($activeFolder) {
    'starred' => 'bi-star-fill text-warning',
    'archive' => 'bi-archive-fill text-secondary',
    'snoozed' => 'bi-alarm-fill text-primary',
    'label' => 'bi-tag-fill',
    'custom' => 'bi-folder2-open text-primary',
    default => 'bi-inbox-fill text-primary',
};
$folderHeading = match ($activeFolder) {
    'starred' => 'Starred',
    'archive' => 'Archive',
    'snoozed' => 'Snoozed',
    'label' => $activeLabel['name'] ?? 'Label',
    'custom' => $activeCustomFolder['name'] ?? 'Folder',
    default => 'Inbox',
};
?>
<!-- Advanced Search Filters Panel (Collapsible) -->
<div class="collapse <?= (!empty($criteria['date_from']) || !empty($criteria['date_to']) || $criteria['has_attachment'] !== '' || $criteria['is_unread'] !== '') ? 'show' : '' ?> mb-3" id="filterPanel">
    <div class="card card-body bg-body-tertiary border-0 shadow-sm p-3">
        <form method="GET" action="<?= app_base_url('public/inbox.php') ?>" class="row g-2 align-items-center">
            <?php if ($activeFolder !== 'inbox'): ?>
                <input type="hidden" name="folder" value="<?= e($activeFolder) ?>">
            <?php endif; ?>
            <?php if ($activeFolder === 'label'): ?>
                <input type="hidden" name="label" value="<?= (int)$activeLabelId ?>">
            <?php endif; ?>
            <?php if ($activeFolder === 'custom'): ?>
                <input type="hidden" name="id" value="<?= (int)$activeCustomFolderId ?>">
            <?php endif; ?>

            <div class="col-sm-6 col-lg-4">
                <label for="inboxSearch" class="form-label small mb-1">Search</label>
                <input id="inboxSearch" type="text" name="q" class="form-control form-control-sm" placeholder="Search messages" value="<?= e($criteria['q']) ?>">
            </div>
            <div class="col-sm-6 col-lg-4">
                <label for="inboxFrom" class="form-label small mb-1">From date</label>
                <input id="inboxFrom" type="date" name="date_from" class="form-control form-control-sm" value="<?= e($criteria['date_from']) ?>">
            </div>
            <div class="col-sm-6 col-lg-4">
                <label for="inboxTo" class="form-label small mb-1">To date</label>
                <input id="inboxTo" type="date" name="date_to" class="form-control form-control-sm" value="<?= e($criteria['date_to']) ?>">
            </div>
            <div class="col-sm-6 col-lg-4">
                <label for="inboxAttachment" class="form-label small mb-1">Attachments</label>
                <select id="inboxAttachment" name="has_attachment" class="form-select form-select-sm">
                    <option value="">Any Attachments</option>
                    <option value="1" <?= $criteria['has_attachment'] === '1' ? 'selected' : '' ?>>Has Attachment</option>
                    <option value="0" <?= $criteria['has_attachment'] === '0' ? 'selected' : '' ?>>No Attachment</option>
                </select>
            </div>
            <div class="col-sm-6 col-lg-4">
                <label for="inboxStatus" class="form-label small mb-1">Status</label>
                <select id="inboxStatus" name="is_unread" class="form-select form-select-sm">
                    <option value="">All Statuses</option>
                    <option value="1" <?= $criteria['is_unread'] === '1' ? 'selected' : '' ?>>Unread Only</option>
                    <option value="0" <?= $criteria['is_unread'] === '0' ? 'selected' : '' ?>>Read Only</option>
                </select>
            </div>
            <div class="col-12 col-lg-4 d-flex align-items-end gap-2">
                <button type="submit" class="btn btn-primary btn-sm">Apply filters</button>
                <a href="<?= app_base_url('public/inbox.php' . ($activeFolder !== 'inbox' ? '?folder=' . urlencode($activeFolder) . ($activeFolder === 'label' ? '&label=' . (int)$activeLabelId : '') . ($activeFolder === 'custom' ? '&id=' . (int)$activeCustomFolderId : '') : '')) ?>" class="btn btn-outline-secondary btn-sm">Clear</a>
            </div>
        </form>
    </div>
</div>

<!-- Main Split / List Layout Container -->
<div class="row g-0" id="mailViewContainer">
    <!-- Email Listing Column -->
    <div class="col-12" id="mailListCol">
        <div class="card shadow-sm border-0 rounded-4 overflow-hidden">
            <!-- Universal Gmail-Style Action Bar -->
            <div id="gmailActionBar" class="gmail-action-bar d-flex align-items-center justify-content-between px-3 py-2 border-bottom bg-body position-sticky top-0 z-3">
                <div class="d-flex align-items-center gap-1 action-bar-left flex-column flex-sm-row gap-sm-1 gap-2">
                    <!-- Master Selection Dropdown -->
                    <div class="dropdown d-inline-flex align-items-center me-1">
                        <div class="form-check m-0 me-1">
                            <input class="form-check-input" type="checkbox" id="selectAllEmails" title="Select all" aria-label="Select all emails">
                        </div>
                        <button class="btn btn-sm btn-link p-1 text-muted dropdown-toggle dropdown-toggle-split border-0 shadow-none" type="button" id="selectAllDropdown" data-bs-toggle="dropdown" aria-expanded="false" title="Select options" aria-label="Select filter options">
                        </button>
                        <ul class="dropdown-menu shadow-sm rounded-3 py-1" id="selectAllDropdownMenu" aria-labelledby="selectAllDropdown">
                            <li><button type="button" class="dropdown-item py-1.5 small select-filter-option" data-select="all">All</button></li>
                            <li><button type="button" class="dropdown-item py-1.5 small select-filter-option" data-select="none">None</button></li>
                            <li><button type="button" class="dropdown-item py-1.5 small select-filter-option" data-select="read">Read</button></li>
                            <li><button type="button" class="dropdown-item py-1.5 small select-filter-option" data-select="unread">Unread</button></li>
                            <li><button type="button" class="dropdown-item py-1.5 small select-filter-option" data-select="starred">Starred</button></li>
                            <li><button type="button" class="dropdown-item py-1.5 small select-filter-option" data-select="unstarred">Unstarred</button></li>
                        </ul>
                    </div>

                    <!-- Refresh Button -->
                    <a href="?<?= e(mail_pagination_query($page, $criteria, $activeFolder)) ?>" class="btn btn-sm btn-link text-muted p-1 border-0 action-bar-btn" id="btnActionRefresh" title="Refresh" aria-label="Refresh">
                        <i class="bi bi-arrow-clockwise fs-5"></i>
                    </a>

                    <!-- Contextual Selection Actions (shown when checkedCount > 0) -->
                    <div class="contextual-actions d-none d-sm-flex align-items-center gap-1 ps-2 border-start ms-1" id="contextualActionsBar">
                        <span class="badge bg-primary-subtle text-primary rounded-pill me-2 px-2 py-1" id="selectedCountBadge">0</span>
                        <button type="button" class="btn btn-sm btn-link text-muted p-1 border-0 action-bar-btn batch-action-btn" data-batch-action="archive" data-action="<?= $activeFolder === 'archive' ? 'unarchive' : 'archive' ?>" title="<?= $activeFolder === 'archive' ? 'Move back to Inbox' : 'Archive' ?>" aria-label="Archive">
                            <i class="bi <?= $activeFolder === 'archive' ? 'bi-inbox' : 'bi-archive' ?> fs-5"></i>
                            <span class="d-none d-sm-inline ms-1"><?= $activeFolder === 'archive' ? 'Unarchive' : 'Archive' ?></span>
                        </button>
                        <button type="button" class="btn btn-sm btn-link text-muted p-1 border-0 action-bar-btn batch-action-btn" data-batch-action="spam" data-action="spam" title="Report spam" aria-label="Report spam">
                            <i class="bi bi-exclamation-octagon fs-5"></i>
                            <span class="d-none d-sm-inline ms-1">Spam</span>
                        </button>
                        <button type="button" class="btn btn-sm btn-link text-muted p-1 border-0 action-bar-btn batch-action-btn" data-batch-action="trash" data-action="trash" title="Delete" aria-label="Delete">
                            <i class="bi bi-trash3 fs-5"></i>
                            <span class="d-none d-sm-inline ms-1">Trash</span>
                        </button>
                        <span class="action-divider border-end mx-1 d-none d-sm-inline" style="height: 18px;"></span>
                        <button type="button" class="btn btn-sm btn-link text-muted p-1 border-0 action-bar-btn batch-action-btn" data-batch-action="mark_read" data-action="mark_read" title="Mark as read" aria-label="Mark as read">
                            <i class="bi bi-envelope-open fs-5"></i>
                            <span class="d-none d-sm-inline ms-1">Read</span>
                        </button>
                        <button type="button" class="btn btn-sm btn-link text-muted p-1 border-0 action-bar-btn batch-action-btn" data-batch-action="mark_unread" data-action="mark_unread" title="Mark as unread" aria-label="Mark as unread">
                            <i class="bi bi-envelope fs-5"></i>
                            <span class="d-none d-sm-inline ms-1">Unread</span>
                        </button>
                        <button type="button" class="btn btn-sm btn-link text-muted p-1 border-0 action-bar-btn batch-action-btn" data-batch-action="star" data-action="star" title="Star" aria-label="Star">
                            <i class="bi bi-star fs-5"></i>
                            <span class="d-none d-sm-inline ms-1">Star</span>
                        </button>
                        <div class="dropdown d-inline-block">
                            <button class="btn btn-sm btn-link text-muted p-1 border-0 action-bar-btn" type="button" data-bs-toggle="dropdown" aria-expanded="false" title="Snooze" aria-label="Snooze">
                                <i class="bi bi-alarm fs-5"></i>
                                <span class="d-none d-sm-inline ms-1">Snooze</span>
                            </button>
                            <ul class="dropdown-menu shadow-sm">
                                <li><button type="button" class="dropdown-item batch-snooze-preset" data-hours="4">In 4 hours</button></li>
                                <li><button type="button" class="dropdown-item batch-snooze-preset" data-tomorrow="1">Tomorrow at 8:00 AM</button></li>
                                <li><button type="button" class="dropdown-item batch-snooze-preset" data-days="7">Next week</button></li>
                            </ul>
                        </div>
                        <?php if (!empty($organizationLabels)): ?>
                            <div class="dropdown d-inline-block">
                                <button class="btn btn-sm btn-link text-muted p-1 border-0 action-bar-btn" type="button" data-bs-toggle="dropdown" aria-expanded="false" title="Labels" aria-label="Labels">
                                    <i class="bi bi-tag fs-5"></i>
                                    <span class="d-none d-sm-inline ms-1">Labels</span>
                                </button>
                                <ul class="dropdown-menu shadow-sm">
                                    <?php foreach ($organizationLabels as $label): ?>
                                        <li><button type="button" class="dropdown-item batch-label-btn" data-label-id="<?= (int)$label['id'] ?>"><span class="label-dot me-2" style="background-color: <?= e($label['color']) ?>;"></span><?= e($label['name']) ?></button></li>
                                    <?php endforeach; ?>
                                </ul>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Right: Pagination & View Modes -->
                <div class="d-flex align-items-center gap-2 action-bar-right">
                    <div class="action-bar-pagination d-flex align-items-center text-muted small" style="font-size: 0.85rem;">
                        <?php
                        $startCount = $totalEmails > 0 ? (($page - 1) * $perPage) + 1 : 0;
                        $endCount = min($totalEmails, $page * $perPage);
                        ?>
                        <span class="me-2"><?= $startCount ?>–<?= $endCount ?> of <?= $totalEmails ?></span>
                        <div class="btn-group btn-group-sm">
                            <a href="?<?= e(mail_pagination_query(max(1, $page - 1), $criteria, $activeFolder)) ?>" class="btn btn-sm btn-link text-muted p-1 border-0 pagination-nav-btn <?= $page <= 1 ? 'disabled' : '' ?>" aria-label="Older" title="Older">
                                <i class="bi bi-chevron-left"></i>
                            </a>
                            <a href="?<?= e(mail_pagination_query(min($totalPages, $page + 1), $criteria, $activeFolder)) ?>" class="btn btn-sm btn-link text-muted p-1 border-0 pagination-nav-btn <?= $page >= $totalPages ? 'disabled' : '' ?>" aria-label="Newer" title="Newer">
                                <i class="bi bi-chevron-right"></i>
                            </a>
                        </div>
                    </div>
                    <button class="btn btn-sm btn-link text-muted p-1 border-0 action-bar-btn" type="button" data-bs-toggle="collapse" data-bs-target="#filterPanel" aria-controls="filterPanel" title="Search filters" aria-label="Toggle search filters">
                        <i class="bi bi-sliders2 fs-6"></i>
                    </button>
                </div>
            </div>

            <?php if ($activeFolder === 'inbox'): ?>
                <?php
                $activeCategory = $_GET['category'] ?? 'primary';
                $validCategories = ['primary', 'promotions', 'updates'];
                if (!in_array($activeCategory, $validCategories, true)) {
                    $activeCategory = 'primary';
                }
                ?>
                <!-- Material 3 Category Tabs -->
                <div id="inboxCategoryTabs" class="inbox-category-tabs d-flex border-bottom bg-body" role="tablist">
                    <a href="?category=primary" id="tabPrimary" class="category-tab-item <?= $activeCategory === 'primary' ? 'active' : '' ?>" role="tab" aria-selected="<?= $activeCategory === 'primary' ? 'true' : 'false' ?>">
                        <i class="bi bi-inbox-fill tab-icon"></i>
                        <span class="tab-label">Primary</span>
                        <?php if ($unreadInboxCount > 0): ?>
                            <span class="badge bg-primary-subtle text-primary border rounded-pill ms-1" style="font-size: 0.72rem;"><?= $unreadInboxCount ?></span>
                        <?php endif; ?>
                    </a>
                    <a href="?category=promotions" id="tabPromotions" class="category-tab-item <?= $activeCategory === 'promotions' ? 'active' : '' ?>" role="tab" aria-selected="<?= $activeCategory === 'promotions' ? 'true' : 'false' ?>">
                        <i class="bi bi-tag-fill tab-icon"></i>
                        <span class="tab-label">Promotions</span>
                    </a>
                    <a href="?category=updates" id="tabUpdates" class="category-tab-item <?= $activeCategory === 'updates' ? 'active' : '' ?>" role="tab" aria-selected="<?= $activeCategory === 'updates' ? 'true' : 'false' ?>">
                        <i class="bi bi-info-circle-fill tab-icon"></i>
                        <span class="tab-label">Updates</span>
                    </a>
                </div>
            <?php endif; ?>

            <!-- Email List -->
            <div class="mail-list-container" id="mailListItems">
                <?php if (empty($emails)): ?>
                    <div class="empty-state-container">
                        <?php if ($activeFolder === 'starred'): ?>
                            <i class="bi bi-star empty-state-icon"></i>
                            <div class="empty-state-title">No starred messages</div>
                            <div class="empty-state-subtitle">Star important messages to find them quickly later.</div>
                        <?php elseif ($activeFolder === 'archive'): ?>
                            <i class="bi bi-archive empty-state-icon"></i>
                            <div class="empty-state-title">Archive is empty</div>
                            <div class="empty-state-subtitle">Archive messages to declutter your Inbox without deleting them.</div>
                        <?php elseif ($activeFolder === 'snoozed'): ?>
                            <i class="bi bi-alarm empty-state-icon"></i>
                            <div class="empty-state-title">Nothing snoozed</div>
                            <div class="empty-state-subtitle">Snoozed messages stay hidden until the time you choose.</div>
                        <?php elseif ($activeFolder === 'label'): ?>
                            <i class="bi bi-tag empty-state-icon"></i>
                            <div class="empty-state-title">No messages with this label</div>
                            <div class="empty-state-subtitle">Apply the label from a message or from the batch toolbar.</div>
                        <?php elseif ($activeFolder === 'custom'): ?>
                            <i class="bi bi-folder2-open empty-state-icon"></i>
                            <div class="empty-state-title">This folder is empty</div>
                            <div class="empty-state-subtitle">Move messages here from the reading view.</div>
                        <?php elseif (!empty($criteria['q']) || !empty($criteria['date_from']) || !empty($criteria['date_to']) || $criteria['has_attachment'] !== '' || $criteria['is_unread'] !== ''): ?>
                            <i class="bi bi-search empty-state-icon"></i>
                            <div class="empty-state-title">No messages found</div>
                            <div class="empty-state-subtitle">Try a different search term or clear active filters.</div>
                        <?php elseif ($activeFolder === 'inbox' && ($activeCategory ?? 'primary') === 'promotions'): ?>
                            <i class="bi bi-tag empty-state-icon text-muted"></i>
                            <div class="empty-state-title">No promotional emails</div>
                            <div class="empty-state-subtitle">Marketing, deals, and newsletters will appear here.</div>
                        <?php elseif ($activeFolder === 'inbox' && ($activeCategory ?? 'primary') === 'updates'): ?>
                            <i class="bi bi-info-circle empty-state-icon text-muted"></i>
                            <div class="empty-state-title">No updates</div>
                            <div class="empty-state-subtitle">Confirmations, receipts, and automated notices will appear here.</div>
                        <?php else: ?>
                            <i class="bi bi-inbox empty-state-icon"></i>
                            <div class="empty-state-title">Your inbox is empty</div>
                            <div class="empty-state-subtitle">New incoming messages will appear here.</div>
                        <?php endif; ?>
                    </div>
                <?php else: ?>
                    <?php foreach ($emails as $mail): ?>
                        <div class="mail-item-row <?= $mail['is_read'] ? 'read' : 'unread' ?>" data-email-id="<?= $mail['id'] ?>">
                            <!-- Checkbox -->
                            <div class="form-check m-0 me-2" data-stop-propagation>
                                <input class="form-check-input email-select-checkbox" type="checkbox" value="<?= $mail['id'] ?>">
                            </div>

                            <!-- Star Button -->
                            <button type="button" class="star-btn star-toggle-btn me-2 <?= $mail['is_starred'] ? 'starred' : '' ?>" data-email-id="<?= $mail['id'] ?>" data-starred="<?= $mail['is_starred'] ? '1' : '0' ?>" title="<?= $mail['is_starred'] ? 'Unstar' : 'Star' ?>">
                                <i class="bi <?= $mail['is_starred'] ? 'bi-star-fill text-warning' : 'bi-star text-secondary' ?>"></i>
                            </button>

                            <!-- Unread indicator dot -->
                            <span class="unread-dot me-2 <?= $mail['is_read'] ? 'd-none' : '' ?>" title="Unread message"></span>

                            <!-- Sender Name -->
                            <a href="<?= app_base_url('public/read.php?id=' . $mail['id']) ?>" class="mail-sender text-decoration-none">
                                <?= e(trim(($mail['sender_first_name'] ?? '') . ' ' . ($mail['sender_last_name'] ?? ''))) ?: e($mail['sender_email']) ?>
                            </a>

                            <!-- Subject & Snippet -->
                            <a href="<?= app_base_url('public/read.php?id=' . $mail['id']) ?>" class="mail-subject-snippet text-decoration-none">
                                <span class="mail-subject"><?= e($mail['subject']) ?: '(No Subject)' ?></span>
                                <?php foreach (($mail['labels'] ?? []) as $label): ?>
                                    <span class="label-chip"><span class="label-dot" style="background-color: <?= e($label['color']) ?>;"></span><?= e($label['name']) ?></span>
                                <?php endforeach; ?>
                                <span class="mail-snippet">&mdash; <?= e(mb_strimwidth(strip_tags($mail['body'] ?? ''), 0, 220, '...')) ?></span>
                                
                                <!-- Attachment Chips in Default Density -->
                                <?php if (!empty($mail['attachments'])): ?>
                                    <div class="mail-attachment-chips mt-1 d-flex flex-wrap gap-1" data-stop-propagation>
                                        <?php foreach (array_slice($mail['attachments'], 0, 3) as $att): ?>
                                            <?php
                                            $ext = strtolower(pathinfo($att['original_filename'], PATHINFO_EXTENSION));
                                            $iconClass = match ($ext) {
                                                'pdf' => 'bi-file-earmark-pdf text-danger',
                                                'jpg', 'jpeg', 'png', 'gif', 'webp' => 'bi-file-earmark-image text-primary',
                                                'zip', 'rar', '7z', 'tar', 'gz' => 'bi-file-earmark-zip text-warning',
                                                'doc', 'docx' => 'bi-file-earmark-word text-info',
                                                'xls', 'xlsx' => 'bi-file-earmark-excel text-success',
                                                default => 'bi-file-earmark text-secondary',
                                            };
                                            ?>
                                            <span class="attachment-chip" title="<?= e($att['original_filename']) ?> (<?= format_bytes((int)$att['filesize']) ?>)">
                                                <i class="bi <?= $iconClass ?> me-1"></i>
                                                <span class="attachment-name text-truncate" style="max-width: 140px;"><?= e($att['original_filename']) ?></span>
                                            </span>
                                        <?php endforeach; ?>
                                        <?php if (count($mail['attachments']) > 3): ?>
                                            <span class="attachment-chip badge bg-light text-muted border">+<?= count($mail['attachments']) - 3 ?></span>
                                        <?php endif; ?>
                                    </div>
                                <?php endif; ?>
                            </a>

                            <!-- Attachment Icon -->
                            <?php if (($mail['attachment_count'] ?? 0) > 0): ?>
                                <span class="text-muted me-3" title="<?= $mail['attachment_count'] ?> attachment(s)">
                                    <i class="bi bi-paperclip"></i>
                                </span>
                            <?php endif; ?>

                            <!-- Timestamp -->
                            <div class="mail-date me-2">
                                <?= date('M d', strtotime($mail['created_at'])) ?>
                            </div>

                            <!-- Quick Action Hover Icons -->
                            <div class="mail-quick-actions" data-stop-propagation>
                                <button type="button" class="btn btn-sm btn-light border-0 quick-action-btn quick-read-toggle-btn" data-email-id="<?= $mail['id'] ?>" data-read="<?= $mail['is_read'] ? '1' : '0' ?>" title="<?= $mail['is_read'] ? 'Mark as unread' : 'Mark as read' ?>">
                                    <i class="bi <?= $mail['is_read'] ? 'bi-envelope' : 'bi-envelope-open' ?>"></i>
                                </button>
                                <?php if ($activeFolder === 'archive'): ?>
                                    <button type="button" class="btn btn-sm btn-light border-0 quick-action-btn quick-unarchive-btn" data-email-id="<?= $mail['id'] ?>" title="Move back to Inbox">
                                        <i class="bi bi-inbox"></i>
                                    </button>
                                <?php else: ?>
                                    <button type="button" class="btn btn-sm btn-light border-0 quick-action-btn quick-archive-btn" data-email-id="<?= $mail['id'] ?>" title="Archive">
                                        <i class="bi bi-archive"></i>
                                    </button>
                                <?php endif; ?>
                                <?php if ($activeFolder === 'snoozed'): ?>
                                    <button type="button" class="btn btn-sm btn-light border-0 quick-action-btn quick-unsnooze-btn" data-email-id="<?= $mail['id'] ?>" title="Unsnooze now">
                                        <i class="bi bi-alarm-fill"></i>
                                    </button>
                                <?php else: ?>
                                    <div class="dropdown">
                                        <button type="button" class="btn btn-sm btn-light border-0 quick-action-btn" data-bs-toggle="dropdown" data-bs-boundary="viewport" aria-expanded="false" title="Snooze">
                                            <i class="bi bi-alarm"></i>
                                        </button>
                                        <ul class="dropdown-menu dropdown-menu-end shadow-sm quick-snooze-menu">
                                            <li><h6 class="dropdown-header">Snooze until</h6></li>
                                            <li><button type="button" class="dropdown-item quick-snooze-btn" data-email-id="<?= $mail['id'] ?>" data-hours="4">In 4 hours</button></li>
                                            <li><button type="button" class="dropdown-item quick-snooze-btn" data-email-id="<?= $mail['id'] ?>" data-tomorrow="1">Tomorrow at 8:00 AM</button></li>
                                            <li><button type="button" class="dropdown-item quick-snooze-btn" data-email-id="<?= $mail['id'] ?>" data-days="7">Next week</button></li>
                                        </ul>
                                    </div>
                                <?php endif; ?>
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
                <p class="small text-muted">Click any conversation from the list to preview it here without leaving your mailbox.</p>
            </div>
        </div>
    </div>
</div>

<?php require_once dirname(__DIR__) . '/includes/footer.php'; ?>
