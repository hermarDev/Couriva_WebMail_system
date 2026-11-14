<?php
/**
 * Couriva - Global Header Template
 * Modern Productivity Design System
 */

if (!defined('WEBMAIL_INIT')) {
    define('WEBMAIL_INIT', true);
}

require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/auth.php';
require_once dirname(__DIR__) . '/app/Services/OrganizationService.php';

// Authenticated user information
$userId = $_SESSION['user_id'] ?? 0;
$userEmail = $_SESSION['user_email'] ?? '';
$userFirstName = $_SESSION['user_first_name'] ?? '';
$userLastName = $_SESSION['user_last_name'] ?? '';
$userFullName = trim($userFirstName . ' ' . $userLastName) ?: ($_SESSION['user_full_name'] ?? 'User');
$userRole = $_SESSION['user_role'] ?? 'user';
$isAdmin = is_admin();
$activeFolder = $activeFolder ?? 'inbox';
$activeLabelId = $activeLabelId ?? 0;
$activeCustomFolderId = $activeCustomFolderId ?? 0;

// Calculate dynamic initials (e.g. "JD", "MS")
$userInitials = strtoupper(substr($userFirstName, 0, 1) . substr($userLastName, 0, 1));
if (empty(trim($userInitials))) {
    $userInitials = strtoupper(substr($userEmail ?: 'U', 0, 2));
}

// Fetch live folder badge counts
$unreadInboxCount = 0;
$draftCount = 0;
$spamCount = 0;
$userStorageBytes = 0;
$snoozedCount = 0;
$scheduledCount = 0;
$archiveCount = 0;
$unreadNotifications = 0;
$organizationLabels = [];
$userFolders = [];

