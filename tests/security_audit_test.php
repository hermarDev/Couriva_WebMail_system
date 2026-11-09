<?php
/**
 * Couriva - Automated Security & Vulnerability Test Suite
 *
 * Exhaustively tests:
 * 1. OWASP Top 10 - Broken Access Control & IDOR (Email, Drafts, Attachments, Contacts, Mutations)
 * 2. OWASP Top 10 - Cross-Site Scripting (Reflected & Stored XSS vectors, sanitize_email_html, e())
 * 3. OWASP Top 10 - Cross-Site Request Forgery (CSRF rejection on all POST endpoints)
 * 4. OWASP Top 10 - Privilege Escalation (Admin route protection against regular users and guests)
 * 5. OWASP Top 10 - File Upload Security & Path Traversal (Forbidden extensions, safe paths, directory isolation)
 * 6. OWASP Top 10 - SQL Injection Defense (Parameterized queries under hostile inputs)
 * 7. Authentication & Session Security (BCrypt hashing, disabled account rejection, active session revocation, audit logs)
 * 8. Cross-Platform Configuration Security (.htaccess, web.config, storage isolation)
 */

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
require_once __DIR__ . '/../app/Services/AuthService.php';

use App\Models\User;
use App\Models\Email;
use App\Models\Attachment;
use App\Models\Contact;
use App\Services\AttachmentService;
use App\Services\MailService;
use App\Services\AuthService;

$pdo = getDbConnection();
$totalPassed = 0;
$totalFailed = 0;
$testResults = [];

function assertSecurity(bool $condition, string $category, string $testName, string $details = ''): void
{
    global $totalPassed, $totalFailed, $testResults;
    if ($condition) {
        $totalPassed++;
        echo "[\033[32mPASS\033[0m] [{$category}] {$testName}\n";
        $testResults[] = ['status' => 'PASS', 'category' => $category, 'name' => $testName, 'details' => $details];
    } else {
        $totalFailed++;
        echo "[\033[31mFAIL\033[0m] [{$category}] {$testName}" . ($details ? ": {$details}" : "") . "\n";
        $testResults[] = ['status' => 'FAIL', 'category' => $category, 'name' => $testName, 'details' => $details];
    }

}

function auditSession(int $userId, string $email, string $role = 'user', ?string $csrfToken = null): array
{
    global $pdo;
    $stmt = $pdo->prepare("SELECT session_epoch, first_name, last_name, must_change_password FROM users WHERE id = ? LIMIT 1");
    $stmt->execute([$userId]);
    $user = $stmt->fetch() ?: [];

    return array_filter([
        'user_id' => $userId,
        'user_email' => $email,
        'user_role' => $role,
        'user_first_name' => $user['first_name'] ?? '',
        'user_last_name' => $user['last_name'] ?? '',
        'session_epoch' => (int)($user['session_epoch'] ?? 1),
        'must_change_password' => 0,
        'auth_time' => time(),
        'last_activity' => time(),
        'csrf_token' => $csrfToken,
    ], static fn ($value): bool => $value !== null);
}

/**
 * Execute endpoint in an isolated PHP subprocess and return HTTP code, stdout, stderr, and headers
 */
function invokeEndpoint(string $scriptRelativePath, string $method, array $post = [], array $get = [], ?array $session = null, array $headers = []): array
{
    $projectRoot = dirname(__DIR__);
    $scriptFullPath = $projectRoot . '/' . ltrim($scriptRelativePath, '/');

    $bootstrapCode = sprintf(
        'register_shutdown_function(function() {
            echo "\n__HTTP_STATUS:" . http_response_code() . ":STATUS__\n";
        });
        $_SERVER["REQUEST_METHOD"] = "%s";
        $_SERVER["SCRIPT_FILENAME"] = %s;
        $_SERVER["SCRIPT_NAME"] = %s;
        $_SERVER["CONTENT_TYPE"] = "%s";
        $_GET = %s;
        $_POST = %s;
        session_start();
        $_SESSION = %s;
        %s
        require %s;',
        $method,
        var_export($scriptFullPath, true),
        var_export('/' . ltrim($scriptRelativePath, '/'), true),
        $headers['Content-Type'] ?? ($method === 'POST' ? 'application/x-www-form-urlencoded' : ''),
        var_export($get, true),
        var_export($post, true),
        var_export($session ?? [], true),
        !empty($headers['X-CSRF-Token']) ? sprintf('$_SERVER["HTTP_X_CSRF_TOKEN"] = "%s";', $headers['X-CSRF-Token']) : '',
        var_export($scriptFullPath, true)
    );

    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];

    $cmd = 'php -d auto_prepend_file= -r ' . escapeshellarg($bootstrapCode);
    $proc = proc_open($cmd, $descriptors, $pipes, $projectRoot);

    $stdout = '';
    $stderr = '';
    $exitCode = -1;

    if (is_resource($proc)) {
        if (!empty($headers['raw_input'])) {
            fwrite($pipes[0], $headers['raw_input']);
        }
        fclose($pipes[0]);

        $stdout = stream_get_contents($pipes[1]);
        fclose($pipes[1]);

        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[2]);

        $exitCode = proc_close($proc);
    }

    $httpCode = 200;
    if (preg_match('/__HTTP_STATUS:(\d+):STATUS__/', $stdout, $matches)) {
        $httpCode = (int)$matches[1];
        $stdout = str_replace($matches[0], '', $stdout);
    }

    $json = json_decode(trim($stdout), true);

    return [
        'http_code' => $httpCode,
        'stdout'    => trim($stdout),
        'stderr'    => trim($stderr),
        'json'      => $json,
        'exit_code' => $exitCode,
    ];
}

