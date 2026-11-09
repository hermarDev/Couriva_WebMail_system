<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$inbox = file_get_contents($root . '/public/inbox.php');
$css = file_get_contents($root . '/assets/css/style.css');
$js = file_get_contents($root . '/assets/js/app.js');

$checks = [
    // Task 1: Universal Gmail Action Bar & Contextual Selection Toolbar
    'task1_gmail_action_bar' => str_contains($inbox, 'id="gmailActionBar"') && !str_contains($inbox, 'Showing 1 of 1 messages'),
    'task1_contextual_actions_present' => str_contains($inbox, 'class="contextual-actions') && str_contains($inbox, 'data-batch-action="archive"') && str_contains($inbox, 'data-batch-action="trash"'),
    'task1_action_bar_pagination' => str_contains($inbox, 'class="action-bar-pagination') && str_contains($inbox, 'bi-chevron-left') && str_contains($inbox, 'bi-chevron-right'),
    'task1_action_bar_css' => str_contains($css, '#gmailActionBar') && str_contains($css, '.contextual-actions'),
    'task1_contextual_js' => str_contains($js, 'contextual-actions') || str_contains($js, 'gmailActionBar'),

    // Task 2: Hairline-Divided Continuous Email Rows & Desktop Hover Actions
    'task2_hairline_rows_css' => str_contains($css, '.mail-item-row') && str_contains($css, 'border-bottom: 1px solid var(--border)'),
    'task2_row_selected_css' => str_contains($css, '.mail-item-row.selected'),
    'task2_hover_actions_swap_css' => str_contains($css, '.mail-item-row:hover .mail-quick-actions') && str_contains($css, '.mail-item-row:hover .mail-date'),
    'task2_row_selection_js' => str_contains($js, "classList.toggle('selected'") || str_contains($js, "classList.add('selected'"),
    'task2_split_pane_inline_js' => str_contains($js, 'readingPaneContent') && str_contains($js, 'get_email.php'),

    // Task 3: Material 3 Category Tabs (Primary, Promotions, Updates)
    'task3_category_tabs_inbox' => str_contains($inbox, 'id="inboxCategoryTabs"') && str_contains($inbox, 'id="tabPrimary"') && str_contains($inbox, 'id="tabPromotions"') && str_contains($inbox, 'id="tabUpdates"'),
    'task3_category_tabs_css' => str_contains($css, '#inboxCategoryTabs') && str_contains($css, '.category-tab-item'),
    'task3_category_tabs_js' => str_contains($js, 'inboxCategoryTabs') || str_contains($js, 'category-tab-item'),

    // Task 4: Material 3 Header Polish, Circular Avatar Button & Google Apps Launcher
    'task4_circular_avatar_button' => str_contains(file_get_contents($root . '/includes/header.php'), 'id="userProfileBtn"') && str_contains(file_get_contents($root . '/includes/header.php'), 'user-avatar-btn'),
    'task4_google_apps_launcher' => str_contains(file_get_contents($root . '/includes/header.php'), 'id="googleAppsLauncherBtn"') && str_contains(file_get_contents($root . '/includes/header.php'), 'id="googleAppsMenu"'),
    'task4_google_apps_css' => str_contains($css, '#googleAppsMenu') && str_contains($css, '.app-launcher-grid') && str_contains($css, '.app-launcher-tile'),
];

$failed = 0;
foreach ($checks as $name => $pass) {
    if ($pass) {
        echo "PASS {$name}\n";
    } else {
        echo "FAIL {$name}\n";
        $failed++;
    }
}

exit($failed > 0 ? 1 : 0);
