<?php
/**
 * Couriva - AJAX API: Toggle Starred Status
 */

define('WEBMAIL_INIT', true);

header('Content-Type: application/json');

require_once dirname(dirname(__DIR__)) . '/includes/functions.php';
require_once dirname(dirname(__DIR__)) . '/includes/database.php';
require_once dirname(dirname(__DIR__)) . '/includes/auth.php';

if (!is_authenticated()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

require_api_password_change();

if (!verify_csrf_token()) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Invalid CSRF token']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$emailId = (int)($input['email_id'] ?? 0);
$isStarred = !empty($input['is_starred']) ? 1 : 0;
$userId = (int)$_SESSION['user_id'];

if ($emailId <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid email ID']);
    exit;
}

// User can only star emails they own in their mailbox
$stmt = $pdo->prepare("
    UPDATE emails 
    SET is_starred = ? 
    WHERE id = ? AND (recipient_id = ? OR (sender_id = ? AND recipient_id IS NULL))
");
$updated = $stmt->execute([$isStarred, $emailId, $userId, $userId]);

echo json_encode([
    'success' => $updated,
    'email_id' => $emailId,
    'is_starred' => $isStarred
]);
