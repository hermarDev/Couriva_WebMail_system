<?php
declare(strict_types=1);

// Verify that admin accounts cannot be modified in admin/users.php or admin/edit-user.php
$root = dirname(__DIR__);
$tmp = sys_get_temp_dir() . '/couriva-admin-protect-' . bin2hex(random_bytes(8));
mkdir($tmp . '/includes', 0700, true);
mkdir($tmp . '/admin', 0700, true);
symlink($root . '/app', $tmp . '/app');
symlink($root . '/config', $tmp . '/config');

foreach (['functions.php', 'auth.php', 'header.php', 'footer.php'] as $file) {
    copy($root . '/includes/' . $file, $tmp . '/includes/' . $file);
}
copy($root . '/admin/users.php', $tmp . '/admin/users.php');
copy($root . '/admin/edit-user.php', $tmp . '/admin/edit-user.php');

$dbStub = <<<'PHP'
<?php
$pdo = new PDO('sqlite:' . getenv('COURIVA_TEST_DB'));
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$pdo->sqliteCreateFunction('NOW', static fn(): string => date('Y-m-d H:i:s'), 0);
function getDbConnection(): PDO {
    global $pdo;
    return $pdo;
}
PHP;
file_put_contents($tmp . '/includes/database.php', $dbStub);

$dbPath = $tmp . '/test.sqlite';
$db = new PDO('sqlite:' . $dbPath);
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->exec("
    CREATE TABLE users (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        email TEXT UNIQUE,
        password TEXT,
        first_name TEXT,
        last_name TEXT,
        role TEXT,
        status TEXT,
        auth_source TEXT DEFAULT 'local',
        ad_object_guid TEXT,
        signature TEXT,
        timezone TEXT DEFAULT 'UTC',
        theme TEXT DEFAULT 'light',
        avatar TEXT,
        must_change_password INTEGER DEFAULT 0,
        session_epoch INTEGER DEFAULT 1,
        created_at TEXT,
        updated_at TEXT
    );
    CREATE TABLE system_logs (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER,
        action TEXT,
        status TEXT,
        details TEXT,
        ip_address TEXT,
        user_agent TEXT,
        created_at TEXT DEFAULT CURRENT_TIMESTAMP
    );
    CREATE TABLE emails (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        sender_id INTEGER,
        recipient_id INTEGER,
        folder TEXT,
        is_read INTEGER DEFAULT 0,
        is_archived INTEGER DEFAULT 0,
        custom_folder_id INTEGER,
        snoozed_until TEXT,
        deleted_at TEXT
    );
    CREATE TABLE scheduled_emails (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER, status TEXT);
    CREATE TABLE labels (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER, name TEXT, color TEXT);
    CREATE TABLE folders (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER, name TEXT, color TEXT, sort_order INTEGER);
    CREATE TABLE attachments (id INTEGER PRIMARY KEY AUTOINCREMENT, email_id INTEGER, filesize INTEGER);
");

$adminHash = password_hash('AdminPass123!', PASSWORD_BCRYPT);
$peerAdminHash = password_hash('PeerAdmin123!', PASSWORD_BCRYPT);
$userHash = password_hash('UserPass123!', PASSWORD_BCRYPT);

$stmt = $db->prepare("INSERT INTO users (id, email, password, first_name, last_name, role, status, auth_source, timezone, must_change_password, session_epoch, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'UTC', 0, 1, '2026-11-19 00:00:00', '2026-11-19 00:00:00')");
$stmt->execute([1, 'admin@couriva.com', $adminHash, 'System', 'Administrator', 'admin', 'active', 'local']);
$stmt->execute([2, 'peer.admin@couriva.local', $peerAdminHash, 'Peer', 'Admin', 'admin', 'active', 'ldap']);
$stmt->execute([3, 'jdoe@couriva.com', $userHash, 'John', 'Doe', 'user', 'active', 'ldap']);

function runEndpoint(string $tmp, string $dbPath, string $scriptPath, string $method, array $get = [], array $post = []): array {
    $session = [
        'user_id' => 1,
        'user_email' => 'admin@couriva.com',
        'user_role' => 'admin',
        'user_name' => 'System Administrator',
        'session_epoch' => 1,
        'must_change_password' => 0,
        'auth_time' => time(),
        'last_activity' => time(),
        'csrf_token' => 'test-csrf-token',
    ];
    $runner = 'session_start(); $_SESSION = ' . var_export($session, true) . '; '
        . '$_SERVER["REQUEST_METHOD"] = ' . var_export($method, true) . '; '
        . '$_SERVER["SCRIPT_FILENAME"] = ' . var_export($tmp . '/' . $scriptPath, true) . '; '
        . '$_GET = ' . var_export($get, true) . '; '
        . '$_POST = ' . var_export($post, true) . '; '
        . 'ob_start(); '
        . 'register_shutdown_function(function () { $b = ob_get_clean(); echo "\n__STATUS__:" . http_response_code() . "\n__BODY__:\n" . $b; }); '
        . 'require ' . var_export($tmp . '/' . $scriptPath, true) . ';';

    $proc = proc_open(
        [PHP_BINARY, '-r', $runner],
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        $tmp,
        ['COURIVA_TEST_DB' => $dbPath]
    );
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[2]);
    $exit = proc_close($proc);

    $status = 200;
    $body = $stdout;
    if (preg_match('/__STATUS__:(\d+)\n__BODY__:\n(.*)/s', $stdout, $m)) {
        $status = (int)$m[1];
        $body = $m[2];
    }
    return ['status' => $status, 'body' => $body, 'stderr' => $stderr, 'exit' => $exit];
}