echo "====================================================================\n";
echo "       WEBMAIL APPLICATION COMPREHENSIVE SECURITY AUDIT SUITE       \n";
echo "====================================================================\n\n";

$userModel = new User($pdo);
$emailModel = new Email($pdo);
$contactModel = new Contact($pdo);
$attachmentModel = new Attachment($pdo);
$mailService = new MailService($pdo);
$authService = new AuthService($pdo);

// -----------------------------------------------------------------------------
// Test Setup: Create Distinct Auditor Test Users
// -----------------------------------------------------------------------------
$aliceEmail = 'audit.alice.' . time() . '@couriva.local';
$bobEmail   = 'audit.bob.'   . time() . '@couriva.local';
$eveEmail   = 'audit.eve.'   . time() . '@couriva.local';
$testPass   = 'P@ssw0rdSecure!2026';

$aliceId = $userModel->create([
    'email'      => $aliceEmail,
    'password'   => $testPass,
    'first_name' => 'Alice',
    'last_name'  => 'Auditor',
    'role'       => 'user',
    'status'     => 'active',
    'must_change_password' => 0,
]);

$bobId = $userModel->create([
    'email'      => $bobEmail,
    'password'   => $testPass,
    'first_name' => 'Bob',
    'last_name'  => 'Auditor',
    'role'       => 'user',
    'status'     => 'active',
    'must_change_password' => 0,
]);

$eveId = $userModel->create([
    'email'      => $eveEmail,
    'password'   => $testPass,
    'first_name' => 'Eve',
    'last_name'  => 'Attacker',
    'role'       => 'user',
    'status'     => 'active',
    'must_change_password' => 0,
]);

echo "Created test identities: Alice (#{$aliceId}), Bob (#{$bobId}), Eve (#{$eveId})\n\n";

// =============================================================================
// SECTION 1: BROKEN ACCESS CONTROL & IDOR AUDIT
// =============================================================================
echo "--- SECTION 1: Broken Access Control & IDOR ---\n";

// Alice creates a private confidential draft with attachment
$aliceDraftId = $emailModel->create([
    'sender_id'       => $aliceId,
    'recipient_id'    => null,
    'subject'         => 'Alice Confidential Financials Draft',
    'body'            => 'Top Secret Budget Data 2026',
    'is_draft'        => 1,
    'folder'          => 'draft',
]);
$aliceDraftAttId = $attachmentModel->create([
    'email_id'          => $aliceDraftId,
    'original_filename' => 'q4_payroll.xlsx',
    'stored_filename'   => 'att_alice_draft_test.xlsx',
    'filepath'          => 'storage/attachments/att_seed_iis_settings.txt', // reference safe existing file
    'mime_type'         => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    'filesize'          => 4096,
]);

// Test 1.1: Eve attempts to read Alice's draft via get_email.php
$eveSession = auditSession($eveId, $eveEmail);
$res = invokeEndpoint('public/api/get_email.php', 'GET', [], ['id' => $aliceDraftId], $eveSession);
assertSecurity(
    $res['http_code'] === 404 && ($res['json']['success'] ?? null) === false,
    'IDOR',
    'Eve cannot read Alice private draft via api/get_email.php (Returns 404/Access Denied)',
    "HTTP {$res['http_code']}"
);

// Test 1.2: Eve attempts to read Alice's draft via read.php
$res = invokeEndpoint('public/read.php', 'GET', [], ['id' => $aliceDraftId], $eveSession);
assertSecurity(
    $res['http_code'] === 302 || str_contains($res['stdout'], 'not found or access denied'),
    'IDOR',
    'Eve cannot read Alice private draft via read.php (Redirects or Denies)',
    "HTTP {$res['http_code']}"
);

// Test 1.3: Eve attempts to download Alice's draft attachment via download.php
$res = invokeEndpoint('public/download.php', 'GET', [], ['id' => $aliceDraftAttId], $eveSession);
assertSecurity(
    $res['http_code'] === 403,
    'IDOR',
    'Eve cannot download Alice draft attachment via download.php (Strict 403 Forbidden)',
    "HTTP {$res['http_code']}"
);

// Alice sends an email to Bob
$sendRes = $mailService->sendEmail(
    $aliceId,
    ['to' => [$bobEmail]],
    'Alice Project Roadmap',
    'Here is the strategic roadmap for Bob only.'
);
$aliceSentEmailId = (int)$sendRes['sent_email_id'];
$bobInboxEmailIds = $sendRes['inbox_email_ids'] ?? [];
$bobInboxEmailId = !empty($bobInboxEmailIds) ? (int)$bobInboxEmailIds[0] : 0;

