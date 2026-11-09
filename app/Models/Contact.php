<?php
/**
 * Couriva - Contact Model
 */

namespace App\Models;

use PDO;

class Contact
{
    protected PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public function findById(int $id, int $userId): ?array
    {
        $stmt = $this->pdo->prepare("SELECT * FROM contacts WHERE id = ? AND user_id = ? LIMIT 1");
        $stmt->execute([$id, $userId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function getByUserId(int $userId, ?string $search = null): array
    {
        $sql = "SELECT * FROM contacts WHERE user_id = ?";
        $params = [$userId];

        if (!empty($search)) {
            $sql .= " AND (contact_name LIKE ? OR contact_email LIKE ? OR company LIKE ?)";
            $term = '%' . $search . '%';
            $params[] = $term;
            $params[] = $term;
            $params[] = $term;
        }

        $sql .= " ORDER BY contact_name ASC";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    public function create(array $data): int
    {
        $stmt = $this->pdo->prepare("
            INSERT INTO contacts (user_id, contact_name, contact_email, phone, company, notes, created_at, updated_at)
            VALUES (?, ?, ?, ?, ?, ?, NOW(), NOW())
        ");
        $stmt->execute([
            $data['user_id'],
            trim($data['contact_name']),
            trim(strtolower($data['contact_email'])),
            $data['phone'] ?? null,
            $data['company'] ?? null,
            $data['notes'] ?? null,
        ]);
        return (int)$this->pdo->lastInsertId();
    }

    public function update(int $id, int $userId, array $data): bool
    {
        $stmt = $this->pdo->prepare("
            UPDATE contacts 
            SET contact_name = ?, contact_email = ?, phone = ?, company = ?, notes = ?, updated_at = NOW()
            WHERE id = ? AND user_id = ?
        ");
        return $stmt->execute([
            trim($data['contact_name']),
            trim(strtolower($data['contact_email'])),
            $data['phone'] ?? null,
            $data['company'] ?? null,
            $data['notes'] ?? null,
            $id,
            $userId,
        ]);
    }

    public function delete(int $id, int $userId): bool
    {
        $stmt = $this->pdo->prepare("DELETE FROM contacts WHERE id = ? AND user_id = ?");
        return $stmt->execute([$id, $userId]);
    }
}
