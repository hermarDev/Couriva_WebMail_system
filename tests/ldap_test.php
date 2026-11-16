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

$adminEmail = 'ldap.breakglass.' . time() . '@couriva.local';
$adminId = $userModel->create([
    'email'       => $adminEmail,
    'password'    => $password,
    'first_name'  => 'BreakGlass',
    'last_name'   => 'Admin',
    'role'        => 'admin',
    'status'      => 'active',
    'auth_source' => 'local',
    'theme'       => 'light',
]);

// 1. LDAP config disabled
$authServiceDisabled = new \App\Services\AuthService($pdo, ['enabled' => false]);
$resDisabled = $authServiceDisabled->authenticate($testEmail, $password);
assertLdapTest(
    !empty($resDisabled['success']) && $resDisabled['auth_source'] === 'local',
    "LDAP Disabled: Successfully authenticates against local MariaDB with auth_source = local"
);

// 2. LDAP Server unreachable + allow_local_admin_fallback = true:
//    normal role='user' MUST be rejected, while local role='admin' falls back
$authServiceFallback = new \App\Services\AuthService($pdo, [
    'enabled'                    => true,
    'host'                       => '127.0.0.1',
    'port'                       => 38999, // unreachable port
    'use_tls'                    => true,
    'require_tls'                => true,
    'timeout'                    => 1,
    'fallback_to_local'          => true,
    'allow_local_admin_fallback' => true,
]);
$resUserBlocked = $authServiceFallback->authenticate($testEmail, $password);
assertLdapTest(
    empty($resUserBlocked['success']),
    "LDAP Enabled: Normal local user (role=user) is blocked from local fallback when domain server unreachable"
);
$resAdminFallback = $authServiceFallback->authenticate($adminEmail, $password);
assertLdapTest(
    !empty($resAdminFallback['success']) && $resAdminFallback['auth_source'] === 'local',
    "LDAP Server unreachable: Break-glass local admin (role=admin) falls back to local MariaDB authentication"
);

// 3. LDAP config enabled + fallback disabled (both fallback_to_local and allow_local_admin_fallback = false)
$authServiceNoFallback = new \App\Services\AuthService($pdo, [
    'enabled'                    => true,
    'host'                       => '127.0.0.1',
    'port'                       => 38999,
    'use_tls'                    => true,
    'require_tls'                => true,
    'timeout'                    => 1,
    'fallback_to_local'          => false,
    'allow_local_admin_fallback' => false,
]);
$resNoFallback = $authServiceNoFallback->authenticate($adminEmail, $password);
assertLdapTest(
    empty($resNoFallback['success']),
    "LDAP Enabled without fallback: Rejects even local admin when fallback flags are false"
);

// 4. Disabled local user check
$userModel->update($userId, ['status' => 'disabled']);
$resDisabledUser = $authServiceDisabled->authenticate($testEmail, $password);
assertLdapTest(
    empty($resDisabledUser['success']) && str_contains(strtolower($resDisabledUser['message']), 'disabled'),
    "Account Status: Disabled user rejected by AuthService with appropriate status message"
);

// Clean up
$pdo->prepare("DELETE FROM users WHERE id IN (?, ?)")->execute([$userId, $adminId]);

echo "\n=== LDAP Test Results: {$passed} passed, {$failed} failed ===\n";
exit($failed === 0 ? 0 : 1);
