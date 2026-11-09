<?php
declare(strict_types=1);

// Run real API scripts against a disposable SQLite database and isolated sessions.
$root = dirname(__DIR__);
$tmp = sys_get_temp_dir() . '/couriva-api-guard-' . bin2hex(random_bytes(8));
mkdir($tmp . '/includes', 0700, true);
mkdir($tmp . '/public/api', 0700, true);
symlink($root . '/app', $tmp . '/app');
foreach (['functions.php', 'auth.php'] as $file) {
    copy($root . '/includes/' . $file, $tmp . '/includes/' . $file);
}
file_put_contents($tmp . '/includes/database.php', '<?php $pdo = new PDO("sqlite:" . getenv("COURIVA_TEST_DB")); $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);');
$database = $tmp . '/test.sqlite';
$db = new PDO('sqlite:' . $database);
$db->exec("CREATE TABLE users (id INTEGER, email TEXT, first_name TEXT, last_name TEXT, role TEXT, status TEXT, session_epoch INTEGER, must_change_password INTEGER, timezone TEXT, theme TEXT, avatar TEXT)");
$db->exec("INSERT INTO users VALUES (1, 'test@example.invalid', 'Test', 'User', 'user', 'active', 1, 1, 'UTC', 'light', NULL)");
$db->exec("CREATE TABLE contacts (user_id INTEGER, contact_name TEXT, contact_email TEXT, company TEXT)");
$endpoints = ['theme.php', 'toggle_star.php', 'contacts.php', 'batch_actions.php', 'get_email.php', 'autosave_draft.php'];
$failed = 0;
try {
    foreach ($endpoints as $endpoint) {
        copy($root . '/public/api/' . $endpoint, $tmp . '/public/api/' . $endpoint);
        $script = '$user = ' . var_export(['user_id' => 1, 'user_email' => 'test@example.invalid', 'session_epoch' => 1, 'auth_time' => time(), 'last_activity' => time(), 'csrf_token' => 'test-token'], true) . ';'
            . 'session_start(); $_SESSION = $user; $_SERVER["REQUEST_METHOD"] = "POST"; $_SERVER["HTTP_X_CSRF_TOKEN"] = "test-token"; '
            . 'register_shutdown_function(function () { echo "\nSTATUS:" . http_response_code(); }); require ' . var_export($tmp . '/public/api/' . $endpoint, true) . ';';
        $proc = proc_open([PHP_BINARY, '-r', $script], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $tmp, ['COURIVA_TEST_DB' => $database]);
        fclose($pipes[0]);
        $output = stream_get_contents($pipes[1]); fclose($pipes[1]);
        $error = stream_get_contents($pipes[2]); fclose($pipes[2]);
        $code = proc_close($proc);
        $ok = $code === 0 && str_contains($output, 'STATUS:403') && str_contains($output, 'password');
        echo ($ok ? 'PASS ' : 'FAIL ') . $endpoint . ': ' . trim($output) . ($error ? ' stderr=' . trim($error) : '') . "\n";
        if (!$ok) $failed++;
    }

    // The same session must regain access when the database flag is cleared.
    $db->exec('UPDATE users SET must_change_password = 0 WHERE id = 1');
    $script = 'session_start(); $_SESSION = ' . var_export(['user_id' => 1, 'user_email' => 'test@example.invalid', 'session_epoch' => 1, 'auth_time' => time(), 'last_activity' => time(), 'must_change_password' => 1], true) . ';'
        . 'require ' . var_export($tmp . '/public/api/contacts.php', true) . ';';
    $proc = proc_open([PHP_BINARY, '-r', $script], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $tmp, ['COURIVA_TEST_DB' => $database]);
    fclose($pipes[0]);
    $output = stream_get_contents($pipes[1]); fclose($pipes[1]);
    $error = stream_get_contents($pipes[2]); fclose($pipes[2]);
    $code = proc_close($proc);
    $ok = $code === 0 && json_decode($output, true) === [] && $error === '';
    echo ($ok ? 'PASS' : 'FAIL') . ' access restored after flag cleared: ' . trim($output) . ($error ? ' stderr=' . trim($error) : '') . "\n";
    if (!$ok) $failed++;
} finally {
    foreach (glob($tmp . '/public/api/*') ?: [] as $file) unlink($file);
    foreach (glob($tmp . '/includes/*') ?: [] as $file) unlink($file);
    unlink($tmp . '/app'); unlink($database);
    rmdir($tmp . '/public/api'); rmdir($tmp . '/public'); rmdir($tmp . '/includes'); rmdir($tmp);
}
exit($failed ? 1 : 0);
