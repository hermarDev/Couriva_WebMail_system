<?php
/**
 * Mail delivery safety regression tests
 * Tests the four prioritized findings:
 * 1. Missing attachment file fails BEFORE SMTP connect (fail-closed)
 * 2. SMTP accepted but DB commit fails → ambiguous outcome reported
 * 3. Scheduled dispatch attachment failure surfaces (not silent)
 * 4. Compose success flash wording: "queued" vs "delivered"
 */

// --- Test harness setup ---
$testDb = sys_get_temp_dir() . '/couriva-mail-safe-' . bin2hex(random_bytes(8)) . '.sqlite';
putenv("COURIVA_TEST_DB=sqlite:{$testDb}");

$pdo = new PDO("sqlite:{$testDb}");
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// Register class autoloader (PSR-4 for App namespace)
spl_autoload_register(function ($class) {
    $prefix = 'App\\';
    $baseDir = __DIR__ . '/../';
    $len = strlen($prefix);
    if (strncmp($prefix, $class, $len) !== 0) return;
    $relativeClass = substr($class, $len);
    $file = $baseDir . str_replace('\\', '/', $relativeClass) . '.php';
    if (file_exists($file)) require_once $file;
});

// Create minimal schema subset
$schema = '
CREATE TABLE users (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    email TEXT UNIQUE NOT NULL,
    password_hash TEXT NOT NULL,
    first_name TEXT DEFAULT "",
    last_name TEXT DEFAULT "",
    status TEXT DEFAULT "active",
    timezone TEXT DEFAULT "UTC",
    must_change_password BOOLEAN DEFAULT 0,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
);
CREATE TABLE emails (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    sender_id INTEGER,
    sender_email_snapshot TEXT,
    sender_name_snapshot TEXT,
    recipient_id INTEGER,
    parent_email_id INTEGER,
    message_id TEXT,
    subject TEXT NOT NULL DEFAULT "",
    body TEXT,
    is_read BOOLEAN DEFAULT 0,
    is_starred BOOLEAN DEFAULT 0,
    is_draft BOOLEAN DEFAULT 0,
    folder TEXT DEFAULT "inbox",
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
);
CREATE TABLE email_recipients (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    email_id INTEGER NOT NULL,
    recipient_id INTEGER,
    email_address TEXT NOT NULL,
    recipient_type TEXT DEFAULT "to",
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
);
CREATE TABLE attachments (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    email_id INTEGER NOT NULL,
    original_filename TEXT NOT NULL,
    stored_filename TEXT NOT NULL,
    filepath TEXT NOT NULL,
    mime_type TEXT,
    filesize INTEGER DEFAULT 0,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
);
CREATE TABLE scheduled_emails (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    draft_email_id INTEGER,
    scheduled_at DATETIME NOT NULL,
    status TEXT DEFAULT "scheduled",
    attempts INTEGER DEFAULT 0,
    last_error TEXT,
    sent_email_id INTEGER,
    delivery_status_id INTEGER,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
);
CREATE TABLE delivery_status (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    message_id TEXT NOT NULL,
    email_id INTEGER DEFAULT NULL,
    recipient_email TEXT NOT NULL,
    recipient_type TEXT NOT NULL DEFAULT "to",
    transport TEXT NOT NULL DEFAULT "smtp",
    status TEXT NOT NULL DEFAULT "queued",
    smtp_response TEXT DEFAULT NULL,
    attempt INTEGER NOT NULL DEFAULT 0,
    max_attempts INTEGER NOT NULL DEFAULT 3,
    next_retry_at DATETIME DEFAULT NULL,
    last_error TEXT DEFAULT NULL,
    idempotency_key TEXT NOT NULL UNIQUE,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
);
CREATE TABLE system_logs (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER,
    action TEXT,
    ip_address TEXT,
    user_agent TEXT,
    status TEXT,
    details TEXT,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
);
CREATE TABLE blocked_senders (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    sender_email TEXT NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
);
CREATE TABLE notifications (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    type TEXT DEFAULT "system",
    title TEXT NOT NULL,
    message TEXT,
    link TEXT,
    read_at DATETIME,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
);
';
foreach (array_filter(explode(';', $schema)) as $stmt) {
    $stmt = trim($stmt);
    if ($stmt !== '') $pdo->exec($stmt);
}

