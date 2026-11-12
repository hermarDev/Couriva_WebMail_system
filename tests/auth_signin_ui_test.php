<?php
/**
 * Couriva - Sign-in UI regression test
 *
 * Run: php tests/auth_signin_ui_test.php
 */

declare(strict_types=1);

$root = dirname(__DIR__);
$loginPath = $root . '/public/login.php';
$scriptPath = $root . '/assets/js/auth-signin.js';

$login = (string)file_get_contents($loginPath);
$script = is_file($scriptPath) ? (string)file_get_contents($scriptPath) : '';

$checks = [
    'login heading says Login' => str_contains($login, '<h1>Login to your Couriva workspace</h1>'),
    'login button says Login' => str_contains($login, 'type="submit"')
        && str_contains($login, 'auth-submit')
        && str_contains($login, '>Login</button>'),
    'no segmented nav pills' => !str_contains($login, 'auth-nav-pills'),
    'no Sign in terminology' => !str_contains($login, 'Sign in'),
    'create account link at bottom' => str_contains($login, "Don't have an account?") && str_contains($login, 'Create account'),
    'administrator-managed recovery note present' => str_contains($login, 'auth-recovery-note')
        && str_contains($login, 'Password resets are managed by a Couriva administrator.'),
    'no forgot or reset endpoint linked' => !preg_match('/href\s*=\s*["\'][^"\']*(forgot|reset\.php)/i', $login),
    'email field keeps type, name, autocomplete and label' => preg_match('/<input\b[^>]*type="email"[^>]*>/i', $login)
        && str_contains($login, 'name="email"')
        && str_contains($login, 'autocomplete="email"')
        && str_contains($login, 'for="email"')
        && str_contains($login, '>Email address</label>'),
    'email placeholder is neutral' => str_contains($login, 'placeholder="name@couriva.local"'),
    'email input group keeps no decorative @ addon' => !str_contains($login, 'bi-at'),
    'password field keeps type, name, autocomplete and label' => preg_match('/<input\b[^>]*type="password"[^>]*>/i', $login)
        && str_contains($login, 'name="password"')
        && str_contains($login, 'autocomplete="current-password"')
        && str_contains($login, 'for="password"'),
    'password toggle exposes accessible state' => str_contains($login, 'password-toggle')
        && str_contains($login, 'aria-label="Show password"')
        && str_contains($login, 'aria-controls="password"')
        && str_contains($login, 'aria-pressed="false"'),
    'CSRF field rendered' => str_contains($login, 'csrf_field()'),
    'server error renders as persistent alert' => str_contains($login, 'role="alert"')
        && str_contains($login, 'alert alert-danger'),
    'sign-in script loaded only by this page' => str_contains($login, 'assets/js/auth-signin.js'),
    'server CSRF verification preserved' => str_contains($login, 'verify_csrf_token()'),
    'server login call preserved' => str_contains($login, 'attempt_login('),
    'local script handles password visibility and loading state' => str_contains($script, 'password-toggle')
        && str_contains($script, 'is-loading')
        && str_contains($script, 'aria-busy')
        && str_contains($script, 'Signing in')
        && str_contains($script, 'pageshow'),
];

$failed = 0;
foreach ($checks as $name => $passed) {
    echo ($passed ? 'PASS ' : 'FAIL ') . $name . PHP_EOL;
    $failed += (int)!$passed;
}

echo sprintf("\n=== %d passed, %d failed ===\n", count($checks) - $failed, $failed);
exit($failed > 0 ? 1 : 0);
