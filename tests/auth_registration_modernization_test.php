<?php
/**
 * Couriva - Auth & Registration Modernization Tests
 */
define('WEBMAIL_INIT', true);
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/database.php';
require_once dirname(__DIR__) . '/app/Models/User.php';
require_once dirname(__DIR__) . '/app/Services/AuthService.php';

$auth = new \App\Services\AuthService($pdo);
$userModel = new \App\Models\User($pdo);
$passed = 0;
$failed = 0;

function check(string $name, bool $condition): void {
    global $passed, $failed;
    if ($condition) { echo "PASS $name\n"; $passed++; }
    else { echo "FAIL $name\n"; $failed++; }
}

// Test 1: Bare username rejected
$r = $auth->authenticate('john.doe', 'anything');
check('bare username rejected', !$r['success'] && str_contains($r['message'], 'valid email'));

// Test 2: Invalid email format rejected
$r = $auth->authenticate('not-an-email', 'anything');
check('invalid email rejected', !$r['success'] && str_contains($r['message'], 'valid email'));

// Test 3: Valid email proceeds to credential check
$r = $auth->authenticate('john.doe@couriva.local', 'wrongpassword');
check('valid email reaches credential check', !$r['success'] && $r['message'] !== 'Please enter a valid email address.');

// Test 4: Registration creates active user
$testEmail = 'unit.test.' . time() . '@couriva.local';
$newId = $userModel->create([
    'email' => $testEmail,
    'password' => 'Test-Password-123!',
    'first_name' => 'Unit',
    'last_name' => 'Test',
    'role' => 'user',
    'status' => 'active',
    'timezone' => 'UTC',
    'theme' => 'light',
    'must_change_password' => 0,
]);
$user = $userModel->findByEmail($testEmail);
check('instant registration creates active user', $user && $user['status'] === 'active');

// Test 5: Newly created user can authenticate
$r = $auth->authenticate($testEmail, 'Test-Password-123!');
check('active user can authenticate', $r['success'] === true);

// Cleanup
$pdo->prepare('DELETE FROM users WHERE email = ?')->execute([$testEmail]);

echo "\n=== Results: $passed passed, $failed failed ===\n";
exit($failed > 0 ? 1 : 0);
