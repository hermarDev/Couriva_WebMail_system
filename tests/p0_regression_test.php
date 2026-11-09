<?php
/**
 * Couriva - P0 Critical Security Regression Tests
 *
 * Covers:
 *   C1 - Email IDOR via reply / reply-all / forward and Email::findVisibleById()
 *   C2 - Stored XSS through compose body, subject, and sender display names
 *   C3 - Draft attachment theft through an unowned / non-draft draft_id
 *   C4 - Inline JavaScript injection in the admin delete confirmation
 *
 * Run with an isolated database:
 *   DB_NAME=webmail_test php tests/p0_regression_test.php
 */

declare(strict_types=1);

define('WEBMAIL_INIT', true);

if (getenv('DB_NAME') === false) {
    putenv('DB_NAME=webmail_test');
}

require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../app/Models/User.php';
require_once __DIR__ . '/../app/Models/Email.php';
require_once __DIR__ . '/../app/Models/Attachment.php';
require_once __DIR__ . '/../app/Services/AttachmentService.php';
require_once __DIR__ . '/../app/Services/SmtpTransport.php';
require_once __DIR__ . '/../app/Services/MailService.php';

use App\Models\User;
use App\Models\Email;
use App\Models\Attachment;
use App\Services\MailService;

$pdo = getDbConnection();
$passed = 0;
$failed = 0;
$createdEmailIds = [];
$createdUserIds = [];
$suffix = bin2hex(random_bytes(4));

function assertP0(bool $condition, string $name, string $details = ''): void
{
    global $passed, $failed;
    if ($condition) {
        $passed++;
        echo "[\033[32mPASS\033[0m] {$name}\n";
    } else {
        $failed++;
        echo "[\033[31mFAIL\033[0m] {$name}" . ($details !== '' ? ": {$details}" : '') . "\n";
    }
}

/**
 * Execute a web endpoint in an isolated PHP subprocess with a forged session.
 */
function invokeP0Endpoint(string $scriptRelativePath, string $method, array $get = [], ?array $session = null): array
{
    $projectRoot = dirname(__DIR__);
    $scriptFullPath = $projectRoot . '/' . ltrim($scriptRelativePath, '/');

    $bootstrap = sprintf(
        'register_shutdown_function(function () {
            echo "\n__HTTP_STATUS:" . http_response_code() . ":STATUS__\n";
        });
        $_SERVER["REQUEST_METHOD"] = %s;
        $_SERVER["SCRIPT_FILENAME"] = %s;
        $_SERVER["SCRIPT_NAME"] = %s;
        $_SERVER["REMOTE_ADDR"] = "127.0.0.1";
        $_SERVER["HTTP_USER_AGENT"] = "P0-Regression-Test";
        $_GET = %s;
        $_POST = [];
        session_start();
        $_SESSION = %s;
        require %s;',
        var_export($method, true),
        var_export($scriptFullPath, true),
        var_export('/' . ltrim($scriptRelativePath, '/'), true),
        var_export($get, true),
        var_export($session ?? [], true),
        var_export($scriptFullPath, true)
    );

    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $proc = proc_open('php -d auto_prepend_file= -r ' . escapeshellarg($bootstrap), $descriptors, $pipes, $projectRoot);
    if (!is_resource($proc)) {
        return ['status' => 0, 'body' => '', 'stderr' => 'proc_open failed'];
    }

    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[2]);
    proc_close($proc);

    $status = 200;
    if (preg_match('/__HTTP_STATUS:(\d+):STATUS__/', $stdout, $m)) {
        $status = (int)$m[1];
        $stdout = str_replace($m[0], '', $stdout);
    }

    return ['status' => $status, 'body' => $stdout, 'stderr' => $stderr];
}

