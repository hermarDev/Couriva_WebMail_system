<?php
/**
 * Couriva - Mailbox Organization Service
 * Custom folders, labels, and per-message organization on the existing schema.
 */

namespace App\Services;

use PDO;
use InvalidArgumentException;
use Throwable;

class OrganizationService
{
    protected PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public function getLabels(int $userId): array
    {
        $stmt = $this->pdo->prepare("
            SELECT l.*,
                   (SELECT COUNT(*) FROM email_labels el WHERE el.label_id = l.id) AS email_count
            FROM labels l
            WHERE l.user_id = ?
            ORDER BY l.name ASC
        ");
        $stmt->execute([$userId]);
        return $stmt->fetchAll();
    }

    public function getFolders(int $userId): array
    {
        $stmt = $this->pdo->prepare("
            SELECT f.*,
                   (SELECT COUNT(*) FROM emails e
                    WHERE e.custom_folder_id = f.id AND e.deleted_at IS NULL) AS email_count
            FROM folders f
            WHERE f.user_id = ?
            ORDER BY f.sort_order ASC, f.name ASC
        ");
        $stmt->execute([$userId]);
        return $stmt->fetchAll();
    }

    public function findLabel(int $userId, int $labelId): ?array
    {
        $stmt = $this->pdo->prepare("SELECT * FROM labels WHERE id = ? AND user_id = ? LIMIT 1");
        $stmt->execute([$labelId, $userId]);
        return $stmt->fetch() ?: null;
    }

    public function findFolder(int $userId, int $folderId): ?array
    {
        $stmt = $this->pdo->prepare("SELECT * FROM folders WHERE id = ? AND user_id = ? LIMIT 1");
        $stmt->execute([$folderId, $userId]);
        return $stmt->fetch() ?: null;
    }

    public function createLabel(int $userId, string $name, string $color = '#2563eb'): int
    {
        $name = trim($name);
        if ($name === '' || mb_strlen($name) > 64) {
            throw new InvalidArgumentException('Label names must be between 1 and 64 characters.');
        }
        if (!preg_match('/^#[0-9a-fA-F]{6}$/', $color)) {
            $color = '#2563eb';
        }

        $stmt = $this->pdo->prepare("INSERT INTO labels (user_id, name, color, created_at) VALUES (?, ?, ?, NOW())");
        $stmt->execute([$userId, $name, strtolower($color)]);
        return (int)$this->pdo->lastInsertId();
    }

    public function deleteLabel(int $userId, int $labelId): bool
    {
        if (!$this->findLabel($userId, $labelId)) {
            return false;
        }
        $stmt = $this->pdo->prepare("DELETE FROM labels WHERE id = ? AND user_id = ?");
        return $stmt->execute([$labelId, $userId]);
    }

    public function updateLabel(int $userId, int $labelId, string $name, string $color = '#2563eb'): bool
    {
        if (!$this->findLabel($userId, $labelId)) {
            return false;
        }
        $name = trim($name);
        if ($name === '' || mb_strlen($name) > 64) {
            throw new InvalidArgumentException('Label names must be between 1 and 64 characters.');
        }
        if (!preg_match('/^#[0-9a-fA-F]{6}$/', $color)) {
            $color = '#2563eb';
        }
        $stmt = $this->pdo->prepare("UPDATE labels SET name = ?, color = ? WHERE id = ? AND user_id = ?");
        return $stmt->execute([$name, strtolower($color), $labelId, $userId]);
    }

    public function createFolder(int $userId, string $name, string $color = '#64748b'): int
    {
        $name = trim($name);
        if ($name === '' || mb_strlen($name) > 80) {
            throw new InvalidArgumentException('Folder names must be between 1 and 80 characters.');
        }
        if (!preg_match('/^#[0-9a-fA-F]{6}$/', $color)) {
            $color = '#64748b';
        }

        $orderStmt = $this->pdo->prepare("SELECT COALESCE(MAX(sort_order), 0) + 1 FROM folders WHERE user_id = ?");
        $orderStmt->execute([$userId]);
        $sortOrder = (int)$orderStmt->fetchColumn();

        $stmt = $this->pdo->prepare("
            INSERT INTO folders (user_id, name, color, sort_order, created_at)
            VALUES (?, ?, ?, ?, NOW())
        ");
        $stmt->execute([$userId, $name, strtolower($color), $sortOrder]);
        return (int)$this->pdo->lastInsertId();
    }

    public function deleteFolder(int $userId, int $folderId): bool
    {
        if (!$this->findFolder($userId, $folderId)) {
            return false;
        }
        $stmt = $this->pdo->prepare("DELETE FROM folders WHERE id = ? AND user_id = ?");
        return $stmt->execute([$folderId, $userId]);
    }

    public function updateFolder(int $userId, int $folderId, string $name, string $color = '#64748b'): bool
    {
        if (!$this->findFolder($userId, $folderId)) {
            return false;
        }
        $name = trim($name);
        if ($name === '' || mb_strlen($name) > 80) {
            throw new InvalidArgumentException('Folder names must be between 1 and 80 characters.');
        }
        if (!preg_match('/^#[0-9a-fA-F]{6}$/', $color)) {
            $color = '#64748b';
        }
        $stmt = $this->pdo->prepare("UPDATE folders SET name = ?, color = ? WHERE id = ? AND user_id = ?");
        return $stmt->execute([$name, strtolower($color), $folderId, $userId]);
    }

    /**
     * Return label rows for one message, scoped to the message owner.
     */
    public function getLabelsForEmail(int $emailId, int $userId): array
    {
        $stmt = $this->pdo->prepare("
            SELECT l.*
            FROM email_labels el
            JOIN labels l ON l.id = el.label_id
            WHERE el.email_id = ? AND l.user_id = ?
            ORDER BY l.name ASC
        ");
        $stmt->execute([$emailId, $userId]);
        return $stmt->fetchAll();
    }

    /**
     * Return [email_id => [label, label...]] for a list of messages.
     */
    public function getLabelsForEmails(array $emailIds, int $userId): array
    {
        $emailIds = array_values(array_unique(array_filter(array_map('intval', $emailIds))));
        if (empty($emailIds)) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($emailIds), '?'));
        $params = array_merge($emailIds, [$userId]);
        $stmt = $this->pdo->prepare("
            SELECT el.email_id, l.id, l.name, l.color
            FROM email_labels el
            JOIN labels l ON l.id = el.label_id
            WHERE el.email_id IN ({$placeholders}) AND l.user_id = ?
            ORDER BY l.name ASC
        ");
        $stmt->execute($params);

        $map = [];
        foreach ($stmt->fetchAll() as $row) {
            $map[(int)$row['email_id']][] = $row;
        }
        return $map;
    }

    public function assignLabel(int $userId, array $emailIds, int $labelId): int
    {
        if (!$this->findLabel($userId, $labelId)) {
            throw new InvalidArgumentException('Label not found.');
        }

        $ownedIds = $this->filterOwnedEmailIds($userId, $emailIds);
        if (empty($ownedIds)) {
            return 0;
        }

        $stmt = $this->pdo->prepare("
            INSERT IGNORE INTO email_labels (email_id, label_id, created_at)
            VALUES (?, ?, NOW())
        ");
        $count = 0;
        foreach ($ownedIds as $emailId) {
            $stmt->execute([$emailId, $labelId]);
            $count += $stmt->rowCount();
        }
        return $count;
    }

    public function removeLabel(int $userId, array $emailIds, int $labelId): int
    {
        if (!$this->findLabel($userId, $labelId)) {
            throw new InvalidArgumentException('Label not found.');
        }

        $ownedIds = $this->filterOwnedEmailIds($userId, $emailIds);
        if (empty($ownedIds)) {
            return 0;
        }

        $placeholders = implode(',', array_fill(0, count($ownedIds), '?'));
        $params = array_merge([$labelId], $ownedIds);
        $stmt = $this->pdo->prepare("DELETE FROM email_labels WHERE label_id = ? AND email_id IN ({$placeholders})");
        $stmt->execute($params);
        return $stmt->rowCount();
    }

    /**
     * Move messages into a custom folder (null returns them to their system folder).
     */
    public function moveToFolder(int $userId, array $emailIds, ?int $folderId): int
    {
        if ($folderId !== null && !$this->findFolder($userId, $folderId)) {
            throw new InvalidArgumentException('Folder not found.');
        }

        $ownedIds = $this->filterOwnedEmailIds($userId, $emailIds);
        if (empty($ownedIds)) {
            return 0;
        }

        $placeholders = implode(',', array_fill(0, count($ownedIds), '?'));
        $params = array_merge([$folderId], $ownedIds, [$userId, $userId]);
        $stmt = $this->pdo->prepare("
            UPDATE emails
            SET custom_folder_id = ?, is_archived = 0, archived_at = NULL, updated_at = NOW()
            WHERE id IN ({$placeholders})
              AND (recipient_id = ? OR (sender_id = ? AND recipient_id IS NULL))
        ");
        $stmt->execute($params);
        return $stmt->rowCount();
    }

    protected function filterOwnedEmailIds(int $userId, array $emailIds): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $emailIds))));
        if (empty($ids)) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $params = array_merge($ids, [$userId, $userId]);
        $stmt = $this->pdo->prepare("
            SELECT id FROM emails
            WHERE id IN ({$placeholders})
              AND (recipient_id = ? OR (sender_id = ? AND recipient_id IS NULL))
        ");
        $stmt->execute($params);
        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }
}
