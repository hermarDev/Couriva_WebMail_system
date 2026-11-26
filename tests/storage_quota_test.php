<?php
/**
 * Couriva - Storage quota & orphan cleanup regression test.
 * Uses a disposable SQLite database and temp attachment directory.
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/app/Models/Attachment.php';
require_once dirname(__DIR__) . '/app/Services/StorageQuotaService.php';
require_once dirname(__DIR__) . '/app/Services/MailService.php';

use App\Services\MailService;
use App\Services\StorageQuotaService;

$root = dirname(__DIR__);
$tmp = sys_get_temp_dir() . '/couriva-storage-quota-' . bin2hex(random_bytes(6));
$storage = $tmp . '/storage';
mkdir($storage, 0700, true);

$db = new PDO('sqlite:' . $tmp . '/test.sqlite');
$db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->exec('PRAGMA foreign_keys = ON');
$db->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, email TEXT)');
$db->exec("INSERT INTO users (id, email) VALUES (1, 'user@example.invalid')");
$db->exec('CREATE TABLE emails (
    id INTEGER PRIMARY KEY, sender_id INTEGER, recipient_id INTEGER,
    folder TEXT, subject TEXT, body TEXT, is_draft INTEGER DEFAULT 0,
    is_read INTEGER DEFAULT 1, is_starred INTEGER DEFAULT 0,
    parent_email_id INTEGER NULL, deleted_at TEXT NULL, created_at TEXT NULL
)');
$db->exec('CREATE TABLE attachments (
    id INTEGER PRIMARY KEY, email_id INTEGER NOT NULL,
    original_filename TEXT, stored_filename TEXT, filepath TEXT,
    mime_type TEXT, filesize INTEGER DEFAULT 0, created_at TEXT NULL,
    FOREIGN KEY(email_id) REFERENCES emails(id) ON DELETE CASCADE
)');

$failed = 0;
$assert = function (bool $condition, string $label) use (&$failed): void {
    echo ($condition ? 'PASS ' : 'FAIL ') . $label . "\n";
    if (!$condition) {
        $failed++;
    }
};

try {
    $quota = new StorageQuotaService($db, 1000, $storage);
    $assert($quota->quotaBytes() === 1000, 'quota override honoured');
    $assert(
        (new StorageQuotaService($db, null, $storage))->quotaBytes() === StorageQuotaService::DEFAULT_QUOTA_BYTES,
        'default quota is 10 GB (' . StorageQuotaService::DEFAULT_QUOTA_BYTES . ' bytes)'
    );

    $db->exec("INSERT INTO emails (id, sender_id, recipient_id, folder, subject, body) VALUES
        (1, 1, NULL, 'sent', 's1', ''),
        (2, NULL, 1, 'inbox', 'r1', ''),
        (3, 2, NULL, 'sent', 'other', '')");
    $db->exec("INSERT INTO attachments (id, email_id, filepath, filesize) VALUES
        (1, 1, 'storage/att_a.bin', 300),
        (2, 2, 'storage/att_a.bin', 300),
        (3, 3, 'storage/att_other.bin', 500)");

    $assert($quota->usage(1) === 600, 'usage counts sender + recipient copies (600)');
    $assert($quota->remaining(1) === 400, 'remaining reflects quota minus usage');

    $ok = true;
    try {
        $quota->assertCanAttach(1, 400);
    } catch (Throwable $e) {
        $ok = false;
    }
    $assert($ok, 'assertCanAttach allows exactly remaining space');

    $blocked = false;
    try {
        $quota->assertCanAttach(1, 401);
    } catch (Throwable $e) {
        $blocked = str_contains($e->getMessage(), 'Storage limit');
    }
    $assert($blocked, 'assertCanAttach blocks over-quota attach with clear message');

    $assert(
        $quota->estimateTransferBytes([1, 1], 2, 100) === 700,
        'estimate dedupes source filepaths and multiplies by copy count'
    );

    // Physical cleanup: one shared file still referenced, one orphan, one traversal attempt.
    file_put_contents($storage . '/keep.bin', 'keep');
    file_put_contents($storage . '/orphan.bin', 'orphan');
    file_put_contents($tmp . '/outside.bin', 'outside');
    $db->exec("INSERT INTO attachments (id, email_id, filepath, filesize) VALUES
        (4, 1, 'storage/keep.bin', 4),
        (5, 2, 'storage/orphan.bin', 5)");

    // filepath stored as absolute path relative to app root is easier to test here.
    $db->exec("UPDATE attachments SET filepath = '" . $storage . "/keep.bin' WHERE id = 4");
    $db->exec("UPDATE attachments SET filepath = '" . $storage . "/orphan.bin' WHERE id = 5");
    $db->exec('DELETE FROM attachments WHERE id = 5');

    $cleaner = new StorageQuotaService($db, 1000, $storage);
    $removed = $cleaner->cleanupOrphanedFiles([
        $storage . '/keep.bin',
        $storage . '/orphan.bin',
        $tmp . '/outside.bin',
    ]);

    $assert($removed === 1, 'cleanup removes exactly one orphan file');
    $assert(is_file($storage . '/keep.bin'), 'referenced file retained');
    $assert(!is_file($storage . '/orphan.bin'), 'orphan file deleted');
    $assert(is_file($tmp . '/outside.bin'), 'file outside storage root not touched');

    // Integration: permanent batch delete removes rows and frees the physical file.
    $db->exec("INSERT INTO emails (id, sender_id, recipient_id, folder, subject, body) VALUES
        (10, 1, NULL, 'sent', 'batch', '')");
    $db->exec("INSERT INTO attachments (id, email_id, filepath, filesize) VALUES
        (10, 10, '" . $storage . "/batch.bin', 12)");
    file_put_contents($storage . '/batch.bin', 'batch-bytes');

    $config = require $root . '/config/mail.php';
    $config['attachments']['storage_path'] = $storage;
    $mail = new MailService($db, $config);
    $deleted = $mail->batchDelete(1, [10], true);
    $assert($deleted === 1, 'batchDelete(permanent) removes the email');
    $assert(
        (int)$db->query('SELECT COUNT(*) FROM attachments WHERE email_id = 10')->fetchColumn() === 0,
        'attachment metadata cascades away'
    );
    $assert(!is_file($storage . '/batch.bin'), 'batchDelete frees physical file when unreferenced');

    $assert(
        $mail->storageQuotaBytes() === StorageQuotaService::DEFAULT_QUOTA_BYTES,
        'MailService exposes shared quota value'
    );
} finally {
    foreach (glob($storage . '/*') ?: [] as $file) {
        @unlink($file);
    }
    @unlink($tmp . '/outside.bin');
    @unlink($tmp . '/test.sqlite');
    @rmdir($storage);
    @rmdir($tmp);
}

echo $failed === 0 ? "ALL STORAGE QUOTA TESTS PASSED\n" : "FAILED: {$failed}\n";
exit($failed === 0 ? 0 : 1);