function p0Session(array $user): array
{
    return [
        'user_id'         => (int)$user['id'],
        'user_email'      => $user['email'],
        'user_first_name' => $user['first_name'],
        'user_last_name'  => $user['last_name'],
        'user_full_name'  => trim($user['first_name'] . ' ' . $user['last_name']),
        'user_role'       => $user['role'],
        'must_change_password' => 0,
        'session_epoch'   => (int)($user['session_epoch'] ?? 1),
        'auth_time'       => time(),
        'last_activity'   => time(),
    ];
}

$userModel = new User($pdo);
$emailModel = new Email($pdo);
$attachmentModel = new Attachment($pdo);

try {
    echo "=== P0 Critical Security Regression Tests ===\n";
    echo "Database: " . (getenv('DB_NAME') ?: 'default') . "\n\n";

    // ------------------------------------------------------------------
    // Fixtures
    // ------------------------------------------------------------------
    $makeUser = function (string $label, string $role = 'user', array $extra = []) use ($userModel, $pdo, $suffix, &$createdUserIds): array {
        $email = 'p0.' . strtolower($label) . '.' . $suffix . '@couriva.local';
        $id = $userModel->create(array_merge([
            'email'      => $email,
            'password'   => 'P0-Test-Password-123!',
            'first_name' => ucfirst($label),
            'last_name'  => 'P0',
            'role'       => $role,
            'status'     => 'active',
            'timezone'   => 'UTC',
            'must_change_password' => 0,
        ], $extra));
        $createdUserIds[] = $id;
        $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
        $stmt->execute([$id]);
        return $stmt->fetch();
    };

    $attacker = $makeUser('attacker');
    $victim = $makeUser('victim');
    $third = $makeUser('third');
    $admin = $makeUser('admin', 'admin');

    $victimEmailId = $emailModel->create([
        'sender_id'    => (int)$victim['id'],
        'recipient_id' => (int)$victim['id'],
        'subject'      => 'C1_VICTIM_SUBJECT_' . $suffix,
        'body'         => 'C1_VICTIM_BODY_' . $suffix,
        'is_read'      => 1,
        'folder'       => 'inbox',
    ]);
    $createdEmailIds[] = $victimEmailId;

    $attackerSentId = $emailModel->create([
        'sender_id'    => (int)$attacker['id'],
        'recipient_id' => null,
        'subject'      => 'C1_ATTACKER_SENT_' . $suffix,
        'body'         => 'C1_ATTACKER_SENT_BODY',
        'is_read'      => 1,
        'folder'       => 'sent',
    ]);
    $createdEmailIds[] = $attackerSentId;

    $victimDraftId = $emailModel->create([
        'sender_id'    => (int)$victim['id'],
        'recipient_id' => null,
        'subject'      => 'C3_VICTIM_DRAFT_' . $suffix,
        'body'         => 'C3_VICTIM_DRAFT_BODY',
        'is_read'      => 0,
        'is_draft'     => 1,
        'folder'       => 'draft',
    ]);
    $createdEmailIds[] = $victimDraftId;
    $attachmentModel->create([
        'email_id'          => $victimDraftId,
        'original_filename' => 'victim-secret.txt',
        'stored_filename'   => 'victim-secret-' . $suffix . '.txt',
        'filepath'          => 'storage/attachments/victim-secret-' . $suffix . '.txt',
        'mime_type'         => 'text/plain',
        'filesize'          => 12,
    ]);

    // ------------------------------------------------------------------
    // C1 - Email IDOR / findVisibleById
    // ------------------------------------------------------------------
    echo "-- C1: Cross-account email access --\n";

    assertP0(method_exists($emailModel, 'findVisibleById'), 'C1 Email::findVisibleById() exists');
    $foreign = method_exists($emailModel, 'findVisibleById')
        ? $emailModel->findVisibleById($victimEmailId, (int)$attacker['id'])
        : ['id' => $victimEmailId];
    assertP0($foreign === null, 'C1 foreign recipient email is not visible to attacker');

    $legitimate = method_exists($emailModel, 'findVisibleById')
        ? $emailModel->findVisibleById($victimEmailId, (int)$victim['id'])
        : null;
    assertP0(is_array($legitimate) && (int)$legitimate['id'] === $victimEmailId, 'C1 recipient can still read own email');

    $ownSent = method_exists($emailModel, 'findVisibleById')
        ? $emailModel->findVisibleById($attackerSentId, (int)$attacker['id'])
        : null;
    assertP0(is_array($ownSent) && (int)$ownSent['id'] === $attackerSentId, 'C1 sender can still read own sent email');

    $foreignDraft = method_exists($emailModel, 'findVisibleById')
        ? $emailModel->findVisibleById($victimDraftId, (int)$attacker['id'])
        : ['id' => $victimDraftId];
    assertP0($foreignDraft === null, 'C1 foreign draft is not visible to attacker');

    $composeSource = (string)file_get_contents(dirname(__DIR__) . '/public/compose.php');
    assertP0(str_contains($composeSource, 'findVisibleById'), 'C1 compose reply path uses findVisibleById');
    assertP0(!preg_match('/\$emailModel->findById\(\$refId\)/', $composeSource), 'C1 compose no longer uses unscoped findById($refId)');

    $response = invokeP0Endpoint('public/compose.php', 'GET', ['reply_to' => (string)$victimEmailId], p0Session($attacker));
    assertP0($response['status'] === 200, 'C1 attacker reply request returns the compose page', 'status=' . $response['status'] . ' stderr=' . substr($response['stderr'], 0, 200));
    assertP0(!str_contains($response['body'], 'C1_VICTIM_SUBJECT_' . $suffix), 'C1 foreign subject is not shown in reply composer');
    assertP0(!str_contains($response['body'], 'C1_VICTIM_BODY_' . $suffix), 'C1 foreign body is not shown in reply composer');
    assertP0(!str_contains($response['body'], $victim['email']), 'C1 foreign sender address is not shown in reply composer');

    $response = invokeP0Endpoint('public/compose.php', 'GET', ['reply_to' => (string)$victimEmailId], p0Session($victim));
    assertP0($response['status'] === 200, 'C1 recipient reply request returns the compose page', 'status=' . $response['status']);
    assertP0(str_contains($response['body'], 'C1_VICTIM_BODY_' . $suffix), 'C1 recipient still receives the quoted body');
    assertP0(str_contains($response['body'], 'C1_VICTIM_SUBJECT_' . $suffix), 'C1 recipient still receives the quoted subject');

    // ------------------------------------------------------------------
    // C2 - Compose XSS / sanitizer corpus
    // ------------------------------------------------------------------
    echo "\n-- C2: Compose body, subject, sender-name XSS --\n";

    $xssCorpus = [
        ['<script>alert(1)</script><b>safe</b>', ['<script', 'alert(1)'], ['<b>safe</b>']],
        ['<img src=x onerror=alert(1)>', ['onerror'], []],
        ['<a href="javascript:alert(1)">click</a>', ['javascript:'], []],
        ['<a href="vbscript:msgbox(1)">click</a>', ['vbscript:'], []],
        ['<a href="data:text/html;base64,PHNjcmlwdD4=">click</a>', ['data:text/html'], []],
        ['<div style="background:url(javascript:alert(1));position:fixed;top:0">overlay</div>', ['url(', 'javascript:', 'position:fixed'], []],
        ['<svg onload=alert(1)></svg>', ['<svg', 'onload'], []],
        ['<iframe src="https://evil.example"></iframe>', ['<iframe'], []],
        ['<object data="x"></object><embed src="x">', ['<object', '<embed'], []],
        ['<form action="https://evil.example"><input name="x"></form>', ['<form', '<input'], []],
        ['<style>body{display:none}</style><p>kept</p>', ['<style', 'display:none'], ['<p>kept</p>']],
        ['<p onclick="alert(1)">Hello <strong>world</strong></p>', ['onclick'], ['<strong>world</strong>']],
        ['<p>Hello <strong>World</strong></p><ul><li>One</li></ul>', [], ['<strong>World</strong>', '<ul>', '<li>One</li>']],
    ];

    foreach ($xssCorpus as $index => [$payload, $forbidden, $required]) {
        $clean = sanitize_email_html($payload);
        $hasExecutableMarkup =
            preg_match('/<\s*(script|svg|iframe|object|embed|form|input|style|meta|link|base)\b/i', $clean) === 1 ||
            preg_match('/<[^>]*\son[a-z]+\s*=/i', $clean) === 1 ||
            preg_match('/(href|src|action|formaction|xlink:href)\s*=\s*["\']?\s*(javascript|vbscript|data:text\/html|file):/i', $clean) === 1 ||
            preg_match('/style\s*=\s*["\'][^"\']*(expression|behavior|-moz-binding|@import|url\s*\(\s*[\'"]?\s*(javascript|vbscript|data:text\/html))/i', $clean) === 1;

        $requiredOk = true;
        foreach ($required as $needle) {
            if (stripos($clean, $needle) === false) {
                $requiredOk = false;
                break;
            }
        }
        assertP0(!$hasExecutableMarkup, 'C2 sanitizer removes dangerous payload #' . ($index + 1), 'output=' . substr($clean, 0, 180));
        assertP0($requiredOk, 'C2 sanitizer preserves safe markup #' . ($index + 1), 'output=' . substr($clean, 0, 180));
    }

    $subjectPayload = '<script>alert("C2SUBJ")</script>';
    assertP0(!str_contains(e($subjectPayload), '<script>'), 'C2 subject payload is escaped by e()');
    assertP0(str_contains(e($subjectPayload), '&lt;script&gt;'), 'C2 subject payload arrives HTML-encoded');

    $senderNamePayload = '<img src=x onerror=alert("C2NAME")>';
    assertP0(!str_contains(e($senderNamePayload), '<img'), 'C2 sender-name payload is escaped by e()');
    assertP0(str_contains(e($senderNamePayload), '&lt;img'), 'C2 sender-name payload arrives HTML-encoded');

    $composePayload = 'C2_BODY_START <img src=x onerror=alert("C2BODY")> <strong>C2_SAFE_BOLD</strong>';
    $attackerDraftId = $emailModel->create([
        'sender_id'    => (int)$attacker['id'],
        'recipient_id' => null,
        'subject'      => 'C2_DRAFT_SUBJECT_' . $suffix,
        'body'         => $composePayload,
        'is_read'      => 1,
        'is_draft'     => 1,
        'folder'       => 'draft',
    ]);
    $createdEmailIds[] = $attackerDraftId;

    $response = invokeP0Endpoint('public/compose.php', 'GET', ['draft_id' => (string)$attackerDraftId], p0Session($attacker));
    assertP0($response['status'] === 200, 'C2 compose draft page renders', 'status=' . $response['status'] . ' stderr=' . substr($response['stderr'], 0, 200));
    assertP0(str_contains($response['body'], 'C2_BODY_START'), 'C2 safe body text is preserved in the editor');
    assertP0(str_contains($response['body'], '<strong>C2_SAFE_BOLD</strong>'), 'C2 safe formatting is preserved in the editor');
    assertP0(!str_contains($response['body'], 'onerror'), 'C2 event handler is not injected into the compose page');
    assertP0(!str_contains($response['body'], 'alert("C2BODY")'), 'C2 script payload is not injected into the compose page');

    $replyEmailId = $emailModel->create([
        'sender_id'    => (int)$third['id'],
        'recipient_id' => (int)$victim['id'],
        'subject'      => 'C2_REPLY_SUBJECT_' . $suffix,
        'body'         => 'C2_REPLY_BODY <img src=x onerror=alert("C2REPLY")> <b>C2_REPLY_SAFE</b>',
        'is_read'      => 1,
        'folder'       => 'inbox',
    ]);
    $createdEmailIds[] = $replyEmailId;

    $response = invokeP0Endpoint('public/compose.php', 'GET', ['reply_to' => (string)$replyEmailId], p0Session($victim));
    assertP0($response['status'] === 200, 'C2 reply composer renders', 'status=' . $response['status']);
    assertP0(str_contains($response['body'], 'C2_REPLY_BODY'), 'C2 reply body text is preserved');
    assertP0(!str_contains($response['body'], 'onerror'), 'C2 reply event handler is stripped');
    assertP0(!str_contains($response['body'], 'alert("C2REPLY")'), 'C2 reply script payload is stripped');

    $namePayloadUser = $makeUser('namepayload', 'user', [
        'first_name' => '<img src=x onerror=alert("C2NAME")>',
        'last_name'  => 'Payload',
    ]);
    $nameEmailId = $emailModel->create([
        'sender_id'    => (int)$namePayloadUser['id'],
        'recipient_id' => (int)$victim['id'],
        'subject'      => '<script>alert("C2SUBJ")</script> Subject',
        'body'         => '<p>Body</p>',
        'is_read'      => 1,
        'folder'       => 'inbox',
    ]);
    $createdEmailIds[] = $nameEmailId;

    $response = invokeP0Endpoint('public/read.php', 'GET', ['id' => (string)$nameEmailId], p0Session($victim));
    assertP0($response['status'] === 200, 'C2 read page renders for sender-name payload', 'status=' . $response['status'] . ' stderr=' . substr($response['stderr'], 0, 200));
    assertP0(!str_contains($response['body'], '<img src=x onerror='), 'C2 sender-name payload is not rendered as markup');
    assertP0(!str_contains($response['body'], '<script>alert("C2SUBJ")'), 'C2 subject payload is not rendered as a script tag');

    // ------------------------------------------------------------------
    // C3 - Draft attachment ownership
    // ------------------------------------------------------------------
    echo "\n-- C3: Draft attachment ownership --\n";

    $method = 'transferDraftAttachmentsOnSend';
    assertP0(method_exists($mailService ??= new MailService($pdo), $method), 'C3 MailService::' . $method . '() exists');

    if (method_exists($mailService, $method)) {
        $attackerSent = $emailModel->create([
            'sender_id'    => (int)$attacker['id'],
            'recipient_id' => null,
            'subject'      => 'C3_ATTACKER_SENT_' . $suffix,
            'body'         => 'C3 sent body',
            'is_read'      => 1,
            'folder'       => 'sent',
        ]);
        $createdEmailIds[] = $attackerSent;

        $copied = $mailService->{$method}($victimDraftId, (int)$attacker['id'], $attackerSent, []);
        assertP0($copied === 0, 'C3 foreign draft copies zero attachments', 'copied=' . var_export($copied, true));
        assertP0(count($attachmentModel->getByEmailId($attackerSent)) === 0, 'C3 foreign draft leaves sent message attachment-free');
        assertP0(count($attachmentModel->getByEmailId($victimDraftId)) === 1, 'C3 victim draft attachment remains untouched');
        assertP0($emailModel->findById($victimDraftId) !== null, 'C3 victim draft remains in place');

        $copied = $mailService->{$method}($victimEmailId, (int)$attacker['id'], $attackerSent, []);
        assertP0($copied === 0, 'C3 non-draft message copies zero attachments');
        assertP0(count($attachmentModel->getByEmailId($victimEmailId)) === 0, 'C3 non-draft message is not modified');

        $attackerDraft = $emailModel->create([
            'sender_id'    => (int)$attacker['id'],
            'recipient_id' => null,
            'subject'      => 'C3_ATTACKER_DRAFT_' . $suffix,
            'body'         => 'C3 draft body',
            'is_read'      => 1,
            'is_draft'     => 1,
            'folder'       => 'draft',
        ]);
        $createdEmailIds[] = $attackerDraft;
        $attachmentModel->create([
            'email_id'          => $attackerDraft,
            'original_filename' => 'owned-draft.txt',
            'stored_filename'   => 'owned-draft-' . $suffix . '.txt',
            'filepath'          => 'storage/attachments/owned-draft-' . $suffix . '.txt',
            'mime_type'         => 'text/plain',
            'filesize'          => 10,
        ]);

        $copied = $mailService->{$method}($attackerDraft, (int)$attacker['id'], $attackerSent, []);
        assertP0($copied === 1, 'C3 owned draft copies its attachment', 'copied=' . var_export($copied, true));
        assertP0(count($attachmentModel->getByEmailId($attackerSent)) === 1, 'C3 owned draft attachment reaches the sent message');
        assertP0($emailModel->findById($attackerDraft) === null, 'C3 owned draft is removed after transfer');
    }

    // ------------------------------------------------------------------
    // C4 - Admin delete confirmation inline JS
    // ------------------------------------------------------------------
    echo "\n-- C4: Admin confirmation XSS --\n";

    $adminSource = (string)file_get_contents(dirname(__DIR__) . '/admin/users.php');
    $appJs = (string)file_get_contents(dirname(__DIR__) . '/assets/js/app.js');
    assertP0(!preg_match('/\bonsubmit\s*=/i', $adminSource), 'C4 admin users page has no inline onsubmit handler');
    assertP0(str_contains($adminSource, 'data-confirm'), 'C4 admin delete form uses a data-confirm attribute');
    assertP0(str_contains($appJs, 'data-confirm'), 'C4 external app.js owns the confirmation listener');

    $payloadEmail = '"a\'-alert(1)-\'"@couriva.local';
    $quotedUserId = $userModel->create([
        'email'      => $payloadEmail,
        'password'   => 'P0-Test-Password-123!',
        'first_name' => 'Quoted',
        'last_name'  => 'Payload',
        'role'       => 'user',
        'status'     => 'active',
        'timezone'   => 'UTC',
        'must_change_password' => 0,
    ]);
    $createdUserIds[] = $quotedUserId;

    assertP0(!str_contains(e($payloadEmail), "'"), 'C4 quoted email local part is escaped by e()');

    $response = invokeP0Endpoint('admin/users.php', 'GET', [], p0Session($admin));
    assertP0($response['status'] === 200, 'C4 admin users page renders', 'status=' . $response['status'] . ' stderr=' . substr($response['stderr'], 0, 200));
    assertP0(!preg_match('/\bonsubmit\s*=/i', $response['body']), 'C4 rendered admin page has no inline onsubmit');
    assertP0(str_contains($response['body'], 'data-confirm'), 'C4 rendered admin page carries data-confirm');
    assertP0(!preg_match('/\bon[a-z]+\s*=\s*["\'][^"\']*alert\(1\)/i', $response['body']), 'C4 quoted local part cannot execute as an inline handler');
    assertP0(str_contains($response['body'], '&quot;a&#039;-alert(1)-&#039;&quot;@couriva.local'), 'C4 quoted email is rendered HTML-encoded');
} catch (Throwable $e) {
    assertP0(false, 'P0 suite completed without an uncaught exception', $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
} finally {
    foreach ($createdEmailIds as $emailId) {
        $pdo->prepare("DELETE FROM attachments WHERE email_id = ?")->execute([$emailId]);
        $pdo->prepare("DELETE FROM email_recipients WHERE email_id = ?")->execute([$emailId]);
        $pdo->prepare("DELETE FROM emails WHERE id = ?")->execute([$emailId]);
    }
    foreach ($createdUserIds as $userId) {
        $pdo->prepare("DELETE FROM system_logs WHERE user_id = ?")->execute([$userId]);
        $pdo->prepare("DELETE FROM users WHERE id = ?")->execute([$userId]);
    }
}

echo "\n=== P0 Summary: {$passed} passed, {$failed} failed ===\n";
exit($failed === 0 ? 0 : 1);
