<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$source = file_get_contents($root . '/public/register.php');
$script = is_file($root . '/assets/js/auth-registration.js')
    ? file_get_contents($root . '/assets/js/auth-registration.js')
    : '';

$checks = [
    'registration heading matches contract' => str_contains($source, '<h1>Create account</h1>'),
    'active account flow contains no pending approval copy' => !str_contains($source, 'pending administrator approval'),
    'username field accepts local part only' => (bool) preg_match('/<input\b(?=[^>]*\bname="username")(?=[^>]*\bmaxlength="32")(?=[^>]*\bautocomplete="username")(?=[^>]*\bplaceholder="firstname\.lastname")(?=[^>]*\bpattern="[^"]*")[^>]*>/', $source),
    'username field shows @couriva.local suffix' => str_contains($source, '<span class="input-group-text">@<?= e(app_domain()) ?></span>'),
    'password checklist uses registration selectors' => str_contains($source, 'auth-password-rules') && str_contains($source, 'auth-password-rule'),
    'password checklist states every server rule' => str_contains($source, '10 characters')
        && str_contains($source, 'uppercase')
        && str_contains($source, 'lowercase')
        && str_contains($source, 'number')
        && str_contains($source, 'symbol'),
    'password checklist positioned below confirm password' => strpos($source, 'auth-password-rules') > strpos($source, 'registerConfirmPassword'),
    'password confirmation has match feedback in checklist' => str_contains($source, 'data-rule="match"') && str_contains($source, 'Passwords match'),
    'name fields use visible labels and autocomplete' => str_contains($source, 'autocomplete="given-name"')
        && str_contains($source, 'autocomplete="family-name"'),
    'username and passwords retain password-manager autocomplete' => str_contains($source, 'autocomplete="username"')
        && substr_count($source, 'autocomplete="new-password"') === 2,
    'registration form includes CSRF field' => str_contains($source, 'csrf_field()'),
    'server error remains an alert' => str_contains($source, 'render_inline_alert'),
    'password toggles expose accessible state' => substr_count($source, 'class="password-toggle"') === 2
        && substr_count($source, 'aria-controls="registerPassword"') === 1
        && substr_count($source, 'aria-controls="registerConfirmPassword"') === 1
        && substr_count($source, 'aria-pressed="false"') === 2,
    'registration script is included' => str_contains($source, 'assets/js/auth-registration.js'),
    'registration script implements loading and live feedback' => str_contains($script, 'auth-password-rule')
        && str_contains($script, 'match')
        && str_contains($script, 'is-loading')
        && str_contains($script, 'aria-busy'),
    'server preserves CSRF check' => str_contains($source, 'verify_csrf_token()'),
    'server preserves registration throttle' => str_contains($source, 'registration_is_throttled($pdo)'),
    'server preserves password policy helper' => str_contains($source, 'is_valid_password_complexity($password)'),
    'server preserves username regex' => str_contains($source, "'/^[a-z0-9][a-z0-9._-]{1,30}[a-z0-9]$/'"),
    'server preserves username suffix normalization' => str_contains($source, "preg_replace('/@.*$/', ''"),
    'server creates active account' => str_contains($source, "'status' => 'active'"),
    'server preserves duplicate email check' => str_contains($source, '$userModel->findByEmail($email)'),
    'submit action uses contract label' => str_contains($source, 'Create account'),
    'no segmented nav pills' => !str_contains($source, 'auth-nav-pills'),
    'no Sign in terminology' => !str_contains($source, 'Sign in'),
    'login link at bottom uses Login' => str_contains($source, 'Already have an account?') && str_contains($source, 'Login'),
    'password rule animation classes present' => str_contains($source, 'auth-password-rule-status')
        && (str_contains($script, 'auth-password-rule.met') || str_contains($script, 'classList.add') || str_contains($script, 'classList.toggle')),
];

$failed = 0;
foreach ($checks as $name => $passed) {
    echo ($passed ? 'PASS ' : 'FAIL ') . $name . PHP_EOL;
    $failed += (int)!$passed;
}

exit($failed ? 1 : 0);