$passed = 0;
$failed = 0;
function check(bool $cond, string $label, string $detail = ''): void {
    global $passed, $failed;
    if ($cond) {
        $passed++;
        echo "PASS {$label}\n";
    } else {
        $failed++;
        echo "FAIL {$label}" . ($detail !== '' ? " ({$detail})" : '') . "\n";
    }
}

try {
    // 1. admin/users.php GET must not expose Edit/Disable/Delete UI controls for admin accounts
    $usersGet = runEndpoint($tmp, $dbPath, 'admin/users.php', 'GET');
    check($usersGet['status'] === 200, 'admin/users.php GET returns 200', 'status=' . $usersGet['status']);
    check(!str_contains($usersGet['body'], 'data-email="admin@couriva.com"'), 'admin/users.php hides Edit modal trigger for admin@couriva.com');
    check(!str_contains($usersGet['body'], 'admin/edit-user.php?id=1'), 'admin/users.php hides Dedicated Edit Page link for admin@couriva.com');
    check(!str_contains($usersGet['body'], 'data-email="peer.admin@couriva.local"'), 'admin/users.php hides Edit modal trigger for peer admin');
    check(str_contains($usersGet['body'], 'Protected'), 'admin/users.php displays Protected indicator for admin accounts');
    check(str_contains($usersGet['body'], 'data-email="jdoe@couriva.com"'), 'admin/users.php still allows editing standard user jdoe@couriva.com');

    // 2. admin/users.php POST update_user targeting an admin must return 403 and leave DB untouched
    $usersUpdateAdmin = runEndpoint($tmp, $dbPath, 'admin/users.php', 'POST', [], [
        'csrf_token' => 'test-csrf-token',
        'action'     => 'update_user',
        'user_id'    => '1',
        'first_name' => 'Hacked',
        'last_name'  => 'User',
        'role'       => 'user',
        'status'     => 'disabled',
        'timezone'   => 'UTC',
    ]);
    $adminRow = $db->query("SELECT first_name, role, status FROM users WHERE id = 1")->fetch();
    check($usersUpdateAdmin['status'] === 403, 'admin/users.php POST update_user on admin rejects with HTTP 403', 'status=' . $usersUpdateAdmin['status']);
    check($adminRow['role'] === 'admin' && $adminRow['status'] === 'active' && $adminRow['first_name'] === 'System', 'admin/users.php preserves admin account fields on rejected update_user');

    // 3. admin/users.php POST toggle_status / delete on peer admin must return 403 and leave DB untouched
    $usersTogglePeer = runEndpoint($tmp, $dbPath, 'admin/users.php', 'POST', [], [
        'csrf_token' => 'test-csrf-token',
        'action'     => 'toggle_status',
        'user_id'    => '2',
    ]);
    $peerRow = $db->query("SELECT status FROM users WHERE id = 2")->fetch();
    check($usersTogglePeer['status'] === 403 && $peerRow && $peerRow['status'] === 'active', 'admin/users.php POST toggle_status on peer admin rejects with HTTP 403', 'status=' . $usersTogglePeer['status']);

    $usersDeletePeer = runEndpoint($tmp, $dbPath, 'admin/users.php', 'POST', [], [
        'csrf_token' => 'test-csrf-token',
        'action'     => 'delete',
        'user_id'    => '2',
    ]);
    $peerStillExists = $db->query("SELECT id FROM users WHERE id = 2")->fetch();
    check($usersDeletePeer['status'] === 403 && (bool)$peerStillExists, 'admin/users.php POST delete on peer admin rejects with HTTP 403', 'status=' . $usersDeletePeer['status']);

    // 4. admin/edit-user.php GET on admin account must display protected notice and no active Update/Reset submit actions
    $editAdminGet = runEndpoint($tmp, $dbPath, 'admin/edit-user.php', 'GET', ['id' => '1']);
    check($editAdminGet['status'] === 200, 'admin/edit-user.php GET on admin renders page', 'status=' . $editAdminGet['status']);
    check(str_contains($editAdminGet['body'], 'Administrator accounts cannot be modified'), 'admin/edit-user.php GET shows admin protection notice');
    check(!str_contains($editAdminGet['body'], 'Update User Details'), 'admin/edit-user.php GET omits Update User Details submit button for admin');
    check(!str_contains($editAdminGet['body'], 'id="adminResetPassword"'), 'admin/edit-user.php GET omits Reset Password form for admin');

    // 5. admin/edit-user.php POST update_profile & reset_password on admin must return 403 and preserve DB
    $editAdminPost = runEndpoint($tmp, $dbPath, 'admin/edit-user.php', 'POST', ['id' => '1'], [
        'csrf_token' => 'test-csrf-token',
        'action'     => 'update_profile',
        'first_name' => 'Demoted',
        'last_name'  => 'User',
        'role'       => 'user',
        'status'     => 'disabled',
        'timezone'   => 'UTC',
    ]);
    $adminRowAfterEdit = $db->query("SELECT first_name, role, status FROM users WHERE id = 1")->fetch();
    check($editAdminPost['status'] === 403, 'admin/edit-user.php POST update_profile on admin rejects with HTTP 403', 'status=' . $editAdminPost['status']);
    check($adminRowAfterEdit['role'] === 'admin' && $adminRowAfterEdit['status'] === 'active' && $adminRowAfterEdit['first_name'] === 'System', 'admin/edit-user.php preserves admin details on rejected POST');

    $resetAdminPost = runEndpoint($tmp, $dbPath, 'admin/edit-user.php', 'POST', ['id' => '1'], [
        'csrf_token'   => 'test-csrf-token',
        'action'       => 'reset_password',
        'new_password' => 'NewPassword123!',
    ]);
    $adminHashAfter = (string)$db->query("SELECT password FROM users WHERE id = 1")->fetchColumn();
    check($resetAdminPost['status'] === 403 && $adminHashAfter === $adminHash, 'admin/edit-user.php POST reset_password on admin rejects with HTTP 403 and preserves password', 'status=' . $resetAdminPost['status']);
} finally {
    // Cleanup temp directory
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($tmp, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($it as $item) {
        if ($item->isLink() || $item->isFile()) {
            @unlink($item->getPathname());
        } else {
            @rmdir($item->getPathname());
        }
    }
    @rmdir($tmp);
}

echo "\nResults: {$passed} passed, {$failed} failed\n";
exit($failed > 0 ? 1 : 0);
