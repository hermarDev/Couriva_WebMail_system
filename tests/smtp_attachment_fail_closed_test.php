<?php
/**
 * Couriva - SMTP attachment fail-closed regression test
 */

require_once __DIR__ . '/../app/Services/SmtpTransport.php';

$passed = 0;
$failed = 0;

function assertSmtpAttachmentTest(bool $condition, string $testName, string $details = ''): void
{
    global $passed, $failed;
    if ($condition) {
        $passed++;
        echo "[PASS] {$testName}\n";
    } else {
        $failed++;
        echo "[FAIL] {$testName}" . ($details ? ": {$details}" : '') . "\n";
    }
}

class SmtpAttachmentProbeTransport extends \App\Services\SmtpTransport
{
    public bool $connectCalled = false;

    protected function connect(): void
    {
        $this->connectCalled = true;
        throw new RuntimeException('SMTP connection was attempted.');
    }
}

function attachmentFailure(\App\Services\SmtpTransport $transport, array $attachments): ?Throwable
{
    try {
        $transport->send(
            'sender@example.test',
            'Sender',
            ['to' => ['recipient@example.test'], 'cc' => [], 'bcc' => []],
            'Subject',
            'Body',
            $attachments
        );
    } catch (Throwable $error) {
        return $error;
    }

    return null;
}

echo "=== SMTP Attachment Fail-Closed Tests ===\n\n";

$transport = new SmtpAttachmentProbeTransport(['encryption' => null]);

// Missing path.
$error = attachmentFailure($transport, [['path' => '', 'name' => 'missing.txt']]);
assertSmtpAttachmentTest(
    $error instanceof RuntimeException && str_contains($error->getMessage(), 'attachment'),
    'Missing attachment path is rejected',
    $error ? $error->getMessage() : 'no exception'
);
assertSmtpAttachmentTest(!$transport->connectCalled, 'Missing attachment fails before SMTP connection');

// Nonexistent file.
$error = attachmentFailure($transport, [['path' => '/tmp/couriva-does-not-exist-' . bin2hex(random_bytes(4)), 'name' => 'gone.txt']]);
assertSmtpAttachmentTest(
    $error instanceof RuntimeException && str_contains($error->getMessage(), 'attachment'),
    'Nonexistent attachment file is rejected',
    $error ? $error->getMessage() : 'no exception'
);
assertSmtpAttachmentTest(!$transport->connectCalled, 'Nonexistent attachment fails before SMTP connection');

// Unreadable file.
$unreadable = tempnam(sys_get_temp_dir(), 'smtp_unreadable');
file_put_contents($unreadable, 'secret');
chmod($unreadable, 0000);
$error = attachmentFailure($transport, [['path' => $unreadable, 'name' => 'unreadable.txt']]);
chmod($unreadable, 0600);
unlink($unreadable);
assertSmtpAttachmentTest(
    $error instanceof RuntimeException && str_contains($error->getMessage(), 'attachment'),
    'Unreadable attachment file is rejected',
    $error ? $error->getMessage() : 'no exception'
);
assertSmtpAttachmentTest(!$transport->connectCalled, 'Unreadable attachment fails before SMTP connection');

// A readable attachment still builds normally.
$readable = tempnam(sys_get_temp_dir(), 'smtp_readable');
file_put_contents($readable, 'visible');
$reflection = new ReflectionClass(\App\Services\SmtpTransport::class);
$buildMime = $reflection->getMethod('buildMimeMessage');
$buildMime->setAccessible(true);
$mime = $buildMime->invoke(
    new \App\Services\SmtpTransport(['encryption' => null]),
    'sender@example.test',
    'Sender',
    ['recipient@example.test'],
    [],
    'Subject',
    'Body',
    [['path' => $readable, 'name' => 'visible.txt', 'mime' => 'text/plain']]
);
unlink($readable);
assertSmtpAttachmentTest(
    str_contains($mime, 'filename="visible.txt"') && str_contains($mime, chunk_split(base64_encode('visible'))),
    'Readable attachment is still encoded'
);

echo "\n=== Results: {$passed} passed, {$failed} failed ===\n";
exit($failed === 0 ? 0 : 1);
