<?php
/**
 * Couriva - Attachment Model
 */

namespace App\Models;

use PDO;

class Attachment
{
    protected PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public function findById(int $id): ?array
    {
        $stmt = $this->pdo->prepare("SELECT * FROM attachments WHERE id = ? LIMIT 1");
        $stmt->execute([$id]);
        $res = $stmt->fetch();
        return $res ?: null;
    }

    public function getByEmailId(int $emailId): array
    {
        $stmt = $this->pdo->prepare("SELECT * FROM attachments WHERE email_id = ? ORDER BY id ASC");
        $stmt->execute([$emailId]);
        return $stmt->fetchAll();
    }

    public function create(array $data): int
    {
        $stmt = $this->pdo->prepare("
            INSERT INTO attachments (email_id, original_filename, stored_filename, filepath, mime_type, filesize, created_at)
            VALUES (?, ?, ?, ?, ?, ?, NOW())
        ");
        $stmt->execute([
            $data['email_id'],
            $data['original_filename'],
            $data['stored_filename'],
            $data['filepath'],
            $data['mime_type'] ?? null,
            $data['filesize'] ?? 0,
        ]);
        return (int)$this->pdo->lastInsertId();
    }

    public function delete(int $id): bool
    {
        $stmt = $this->pdo->prepare("DELETE FROM attachments WHERE id = ?");
        return $stmt->execute([$id]);
    }
}
