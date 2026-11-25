<?php
declare(strict_types=1);

namespace App\Services {
    // Stubs shadow ext-ldap inside this namespace so bind order is testable without a domain controller.
    function ldap_connect(...$a) { return 'stub'; }
    function ldap_set_option(...$a) { return true; }
    function ldap_start_tls(...$a) { return true; }
    function ldap_unbind(...$a) { return true; }
    function ldap_search(...$a) {
        return !empty($GLOBALS['ldap_stub_search_fail']) ? false : 'stub-search';
    }
    function ldap_get_entries($c, $r) {
        return $GLOBALS['ldap_stub_entries'] ?? ['count' => 0];
    }
    function ldap_escape(string $v, string $i = '', int $f = 0): string { return $v; }
    function ldap_bind($c, $dn = null, $pw = null) {
        $GLOBALS['ldap_stub_binds'][] = $dn;
        return $dn !== null && in_array($dn, $GLOBALS['ldap_stub_ok'] ?? [], true);
    }
}

namespace {

foreach (['LDAP_OPT_PROTOCOL_VERSION' => 17, 'LDAP_OPT_REFERRALS' => 8, 'LDAP_OPT_NETWORK_TIMEOUT' => 20485, 'LDAP_ESCAPE_FILTER' => 1] as $k => $v) {
    defined($k) || define($k, $v);
}

// function_exists() in AuthService looks in the global namespace; satisfy it when ext-ldap is absent.
if (!function_exists('ldap_connect')) {
    function ldap_connect(...$a) { return 'stub'; }
}

define('WEBMAIL_INIT', true);

require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../app/Models/User.php';
require_once __DIR__ . '/../app/Services/AuthService.php';

$pdo = getDbConnection();
$userModel = new \App\Models\User($pdo);
$passed = 0;
$failed = 0;
$createdIds = [];

function checkAdds(bool $ok, string $name, string $detail = ''): void
{
    global $passed, $failed;
    if ($ok) {
        $passed++;
        echo "[PASS] {$name}\n";
    } else {
        $failed++;
        echo "[FAIL] {$name}" . ($detail !== '' ? ": {$detail}" : '') . "\n";
    }
}

try {
    $suffix = bin2hex(random_bytes(4));

    // 1. User::create persists auth_source='ldap'
    $ldapUserId = $userModel->create([
        'email' => "ad.firstlogin.{$suffix}@couriva.local",
        'password' => bin2hex(random_bytes(16)),
        'first_name' => 'Ad',
        'last_name' => 'First',
        'role' => 'user',
        'status' => 'active',
        'auth_source' => 'ldap',
        'must_change_password' => 0,
    ]);
    $createdIds[] = $ldapUserId;
    $fetched = $userModel->findById($ldapUserId);
    checkAdds(($fetched['auth_source'] ?? '') === 'ldap', 'User::create persists auth_source=ldap on first insert', 'got ' . ($fetched['auth_source'] ?? 'null'));

    // 2. User::update persists theme='system'
    $userModel->update($ldapUserId, ['theme' => 'system']);
    $fetchedAfterTheme = $userModel->findById($ldapUserId);
    checkAdds(($fetchedAfterTheme['theme'] ?? '') === 'system', 'User::update persists theme=system', 'got ' . ($fetchedAfterTheme['theme'] ?? 'null'));

    // 3. AuthService::syncLdapUserToDatabase assigns auth_source='ldap' on auto-create
    $authService = new \App\Services\AuthService($pdo, [
        'enabled' => true,
        'auto_create_user' => true,
        'default_role' => 'user',
    ]);
    $syncMethod = (new ReflectionClass($authService))->getMethod('syncLdapUserToDatabase');
    $syncMethod->setAccessible(true);
    $syncEmail = "ad.autoprovision.{$suffix}@couriva.local";
    $syncGuid = 'aaaa1111-2222-3333-4444-' . str_pad(substr($suffix, 0, 8), 12, '0', STR_PAD_LEFT);
    $syncRes = $syncMethod->invoke($authService, $syncEmail, "ad.autoprovision.{$suffix}", [
        'mail' => [$syncEmail],
        'givenname' => ['Auto'],
        'sn' => ['Provisioned'],
        'objectguid' => [$syncGuid],
    ]);
    if (!empty($syncRes['user']['id'])) {
        $createdIds[] = (int)$syncRes['user']['id'];
    }
    checkAdds(
        !empty($syncRes['success']) && (($syncRes['user']['auth_source'] ?? '') === 'ldap'),
        'AuthService auto-provisioned LDAP user has auth_source=ldap immediately',
        'got ' . ($syncRes['user']['auth_source'] ?? 'null')
    );

    // 4. public/settings.php rejects password change for auth_source='ldap'
    $beforeHash = $fetched['password'];
    $csrf = bin2hex(random_bytes(16));
    $script = dirname(__DIR__) . '/public/settings.php';
    $runner = sprintf(
        '$_SERVER["REQUEST_METHOD"]="POST";$_SERVER["SCRIPT_FILENAME"]=%s;$_SERVER["REMOTE_ADDR"]="127.0.0.1";'
        . 'session_start();$_SESSION=%s;$_POST=%s;require %s;',
        var_export($script, true),
        var_export([
            'user_id' => $ldapUserId,
            'user_email' => $fetched['email'],
            'user_role' => 'user',
            'user_first_name' => 'Ad',
            'user_last_name' => 'First',
            'session_epoch' => (int)$fetched['session_epoch'],
            'must_change_password' => 0,
            'auth_time' => time(),
            'last_activity' => time(),
            'csrf_token' => $csrf,
        ], true),
        var_export([
            'csrf_token' => $csrf,
            'action' => 'password',
            'current_password' => 'Anything123!',
            'new_password' => 'NewValidPass123!',
            'confirm_password' => 'NewValidPass123!',
        ], true),
        var_export($script, true)
    );
    $cmd = 'php -r ' . escapeshellarg($runner);
    $output = shell_exec($cmd) ?? '';
    $afterUser = $userModel->findById($ldapUserId);
    checkAdds(
        str_contains($output, 'Active Directory') && $afterUser['password'] === $beforeHash,
        'public/settings.php blocks password change for LDAP users and preserves password hash'
    );

    // 5. public/change-password.php blocks password change for LDAP users even if must_change_password=1
    $pdo->prepare("UPDATE users SET must_change_password = 1 WHERE id = ?")->execute([$ldapUserId]);
    $cpScript = dirname(__DIR__) . '/public/change-password.php';
    $cpRunner = sprintf(
        '$_SERVER["REQUEST_METHOD"]="GET";$_SERVER["SCRIPT_FILENAME"]=%s;$_SERVER["REMOTE_ADDR"]="127.0.0.1";'
        . 'session_start();$_SESSION=%s;require %s;',
        var_export($cpScript, true),
        var_export([
            'user_id' => $ldapUserId,
            'user_email' => $fetched['email'],
            'user_role' => 'user',
            'user_first_name' => 'Ad',
            'user_last_name' => 'First',
            'session_epoch' => (int)$fetched['session_epoch'],
            'must_change_password' => 1,
            'auth_time' => time(),
            'last_activity' => time(),
            'csrf_token' => $csrf,
        ], true),
        var_export($cpScript, true)
    );
    $cpOut = shell_exec('php -r ' . escapeshellarg($cpRunner)) ?? '';
    checkAdds(
        str_contains($cpOut, 'Active Directory') && !str_contains($cpOut, 'id="current_password"'),
        'public/change-password.php shows AD guidance instead of local password form for LDAP users'
    );
    // 6. NetBIOS bind (COURIVA\user) is tried before DNS (couriva.local\user), after UPN
    $adUser = 'ad.bind.' . $suffix;
    $adEmail = "{$adUser}@couriva.local";
    $adGuid = 'bbbb1111-2222-3333-4444-' . str_pad(substr($suffix, 0, 8), 12, '0', STR_PAD_LEFT);
    $adId = $userModel->create([
        'email' => $adEmail, 'password' => 'LocalPass123!', 'first_name' => 'Ad', 'last_name' => 'Bind',
        'role' => 'user', 'status' => 'active', 'auth_source' => 'ldap', 'ad_object_guid' => $adGuid, 'must_change_password' => 0,
    ]);
    $createdIds[] = $adId;
    $bindSvc = new \App\Services\AuthService($pdo, [
        'enabled' => true,
        'domain' => 'couriva.local',
        'use_tls' => true,
        'require_tls' => true,
        'allowed_ou_dn' => 'OU=CourivaUsers,DC=couriva,DC=local',
    ]);
    $GLOBALS['ldap_stub_entries'] = [
        'count' => 1,
        0 => [
            'dn' => "CN={$adUser},OU=CourivaUsers,DC=couriva,DC=local",
            'mail' => [$adEmail],
            'givenname' => ['Ad'],
            'sn' => ['Bind'],
            'objectguid' => [$adGuid],
            'useraccountcontrol' => ['512'],
        ],
    ];
    $GLOBALS['ldap_stub_binds'] = [];
    $GLOBALS['ldap_stub_ok'] = ["COURIVA\\{$adUser}", "CN={$adUser},OU=CourivaUsers,DC=couriva,DC=local"];
    $r = $bindSvc->attemptLdapAuth($adEmail, 'DomainPass!1');
    checkAdds(
        !empty($r['success']) && $GLOBALS['ldap_stub_binds'] === [$adEmail, "COURIVA\\{$adUser}", "CN={$adUser},OU=CourivaUsers,DC=couriva,DC=local"],
        'attemptLdapAuth binds NetBIOS COURIVA\\user after UPN and stops on success',
        json_encode($GLOBALS['ldap_stub_binds'])
    );
    $GLOBALS['ldap_stub_binds'] = [];
    $GLOBALS['ldap_stub_ok'] = ["couriva.local\\{$adUser}", "CN={$adUser},OU=CourivaUsers,DC=couriva,DC=local"];
    $r = $bindSvc->attemptLdapAuth($adEmail, 'DomainPass!1');
    checkAdds(
        !empty($r['success']) && $GLOBALS['ldap_stub_binds'] === [$adEmail, "COURIVA\\{$adUser}", "couriva.local\\{$adUser}", "CN={$adUser},OU=CourivaUsers,DC=couriva,DC=local"],
        'attemptLdapAuth falls through NetBIOS to DNS domain\\user bind',
        json_encode($GLOBALS['ldap_stub_binds'])
    );

    // 7. AD users never fall back to local password auth; only break-glass local admin falls back when LDAP enabled
    $failingLdap = fn(array $cfg) => new class($pdo, $cfg) extends \App\Services\AuthService {
        public function attemptLdapAuth(string $email, string $password): array
        {
            return ['success' => false, 'message' => 'LDAP credentials rejected.'];
        }
    };
    $localEmail = "local.user.{$suffix}@couriva.local";
    $localId = $userModel->create([
        'email' => $localEmail, 'password' => 'LocalPass123!', 'first_name' => 'Lo', 'last_name' => 'Cal',
        'role' => 'user', 'status' => 'active', 'auth_source' => 'local', 'must_change_password' => 0,
    ]);
    $createdIds[] = $localId;
    $localAdminEmail = "local.admin.{$suffix}@couriva.local";
    $localAdminId = $userModel->create([
        'email' => $localAdminEmail, 'password' => 'AdminPass123!', 'first_name' => 'Break', 'last_name' => 'Admin',
        'role' => 'admin', 'status' => 'active', 'auth_source' => 'local', 'must_change_password' => 0,
    ]);
    $createdIds[] = $localAdminId;
    $svc = $failingLdap(['enabled' => true, 'fallback_to_local' => true, 'allow_local_admin_fallback' => true]);
    $res = $svc->authenticate($adEmail, 'LocalPass123!');
    checkAdds(empty($res['success']), 'authenticate blocks AD user local-password fallback when LDAP enabled + fallback_to_local');
    $svcOff = new \App\Services\AuthService($pdo, ['enabled' => false]);
    $res = $svcOff->authenticate($adEmail, 'LocalPass123!');
    checkAdds(empty($res['success']), 'authenticate blocks AD user local-password auth when LDAP disabled');
    $res = $svc->authenticate($localEmail, 'LocalPass123!');
    checkAdds(empty($res['success']), 'authenticate blocks normal local user (role=user) fallback when LDAP enabled');
    $res = $svc->authenticate($localAdminEmail, 'AdminPass123!');
    checkAdds(!empty($res['success']) && ($res['auth_source'] ?? '') === 'local', 'authenticate allows break-glass local admin fallback when LDAP enabled');
    $res = $svcOff->authenticate($localEmail, 'LocalPass123!');
    checkAdds(!empty($res['success']) && ($res['auth_source'] ?? '') === 'local', 'authenticate allows local user when LDAP disabled');
} finally {
    foreach ($createdIds as $id) {
        $pdo->prepare("DELETE FROM system_logs WHERE user_id = ?")->execute([$id]);
        $pdo->prepare("DELETE FROM users WHERE id = ?")->execute([$id]);
    }
}

echo "\nResults: {$passed} passed, {$failed} failed\n";
exit($failed === 0 ? 0 : 1);

}
