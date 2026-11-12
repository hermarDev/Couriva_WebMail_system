<?php
declare(strict_types=1);
$root = dirname(__DIR__);
$login = file_get_contents($root . '/public/login.php');
$js = file_get_contents($root . '/assets/js/app.js');
$header = file_get_contents($root . '/includes/header.php');
$passwordPages = ['public/login.php', 'public/change-password.php', 'public/settings.php', 'admin/create-user.php', 'admin/edit-user.php', 'admin/users.php'];
$passwordCoverage = true;
foreach ($passwordPages as $page) {
    $source = file_get_contents($root . '/' . $page);
    preg_match_all('/<input\b[^>]*type="password"[^>]*>/i', $source, $inputs);
    preg_match_all('/class="[^"]*password-toggle\b/i', $source, $buttons);
    if (count($inputs[0]) !== count($buttons[0])) {
        $passwordCoverage = false;
        echo 'Missing password toggle in ' . $page . PHP_EOL;
    }
}
require_once $root . '/includes/functions.php';
$input = ['q' => 'two words & more', 'date_from' => '2026-01-01', 'date_to' => '2026-12-31', 'has_attachment' => '0', 'is_unread' => '1', 'is_starred' => '', 'unexpected' => 'discard'];
$pagination = function_exists('mail_pagination_query') ? mail_pagination_query(2, $input, 'starred') : '';
parse_str($pagination, $paginationParams);
$checks = [
    'theme API and settings support system option' => str_contains(file_get_contents($root . '/public/api/theme.php'), "'system'") && str_contains(file_get_contents($root . '/public/settings.php'), 'value="system"'),
    'pagination retains filters including zero and folder' => $paginationParams === ['page' => '2', 'folder' => 'starred', 'q' => 'two words & more', 'date_from' => '2026-01-01', 'date_to' => '2026-12-31', 'has_attachment' => '0', 'is_unread' => '1'],
    'compose has keyboard attachment control and editor label' => str_contains(file_get_contents($root . '/public/compose.php'), 'id="browseAttachments"') && str_contains(file_get_contents($root . '/public/compose.php'), 'aria-label="Message body"'),
    'admin does not claim unmeasured health or capacity' => !str_contains(file_get_contents($root . '/admin/index.php'), 'Services Healthy') && !str_contains(file_get_contents($root . '/admin/index.php'), 'Available Storage Pool'),
    'forced password page avoids marketing hero' => !str_contains(file_get_contents($root . '/public/change-password.php'), 'Protect your new beginning.'),
    'each password field has a toggle' => $passwordCoverage,
    'login uses a task-focused heading' => str_contains($login, '<h1>Login to your Couriva workspace</h1>') && !str_contains($login, 'Make space for better conversations.'),
    'unsupported remember me is removed' => !str_contains($login, 'name="remember_me"'),
    'password has an accessible icon toggle' => str_contains($login, 'password-toggle') && str_contains($js, 'password-toggle'),
    'navigation provides a skip link' => str_contains($header, 'href="#main-content"') && str_contains($header, 'id="main-content"'),
];
$failed = 0;
foreach ($checks as $name => $passed) {
    echo ($passed ? 'PASS ' : 'FAIL ') . $name . PHP_EOL;
    $failed += (int)!$passed;
}
exit($failed ? 1 : 0);
