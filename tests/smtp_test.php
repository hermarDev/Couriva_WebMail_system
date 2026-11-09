<?php
/**
 * Couriva - SMTP Transport & MIME Formatting Unit Test
 */

define('WEBMAIL_INIT', true);

require_once __DIR__ . '/../app/Services/SmtpTransport.php';

$passed = 0;
$failed = 0;

function assertSmtpTest(bool $condition, string $testName, string $details = '')
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

echo "=== Starting SMTP Transport Unit Tests ===\n\n";

$transport = new \App\Services\SmtpTransport([
    'host'       => '127.0.0.1',
    'port'       => 587,
    'encryption' => 'tls',
    'auth'       => true,
    'username'   => 'mailer@couriva.local',
    'password'   => 'SecretPassword',
    'timeout'    => 5,
]);

// Test reflection access to protected buildMimeMessage
$ref = new ReflectionClass($transport);
$buildMime = $ref->getMethod('buildMimeMessage');
$buildMime->setAccessible(true);

// 1. Plain text email MIME building
$mimePlain = $buildMime->invoke(
    $transport,
    'alice@couriva.local',
    'Alice Smith',
    ['bob@couriva.local'],
    ['carol@couriva.local'],
    'Test Subject Line',
    'This is the plain text body.'
);

assertSmtpTest(str_contains($mimePlain, 'From: =?UTF-8?B?'), "MIME: UTF-8 Base64 encoded From header");
assertSmtpTest(str_contains($mimePlain, 'To: bob@couriva.local'), "MIME: To header formatted");
assertSmtpTest(str_contains($mimePlain, 'Cc: carol@couriva.local'), "MIME: Cc header formatted");
assertSmtpTest(str_contains($mimePlain, 'Content-Type: text/plain'), "MIME: Content-Type text/plain for single part");
assertSmtpTest(str_contains($mimePlain, 'This is the plain text body.'), "MIME: Body content matches");

// 2. Multipart email with attachment
$tmpFile = tempnam(sys_get_temp_dir(), 'smtp_att');
file_put_contents($tmpFile, 'Sample attachment binary/text content.');

$mimeMulti = $buildMime->invoke(
    $transport,
    'alice@couriva.local',
    'Alice Smith',
    ['bob@couriva.local'],
    [],
    'Invoice Attached',
    'Please find attached invoice.',
    [
        ['path' => $tmpFile, 'name' => 'invoice.txt', 'mime' => 'text/plain']
    ]
);

assertSmtpTest(str_contains($mimeMulti, 'Content-Type: multipart/mixed; boundary='), "MIME: Multipart boundary declared");
assertSmtpTest(str_contains($mimeMulti, 'filename="invoice.txt"'), "MIME: Attachment filename header formatted");
assertSmtpTest(str_contains($mimeMulti, chunk_split(base64_encode('Sample attachment binary/text content.'))), "MIME: Base64 attachment payload encoded");

unlink($tmpFile);

// 3. Rejection of empty recipients
try {
    $transport->send('alice@couriva.local', 'Alice', ['to' => [], 'cc' => [], 'bcc' => []], 'Subject', 'Body');
    assertSmtpTest(false, "SmtpTransport: Throws exception on empty recipients list");
} catch (\InvalidArgumentException $e) {
    assertSmtpTest(true, "SmtpTransport: Correctly threw InvalidArgumentException for empty recipients");
}

echo "\n=== SMTP Test Results: {$passed} passed, {$failed} failed ===\n";
exit($failed === 0 ? 0 : 1);
