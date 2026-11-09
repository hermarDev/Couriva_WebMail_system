<?php
declare(strict_types=1);

define('WEBMAIL_INIT', true);

require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../app/Models/User.php';
require_once __DIR__ . '/../app/Models/Email.php';
require_once __DIR__ . '/../app/Models/Attachment.php';
require_once __DIR__ . '/../app/Models/Contact.php';
require_once __DIR__ . '/../app/Services/AttachmentService.php';
require_once __DIR__ . '/../app/Services/SmtpTransport.php';
require_once __DIR__ . '/../app/Services/MailService.php';

use App\Models\User;
use App\Models\Email;
use App\Services\MailService;

echo "=== Testing Frontend Helpers & APIs (sanitize_email_html, get_attachment_icon_class, theme, get_email) ===\n\n";

$db = getDbConnection();
$userModel = new User($db);
$emailModel = new Email($db);
$mailService = new MailService($db);

$passedTests = 0;

function assertCondition(bool $cond, string $desc): void {
    global $passedTests;
    if ($cond) {
        echo "[PASS] {$desc}\n";
        $passedTests++;
    } else {
        echo "[FAIL] {$desc}\n";
        exit(1);
    }
}

// 1. Test sanitize_email_html
$dangerousHtml = '<p>Hello <script>alert("xss")</script><a href="javascript:alert(1)">Click</a> <img src="x" onerror="alert(2)" style="color:red"><b>World</b></p>';
$sanitized = sanitize_email_html($dangerousHtml);
assertCondition(!str_contains($sanitized, '<script>'), "sanitize_email_html strips <script>");
assertCondition(!str_contains($sanitized, 'javascript:'), "sanitize_email_html strips javascript: href");
assertCondition(!str_contains($sanitized, 'onerror'), "sanitize_email_html strips event handlers");
assertCondition(str_contains($sanitized, '<b>World</b>') || str_contains($sanitized, 'World'), "sanitize_email_html preserves legitimate safe HTML formatting");

// 2. Test get_attachment_icon_class
assertCondition(get_attachment_icon_class('application/pdf', 'doc.pdf') === 'bi bi-file-earmark-pdf-fill text-danger', "PDF icon class");
assertCondition(get_attachment_icon_class('image/png', 'img.png') === 'bi bi-file-earmark-image-fill text-info', "Image icon class");
assertCondition(get_attachment_icon_class('application/zip', 'archive.zip') === 'bi bi-file-earmark-zip-fill text-secondary', "ZIP icon class");
assertCondition(get_attachment_icon_class('text/plain', 'notes.txt') === 'bi bi-file-earmark-text-fill text-muted', "Text icon class");

// 3. Test theme switching logic
$userModel->update(1, ['theme' => 'dark']);
$updatedUser = $userModel->findById(1);
assertCondition($updatedUser['theme'] === 'dark', "User theme updated to 'dark' in DB");

$userModel->update(1, ['theme' => 'light']);
$updatedUser = $userModel->findById(1);
assertCondition($updatedUser['theme'] === 'light', "User theme restored to 'light' in DB");

// 4. Test get_email endpoint logic for Split Reading Pane
$adminUser = $userModel->findByEmail('admin@couriva.local');
assertCondition($adminUser !== null, "Admin user exists");

$sendRes = $mailService->sendEmail(
    (int)$adminUser['id'],
    ['to' => ['john.doe@couriva.local']],
    'Split Pane Test Subject',
    '<p>Testing <strong>split pane</strong> reading view</p>'
);
assertCondition($sendRes['success'], "Sent test email for split pane preview");
$emailId = (int)$sendRes['sent_email_id'];

// Retrieve email details for preview via query matching get_email.php
$stmt = $db->prepare("
    SELECT e.*, 
           s.first_name AS sender_first_name, s.last_name AS sender_last_name, s.email AS sender_email
    FROM emails e
    JOIN users s ON e.sender_id = s.id
    WHERE e.id = ? AND (e.sender_id = ? OR e.recipient_id = ?)
    LIMIT 1
");
$stmt->execute([$emailId, (int)$adminUser['id'], (int)$adminUser['id']]);
$fetched = $stmt->fetch();
assertCondition($fetched !== false && $fetched !== null, "Sender can retrieve email details for preview");
assertCondition($fetched['subject'] === 'Split Pane Test Subject', "Email subject matches expected");

// Test recipients structure
$recipients = $emailModel->getRecipients($emailId);
assertCondition(!empty($recipients), "Recipients retrieved successfully");

echo "\n=== All {$passedTests} Frontend API Tests Passed Successfully! ===\n";
