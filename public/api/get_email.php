<?php
/**
 * Couriva - AJAX API: Fetch Single Email Detail for Split Reading Pane
 */

define('WEBMAIL_INIT', true);

header('Content-Type: application/json; charset=utf-8');

require_once dirname(dirname(__DIR__)) . '/includes/functions.php';
require_once dirname(dirname(__DIR__)) . '/includes/database.php';
require_once dirname(dirname(__DIR__)) . '/includes/auth.php';

// Authentication Check
if (!is_authenticated()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Authentication required']);
    exit;
}

require_api_password_change();

$emailId = (int)($_GET['id'] ?? 0);
$userId = (int)$_SESSION['user_id'];

if ($emailId <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid email ID requested']);
    exit;
}

// Fetch email and verify ownership (user must be recipient or sender of sent/draft email)
$stmt = $pdo->prepare("
    SELECT e.*, 
           COALESCE(s.first_name, SUBSTRING_INDEX(e.sender_name_snapshot, ' ', 1)) AS sender_first_name,
           COALESCE(s.last_name, SUBSTRING_INDEX(e.sender_name_snapshot, ' ', -1)) AS sender_last_name,
           COALESCE(s.email, e.sender_email_snapshot) AS sender_email,
           r.first_name AS recipient_first_name, r.last_name AS recipient_last_name, r.email AS recipient_email
    FROM emails e
    LEFT JOIN users s ON e.sender_id = s.id
    LEFT JOIN users r ON e.recipient_id = r.id
    WHERE e.id = ? AND (e.recipient_id = ? OR (e.sender_id = ? AND e.recipient_id IS NULL))
    LIMIT 1
");
$stmt->execute([$emailId, $userId, $userId]);
$email = $stmt->fetch();

if (!$email) {
    http_response_code(404);
    echo json_encode(['success' => false, 'error' => 'Email message not found or access denied']);
    exit;
}

// Mark as read if user is the recipient
$wasUnread = false;
if ((int)$email['recipient_id'] === $userId && !$email['is_read']) {
    $updateStmt = $pdo->prepare("UPDATE emails SET is_read = 1 WHERE id = ?");
    $updateStmt->execute([$emailId]);
    $email['is_read'] = 1;
    $wasUnread = true;
}

// Fetch recipients
$recipientsStmt = $pdo->prepare("SELECT * FROM email_recipients WHERE email_id = ? ORDER BY recipient_type ASC, id ASC");
$recipientsStmt->execute([$emailId]);
$rawRecipients = $recipientsStmt->fetchAll();

$recipients = [
    'to'  => [],
    'cc'  => [],
    'bcc' => [],
];
foreach ($rawRecipients as $r) {
    $type = $r['recipient_type'] ?? 'to';
    if (isset($recipients[$type])) {
        $recipients[$type][] = [
            'email' => $r['email_address'],
            'type'  => $type,
        ];
    }
}

// Fetch attachments
$attStmt = $pdo->prepare("SELECT * FROM attachments WHERE email_id = ? ORDER BY id ASC");
$attStmt->execute([$emailId]);
$rawAttachments = $attStmt->fetchAll();

$attachments = [];
foreach ($rawAttachments as $att) {
    $attachments[] = [
        'id'                  => (int)$att['id'],
        'filename'            => $att['original_filename'],
        'filesize'            => (int)$att['filesize'],
        'size_display'        => format_bytes((int)$att['filesize']),
        'formatted_size'      => format_bytes((int)$att['filesize']),
        'mime_type'           => $att['mime_type'],
        'icon_class'          => get_attachment_icon_class($att['mime_type'], $att['original_filename']),
        'download_url'        => app_base_url('public/download.php?id=' . $att['id']),
    ];
}

$senderDisplayName = trim(($email['sender_first_name'] ?? '') . ' ' . ($email['sender_last_name'] ?? ''));
if (empty($senderDisplayName)) {
    $senderDisplayName = $email['sender_email'];
}
$senderInitial = strtoupper(substr($senderDisplayName, 0, 1));

$createdAtTimestamp = strtotime($email['created_at']);
$formattedDate = date('D, M d, Y \a\t h:i A', $createdAtTimestamp);

// Humanized relative time calculation
$diffSeconds = time() - $createdAtTimestamp;
if ($diffSeconds < 60) {
    $relativeTime = 'Just now';
} elseif ($diffSeconds < 3600) {
    $mins = max(1, (int)floor($diffSeconds / 60));
    $relativeTime = "{$mins}m ago";
} elseif ($diffSeconds < 86400) {
    $hours = max(1, (int)floor($diffSeconds / 3600));
    $relativeTime = "{$hours}h ago";
} elseif ($diffSeconds < 604800) {
    $days = max(1, (int)floor($diffSeconds / 86400));
    $relativeTime = "{$days}d ago";
} else {
    $relativeTime = date('M d, Y', $createdAtTimestamp);
}

$sanitizedBodyHtml = sanitize_email_html($email['body'], isset($_GET['images']) && $_GET['images'] === '1');
$hasBlockedImages = str_contains($sanitizedBodyHtml, 'data-blocked-src=');

echo json_encode([
    'success' => true,
    'email'   => [
        'id'                  => (int)$email['id'],
        'subject'             => $email['subject'] ?: '(No Subject)',
        'body_html'           => $sanitizedBodyHtml,
        'sanitized_body_html' => $sanitizedBodyHtml,
        'has_blocked_images'  => $hasBlockedImages,
        'folder'         => $email['folder'],
        'is_read'        => (int)$email['is_read'],
        'was_unread'     => $wasUnread,
        'is_starred'     => (int)$email['is_starred'],
        'sender_id'      => (int)$email['sender_id'],
        'recipient_id'   => $email['recipient_id'] ? (int)$email['recipient_id'] : null,
        'sender_name'    => $senderDisplayName,
        'sender_email'   => $email['sender_email'],
        'sender_initial' => $senderInitial,
        'recipients'     => $recipients,
        'attachments'    => $attachments,
        'created_at'     => $email['created_at'],
        'formatted_date' => $formattedDate,
        'relative_time'  => $relativeTime,
        'reply_url'      => app_base_url('public/compose.php?reply_to=' . $email['id']),
        'reply_all_url'  => app_base_url('public/compose.php?reply_all=' . $email['id']),
        'forward_url'    => app_base_url('public/compose.php?forward=' . $email['id']),
        'read_url'       => app_base_url('public/read.php?id=' . $email['id']),
    ]
], JSON_UNESCAPED_UNICODE);