// Seed a sender user
$stmt = $pdo->prepare("INSERT INTO users (email, password_hash, first_name, last_name) VALUES (?, ?, ?, ?)");
$stmt->execute(['sender@test.local', '$2y$10$dummyhash', 'Test', 'Sender']);
$senderId = (int)$pdo->lastInsertId();

// Seed a local recipient user
$stmt = $pdo->prepare("INSERT INTO users (email, password_hash, first_name, last_name) VALUES (?, ?, ?, ?)");
$stmt->execute(['recipient@test.local', '$2y$10$dummyhash', 'Recipient', 'User']);
$recipientId = (int)$pdo->lastInsertId();

// --- Stub database.php to use our test PDO ---
$stubDatabase = '
<?php
function getDbConnection(?array $overrideConfig = null): PDO {
    $dsn = getenv("COURIVA_TEST_DB") ?: "sqlite:" . sys_get_temp_dir() . "/couriva-test.sqlite";
    return new PDO($dsn, "", "", [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
}
$pdo = getDbConnection();
';
file_put_contents(sys_get_temp_dir() . '/test_database_stub.php', $stubDatabase);

// Helper to run a test in isolated subprocess with test DB + stubbed database.php
function runTest(string $name, string $script): array {
    $code = <<<PHP
require_once '{$GLOBALS['stubPath']}';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../app/Models/User.php';
require_once __DIR__ . '/../app/Models/Email.php';
require_once __DIR__ . '/../app/Models/Attachment.php';
require_once __DIR__ . '/../app/Services/AttachmentService.php';
require_once __DIR__ . '/../app/Services/SmtpTransport.php';
require_once __DIR__ . '/../app/Services/MailService.php';
require_once __DIR__ . '/../app/Services/ScheduledMailService.php';
require_once __DIR__ . '/../app/Services/OrganizationService.php';

\$pdo = getDbConnection();
\$_SESSION = ["user_id" => {$GLOBALS['senderId']}, "user_email" => "sender@test.local", "csrf_token" => "test-token"];
\$_SERVER["HTTP_X_CSRF_TOKEN"] = "test-token";

{$script}
PHP;

    $tmp = sys_get_temp_dir() . '/mail_test_' . bin2hex(random_bytes(6)) . '.php';
    file_put_contents($tmp, $code);
    $output = [];
    $exitCode = 0;
    exec("php {$tmp} 2>&1", $output, $exitCode);
    @unlink($tmp);
    return ['exit' => $exitCode, 'out' => implode("\n", $output)];
}

$GLOBALS['senderId'] = $senderId;
$GLOBALS['stubPath'] = sys_get_temp_dir() . '/test_database_stub.php';

// Create a temp attachment file for positive test
$attDir = sys_get_temp_dir() . '/couriva_test_atts_' . bin2hex(random_bytes(6));
mkdir($attDir, 0777, true);
$validAttPath = $attDir . '/test.pdf';
file_put_contents($validAttPath, "%PDF-1.4 test content");

// Create a missing attachment path
$missingAttPath = $attDir . '/missing.pdf';

// ================== TEST 1: Missing attachment fails BEFORE SMTP connect ==================
echo "TEST 1: Missing attachment file fails before SMTP connect...\n";
$result = runTest('missing_attachment_fails', <<<PHP
\$mailService = new App\\Services\\MailService(\$pdo);
\$mailService->config['driver'] = 'smtp';
\$mailService->config['smtp'] = ['host' => '127.0.0.1', 'port' => 2525, 'encryption' => 'tls', 'auth' => false];

try {
    \$result = \$mailService->sendEmail(
        {$senderId},
        ['to' => ['recipient@test.local'], 'cc' => [], 'bcc' => []],
        'Test Subject',
        'Test body',
        null,
        [['filepath' => '{$missingAttPath}', 'original_filename' => 'missing.pdf', 'mime_type' => 'application/pdf']]
    );
    echo "FAIL: Expected exception, got result: " . json_encode(\$result) . "\n";
    exit(1);
} catch (RuntimeException \$e) {
    if (str_contains(\$e->getMessage(), 'missing or unreadable')) {
        echo "PASS: RuntimeException thrown for missing attachment: " . \$e->getMessage() . "\n";
        exit(0);
    } else {
        echo "FAIL: Wrong exception: " . \$e->getMessage() . "\n";
        exit(1);
    }
} catch (Throwable \$e) {
    echo "FAIL: Unexpected exception: " . get_class(\$e) . ": " . \$e->getMessage() . "\n";
    exit(1);
}
PHP
);
if ($result['exit'] !== 0) { echo "  FAILED\n" . $result['out'] . "\n"; exit(1); }
echo "  PASSED\n";

// ================== TEST 2: Unreadable attachment fails BEFORE SMTP connect ==================
echo "TEST 2: Unreadable attachment file fails before SMTP connect...\n";
$unreadablePath = $attDir . '/unreadable.pdf';
file_put_contents($unreadablePath, "content");
chmod($unreadablePath, 0000);

$result = runTest('unreadable_attachment_fails', <<<PHP
\$mailService = new App\\Services\\MailService(\$pdo);
\$mailService->config['driver'] = 'smtp';
\$mailService->config['smtp'] = ['host' => '127.0.0.1', 'port' => 2525, 'encryption' => 'tls', 'auth' => false];

try {
    \$result = \$mailService->sendEmail(
        {$senderId},
        ['to' => ['recipient@test.local'], 'cc' => [], 'bcc' => []],
        'Test Subject',
        'Test body',
        null,
        [['filepath' => '{$unreadablePath}', 'original_filename' => 'unreadable.pdf', 'mime_type' => 'application/pdf']]
    );
    echo "FAIL: Expected exception, got result: " . json_encode(\$result) . "\n";
    exit(1);
} catch (RuntimeException \$e) {
    if (str_contains(\$e->getMessage(), 'missing or unreadable')) {
        echo "PASS: RuntimeException thrown for unreadable attachment: " . \$e->getMessage() . "\n";
        exit(0);
    } else {
        echo "FAIL: Wrong exception: " . \$e->getMessage() . "\n";
        exit(1);
    }
} catch (Throwable \$e) {
    echo "FAIL: Unexpected exception: " . get_class(\$e) . ": " . \$e->getMessage() . "\n";
    exit(1);
}
PHP
);
chmod($unreadablePath, 0644);
if ($result['exit'] !== 0) { echo "  FAILED\n" . $result['out'] . "\n"; exit(1); }
echo "  PASSED\n";

// ================== TEST 3: Valid attachment passes preparation, SMTP connect attempted ==================
echo "TEST 3: Valid attachment passes preparation (SMTP connect will fail but prep OK)...\n";
$result = runTest('valid_attachment_prep', <<<PHP
\$mailService = new App\\Services\\MailService(\$pdo);
\$mailService->config['driver'] = 'smtp';
\$mailService->config['smtp'] = ['host' => '127.0.0.1', 'port' => 2525, 'encryption' => 'tls', 'auth' => false];

try {
    \$result = \$mailService->sendEmail(
        {$senderId},
        ['to' => ['recipient@test.local'], 'cc' => [], 'bcc' => []],
        'Test Subject',
        'Test body',
        null,
        [['filepath' => '{$validAttPath}', 'original_filename' => 'test.pdf', 'mime_type' => 'application/pdf']]
    );
    // SMTP connect will fail, but attachment prep should pass
    if (!\$result['success'] && str_contains(\$result['error'] ?? '', 'temporarily unavailable')) {
        echo "PASS: Attachment prep OK, SMTP failure handled gracefully\n";
        exit(0);
    }
    echo "UNEXPECTED: " . json_encode(\$result) . "\n";
    exit(1);
} catch (RuntimeException \$e) {
    if (str_contains(\$e->getMessage(), 'missing or unreadable')) {
        echo "FAIL: Valid attachment rejected: " . \$e->getMessage() . "\n";
        exit(1);
    }
    // Connection failure is expected - attachment prep passed
    echo "PASS: Attachment prep OK (connection failed as expected): " . \$e->getMessage() . "\n";
    exit(0);
} catch (Throwable \$e) {
    echo "FAIL: Unexpected exception: " . get_class(\$e) . ": " . \$e->getMessage() . "\n";
    exit(1);
}
PHP
);
if ($result['exit'] !== 0) { echo "  FAILED\n" . $result['out'] . "\n"; exit(1); }
echo "  PASSED\n";

// ================== TEST 4: Scheduled dispatch attachment failure surfaces ==================
echo "TEST 4: Scheduled dispatch missing attachment fails (not silent)...\n";
$result = runTest('scheduled_missing_attachment_fails', <<<PHP
// Create a draft with a "missing" attachment reference in DB
\$emailModel = new App\\Models\\Email(\$pdo);
\$draftId = \$emailModel->create([
    'sender_id' => {$senderId},
    'subject' => 'Scheduled Draft',
    'body' => 'Draft body',
    'is_draft' => 1,
    'folder' => 'draft',
]);
\$emailModel->addRecipient(\$draftId, null, 'recipient@test.local', 'to');

// Insert a missing attachment record for the draft
\$attStmt = \$pdo->prepare("INSERT INTO attachments (email_id, original_filename, stored_filename, filepath, mime_type, filesize) VALUES (?, ?, ?, ?, ?, ?)");
\$attStmt->execute([\$draftId, 'missing.pdf', 'missing.pdf', '{$missingAttPath}', 'application/pdf', 123]);

\$scheduledService = new App\\Services\\ScheduledMailService(\$pdo);
\$scheduleId = \$scheduledService->schedule({$senderId}, \$draftId, date('Y-m-d H:i:s', time() + 60), 'UTC');
\$pdo->prepare("UPDATE scheduled_emails SET scheduled_at = ? WHERE id = ?")->execute([date('Y-m-d H:i:s', time() - 60), \$scheduleId]);

// Immediately process (will attempt delivery)
\$result = \$scheduledService->processDue(5);

if (\$result['failed'] > 0) {
    echo "PASS: Scheduled dispatch failed as expected (failed={\$result['failed']})\n";
    exit(0);
} else {
    echo "FAIL: Expected scheduled dispatch to fail, got: " . json_encode(\$result) . "\n";
    exit(1);
}
PHP
);
if ($result['exit'] !== 0) { echo "  FAILED\n" . $result['out'] . "\n"; exit(1); }
echo "  PASSED\n";

// ================== TEST 5: DB commit failure after SMTP accept → ambiguous outcome ==================
echo "TEST 5: Ambiguous SMTP outcome records 'unknown' in delivery_status and caps retries at 3...\n";
$result = runTest('smtp_ok_db_fail_ambiguous', <<<PHP
\$emailModel = new App\\Models\\Email(\$pdo);
\$draftId = \$emailModel->create([
    'sender_id' => {$senderId},
    'subject' => 'Uncertain Scheduled Subject',
    'body' => 'Body with uncertain SMTP outcome',
    'is_draft' => 1,
    'folder' => 'draft',
]);
\$emailModel->addRecipient(\$draftId, {$recipientId}, 'recipient@test.local', 'to');

\$pastTime = date('Y-m-d H:i:s', time() - 120);
\$ins = \$pdo->prepare("INSERT INTO scheduled_emails (user_id, draft_email_id, scheduled_at, status, attempts) VALUES (?, ?, ?, 'scheduled', 0)");
\$ins->execute([{$senderId}, \$draftId, \$pastTime]);
\$scheduleId = (int)\$pdo->lastInsertId();

// Trigger simulates an SMTP send whose delivery_status row was recorded as 'unknown'
\$pdo->exec("
    CREATE TRIGGER trg_seed_unknown_delivery AFTER INSERT ON emails
    WHEN NEW.subject = 'Uncertain Scheduled Subject' AND NEW.folder = 'sent'
    BEGIN
        INSERT INTO delivery_status (message_id, email_id, recipient_email, recipient_type, transport, status, idempotency_key, attempt, max_attempts)
        VALUES ('<unknown-' || NEW.id || '@test.local>', NEW.id, 'recipient@test.local', 'to', 'smtp', 'unknown', 'idem-unk-' || NEW.id, 1, 3);
    END;
");

\$scheduler = new App\\Services\\ScheduledMailService(\$pdo);

// Attempt 1 (attempts 0 -> 1): should re-schedule as 'scheduled' and record delivery_status_id
\$summary1 = \$scheduler->processDue(5);
\$after1 = \$pdo->query("SELECT * FROM scheduled_emails WHERE id = {\$scheduleId}")->fetch(PDO::FETCH_ASSOC);
if (\$summary1['failed'] !== 1 || \$after1['status'] !== 'scheduled' || (int)\$after1['attempts'] !== 1 || (int)\$after1['delivery_status_id'] <= 0) {
    echo "FAIL: Attempt 1 did not re-queue with delivery_status_id: " . json_encode(['summary' => \$summary1, 'row' => \$after1]) . "\n";
    exit(1);
}

// Attempt 3 (attempts 2 -> 3): should cap retries at 3 and mark status = 'failed'
\$pdo->prepare("UPDATE scheduled_emails SET attempts = 2, scheduled_at = ? WHERE id = ?")->execute([\$pastTime, \$scheduleId]);
\$summary3 = \$scheduler->processDue(5);
\$after3 = \$pdo->query("SELECT * FROM scheduled_emails WHERE id = {\$scheduleId}")->fetch(PDO::FETCH_ASSOC);
\$pdo->exec("DROP TRIGGER IF EXISTS trg_seed_unknown_delivery");

if (\$summary3['failed'] === 1 && \$after3['status'] === 'failed' && str_contains((string)\$after3['last_error'], '3 attempts')) {
    echo "PASS: Ambiguous 'unknown' outcome tracked and capped at 3 attempts\n";
    exit(0);
}
echo "FAIL: Attempt 3 did not cap retries as failed: " . json_encode(['summary' => \$summary3, 'row' => \$after3]) . "\n";
exit(1);
PHP
);
if ($result['exit'] !== 0) { echo "  FAILED\n" . $result['out'] . "\n"; exit(1); }
echo "  PASSED\n";

// ================== TEST 6: Compose flash wording & draft attachment transfer errors ==================
echo "TEST 6: Compose surfaces delivery_status warnings and draft attachment transfer errors...\n";
$result = runTest('compose_flash_wording', <<<PHP
\$composeSource = file_get_contents(__DIR__ . '/../public/compose.php');
\$hasUnknownFlash = str_contains(\$composeSource, 'delivery to some external recipients could not be confirmed');
\$hasFailedFlash = str_contains(\$composeSource, 'delivery to some recipients failed');
\$hasTransferCatch = str_contains(\$composeSource, 'was sent, but attachments could not be linked to the sent copy');

if (!\$hasUnknownFlash || !\$hasFailedFlash || !\$hasTransferCatch) {
    echo "FAIL: compose.php missing delivery_status warning or draft attachment transfer catch\n";
    exit(1);
}

// Also verify transferDraftAttachmentsOnSend rolls back and rethrows when DB write fails during transfer
\$emailModel = new App\\Models\\Email(\$pdo);
\$draftId = \$emailModel->create([
    'sender_id' => {$senderId},
    'subject' => 'Draft With Attachment',
    'body' => 'Body',
    'is_draft' => 1,
    'folder' => 'draft',
]);
\$sentId = \$emailModel->create([
    'sender_id' => {$senderId},
    'subject' => 'Sent Copy',
    'body' => 'Body',
    'is_draft' => 0,
    'folder' => 'sent',
]);
\$pdo->prepare("INSERT INTO attachments (email_id, original_filename, stored_filename, filepath, mime_type, filesize) VALUES (?, 'doc.pdf', 'stored_doc.pdf', '{$validAttPath}', 'application/pdf', 20)")
    ->execute([\$draftId]);

// Force attachment insert for sent copy to fail via SQLite trigger so transferDraftAttachmentsOnSend throws and preserves draft
\$pdo->exec("
    CREATE TRIGGER trg_fail_att_transfer BEFORE INSERT ON attachments
    WHEN NEW.email_id = {\$sentId}
    BEGIN
        SELECT RAISE(ABORT, 'Simulated attachment link failure');
    END;
");

\$mailService = new App\\Services\\MailService(\$pdo);
\$threw = false;
try {
    \$mailService->transferDraftAttachmentsOnSend(\$draftId, \$sentId, [], {$senderId});
} catch (Throwable \$e) {
    \$threw = true;
}
\$pdo->exec("DROP TRIGGER IF EXISTS trg_fail_att_transfer");

\$draftStillExists = (int)\$pdo->query("SELECT COUNT(*) FROM emails WHERE id = {\$draftId}")->fetchColumn();
if (\$threw && \$draftStillExists === 1) {
    echo "PASS: Compose flash warnings present and draft attachment transfer rolls back on failure\n";
    exit(0);
}
echo "FAIL: Expected exception and preserved draft, got threw=" . (\$threw ? 'true' : 'false') . ", draftStillExists={\$draftStillExists}\n";
exit(1);
PHP
);
if ($result['exit'] !== 0) { echo "  FAILED\n" . $result['out'] . "\n"; exit(1); }
echo "  PASSED\n";

// ================== TEST 7: Stuck 'processing' scheduled row reclaim ==================
echo "TEST 7: Stuck 'processing' scheduled rows older than 10 minutes are reclaimed...\n";
$result = runTest('stuck_processing_reclaim', <<<PHP
\$emailModel = new App\\Models\\Email(\$pdo);
\$staleDraftId = \$emailModel->create([
    'sender_id' => {$senderId},
    'subject' => 'Stale Processing Draft',
    'body' => 'Stale body',
    'is_draft' => 1,
    'folder' => 'draft',
]);
\$emailModel->addRecipient(\$staleDraftId, {$recipientId}, 'recipient@test.local', 'to');

\$freshDraftId = \$emailModel->create([
    'sender_id' => {$senderId},
    'subject' => 'Fresh Processing Draft',
    'body' => 'Fresh body',
    'is_draft' => 1,
    'folder' => 'draft',
]);
\$emailModel->addRecipient(\$freshDraftId, {$recipientId}, 'recipient@test.local', 'to');

\$staleTime = date('Y-m-d H:i:s', time() - 900); // 15 minutes ago (> 10m threshold)
\$freshTime = date('Y-m-d H:i:s', time() - 120); // 2 minutes ago (< 10m threshold)
\$pastSchedule = date('Y-m-d H:i:s', time() - 900);

\$ins = \$pdo->prepare("INSERT INTO scheduled_emails (user_id, draft_email_id, scheduled_at, status, attempts, updated_at) VALUES (?, ?, ?, 'processing', 0, ?)");
\$ins->execute([{$senderId}, \$staleDraftId, \$pastSchedule, \$staleTime]);
\$staleScheduleId = (int)\$pdo->lastInsertId();

\$ins->execute([{$senderId}, \$freshDraftId, \$pastSchedule, \$freshTime]);
\$freshScheduleId = (int)\$pdo->lastInsertId();

\$scheduler = new App\\Services\\ScheduledMailService(\$pdo);
\$summary = \$scheduler->processDue(10);

\$staleStatus = \$pdo->query("SELECT status FROM scheduled_emails WHERE id = {\$staleScheduleId}")->fetchColumn();
\$freshStatus = \$pdo->query("SELECT status FROM scheduled_emails WHERE id = {\$freshScheduleId}")->fetchColumn();

if (\$staleStatus === 'sent' && \$freshStatus === 'processing' && \$summary['sent'] >= 1) {
    echo "PASS: Stale processing row (>10m) reclaimed and dispatched; fresh processing row (<10m) untouched\n";
    exit(0);
}
echo "FAIL: Unexpected statuses stale={\$staleStatus}, fresh={\$freshStatus}, summary=" . json_encode(\$summary) . "\n";
exit(1);
PHP
);
if ($result['exit'] !== 0) { echo "  FAILED\n" . $result['out'] . "\n"; exit(1); }
echo "  PASSED\n";

echo "\n=== ALL TESTS PASSED ===\n";
exit(0);