<?php
/**
 * Couriva - Secure Attachment Downloader
 */

define('WEBMAIL_INIT', true);

require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/database.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/app/Models/Attachment.php';
require_once dirname(__DIR__) . '/app/Services/AttachmentService.php';

require_auth();

$attachmentId = (int)($_GET['id'] ?? 0);
$userId = (int)$_SESSION['user_id'];

if ($attachmentId <= 0) {
    http_response_code(400);
    exit('Invalid attachment identifier.');
}

// Verify that the user has permission to view this attachment (must be owner of the mailbox containing the email)
$stmt = $pdo->prepare("
    SELECT a.* 
    FROM attachments a
    JOIN emails e ON a.email_id = e.id
    WHERE a.id = ? AND (e.recipient_id = ? OR (e.sender_id = ? AND e.recipient_id IS NULL))
    LIMIT 1
");
$stmt->execute([$attachmentId, $userId, $userId]);
$att = $stmt->fetch();

if (!$att) {
    http_response_code(403);
    exit('Access denied to attachment.');
}

$attachmentService = new \App\Services\AttachmentService($pdo);
$attachmentService->downloadAttachment($attachmentId);
