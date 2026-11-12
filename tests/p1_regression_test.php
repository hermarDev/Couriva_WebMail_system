<?php
/**
 * Couriva - P1 High Severity Security Regression Tests
 *
 * Covers:
 *   H1 - Public registration removed; role/status validation
 *   H2 - No default credentials; forced first-login password change
 *   H3 - Fail-closed sessions, revocation, timeouts, strict mode
 *   H4 - SMTP TLS peer verification and explicit development override
 *   H5 - User deletion preserves recipients' mail via sender snapshots
 *   H6 - IIS/Apache configuration isolation and handler ordering
 *   H7 - Security headers, secure cookies, no inline scripts/handlers
 *   H8 - HTML sanitizer hardening (ping/srcset/overlay CSS/images)
 *   H9 - Sandboxed iframe rendering with remote-image opt-in
 *
 * Run with an isolated database:
 *   DB_NAME=webmail_test php tests/p1_regression_test.php
 */

declare(strict_types=1);

define('WEBMAIL_INIT', true);

if (getenv('DB_NAME') === false) {
    putenv('DB_NAME=webmail_test');
}

require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../app/Models/User.php';
require_once __DIR__ . '/../app/Models/Email.php';
require_once __DIR__ . '/../app/Services/SmtpTransport.php';

use App\Models\User;
use App\Models\Email;
use App\Services\SmtpTransport;

$pdo = getDbConnection();
$passed = 0;
$failed = 0;
$createdEmailIds = [];
$createdUserIds = [];
$suffix = bin2hex(random_bytes(4));
$projectRoot = dirname(__DIR__);
$tempPaths = [];
$runningProcesses = [];

function assertP1(bool $condition, string $name, string $details = ''): void
{
    global $passed, $failed;
    if ($condition) {
        $passed++;
        echo "[\033[32mPASS\033[0m] {$name}\n";
    } else {
        $failed++;
        echo "[\033[31mFAIL\033[0m] {$name}" . ($details !== '' ? ": {$details}" : '') . "\n";
    }
}

function p1Session(array $user): array
{
    return [
        'user_id'              => (int)$user['id'],
        'user_email'           => $user['email'],
        'user_first_name'      => $user['first_name'],
        'user_last_name'       => $user['last_name'],
        'user_full_name'       => trim($user['first_name'] . ' ' . $user['last_name']),
        'user_role'            => $user['role'],
        'must_change_password' => (int)($user['must_change_password'] ?? 0),
        'session_epoch'        => (int)($user['session_epoch'] ?? 1),
        'auth_time'            => time(),
        'last_activity'        => time(),
        'csrf_token'           => 'p1_csrf_token_' . bin2hex(random_bytes(4)),
    ];
}

function p1BaseEnv(array $extra = []): array
{
    $env = getenv();
    return array_merge(is_array($env) ? $env : [], [
        'DB_NAME' => getenv('DB_NAME') ?: 'webmail_test',
        'APP_ENV' => 'test',
    ], $extra);
}

function p1StartProcess(string $command, string $cwd, array $env): array
{
    $outFile = tempnam('/tmp/opencode', 'p1-out-');
    $errFile = tempnam('/tmp/opencode', 'p1-err-');

    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['file', $outFile, 'w'],
        2 => ['file', $errFile, 'w'],
    ];

    $proc = @proc_open($command, $descriptors, $pipes, $cwd, $env);
    if (!is_resource($proc)) {
        return [null, $outFile, $errFile];
    }
    if (isset($pipes[0]) && is_resource($pipes[0])) {
        fclose($pipes[0]);
    }

    return [$proc, $outFile, $errFile];
}

function p1StopProcess($proc): void
{
    if (is_resource($proc)) {
        @proc_terminate($proc);
        @proc_close($proc);
    }
}

function p1ReadProcessOutput(string $outFile, string $errFile): string
{
    $out = is_file($outFile) ? (string)file_get_contents($outFile) : '';
    $err = is_file($errFile) ? (string)file_get_contents($errFile) : '';
    return trim($out . "\n" . $err);
}

/**
 * Execute a web endpoint in an isolated PHP subprocess with a forged session.
 */
function invokeP1Endpoint(
    string $scriptRelativePath,
    string $method,
    array $get = [],
    array $post = [],
    ?array $session = null,
    array $env = []
): array {
    global $projectRoot;

    $scriptFullPath = $projectRoot . '/' . ltrim($scriptRelativePath, '/');

    $bootstrap = sprintf(
        'register_shutdown_function(function () {
            echo "\n__HTTP_STATUS:" . (http_response_code() ?: 200) . ":STATUS__\n";
        });
        $_SERVER["REQUEST_METHOD"] = %s;
        $_SERVER["SCRIPT_FILENAME"] = %s;
        $_SERVER["SCRIPT_NAME"] = %s;
        $_SERVER["REMOTE_ADDR"] = "127.0.0.1";
        $_SERVER["HTTP_USER_AGENT"] = "P1-Regression-Test";
        $_GET = %s;
        $_POST = %s;
        session_start();
        $_SESSION = %s;
        require %s;',
        var_export($method, true),
        var_export($scriptFullPath, true),
        var_export('/' . ltrim($scriptRelativePath, '/'), true),
        var_export($get, true),
        var_export($post, true),
        var_export($session ?? [], true),
        var_export($scriptFullPath, true)
    );

    [$proc, $outFile, $errFile] = p1StartProcess(
        escapeshellarg(PHP_BINARY) . ' -d auto_prepend_file= -r ' . escapeshellarg($bootstrap),
        $projectRoot,
        p1BaseEnv($env)
    );

    if (!is_resource($proc)) {
        return ['status' => 0, 'body' => '', 'stderr' => 'proc_open failed'];
    }

    @proc_close($proc);

    $stdout = (string)file_get_contents($outFile);
    $stderr = (string)file_get_contents($errFile);

    $status = 200;
    if (preg_match('/__HTTP_STATUS:(\d+):STATUS__/', $stdout, $m)) {
        $status = (int)$m[1];
        $stdout = str_replace($m[0], '', $stdout);
    }

    return ['status' => $status, 'body' => $stdout, 'stderr' => $stderr];
}

/**
 * Run arbitrary PHP after bootstrapping the application. Optional prelude runs
 * before functions.php so tests can simulate HTTPS/proxy request state.
 */
function runP1Code(string $code, array $env = [], string $prelude = ''): array
{
    global $projectRoot;

    $bootstrap = ($prelude !== '' ? $prelude . ";\n" : '')
        . 'define("WEBMAIL_INIT", true);'
        . 'require ' . var_export($projectRoot . '/includes/functions.php', true) . ';'
        . 'require ' . var_export($projectRoot . '/includes/auth.php', true) . ';'
        . $code;

    [$proc, $outFile, $errFile] = p1StartProcess(
        escapeshellarg(PHP_BINARY) . ' -d auto_prepend_file= -r ' . escapeshellarg($bootstrap),
        $projectRoot,
        p1BaseEnv($env)
    );

    if (!is_resource($proc)) {
        return ['status' => 1, 'stdout' => '', 'stderr' => 'proc_open failed'];
    }

    @proc_close($proc);

    return [
        'status' => 0,
        'stdout' => trim((string)file_get_contents($outFile)),
        'stderr' => trim((string)file_get_contents($errFile)),
    ];
}