if ($userId > 0 && empty($_SESSION['must_change_password'])) {
    try {
        global $pdo;
        // Unread in inbox
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM emails WHERE recipient_id = ? AND folder = 'inbox' AND is_read = 0 AND deleted_at IS NULL AND is_archived = 0 AND custom_folder_id IS NULL AND (snoozed_until IS NULL OR snoozed_until <= NOW())");
        $stmt->execute([$userId]);
        $unreadInboxCount = (int)$stmt->fetchColumn();

        // Drafts
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM emails WHERE sender_id = ? AND folder = 'draft' AND deleted_at IS NULL");
        $stmt->execute([$userId]);
        $draftCount = (int)$stmt->fetchColumn();

        // Spam
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM emails WHERE recipient_id = ? AND folder = 'spam' AND is_read = 0 AND deleted_at IS NULL");
        $stmt->execute([$userId]);
        $spamCount = (int)$stmt->fetchColumn();

        // Snoozed
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM emails WHERE recipient_id = ? AND folder = 'inbox' AND snoozed_until > NOW() AND deleted_at IS NULL");
        $stmt->execute([$userId]);
        $snoozedCount = (int)$stmt->fetchColumn();

        // Archived
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM emails WHERE recipient_id = ? AND is_archived = 1 AND deleted_at IS NULL");
        $stmt->execute([$userId]);
        $archiveCount = (int)$stmt->fetchColumn();

        // Scheduled dispatches
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM scheduled_emails WHERE user_id = ? AND status = 'scheduled'");
        $stmt->execute([$userId]);
        $scheduledCount = (int)$stmt->fetchColumn();

        $unreadNotifications = unread_notification_count($userId);

        $organization = new \App\Services\OrganizationService($pdo);
        $organizationLabels = $organization->getLabels($userId);
        $userFolders = $organization->getFolders($userId);

        // Storage calculation
        $storageStmt = $pdo->prepare("
            SELECT COALESCE(SUM(att.filesize), 0)
            FROM attachments att
            JOIN emails e ON att.email_id = e.id
            WHERE (e.sender_id = ? OR e.recipient_id = ?)
        ");
        $storageStmt->execute([$userId, $userId]);
        $userStorageBytes = (int)$storageStmt->fetchColumn();
    } catch (Throwable $t) {
        // Fallback silently if DB issue
    }
}

$storageQuotaBytes = 500 * 1024 * 1024; // 500 MB default quota
$storagePercent = min(100, (int)round(($userStorageBytes / $storageQuotaBytes) * 100));
$currentTheme = is_authenticated() ? ($_SESSION['user_theme'] ?? 'light') : '';
$resolvedTheme = $currentTheme === 'dark' ? 'dark' : 'light';
$bodyClass = $bodyClass ?? '';
?>
<!DOCTYPE html>
<html lang="en" data-bs-theme="<?= e($resolvedTheme) ?>"<?= $currentTheme !== '' ? ' data-theme-preference="' . e($currentTheme) . '"' : '' ?>>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?= e(csrf_token()) ?>">
    <title><?= isset($pageTitle) ? e($pageTitle) . ' — ' . e(app_name()) : e(app_name()) ?></title>
    <!-- Brand Favicons & App Icons -->
    <link rel="icon" type="image/svg+xml" href="<?= app_base_url('assets/brand/couriva-favicon.svg') ?>" id="appFavicon">
    <link rel="icon" type="image/png" sizes="32x32" href="<?= app_base_url('assets/brand/favicon-32x32.png') ?>">
    <link rel="icon" type="image/png" sizes="16x16" href="<?= app_base_url('assets/brand/favicon-16x16.png') ?>">
    <link rel="apple-touch-icon" sizes="180x180" href="<?= app_base_url('assets/brand/apple-touch-icon.png') ?>">
    <link rel="shortcut icon" href="<?= app_base_url('assets/brand/favicon.ico') ?>">
    <link rel="manifest" href="<?= app_base_url('public/manifest.json') ?>">
    <!-- Inter Font Family -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Locally bundled Bootstrap Icons -->
    <link href="<?= app_base_url('assets/css/bootstrap-icons.css') ?>" rel="stylesheet">
    <!-- Font-independent local SVG icon fallback -->
    <link href="<?= app_base_url('assets/css/icon-fallback.css') ?>" rel="stylesheet">
    <!-- Locally generated Tailwind utility layer -->
    <link href="<?= app_base_url('assets/css/tailwind.generated.css') ?>" rel="stylesheet">
    <!-- Application Modern Token CSS -->
    <link href="<?= app_base_url('assets/css/style.css') ?>" rel="stylesheet">
    <meta name="webmail-base-url" content="<?= e(rtrim(app_base_url(), '/')) ?>">
    <meta name="couriva-domain" content="<?= e(app_domain()) ?>">
</head>
<body<?= $bodyClass !== '' ? ' class="' . e($bodyClass) . '"' : '' ?>>

<?php if (is_authenticated() && empty($_SESSION['must_change_password'])): ?>
<a class="visually-hidden-focusable skip-link" href="#main-content">Skip to content</a>
<!-- Top Compact Navigation Bar (56–64px height) -->
<header class="navbar navbar-expand webmail-navbar">
    <div class="container-fluid px-2 px-md-3">
        <!-- Left: Mobile Sidebar Toggler & Unified Brand Header -->
        <div class="d-flex align-items-center header-brand-zone">
            <button class="btn btn-sm webmail-header-btn border d-md-none me-2" type="button" data-bs-toggle="collapse" data-bs-target="#sidebarMenu" aria-controls="sidebarMenu" aria-expanded="false" aria-label="Toggle navigation">
                <i class="bi bi-list fs-5"></i>
            </button>
            <a class="header-brand-link d-flex align-items-center text-decoration-none gap-2 text-truncate" href="<?= app_base_url('public/inbox.php') ?>" title="<?= e(app_name()) ?>">
                <div class="brand-monogram-container sidebar-brand-icon">
                    <img src="<?= app_base_url('assets/brand/couriva-logo-black.svg') ?>" class="couriva-logo-symbol couriva-logo-light" alt="Couriva" width="32" height="32">
                    <img src="<?= app_base_url('assets/brand/couriva-logo-white.svg') ?>" class="couriva-logo-symbol couriva-logo-dark" alt="Couriva" width="32" height="32">
                </div>
                <div class="header-brand-text d-none d-sm-block text-truncate">
                    <div class="sidebar-brand-title fw-bold text-body lh-sm" style="font-size: 1.05rem;"><?= e(app_name()) ?></div>
                    <div class="sidebar-brand-subtitle text-muted" style="font-size: 0.72rem; line-height: 1;">Workspace Mail</div>
                </div>
            </a>
        </div>

        <!-- Center: Gmail-Style Centered Pill Search Bar with Floating Advanced Filter Popover -->
        <div class="d-none d-md-flex flex-grow-1 justify-content-center px-3 position-relative">
            <div style="max-width: 720px; width: 100%;">
                <form class="w-100 d-flex align-items-center gmail-search-bar rounded-pill px-3 py-1 position-relative" id="gmailSearchBar" action="<?= app_base_url('public/inbox.php') ?>" method="GET">
                    <?php if (in_array($activeFolder, ['starred', 'archive', 'snoozed', 'label', 'custom'], true)): ?>
                        <input type="hidden" name="folder" value="<?= e($activeFolder) ?>">
                        <?php if ($activeFolder === 'label'): ?><input type="hidden" name="label" value="<?= (int)$activeLabelId ?>"><?php endif; ?>
                        <?php if ($activeFolder === 'custom'): ?><input type="hidden" name="id" value="<?= (int)$activeCustomFolderId ?>"><?php endif; ?>
                    <?php endif; ?>
                    <?php if (isset($_GET['is_starred']) && in_array($_GET['is_starred'], ['0', '1'], true)): ?><input type="hidden" name="is_starred" value="<?= e($_GET['is_starred']) ?>"><?php endif; ?>
                    <button type="submit" class="btn btn-link p-0 text-muted me-2 border-0 search-submit-btn" aria-label="Submit Search">
                        <i class="bi bi-search"></i>
                    </button>
                    <input type="text" name="q" id="headerSearchInput" class="form-control border-0 bg-transparent shadow-none p-0 gmail-search-input" aria-label="Search in mail" placeholder="Search in mail" value="<?= e($_GET['q'] ?? '') ?>">
                    <?php if (!empty($_GET['q'])): ?>
                        <a href="<?= app_base_url('public/inbox.php') ?>" class="text-muted text-decoration-none me-2" title="Clear search" aria-label="Clear search"><i class="bi bi-x-lg"></i></a>
                    <?php endif; ?>
                    <!-- Advanced Search Tune/Sliders Icon -->
                    <button type="button" class="btn btn-sm btn-link p-1 text-muted border-0 search-filter-btn rounded-circle" id="searchFilterDropdown" data-bs-toggle="dropdown" data-bs-auto-close="outside" aria-expanded="false" title="Show search options" aria-label="Show search options">
                        <i class="bi bi-sliders2 fs-6"></i>
                    </button>

                    <!-- Floating Search Options Popover Menu -->
                    <div class="dropdown-menu dropdown-menu-end p-3 shadow-lg border-0 rounded-4 advanced-search-popover" style="width: min(520px, 90vw);">
                        <div class="row g-2">
                            <div class="col-sm-6">
                                <label for="popoverStatus" class="form-label small text-muted mb-1">Status</label>
                                <select id="popoverStatus" name="is_unread" class="form-select form-select-sm">
                                    <option value="">All</option>
                                    <option value="1" <?= ($_GET['is_unread'] ?? '') === '1' ? 'selected' : '' ?>>Unread only</option>
                                    <option value="0" <?= ($_GET['is_unread'] ?? '') === '0' ? 'selected' : '' ?>>Read only</option>
                                </select>
                            </div>
                            <div class="col-sm-6">
                                <label for="popoverAttachment" class="form-label small text-muted mb-1">Attachments</label>
                                <select id="popoverAttachment" name="has_attachment" class="form-select form-select-sm">
                                    <option value="">Any</option>
                                    <option value="1" <?= ($_GET['has_attachment'] ?? '') === '1' ? 'selected' : '' ?>>Has attachment</option>
                                    <option value="0" <?= ($_GET['has_attachment'] ?? '') === '0' ? 'selected' : '' ?>>No attachment</option>
                                </select>
                            </div>
                            <div class="col-sm-6">
                                <label for="popoverDateFrom" class="form-label small text-muted mb-1">Date from</label>
                                <input type="date" id="popoverDateFrom" name="date_from" class="form-control form-control-sm" value="<?= e($_GET['date_from'] ?? '') ?>">
                            </div>
                            <div class="col-sm-6">
                                <label for="popoverDateTo" class="form-label small text-muted mb-1">Date to</label>
                                <input type="date" id="popoverDateTo" name="date_to" class="form-control form-control-sm" value="<?= e($_GET['date_to'] ?? '') ?>">
                            </div>
                            <div class="col-12 d-flex align-items-center justify-content-end pt-2">
                                <div class="d-flex gap-2">
                                    <a href="<?= app_base_url('public/inbox.php') ?>" class="btn btn-sm btn-outline-secondary">Reset</a>
                                    <button type="submit" class="btn btn-sm btn-primary rounded-pill px-3">Search</button>
                                </div>
                            </div>
                        </div>
                    </div>
                </form>
            </div>
        </div>

        <!-- Right: Actions, Theme Switcher & Dynamic Profile -->
        <div class="d-flex align-items-center gap-2">
            <!-- Notifications -->
            <a href="<?= app_base_url('public/notifications.php') ?>" class="btn btn-sm webmail-header-btn border position-relative d-flex align-items-center justify-content-center rounded-circle px-2 py-1 shadow-sm" title="Notifications" aria-label="Notifications<?= $unreadNotifications > 0 ? ' (' . $unreadNotifications . ' unread)' : '' ?>" style="width: 44px; height: 44px;">
                <i class="bi bi-bell<?= $unreadNotifications > 0 ? '-fill text-primary' : '' ?>"></i>
                <?php if ($unreadNotifications > 0): ?>
                    <span class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger" style="font-size: 0.62rem;"><?= $unreadNotifications > 99 ? '99+' : $unreadNotifications ?></span>
                <?php endif; ?>
            </a>

            <!-- Quick Settings Button -->
            <button class="btn btn-sm webmail-header-btn border d-flex align-items-center justify-content-center rounded-circle shadow-sm" type="button" id="btnQuickSettings" data-bs-toggle="offcanvas" data-bs-target="#quickSettingsOffcanvas" aria-controls="quickSettingsOffcanvas" title="Quick settings" aria-label="Quick settings" style="width: 44px; height: 44px;">
                <i class="bi bi-gear fs-6"></i>
            </button>

            <!-- Theme Toggle Button -->
            <button class="btn btn-sm webmail-header-btn border d-flex align-items-center justify-content-center rounded-circle shadow-sm" id="themeToggleBtn" type="button" title="Toggle Dark/Light Mode" aria-label="Toggle theme" style="width: 44px; height: 44px;">
                <i class="bi <?= $currentTheme === 'dark' ? 'bi-moon-stars-fill' : 'bi-sun-fill' ?>" id="themeToggleIcon"></i>
                <span class="visually-hidden" id="themeToggleLabel"><?= $currentTheme === 'dark' ? 'Dark' : 'Light' ?></span>
            </button>

            <!-- Google Apps Launcher Button & Dropdown -->
            <div class="dropdown">
                <button class="btn btn-sm webmail-header-btn border d-flex align-items-center justify-content-center rounded-circle shadow-sm" type="button" id="googleAppsLauncherBtn" data-bs-toggle="dropdown" aria-expanded="false" title="Couriva apps" aria-label="Couriva apps" style="width: 44px; height: 44px;">
                    <i class="bi bi-grid-3x3-gap-fill fs-6"></i>
                </button>
                <div class="dropdown-menu dropdown-menu-end shadow-lg border-0 p-3 rounded-4" id="googleAppsMenu" style="width: 300px;">
                    <div class="app-launcher-grid">
                        <a href="<?= app_base_url('public/inbox.php') ?>" class="app-launcher-tile text-decoration-none">
                            <div class="app-tile-icon"><i class="bi bi-envelope-fill"></i></div>
                            <span class="app-tile-name">Mail</span>
                        </a>
                        <a href="<?= app_base_url('public/contacts.php') ?>" class="app-launcher-tile text-decoration-none">
                            <div class="app-tile-icon"><i class="bi bi-person-lines-fill"></i></div>
                            <span class="app-tile-name">Contacts</span>
                        </a>
                        <a href="<?= app_base_url('public/settings.php') ?>" class="app-launcher-tile text-decoration-none">
                            <div class="app-tile-icon"><i class="bi bi-gear-fill"></i></div>
                            <span class="app-tile-name">Account settings</span>
                        </a>
                        <?php if ($isAdmin): ?>
                            <a href="<?= app_base_url('admin/index.php') ?>" class="app-launcher-tile text-decoration-none">
                                <div class="app-tile-icon"><i class="bi bi-speedometer2"></i></div>
                                <span class="app-tile-name">Admin</span>
                            </a>
                            <a href="<?= app_base_url('admin/users.php') ?>" class="app-launcher-tile text-decoration-none">
                                <div class="app-tile-icon"><i class="bi bi-people-fill"></i></div>
                                <span class="app-tile-name">Users</span>
                            </a>
                            <a href="<?= app_base_url('admin/logs.php') ?>" class="app-launcher-tile text-decoration-none">
                                <div class="app-tile-icon"><i class="bi bi-file-earmark-text-fill"></i></div>
                                <span class="app-tile-name">Logs</span>
                            </a>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Authenticated User Profile Menu -->
            <div class="dropdown" id="userProfileMenu">
                <button class="btn btn-sm webmail-header-btn border d-flex align-items-center justify-content-center rounded-circle shadow-sm user-avatar-btn p-0" type="button" id="userProfileBtn" data-bs-toggle="dropdown" aria-expanded="false" title="<?= e($userFullName) ?> (<?= e($userEmail) ?>)" aria-label="Account details" style="width: 44px; height: 44px;">
                    <span class="user-avatar-badge rounded-circle" style="width: 36px; height: 36px; font-size: 0.9rem;"><?= e($userInitials) ?></span>
                </button>
                <div class="dropdown-menu dropdown-menu-end shadow-lg border-0 p-3 google-profile-card rounded-4" style="min-width: 280px;">
                    <div class="text-center py-2">
                        <div class="mx-auto user-avatar-badge rounded-circle mb-2" style="width: 52px; height: 52px; font-size: 1.3rem; display: flex; align-items: center; justify-content: center; background: var(--primary); color: #fff;">
                            <?= e($userInitials) ?>
                        </div>
                        <div class="fw-bold text-body text-truncate fs-6"><?= e($userFullName) ?></div>
                        <div class="text-muted small text-truncate mb-2"><?= e($userEmail) ?></div>
                        <a href="<?= app_base_url('public/settings.php') ?>" class="btn btn-outline-secondary btn-sm rounded-pill px-3 py-1 fw-medium">
                            Account settings
                        </a>
                    </div>
                    <hr class="my-2">
                    <div class="d-flex flex-column gap-1">
                        <a class="dropdown-item py-2 rounded-3 d-flex align-items-center gap-2" href="<?= app_base_url('public/settings.php') ?>">
                            <i class="bi bi-sliders text-secondary"></i>
                            <span>Account settings</span>
                        </a>
                        <a class="dropdown-item py-2 rounded-3 d-flex align-items-center gap-2" href="<?= app_base_url('public/contacts.php') ?>">
                            <i class="bi bi-person-lines-fill text-secondary"></i>
                            <span>Contacts</span>
                        </a>
                        <?php if ($isAdmin): ?>
                            <a class="dropdown-item py-2 rounded-3 d-flex align-items-center gap-2" href="<?= app_base_url('admin/index.php') ?>">
                                <i class="bi bi-speedometer2 text-secondary"></i>
                                <span>Admin Dashboard</span>
                            </a>
                        <?php endif; ?>
                    </div>
                    <hr class="my-2">
                    <div class="text-center">
                        <a class="btn btn-light border text-danger w-100 rounded-pill py-1.5 fw-medium d-flex align-items-center justify-content-center gap-2" href="<?= app_base_url('public/logout.php') ?>">
                            <i class="bi bi-box-arrow-right"></i>
                            <span>Sign out</span>
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>
</header>

<!-- Quick Settings Offcanvas Drawer (Mockup Parity) -->
<div class="offcanvas offcanvas-end shadow" tabindex="-1" id="quickSettingsOffcanvas" role="dialog" aria-modal="true" aria-labelledby="quickSettingsOffcanvasLabel" style="width: min(350px, 90vw);">
    <div class="offcanvas-header border-bottom py-3 px-4">
        <div>
            <h5 class="offcanvas-title fw-bold fs-6 mb-0 text-body" id="quickSettingsOffcanvasLabel">Quick settings</h5>
            <div class="text-muted small" style="font-size: 0.78rem;">Personalize your mail experience</div>
        </div>
        <button type="button" class="btn-close" data-bs-dismiss="offcanvas" aria-label="Close"></button>
    </div>
    <div class="offcanvas-body p-4">
        <!-- Appearance Section -->
        <div class="mb-4">
            <div class="qs-section-heading">Appearance</div>
            <div class="qs-theme-grid">
                <button type="button" class="qs-theme-card theme-select-btn <?= $currentTheme === 'light' ? 'selected' : '' ?>" data-theme="light" aria-label="Light Theme">
                    <i class="bi bi-sun fs-5"></i>
                    <span class="small fw-medium">Light</span>
                    <span class="qs-card-indicator"><i class="bi <?= $currentTheme === 'light' ? 'bi-check-circle-fill' : 'bi-circle' ?>"></i></span>
                </button>
                <button type="button" class="qs-theme-card theme-select-btn <?= $currentTheme === 'dark' ? 'selected' : '' ?>" data-theme="dark" aria-label="Dark Theme">
                    <i class="bi bi-moon fs-5"></i>
                    <span class="small fw-medium">Dark</span>
                    <span class="qs-card-indicator"><i class="bi <?= $currentTheme === 'dark' ? 'bi-check-circle-fill' : 'bi-circle' ?>"></i></span>
                </button>
                <button type="button" class="qs-theme-card theme-select-btn <?= $currentTheme === 'system' ? 'selected' : '' ?>" data-theme="system" aria-label="System Theme">
                    <i class="bi bi-display fs-5"></i>
                    <span class="small fw-medium">System</span>
                    <span class="qs-card-indicator"><i class="bi <?= $currentTheme === 'system' ? 'bi-check-circle-fill' : 'bi-circle' ?>"></i></span>
                </button>
            </div>
        </div>

        <hr class="my-3 opacity-25">

        <!-- Display Density Section -->
        <div class="mb-4">
            <div class="qs-section-heading">Display density</div>
            <div class="qs-density-group">
                <label class="qs-density-card" for="densityComfortable">
                    <input class="form-check-input density-radio" type="radio" name="densitySetting" id="densityComfortable" value="comfortable">
                    <div class="qs-density-info">
                        <div class="fw-semibold qs-density-title">Comfortable</div>
                        <div class="text-muted small" style="font-size: 0.78rem;">Spacious rows and attachment details</div>
                    </div>
                </label>
                <label class="qs-density-card selected" for="densityDefault">
                    <input class="form-check-input density-radio" type="radio" name="densitySetting" id="densityDefault" value="default" checked>
                    <div class="qs-density-info">
                        <div class="fw-semibold qs-density-title">Default</div>
                        <div class="text-muted small" style="font-size: 0.78rem;">Balanced layout for everyday use</div>
                    </div>
                </label>
                <label class="qs-density-card" for="densityCompact">
                    <input class="form-check-input density-radio" type="radio" name="densitySetting" id="densityCompact" value="compact">
                    <div class="qs-density-info">
                        <div class="fw-semibold qs-density-title">Compact</div>
                        <div class="text-muted small" style="font-size: 0.78rem;">More messages visible at once</div>
                    </div>
                </label>
            </div>
        </div>

        <hr class="my-3 opacity-25">

        <!-- Reading Pane Section -->
        <div class="mb-4">
            <div class="qs-section-heading">Reading pane</div>
            <div class="qs-pane-grid">
                <button type="button" class="qs-pane-card qs-pane-none selected" id="qsPaneNoneBtn" data-pane="list">
                    <i class="bi bi-window fs-5 text-primary"></i>
                    <span class="fw-semibold small">No split</span>
                    <span class="badge bg-success-subtle text-success border border-success-subtle px-2 py-1 qs-selected-badge">Selected</span>
                </button>
                <button type="button" class="qs-pane-card qs-pane-right" id="qsPaneRightBtn" data-pane="split">
                    <i class="bi bi-layout-sidebar-reverse fs-5"></i>
                    <span class="fw-semibold small">Right pane</span>
                    <span class="text-muted small" style="font-size: 0.72rem;">Preview emails</span>
                </button>
            </div>
            <!-- Hidden radio controls for legacy selector compatibility -->
            <div class="d-none">
                <input class="reading-pane-radio" type="radio" name="readingPaneSetting" id="readingPaneNone" value="none" checked>
                <input class="reading-pane-radio" type="radio" name="readingPaneSetting" id="readingPaneRight" value="right">
            </div>
        </div>

        <hr class="my-3 opacity-25">

        <div class="pt-1">
            <a href="<?= app_base_url('public/settings.php') ?>" class="d-flex align-items-center justify-content-between text-decoration-none fw-medium text-body px-1 py-1">
                <span>Account settings</span>
                <i class="bi bi-arrow-right fs-5 text-muted"></i>
            </a>
        </div>
    </div>
</div>
    </div>
</header>

<!-- Main Workspace Layout with Sidebar -->
<div class="container-fluid">
    <div class="row">
        <!-- Sidebar Navigation (Section 13, 14, 39) -->
        <nav class="col-md-3 col-lg-2 d-md-block webmail-sidebar collapse" id="sidebarMenu">
            <!-- Sidebar Navigation Header & Chevron Edge Toggle (Option 1 - Mockup Parity) -->
            <div class="sidebar-edge-toggle-container d-none d-md-flex align-items-center justify-content-between mb-2 px-1">
                <span class="sidebar-nav-heading text-uppercase text-muted fw-semibold" style="font-size: 0.72rem; letter-spacing: 0.05em;">Navigation</span>
                <button type="button" class="sidebar-edge-toggle btn btn-sm border-0 text-muted p-1" id="sidebarMiniToggle" title="Collapse sidebar" aria-label="Collapse sidebar">
                    <i class="bi bi-chevron-left sidebar-icon-expanded"></i>
                    <i class="bi bi-chevron-right sidebar-icon-collapsed"></i>
                </button>
            </div>

            <!-- Material 3 Floating Pill Compose Button -->
            <div class="mb-2">
                <a href="<?= app_base_url('public/compose.php') ?>" class="btn-compose btn-compose-fab w-100" id="btnOpenCompose" title="Compose message">
                    <i class="bi bi-pencil-fill compose-icon me-2"></i>
                    <span class="nav-text fw-semibold">Compose message</span>
                </a>
            </div>

            <!-- Mail Section -->
            <div class="sidebar-section-title">MAIL</div>
            <div class="nav flex-column mb-2">
                <a class="webmail-nav-link nav-pill-item <?= $activeFolder === 'inbox' ? 'active' : '' ?>" href="<?= app_base_url('public/inbox.php') ?>" title="Inbox" <?= $activeFolder === 'inbox' ? 'aria-current="page"' : '' ?>>
                    <i class="bi bi-inbox nav-icon"></i>
                    <span class="nav-text">Inbox</span>
                    <?php if ($unreadInboxCount > 0): ?>
                        <span class="badge bg-primary rounded-pill inbox-unread-badge ms-auto"><?= $unreadInboxCount ?></span>
                    <?php endif; ?>
                </a>

                <a class="webmail-nav-link nav-pill-item <?= $activeFolder === 'starred' ? 'active' : '' ?>" href="<?= app_base_url('public/inbox.php?folder=starred') ?>" title="Starred">
                    <i class="bi bi-star nav-icon"></i>
                    <span class="nav-text">Starred</span>
                </a>

                <a class="webmail-nav-link nav-pill-item <?= $activeFolder === 'sent' ? 'active' : '' ?>" href="<?= app_base_url('public/sent.php') ?>" title="Sent">
                    <i class="bi bi-send nav-icon"></i>
                    <span class="nav-text">Sent</span>
                </a>

                <a class="webmail-nav-link nav-pill-item <?= $activeFolder === 'draft' || $activeFolder === 'drafts' ? 'active' : '' ?>" href="<?= app_base_url('public/drafts.php') ?>" title="Drafts">
                    <i class="bi bi-file-earmark-text nav-icon"></i>
                    <span class="nav-text">Drafts</span>
                    <?php if ($draftCount > 0): ?>
                        <span class="badge rounded-pill sidebar-count-pill ms-auto"><?= $draftCount ?></span>
                    <?php endif; ?>
                </a>

                <?php
                $isSecondaryActive = in_array($activeFolder, ['snoozed', 'scheduled', 'archive', 'spam', 'trash'], true);
                ?>
                <button class="btn btn-link webmail-nav-link nav-pill-item text-muted w-100 text-start text-decoration-none border-0 d-flex align-items-center py-2 px-3" type="button" data-bs-toggle="collapse" data-bs-target="#moreFoldersCollapse" aria-expanded="<?= $isSecondaryActive ? 'true' : 'false' ?>" aria-controls="moreFoldersCollapse" id="moreFoldersToggle">
                    <i class="bi bi-chevron-<?= $isSecondaryActive ? 'up' : 'down' ?> nav-icon me-2" id="moreFoldersIcon"></i>
                    <span class="nav-text">More</span>
                </button>

                <div class="collapse <?= $isSecondaryActive ? 'show' : '' ?>" id="moreFoldersCollapse">
                    <a class="webmail-nav-link nav-pill-item <?= $activeFolder === 'snoozed' ? 'active' : '' ?>" href="<?= app_base_url('public/inbox.php?folder=snoozed') ?>" title="Snoozed">
                        <i class="bi bi-alarm nav-icon"></i>
                        <span class="nav-text">Snoozed</span>
                        <?php if ($snoozedCount > 0): ?>
                            <span class="badge rounded-pill sidebar-count-pill ms-auto"><?= $snoozedCount ?></span>
                        <?php endif; ?>
                    </a>

                    <a class="webmail-nav-link nav-pill-item <?= $activeFolder === 'scheduled' ? 'active' : '' ?>" href="<?= app_base_url('public/scheduled.php') ?>" title="Scheduled">
                        <i class="bi bi-calendar2-check nav-icon"></i>
                        <span class="nav-text">Scheduled</span>
                        <?php if ($scheduledCount > 0): ?>
                            <span class="badge rounded-pill bg-info ms-auto"><?= $scheduledCount ?></span>
                        <?php endif; ?>
                    </a>

                    <a class="webmail-nav-link nav-pill-item <?= $activeFolder === 'archive' ? 'active' : '' ?>" href="<?= app_base_url('public/inbox.php?folder=archive') ?>" title="Archive">
                        <i class="bi bi-archive nav-icon"></i>
                        <span class="nav-text">Archive</span>
                        <?php if ($archiveCount > 0): ?>
                            <span class="badge rounded-pill sidebar-count-pill ms-auto"><?= $archiveCount ?></span>
                        <?php endif; ?>
                    </a>

                    <a class="webmail-nav-link nav-pill-item <?= $activeFolder === 'spam' ? 'active' : '' ?>" href="<?= app_base_url('public/spam.php') ?>" title="Spam">
                        <i class="bi bi-shield-exclamation nav-icon"></i>
                        <span class="nav-text">Spam</span>
                        <?php if ($spamCount > 0): ?>
                            <span class="badge bg-danger rounded-pill ms-auto"><?= $spamCount ?></span>
                        <?php endif; ?>
                    </a>

                    <a class="webmail-nav-link nav-pill-item <?= $activeFolder === 'trash' ? 'active' : '' ?>" href="<?= app_base_url('public/trash.php') ?>" title="Trash">
                        <i class="bi bi-trash2 nav-icon"></i>
                        <span class="nav-text">Trash</span>
                    </a>
                </div>
            </div>

            <!-- Labels Section -->
            <div class="sidebar-section-title d-flex justify-content-between align-items-center">
                <span>LABELS</span>
                <button type="button" class="btn btn-sm btn-link text-muted p-0 border-0" data-bs-toggle="modal" data-bs-target="#createLabelModal" title="Create label" aria-label="Create label"><i class="bi bi-plus-lg fs-6"></i></button>
            </div>
            <div class="nav flex-column mb-2 sidebar-labels-group">
                <?php if (empty($organizationLabels)): ?>
                    <span class="sidebar-muted-note px-3 py-1 text-muted small">No labels yet</span>
                <?php else: ?>
                    <?php foreach ($organizationLabels as $label): ?>
                        <a class="webmail-nav-link <?= $activeFolder === 'label' && $activeLabelId === (int)$label['id'] ? 'active' : '' ?>" href="<?= app_base_url('public/inbox.php?folder=label&label=' . (int)$label['id']) ?>" title="<?= e($label['name']) ?>">
                            <span class="label-dot nav-icon" style="background-color: <?= e($label['color']) ?>;"></span>
                            <span class="nav-text text-truncate"><?= e($label['name']) ?></span>
                            <?php if ((int)$label['email_count'] > 0): ?>
                                <span class="badge rounded-pill sidebar-count-pill ms-auto"><?= (int)$label['email_count'] ?></span>
                            <?php endif; ?>
                        </a>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>

            <!-- Custom Folders Section -->
            <div class="sidebar-section-title d-flex justify-content-between align-items-center">
                <span>FOLDERS</span>
                <button type="button" class="btn btn-sm btn-link text-muted p-0 border-0" data-bs-toggle="modal" data-bs-target="#createFolderModal" title="Create folder" aria-label="Create folder"><i class="bi bi-plus-lg fs-6"></i></button>
            </div>
            <div class="nav flex-column mb-2 sidebar-folders-group">
                <?php if (empty($userFolders)): ?>
                    <button type="button" class="webmail-nav-link text-muted sidebar-empty-action btn btn-link text-start p-0 border-0 w-100" data-bs-toggle="modal" data-bs-target="#createFolderModal" title="Create your first folder">
                        <i class="bi bi-folder2 nav-icon"></i>
                        <span class="nav-text">Create your first folder</span>
                    </button>
                <?php else: ?>
                    <?php foreach ($userFolders as $folder): ?>
                        <a class="webmail-nav-link <?= $activeFolder === 'custom' && $activeCustomFolderId === (int)$folder['id'] ? 'active' : '' ?>" href="<?= app_base_url('public/inbox.php?folder=custom&id=' . (int)$folder['id']) ?>" title="<?= e($folder['name']) ?>">
                            <i class="bi bi-folder2 nav-icon" style="color: <?= e($folder['color']) ?>;"></i>
                            <span class="nav-text text-truncate"><?= e($folder['name']) ?></span>
                            <?php if ((int)$folder['email_count'] > 0): ?>
                                <span class="badge rounded-pill sidebar-count-pill ms-auto"><?= (int)$folder['email_count'] ?></span>
                            <?php endif; ?>
                        </a>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>

            <!-- Workspace Section -->
            <div class="sidebar-section-title">WORKSPACE</div>
            <div class="nav flex-column mb-2 sidebar-workspace-group">
                <a class="webmail-nav-link <?= ($activeFolder ?? '') === 'contacts' ? 'active' : '' ?>" href="<?= app_base_url('public/contacts.php') ?>" title="Contacts">
                    <i class="bi bi-people nav-icon"></i>
                    <span class="nav-text">Contacts</span>
                </a>
            </div>

            <hr class="sidebar-divider my-1">

            <!-- Elevated Settings Row (Mockup Parity) -->
            <div class="mb-2">
                <a class="webmail-nav-link sidebar-settings-pill <?= ($activeFolder ?? '') === 'settings' ? 'active' : '' ?>" href="<?= app_base_url('public/settings.php') ?>" title="Account settings">
                    <i class="bi bi-gear-fill nav-icon settings-icon"></i>
                    <span class="nav-text fw-medium">Account settings</span>
                </a>
            </div>

            <!-- Compact Storage Component (Mockup Parity) -->
            <div class="sidebar-storage-card mt-auto pt-2" id="storageUsageMeter">
                <div class="sidebar-storage-inner p-3 rounded-3 border">
                    <div class="d-flex justify-content-between align-items-center mb-1" style="font-size: 0.8rem;">
                        <span class="fw-semibold text-body">Storage</span>
                        <span class="text-muted" style="font-size: 0.75rem;"><?= format_bytes($userStorageBytes) ?> / <?= format_bytes($storageQuotaBytes) ?></span>
                    </div>
                    <div class="progress mb-1" style="height: 4px; border-radius: 2px;">
                        <?php $storageBarWidth = $userStorageBytes > 0 ? max(2, $storagePercent) : 0; ?>
                        <div class="progress-bar <?= $storagePercent > 80 ? 'bg-danger' : ($storagePercent > 50 ? 'bg-warning' : 'bg-primary') ?>" role="progressbar" style="width: <?= $storageBarWidth ?>%;" aria-valuenow="<?= $storagePercent ?>" aria-valuemin="0" aria-valuemax="100"></div>
                    </div>
                    <div class="text-muted" style="font-size: 0.72rem;">
                        <span><?= $storagePercent ?>% used</span>
                    </div>
                </div>
            </div>
        </nav>

        <!-- Main Content View Area -->
        <main id="main-content" tabindex="-1" class="col-md-9 ms-sm-auto col-lg-10 px-md-4 py-3">
<?php else: ?>
<!-- Unauthenticated Header Wrapper -->
<div class="container py-4">
<?php endif; ?>

<!-- Flash Message Notifications -->
<?php foreach (get_flashes() as $type => $messages): ?>
    <?php foreach ($messages as $msg): ?>
        <div class="alert alert-<?= e($type) ?> alert-dismissible fade show shadow-sm" role="alert">
            <?= e($msg) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endforeach; ?>
<?php endforeach; ?>
