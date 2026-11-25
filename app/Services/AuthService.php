<?php
/**
 * Couriva - Auth Service
 * Supports Local DB and Active Directory / LDAP domain authentication (Section 54)
 */

namespace App\Services;

use App\Models\User;
use PDO;
use Throwable;

class AuthService
{
    protected PDO $pdo;
    protected User $userModel;
    protected array $ldapConfig;

    public function __construct(PDO $pdo, ?array $ldapConfig = null)
    {
        $this->pdo = $pdo;
        $this->userModel = new User($pdo);

        if ($ldapConfig !== null) {
            $this->ldapConfig = $ldapConfig;
        } else {
            $authCfgFile = dirname(dirname(__DIR__)) . '/config/auth.php';
            $ldapCfgFile = dirname(dirname(__DIR__)) . '/config/ldap.php';

            if (file_exists($authCfgFile)) {
                $authCfg = require $authCfgFile;
                $this->ldapConfig = $authCfg['ldap'] ?? [];
            } elseif (file_exists($ldapCfgFile)) {
                $this->ldapConfig = require $ldapCfgFile;
            } else {
                $this->ldapConfig = ['enabled' => false];
            }
        }
    }

    /**
     * Authenticate user with Active Directory / LDAP support and Local DB fallback
     *
     * @param string $email
     * @param string $password
     * @return array ['success' => bool, 'user' => ?array, 'auth_source' => string, 'message' => ?string]
     */
    public function authenticate(string $email, string $password): array
    {
        if (str_contains($email, "\0") || str_contains($password, "\0")) {
            return ['success' => false, 'message' => 'Invalid email or password.'];
        }

        $email = trim(strtolower($email));
        if (empty($email) || empty($password)) {
            return ['success' => false, 'message' => 'Invalid email or password.'];
        }

        // Enforce strict email requirement — no bare username fallback
        if (!str_contains($email, '@') || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return ['success' => false, 'message' => 'Please enter a valid email address.'];
        }

        $existingUser = $this->userModel->findByEmail($email);
        $isAdUser = $existingUser && (($existingUser['auth_source'] ?? 'local') === 'ldap');

        // 1. Attempt LDAP / Active Directory if enabled
        if (!empty($this->ldapConfig['enabled'])) {
            $ldapResult = $this->attemptLdapAuth($email, $password);
            if ($ldapResult['success']) {
                return $ldapResult;
            }

            // Active Directory accounts MUST NOT fall back to local DB authentication
            if ($isAdUser) {
                return ['success' => false, 'message' => $ldapResult['message'] ?? 'Active Directory credentials rejected.'];
            }

            // Break-glass local admin policy: only auth_source='local' AND role='admin' may fall back
            $fallbackAllowed = !empty($this->ldapConfig['fallback_to_local'])
                || !empty($this->ldapConfig['allow_local_admin_fallback']);
            $isLocalBreakGlassAdmin = $existingUser
                && (($existingUser['auth_source'] ?? 'local') === 'local')
                && (($existingUser['role'] ?? 'user') === 'admin');

            if (!$fallbackAllowed || !$isLocalBreakGlassAdmin) {
                return ['success' => false, 'message' => $ldapResult['message'] ?? 'Invalid email or password.'];
            }
        }

        // 2. Local Database Authentication Fallback
        if ($isAdUser) {
            return ['success' => false, 'message' => 'Active Directory accounts must authenticate via domain controller.'];
        }

        return $this->attemptLocalAuth($email, $password);
    }

    /**
     * Local MariaDB / MySQL password verification
     */
    protected function attemptLocalAuth(string $email, string $password): array
    {
        $user = $this->userModel->findByEmail($email);
        if (!$user) {
            return ['success' => false, 'message' => 'Invalid email or password.'];
        }

        if (($user['auth_source'] ?? 'local') === 'ldap') {
            return ['success' => false, 'message' => 'Active Directory accounts must authenticate via domain controller.'];
        }

        if ($user['status'] !== 'active') {
            $message = match ($user['status']) {
                'pending' => 'Your account is awaiting administrator approval.',
                'rejected' => 'Your account request was not approved. Contact an administrator for help.',
                'suspended' => 'Your account is suspended. Contact an administrator for help.',
                default => 'Your account is disabled. Contact system administrator.',
            };
            return ['success' => false, 'message' => $message];
        }

        if (!password_verify($password, $user['password'])) {
            return ['success' => false, 'message' => 'Invalid email or password.'];
        }

        return [
            'success'     => true,
            'user'        => $user,
            'auth_source' => 'local',
        ];
    }

