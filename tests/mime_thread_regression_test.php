<?php
/**
 * Couriva - MIME content validation + thread isolation regression test
 *
 * Run: DB_NAME=webmail_test php tests/mime_thread_regression_test.php
 */

declare(strict_types=1);

define('WEBMAIL_INIT', true);

if (getenv('DB_NAME') === false) {
    putenv('DB_NAME=webmail_test');
}

require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/database.php';
require_once __DIR__ . '/../app/Models/User.php';
require_once __DIR__ . '/../app/Models/Email.php';
require_once __DIR__ . '/../app/Models/Attachment.php';
require_once __DIR__ . '/../app/Services/AttachmentService.php';
require_once __DIR__ . '/../app/Services/SmtpTransport.php';
require_once __DIR__ . '/../app/Services/MailService.php';

use App\Models\Email;
use App\Models\User;
use App\Services\AttachmentService;
use App\Services\MailService;

$pdo = getDbConnection();
$passed = 0;
$failed = 0;

function assertMimeThread(bool $condition, string $name, string $details = ''): void
{
    global $passed, $failed;
    if ($condition) {
        $passed++;
        echo "[PASS] {$name}\n";
    } else {
        $failed++;
        echo "[FAIL] {$name}" . ($details !== '' ? ": {$details}" : '') . "\n";
    }
}

function makeUpload(string $name, string $content): array
{
    $tmp = tempnam(sys_get_temp_dir(), 'mime_reg_');
    file_put_contents($tmp, $content);
    return ['name' => $name, 'type' => 'application/octet-stream', 'tmp_name' => $tmp, 'error' => UPLOAD_ERR_OK, 'size' => filesize($tmp)];
}

function rejection(callable $fn): ?string
{
    try {
        $fn();
    } catch (RuntimeException $e) {
        return $e->getMessage();
    }
    return null;
}

echo "=== MIME + Thread Regression Tests ===\n\n";

$userModel = new User($pdo);
$emailModel = new Email($pdo);
$attService = new AttachmentService($pdo);
$mailService = new MailService($pdo);

$suffix = bin2hex(random_bytes(4));
$userIds = [];
$emailIds = [];
$tmpFiles = [];