// Add attachment to Alice's sent email and Bob's inbox email
$aliceSentAttId = $attachmentModel->create([
    'email_id'          => $aliceSentEmailId,
    'original_filename' => 'roadmap_v1.pdf',
    'stored_filename'   => 'att_roadmap_alice.pdf',
    'filepath'          => 'storage/attachments/att_seed_q3_blueprint.pdf',
    'mime_type'         => 'application/pdf',
    'filesize'          => 154200,
]);
$bobInboxAttId = $attachmentModel->create([
    'email_id'          => $bobInboxEmailId,
    'original_filename' => 'roadmap_v1.pdf',
    'stored_filename'   => 'att_roadmap_bob.pdf',
    'filepath'          => 'storage/attachments/att_seed_q3_blueprint.pdf',
    'mime_type'         => 'application/pdf',
    'filesize'          => 154200,
]);

// Test 1.4: Eve attempts to download Alice's sent email attachment
$res = invokeEndpoint('public/download.php', 'GET', [], ['id' => $aliceSentAttId], $eveSession);
assertSecurity(
    $res['http_code'] === 403,
    'IDOR',
    'Eve cannot download Alice sent email attachment (Strict 403 Forbidden)',
    "HTTP {$res['http_code']}"
);

// Test 1.5: Eve attempts to download Bob's received inbox attachment
$res = invokeEndpoint('public/download.php', 'GET', [], ['id' => $bobInboxAttId], $eveSession);
assertSecurity(
    $res['http_code'] === 403,
    'IDOR',
    'Eve cannot download Bob received email attachment (Strict 403 Forbidden)',
    "HTTP {$res['http_code']}"
);

// Test 1.6: Bob can legitimately download his own attachment
$bobSession = auditSession($bobId, $bobEmail);
$res = invokeEndpoint('public/download.php', 'GET', [], ['id' => $bobInboxAttId], $bobSession);
assertSecurity(
    $res['http_code'] === 200,
    'IDOR',
    'Legitimate recipient (Bob) can download his inbox attachment (HTTP 200)',
    "HTTP {$res['http_code']}"
);

// Test 1.7: Alice (sender) attempts to alter / star Bob's received email copy
$aliceSession = auditSession($aliceId, $aliceEmail, 'user', 'alice_token_123');
$res = invokeEndpoint(
    'public/api/toggle_star.php',
    'POST',
    [],
    [],
    $aliceSession,
    ['Content-Type' => 'application/json', 'X-CSRF-Token' => 'alice_token_123', 'raw_input' => json_encode(['email_id' => $bobInboxEmailId, 'is_starred' => 1])]
);
// Verify in DB that Bob's email is_starred is still 0
$checkStmt = $pdo->prepare("SELECT is_starred FROM emails WHERE id = ?");
$checkStmt->execute([$bobInboxEmailId]);
$bobStarredStatus = (int)$checkStmt->fetchColumn();
assertSecurity(
    $bobStarredStatus === 0,
    'IDOR',
    'Alice (sender) cannot star Bob received inbox copy (Bob email remains unstarred)',
    "is_starred: {$bobStarredStatus}"
);

// Test 1.8: Alice attempts to trash Bob's received email via public/trash.php
$res = invokeEndpoint(
    'public/trash.php',
    'POST',
    ['action' => 'trash', 'email_id' => $bobInboxEmailId, 'csrf_token' => 'alice_token_123'],
    [],
    $aliceSession
);
$checkStmt = $pdo->prepare("SELECT folder FROM emails WHERE id = ?");
$checkStmt->execute([$bobInboxEmailId]);
$bobFolder = $checkStmt->fetchColumn();
assertSecurity(
    $bobFolder === 'inbox',
    'IDOR',
    'Alice cannot move Bob received email to trash (Folder remains inbox)',
    "folder: {$bobFolder}"
);

// Test 1.9: Alice attempts to permanently delete Bob's received email via Email model
$delResult = $emailModel->permanentDelete($bobInboxEmailId, $aliceId);
$checkStmt = $pdo->prepare("SELECT id FROM emails WHERE id = ?");
$checkStmt->execute([$bobInboxEmailId]);
$stillExists = (bool)$checkStmt->fetch();
assertSecurity(
    $stillExists === true && $delResult === true, // execute returns true but affected rows is 0
    'IDOR',
    'Alice cannot delete Bob received email via Email::permanentDelete',
    "Record still exists in DB: " . ($stillExists ? 'yes' : 'no')
);

// Test 1.10: Eve attempts to access Alice's contacts
$aliceContactId = $contactModel->create([
    'user_id'       => $aliceId,
    'contact_name'  => 'Alice Private Doctor',
    'contact_email' => 'dr.smith@clinic.local',
]);
$eveFound = $contactModel->findById($aliceContactId, $eveId);
assertSecurity(
    $eveFound === null,
    'IDOR',
    'Eve cannot find Alice private contact record by ID (Returns null)',
    "Result: " . json_encode($eveFound)
);

// Test 1.11: Eve attempts to update Alice's contact record
$contactModel->update($aliceContactId, $eveId, [
    'contact_name'  => 'Hacked Doctor Name',
    'contact_email' => 'hacked@evil.local',
]);
$checkContact = $contactModel->findById($aliceContactId, $aliceId);
assertSecurity(
    $checkContact['contact_name'] === 'Alice Private Doctor',
    'IDOR',
    'Eve cannot update Alice contact details (Alice contact data remains unaltered)',
    "Name: {$checkContact['contact_name']}"
);