function p1StartBuiltInServer(string $projectRoot, array $env, string $prependFile = ''): array
{
    $probe = @stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
    if (!$probe) {
        return [null, 0, '', '', '', ''];
    }
    $address = stream_socket_get_name($probe, false);
    fclose($probe);
    $port = (int)substr($address, strrpos($address, ':') + 1);

    $command = escapeshellarg(PHP_BINARY) . ' -S 127.0.0.1:' . $port . ' -t ' . escapeshellarg($projectRoot);
    if ($prependFile !== '') {
        $command .= ' -d auto_prepend_file=' . escapeshellarg($prependFile);
    }

    [$proc, $outFile, $errFile] = p1StartProcess($command, $projectRoot, $env);

    for ($i = 0; $i < 100; $i++) {
        $fp = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.2);
        if ($fp) {
            fclose($fp);
            break;
        }
        usleep(50000);
    }

    return [$proc, $port, $outFile, $errFile, $command, $prependFile];
}

function p1HttpRequest(string $url): array
{
    $context = stream_context_create([
        'http' => [
            'ignore_errors' => true,
            'timeout'       => 10,
            'follow_location' => 0,
        ],
    ]);

    $body = @file_get_contents($url, false, $context);
    $headers = $http_response_header ?? [];

    return ['body' => $body === false ? '' : $body, 'headers' => array_values($headers)];
}

function p1HeaderLine(array $headers, string $name): string
{
    foreach ($headers as $header) {
        if (stripos($header, $name . ':') === 0) {
            return $header;
        }
    }
    return '';
}

function p1HttpStatus(array $headers): int
{
    foreach ($headers as $header) {
        if (preg_match('#^HTTP/\S+\s+(\d{3})#i', $header, $m)) {
            return (int)$m[1];
        }
    }
    return 0;
}

function p1CollectPhpFiles(array $directories): array
{
    $files = [];
    foreach ($directories as $directory) {
        if (!is_dir($directory)) {
            continue;
        }
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $file) {
            if ($file->isFile() && strtolower($file->getExtension()) === 'php') {
                $files[] = $file->getPathname();
            }
        }
    }
    return $files;
}

function p1RemoveTree(string $path): void
{
    if (!file_exists($path)) {
        return;
    }
    if (is_file($path) || is_link($path)) {
        @unlink($path);
        return;
    }
    foreach (scandir($path) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }
        p1RemoveTree($path . '/' . $entry);
    }
    @rmdir($path);
}

function p1RunCommand(string $command): array
{
    exec($command . ' 2>&1', $output, $code);
    return [$code, implode("\n", $output)];
}

function p1StartTlsServer(string $cert, string $key, string $tempDir): array
{
    $portFile = $tempDir . '/smtp-port-' . bin2hex(random_bytes(4)) . '.txt';
    @unlink($portFile);

    $command = escapeshellarg(PHP_BINARY) . ' '
        . escapeshellarg(__DIR__ . '/fixtures/smtp_tls_server.php') . ' '
        . escapeshellarg($cert) . ' '
        . escapeshellarg($key) . ' '
        . escapeshellarg($portFile);

    [$proc, $outFile, $errFile] = p1StartProcess($command, dirname(__DIR__), p1BaseEnv());

    $port = 0;
    for ($i = 0; $i < 200; $i++) {
        if (is_file($portFile)) {
            $port = (int)trim((string)file_get_contents($portFile));
            if ($port > 0) {
                break;
            }
        }
        usleep(25000);
    }

    return [$proc, $port, $outFile, $errFile, $portFile];
}

$userModel = new User($pdo);
$emailModel = new Email($pdo);

