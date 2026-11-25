<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$settings = file_get_contents($root . '/public/settings.php');
$css = file_get_contents($root . '/assets/css/style.css');

$checks = [
    'summary_card_wraps_responsively' => str_contains($settings, 'id="profileSummaryCard"')
        && str_contains($settings, 'flex-column flex-sm-row'),
    'summary_avatar_does_not_shrink' => preg_match('/avatar-circle[^"]*flex-shrink-0/', $settings) === 1,
    'summary_identity_allows_shrink_and_wrap' => str_contains($settings, 'min-w-0')
        && str_contains($settings, 'text-break'),
    'summary_badges_wrap_without_horizontal_push' => !str_contains($settings, '<div class="d-flex gap-2 ms-2">'),
    'summary_metadata_wraps_cleanly_without_splitting_date' => str_contains($settings, 'profile-summary-meta')
        && str_contains($settings, 'text-nowrap'),
    'mobile_settings_clears_fab_and_summary_padding' => str_contains($css, '.settings-container')
        && str_contains($css, 'profile-summary-meta'),
];

$failed = 0;
foreach ($checks as $name => $ok) {
    echo ($ok ? 'PASS ' : 'FAIL ') . $name . PHP_EOL;
    if (!$ok) {
        $failed++;
    }
}

exit($failed > 0 ? 1 : 0);
