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

// User can only star emails they own in their mailbox.
// Ownership is verified with a SELECT first so the response does not depend on
// UPDATE rowCount(), which is 0 on MySQL when the stored value does not change.
$check = $pdo->prepare("
    SELECT id FROM emails 
    WHERE id = ? AND (recipient_id = ? OR (sender_id = ? AND recipient_id IS NULL))
");
$check->execute([$emailId, $userId, $userId]);

if ($check->fetchColumn() === false) {
    http_response_code(404);
    echo json_encode(['success' => false, 'error' => 'Email not found or access denied']);
    exit;
}

$stmt = $pdo->prepare("UPDATE emails SET is_starred = ? WHERE id = ?");
$stmt->execute([$isStarred, $emailId]);

echo json_encode([
    'success' => true,
    'email_id' => $emailId,
    'is_starred' => $isStarred
]);