    private function getAllowedLdapDomains(): array
    {
        $domain = trim((string)($this->ldapConfig['domain'] ?? 'couriva.local'));
        $accountSuffixDomain = ltrim(trim((string)($this->ldapConfig['account_suffix'] ?? '')), '@');
        return array_values(array_unique(array_filter([
            strtolower($domain),
            strtolower($accountSuffixDomain),
        ])));
    }

    /**
     * Active Directory / LDAP Domain Authentication
     */
    public function attemptLdapAuth(string $email, string $password): array
    {
        if (str_contains($email, "\0") || str_contains($password, "\0") || $password === '') {
            return ['success' => false, 'message' => 'LDAP credentials rejected.'];
        }

        if (!function_exists('ldap_connect')) {
            // LDAP extension not available in current PHP binary
            return ['success' => false, 'message' => 'LDAP module not available on server.'];
        }

        $host = trim((string)($this->ldapConfig['host'] ?? '127.0.0.1'));
        $port = (int)($this->ldapConfig['port'] ?? 389);
        $useSsl = !empty($this->ldapConfig['use_ssl']);
        $useTls = !empty($this->ldapConfig['use_tls']);
        $requireTls = !array_key_exists('require_tls', $this->ldapConfig) || !empty($this->ldapConfig['require_tls']);
        $timeout = (int)($this->ldapConfig['timeout'] ?? 10);
        $domain = trim((string)($this->ldapConfig['domain'] ?? 'couriva.local'));
        $allowedDomains = $this->getAllowedLdapDomains();
        if (str_contains($email, '@')) {
            $inputDomain = strtolower(substr((string)strrchr($email, '@'), 1));
            if ($inputDomain === '' || !in_array($inputDomain, $allowedDomains, true)) {
                return ['success' => false, 'message' => 'Email domain is not permitted for Active Directory sign-in.'];
            }
        }
        $allowedOuDn = array_key_exists('allowed_ou_dn', $this->ldapConfig)
            ? trim((string)$this->ldapConfig['allowed_ou_dn'])
            : 'OU=CourivaUsers,DC=couriva,DC=local';
        $allowedGroupDn = trim((string)($this->ldapConfig['allowed_group_dn'] ?? ''));

        if ($allowedOuDn === '') {
            return ['success' => false, 'message' => 'Active Directory OU boundary is required by policy.'];
        }

        $searchBase = $allowedOuDn;

        if ($requireTls && !$useSsl && !$useTls) {
            return ['success' => false, 'message' => 'LDAP TLS/LDAPS transport is required by policy.'];
        }

        if ($host === '' || $port <= 0 || $port > 65535 || $domain === '' || $searchBase === '') {
            return ['success' => false, 'message' => 'Invalid LDAP server configuration.'];
        }

        $ldapUri = ($useSsl ? 'ldaps://' : 'ldap://') . $host . ':' . $port;
        $conn = @ldap_connect($ldapUri);
        if (!$conn) {
            error_log("Couriva LDAP connect failed: uri={$ldapUri}");
            return ['success' => false, 'message' => 'Could not connect to authentication server.'];
        }

        ldap_set_option($conn, LDAP_OPT_PROTOCOL_VERSION, 3);
        ldap_set_option($conn, LDAP_OPT_REFERRALS, 0);
        if (defined('LDAP_OPT_NETWORK_TIMEOUT')) {
            ldap_set_option($conn, LDAP_OPT_NETWORK_TIMEOUT, $timeout);
        }

        if ($useTls && !$useSsl) {
            if (!@ldap_start_tls($conn)) {
                error_log("Couriva LDAP STARTTLS failed: uri={$ldapUri}");
                @ldap_unbind($conn);
                return ['success' => false, 'message' => 'Failed to initiate STARTTLS with authentication server.'];
            }
        }

        // Prepare username / UPN for binding
        $username = str_contains($email, '@') ? strstr($email, '@', true) : $email;
        $userUpn = str_contains($email, '@') ? $email : ($username . '@' . $domain);

        $baseFilter = str_replace(
            ['{email}', '{username}'],
            [ldap_escape($email, '', LDAP_ESCAPE_FILTER), ldap_escape($username, '', LDAP_ESCAPE_FILTER)],
            $this->ldapConfig['user_filter'] ?? '(&(objectClass=user)(|(mail={email})(userPrincipalName={email})(sAMAccountName={username})))'
        );
        $searchFilter = $allowedGroupDn !== ''
            ? '(&' . $baseFilter . '(memberOf=' . ldap_escape($allowedGroupDn, '', LDAP_ESCAPE_FILTER) . '))'
            : $baseFilter;
        $searchAttributes = ['dn', 'mail', 'userprincipalname', 'givenname', 'sn', 'displayname', 'telephonenumber', 'company', 'objectguid', 'useraccountcontrol'];

        $bindSuccess = false;
        $ldapUserAttributes = [];

        // Attempt 1: Direct user bind using UPN (Standard Active Directory)
        if (@ldap_bind($conn, $userUpn, $password)) {
            $bindSuccess = true;
        }

        // Attempt 2: If direct UPN bind failed, try NetBIOS (COURIVA\user) then DNS (couriva.local\user) domain forms
        if (!$bindSuccess) {
            $netbios = strtoupper(explode('.', $domain)[0]);
            foreach (array_unique([$netbios . '\\' . $username, $domain . '\\' . $username]) as $domainUser) {
                if (@ldap_bind($conn, $domainUser, $password)) {
                    $bindSuccess = true;
                    break;
                }
            }
        }

        // Attempt 3: If bind DN service account configured, search user DN within allowed OU and bind
        if (!$bindSuccess && !empty($this->ldapConfig['bind_dn'])) {
            if (@ldap_bind($conn, $this->ldapConfig['bind_dn'], $this->ldapConfig['bind_password'] ?? '')) {
                $search = @ldap_search($conn, $searchBase, $searchFilter, $searchAttributes);
                if ($search) {
                    $entries = @ldap_get_entries($conn, $search);
                    $validated = $this->validateLdapDirectoryEntries($entries, $allowedOuDn);
                    if ($validated['success']) {
                        $userDn = trim((string)($validated['entry']['dn'] ?? ''));
                        if ($userDn !== '' && @ldap_bind($conn, $userDn, $password)) {
                            $bindSuccess = true;
                            $ldapUserAttributes = $validated['entry'];
                        }
                    }
                }
            }
        }

        if (!$bindSuccess) {
            @ldap_unbind($conn);
            return ['success' => false, 'message' => 'LDAP credentials rejected.'];
        }

        // Post-bind verification: every bind MUST resolve to 1 enabled in-OU entry with objectGUID and verify password against that exact DN
        if (empty($ldapUserAttributes)) {
            $search = @ldap_search($conn, $searchBase, $searchFilter, $searchAttributes);
            if ($search === false && !empty($this->ldapConfig['bind_dn'])) {
                if (@ldap_bind($conn, $this->ldapConfig['bind_dn'], $this->ldapConfig['bind_password'] ?? '')) {
                    $search = @ldap_search($conn, $searchBase, $searchFilter, $searchAttributes);
                }
            }

            if (!$search) {
                error_log("Couriva LDAP search failed: uri={$ldapUri}, base_dn={$searchBase}");
                @ldap_unbind($conn);
                return ['success' => false, 'message' => 'Active Directory directory lookup failed.'];
            }

            $entries = @ldap_get_entries($conn, $search);
            $validated = $this->validateLdapDirectoryEntries($entries, $allowedOuDn);
            if (!$validated['success']) {
                @ldap_unbind($conn);
                return $validated;
            }
            $resolvedDn = trim((string)($validated['entry']['dn'] ?? ''));
            if ($resolvedDn === '' || !@ldap_bind($conn, $resolvedDn, $password)) {
                error_log("Couriva LDAP entry DN bind failed for {$email}");
                @ldap_unbind($conn);
                return ['success' => false, 'message' => 'LDAP credentials rejected.'];
            }
            $ldapUserAttributes = $validated['entry'];
        }

        @ldap_unbind($conn);

        // Sync or auto-provision local account
        return $this->syncLdapUserToDatabase($email, $username, $ldapUserAttributes);
    }

