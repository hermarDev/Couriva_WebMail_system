<?php
declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/app/Services/MailService.php';
$header = file_get_contents($root . '/includes/header.php');
$css = file_get_contents($root . '/assets/css/style.css');
$js = file_get_contents($root . '/assets/js/app.js');

$checks = [
    // Task 1: Material Shell & Top Navigation
    'task1_centered_pill_search_bar' => str_contains($header, 'id="gmailSearchBar"') && str_contains($header, 'placeholder="Search in mail"'),
    'task1_search_filter_dropdown' => str_contains($header, 'id="searchFilterDropdown"') && str_contains($header, 'data-bs-toggle="dropdown"'),
    'task1_quick_settings_drawer' => str_contains($header, 'id="quickSettingsOffcanvas"') && str_contains($header, 'offcanvas-end'),
    'task1_google_style_profile_card' => str_contains($header, 'id="userProfileMenu"') && str_contains($header, 'google-profile-card'),
    'task1_mini_rail_sidebar_css' => str_contains($css, '.sidebar-mini') && str_contains($css, 'var(--sidebar-mini-width)'),
    'task1_mini_rail_sidebar_js' => str_contains($js, 'sidebar-mini') && str_contains($js, 'couriva_sidebar_mini'),

    // Task 2: Left Sidebar Navigation & Storage Quota Meter
    'task2_compose_fab_pill' => str_contains($header, 'btn-compose-fab') && str_contains($header, 'id="btnOpenCompose"'),
    'task2_more_folders_collapse' => str_contains($header, 'id="moreFoldersCollapse"') && str_contains($header, 'data-bs-target="#moreFoldersCollapse"'),
    'task2_storage_usage_meter' => str_contains($header, 'id="storageUsageMeter"') && str_contains($header, 'progress-bar'),
    'task2_nav_pill_styling' => str_contains($css, '.btn-compose-fab') && str_contains($css, '.nav-pill-item'),

    // Task 3: Inbox Density Modes, Category Tabs & Attachment Chips
    'task3_select_all_dropdown' => str_contains(file_get_contents($root . '/public/inbox.php'), 'id="selectAllDropdownMenu"') && str_contains(file_get_contents($root . '/public/inbox.php'), 'data-select="unread"'),
    'task3_density_css_classes' => str_contains($css, '.density-default') && str_contains($css, '.density-comfortable') && str_contains($css, '.density-compact'),
    'task3_attachment_chips' => str_contains($css, '.attachment-chip') && str_contains(file_get_contents($root . '/public/inbox.php'), 'attachment-chip'),
    'task3_select_all_js' => str_contains($js, 'data-select') && str_contains($js, 'selectAllDropdown'),

    // Task 4: Floating Docked Multi-Window Compose Experience
    'task4_docked_compose_container' => str_contains(file_get_contents($root . '/includes/footer.php'), 'id="dockedComposeContainer"') && str_contains(file_get_contents($root . '/includes/footer.php'), 'id="dockedComposeHeader"'),
    'task4_docked_compose_controls' => str_contains(file_get_contents($root . '/includes/footer.php'), 'compose-minimize-btn') && str_contains(file_get_contents($root . '/includes/footer.php'), 'compose-maximize-btn'),
    'task4_docked_compose_css' => str_contains($css, '#dockedComposeContainer') && str_contains($css, '.docked-minimized') && str_contains($css, '.docked-fullscreen'),
    'task4_docked_compose_js' => str_contains($js, 'dockedComposeContainer') && str_contains($js, 'btnOpenCompose'),

    // Task 5: Conversation Threading Backend & Thread Reading View
    'task5_service_conversation_thread' => method_exists('\App\Services\MailService', 'getConversationThread'),
    'task5_read_thread_accordion' => str_contains(file_get_contents($root . '/public/read.php'), 'thread-message-card'),
    'task5_inline_reply_container' => str_contains(file_get_contents($root . '/public/read.php'), 'id="inlineReplyContainer"'),
    'task5_thread_css' => str_contains($css, '.thread-message-card') && str_contains($css, '.inline-reply-box'),

    // Task 6: Gmail Keyboard Shortcuts, SnackBar Undo & Shortcut Help Modal
    'task6_shortcuts_modal' => str_contains(file_get_contents($root . '/includes/footer.php'), 'id="keyboardShortcutsModal"'),
    'task6_snackbar_undo' => str_contains(file_get_contents($root . '/includes/footer.php'), 'id="gmailSnackbar"') && str_contains(file_get_contents($root . '/includes/footer.php'), 'snackbar-undo-btn'),
    'task6_snackbar_css' => str_contains($css, '#gmailSnackbar') && str_contains($css, '.snackbar-undo-btn'),
    'task6_shortcuts_js' => str_contains($js, 'keyboardShortcutsModal') && str_contains($js, 'gmailSnackbar'),
];

$failed = 0;
foreach ($checks as $name => $passed) {
    echo ($passed ? 'PASS ' : 'FAIL ') . $name . PHP_EOL;
    $failed += (int)!$passed;
}

exit($failed ? 1 : 0);
