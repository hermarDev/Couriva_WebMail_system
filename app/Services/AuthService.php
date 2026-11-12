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

            // If LDAP failed and fallback to local DB is disabled, stop here
            if (empty($this->ldapConfig['fallback_to_local'])) {
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

    /**
     * Active Directory / LDAP Domain Authentication
     */
    public function attemptLdapAuth(string $email, string $password): array
    {
        if (!function_exists('ldap_connect')) {
            // LDAP extension not available in current PHP binary
            return ['success' => false, 'message' => 'LDAP module not available on server.'];
        }

        $host = $this->ldapConfig['host'] ?? '127.0.0.1';
        $port = (int)($this->ldapConfig['port'] ?? 389);
        $useSsl = !empty($this->ldapConfig['use_ssl']);
        $useTls = !empty($this->ldapConfig['use_tls']);
        $timeout = (int)($this->ldapConfig['timeout'] ?? 10);
        $domain = $this->ldapConfig['domain'] ?? 'couriva.local';
        $baseDn = $this->ldapConfig['base_dn'] ?? 'dc=couriva,dc=local';

        $ldapUri = ($useSsl ? 'ldaps://' : 'ldap://') . $host . ':' . $port;
        $conn = @ldap_connect($ldapUri);
        if (!$conn) {
            return ['success' => false, 'message' => "Could not connect to LDAP server {$ldapUri}."];
        }

        ldap_set_option($conn, LDAP_OPT_PROTOCOL_VERSION, 3);
        ldap_set_option($conn, LDAP_OPT_REFERRALS, 0);
        if (defined('LDAP_OPT_NETWORK_TIMEOUT')) {
            ldap_set_option($conn, LDAP_OPT_NETWORK_TIMEOUT, $timeout);
        }

        if ($useTls && !$useSsl) {
            if (!@ldap_start_tls($conn)) {
                @ldap_unbind($conn);
                return ['success' => false, 'message' => 'Failed to initiate STARTTLS with LDAP server.'];
            }
        }

        // Prepare username / UPN for binding
        $username = str_contains($email, '@') ? strstr($email, '@', true) : $email;
        $userUpn = str_contains($email, '@') ? $email : ($username . '@' . $domain);

        $bindSuccess = false;
        $ldapUserAttributes = [];

        // Attempt 1: Direct user bind using UPN (Standard Active Directory)
        if (@ldap_bind($conn, $userUpn, $password)) {
            $bindSuccess = true;
        }

        // Attempt 2: If direct UPN bind failed, try domain\username format
        if (!$bindSuccess) {
            $domainUser = $domain . '\\' . $username;
            if (@ldap_bind($conn, $domainUser, $password)) {
                $bindSuccess = true;
            }
        }

        // Attempt 3: If bind DN service account configured, search user DN and bind
        if (!$bindSuccess && !empty($this->ldapConfig['bind_dn'])) {
            if (@ldap_bind($conn, $this->ldapConfig['bind_dn'], $this->ldapConfig['bind_password'] ?? '')) {
                $filter = str_replace(
                    ['{email}', '{username}'],
                    [ldap_escape($email, '', LDAP_ESCAPE_FILTER), ldap_escape($username, '', LDAP_ESCAPE_FILTER)],
                    $this->ldapConfig['user_filter'] ?? '(&(objectClass=user)(|(mail={email})(userPrincipalName={email})(sAMAccountName={username})))'
                );

                $search = @ldap_search($conn, $baseDn, $filter, ['dn', 'mail', 'givenname', 'sn', 'displayname', 'telephonenumber', 'company']);
                if ($search) {
                    $entries = @ldap_get_entries($conn, $search);
                    if ($entries && $entries['count'] > 0) {
                        $userDn = $entries[0]['dn'];
                        if (@ldap_bind($conn, $userDn, $password)) {
                            $bindSuccess = true;
                            $ldapUserAttributes = $entries[0];
                        }
                    }
                }
            }
        }

        if (!$bindSuccess) {
            @ldap_unbind($conn);
            return ['success' => false, 'message' => 'LDAP credentials rejected.'];
        }

        // If authenticated successfully, fetch user details if not yet fetched
        if (empty($ldapUserAttributes)) {
            $filter = str_replace(
                ['{email}', '{username}'],
                [ldap_escape($email, '', LDAP_ESCAPE_FILTER), ldap_escape($username, '', LDAP_ESCAPE_FILTER)],
                $this->ldapConfig['user_filter'] ?? '(&(objectClass=user)(|(mail={email})(userPrincipalName={email})(sAMAccountName={username})))'
            );
            $search = @ldap_search($conn, $baseDn, $filter, ['mail', 'givenname', 'sn', 'displayname', 'telephonenumber', 'company']);
            if ($search) {
                $entries = @ldap_get_entries($conn, $search);
                if ($entries && $entries['count'] > 0) {
                    $ldapUserAttributes = $entries[0];
                }
            }
        }

        @ldap_unbind($conn);

        // Sync or auto-provision local account
        return $this->syncLdapUserToDatabase($email, $username, $ldapUserAttributes);
    }

    /**
     * Synchronize LDAP authenticated user to local MariaDB/MySQL database
     */
    protected function syncLdapUserToDatabase(string $email, string $username, array $attrs): array
    {
        $ldapEmail = !empty($attrs['mail'][0]) ? strtolower($attrs['mail'][0]) : $email;
        $firstName = $attrs['givenname'][0] ?? ucfirst($username);
        $lastName = $attrs['sn'][0] ?? 'User';

        $localUser = $this->userModel->findByEmail($ldapEmail);

        if ($localUser) {
            if ($localUser['status'] !== 'active') {
                return ['success' => false, 'message' => 'Your account is disabled. Contact system administrator.'];
            }

            // Sync updated name and mark auth_source as ldap
            $this->userModel->update((int)$localUser['id'], [
                'first_name'  => $firstName,
                'last_name'   => $lastName,
                'auth_source' => 'ldap',
            ]);

            // Re-fetch updated user record
            $refreshed = $this->userModel->findById((int)$localUser['id']);
            return [
                'success'     => true,
                'user'        => $refreshed ?: $localUser,
                'auth_source' => 'ldap',
            ];
        }

        // Auto-provision local user account if enabled
        if (!empty($this->ldapConfig['auto_create_user'])) {
            $newUserId = $this->userModel->create([
                'email'      => $ldapEmail,
                'password'   => bin2hex(random_bytes(16)), // Unusable random hash since authenticated via LDAP
                'first_name' => $firstName,
                'last_name'  => $lastName,
                'role'       => $this->ldapConfig['default_role'] ?? 'user',
                'status'     => 'active',
                'signature'  => "-- \n{$firstName} {$lastName}\n{$ldapEmail}",
                'timezone'   => 'UTC',
                'theme'      => 'light',
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
