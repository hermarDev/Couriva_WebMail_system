<?php
/**
 * Couriva - Authentication Middleware & Functions
 */

if (!defined('WEBMAIL_INIT')) {
    define('WEBMAIL_INIT', true);
}

require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/database.php';

/**
 * Enforce that the user is authenticated; redirects to login if not.
 *
 * @param bool $allowPendingPasswordChange Set by change-password.php itself.
 */
function require_auth(bool $allowPendingPasswordChange = false): void
{
    if (!is_authenticated()) {
        set_flash('warning', 'Please sign in to access your mailbox.');
        header('Location: ' . app_base_url('public/login.php'));
        exit;
    }

    if (!$allowPendingPasswordChange && !empty($_SESSION['must_change_password'])) {
        set_flash('warning', 'You must change your password before continuing.');
        header('Location: ' . app_base_url('public/change-password.php'));
        exit;
    }
}

function require_api_password_change(): void
{
    if (!empty($_SESSION['must_change_password'])) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'You must change your password before continuing.']);
        exit;
    }
}

/**
 * Enforce that the user has admin privileges
 */
function require_admin(): void
{
    require_auth();
    if (!is_admin()) {
        http_response_code(403);
        set_flash('danger', 'Access denied. Administrator privileges required.');
        header('Location: ' . app_base_url('public/inbox.php'), true, 403);
        exit;
    }
}

/**
 * Authenticate credentials against database
 */
function attempt_login(string $email, string $password): array
{
    global $pdo;

    $email = trim(strtolower($email));
    if (empty($email) || empty($password)) {
        return ['success' => false, 'message' => 'Please enter both email and password.'];
    }

    require_once dirname(__DIR__) . '/app/Models/User.php';
    require_once dirname(__DIR__) . '/app/Services/AuthService.php';

    $authService = new \App\Services\AuthService($pdo);
    $result = $authService->authenticate($email, $password);

    if (!$result['success']) {
        log_activity(null, 'FAILED_LOGIN', 'FAILED', ($result['message'] ?? 'Login failed') . ": {$email}");
        return $result;
    }

    $user = $result['user'];

    // Authentication successful: Regenerate session ID to prevent fixation
    session_regenerate_id(true);

    $_SESSION['user_id']         = (int)$user['id'];
    $_SESSION['user_email']      = $user['email'];
    $_SESSION['user_first_name'] = $user['first_name'];
    $_SESSION['user_last_name']  = $user['last_name'];
    $_SESSION['user_full_name']  = trim($user['first_name'] . ' ' . $user['last_name']);
    $_SESSION['user_role']       = $user['role'];
    $_SESSION['user_timezone']   = $user['timezone'] ?: 'UTC';
    $_SESSION['user_theme']      = $user['theme'] ?? 'light';
    $_SESSION['user_avatar']     = $user['avatar'] ?? null;
    $_SESSION['auth_source']     = $result['auth_source'] ?? 'local';
    $_SESSION['session_epoch']   = (int)($user['session_epoch'] ?? 1);
    $_SESSION['must_change_password'] = (int)($user['must_change_password'] ?? 0);
    $_SESSION['auth_time']       = time();
    $_SESSION['last_activity']   = time();

    log_activity($user['id'], 'USER_LOGIN', 'SUCCESS', "User successfully authenticated ({$_SESSION['auth_source']}): {$email}");

    return ['success' => true, 'user' => $user];
}

/**
 * Log out user safely and terminate session.
 *
 * The session epoch is incremented so any other stolen or stale session for
 * this account is invalidated at the same time.
 */
function perform_logout(): void
{
    $userId = (int)($_SESSION['user_id'] ?? 0);

    if ($userId > 0) {
        try {
            global $pdo;
            if ($pdo instanceof PDO) {
                $stmt = $pdo->prepare("UPDATE users SET session_epoch = session_epoch + 1 WHERE id = ?");
                $stmt->execute([$userId]);
            }
        } catch (Throwable $e) {
            error_log('Logout session revocation failed: ' . $e->getMessage());
        }

        log_activity($userId, 'USER_LOGOUT', 'SUCCESS', 'User logged out: ' . ($_SESSION['user_email'] ?? 'unknown'));
    }

    $_SESSION = [];

    if (ini_get("session.use_cookies")) {
        $params = session_get_cookie_params();
        setcookie(
            session_name(),
            '',
            time() - 42000,
            $params["path"],
            $params["domain"],
            $params["secure"],
            $params["httponly"]
        );
    }

    if (session_status() === PHP_SESSION_ACTIVE) {
        session_destroy();
    }
}
