<?php
/**
 * Couriva - API Endpoints Smoke Test Suite
 * Tests public/api/batch_actions.php, contacts.php, and autosave_draft.php
 */

define('WEBMAIL_INIT', true);

require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../app/Models/User.php';
require_once __DIR__ . '/../app/Models/Email.php';
require_once __DIR__ . '/../app/Models/Contact.php';
require_once __DIR__ . '/../app/Services/MailService.php';

$pdo = getDbConnection();
$passed = 0;
$failed = 0;

function assertApiTest(bool $condition, string $testName, string $details = '')
{
    global $passed, $failed;
    if ($condition) {
        $passed++;
        echo "[\033[32mPASS\033[0m] {$testName}\n";
    } else {
        $failed++;
        echo "[\033[31mFAIL\033[0m] {$testName}" . ($details ? ": {$details}" : "") . "\n";
    }
}

echo "=== Starting Couriva API Smoke Test Suite ===\n\n";

// Helper function to invoke PHP API endpoint scripts in an isolated process or buffer
function callApi(string $script, string $method, array $headers, $payload, ?array $sessionData = null): array
{
    $url = 'http://127.0.0.1/' . ltrim($script, '/');
    
    // We can simulate execution by setting up superglobals and capturing output buffer
    // Save current globals
    $oldServer = $_SERVER;
    $oldSession = $_SESSION ?? [];
    $oldPost = $_POST;
    $oldGet = $_GET;

    $_SERVER['REQUEST_METHOD'] = strtoupper($method);
    $_SERVER['SCRIPT_FILENAME'] = dirname(__DIR__) . '/' . ltrim($script, '/');

    foreach ($headers as $k => $v) {
        $headerKey = 'HTTP_' . strtoupper(str_replace('-', '_', $k));
        if (strtolower($k) === 'content-type') {
            $_SERVER['CONTENT_TYPE'] = $v;
        } else {
            $_SERVER[$headerKey] = $v;
        }
    }

    if ($sessionData !== null) {
        $_SESSION = $sessionData;
    } else {
        $_SESSION = [];
    }

    if (is_array($payload) && !str_contains($headers['Content-Type'] ?? '', 'application/json')) {
        if ($method === 'GET') {
            $_GET = $payload;
        } else {
            $_POST = $payload;
        }
    }

    // For JSON payload stream simulation
    if (is_string($payload) || (is_array($payload) && str_contains($headers['Content-Type'] ?? '', 'application/json'))) {
        $jsonStr = is_string($payload) ? $payload : json_encode($payload);
        $tmpInput = fopen('php://temp', 'r+');
        fwrite($tmpInput, $jsonStr);
        rewind($tmpInput);
    }

    ob_start();
    // Run via php CLI invocation for complete process separation
    $cmd = sprintf(
        'php -d auto_prepend_file= -r %s',
        escapeshellarg(sprintf(
            '$session = %s; session_start(); $_SESSION = $session; $_SERVER["REQUEST_METHOD"] = "%s"; $_SERVER["CONTENT_TYPE"] = "%s"; %s require "%s";',
            var_export($sessionData ?? [], true),
            $method,
            $headers['Content-Type'] ?? '',
            !empty($headers['X-CSRF-Token']) ? sprintf('$_SERVER["HTTP_X_CSRF_TOKEN"] = "%s";', $headers['X-CSRF-Token']) : '',
            dirname(__DIR__) . '/' . ltrim($script, '/')
        ))
    );

    // If payload, write to stdin
    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];

    if ($method === 'GET' && !empty($payload) && is_array($payload)) {
        $cmd .= ' ' . escapeshellarg(http_build_query($payload));
    }

    $proc = proc_open($cmd, $descriptors, $pipes, dirname(__DIR__));
    if (is_resource($proc)) {
        if (!empty($payload)) {
            $inputPayload = is_string($payload) ? $payload : json_encode($payload);
            fwrite($pipes[0], $inputPayload);
        }
        fclose($pipes[0]);

        $stdout = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[2]);
        $status = proc_close($proc);
    }

    ob_end_clean();

    // Restore globals
    $_SERVER = $oldServer;
    $_SESSION = $oldSession;
    $_POST = $oldPost;
    $_GET = $oldGet;

    $json = json_decode($stdout, true);
    return [
        'raw'    => $stdout,
        'json'   => $json,
        'stderr' => $stderr,
    ];
}

// 1. Setup Test User
$userModel = new \App\Models\User($pdo);
$testEmail = 'api.tester.' . time() . '@couriva.local';
$userId = $userModel->create([
    'email'                => $testEmail,
    'password'             => 'ApiPassword123!',
    'first_name'           => 'API',
    'last_name'            => 'Tester',
    'role'                 => 'user',
    'status'               => 'active',
    'theme'                => 'light',
    'must_change_password' => 0,
]);

$csrfToken = bin2hex(random_bytes(32));
$userSession = [
    'user_id'         => $userId,
    'user_email'      => $testEmail,
    'user_first_name' => 'API',
    'user_last_name'  => 'Tester',
    'user_full_name'  => 'API Tester',
    'user_role'       => 'user',
    'user_timezone'   => 'UTC',
    'csrf_token'      => $csrfToken,
    'session_epoch'   => 1,
    'must_change_password' => 0,
    'auth_time'       => time(),
    'last_activity'   => time(),
];

// Create a test contact
$contactModel = new \App\Models\Contact($pdo);
$contactId = $contactModel->create([
    'user_id'       => $userId,
    'contact_name'  => 'Samantha Smith',
    'contact_email' => 'samantha@example.com',
    'company'       => 'Global Corp',
    'phone'         => '123-456-7890',
]);