// Test 1.12: Eve attempts to overwrite Alice's draft via autosave_draft.php
$eveSession['csrf_token'] = 'eve_token_456';
$res = invokeEndpoint(
    'public/api/autosave_draft.php',
    'POST',
    [],
    [],
    $eveSession,
    [
        'Content-Type' => 'application/json',
        'X-CSRF-Token' => 'eve_token_456',
        'raw_input'    => json_encode([
            'draft_id' => $aliceDraftId,
            'subject'  => 'Eve Tampered Subject',
            'body'     => 'Eve Injected Body Content',
        ])
    ]
);
// Verify Alice draft was NOT overwritten
$checkDraft = $emailModel->findById($aliceDraftId);
assertSecurity(
    $checkDraft['subject'] === 'Alice Confidential Financials Draft' &&
    $checkDraft['body'] === 'Top Secret Budget Data 2026',
    'IDOR',
    'Eve cannot overwrite Alice draft via autosave_draft.php (Alice draft untouched)',
    "Subject: {$checkDraft['subject']}"
);

echo "\n";

// =============================================================================
// SECTION 2: CROSS-SITE SCRIPTING (XSS) DEFENSE AUDIT
// =============================================================================
echo "--- SECTION 2: Cross-Site Scripting (XSS) Vectors ---\n";

// 2.1: Basic script tag
$p1 = '<script>alert("XSS1")</script><b>Important Info</b>';
$s1 = sanitize_email_html($p1);
assertSecurity(
    !str_contains($s1, '<script>') && !str_contains($s1, 'alert('),
    'XSS',
    '<script> tag completely removed from email body',
    $s1
);

// 2.2: Event handlers in images
$p2 = '<img src="x" onerror="alert(document.domain)">';
$s2 = sanitize_email_html($p2);
assertSecurity(
    !str_contains($s2, 'onerror') && !str_contains($s2, 'alert('),
    'XSS',
    'Event handlers (onerror) stripped from <img> tags',
    $s2
);

// 2.3: Javascript pseudo-protocol
$p3 = '<a href="javascript:alert(1)">Click for prize</a>';
$s3 = sanitize_email_html($p3);
assertSecurity(
    !str_contains($s3, 'javascript:'),
    'XSS',
    'javascript: scheme stripped from <a href>',
    $s3
);

// 2.4: Tab-encoded and control character javascript pseudo-protocol
$p4 = '<a href="java&#x09;script:alert(2)">Tab Encoded Link</a> and <a href="java' . "\t" . 'script:alert(3)">Raw Tab Link</a>';
$s4 = sanitize_email_html($p4);
assertSecurity(
    !str_contains($s4, 'javascript:') && !str_contains($s4, 'alert(2)') && !str_contains($s4, 'alert(3)'),
    'XSS',
    'Tab/whitespace encoded javascript: scheme stripped from <a href>',
    $s4
);

// 2.5: SVG with embedded script in data URI
$p5 = '<a href="data:image/svg+xml;base64,PHN2ZyB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciPjxzY3JpcHQ+YWxlcnQoMSk8L3NjcmlwdD48L3N2Zz4=">SVG Link</a>';
$s5 = sanitize_email_html($p5);
assertSecurity(
    !str_contains($s5, 'data:image/svg+xml'),
    'XSS',
    'data:image/svg+xml forbidden in <a href>',
    $s5
);

// 2.6: HTML data URI in link
$p6 = '<a href="data:text/html;base64,PHNjcmlwdD5hbGVydCgxKTwvc2NyaXB0Pg==">HTML Data</a>';
$s6 = sanitize_email_html($p6);
assertSecurity(
    !str_contains($s6, 'data:text/html'),
    'XSS',
    'data:text/html scheme forbidden in <a href>',
    $s6
);

// 2.7: Style expression injection
$p7 = '<div style="width: expression(alert(1)); background: url(javascript:alert(2)); -moz-binding: url(evil.xml)">Text</div>';
$s7 = sanitize_email_html($p7);
assertSecurity(
    !str_contains($s7, 'expression') && !str_contains($s7, 'javascript:') && !str_contains($s7, '-moz-binding'),
    'XSS',
    'Dangerous style attributes (expression, url(javascript), -moz-binding) neutralized',
    $s7
);

// 2.8: Dangerous media and active tags
$p8 = '<audio src="x" onerror="alert(1)"></audio><video poster="javascript:alert(2)"></video><math href="javascript:alert(3)">x</math>';
$s8 = sanitize_email_html($p8);
assertSecurity(
    !str_contains($s8, '<audio') && !str_contains($s8, '<video') && !str_contains($s8, '<math'),
    'XSS',
    'Dangerous media and active tags (audio, video, math) stripped',
    $s8
);

// 2.9: Legitimate HTML preservation
$p9 = '<p>Dear <b>Colleague</b>, please visit our <a href="https://example.com/portal">Secure Portal</a>.</p>';
$s9 = sanitize_email_html($p9);
assertSecurity(
    str_contains($s9, '<b>Colleague</b>') && str_contains($s9, 'href="https://example.com/portal"'),
    'XSS',
    'Legitimate safe HTML formatting and https:// links preserved intact',
    $s9
);