    /**
     * Validate LDAP search results: single entry, strict OU boundary, active UAC, valid objectGUID
     */
    protected function validateLdapDirectoryEntries($entries, string $allowedOuDn): array
    {
        if (!is_array($entries) || !isset($entries['count'])) {
            return ['success' => false, 'message' => 'Active Directory lookup returned invalid results.'];
        }

        $count = (int)$entries['count'];
        if ($count === 0) {
            return ['success' => false, 'message' => 'Account is not authorized in the required Active Directory OU or group.'];
        }
        if ($count > 1) {
            return ['success' => false, 'message' => 'Ambiguous Active Directory account match.'];
        }

        $entry = $entries[0] ?? null;
        if (!is_array($entry)) {
            return ['success' => false, 'message' => 'Invalid Active Directory entry format.'];
        }

        if (trim($allowedOuDn) === '') {
            return ['success' => false, 'message' => 'Active Directory OU boundary is required by policy.'];
        }

        $entryDn = trim((string)($entry['dn'] ?? ''));
        if ($entryDn === '') {
            return ['success' => false, 'message' => 'Active Directory entry DN is missing.'];
        }

        $entryRdns = $this->parseRdns($entryDn);
        $ouRdns = $this->parseRdns($allowedOuDn);
        if (
            empty($ouRdns)
            || empty($entryRdns)
            || count($entryRdns) < count($ouRdns)
            || array_slice($entryRdns, -count($ouRdns)) !== $ouRdns
        ) {
            return ['success' => false, 'message' => 'Account is outside the authorized Active Directory OU.'];
        }

        if (isset($entry['useraccountcontrol'][0])) {
            $uac = (int)$entry['useraccountcontrol'][0];
            if (($uac & 0x0002) !== 0) {
                return ['success' => false, 'message' => 'Active Directory account is disabled.'];
            }
        }

        $guid = $this->extractAdObjectGuid($entry);
        if ($guid === null) {
            return ['success' => false, 'message' => 'Active Directory objectGUID is missing or invalid.'];
        }

        return ['success' => true, 'entry' => $entry, 'guid' => $guid];
    }