// Create 2 test emails for batch testing
$emailModel = new \App\Models\Email($pdo);
$email1 = $emailModel->create([
    'sender_id'    => $userId,
    'recipient_id' => $userId,
    'subject'      => 'Test Mail 1',
    'body'         => 'Body 1',
    'is_read'      => 0,
    'is_starred'   => 0,
    'folder'       => 'inbox',
]);
$email2 = $emailModel->create([
    'sender_id'    => $userId,
    'recipient_id' => $userId,
    'subject'      => 'Test Mail 2',
    'body'         => 'Body 2',
    'is_read'      => 0,
    'is_starred'   => 0,
    'folder'       => 'inbox',
]);

// TEST 1: Unauthenticated request to batch_actions.php -> should return error
$resUnauth = callApi('public/api/batch_actions.php', 'POST', ['Content-Type' => 'application/json'], ['action' => 'mark_read', 'email_ids' => [$email1]], null);
assertApiTest(
    isset($resUnauth['json']['success']) && $resUnauth['json']['success'] === false,
    "API Auth: batch_actions.php rejects unauthenticated request"
);

// TEST 2: Invalid CSRF to batch_actions.php -> should return error
$resCsrfFail = callApi('public/api/batch_actions.php', 'POST', ['Content-Type' => 'application/json', 'X-CSRF-Token' => 'invalid_csrf'], ['action' => 'mark_read', 'email_ids' => [$email1]], $userSession);
assertApiTest(
    isset($resCsrfFail['json']['success']) && $resCsrfFail['json']['success'] === false,
    "API CSRF: batch_actions.php rejects request with invalid CSRF token"
);

// TEST 3: Valid batch_actions.php mark_read
$resBatchRead = callApi('public/api/batch_actions.php', 'POST', [
    'Content-Type' => 'application/json',
    'X-CSRF-Token' => $csrfToken,
], [
    'action'    => 'mark_read',
    'email_ids' => [$email1, $email2],
], $userSession);

assertApiTest(
    !empty($resBatchRead['json']['success']) && $resBatchRead['json']['count'] === 2,
    "API batch_actions: mark_read successfully marks 2 emails as read"
);

// Verify in DB
$c1 = $emailModel->findById($email1);
$c2 = $emailModel->findById($email2);
assertApiTest((int)$c1['is_read'] === 1 && (int)$c2['is_read'] === 1, "DB check: Both emails verified as is_read = 1");

// TEST 4: Valid batch_actions.php star
$resBatchStar = callApi('public/api/batch_actions.php', 'POST', [
    'Content-Type' => 'application/json',
    'X-CSRF-Token' => $csrfToken,
], [
    'action'    => 'star',
    'email_ids' => [$email1, $email2],
], $userSession);

assertApiTest(
    !empty($resBatchStar['json']['success']) && $resBatchStar['json']['count'] === 2,
    "API batch_actions: star successfully stars 2 emails"
);

// TEST 5: API contacts.php
$resContacts = callApi('public/api/contacts.php', 'GET', [], [], $userSession);
assertApiTest(
    is_array($resContacts['json']) && count($resContacts['json']) >= 1,
    "API contacts: returns list of contacts array"
);
assertApiTest(
    $resContacts['json'][0]['email'] === 'samantha@example.com' && $resContacts['json'][0]['name'] === 'Samantha Smith',
    "API contacts: contains Samantha Smith contact record"
);

// TEST 6: API autosave_draft.php (Create draft)
$resAutosaveCreate = callApi('public/api/autosave_draft.php', 'POST', [
    'Content-Type' => 'application/json',
    'X-CSRF-Token' => $csrfToken,
], [
    'draft_id' => null,
    'subject'  => 'Auto-saved Executive Summary',
    'body'     => 'This draft was created automatically via background AJAX.',
    'to'       => 'board@couriva.local',
    'cc'       => 'finance@couriva.local',
    'bcc'      => '',
], $userSession);

assertApiTest(
    !empty($resAutosaveCreate['json']['success']) && !empty($resAutosaveCreate['json']['draft_id']),
    "API autosave_draft: background draft creation returns success and new draft_id"
);

$newDraftId = $resAutosaveCreate['json']['draft_id'];

// TEST 7: API autosave_draft.php (Update existing draft)
$resAutosaveUpdate = callApi('public/api/autosave_draft.php', 'POST', [
    'Content-Type' => 'application/json',
    'X-CSRF-Token' => $csrfToken,
], [
    'draft_id' => $newDraftId,
    'subject'  => 'Auto-saved Executive Summary (Revision 2)',
    'body'     => 'Updated body content via second background auto-save pulse.',
    'to'       => 'board@couriva.local',
    'cc'       => 'finance@couriva.local, audit@couriva.local',
    'bcc'      => '',
], $userSession);

assertApiTest(
    !empty($resAutosaveUpdate['json']['success']) && $resAutosaveUpdate['json']['draft_id'] === $newDraftId,
    "API autosave_draft: background draft update retains draft_id and returns success"
);

$checkDraft = $emailModel->findById($newDraftId);
assertApiTest(
    $checkDraft['subject'] === 'Auto-saved Executive Summary (Revision 2)' && $checkDraft['folder'] === 'draft',
    "DB check: Auto-saved draft record verified in database"
);

// Clean up test data
$pdo->prepare("DELETE FROM emails WHERE sender_id = ? OR recipient_id = ?")->execute([$userId, $userId]);
$pdo->prepare("DELETE FROM contacts WHERE user_id = ?")->execute([$userId]);
$pdo->prepare("DELETE FROM users WHERE id = ?")->execute([$userId]);

echo "\n=== API Test Results: {$passed} passed, {$failed} failed ===\n";
exit($failed === 0 ? 0 : 1);