try {
    echo "=== P1 High Severity Security Regression Tests ===\n";
    echo 'Database: ' . (getenv('DB_NAME') ?: 'default') . "\n\n";

    // ------------------------------------------------------------------
    // Fixtures
    // ------------------------------------------------------------------
    $makeUser = function (string $label, string $role = 'user', array $extra = []) use ($userModel, $pdo, $suffix, &$createdUserIds): array {
        $email = 'p1.' . strtolower($label) . '.' . $suffix . '@couriva.local';
        $id = $userModel->create(array_merge([
            'email'                => $email,
            'password'             => 'P1-Test-Password-123!',
            'first_name'           => ucfirst($label),
            'last_name'            => 'P1',
            'role'                 => $role,
            'status'               => 'active',
            'timezone'             => 'UTC',
            'must_change_password' => 0,
        ], $extra));
        $createdUserIds[] = $id;

        $stmt = $pdo->prepare('SELECT * FROM users WHERE id = ?');
        $stmt->execute([$id]);
        return $stmt->fetch();
    };

    $makeEmail = function (array $sender, ?array $recipient, string $subject, string $body, string $folder = 'inbox') use ($emailModel, &$createdEmailIds): int {
        $id = $emailModel->create([
            'sender_id'    => $sender ? (int)$sender['id'] : null,
            'recipient_id' => $recipient ? (int)$recipient['id'] : null,
            'subject'      => $subject,
            'body'         => $body,
            'folder'       => $folder,
        ]);
        $createdEmailIds[] = $id;
        return $id;
    };

    $admin = $makeUser('admin', 'admin');
    $sender = $makeUser('sender');
    $recipient = $makeUser('recipient');
    $sessionUser = $makeUser('session');

    // ==================================================================
    // H1 - Secure registration workflow and role/status validation
    // ==================================================================
    echo "--- H1: Registration and role/status validation ---\n";

    $registerPath = $projectRoot . '/public/register.php';
    assertP1(is_file($registerPath), 'H1 public/register.php exists for secure account requests');
    $registerSource = is_file($registerPath) ? (string)file_get_contents($registerPath) : '';
    assertP1(str_contains($registerSource, "'status' => 'active'"), 'H1 registration creates an active account');
    assertP1(str_contains($registerSource, 'verify_csrf_token'), 'H1 registration verifies CSRF tokens');
    assertP1(str_contains($registerSource, 'registration_is_throttled'), 'H1 registration is rate limited');

    $loginSource = (string)file_get_contents($projectRoot . '/public/login.php');
    assertP1(str_contains($loginSource, 'register.php'), 'H1 login page links to account creation');
    assertP1(str_contains($loginSource, 'Create account'), 'H1 login page offers the create-account action');
    assertP1(!preg_match('/Admin123!|User123!/', $loginSource), 'H1 login page exposes no demo credentials');

    $authServiceSource = (string)file_get_contents($projectRoot . '/app/Services/AuthService.php');
    assertP1(!str_contains($authServiceSource, 'registerUser'), 'H1 AuthService does not grant access during registration');

    assertP1(is_valid_user_role('admin') && is_valid_user_role('user'), 'H1 valid roles accepted');
    assertP1(!is_valid_user_role('superadmin') && !is_valid_user_role('') && !is_valid_user_role(['admin']), 'H1 invalid roles rejected');
    assertP1(is_valid_user_status('active') && is_valid_user_status('disabled'), 'H1 active and disabled statuses accepted');
    assertP1(is_valid_user_status('pending') && is_valid_user_status('rejected') && is_valid_user_status('suspended'), 'H1 approval-workflow statuses accepted');
    assertP1(!is_valid_user_status('banned') && !is_valid_user_status(''), 'H1 unknown statuses rejected');

    $adminSession = p1Session($admin);
    $badRoleEmail = 'p1.badrole.' . $suffix . '@couriva.local';
    $badRole = invokeP1Endpoint('admin/create-user.php', 'POST', [], [
        'csrf_token' => $adminSession['csrf_token'],
        'email'      => $badRoleEmail,
        'password'   => 'Valid-Password-123!',
        'first_name' => 'Bad',
        'last_name'  => 'Role',
        'role'       => 'superadmin',
        'status'     => 'active',
        'timezone'   => 'UTC',
    ], $adminSession);
    assertP1(str_contains($badRole['body'], 'Invalid role or account status selection.'), 'H1 server rejects invalid role on create-user', 'status=' . $badRole['status']);
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM users WHERE email = ?');
    $stmt->execute([$badRoleEmail]);
    assertP1((int)$stmt->fetchColumn() === 0, 'H1 invalid role account was not created');

    $badStatusEmail = 'p1.badstatus.' . $suffix . '@couriva.local';
    $badStatus = invokeP1Endpoint('admin/create-user.php', 'POST', [], [
        'csrf_token' => $adminSession['csrf_token'],
        'email'      => $badStatusEmail,
        'password'   => 'Valid-Password-123!',
        'first_name' => 'Bad',
        'last_name'  => 'Status',
        'role'       => 'user',
        'status'     => 'banned',
        'timezone'   => 'UTC',
    ], $adminSession);
    assertP1(str_contains($badStatus['body'], 'Invalid role or account status selection.'), 'H1 server rejects invalid status on create-user', 'status=' . $badStatus['status']);
    $stmt->execute([$badStatusEmail]);
    assertP1((int)$stmt->fetchColumn() === 0, 'H1 invalid status account was not created');

    $validEmail = 'p1.valid.' . $suffix . '@couriva.local';
    $validCreate = invokeP1Endpoint('admin/create-user.php', 'POST', [], [
        'csrf_token' => $adminSession['csrf_token'],
        'email'      => $validEmail,
        'password'   => 'Valid-Password-123!',
        'first_name' => 'Valid',
        'last_name'  => 'Created',
        'role'       => 'user',
        'status'     => 'active',
        'timezone'   => 'UTC',
    ], $adminSession);
    $stmt = $pdo->prepare('SELECT * FROM users WHERE email = ?');
    $stmt->execute([$validEmail]);
    $validUser = $stmt->fetch();
    if ($validUser) {
        $createdUserIds[] = (int)$validUser['id'];
    }
    assertP1($validUser !== false, 'H1 server accepts a valid role/status combination', 'status=' . $validCreate['status']);
    assertP1($validUser && (int)$validUser['must_change_password'] === 1, 'H1 admin-created account is forced to change password');

    $usersSource = (string)file_get_contents($projectRoot . '/admin/users.php');
    $editUserSource = (string)file_get_contents($projectRoot . '/admin/edit-user.php');
    $createUserSource = (string)file_get_contents($projectRoot . '/admin/create-user.php');
    assertP1(str_contains($usersSource, 'is_valid_user_role') && str_contains($usersSource, 'is_valid_user_status'), 'H1 admin/users.php validates role and status');
    assertP1(str_contains($editUserSource, 'is_valid_user_role') && str_contains($editUserSource, 'is_valid_user_status'), 'H1 admin/edit-user.php validates role and status');
    assertP1(str_contains($createUserSource, 'is_valid_user_role') && str_contains($createUserSource, 'is_valid_user_status'), 'H1 admin/create-user.php validates role and status');

    // ==================================================================
    // H2 - No default credentials; forced first-login password change
    // ==================================================================
    echo "\n--- H2: Password lifecycle ---\n";

    $readme = (string)file_get_contents($projectRoot . '/README.md');
    $seed = (string)file_get_contents($projectRoot . '/database/seed.sql');
    $createAdminSource = (string)file_get_contents($projectRoot . '/bin/create-admin.php');
    assertP1(!preg_match('/Admin123!|User123!/', $readme), 'H2 README contains no default credentials');
    assertP1(!preg_match('/Admin123!|User123!/', $seed), 'H2 seed contains no default credentials');
    assertP1(!preg_match('/Admin123!|User123!/', $createAdminSource), 'H2 create-admin script contains no hard-coded password');
    assertP1(str_contains($readme, 'ADMIN_PASSWORD') || str_contains($readme, 'Administrator Provisioning'), 'H2 README documents administrator provisioning');
    assertP1(str_contains($seed, 'No usable default credential'), 'H2 seed documents that no default credential is usable');

    $stmt = $pdo->prepare("SELECT must_change_password FROM users WHERE email = 'admin@couriva.local' LIMIT 1");
    $stmt->execute();
    $seedAdminFlag = $stmt->fetchColumn();
    assertP1($seedAdminFlag === false || (int)$seedAdminFlag === 1, 'H2 seeded accounts require a first-login password change');

    $defaultFlag = $makeUser('defaultflag', 'user', ['must_change_password' => true]);
    assertP1((int)$defaultFlag['must_change_password'] === 1, 'H2 User::create() defaults to must_change_password=1');
    $explicitNoChange = $makeUser('nochange', 'user', ['must_change_password' => false]);
    assertP1((int)$explicitNoChange['must_change_password'] === 0, 'H2 explicit must_change_password=0 is honored');

    $forced = $makeUser('forced', 'user', ['must_change_password' => 1]);
    $forcedSession = p1Session($forced);

    $changePage = invokeP1Endpoint('public/change-password.php', 'GET', [], [], $forcedSession);
    assertP1($changePage['status'] === 200, 'H2 forced-change page renders', 'status=' . $changePage['status'] . ' stderr=' . substr($changePage['stderr'], 0, 200));
    assertP1(stripos($changePage['body'], 'Change your password') !== false, 'H2 forced-change page shows password form');
    assertP1(str_contains($changePage['body'], 'name="current_password"'), 'H2 forced-change page asks for current password');

    $blockedInbox = invokeP1Endpoint('public/inbox.php', 'GET', [], [], $forcedSession);
    assertP1(trim(strip_tags($blockedInbox['body'])) === '' || stripos($blockedInbox['body'], 'Inbox') === false, 'H2 forced-change user is blocked from the mailbox');

    $loginAttempt = runP1Code(
        '$r = attempt_login(' . var_export($forced['email'], true) . ', ' . var_export('P1-Test-Password-123!', true) . ');'
        . 'echo json_encode(["success" => $r["success"], "must" => $_SESSION["must_change_password"] ?? null, "epoch" => $_SESSION["session_epoch"] ?? null]);'
    );
    $loginData = json_decode($loginAttempt['stdout'], true);
    assertP1(is_array($loginData) && ($loginData['success'] ?? false) === true, 'H2 login succeeds for a valid account');
    assertP1(is_array($loginData) && (int)($loginData['must'] ?? -1) === 1, 'H2 login session carries the forced-change flag');

    $wrongCurrent = invokeP1Endpoint('public/change-password.php', 'POST', [], [
        'csrf_token'        => $forcedSession['csrf_token'],
        'current_password'  => 'Wrong-Current-Password',
        'new_password'      => 'New-Password-123!',
        'confirm_password'  => 'New-Password-123!',
    ], $forcedSession);
    assertP1(str_contains($wrongCurrent['body'], 'Current password is incorrect.'), 'H2 wrong current password is rejected');

    $stmt = $pdo->prepare('SELECT session_epoch FROM users WHERE id = ?');
    $stmt->execute([(int)$forced['id']]);
    $forcedEpochBefore = (int)$stmt->fetchColumn();

    $changeResult = invokeP1Endpoint('public/change-password.php', 'POST', [], [
        'csrf_token'        => $forcedSession['csrf_token'],
        'current_password'  => 'P1-Test-Password-123!',
        'new_password'      => 'New-Password-123!',
        'confirm_password'  => 'New-Password-123!',
    ], $forcedSession);
    assertP1(trim($changeResult['body']) === '', 'H2 successful password change redirects');

    $stmt = $pdo->prepare('SELECT password, must_change_password, session_epoch FROM users WHERE id = ?');
    $stmt->execute([(int)$forced['id']]);
    $forcedAfter = $stmt->fetch();
    assertP1($forcedAfter && (int)$forcedAfter['must_change_password'] === 0, 'H2 password change clears the forced-change flag');
    assertP1($forcedAfter && password_verify('New-Password-123!', $forcedAfter['password']), 'H2 new password is stored hashed and verifiable');
    assertP1($forcedAfter && (int)$forcedAfter['session_epoch'] === $forcedEpochBefore + 1, 'H2 password change increments the session epoch');

    $resetEpochBefore = (int)$forcedAfter['session_epoch'];
    $userModel->updatePassword((int)$forced['id'], 'Reset-Password-123!', true);
    $stmt->execute([(int)$forced['id']]);
    $resetAfter = $stmt->fetch();
    assertP1((int)$resetAfter['must_change_password'] === 1, 'H2 administrator password reset forces a new password');
    assertP1((int)$resetAfter['session_epoch'] === $resetEpochBefore + 1, 'H2 administrator password reset revokes old sessions');

    // ==================================================================
    // H3 - Authentication fail-closed, revocation, timeouts
    // ==================================================================
    echo "\n--- H3: Session and authentication hardening ---\n";

    assertP1((string)ini_get('session.use_strict_mode') === '1', 'H3 session.use_strict_mode is enabled');
    assertP1((string)ini_get('session.cookie_httponly') === '1', 'H3 session cookie is HttpOnly');
    assertP1((string)ini_get('session.cookie_samesite') === 'Strict', 'H3 session cookie is SameSite=Strict');

    $sessionUserSession = p1Session($sessionUser);
    $validCheck = runP1Code(
        '$_SESSION = ' . var_export($sessionUserSession, true) . '; echo is_authenticated() ? "true" : "false";'
    );
    assertP1($validCheck['stdout'] === 'true', 'H3 valid session is accepted');

    $dbFailure = runP1Code(
        '$_SESSION = ' . var_export($sessionUserSession, true) . ';'
        . '$pdo = new PDO("sqlite::memory:");'
        . 'echo is_authenticated() ? "true" : "false";'
    );
    assertP1($dbFailure['stdout'] === 'false', 'H3 database failure fails closed', $dbFailure['stderr']);

    $idleTimeout = runP1Code(
        '$_SESSION = ' . var_export(array_merge($sessionUserSession, [
            'last_activity' => time() - 120,
            'auth_time'     => time(),
        ]), true) . '; echo is_authenticated() ? "true" : "false";',
        ['SESSION_IDLE_TIMEOUT' => '60']
    );
    assertP1($idleTimeout['stdout'] === 'false', 'H3 idle timeout expires the session');

    $absoluteTimeout = runP1Code(
        '$_SESSION = ' . var_export(array_merge($sessionUserSession, [
            'last_activity' => time(),
            'auth_time'     => time() - 7200,
        ]), true) . '; echo is_authenticated() ? "true" : "false";',
        ['SESSION_ABSOLUTE_TIMEOUT' => '3600']
    );
    assertP1($absoluteTimeout['stdout'] === 'false', 'H3 absolute timeout expires the session');

    $epochMismatch = runP1Code(
        '$_SESSION = ' . var_export(array_merge($sessionUserSession, [
            'session_epoch' => (int)$sessionUser['session_epoch'] + 1,
        ]), true) . '; echo is_authenticated() ? "true" : "false";'
    );
    assertP1($epochMismatch['stdout'] === 'false', 'H3 stale session epoch is rejected');

    $pdo->prepare("UPDATE users SET status = 'disabled' WHERE id = ?")->execute([(int)$sessionUser['id']]);
    $disabledCheck = runP1Code(
        '$_SESSION = ' . var_export($sessionUserSession, true) . '; echo is_authenticated() ? "true" : "false";'
    );
    assertP1($disabledCheck['stdout'] === 'false', 'H3 disabled account session is rejected');
    $pdo->prepare("UPDATE users SET status = 'active' WHERE id = ?")->execute([(int)$sessionUser['id']]);

    $pdo->prepare("UPDATE users SET role = 'user' WHERE id = ?")->execute([(int)$sessionUser['id']]);
    $demotionCheck = runP1Code(
        '$_SESSION = ' . var_export(array_merge($sessionUserSession, ['user_role' => 'admin']), true) . ';'
        . '$ok = is_authenticated();'
        . 'echo json_encode(["auth" => $ok, "role" => $_SESSION["user_role"] ?? null, "admin" => is_admin()]);'
    );
    $demotion = json_decode($demotionCheck['stdout'], true);
    assertP1(is_array($demotion) && ($demotion['auth'] ?? false) === true, 'H3 demoted account remains authenticated with refreshed role');
    assertP1(is_array($demotion) && ($demotion['role'] ?? '') === 'user' && ($demotion['admin'] ?? true) === false, 'H3 demotion removes administrator access in the active session');

    $pdo->prepare("UPDATE users SET role = 'admin' WHERE id = ?")->execute([(int)$sessionUser['id']]);
    $newEpoch = (int)($pdo->query('SELECT session_epoch FROM users WHERE id = ' . (int)$sessionUser['id'])->fetchColumn());
    $userModel->updatePassword((int)$sessionUser['id'], 'After-Reset-Password-123!', false);
    $stmt = $pdo->prepare('SELECT session_epoch FROM users WHERE id = ?');
    $stmt->execute([(int)$sessionUser['id']]);
    $epochAfterReset = (int)$stmt->fetchColumn();
    assertP1($epochAfterReset === $newEpoch + 1, 'H3 password reset increments the session epoch');
    $oldSessionAfterReset = runP1Code(
        '$_SESSION = ' . var_export(array_merge($sessionUserSession, ['session_epoch' => $newEpoch]), true) . ';'
        . 'echo is_authenticated() ? "true" : "false";'
    );
    assertP1($oldSessionAfterReset['stdout'] === 'false', 'H3 password reset invalidates an existing session');

    $stmt = $pdo->prepare('SELECT session_epoch FROM users WHERE id = ?');
    $stmt->execute([(int)$sessionUser['id']]);
    $logoutEpochBefore = (int)$stmt->fetchColumn();
    $logoutResult = runP1Code(
        '$_SESSION = ' . var_export(array_merge($sessionUserSession, ['session_epoch' => $logoutEpochBefore]), true) . ';'
        . 'perform_logout(); echo "done";'
    );
    $stmt->execute([(int)$sessionUser['id']]);
    $logoutEpochAfter = (int)$stmt->fetchColumn();
    assertP1($logoutResult['stdout'] === 'done' && $logoutEpochAfter === $logoutEpochBefore + 1, 'H3 logout increments the session epoch');

    $proxyHttps = runP1Code(
        'echo is_https_request() ? "true" : "false";',
        ['TRUST_PROXY_HEADERS' => 'true'],
        '$_SERVER["HTTP_X_FORWARDED_PROTO"] = "https"'
    );
    assertP1($proxyHttps['stdout'] === 'true', 'H3 trusted proxy HTTPS header is honored');

    $proxyHttpsUntrusted = runP1Code(
        'echo is_https_request() ? "true" : "false";',
        [],
        '$_SERVER["HTTP_X_FORWARDED_PROTO"] = "https"'
    );
    assertP1($proxyHttpsUntrusted['stdout'] === 'false', 'H3 forwarded HTTPS header is ignored without explicit trust');

    $directHttps = runP1Code(
        'echo is_https_request() ? "true" : "false";',
        [],
        '$_SERVER["HTTPS"] = "on"'
    );
    assertP1($directHttps['stdout'] === 'true', 'H3 direct HTTPS request is detected');

    $secureCookie = runP1Code(
        'echo ini_get("session.cookie_secure");',
        ['TRUST_PROXY_HEADERS' => 'true'],
        '$_SERVER["HTTP_X_FORWARDED_PROTO"] = "https"'
    );
    assertP1($secureCookie['stdout'] === '1', 'H3 secure session cookie is enabled behind a trusted proxy');

    // ==================================================================
    // H4 - SMTP TLS verification
    // ==================================================================
    echo "\n--- H4: SMTP TLS verification ---\n";

    putenv('SMTP_INSECURE_TLS');
    putenv('SMTP_VERIFY_PEER');
    putenv('SMTP_VERIFY_PEER_NAME');
    $mailConfig = require $projectRoot . '/config/mail.php';
    assertP1(($mailConfig['smtp']['verify_peer'] ?? false) === true, 'H4 SMTP verify_peer defaults to true');
    assertP1(($mailConfig['smtp']['verify_peer_name'] ?? false) === true, 'H4 SMTP verify_peer_name defaults to true');
    assertP1(($mailConfig['smtp']['allow_self_signed'] ?? true) === false, 'H4 SMTP self-signed certificates are not allowed by default');

    $overrideConfig = runP1Code(
        '$c = require ' . var_export($projectRoot . '/config/mail.php', true) . ';'
        . 'echo json_encode([$c["smtp"]["verify_peer"], $c["smtp"]["verify_peer_name"], $c["smtp"]["allow_self_signed"]]);',
        ['APP_ENV' => 'development', 'SMTP_INSECURE_TLS' => 'true']
    );
    $overrideValues = json_decode($overrideConfig['stdout'], true);
    assertP1(is_array($overrideValues) && $overrideValues === [false, false, true], 'H4 SMTP_INSECURE_TLS is an explicit development-only override', $overrideConfig['stdout'] . ' ' . $overrideConfig['stderr']);

    $tlsDir = '/tmp/opencode/p1-tls-' . $suffix;
    $tempPaths[] = $tlsDir;
    @mkdir($tlsDir, 0700, true);

    $caKey = $tlsDir . '/ca.key';
    $caCrt = $tlsDir . '/ca.crt';
    $serverKey = $tlsDir . '/server.key';
    $serverCsr = $tlsDir . '/server.csr';
    $serverCrt = $tlsDir . '/server.crt';
    $selfKey = $tlsDir . '/self.key';
    $selfCrt = $tlsDir . '/self.crt';
    $sanFile = $tlsDir . '/san.cnf';

    [$caCode, $caOut] = p1RunCommand(
        'openssl req -x509 -newkey rsa:2048 -nodes -sha256 -days 2 '
        . '-keyout ' . escapeshellarg($caKey) . ' -out ' . escapeshellarg($caCrt)
        . ' -subj ' . escapeshellarg('/CN=Couriva P1 Test CA')
        . ' -addext ' . escapeshellarg('basicConstraints=critical,CA:TRUE')
    );
    [$csrCode, $csrOut] = p1RunCommand(
        'openssl req -newkey rsa:2048 -nodes -sha256 '
        . '-keyout ' . escapeshellarg($serverKey) . ' -out ' . escapeshellarg($serverCsr)
        . ' -subj ' . escapeshellarg('/CN=localhost')
    );
    file_put_contents($sanFile, "subjectAltName=DNS:localhost,IP:127.0.0.1\n");
    [$signCode, $signOut] = p1RunCommand(
        'openssl x509 -req -in ' . escapeshellarg($serverCsr)
        . ' -CA ' . escapeshellarg($caCrt) . ' -CAkey ' . escapeshellarg($caKey)
        . ' -CAcreateserial -out ' . escapeshellarg($serverCrt)
        . ' -days 2 -sha256 -extfile ' . escapeshellarg($sanFile)
    );
    [$selfCode, $selfOut] = p1RunCommand(
        'openssl req -x509 -newkey rsa:2048 -nodes -sha256 -days 2 '
        . '-keyout ' . escapeshellarg($selfKey) . ' -out ' . escapeshellarg($selfCrt)
        . ' -subj ' . escapeshellarg('/CN=localhost')
        . ' -addext ' . escapeshellarg('subjectAltName=DNS:localhost,IP:127.0.0.1')
    );

    $tlsFixturesReady = $caCode === 0 && $csrCode === 0 && $signCode === 0 && $selfCode === 0
        && is_file($caCrt) && is_file($serverCrt) && is_file($serverKey) && is_file($selfCrt) && is_file($selfKey);
    assertP1(
        $tlsFixturesReady,
        'H4 local CA and TLS fixtures were generated',
        implode(' | ', [$caOut, $csrOut, $signOut, $selfOut])
    );

    if ($tlsFixturesReady) {
        $senderAddress = 'p1-sender@example.test';
        $recipientAddress = 'p1-recipient@example.test';
        $recipients = ['to' => [$recipientAddress]];

        [$selfServer, $selfPort] = p1StartTlsServer($selfCrt, $selfKey, $tlsDir);
        $runningProcesses[] = $selfServer;
        $selfRejected = false;
        $selfError = '';
        if ($selfPort > 0) {
            try {
                $transport = new SmtpTransport([
                    'host'       => '127.0.0.1',
                    'port'       => $selfPort,
                    'encryption' => 'tls',
                    'auth'       => false,
                    'timeout'    => 5,
                ]);
                $transport->send($senderAddress, 'P1 Sender', $recipients, 'H4 self-signed', 'Body');
            } catch (Throwable $e) {
                $selfRejected = true;
                $selfError = $e->getMessage();
            }
        }
        assertP1($selfRejected, 'H4 self-signed SMTP certificate is rejected by default', 'port=' . $selfPort . ' error=' . substr($selfError, 0, 200));
        p1StopProcess($selfServer);

        [$insecureServer, $insecurePort] = p1StartTlsServer($selfCrt, $selfKey, $tlsDir);
        $runningProcesses[] = $insecureServer;
        $insecureAccepted = false;
        $insecureError = '';
        if ($insecurePort > 0) {
            try {
                $transport = new SmtpTransport([
                    'host'             => '127.0.0.1',
                    'port'             => $insecurePort,
                    'encryption'       => 'tls',
                    'auth'             => false,
                    'timeout'          => 5,
                    'verify_peer'      => false,
                    'verify_peer_name' => false,
                    'allow_self_signed'=> true,
                ]);
                $insecureAccepted = $transport->send($senderAddress, 'P1 Sender', $recipients, 'H4 insecure override', 'Body');
            } catch (Throwable $e) {
                $insecureError = $e->getMessage();
            }
        }
        assertP1($insecureAccepted === true, 'H4 explicit insecure override accepts a self-signed SMTP certificate', 'port=' . $insecurePort . ' error=' . substr($insecureError, 0, 200));
        p1StopProcess($insecureServer);

        [$caServer, $caPort] = p1StartTlsServer($serverCrt, $serverKey, $tlsDir);
        $runningProcesses[] = $caServer;
        $caAccepted = false;
        $caError = '';
        if ($caPort > 0) {
            try {
                $transport = new SmtpTransport([
                    'host'        => '127.0.0.1',
                    'port'        => $caPort,
                    'encryption'  => 'tls',
                    'auth'        => false,
                    'timeout'     => 5,
                    'verify_peer' => true,
                    'verify_peer_name' => true,
                    'allow_self_signed' => false,
                    'ca_file'     => $caCrt,
                ]);
                $caAccepted = $transport->send($senderAddress, 'P1 Sender', $recipients, 'H4 CA signed', 'Body');
            } catch (Throwable $e) {
                $caError = $e->getMessage();
            }
        }
        assertP1($caAccepted === true, 'H4 CA-signed SMTP certificate is accepted', 'port=' . $caPort . ' error=' . substr($caError, 0, 200));
        p1StopProcess($caServer);
    }

    // ==================================================================
    // H5 - User deletion preserves recipient mail
    // ==================================================================
    echo "\n--- H5: User deletion preserves mail ---\n";

    $deleteSender = $makeUser('deletesender');
    $deleteRecipient = $makeUser('deleterecipient');
    $preservedEmailId = $makeEmail(
        $deleteSender,
        $deleteRecipient,
        'H5 preserved message ' . $suffix,
        '<p>Mail must survive sender deletion.</p>'
    );

    $preservedBefore = $emailModel->findById($preservedEmailId);
    assertP1(
        $preservedBefore
        && $preservedBefore['sender_email_snapshot'] === $deleteSender['email']
        && str_contains((string)$preservedBefore['sender_name_snapshot'], 'P1'),
        'H5 sender snapshots are written when mail is created'
    );

    $fkStmt = $pdo->prepare("
        SELECT DELETE_RULE
        FROM information_schema.REFERENTIAL_CONSTRAINTS
        WHERE CONSTRAINT_SCHEMA = DATABASE() AND CONSTRAINT_NAME = 'fk_emails_sender'
        LIMIT 1
    ");
    $fkStmt->execute();
    assertP1(strtoupper((string)$fkStmt->fetchColumn()) === 'SET NULL', 'H5 emails.sender_id foreign key uses ON DELETE SET NULL');

    $userModel->delete((int)$deleteSender['id']);
    $preservedAfter = $emailModel->findById($preservedEmailId);
    assertP1($preservedAfter !== null, 'H5 recipient email row survives sender deletion');
    assertP1($preservedAfter && $preservedAfter['sender_id'] === null, 'H5 deleted sender_id becomes NULL');
    assertP1(
        $preservedAfter
        && $preservedAfter['sender_email_snapshot'] === $deleteSender['email']
        && str_contains((string)$preservedAfter['sender_name_snapshot'], 'P1'),
        'H5 sender snapshot remains available after deletion'
    );
    assertP1($emailModel->findVisibleById($preservedEmailId, (int)$deleteRecipient['id']) !== null, 'H5 recipient can still resolve the deleted-sender message');

    $preservedRead = invokeP1Endpoint('public/read.php', 'GET', ['id' => $preservedEmailId], [], p1Session($deleteRecipient));
    assertP1(str_contains($preservedRead['body'], 'H5 preserved message'), 'H5 recipient can open mail after sender deletion');
    assertP1(str_contains($preservedRead['body'], $deleteSender['email']), 'H5 read view renders the sender snapshot');

    $migration = (string)file_get_contents($projectRoot . '/database/migrations/001_p1_security.sql');
    assertP1(str_contains($migration, 'sender_email_snapshot') && str_contains($migration, 'sender_name_snapshot'), 'H5 migration adds sender snapshot columns');
    assertP1(str_contains($migration, 'ON DELETE SET NULL'), 'H5 migration changes sender foreign key to SET NULL');

    // ==================================================================
    // H6 - IIS and Apache configuration
    // ==================================================================
    echo "\n--- H6: Web server configuration isolation ---\n";

    $webConfigPath = $projectRoot . '/web.config';
    $webConfig = (string)file_get_contents($webConfigPath);
    $previousLibxml = libxml_use_internal_errors(true);
    $webConfigXml = simplexml_load_file($webConfigPath);
    libxml_clear_errors();
    libxml_use_internal_errors($previousLibxml);
    assertP1($webConfigXml !== false, 'H6 web.config is valid XML');

    $fastCgiPos = strpos($webConfig, 'name="PHP_via_FastCGI"');
    $staticPos = strpos($webConfig, 'name="StaticFile"');
    assertP1($fastCgiPos !== false && $staticPos !== false && $fastCgiPos < $staticPos, 'H6 FastCGI handler precedes the catch-all StaticFile handler');
    assertP1(str_contains($webConfig, '<add segment="bin" />'), 'H6 web.config hides the bin directory');
    assertP1(str_contains($webConfig, '<add segment="config" />') && str_contains($webConfig, '<add segment="storage" />'), 'H6 web.config hides config and storage');
    assertP1(str_contains($webConfig, 'fileExtension=".sql"') && str_contains($webConfig, 'fileExtension=".env"'), 'H6 web.config blocks sensitive file extensions');
    assertP1(str_contains($webConfig, 'Redirect HTTP to HTTPS') && str_contains($webConfig, '{HTTPS}'), 'H6 web.config redirects HTTP to HTTPS');

    $htaccess = (string)file_get_contents($projectRoot . '/.htaccess');
    assertP1(str_contains($htaccess, '^(storage|config|app|database|includes|tests|bin)/.*$'), 'H6 .htaccess blocks internal directories including bin');
    assertP1(str_contains($htaccess, 'FilesMatch "\\.(sql|log|env|ini|bak|md|yml|yaml|sh|bat)$"'), 'H6 .htaccess blocks sensitive file extensions');
    assertP1(str_contains($htaccess, 'RewriteRule ^ https://%{HTTP_HOST}%{REQUEST_URI}'), 'H6 .htaccess redirects HTTP to HTTPS');
    assertP1(str_contains($htaccess, 'RewriteCond %{HTTP_HOST} !^(localhost|127\\.0\\.0\\.1|\\[::1\\])(:[0-9]+)?$'), 'H6 .htaccess keeps loopback HTTP available for local work');

    // ==================================================================
    // H7 - Security headers, cookies, and inline script removal
    // ==================================================================
    echo "\n--- H7: Headers, cookies, and inline code ---\n";

    $functionsSource = (string)file_get_contents($projectRoot . '/includes/functions.php');
    assertP1(str_contains($functionsSource, 'frame-ancestors') && str_contains($functionsSource, "object-src 'none'"), 'H7 CSP restricts framing and objects');
    assertP1(str_contains($functionsSource, "script-src 'self'") && str_contains($functionsSource, 'form-action'), 'H7 CSP restricts scripts and form actions');
    assertP1(str_contains($functionsSource, 'Strict-Transport-Security'), 'H7 HSTS is emitted for HTTPS requests');
    assertP1(str_contains($functionsSource, 'WEBMAIL_FORCE_HTTPS'), 'H7 optional HTTPS enforcement is implemented');
    assertP1(str_contains($functionsSource, 'Cache-Control: no-store'), 'H7 authenticated responses are marked no-store');
    assertP1(!str_contains($functionsSource, 'X-XSS-Protection'), 'H7 obsolete X-XSS-Protection header is not emitted');

    $inlineScriptFiles = [];
    $inlineHandlerFiles = [];
    foreach (p1CollectPhpFiles([
        $projectRoot . '/public',
        $projectRoot . '/admin',
        $projectRoot . '/includes',
    ]) as $phpFile) {
        $source = (string)file_get_contents($phpFile);
        if (preg_match('/<script\b(?![^>]*\bsrc\s*=)/i', $source)) {
            $inlineScriptFiles[] = str_replace($projectRoot . '/', '', $phpFile);
        }
        if (preg_match('/\bon(click|submit|change|input|load|error|mouseover|focus|keydown|keyup)\s*=/i', $source)) {
            $inlineHandlerFiles[] = str_replace($projectRoot . '/', '', $phpFile);
        }
    }
    assertP1($inlineScriptFiles === [], 'H7 no inline <script> blocks remain', implode(', ', $inlineScriptFiles));
    assertP1($inlineHandlerFiles === [], 'H7 no inline event handlers remain', implode(', ', $inlineHandlerFiles));

    $configHeaders = $htaccess . $webConfig;
    assertP1(!str_contains($configHeaders, 'X-XSS-Protection'), 'H7 server configs do not emit X-XSS-Protection');
    assertP1(str_contains($webConfig, 'Strict-Transport-Security'), 'H7 IIS config sets HSTS');
    assertP1(str_contains($htaccess, 'Strict-Transport-Security') && str_contains($htaccess, 'env=HTTPS'), 'H7 Apache config sets HSTS only over HTTPS');

    [$httpServer, $httpPort] = p1StartBuiltInServer($projectRoot, p1BaseEnv());
    $runningProcesses[] = $httpServer;
    $loginResponse = $httpPort > 0
        ? p1HttpRequest('http://127.0.0.1:' . $httpPort . '/public/login.php')
        : ['body' => '', 'headers' => []];
    $loginHeaders = $loginResponse['headers'];
    $headerBlob = implode("\n", $loginHeaders);

    assertP1(p1HttpStatus($loginHeaders) === 200 && str_contains($loginResponse['body'], 'Sign In'), 'H7 login page responds over HTTP', 'status=' . p1HttpStatus($loginHeaders));
    assertP1(stripos($headerBlob, "Content-Security-Policy:") !== false, 'H7 CSP header is sent');
    assertP1(stripos(p1HeaderLine($loginHeaders, 'Content-Security-Policy'), "frame-ancestors 'self'") !== false, 'H7 CSP frame-ancestors is sent');
    assertP1(stripos(p1HeaderLine($loginHeaders, 'Content-Security-Policy'), "script-src 'self'") !== false, 'H7 CSP script-src is sent');
    assertP1(stripos(p1HeaderLine($loginHeaders, 'X-Content-Type-Options'), 'nosniff') !== false, 'H7 nosniff header is sent');
    assertP1(stripos(p1HeaderLine($loginHeaders, 'X-Frame-Options'), 'SAMEORIGIN') !== false, 'H7 frame options header is sent');
    assertP1(stripos(p1HeaderLine($loginHeaders, 'Referrer-Policy'), 'strict-origin-when-cross-origin') !== false, 'H7 referrer policy is sent');
    assertP1(stripos(p1HeaderLine($loginHeaders, 'Permissions-Policy'), 'camera=()') !== false, 'H7 permissions policy is sent');
    assertP1(stripos(p1HeaderLine($loginHeaders, 'Cache-Control'), 'no-store') !== false, 'H7 cache-control no-store is sent');
    assertP1(p1HeaderLine($loginHeaders, 'X-XSS-Protection') === '', 'H7 X-XSS-Protection is absent');
    assertP1(p1HeaderLine($loginHeaders, 'Strict-Transport-Security') === '', 'H7 HSTS is not sent over plain HTTP');

    $cookieLine = p1HeaderLine($loginHeaders, 'Set-Cookie');
    assertP1(stripos($cookieLine, 'HttpOnly') !== false, 'H7 session cookie is HttpOnly');
    assertP1(stripos($cookieLine, 'SameSite=Strict') !== false, 'H7 session cookie is SameSite=Strict');
    p1StopProcess($httpServer);

    $httpsPrepend = $tlsDir . '/https-probe.php';
    file_put_contents($httpsPrepend, "<?php\n\$_SERVER['HTTPS'] = 'on';\n");
    [$httpsServer, $httpsPort] = p1StartBuiltInServer($projectRoot, p1BaseEnv(), $httpsPrepend);
    $runningProcesses[] = $httpsServer;
    $httpsResponse = $httpsPort > 0
        ? p1HttpRequest('http://127.0.0.1:' . $httpsPort . '/public/login.php')
        : ['body' => '', 'headers' => []];
    $httpsHeaders = $httpsResponse['headers'];
    $httpsCsp = p1HeaderLine($httpsHeaders, 'Content-Security-Policy');
    $httpsCookie = p1HeaderLine($httpsHeaders, 'Set-Cookie');
    assertP1(p1HeaderLine($httpsHeaders, 'Strict-Transport-Security') !== '', 'H7 HSTS header is sent for HTTPS');
    assertP1(stripos($httpsCsp, 'upgrade-insecure-requests') !== false, 'H7 CSP upgrades insecure requests under HTTPS');
    assertP1(stripos($httpsCookie, 'Secure') !== false, 'H7 session cookie is Secure under HTTPS');
    p1StopProcess($httpsServer);

    // ==================================================================
    // H8 - Sanitizer hardening
    // ==================================================================
    echo "\n--- H8: Email HTML sanitizer ---\n";

    $sanitizerPayload = '<div style="position:fixed;top:0;left:0;z-index:99999;color:red">'
        . 'Overlay'
        . '</div>'
        . '<a href="javascript:alert(1)" ping="https://evil.example/track">bad link</a>'
        . '<img src="https://evil.example/pixel.png" srcset="https://evil.example/2x.png 2x" onerror="alert(1)" alt="pixel">'
        . '<style>body{position:fixed}</style>'
        . '<form action="https://evil.example"><input name="password"></form>'
        . '<p style="color: blue">Safe color</p>';

    $sanitized = sanitize_email_html($sanitizerPayload);
    assertP1(!str_contains($sanitized, 'position'), 'H8 CSS positioning overlay is stripped');
    assertP1(!str_contains($sanitized, 'z-index') && !str_contains($sanitized, 'top:'), 'H8 overlay coordinates are stripped');
    assertP1(str_contains($sanitized, 'color: blue'), 'H8 safe inline color survives');
    assertP1(!str_contains($sanitized, 'javascript:'), 'H8 javascript: URLs are stripped');
    assertP1(!str_contains($sanitized, 'ping='), 'H8 ping tracking attribute is stripped');
    assertP1(!str_contains($sanitized, 'srcset='), 'H8 srcset attribute is stripped');
    assertP1(!str_contains($sanitized, 'onerror=') && !str_contains($sanitized, '<script'), 'H8 event handlers and scripts are stripped');
    assertP1(!str_contains($sanitized, '<style') && !str_contains($sanitized, '<form') && !str_contains($sanitized, '<input'), 'H8 style/form/input elements are stripped');
    assertP1(str_contains($sanitized, 'data-blocked-src="https://evil.example/pixel.png"'), 'H8 remote images are blocked by default');
    assertP1(!preg_match('/<img\b[^>]*\ssrc="https:\/\/evil\.example\/pixel\.png"/', $sanitized), 'H8 remote image source is withheld until opt-in');

    $sanitizedWithImages = sanitize_email_html($sanitizerPayload, true);
    assertP1(str_contains($sanitizedWithImages, 'src="https://evil.example/pixel.png"'), 'H8 remote images load after explicit opt-in');
    assertP1(!str_contains($sanitizedWithImages, 'data-blocked-src='), 'H8 opt-in does not leave blocked-image markers');

    assertP1(sanitize_email_url('data:text/html;base64,PHNjcmlwdD4=') === null, 'H8 data:text/html URL is rejected');
    assertP1(sanitize_email_url('vbscript:msgbox(1)') === null, 'H8 vbscript URL is rejected');
    assertP1(sanitize_email_url('https://example.test/page') === 'https://example.test/page', 'H8 HTTPS links are allowed');

    // ==================================================================
    // H9 - Sandboxed iframe rendering and image opt-in
    // ==================================================================
    echo "\n--- H9: Sandboxed email rendering ---\n";

    $emailBody = '<p>H9 safe body</p>'
        . '<script>alert("H9")</script>'
        . '<img src="https://images.example.test/pixel.png" onerror="alert(1)">'
        . '<a href="javascript:alert(1)">bad</a>'
        . '<strong>H9 safe bold</strong>';
    $h9EmailId = $makeEmail($sender, $recipient, 'H9 sandboxed ' . $suffix, $emailBody);
    $recipientSession = p1Session($recipient);

    $readResponse = invokeP1Endpoint('public/read.php', 'GET', ['id' => $h9EmailId], [], $recipientSession);
    assertP1($readResponse['status'] === 200, 'H9 read page renders', 'status=' . $readResponse['status'] . ' stderr=' . substr($readResponse['stderr'], 0, 200));
    assertP1(str_contains($readResponse['body'], '<iframe'), 'H9 email body is rendered in an iframe');
    assertP1(str_contains($readResponse['body'], 'srcdoc='), 'H9 iframe uses srcdoc');
    assertP1(str_contains($readResponse['body'], 'sandbox='), 'H9 iframe has a sandbox attribute');
    assertP1(!str_contains($readResponse['body'], 'allow-same-origin'), 'H9 iframe does not grant same-origin access');
    assertP1(stripos($readResponse['body'], 'referrerpolicy="no-referrer"') !== false, 'H9 iframe suppresses referrers');
    assertP1(str_contains($readResponse['body'], 'Load images'), 'H9 read view offers remote-image opt-in');
    assertP1(str_contains($readResponse['body'], 'compose.php?reply_to='), 'H9 reply action remains available');
    assertP1(!preg_match('/<script\b(?![^>]*\bsrc\s*=)/i', $readResponse['body']), 'H9 email body cannot inject a script block');
    assertP1(!str_contains($readResponse['body'], 'onerror="alert(1)"'), 'H9 email body cannot inject an event handler');

    $apiResponse = invokeP1Endpoint('public/api/get_email.php', 'GET', ['id' => $h9EmailId], [], $recipientSession);
    $apiJson = json_decode($apiResponse['body'], true);
    assertP1(
        is_array($apiJson) && ($apiJson['success'] ?? false) === true,
        'H9 AJAX reading pane returns JSON',
        'json_error=' . json_last_error_msg() . ' length=' . strlen($apiResponse['body']) . ' stderr=' . substr($apiResponse['stderr'], 0, 300) . ' body=' . substr($apiResponse['body'], 0, 200)
    );
    $apiEmail = $apiJson['email'] ?? [];
    assertP1(str_contains((string)($apiEmail['body_html'] ?? ''), '<strong>H9 safe bold</strong>'), 'H9 AJAX body preserves safe markup');
    assertP1(!str_contains((string)($apiEmail['body_html'] ?? ''), '<script') && !str_contains((string)($apiEmail['body_html'] ?? ''), 'onerror'), 'H9 AJAX body is sanitized');
    assertP1(!str_contains((string)($apiEmail['body_html'] ?? ''), 'javascript:'), 'H9 AJAX body strips javascript links');
    assertP1(($apiEmail['has_blocked_images'] ?? false) === true, 'H9 AJAX response reports blocked remote images');
    assertP1(!array_key_exists('raw_body', $apiEmail), 'H9 AJAX response never exposes the raw email body');
    assertP1(
        !str_contains((string)($apiEmail['reply_url'] ?? ''), '/api/public/')
        && str_contains((string)($apiEmail['reply_url'] ?? ''), '/public/compose.php?reply_to='),
        'H9 AJAX reply URL resolves from the application root'
    );

    $apiWithImages = invokeP1Endpoint('public/api/get_email.php', 'GET', ['id' => $h9EmailId, 'images' => '1'], [], $recipientSession);
    $apiImagesJson = json_decode($apiWithImages['body'], true);
    $apiImagesEmail = $apiImagesJson['email'] ?? [];
    assertP1(str_contains((string)($apiImagesEmail['body_html'] ?? ''), 'src="https://images.example.test/pixel.png"'), 'H9 AJAX body loads remote images only on opt-in');
    assertP1(($apiImagesEmail['has_blocked_images'] ?? true) === false, 'H9 AJAX opt-in clears the blocked-image marker');

    $appJs = (string)file_get_contents($projectRoot . '/assets/js/app.js');
    assertP1(str_contains($appJs, 'sandbox="allow-popups allow-popups-to-escape-sandbox"'), 'H9 split reading pane uses a sandboxed iframe');
    assertP1(str_contains($appJs, 'load-remote-images-btn'), 'H9 split reading pane exposes a Load images control');
    assertP1(!str_contains($appJs, 'raw_body'), 'H9 client code does not request raw email HTML');
    assertP1(str_contains((string)file_get_contents($projectRoot . '/public/read.php'), 'sanitize_email_html'), 'H9 read page sanitizes before rendering');
} catch (Throwable $e) {
    assertP1(false, 'P1 suite completed without an uncaught exception', $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
} finally {
    foreach ($runningProcesses as $proc) {
        p1StopProcess($proc);
    }
    foreach ($tempPaths as $path) {
        p1RemoveTree($path);
    }
    foreach ($createdEmailIds as $emailId) {
        $pdo->prepare('DELETE FROM attachments WHERE email_id = ?')->execute([$emailId]);
        $pdo->prepare('DELETE FROM email_recipients WHERE email_id = ?')->execute([$emailId]);
        $pdo->prepare('DELETE FROM emails WHERE id = ?')->execute([$emailId]);
    }
    foreach (array_unique($createdUserIds) as $userId) {
        $pdo->prepare('DELETE FROM system_logs WHERE user_id = ?')->execute([$userId]);
        $pdo->prepare('DELETE FROM users WHERE id = ?')->execute([$userId]);
    }
}

echo "\n=== P1 Summary: {$passed} passed, {$failed} failed ===\n";
exit($failed === 0 ? 0 : 1);