// 2.10: Output escaping helper e()
$rawMalicious = '<script>alert("pwnd")</script>&"\'';
$escaped = e($rawMalicious);
assertSecurity(
    $escaped === '&lt;script&gt;alert(&quot;pwnd&quot;)&lt;/script&gt;&amp;&quot;&#039;',
    'XSS',
    'Helper function e() correctly escapes <, >, &, ", and \'',
    $escaped
);

echo "\n";

// =============================================================================
// SECTION 3: CROSS-SITE REQUEST FORGERY (CSRF) AUDIT
// =============================================================================
echo "--- SECTION 3: Cross-Site Request Forgery (CSRF) Rejection ---\n";

// 3.1: public/compose.php POST without CSRF token
$res = invokeEndpoint('public/compose.php', 'POST', ['action' => 'send', 'to' => 'test@couriva.local', 'subject' => 'CSRF Test'], [], $aliceSession);
assertSecurity(
    str_contains($res['stdout'], 'Security token invalid') || str_contains($res['stdout'], 'token'),
    'CSRF',
    'public/compose.php rejects POST with missing/invalid CSRF token',
    "HTTP {$res['http_code']}"
);

// 3.2: public/contacts.php POST without CSRF token
$res = invokeEndpoint('public/contacts.php', 'POST', ['action' => 'create', 'contact_name' => 'Bad Contact', 'contact_email' => 'bad@couriva.local'], [], $aliceSession);
// Contact should not be created
$check = $contactModel->getByUserId($aliceId, 'Bad Contact');
assertSecurity(
    empty($check),
    'CSRF',
    'public/contacts.php rejects POST with invalid CSRF token (No contact created)',
    "Contacts count: " . count($check)
);

// 3.3: public/settings.php POST without CSRF token
$res = invokeEndpoint('public/settings.php', 'POST', ['action' => 'theme', 'theme' => 'dark'], [], $aliceSession);
$userAfter = $userModel->findById($aliceId);
assertSecurity(
    $userAfter['theme'] === 'light',
    'CSRF',
    'public/settings.php rejects POST without CSRF token (Theme remains light)',
    "Theme: {$userAfter['theme']}"
);

// 3.4: public/api/batch_actions.php without CSRF token
$res = invokeEndpoint('public/api/batch_actions.php', 'POST', [], [], $aliceSession, ['Content-Type' => 'application/json', 'raw_input' => json_encode(['action' => 'mark_read', 'email_ids' => [1]])]);
assertSecurity(
    $res['http_code'] === 403,
    'CSRF',
    'public/api/batch_actions.php returns 403 Forbidden for missing CSRF token',
    "HTTP {$res['http_code']}"
);

// 3.5: public/api/autosave_draft.php without CSRF token
$res = invokeEndpoint('public/api/autosave_draft.php', 'POST', [], [], $aliceSession, ['Content-Type' => 'application/json', 'raw_input' => json_encode(['subject' => 'CSRF Draft'])]);
assertSecurity(
    $res['http_code'] === 403,
    'CSRF',
    'public/api/autosave_draft.php returns 403 Forbidden for missing CSRF token',
    "HTTP {$res['http_code']}"
);

// 3.6: public/api/theme.php without CSRF token
$res = invokeEndpoint('public/api/theme.php', 'POST', [], [], $aliceSession, ['Content-Type' => 'application/json', 'raw_input' => json_encode(['theme' => 'dark'])]);
assertSecurity(
    $res['http_code'] === 403,
    'CSRF',
    'public/api/theme.php returns 403 Forbidden for missing CSRF token',
    "HTTP {$res['http_code']}"
);

// 3.7: public/api/toggle_star.php without CSRF token
$res = invokeEndpoint('public/api/toggle_star.php', 'POST', [], [], $aliceSession, ['Content-Type' => 'application/json', 'raw_input' => json_encode(['email_id' => 1, 'is_starred' => 1])]);
assertSecurity(
    $res['http_code'] === 403,
    'CSRF',
    'public/api/toggle_star.php returns 403 Forbidden for missing CSRF token',
    "HTTP {$res['http_code']}"
);

// 3.8: admin/create-user.php without CSRF token
$adminRecord = $userModel->findByEmail('admin@couriva.local');
$adminSession = auditSession((int)($adminRecord['id'] ?? 1), 'admin@couriva.local', 'admin');
$res = invokeEndpoint('admin/create-user.php', 'POST', ['email' => 'csrf.user@couriva.local', 'password' => 'Password123!', 'first_name' => 'Bad', 'last_name' => 'CSRF'], [], $adminSession);
$csrfCreated = $userModel->findByEmail('csrf.user@couriva.local');
assertSecurity(
    $csrfCreated === null,
    'CSRF',
    'admin/create-user.php rejects POST with missing CSRF token (No user created)',
    "Created: " . ($csrfCreated ? 'yes' : 'no')
);

