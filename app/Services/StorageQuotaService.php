<?php
/**
 * Couriva - Per-user attachment storage quota and physical file cleanup.
 *
 * The quota is enforced on every path that can create attachment rows:
 * compose send, draft autosave/save, scheduled delivery and forwards.
 * Physical files are shared between the sender copy and recipient copies,
 * so cleanup is reference-counted: a file is removed only when no
 * attachments row references its path any more.
 */

namespace App\Services;

use PDO;
use RuntimeException;

class StorageQuotaService
{
    public const DEFAULT_QUOTA_BYTES = 10737418240; // 10 GB

    protected PDO $pdo;
    protected int $quotaBytes;
    protected string $storageRoot;

    public function __construct(PDO $pdo, ?int $quotaBytes = null, ?string $storagePath = null)
    {
        $this->pdo = $pdo;

        $configured = $quotaBytes;
        if ($configured === null) {
            $env = getenv('MAIL_STORAGE_QUOTA_BYTES');
            $configured = ($env !== false && ctype_digit((string)$env) && (int)$env > 0)
                ? (int)$env
                : self::DEFAULT_QUOTA_BYTES;
        }
        $this->quotaBytes = max(1, (int)$configured);

        if ($storagePath === null) {
            $config = require dirname(__DIR__, 2) . '/config/mail.php';
            $storagePath = (string)($config['attachments']['storage_path'] ?? dirname(__DIR__, 2) . '/storage/attachments');
        }
        $resolved = realpath($storagePath);
        $this->storageRoot = $resolved === false
            ? ''
            : rtrim(str_replace('\\', '/', $resolved), '/');
    }

    public function quotaBytes(): int
    {
        return $this->quotaBytes;
    }

    /**
     * Logical bytes counted toward a user's quota. Kept identical to the
     * sidebar storage meter so the meter and the gate never disagree.
     */
    public function usage(int $userId): int
    {
        $stmt = $this->pdo->prepare("
            SELECT COALESCE(SUM(att.filesize), 0)
            FROM attachments att
            JOIN emails e ON att.email_id = e.id
            WHERE (e.sender_id = ? OR e.recipient_id = ?)
        ");
        $stmt->execute([$userId, $userId]);
        return (int)$stmt->fetchColumn();
    }

    public function remaining(int $userId): int
    {
        return max(0, $this->quotaBytes - $this->usage($userId));
    }

    /**
     * Estimate logical growth for attachments copied from existing messages
     * (forwarded mail or a sent draft) plus freshly uploaded bytes.
     */
    public function estimateTransferBytes(array $emailIds, int $copyCount, int $newUploadBytes = 0): int
    {
        $ids = array_values(array_unique(array_filter(
            array_map('intval', $emailIds),
            static fn (int $id): bool => $id > 0
        )));
        $newUploadBytes = max(0, (int)$newUploadBytes);
        if (empty($ids) || $copyCount <= 0) {
            return $newUploadBytes;
        }

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $this->pdo->prepare("
            SELECT DISTINCT filepath, filesize FROM attachments WHERE email_id IN ({$placeholders})
        ");
        $stmt->execute($ids);
        $sum = 0;
        foreach ($stmt->fetchAll() as $row) {
            $sum += (int)$row['filesize'];
        }
        return $newUploadBytes + ($sum * $copyCount);
    }

    /**
     * Block an attach operation that would take the user over quota.
     *
     * @throws RuntimeException when the quota would be exceeded
     */
    public function assertCanAttach(int $userId, int $additionalBytes): void
    {
        if ($additionalBytes <= 0) {
            return;
        }
        if ($this->usage($userId) + $additionalBytes <= $this->quotaBytes) {
            return;
        }
        $size = round($this->quotaBytes / 1073741824, 1);
        throw new RuntimeException(
            'Storage limit reached (' . $size . ' GB). Delete messages with attachments to free space.'
        );
    }

    /**
     * Remove physical attachment files no longer referenced by any row.
     * Files are shared across sender/recipient copies, so never unlink a
     * file that is still referenced elsewhere.
     *
     * @param array $filepaths values from attachments.filepath
     * @return int number of physical files removed
     */
    public function cleanupOrphanedFiles(array $filepaths): int
    {
        if ($this->storageRoot === '') {
            return 0;
        }

        $removed = 0;
        foreach (array_values(array_unique(array_filter($filepaths, 'is_string'))) as $relative) {
            if ($relative === '') {
                continue;
            }

            $check = $this->pdo->prepare("SELECT COUNT(*) FROM attachments WHERE filepath = ?");
            $check->execute([$relative]);
            if ((int)$check->fetchColumn() > 0) {
                continue;
            }

            $normalizedRelative = str_replace('\\', '/', $relative);
            if (str_starts_with($normalizedRelative, $this->storageRoot . '/')) {
                $candidate = realpath($relative);
            } else {
                $candidate = realpath(dirname(__DIR__, 2) . DIRECTORY_SEPARATOR
                    . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $relative));
            }
            if ($candidate === false || !is_file($candidate)) {
                continue;
            }
            $normalized = rtrim(str_replace('\\', '/', $candidate), '/');
            if (!str_starts_with($normalized, $this->storageRoot . '/')) {
                continue;
            }
            if (@unlink($candidate)) {
                $removed++;
            }
        }
        return $removed;
    }
}
