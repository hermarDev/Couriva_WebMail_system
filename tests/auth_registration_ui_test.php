<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$registerPath = $root . '/public/register.php';
$loginPath = $root . '/public/login.php';
$createUserPath = $root . '/admin/create-user.php';
$usersPath = $root . '/admin/users.php';
$registrationsPath = $root . '/admin/registrations.php';

$registerSource = (string)file_get_contents($registerPath);
$loginSource = (string)file_get_contents($loginPath);
$createUserSource = (string)file_get_contents($createUserPath);
$usersSource = (string)file_get_contents($usersPath);
$registrationsSource = (string)file_get_contents($registrationsPath);

$invokeRegister = static function (string $method) use ($root, $registerPath): array {
    $bootstrap = sprintf(
        'register_shutdown_function(function () { echo "\n__STATUS:" . (http_response_code() ?: 200) . ":__"; });'
        . '$_SERVER["REQUEST_METHOD"] = %s;'
        . '$_SERVER["SCRIPT_FILENAME"] = %s;'
        . '$_SERVER["SCRIPT_NAME"] = "/public/register.php";'
        . 'require %s;',
        var_export($method, true),
        var_export($registerPath, true),
        var_export($registerPath, true)
    );

    $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $proc = proc_open(
        escapeshellarg(PHP_BINARY) . ' -d auto_prepend_file= -r ' . escapeshellarg($bootstrap),
        $descriptors,
        $pipes,
        $root
    );
    if (!is_resource($proc)) {
        return ['status' => 0, 'body' => ''];
    }
    fclose($pipes[0]);
    $stdout = (string)stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($proc);

    $status = 200;
    if (preg_match('/__STATUS:(\d+):__/', $stdout, $m)) {
        $status = (int)$m[1];
        $stdout = str_replace($m[0], '', $stdout);
    }

    return ['status' => $status, 'body' => $stdout];
};

$getRes = $invokeRegister('GET');
$postRes = $invokeRegister('POST');

$checks = [
    'public/register.php GET returns HTTP 410' => $getRes['status'] === 410,
    'public/register.php POST returns HTTP 410' => $postRes['status'] === 410,
    'public/register.php renders Couriva auth-card linking back to login' => str_contains($getRes['body'], 'auth-card')
        && str_contains($getRes['body'], 'public/login.php'),
    'public/register.php states Windows Active Directory provisioning and admin contact' => str_contains($getRes['body'], 'Windows Active Directory')
        && stripos($getRes['body'], 'administrator') !== false,
    'public/register.php never references User::create or database/model includes' => !str_contains($registerSource, 'User::create')
        && !str_contains($registerSource, '->create(')
        && !str_contains($registerSource, 'App\\Models\\User')
        && !str_contains($registerSource, 'database.php'),
    'public/login.php removes register.php link and Create account copy' => !str_contains($loginSource, 'register.php')
        && !str_contains($loginSource, 'Create account'),
    'public/login.php shows Windows Active Directory guidance' => str_contains($loginSource, 'Windows Active Directory')
        && stripos($loginSource, 'administrator') !== false,
    'public/login.php keeps heading, CSRF, throttling, and accessible password toggle' => str_contains($loginSource, '<h1>Login to your Couriva workspace</h1>')
        && str_contains($loginSource, 'csrf_field()')
        && str_contains($loginSource, 'verify_csrf_token()')
        && str_contains($loginSource, 'login_is_throttled($pdo)')
        && str_contains($loginSource, '<span class="visually-hidden">Show password</span>'),
    'admin/create-user.php disables web creation with 403 and AD/CLI guidance' => str_contains($createUserSource, 'http_response_code(403)')
        && !str_contains($createUserSource, '->create(')
        && str_contains($createUserSource, 'Active Directory')
        && str_contains($createUserSource, 'bin/create-admin.php'),
    'admin/users.php removes Add New User modal and disables create_user' => !str_contains($usersSource, 'createUserModal')
        && !str_contains($usersSource, 'Add New User')
        && !str_contains($usersSource, '->create(')
        && str_contains($usersSource, 'Active Directory'),
    'admin/registrations.php retires approval queue with 410 and no approve/reject writes' => str_contains($registrationsSource, 'http_response_code(410)')
        && !str_contains($registrationsSource, '->approve(')
        && !str_contains($registrationsSource, '->reject(')
        && str_contains($registrationsSource, 'Active Directory'),
];

$failed = 0;
foreach ($checks as $name => $passed) {
    echo ($passed ? 'PASS ' : 'FAIL ') . $name . PHP_EOL;
    $failed += (int)!$passed;
}

exit($failed ? 1 : 0);