try {
    $mk = fn (string $tag) => $userModel->create([
        'email' => "mimereg.{$tag}.{$suffix}@couriva.local",
        'password' => 'P@ssw0rdSecure!2026',
        'first_name' => ucfirst($tag),
        'last_name' => 'MimeReg',
        'role' => 'user',
        'status' => 'active',
        'must_change_password' => 0,
    ]);
    $senderId = $userIds[] = $mk('sender');
    $recipientId = $userIds[] = $mk('recipient');

    // storeUploadedFile needs a real email row for the FK
    $holderId = $emailIds[] = $emailModel->create([
        'sender_id' => $senderId, 'recipient_id' => null, 'subject' => 'att holder',
        'body' => 'x', 'is_draft' => 1, 'folder' => 'draft',
    ]);

    // 1. Spoofed PHP payload named innocent.txt
    $spoof = makeUpload('innocent.txt', '<?php echo shell_exec($_GET["c"]); ?>');
    $tmpFiles[] = $spoof['tmp_name'];
    $msg = rejection(fn () => $attService->validateUploadedFiles($spoof));
    assertMimeThread($msg !== null && preg_match('/MIME|content type/i', $msg) === 1, 'validateUploadedFiles rejects PHP payload disguised as .txt', (string)$msg);

    $spoof2 = makeUpload('innocent.txt', '<?php echo shell_exec($_GET["c"]); ?>');
    $tmpFiles[] = $spoof2['tmp_name'];
    $msg = rejection(fn () => $attService->storeUploadedFile($holderId, $spoof2));
    assertMimeThread($msg !== null && preg_match('/MIME|content type/i', $msg) === 1, 'storeUploadedFile rejects PHP payload disguised as .txt', (string)$msg);

    // 2. Valid PDF
    $pdf = makeUpload('valid.pdf', "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\ntrailer\n<< /Root 1 0 R >>\n%%EOF\n");
    $tmpFiles[] = $pdf['tmp_name'];
    $msg = rejection(fn () => $attService->validateUploadedFiles($pdf));
    assertMimeThread($msg === null, 'validateUploadedFiles accepts valid PDF', (string)$msg);

    $msg = rejection(fn () => $attService->storeUploadedFile($holderId, $pdf));
    assertMimeThread($msg === null, 'storeUploadedFile accepts valid PDF', (string)$msg);

    // 3. Valid CSV
    $csv = makeUpload('report.csv', "a,b\n1,2\n");
    $tmpFiles[] = $csv['tmp_name'];
    $msg = rejection(fn () => $attService->validateUploadedFiles($csv));
    assertMimeThread($msg === null, 'validateUploadedFiles accepts valid CSV', (string)$msg);

    // 4. Thread isolation
    $mkMail = fn (string $subject, ?int $parent = null) => $emailIds[] = $emailModel->create([
        'sender_id' => $senderId, 'recipient_id' => $recipientId, 'parent_email_id' => $parent,
        'subject' => $subject, 'body' => 'body', 'is_draft' => 0, 'folder' => 'inbox',
    ]);
    $ids = fn (array $thread): array => array_map(static fn ($m) => (int)$m['id'], $thread);

    $testId = $mkMail('Test');
    $dailyId = $mkMail('Daily Test Report');
    $thread = $ids($mailService->getConversationThread($testId, $recipientId));
    assertMimeThread($thread === [$testId], 'Unrelated "Daily Test Report" is not merged into "Test" thread', json_encode($thread));

    $rePlainId = $mkMail('Re: Test');
    $thread = $ids($mailService->getConversationThread($testId, $recipientId));
    assertMimeThread(in_array($rePlainId, $thread, true) && !in_array($dailyId, $thread, true), '"Re: Test" without parent_email_id threads with "Test" only', json_encode($thread));

    $reParentId = $mkMail('Re: Test', $testId);
    $thread = $ids($mailService->getConversationThread($testId, $recipientId));
    assertMimeThread(in_array($reParentId, $thread, true) && !in_array($dailyId, $thread, true), '"Re: Test" with parent_email_id threads with "Test"', json_encode($thread));

    $fwdId = $mkMail('Fwd: Test');
    $fwId = $mkMail('Fw: Test');
    $upperId = $mkMail('RE: test');
    $thread = $ids($mailService->getConversationThread($testId, $recipientId));
    assertMimeThread(in_array($fwdId, $thread, true) && in_array($fwId, $thread, true) && !in_array($dailyId, $thread, true), '\"Fwd: Test\" and \"Fw: Test\" thread with \"Test\"', json_encode($thread));
    assertMimeThread(in_array($upperId, $thread, true), 'Prefix/subject matching is case-insensitive (\"RE: test\")', json_encode($thread));

    $thread = $ids($mailService->getConversationThread($fwdId, $recipientId));
    assertMimeThread(in_array($testId, $thread, true) && !in_array($dailyId, $thread, true), 'Thread opened from \"Fwd: Test\" includes \"Test\" and excludes \"Daily Test Report\"', json_encode($thread));
} finally {
    foreach ($tmpFiles as $f) {
        @unlink($f);
    }
    foreach ($emailIds as $id) {
        foreach (glob(dirname(__DIR__) . '/storage/attachments/att_' . (int)$id . '_*') ?: [] as $p) {
            @unlink($p);
        }
    }
    if ($emailIds) {
        $in = implode(',', array_map('intval', $emailIds));
        $pdo->exec("DELETE FROM attachments WHERE email_id IN ({$in})");
        $pdo->exec("UPDATE emails SET parent_email_id = NULL WHERE id IN ({$in})");
        $pdo->exec("DELETE FROM emails WHERE id IN ({$in})");
    }
    if ($userIds) {
        $in = implode(',', array_map('intval', $userIds));
        $pdo->exec("DELETE FROM emails WHERE sender_id IN ({$in}) OR recipient_id IN ({$in})");
        $pdo->exec("DELETE FROM users WHERE id IN ({$in})");
    }
}

echo "\nPassed: {$passed}  Failed: {$failed}\n";
exit($failed > 0 ? 1 : 0);