    /**
     * Parse RFC 4514 DN into normalized lowercase RDN strings (split on unescaped commas)
     */
    protected function parseRdns(string $dn): array
    {
        $parts = preg_split('/(?<!\\\\),/', trim($dn));
        if (!is_array($parts)) {
            return [];
        }
        $rdns = array_map(
            static fn(string $rdn): string => strtolower(trim($rdn)),
            $parts
        );
        if (in_array('', $rdns, true)) {
            return [];
        }
        return array_values($rdns);
    }

    /**
     * Convert 16-byte binary AD objectGUID (little-endian mixed format) or UUID string to canonical lowercase UUID
     */
    protected function extractAdObjectGuid(array $attrs): ?string
    {
        $raw = $attrs['objectguid'][0] ?? null;
        if (!is_string($raw) || $raw === '') {
            return null;
        }

        if (strlen($raw) === 16) {
            $u = unpack('Vdata1/vdata2/vdata3/nclk_seq/H12node', $raw);
            if (!$u) {
                return null;
            }
            return sprintf(
                '%08x-%04x-%04x-%04x-%s',
                $u['data1'],
                $u['data2'],
                $u['data3'],
                $u['clk_seq'],
                strtolower($u['node'])
            );
        }

        if (preg_match('/^\{?([0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12})\}?$/i', trim($raw), $m)) {
            return strtolower($m[1]);
        }

        return null;
    }

