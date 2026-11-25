<?php
declare(strict_types=1);

namespace App\Services {
    function ldap_connect(...$args)
    {
        $state = &$GLOBALS['ad_test_state'];
        $state['connect_calls'][] = $args;
        if (!empty($state['connect_fail'])) {
            return false;
        }
        return 'stub-ldap-conn';
    }

    function ldap_set_option($conn, int $option, $value): bool
    {
        return true;
    }

    function ldap_start_tls($conn): bool
    {
        $state = &$GLOBALS['ad_test_state'];
        $state['start_tls_calls']++;
        return empty($state['start_tls_fail']);
    }

    function ldap_unbind($conn): bool
    {
        return true;
    }

    function ldap_escape(string $value, string $ignore = '', int $flags = 0): string
    {
        return $value;
    }

    function ldap_bind($conn, ?string $dn = null, ?string $password = null): bool
    {
        $state = &$GLOBALS['ad_test_state'];
        $state['binds'][] = $dn;
        if (!empty($state['bind_ok']) && $dn !== null && in_array($dn, $state['bind_ok'], true)) {
            return true;
        }
        return false;
    }

    function ldap_search($conn, string $baseDn, string $filter, array $attributes = [])
    {
        $state = &$GLOBALS['ad_test_state'];
        $state['searches'][] = [
            'base_dn' => $baseDn,
            'filter' => $filter,
            'attributes' => $attributes,
        ];
        if (!empty($state['search_fail'])) {
            return false;
        }
        return 'stub-search-result';
    }

    function ldap_get_entries($conn, $result)
    {
        $state = &$GLOBALS['ad_test_state'];
        if ($result === false) {
            return false;
        }
        return $state['entries'] ?? ['count' => 0];
    }
}

namespace {
    foreach ([
        'LDAP_OPT_PROTOCOL_VERSION' => 17,
        'LDAP_OPT_REFERRALS' => 8,
        'LDAP_OPT_NETWORK_TIMEOUT' => 20485,
        'LDAP_ESCAPE_FILTER' => 1,
    ] as $k => $v) {
        defined($k) || define($k, $v);
    }

    if (!function_exists('ldap_connect')) {
        function ldap_connect(...$args)
        {
            return 'stub-ldap-conn';
        }
    }

    define('WEBMAIL_INIT', true);

    require_once __DIR__ . '/../app/Models/User.php';
    require_once __DIR__ . '/../app/Services/AuthService.php';

