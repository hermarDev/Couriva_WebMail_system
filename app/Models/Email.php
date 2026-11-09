<?php
/**
 * Couriva - Email Model
 */

namespace App\Models;

use PDO;

class Email
{
    protected PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public function findById(int $id): ?array
    {
        $stmt = $this->pdo->prepare("
            SELECT e.*, 
                   COALESCE(s.first_name, SUBSTRING_INDEX(e.sender_name_snapshot, ' ', 1)) AS sender_first_name,
                   COALESCE(s.last_name, SUBSTRING_INDEX(e.sender_name_snapshot, ' ', -1)) AS sender_last_name,
                   COALESCE(s.email, e.sender_email_snapshot) AS sender_email,
                   r.first_name AS recipient_first_name, r.last_name AS recipient_last_name, r.email AS recipient_email
            FROM emails e
            LEFT JOIN users s ON e.sender_id = s.id
            LEFT JOIN users r ON e.recipient_id = r.id
            WHERE e.id = ? LIMIT 1
        ");
        $stmt->execute([$id]);
        $email = $stmt->fetch();
        return $email ?: null;
    }

    /**
     * Find an email only when it is visible to the given user.
     *
     * Visible means:
     *  - the user is the direct recipient, or
     *  - the user is the sender of a sent/draft message (recipient_id IS NULL).
     *
     * Foreign IDs return null without exposing any row data.
     */
    public function findVisibleById(int $id, int $userId): ?array
    {
        $stmt = $this->pdo->prepare("
            SELECT e.*,
                   COALESCE(s.first_name, SUBSTRING_INDEX(e.sender_name_snapshot, ' ', 1)) AS sender_first_name,
                   COALESCE(s.last_name, SUBSTRING_INDEX(e.sender_name_snapshot, ' ', -1)) AS sender_last_name,
                   COALESCE(s.email, e.sender_email_snapshot) AS sender_email,
                   r.first_name AS recipient_first_name,
                   r.last_name AS recipient_last_name,
                   r.email AS recipient_email
            FROM emails e
            LEFT JOIN users s ON e.sender_id = s.id
            LEFT JOIN users r ON e.recipient_id = r.id
            WHERE e.id = ?
              AND (e.recipient_id = ? OR (e.sender_id = ? AND e.recipient_id IS NULL))
            LIMIT 1
        ");
        $stmt->execute([$id, $userId, $userId]);
        $email = $stmt->fetch();
        return $email ?: null;
    }

    public function create(array $data): int
    {
        $senderEmailSnapshot = $data['sender_email_snapshot'] ?? null;
        $senderNameSnapshot = $data['sender_name_snapshot'] ?? null;

        if ((!$senderEmailSnapshot || !$senderNameSnapshot) && !empty($data['sender_id'])) {
            $senderStmt = $this->pdo->prepare("SELECT email, first_name, last_name FROM users WHERE id = ? LIMIT 1");
            $senderStmt->execute([(int)$data['sender_id']]);
            $sender = $senderStmt->fetch();
            if ($sender) {
                $senderEmailSnapshot = $senderEmailSnapshot ?: $sender['email'];
                $senderNameSnapshot = $senderNameSnapshot ?: trim($sender['first_name'] . ' ' . $sender['last_name']);
            }
        }

        $stmt = $this->pdo->prepare("
            INSERT INTO emails (sender_id, sender_email_snapshot, sender_name_snapshot, recipient_id, parent_email_id, subject, body, is_read, is_starred, is_draft, folder, created_at, updated_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
        ");
        $stmt->execute([
            $data['sender_id'] ?? null,
            $senderEmailSnapshot ?: null,
            $senderNameSnapshot ?: null,
            $data['recipient_id'] ?? null,
            $data['parent_email_id'] ?? null,
            $data['subject'] ?? '',
            $data['body'] ?? '',
            !empty($data['is_read']) ? 1 : 0,
            !empty($data['is_starred']) ? 1 : 0,
            !empty($data['is_draft']) ? 1 : 0,
            $data['folder'] ?? 'inbox',
        ]);
        return (int)$this->pdo->lastInsertId();
    }

    public function addRecipient(int $emailId, ?int $recipientId, string $emailAddress, string $type = 'to'): bool
    {
        $stmt = $this->pdo->prepare("
            INSERT INTO email_recipients (email_id, recipient_id, email_address, recipient_type, created_at)
            VALUES (?, ?, ?, ?, NOW())
        ");
        return $stmt->execute([$emailId, $recipientId, trim(strtolower($emailAddress)), $type]);
    }

    public function clearRecipients(int $emailId): bool
    {
        $stmt = $this->pdo->prepare("DELETE FROM email_recipients WHERE email_id = ?");
        return $stmt->execute([$emailId]);
    }

    public function update(int $id, array $data): bool
    {
        $fields = [];
        $params = [];

        $allowed = ['subject', 'body', 'folder', 'is_read', 'is_starred', 'is_draft', 'parent_email_id', 'recipient_id', 'deleted_at'];
        foreach ($allowed as $col) {
            if (array_key_exists($col, $data)) {
                $fields[] = "`{$col}` = ?";
                $params[] = $data[$col];
            }
        }

        if (empty($fields)) {
            return false;
        }

        $fields[] = "`updated_at` = NOW()";
        $params[] = $id;

        $sql = "UPDATE emails SET " . implode(', ', $fields) . " WHERE id = ?";
        $stmt = $this->pdo->prepare($sql);
        return $stmt->execute($params);
    }

    public function getRecipients(int $emailId): array
    {
        $stmt = $this->pdo->prepare("
            SELECT er.*, u.first_name, u.last_name
            FROM email_recipients er
            LEFT JOIN users u ON er.recipient_id = u.id
            WHERE er.email_id = ?
        ");
        $stmt->execute([$emailId]);
        return $stmt->fetchAll();
    }

    public function markRead(int $id, int $userId, bool $isRead = true): bool
    {
        $stmt = $this->pdo->prepare("
            UPDATE emails 
            SET is_read = ?, updated_at = NOW() 
            WHERE id = ? AND recipient_id = ?
        ");
        return $stmt->execute([$isRead ? 1 : 0, $id, $userId]);
    }

    public function toggleStar(int $id, int $userId, ?bool $isStarred = null): bool
    {
        if ($isStarred === null) {
            $stmt = $this->pdo->prepare("
                UPDATE emails 
                SET is_starred = CASE WHEN is_starred = 1 THEN 0 ELSE 1 END, updated_at = NOW()
                WHERE id = ? AND (recipient_id = ? OR (sender_id = ? AND recipient_id IS NULL))
            ");
            return $stmt->execute([$id, $userId, $userId]);
        } else {
            $stmt = $this->pdo->prepare("
                UPDATE emails 
                SET is_starred = ?, updated_at = NOW()
                WHERE id = ? AND (recipient_id = ? OR (sender_id = ? AND recipient_id IS NULL))
            ");
            return $stmt->execute([$isStarred ? 1 : 0, $id, $userId, $userId]);
        }
    }

    public function moveToFolder(int $id, int $userId, string $folder): bool
    {
        $validFolders = ['inbox', 'sent', 'draft', 'trash', 'spam'];
        if (!in_array($folder, $validFolders, true)) {
            return false;
        }

        $stmt = $this->pdo->prepare("
            UPDATE emails 
            SET folder = ?, updated_at = NOW() 
            WHERE id = ? AND (recipient_id = ? OR (sender_id = ? AND recipient_id IS NULL))
        ");
        return $stmt->execute([$folder, $id, $userId, $userId]);
    }

    public function softDelete(int $id, int $userId): bool
    {
        $stmt = $this->pdo->prepare("
            UPDATE emails 
            SET folder = 'trash', deleted_at = NOW(), updated_at = NOW()
            WHERE id = ? AND (recipient_id = ? OR (sender_id = ? AND recipient_id IS NULL))
        ");
        return $stmt->execute([$id, $userId, $userId]);
    }

    public function permanentDelete(int $id, int $userId): bool
    {
        $stmt = $this->pdo->prepare("
            DELETE FROM emails 
            WHERE id = ? AND (recipient_id = ? OR (sender_id = ? AND recipient_id IS NULL))
        ");
        return $stmt->execute([$id, $userId, $userId]);
    }

    public function restore(int $id, int $userId): bool
    {
        $stmt = $this->pdo->prepare("
            UPDATE emails 
            SET folder = 'inbox', deleted_at = NULL, updated_at = NOW()
            WHERE id = ? AND (recipient_id = ? OR (sender_id = ? AND recipient_id IS NULL))
        ");
        return $stmt->execute([$id, $userId, $userId]);
    }
}