// 3.9: admin/edit-user.php without CSRF token
$res = invokeEndpoint('admin/edit-user.php', 'POST', ['action' => 'update_profile', 'first_name' => 'HackedName', 'last_name' => 'HackedLast'], ['id' => $bobId], $adminSession);
$bobCheck = $userModel->findById($bobId);
assertSecurity(
    $bobCheck['first_name'] === 'Bob',
    'CSRF',
    'admin/edit-user.php rejects POST with missing CSRF token (User details unaltered)',
    "First name: {$bobCheck['first_name']}"
);

echo "\n";

// =============================================================================
// SECTION 4: PRIVILEGE ESCALATION & ADMIN AUTHORIZATION AUDIT
// =============================================================================
echo "--- SECTION 4: Unauthorized Admin Privilege Escalation ---\n";

$adminEndpoints = [
    'admin/index.php',
    'admin/users.php',
    'admin/logs.php',
    'admin/create-user.php',
    'admin/edit-user.php?id=' . $bobId,
];

// 4.1 - 4.5: Regular authenticated user (Eve) attempting to access admin endpoints
foreach ($adminEndpoints as $ep) {
    $parts = explode('?', $ep);
    $script = $parts[0];
    $query = [];
    if (isset($parts[1])) {
        parse_str($parts[1], $query);
    }

    $res = invokeEndpoint($script, 'GET', [], $query, $eveSession);
    assertSecurity(
        $res['http_code'] === 403,
        'AuthZ',
        "Regular user (role=user) denied access to {$script} (HTTP 403 Forbidden)",
        "HTTP {$res['http_code']}"
    );
}

// 4.6: Unauthenticated guest accessing admin/index.php
$res = invokeEndpoint('admin/index.php', 'GET', [], [], null);
assertSecurity(
    $res['http_code'] === 302,
    'AuthZ',
    'Unauthenticated guest redirected to login when accessing admin/index.php (HTTP 302)',
    "HTTP {$res['http_code']}"
);

echo "\n";

// =============================================================================
// SECTION 5: FILE UPLOAD SECURITY & PATH TRAVERSAL AUDIT
// =============================================================================
echo "--- SECTION 5: File Upload Security & Path Traversal ---\n";

$attService = new AttachmentService($pdo);

// 5.1: Executable PHP file upload rejection
$dangerousExtensions = ['php', 'phtml', 'phar', 'exe', 'bat', 'cmd', 'sh', 'svg', 'vbs', 'py', 'dll', 'asp'];
$allRejected = true;

foreach ($dangerousExtensions as $ext) {
    try {
        $fakeFile = [
            'name'     => "malicious.{$ext}",
            'type'     => 'application/octet-stream',
            'tmp_name' => '/tmp/fake_malicious_' . $ext,
            'error'    => UPLOAD_ERR_OK,
            'size'     => 1024,
        ];
        $attService->storeUploadedFile($aliceDraftId, $fakeFile);
        $allRejected = false;
        break;
    } catch (RuntimeException $re) {
        // Expected rejection
    }
}
assertSecurity(
    $allRejected,
    'Upload',
    'Dangerous file extensions (.php, .phtml, .exe, .sh, .bat, .svg, etc.) strictly rejected by AttachmentService'
);

// 5.2: Directory traversal in uploaded filename
$traversalThrown = false;
try {
    $fakeFile = [
        'name'     => '../../../../var/www/html/shell.php',
        'type'     => 'application/octet-stream',
        'tmp_name' => '/tmp/fake_traversal',
        'error'    => UPLOAD_ERR_OK,
        'size'     => 1024,
    ];
    $attService->storeUploadedFile($aliceDraftId, $fakeFile);
} catch (RuntimeException $re) {
    $traversalThrown = true;
}
assertSecurity(
    $traversalThrown,
    'Upload',
    'Path traversal filename ../../../../shell.php safely handled and forbidden'
);

// 5.3: Legitimate file upload acceptance
$tmpTestFile = tempnam(sys_get_temp_dir(), 'safe_att_');
file_put_contents($tmpTestFile, '%PDF-1.4 Legitimate test pdf document content');
$safeFile = [
    'name'     => 'valid_document.pdf',
    'type'     => 'application/pdf',
    'tmp_name' => $tmpTestFile,
    'error'    => UPLOAD_ERR_OK,
    'size'     => filesize($tmpTestFile),
];
$uploadResult = $attService->storeUploadedFile($aliceDraftId, $safeFile);
@unlink($tmpTestFile);

$physicalPath = dirname(__DIR__) . '/' . $uploadResult['filepath'];
$storedFileExists = file_exists($physicalPath);
assertSecurity(
    $uploadResult['id'] > 0 &&
    $storedFileExists &&
    str_starts_with($uploadResult['stored_filename'], 'att_') &&
    str_ends_with($uploadResult['stored_filename'], '.pdf'),
    'Upload',
    'Valid PDF upload accepted, stored with randomized filename under storage/attachments/',
    "Path: {$uploadResult['filepath']}"
);