    function makeSqlitePdo(): PDO
    {
        $pdo = new PDO('sqlite::memory:', null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $pdo->sqliteCreateFunction('NOW', static fn(): string => '2026-11-16 00:00:00', 0);
        $pdo->exec("
            CREATE TABLE users (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                email TEXT NOT NULL UNIQUE,
                password TEXT NOT NULL,
                first_name TEXT NOT NULL,
                last_name TEXT NOT NULL,
                role TEXT NOT NULL DEFAULT 'user',
                status TEXT NOT NULL DEFAULT 'active',
                avatar TEXT NULL DEFAULT NULL,
                auth_source TEXT NOT NULL DEFAULT 'local',
                ad_object_guid TEXT NULL UNIQUE,
                signature TEXT NULL DEFAULT NULL,
                timezone TEXT NOT NULL DEFAULT 'UTC',
                theme TEXT NOT NULL DEFAULT 'light',
                must_change_password INTEGER NOT NULL DEFAULT 0,
                session_epoch INTEGER NOT NULL DEFAULT 1,
                created_at TEXT DEFAULT CURRENT_TIMESTAMP,
                updated_at TEXT DEFAULT CURRENT_TIMESTAMP
            )
        ");
        return $pdo;
    }

    function resetLdapState(array $overrides = []): void
    {
        $GLOBALS['ad_test_state'] = array_merge([
            'connect_calls' => [],
            'connect_fail' => false,
            'start_tls_calls' => 0,
            'start_tls_fail' => false,
            'binds' => [],
            'bind_ok' => [],
            'searches' => [],
            'search_fail' => false,
            'entries' => ['count' => 0],
        ], $overrides);
    }

    $passed = 0;
    $failed = 0;

    function assertPolicy(bool $cond, string $name, string $detail = ''): void
    {
        global $passed, $failed;
        if ($cond) {
            $passed++;
            echo "[PASS] {$name}\n";
        } else {
            $failed++;
            echo "[FAIL] {$name}" . ($detail !== '' ? ": {$detail}" : '') . "\n";
        }
    }

    $baseConfig = [
        'enabled' => true,
        'host' => 'dc01.couriva.local',
        'port' => 389,
        'use_ssl' => false,
        'use_tls' => true,
        'require_tls' => true,
        'domain' => 'couriva.local',
        'base_dn' => 'DC=couriva,DC=local',
        'allowed_ou_dn' => 'OU=CourivaUsers,DC=couriva,DC=local',
        'allowed_group_dn' => '',
        'fallback_to_local' => false,
        'allow_local_admin_fallback' => true,
        'auto_create_user' => true,
        'default_role' => 'user',
    ];

    // Canonical 16-byte little-endian AD objectGUID -> 04030201-0605-0807-090a-0b0c0d0e0f10
    $rawGuid1 = hex2bin('0102030405060708090a0b0c0d0e0f10');
    $expectedUuid1 = '04030201-0605-0807-090a-0b0c0d0e0f10';

    $rawGuid2 = hex2bin('a1b2c3d4e5f611223344556677889900');
    $expectedUuid2 = 'd4c3b2a1-f6e5-2211-3344-556677889900';

    $pdo = makeSqlitePdo();
    $userModel = new \App\Models\User($pdo);
    $svc = new \App\Services\AuthService($pdo, $baseConfig);

    // 1a. Direct bind succeeds, search returns 0 entries -> fails closed
    resetLdapState([
        'bind_ok' => ['alice@couriva.local'],
        'entries' => ['count' => 0],
    ]);
    $res = $svc->authenticate('alice@couriva.local', 'DomainPass!1');
    assertPolicy(empty($res['success']), '1a. Direct bind with 0 search entries fails closed', json_encode($res));

    // 1b. Direct bind succeeds, entry DN outside allowed OU (including OU suffix spoof) -> fails closed
    resetLdapState([
        'bind_ok' => ['alice@couriva.local'],
        'entries' => [
            'count' => 1,
            0 => [
                'dn' => 'CN=Alice,OU=CourivaUsersEvil,DC=couriva,DC=local',
                'mail' => ['alice@couriva.local'],
                'givenname' => ['Alice'],
                'sn' => ['Smith'],
                'objectguid' => [$rawGuid1],
                'useraccountcontrol' => ['512'],
            ],
        ],
    ]);
    $res = $svc->authenticate('alice@couriva.local', 'DomainPass!1');
    assertPolicy(empty($res['success']), '1b. Entry DN outside allowed OU fails closed', json_encode($res));

    // 2. >1 search entries -> fails closed
    resetLdapState([
        'bind_ok' => ['alice@couriva.local'],
        'entries' => [
            'count' => 2,
            0 => [
                'dn' => 'CN=Alice1,OU=CourivaUsers,DC=couriva,DC=local',
                'mail' => ['alice@couriva.local'],
                'objectguid' => [$rawGuid1],
                'useraccountcontrol' => ['512'],
            ],
            1 => [
                'dn' => 'CN=Alice2,OU=CourivaUsers,DC=couriva,DC=local',
                'mail' => ['alice@couriva.local'],
                'objectguid' => [$rawGuid2],
                'useraccountcontrol' => ['512'],
            ],
        ],
    ]);
    $res = $svc->authenticate('alice@couriva.local', 'DomainPass!1');
    assertPolicy(empty($res['success']), '2. Ambiguous (>1) search entries fails closed', json_encode($res));

    // 3. LDAP search failure -> fails closed
    resetLdapState([
        'bind_ok' => ['alice@couriva.local'],
        'search_fail' => true,
    ]);
    $res = $svc->authenticate('alice@couriva.local', 'DomainPass!1');
    assertPolicy(empty($res['success']), '3. LDAP search failure fails closed', json_encode($res));

    // 4. require_tls=true without use_ssl/use_tls -> fails closed
    $noTlsSvc = new \App\Services\AuthService($pdo, array_merge($baseConfig, [
        'use_ssl' => false,
        'use_tls' => false,
        'require_tls' => true,
    ]));
    resetLdapState([
        'bind_ok' => ['alice@couriva.local'],
        'entries' => [
            'count' => 1,
            0 => [
                'dn' => 'CN=Alice,OU=CourivaUsers,DC=couriva,DC=local',
                'mail' => ['alice@couriva.local'],
                'objectguid' => [$rawGuid1],
                'useraccountcontrol' => ['512'],
            ],
        ],
    ]);
    $res = $noTlsSvc->authenticate('alice@couriva.local', 'DomainPass!1');
    assertPolicy(empty($res['success']), '4. require_tls=true without use_ssl/use_tls fails closed', json_encode($res));

    // 5. StartTLS failure -> fails closed
    resetLdapState([
        'start_tls_fail' => true,
        'bind_ok' => ['alice@couriva.local'],
        'entries' => [
            'count' => 1,
            0 => [
                'dn' => 'CN=Alice,OU=CourivaUsers,DC=couriva,DC=local',
                'mail' => ['alice@couriva.local'],
                'objectguid' => [$rawGuid1],
                'useraccountcontrol' => ['512'],
            ],
        ],
    ]);
    $res = $svc->authenticate('alice@couriva.local', 'DomainPass!1');
    assertPolicy(empty($res['success']), '5. StartTLS failure fails closed', json_encode($res));

    // 6. Disabled AD userAccountControl (bit 2 = 0x0002, e.g. 514) -> fails closed
    resetLdapState([
        'bind_ok' => ['alice@couriva.local'],
        'entries' => [
            'count' => 1,
            0 => [
                'dn' => 'CN=Alice,OU=CourivaUsers,DC=couriva,DC=local',
                'mail' => ['alice@couriva.local'],
                'givenname' => ['Alice'],
                'sn' => ['Smith'],
                'objectguid' => [$rawGuid1],
                'useraccountcontrol' => ['514'],
            ],
        ],
    ]);
    $res = $svc->authenticate('alice@couriva.local', 'DomainPass!1');
    assertPolicy(empty($res['success']), '6. Disabled AD userAccountControl (0x0002) fails closed', json_encode($res));

    // 7. Valid in-OU bind + 1 entry + 16-byte binary objectGUID succeeds and converts GUID deterministically
    resetLdapState([
        'bind_ok' => ['alice@couriva.local', 'CN=Alice,OU=Sales,OU=CourivaUsers,DC=couriva,DC=local'],
        'entries' => [
            'count' => 1,
            0 => [
                'dn' => 'CN=Alice,OU=Sales,OU=CourivaUsers,DC=couriva,DC=local',
                'mail' => ['alice@couriva.local'],
                'givenname' => ['Alice'],
                'sn' => ['Smith'],
                'objectguid' => [$rawGuid1],
                'useraccountcontrol' => ['512'],
            ],
        ],
    ]);
    $res = $svc->authenticate('alice@couriva.local', 'DomainPass!1');
    $aliceId = (int)($res['user']['id'] ?? 0);
    $aliceGuid = $res['user']['ad_object_guid'] ?? '';
    assertPolicy(
        !empty($res['success']) && $aliceId > 0 && $aliceGuid === $expectedUuid1 && ($res['auth_source'] ?? '') === 'ldap',
        '7. Valid in-OU bind + 16-byte binary objectGUID succeeds and stores canonical UUID',
        'got guid=' . var_export($aliceGuid, true) . ' expected=' . $expectedUuid1
    );

    // 8. AD email/name rename preserves same user ID via objectGUID
    resetLdapState([
        'bind_ok' => ['alice.renamed@couriva.local', 'CN=Alice Renamed,OU=CourivaUsers,DC=couriva,DC=local'],
        'entries' => [
            'count' => 1,
            0 => [
                'dn' => 'CN=Alice Renamed,OU=CourivaUsers,DC=couriva,DC=local',
                'mail' => ['alice.renamed@couriva.local'],
                'givenname' => ['Alicia'],
                'sn' => ['Johnson'],
                'objectguid' => [$rawGuid1],
                'useraccountcontrol' => ['512'],
            ],
        ],
    ]);
    $resRename = $svc->authenticate('alice.renamed@couriva.local', 'DomainPass!1');
    assertPolicy(
        !empty($resRename['success'])
            && (int)($resRename['user']['id'] ?? -1) === $aliceId
            && ($resRename['user']['email'] ?? '') === 'alice.renamed@couriva.local'
            && ($resRename['user']['first_name'] ?? '') === 'Alicia'
            && ($resRename['user']['last_name'] ?? '') === 'Johnson',
        '8. AD email/name rename preserves same user ID via objectGUID',
        json_encode($resRename)
    );

    // 9a. Existing local account with matching email and no GUID is NOT auto-linked (fails closed)
    $localConflictId = $userModel->create([
        'email' => 'conflict@couriva.local',
        'password' => 'LocalSecret!123',
        'first_name' => 'Local',
        'last_name' => 'Conflict',
        'role' => 'user',
        'status' => 'active',
        'auth_source' => 'local',
        'must_change_password' => 0,
    ]);
    resetLdapState([
        'bind_ok' => ['conflict@couriva.local', 'CN=Conflict,OU=CourivaUsers,DC=couriva,DC=local'],
        'entries' => [
            'count' => 1,
            0 => [
                'dn' => 'CN=Conflict,OU=CourivaUsers,DC=couriva,DC=local',
                'mail' => ['conflict@couriva.local'],
                'givenname' => ['Ad'],
                'sn' => ['Conflict'],
                'objectguid' => [$rawGuid2],
                'useraccountcontrol' => ['512'],
            ],
        ],
    ]);
    $resConflict = $svc->authenticate('conflict@couriva.local', 'DomainPass!1');
    $localAfter = $userModel->findById($localConflictId);
    assertPolicy(
        empty($resConflict['success']) && ($localAfter['auth_source'] ?? '') === 'local' && empty($localAfter['ad_object_guid']),
        '9a. Existing local account with matching email is NOT auto-linked to AD user',
        json_encode($resConflict)
    );

    // 9b. Existing LDAP account with different GUID and matching email is NOT overwritten (fails closed)
    resetLdapState([
        'bind_ok' => ['alice.renamed@couriva.local', 'CN=Impostor,OU=CourivaUsers,DC=couriva,DC=local'],
        'entries' => [
            'count' => 1,
            0 => [
                'dn' => 'CN=Impostor,OU=CourivaUsers,DC=couriva,DC=local',
                'mail' => ['alice.renamed@couriva.local'],
                'givenname' => ['Impostor'],
                'sn' => ['User'],
                'objectguid' => [$rawGuid2],
                'useraccountcontrol' => ['512'],
            ],
        ],
    ]);
    $resDiffGuid = $svc->authenticate('alice.renamed@couriva.local', 'DomainPass!1');
    $aliceAfter = $userModel->findById($aliceId);
    assertPolicy(
        empty($resDiffGuid['success']) && ($aliceAfter['ad_object_guid'] ?? '') === $expectedUuid1,
        '9b. Existing LDAP account with different objectGUID fails closed',
        json_encode($resDiffGuid)
    );

    // 10. Local fallback policy when LDAP enabled:
    //     - auth_source=local, role=admin -> ALLOWED
    //     - auth_source=local, role=user -> REJECTED
    //     - auth_source=ldap -> REJECTED (even if role=admin)
    $adminId = $userModel->create([
        'email' => 'breakglass@couriva.local',
        'password' => 'BreakGlassPass!123',
        'first_name' => 'Break',
        'last_name' => 'Glass',
        'role' => 'admin',
        'status' => 'active',
        'auth_source' => 'local',
        'must_change_password' => 0,
    ]);
    $normalLocalId = $userModel->create([
        'email' => 'normal.local@couriva.local',
        'password' => 'UserLocalPass!123',
        'first_name' => 'Normal',
        'last_name' => 'Local',
        'role' => 'user',
        'status' => 'active',
        'auth_source' => 'local',
        'must_change_password' => 0,
    ]);
    $adAdminId = $userModel->create([
        'email' => 'ad.admin@couriva.local',
        'password' => 'AdAdminDummyPass!123',
        'first_name' => 'Ad',
        'last_name' => 'Admin',
        'role' => 'admin',
        'status' => 'active',
        'auth_source' => 'ldap',
        'ad_object_guid' => '11111111-2222-3333-4444-555555555555',
        'must_change_password' => 0,
    ]);

    resetLdapState(['bind_ok' => [], 'entries' => ['count' => 0]]);
    $resAdmin = $svc->authenticate('breakglass@couriva.local', 'BreakGlassPass!123');
    assertPolicy(
        !empty($resAdmin['success']) && ($resAdmin['auth_source'] ?? '') === 'local',
        '10a. Break-glass local admin (auth_source=local, role=admin) succeeds on fallback when LDAP enabled',
        json_encode($resAdmin)
    );

    $resUser = $svc->authenticate('normal.local@couriva.local', 'UserLocalPass!123');
    assertPolicy(
        empty($resUser['success']),
        '10b. Normal local user (auth_source=local, role=user) rejected when LDAP enabled even with admin fallback',
        json_encode($resUser)
    );

    $resAdAdmin = $svc->authenticate('ad.admin@couriva.local', 'AdAdminDummyPass!123');
    assertPolicy(
        empty($resAdAdmin['success']),
        '10c. AD user (auth_source=ldap, even role=admin) never falls back to local password auth',
        json_encode($resAdAdmin)
    );

    $svcWithFallbackFlag = new \App\Services\AuthService($pdo, array_merge($baseConfig, [
        'fallback_to_local' => true,
        'allow_local_admin_fallback' => true,
    ]));
    $resUserWithFlag = $svcWithFallbackFlag->authenticate('normal.local@couriva.local', 'UserLocalPass!123');
    assertPolicy(
        empty($resUserWithFlag['success']),
        '10d. Normal local user (role=user) remains rejected even when fallback_to_local=true',
        json_encode($resUserWithFlag)
    );

    // 11. Missing objectGUID fails closed, and allowed_group_dn adds memberOf filter
    $groupSvc = new \App\Services\AuthService($pdo, array_merge($baseConfig, [
        'allowed_group_dn' => 'CN=CourivaMailUsers,OU=Groups,DC=couriva,DC=local',
    ]));
    resetLdapState([
        'bind_ok' => ['noguid@couriva.local'],
        'entries' => [
            'count' => 1,
            0 => [
                'dn' => 'CN=NoGuid,OU=CourivaUsers,DC=couriva,DC=local',
                'mail' => ['noguid@couriva.local'],
                'useraccountcontrol' => ['512'],
            ],
        ],
    ]);
    $resNoGuid = $groupSvc->authenticate('noguid@couriva.local', 'DomainPass!1');
    $lastFilter = $GLOBALS['ad_test_state']['searches'][0]['filter'] ?? '';
    assertPolicy(
        empty($resNoGuid['success']) && str_contains($lastFilter, '(memberOf=CN=CourivaMailUsers,OU=Groups,DC=couriva,DC=local)'),
        '11. Missing objectGUID fails closed and allowed_group_dn enforces memberOf filter',
        'filter=' . $lastFilter . ' res=' . json_encode($resNoGuid)
    );

    // 12a. Direct UPN bind cannot impersonate another user returned by search (must re-bind entry DN)
    $rawGuidVictim = hex2bin('11223344556677889900aabbccddeeff');
    resetLdapState([
        'bind_ok' => ['attacker@couriva.local'],
        'entries' => [
            'count' => 1,
            0 => [
                'dn' => 'CN=VictimB,OU=CourivaUsers,DC=couriva,DC=local',
                'mail' => ['victim@couriva.local'],
                'givenname' => ['Victim'],
                'sn' => ['User'],
                'objectguid' => [$rawGuidVictim],
                'useraccountcontrol' => ['512'],
            ],
        ],
    ]);
    $resImpersonateUpn = $svc->authenticate('attacker@couriva.local', 'AttackerPass!1');
    assertPolicy(
        empty($resImpersonateUpn['success'])
            && ($resImpersonateUpn['message'] ?? '') === 'LDAP credentials rejected.'
            && !$userModel->findByEmail('victim@couriva.local'),
        '12a. Direct UPN bind fails closed when resolved entry DN fails password bind',
        json_encode($resImpersonateUpn)
    );

    // 12b. Direct NetBIOS bind cannot impersonate another user returned by search
    $rawGuidVictim2 = hex2bin('223344556677889900aabbccddeeff11');
    resetLdapState([
        'bind_ok' => ['COURIVA\\attacker2'],
        'entries' => [
            'count' => 1,
            0 => [
                'dn' => 'CN=VictimB2,OU=CourivaUsers,DC=couriva,DC=local',
                'mail' => ['victim2@couriva.local'],
                'givenname' => ['Victim2'],
                'sn' => ['User'],
                'objectguid' => [$rawGuidVictim2],
                'useraccountcontrol' => ['512'],
            ],
        ],
    ]);
    $resImpersonateNetbios = $svc->authenticate('attacker2@couriva.local', 'AttackerPass!1');
    assertPolicy(
        empty($resImpersonateNetbios['success'])
            && ($resImpersonateNetbios['message'] ?? '') === 'LDAP credentials rejected.'
            && !$userModel->findByEmail('victim2@couriva.local'),
        '12b. Direct NetBIOS bind fails closed when resolved entry DN fails password bind',
        json_encode($resImpersonateNetbios)
    );

    // 13. Escaped-comma DN suffix spoofing fails closed
    $rawGuidEvil = hex2bin('3344556677889900aabbccddeeff1122');
    $evilDn = 'CN=Evil\\,OU=CourivaUsers,DC=couriva,DC=local';
    resetLdapState([
        'bind_ok' => ['evil@couriva.local', $evilDn],
        'entries' => [
            'count' => 1,
            0 => [
                'dn' => $evilDn,
                'mail' => ['evil@couriva.local'],
                'givenname' => ['Evil'],
                'sn' => ['Spoof'],
                'objectguid' => [$rawGuidEvil],
                'useraccountcontrol' => ['512'],
            ],
        ],
    ]);
    $resEscapedComma = $svc->authenticate('evil@couriva.local', 'DomainPass!1');
    assertPolicy(
        empty($resEscapedComma['success']),
        '13. Escaped-comma DN suffix spoofing fails closed',
        json_encode($resEscapedComma)
    );

    // 14a. Empty allowed_ou_dn fails closed in AuthService::attemptLdapAuth()
    $emptyOuSvc = new \App\Services\AuthService($pdo, array_merge($baseConfig, [
        'allowed_ou_dn' => '',
    ]));
    resetLdapState([
        'bind_ok' => ['noou@couriva.local', 'CN=NoOu,OU=OtherUsers,DC=couriva,DC=local'],
        'entries' => [
            'count' => 1,
            0 => [
                'dn' => 'CN=NoOu,OU=OtherUsers,DC=couriva,DC=local',
                'mail' => ['noou@couriva.local'],
                'givenname' => ['No'],
                'sn' => ['Ou'],
                'objectguid' => [$rawGuidEvil],
                'useraccountcontrol' => ['512'],
            ],
        ],
    ]);
    $resEmptyOu = $emptyOuSvc->authenticate('noou@couriva.local', 'DomainPass!1');
    assertPolicy(
        empty($resEmptyOu['success']) && ($resEmptyOu['message'] ?? '') === 'Active Directory OU boundary is required by policy.',
        '14a. Empty allowed_ou_dn fails closed with OU boundary policy error',
        json_encode($resEmptyOu)
    );

    // 14b. config/ldap.php defaults blank LDAP_ALLOWED_OU_DN and accepts '1' for use_ssl/use_tls
    $prevOu = getenv('LDAP_ALLOWED_OU_DN');
    $prevSsl = getenv('LDAP_USE_SSL');
    $prevTls = getenv('LDAP_USE_TLS');
    putenv('LDAP_ALLOWED_OU_DN=   ');
    putenv('LDAP_USE_SSL=1');
    putenv('LDAP_USE_TLS=1');
    $loadedLdapCfg = require __DIR__ . '/../config/ldap.php';
    putenv($prevOu === false ? 'LDAP_ALLOWED_OU_DN' : "LDAP_ALLOWED_OU_DN={$prevOu}");
    putenv($prevSsl === false ? 'LDAP_USE_SSL' : "LDAP_USE_SSL={$prevSsl}");
    putenv($prevTls === false ? 'LDAP_USE_TLS' : "LDAP_USE_TLS={$prevTls}");
    assertPolicy(
        ($loadedLdapCfg['allowed_ou_dn'] ?? '') === 'OU=CourivaUsers,DC=couriva,DC=local'
            && ($loadedLdapCfg['use_ssl'] ?? false) === true
            && ($loadedLdapCfg['use_tls'] ?? false) === true,
        '14b. config/ldap.php defaults blank LDAP_ALLOWED_OU_DN and accepts 1 for use_ssl/use_tls',
        json_encode([
            'allowed_ou_dn' => $loadedLdapCfg['allowed_ou_dn'] ?? null,
            'use_ssl' => $loadedLdapCfg['use_ssl'] ?? null,
            'use_tls' => $loadedLdapCfg['use_tls'] ?? null,
        ])
    );

    // 15a. Null-byte password/email rejected early without calling ldap_connect
    resetLdapState([
        'bind_ok' => ['alice@couriva.local', 'CN=Alice,OU=Sales,OU=CourivaUsers,DC=couriva,DC=local'],
        'entries' => [
            'count' => 1,
            0 => [
                'dn' => 'CN=Alice,OU=Sales,OU=CourivaUsers,DC=couriva,DC=local',
                'mail' => ['alice@couriva.local'],
                'objectguid' => [$rawGuid1],
                'useraccountcontrol' => ['512'],
            ],
        ],
    ]);
    $resNullAuth = $svc->authenticate('alice@couriva.local', "DomainPass!1\0extra");
    $resNullLdap = $svc->attemptLdapAuth('alice@couriva.local', "DomainPass!1\0extra");
    $resNullEmail = $svc->attemptLdapAuth("alice\0@couriva.local", 'DomainPass!1');
    assertPolicy(
        empty($resNullAuth['success'])
            && empty($resNullLdap['success'])
            && empty($resNullEmail['success'])
            && count($GLOBALS['ad_test_state']['connect_calls']) === 0,
        '15a. Null-byte in email or password fails closed before ldap_connect',
        json_encode(['connect_calls' => $GLOBALS['ad_test_state']['connect_calls'], 'auth' => $resNullAuth, 'ldap' => $resNullLdap])
    );

    // 15b. connect_fail does not leak ldaps://host:port in returned failure message
    $sslSvc = new \App\Services\AuthService($pdo, array_merge($baseConfig, [
        'host' => 'secret-dc01.internal.local',
        'port' => 636,
        'use_ssl' => true,
        'use_tls' => false,
    ]));
    resetLdapState(['connect_fail' => true]);
    $resConnectFail = $sslSvc->attemptLdapAuth('alice@couriva.local', 'DomainPass!1');
    $failMsg = (string)($resConnectFail['message'] ?? '');
    assertPolicy(
        empty($resConnectFail['success'])
            && $failMsg !== ''
            && !str_contains($failMsg, 'ldaps://')
            && !str_contains($failMsg, 'secret-dc01.internal.local')
            && !str_contains($failMsg, '636'),
        '15b. LDAP connect failure message does not disclose internal URI/host/port',
        'message=' . var_export($failMsg, true)
    );

    // 16. admin_ou_dn grants admin role on provision and syncs role when moved out
    $adminOuConfig = array_merge($baseConfig, [
        'admin_ou_dn' => 'OU=CourivaAdmins,OU=CourivaUsers,DC=couriva,DC=local',
    ]);
    $adminOuSvc = new \App\Services\AuthService($pdo, $adminOuConfig);
    $spareGuid = hex2bin('33333333333333333333333333333333');
    resetLdapState([
        'bind_ok' => ['spare.admin@couriva.local', 'CN=Spare Admin,OU=CourivaAdmins,OU=CourivaUsers,DC=couriva,DC=local'],
        'entries' => [
            'count' => 1,
            0 => [
                'dn' => 'CN=Spare Admin,OU=CourivaAdmins,OU=CourivaUsers,DC=couriva,DC=local',
                'mail' => ['spare.admin@couriva.local'],
                'givenname' => ['Spare'],
                'sn' => ['Admin'],
                'objectguid' => [$spareGuid],
                'useraccountcontrol' => ['512'],
            ],
        ],
    ]);
    $resAdminOu = $adminOuSvc->attemptLdapAuth('spare.admin@couriva.local', 'DomainPass!1');
    assertPolicy(
        !empty($resAdminOu['success']) && ($resAdminOu['user']['role'] ?? '') === 'admin',
        '16a. User in admin_ou_dn is provisioned with role=admin',
        json_encode($resAdminOu)
    );

    resetLdapState([
        'bind_ok' => ['spare.admin@couriva.local', 'CN=Spare Admin,OU=CourivaUsers,DC=couriva,DC=local'],
        'entries' => [
            'count' => 1,
            0 => [
                'dn' => 'CN=Spare Admin,OU=CourivaUsers,DC=couriva,DC=local',
                'mail' => ['spare.admin@couriva.local'],
                'givenname' => ['Spare'],
                'sn' => ['Admin'],
                'objectguid' => [$spareGuid],
                'useraccountcontrol' => ['512'],
            ],
        ],
    ]);
    $resDemotedOu = $adminOuSvc->attemptLdapAuth('spare.admin@couriva.local', 'DomainPass!1');
    assertPolicy(
        !empty($resDemotedOu['success']) && ($resDemotedOu['user']['role'] ?? '') === 'user',
        '16b. User moved out of admin_ou_dn reverts to role=user on next login',
        json_encode($resDemotedOu)
    );

    // 17. Reject foreign email domain (e.g. @gmail.com) and prefer userPrincipalName when mail is empty
    resetLdapState([]);
    $resForeignDomain = $adminOuSvc->attemptLdapAuth('spare.admin@gmail.com', 'DomainPass!1');
    assertPolicy(
        empty($resForeignDomain['success']) && count($GLOBALS['ad_test_state']['connect_calls']) === 0,
        '17a. Foreign email domain (@gmail.com) is rejected before ldap_connect',
        json_encode($resForeignDomain)
    );

    resetLdapState([
        'bind_ok' => ['upn.only@couriva.local', 'CN=UPN Only,OU=CourivaUsers,DC=couriva,DC=local'],
        'entries' => [
            'count' => 1,
            0 => [
                'dn' => 'CN=UPN Only,OU=CourivaUsers,DC=couriva,DC=local',
                'userprincipalname' => ['upn.only@couriva.local'],
                'givenname' => ['Upn'],
                'sn' => ['Only'],
                'objectguid' => [hex2bin('44444444444444444444444444444444')],
                'useraccountcontrol' => ['512'],
            ],
        ],
    ]);
    $resUpnFallback = $adminOuSvc->attemptLdapAuth('upn.only', 'DomainPass!1');
    assertPolicy(
        !empty($resUpnFallback['success']) && ($resUpnFallback['user']['email'] ?? '') === 'upn.only@couriva.local',
        '17b. Empty AD mail falls back to userPrincipalName within allowed domain',
        json_encode($resUpnFallback)
    );

    echo "\n=== AD OU Policy Test Results: {$passed} passed, {$failed} failed ===\n";
    exit($failed === 0 ? 0 : 1);
}
