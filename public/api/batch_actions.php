<?php
/**
 * Couriva - AJAX API: Batch Email Actions
 * Handles read state, trash, spam, archive, snooze, labels, custom folders,
 * permanent deletion, and star state.
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
require_once dirname(dirname(__DIR__)) . '/app/Services/OrganizationService.php';

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

// Parse request payload (supports JSON payload and form post)
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

$action = trim($inputData['action'] ?? '');
$rawIds = $inputData['email_ids'] ?? [];

if (is_string($rawIds)) {
    $rawIds = explode(',', $rawIds);
}

$allowedActions = [
    'mark_read',
    'mark_unread',
    'trash',
    'spam',
    'restore',
    'not_spam',
    'permanent_delete',
    'star',
    'unstar',
    'archive',
    'unarchive',
    'snooze',
    'unsnooze',
    'label',
    'unlabel',
    'move_folder',
];

if (!in_array($action, $allowedActions, true)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Unsupported batch action.']);
    exit;
}

if (!is_array($rawIds) || empty($rawIds)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'No emails selected for batch operation']);
    exit;
}

$userId = (int)$_SESSION['user_id'];
$mailService = new \App\Services\MailService($pdo);
$organizationService = new \App\Services\OrganizationService($pdo);

try {
    $count = 0;
    $message = '';

    switch ($action) {
        case 'mark_read':
            $count = $mailService->batchMarkRead($userId, $rawIds, true);
            $message = "{$count} email(s) marked as read.";
            break;

        case 'mark_unread':
            $count = $mailService->batchMarkRead($userId, $rawIds, false);
            $message = "{$count} email(s) marked as unread.";
            break;

        case 'trash':
            $count = $mailService->batchMoveFolder($userId, $rawIds, 'trash');
            $message = "{$count} email(s) moved to trash.";
            break;

        case 'spam':
            $count = $mailService->batchMoveFolder($userId, $rawIds, 'spam');
            $message = "{$count} email(s) marked as spam.";
            break;

        case 'restore':
        case 'not_spam':
            $count = $mailService->batchMoveFolder($userId, $rawIds, 'inbox');
            $message = "{$count} email(s) restored to Inbox.";
            break;

        case 'permanent_delete':
            $count = $mailService->batchDelete($userId, $rawIds, true);
            $message = "{$count} email(s) permanently deleted.";
            break;

        case 'star':
            $count = $mailService->batchToggleStar($userId, $rawIds, true);
            $message = "{$count} email(s) starred.";
            break;

        case 'unstar':
            $count = $mailService->batchToggleStar($userId, $rawIds, false);
            $message = "{$count} email(s) unstarred.";
            break;

        case 'archive':
            $count = $mailService->batchArchive($userId, $rawIds, true);
            $message = "{$count} email(s) archived.";
            break;

        case 'unarchive':
            $count = $mailService->batchArchive($userId, $rawIds, false);
            $message = "{$count} email(s) moved back to Inbox.";
            break;

        case 'snooze':
            $until = trim((string)($inputData['snooze_until'] ?? ''));
            if ($until === '') {
                throw new InvalidArgumentException('Choose a snooze time.');
            }
            $count = $mailService->batchSnooze($userId, $rawIds, $until);
            $message = "{$count} email(s) snoozed.";
            break;

        case 'unsnooze':
            $count = $mailService->batchUnsnooze($userId, $rawIds);
            $message = "{$count} email(s) returned to Inbox.";
            break;

        case 'label':
            $labelId = (int)($inputData['label_id'] ?? 0);
            if ($labelId <= 0) {
                throw new InvalidArgumentException('Choose a label.');
            }
            $count = $organizationService->assignLabel($userId, $rawIds, $labelId);
            $message = "{$count} email(s) labeled.";
            break;

        case 'unlabel':
            $labelId = (int)($inputData['label_id'] ?? 0);
            if ($labelId <= 0) {
                throw new InvalidArgumentException('Choose a label.');
            }
            $count = $organizationService->removeLabel($userId, $rawIds, $labelId);
            $message = "{$count} email(s) updated.";
            break;

        case 'move_folder':
            $folderId = (int)($inputData['folder_id'] ?? 0);
            $count = $organizationService->moveToFolder($userId, $rawIds, $folderId > 0 ? $folderId : null);
            $message = "{$count} email(s) moved.";
            break;
    }

    log_activity($userId, 'BATCH_ACTION', 'SUCCESS', "Action: {$action}, Count: {$count}");

    echo json_encode([
        'success' => true,
        'action'  => $action,
        'count'   => $count,
        'message' => $message,
    ]);
} catch (InvalidArgumentException $e) {
    http_response_code(422);
    echo json_encode([
        'success' => false,
        'error'   => $e->getMessage(),
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    error_log('Batch action failed: ' . $e->getMessage());
    log_activity($userId, 'BATCH_ACTION_ERROR', 'FAILED', 'Batch action failed.');
    echo json_encode([
        'success' => false,
        'error'   => 'The action could not be completed. Please try again.',
    ]);
}