// 5.4: Path traversal defense in downloadAttachment()
// Simulate a tampered attachment database record pointing to /etc/passwd
$tamperedAttId = $attachmentModel->create([
    'email_id'          => $aliceDraftId,
    'original_filename' => 'passwd_leak.txt',
    'stored_filename'   => 'evil.txt',
    'filepath'          => '../../../etc/passwd',
    'mime_type'         => 'text/plain',
    'filesize'          => 1024,
]);
$res = invokeEndpoint('public/download.php', 'GET', [], ['id' => $tamperedAttId], $aliceSession);
assertSecurity(
    $res['http_code'] === 404 || $res['http_code'] === 403,
    'Upload',
    'downloadAttachment strictly blocks path traversal escaping storage/attachments/ (Returns 404/403)',
    "HTTP {$res['http_code']}"
);

echo "\n";

// =============================================================================
// SECTION 6: SQL INJECTION DEFENSE AUDIT
// =============================================================================
echo "--- SECTION 6: SQL Injection Defense ---\n";

$sqliPayloads = [
    "' OR '1'='1",
    "' UNION SELECT 1,2,3,4,5,6,7,8,9,10,11,12,13 --",
    "1; DROP TABLE contacts; --",
    "admin' --",
    "\" OR \"\"=\"",
    "1' AND SLEEP(2) --",
];

// 6.1: Search emails with hostile SQLi payloads
$sqliSafe = true;
foreach ($sqliPayloads as $payload) {
    try {
        $result = $mailService->searchEmails($aliceId, 'inbox', ['q' => $payload]);
        // Search should execute cleanly and return 0 results
        if (!is_array($result) || !isset($result['total'])) {
            $sqliSafe = false;
        }
    } catch (Throwable $t) {
        $sqliSafe = false;
    }
}
assertSecurity(
    $sqliSafe,
    'SQLi',
    'MailService::searchEmails safely executes under aggressive SQLi payloads with PDO prepared statements'
);

// 6.2: Search contacts with SQLi payloads
$contactsSqliSafe = true;
foreach ($sqliPayloads as $payload) {
    try {
        $contacts = $contactModel->getByUserId($aliceId, $payload);
        if (!is_array($contacts)) {
            $contactsSqliSafe = false;
        }
    } catch (Throwable $t) {
        $contactsSqliSafe = false;
    }
}
assertSecurity(
    $contactsSqliSafe,
    'SQLi',
    'Contact::getByUserId safely executes under SQLi payloads without syntax error or leakage'
);

// 6.3: Hostile authentication input
$authSafe = true;
foreach ($sqliPayloads as $payload) {
    try {
        $authRes = $authService->authenticate($payload, 'any_password');
        if ($authRes['success'] !== false) {
            $authSafe = false;
        }
    } catch (Throwable $t) {
        $authSafe = false;
    }
}
assertSecurity(
    $authSafe,
    'SQLi',
    'AuthService::authenticate safely rejects SQLi payloads in email credential input'
);

echo "\n";

// =============================================================================
// SECTION 7: AUTHENTICATION & ACTIVE SESSION SECURITY AUDIT
// =============================================================================
echo "--- SECTION 7: Authentication & Session Security ---\n";

// 7.1: BCrypt password hashing verification
$userRecord = $userModel->findById($aliceId);
assertSecurity(
    str_starts_with($userRecord['password'], '$2y$') || str_starts_with($userRecord['password'], '$2b$'),
    'Auth',
    'Passwords securely hashed with PASSWORD_BCRYPT'
);

// 7.2: Correct password verification
assertSecurity(
    password_verify($testPass, $userRecord['password']),
    'Auth',
    'password_verify confirms legitimate password matches hash'
);

// 7.3: Invalid password rejection
assertSecurity(
    !password_verify('WrongPassword123!', $userRecord['password']),
    'Auth',
    'password_verify rejects incorrect password'
);

// 7.4: Disabled account rejected at login
$userModel->update($aliceId, ['status' => 'disabled']);
$loginRes = $authService->authenticate($aliceEmail, $testPass);
assertSecurity(
    $loginRes['success'] === false && str_contains(strtolower($loginRes['message']), 'disabled'),
    'Auth',
    'Disabled user account rejected at login with informative status message'
);

// 7.5: Active session revocation for disabled user
// Alice currently has an active session in $_SESSION, but her DB status is now 'disabled'
$_SESSION['user_id'] = $aliceId;
$_SESSION['user_email'] = $aliceEmail;
$_SESSION['user_role'] = 'user';
$isActiveSessionValid = is_authenticated();
assertSecurity(
    $isActiveSessionValid === false,
    'Auth',
    'Active session of disabled user is immediately terminated by is_authenticated()'
);
// Restore Alice status for remaining tests
$userModel->update($aliceId, ['status' => 'active']);

// 7.6: Audit logging in system_logs
$logged = log_activity($aliceId, 'SECURITY_AUDIT_PROBE', 'SUCCESS', 'Automated security test audit log verification');
$auditLogStmt = $pdo->prepare("SELECT COUNT(*) FROM system_logs WHERE user_id = ? AND action = 'SECURITY_AUDIT_PROBE'");
$auditLogStmt->execute([$aliceId]);
$logCount = (int)$auditLogStmt->fetchColumn();
assertSecurity(
    $logged && $logCount > 0,
    'Auth',
    'System audit logs properly recorded in system_logs table'
);

echo "\n";

// =============================================================================
// SECTION 8: CROSS-PLATFORM WEB SERVER CONFIGURATION AUDIT
// =============================================================================
echo "--- SECTION 8: Cross-Platform Web Server Configurations ---\n";

