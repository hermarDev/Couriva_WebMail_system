<?php
/**
 * Couriva - AJAX API: Auto-Save / Update Draft Email
 * Background asynchronous draft persistence
 */

define('WEBMAIL_INIT', true);

header('Content-Type: application/json; charset=utf-8');

require_once dirname(dirname(__DIR__)) . '/includes/functions.php';
require_once dirname(dirname(__DIR__)) . '/includes/database.php';
require_once dirname(dirname(__DIR__)) . '/includes/auth.php';
require_once dirname(dirname(__DIR__)) . '/app/Models/User.php';
require_once dirname(dirname(__DIR__)) . '/app/Models/Email.php';
require_once dirname(dirname(__DIR__)) . '/app/Models/Attachment.php';
require_once dirname(dirname(__DIR__)) . '/app/Services/AttachmentService.php';
require_once dirname(dirname(__DIR__)) . '/app/Services/SmtpTransport.php';
require_once dirname(dirname(__DIR__)) . '/app/Services/MailService.php';

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

// Parse request payload
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

$userId = (int)$_SESSION['user_id'];
$rawDraftId = $inputData['draft_id'] ?? null;
$draftId = (!empty($rawDraftId) && (int)$rawDraftId > 0) ? (int)$rawDraftId : null;

$data = [
    'subject'         => trim($inputData['subject'] ?? ''),
    'body'            => $inputData['body'] ?? '',
    'to'              => $inputData['to'] ?? '',
    'cc'              => $inputData['cc'] ?? '',
    'bcc'             => $inputData['bcc'] ?? '',
    'parent_email_id' => !empty($inputData['parent_email_id']) ? (int)$inputData['parent_email_id'] : null,
];

try {
    $mailService = new \App\Services\MailService($pdo);
    $savedDraftId = $mailService->saveOrUpdateDraft($userId, $draftId, $data, $_FILES['attachments'] ?? []);

    echo json_encode([
        'success'    => true,
        'draft_id'   => $savedDraftId,
        'updated_at' => date('Y-m-d H:i:s'),
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error'   => 'Failed to save draft: ' . $e->getMessage(),
    ]);
}
