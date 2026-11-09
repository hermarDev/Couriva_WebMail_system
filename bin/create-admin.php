<?php
/**
 * Couriva - Administrator provisioning CLI
 *
 * Usage:
 *   ADMIN_EMAIL=admin@example.com \
 *   ADMIN_PASSWORD='a-long-random-secret' \
 *   php bin/create-admin.php
 *
 * Provide names with ADMIN_FIRST_NAME and ADMIN_LAST_NAME if desired.
 * The account is always flagged for a password change on first login.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only.\n");
}

define('WEBMAIL_INIT', true);

require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/database.php';
require_once dirname(__DIR__) . '/app/Models/User.php';

$email = trim((string)(getenv('ADMIN_EMAIL') ?: ''));
$password = (string)(getenv('ADMIN_PASSWORD') ?: '');
$firstName = trim((string)(getenv('ADMIN_FIRST_NAME') ?: 'System'));
$lastName = trim((string)(getenv('ADMIN_LAST_NAME') ?: 'Administrator'));

if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    fwrite(STDERR, "Set ADMIN_EMAIL to a valid email address.\n");
    exit(1);
}
if (strlen($password) < 12) {
    fwrite(STDERR, "Set ADMIN_PASSWORD to at least 12 characters.\n");
    exit(1);
}

try {
    $pdo = getDbConnection();
    $userModel = new \App\Models\User($pdo);
    $existing = $userModel->findByEmail($email);

    if ($existing) {
        $userModel->updatePassword((int)$existing['id'], $password, true);
        $userModel->update((int)$existing['id'], [
            'role'   => 'admin',
            'status' => 'active',
        ]);
        fwrite(STDOUT, "Updated administrator {$email}. A password change is required at first login.\n");
        exit(0);
    }

    $id = $userModel->create([
        'email'                 => $email,
        'password'              => $password,
        'first_name'            => $firstName !== '' ? $firstName : 'System',
        'last_name'             => $lastName !== '' ? $lastName : 'Administrator',
        'role'                  => 'admin',
        'status'                => 'active',
        'timezone'              => 'UTC',
        'must_change_password'  => 1,
    ]);

    fwrite(STDOUT, "Created administrator {$email} (ID {$id}). A password change is required at first login.\n");
} catch (Throwable $e) {
    error_log('create-admin failed: ' . $e->getMessage());
    fwrite(STDERR, "Could not provision the administrator. Check the database connection and logs.\n");
    exit(1);
}