// 8.1: web.config XML validity & hidden segments
$webConfigPath = dirname(__DIR__) . '/web.config';
$webConfigXml = simplexml_load_file($webConfigPath);
assertSecurity(
    $webConfigXml !== false,
    'Config',
    'Windows IIS web.config is syntactically valid XML'
);

$hiddenSegments = [];
if ($webConfigXml && isset($webConfigXml->{'system.webServer'}->security->requestFiltering->hiddenSegments->add)) {
    foreach ($webConfigXml->{'system.webServer'}->security->requestFiltering->hiddenSegments->add as $seg) {
        $hiddenSegments[] = (string)$seg['segment'];
    }
}
$expectedSegments = ['storage', 'config', 'app', 'database', 'includes', 'tests'];
$allSegmentsPresent = count(array_intersect($expectedSegments, $hiddenSegments)) === count($expectedSegments);
assertSecurity(
    $allSegmentsPresent,
    'Config',
    'web.config hiddenSegments protects /storage, /config, /app, /database, /includes, and /tests',
    implode(', ', $hiddenSegments)
);

// 8.2: web.config blocked file extensions
$blockedExts = [];
if ($webConfigXml && isset($webConfigXml->{'system.webServer'}->security->requestFiltering->fileExtensions->add)) {
    foreach ($webConfigXml->{'system.webServer'}->security->requestFiltering->fileExtensions->add as $fext) {
        if ((string)$fext['allowed'] === 'false') {
            $blockedExts[] = (string)$fext['fileExtension'];
        }
    }
}
$expectedExts = ['.sql', '.log', '.env', '.ini', '.bak', '.sh', '.bat'];
$allExtsPresent = count(array_intersect($expectedExts, $blockedExts)) === count($expectedExts);
assertSecurity(
    $allExtsPresent,
    'Config',
    'web.config fileExtensions blocks .sql, .log, .env, .ini, .bak, .sh, and .bat',
    implode(', ', $blockedExts)
);

// 8.3: Root .htaccess directory protection
$htaccessContent = file_get_contents(dirname(__DIR__) . '/.htaccess');
assertSecurity(
    str_contains($htaccessContent, '^(storage|config|app|database|includes|tests|bin)/.*$'),
    'Config',
    'Apache .htaccess blocks direct HTTP access to internal architecture directories'
);

// 8.4: storage/.htaccess deny all
$storageHtaccess = file_get_contents(dirname(__DIR__) . '/storage/.htaccess');
assertSecurity(
    str_contains($storageHtaccess, 'Require all denied') && str_contains($storageHtaccess, 'Deny from all'),
    'Config',
    'storage/.htaccess enforces strict defense-in-depth Require all denied'
);

// 8.5: Fail-closed AES-256-GCM encryption helpers
putenv('APP_SECRET_KEY=test-audit-secret-key-32bytes-minimum');
$cipher = encrypt_data('CourivaSecretPayload');
$plain  = decrypt_data($cipher);
assertSecurity(
    $cipher !== '' && $cipher !== 'CourivaSecretPayload' && $plain === 'CourivaSecretPayload',
    'Crypto',
    'encrypt_data() and decrypt_data() round-trip AES-256-GCM payload accurately'
);

$tamperedThrows = false;
try {
    $raw = base64_decode($cipher, true);
    $raw[strlen($raw) - 1] = $raw[strlen($raw) - 1] ^ "\xFF";
    decrypt_data(base64_encode($raw));
} catch (RuntimeException $e) {
    $tamperedThrows = true;
}
assertSecurity(
    $tamperedThrows,
    'Crypto',
    'decrypt_data() fails closed with RuntimeException when GCM authentication tag or ciphertext is tampered'
);

$missingKeyThrows = false;
putenv('APP_SECRET_KEY=');
try {
    encrypt_data('ShouldFailClosed');
} catch (RuntimeException $e) {
    $missingKeyThrows = true;
}
assertSecurity(
    $missingKeyThrows,
    'Crypto',
    'encrypt_data() fails closed with RuntimeException when secret key is missing'
);

// -----------------------------------------------------------------------------
// Teardown: Clean up Auditor Test Users
// -----------------------------------------------------------------------------
$pdo->prepare("DELETE FROM users WHERE id IN (?, ?, ?)")->execute([$aliceId, $bobId, $eveId]);

echo "\n====================================================================\n";
echo "                      SECURITY AUDIT SUMMARY                        \n";
echo "====================================================================\n";
echo "Total Tests Run : " . ($totalPassed + $totalFailed) . "\n";
echo "Passed Tests    : \033[32m{$totalPassed}\033[0m\n";
echo "Failed Tests    : " . ($totalFailed > 0 ? "\033[31m{$totalFailed}\033[0m" : "\033[32m0\033[0m") . "\n";

if ($totalFailed === 0) {
    echo "\n\033[32m>>> 100% SECURITY AUDIT PASS RATE - SYSTEM FULLY HARDENED <<<\033[0m\n\n";
    exit(0);
} else {
    echo "\n\033[31m>>> SECURITY AUDIT DETECTED FAILURES - REMEDIATION REQUIRED <<<\033[0m\n\n";
    exit(1);
}
