<?php
/**
 * Couriva - AJAX API: Update User Theme Preference
 */

define('WEBMAIL_INIT', true);

header('Content-Type: application/json; charset=utf-8');

require_once dirname(dirname(__DIR__)) . '/includes/functions.php';
require_once dirname(dirname(__DIR__)) . '/includes/database.php';
require_once dirname(dirname(__DIR__)) . '/includes/auth.php';
require_once dirname(dirname(__DIR__)) . '/app/Models/User.php';

// Authentication Check
if (!is_authenticated()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Authentication required']);
    exit;
}

require_api_password_change();

// CSRF Check
if (!verify_csrf_token()) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Invalid or expired CSRF token']);
    exit;
}

// Only POST allowed
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

$contentType = $_SERVER['CONTENT_TYPE'] ?? '';
$inputData = [];

if (str_contains($contentType, 'application/json')) {
    $raw = file_get_contents('php://input');
    if (($raw === '' || $raw === false) && php_sapi_name() === 'cli') {
        $raw = @file_get_contents('php://stdin') ?: '';
    }
    $inputData = json_decode($raw, true) ?: [];
} else {
    $inputData = $_POST;
}

$theme = trim($inputData['theme'] ?? '');
if (!in_array($theme, ['light', 'dark', 'system'], true)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid theme value']);
    exit;
}

$userId = (int)$_SESSION['user_id'];
$userModel = new \App\Models\User($pdo);
$updated = $userModel->update($userId, ['theme' => $theme]);

if ($updated) {
    $_SESSION['user_theme'] = $theme;
    log_activity($userId, 'THEME_UPDATED', 'SUCCESS', "Theme switched to {$theme}");
    echo json_encode(['success' => true, 'theme' => $theme]);
} else {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Failed to persist theme preference']);
}
