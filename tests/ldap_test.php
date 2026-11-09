<?php
/**
 * Couriva - Active Directory / LDAP Authentication Adapter Smoke Test
 */

define('WEBMAIL_INIT', true);

require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../app/Models/User.php';
require_once __DIR__ . '/../app/Services/AuthService.php';

$pdo = getDbConnection();
$passed = 0;
$failed = 0;

function assertLdapTest(bool $condition, string $testName, string $details = '')
{
    global $passed, $failed;
    if ($condition) {
        $passed++;
        echo "[\033[32mPASS\033[0m] {$testName}\n";
    } else {
        $failed++;
        echo "[\033[31mFAIL\033[0m] {$testName}" . ($details ? ": {$details}" : "") . "\n";
    }
}

echo "=== Starting LDAP / Active Directory Adapter Smoke Test ===\n\n";

$userModel = new \App\Models\User($pdo);
$testEmail = 'ldap.fallback.' . time() . '@couriva.local';
$password = 'LocalSecurePass123!';

$userId = $userModel->create([
    'email'      => $testEmail,
    'password'   => $password,
    'first_name' => 'Fallback',
    'last_name'  => 'User',
    'role'       => 'user',
    'status'     => 'active',
    'theme'      => 'light',
]);

// 1. LDAP config disabled
$authServiceDisabled = new \App\Services\AuthService($pdo, ['enabled' => false]);
$resDisabled = $authServiceDisabled->authenticate($testEmail, $password);
assertLdapTest(
    !empty($resDisabled['success']) && $resDisabled['auth_source'] === 'local',
    "LDAP Disabled: Successfully authenticates against local MariaDB with auth_source = local"
);

// 2. LDAP config enabled with non-existent server + fallback_to_local = true
$authServiceFallback = new \App\Services\AuthService($pdo, [
    'enabled'           => true,
    'host'              => '127.0.0.1',
    'port'              => 38999, // unreachable port
    'timeout'           => 1,
    'fallback_to_local' => true,
]);
$resFallback = $authServiceFallback->authenticate($testEmail, $password);
assertLdapTest(
    !empty($resFallback['success']) && $resFallback['auth_source'] === 'local',
    "LDAP Server unreachable: Gracefully falls back to local MariaDB authentication"
);

// 3. LDAP config enabled + fallback_to_local = false
$authServiceNoFallback = new \App\Services\AuthService($pdo, [
    'enabled'           => true,
    'host'              => '127.0.0.1',
    'port'              => 38999,
    'timeout'           => 1,
    'fallback_to_local' => false,
]);
$resNoFallback = $authServiceNoFallback->authenticate($testEmail, $password);
assertLdapTest(
    empty($resNoFallback['success']),
    "LDAP Enabled without fallback: Rejects authentication when domain server unreachable"
);

// 4. Disabled local user check
$userModel->update($userId, ['status' => 'disabled']);
$resDisabledUser = $authServiceDisabled->authenticate($testEmail, $password);
assertLdapTest(
    empty($resDisabledUser['success']) && str_contains(strtolower($resDisabledUser['message']), 'disabled'),
    "Account Status: Disabled user rejected by AuthService with appropriate status message"
);

// Clean up
$pdo->prepare("DELETE FROM users WHERE id = ?")->execute([$userId]);

echo "\n=== LDAP Test Results: {$passed} passed, {$failed} failed ===\n";
exit($failed === 0 ? 0 : 1);