    /**
     * Synchronize LDAP authenticated user to local MariaDB/MySQL database
     */
    protected function syncLdapUserToDatabase(string $email, string $username, array $attrs): array
    {
        $guid = $this->extractAdObjectGuid($attrs);
        if ($guid === null) {
            return ['success' => false, 'message' => 'Active Directory objectGUID is missing or invalid.'];
        }

        $ldapEmail = !empty($attrs['mail'][0])
            ? trim(strtolower((string)$attrs['mail'][0]))
            : (!empty($attrs['userprincipalname'][0])
                ? trim(strtolower((string)$attrs['userprincipalname'][0]))
                : $email);
        if ($ldapEmail === '' || !filter_var($ldapEmail, FILTER_VALIDATE_EMAIL)) {
            $ldapEmail = $email;
        }
        $resolvedDomain = str_contains($ldapEmail, '@') ? strtolower(substr((string)strrchr($ldapEmail, '@'), 1)) : '';
        if ($resolvedDomain === '' || !in_array($resolvedDomain, $this->getAllowedLdapDomains(), true)) {
            return ['success' => false, 'message' => 'Active Directory email domain is not permitted.'];
        }
        $firstName = !empty($attrs['givenname'][0]) ? trim((string)$attrs['givenname'][0]) : ucfirst($username);
        $lastName = !empty($attrs['sn'][0]) ? trim((string)$attrs['sn'][0]) : 'User';
        $adminOuDn = trim((string)($this->ldapConfig['admin_ou_dn'] ?? ''));
        $dn = strtolower(trim((string)($attrs['dn'] ?? '')));
        $resolvedRole = ($adminOuDn !== '' && $dn !== '' && str_ends_with($dn, ',' . strtolower($adminOuDn)))
            ? 'admin'
            : ($this->ldapConfig['default_role'] ?? 'user');

        // 1. Match immutably by ad_object_guid first (handles AD renames / email updates)
        $guidUser = $this->userModel->findByAdObjectGuid($guid);
        if ($guidUser) {
            if (($guidUser['status'] ?? '') !== 'active') {
                return ['success' => false, 'message' => 'Your account is disabled. Contact system administrator.'];
            }
            if (($guidUser['auth_source'] ?? 'local') !== 'ldap') {
                return ['success' => false, 'message' => 'Account authentication source conflict. Contact administrator.'];
            }
            if (strtolower((string)$guidUser['email']) !== $ldapEmail) {
                $emailOwner = $this->userModel->findByEmail($ldapEmail);
                if ($emailOwner && (int)$emailOwner['id'] !== (int)$guidUser['id']) {
                    return ['success' => false, 'message' => 'Active Directory email conflicts with an existing account.'];
                }
            }

            $updateData = [
                'email'       => $ldapEmail,
                'first_name'  => $firstName,
                'last_name'   => $lastName,
                'auth_source' => 'ldap',
            ];
            if ($adminOuDn !== '') {
                $updateData['role'] = $resolvedRole;
            }
            $this->userModel->update((int)$guidUser['id'], $updateData);

            $refreshed = $this->userModel->findById((int)$guidUser['id']);
            return [
                'success'     => true,
                'user'        => $refreshed ?: $guidUser,
                'auth_source' => 'ldap',
            ];
        }

        // 2. Email lookup: fail closed on pre-existing local accounts or mismatched objectGUID
        $localUser = $this->userModel->findByEmail($ldapEmail);
        if ($localUser) {
            if (($localUser['auth_source'] ?? 'local') !== 'ldap') {
                return ['success' => false, 'message' => 'Email belongs to a local account and cannot be auto-linked to Active Directory.'];
            }
            $existingGuid = trim(strtolower((string)($localUser['ad_object_guid'] ?? '')));
            if ($existingGuid !== '' && $existingGuid !== $guid) {
                return ['success' => false, 'message' => 'Active Directory objectGUID mismatch for existing account.'];
            }
            if (($localUser['status'] ?? '') !== 'active') {
                return ['success' => false, 'message' => 'Your account is disabled. Contact system administrator.'];
            }

            $updateData = [
                'first_name'     => $firstName,
                'last_name'      => $lastName,
                'auth_source'    => 'ldap',
                'ad_object_guid' => $guid,
            ];
            if ($adminOuDn !== '') {
                $updateData['role'] = $resolvedRole;
            }
            $this->userModel->update((int)$localUser['id'], $updateData);

            $refreshed = $this->userModel->findById((int)$localUser['id']);
            return [
                'success'     => true,
                'user'        => $refreshed ?: $localUser,
                'auth_source' => 'ldap',
            ];
        }

        // 3. Auto-provision local user account if enabled
        if (!empty($this->ldapConfig['auto_create_user'])) {
            $newUserId = $this->userModel->create([
                'email'                => $ldapEmail,
                'password'             => bin2hex(random_bytes(16)), // Unusable random hash since authenticated via LDAP
                'first_name'           => $firstName,
                'last_name'            => $lastName,
                'role'                 => $resolvedRole,
                'status'               => 'active',
                'auth_source'          => 'ldap',
                'ad_object_guid'       => $guid,
                'signature'            => "-- \n{$firstName} {$lastName}\n{$ldapEmail}",
                'timezone'             => 'UTC',
                'theme'                => 'light',
                'must_change_password' => 0,
            ]);

            $createdUser = $this->userModel->findById($newUserId);
            return [
                'success'     => true,
                'user'        => $createdUser,
                'auth_source' => 'ldap',
            ];
        }

        return ['success' => false, 'message' => 'User account does not exist in local database.'];
    }
}
