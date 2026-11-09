<?php
/**
 * Couriva - User Model
 */

namespace App\Models;

use PDO;

class User
{
    protected PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public function findById(int $id): ?array
    {
        $stmt = $this->pdo->prepare("SELECT * FROM users WHERE id = ? LIMIT 1");
        $stmt->execute([$id]);
        $user = $stmt->fetch();
        return $user ?: null;
    }

    public function findByEmail(string $email): ?array
    {
        $stmt = $this->pdo->prepare("SELECT * FROM users WHERE email = ? LIMIT 1");
        $stmt->execute([trim(strtolower($email))]);
        $user = $stmt->fetch();
        return $user ?: null;
    }

    public function create(array $data): int
    {
        $stmt = $this->pdo->prepare("
            INSERT INTO users (email, password, first_name, last_name, role, status, signature, timezone, theme, must_change_password, session_epoch, created_at, updated_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, NOW(), NOW())
        ");
        $stmt->execute([
            trim(strtolower($data['email'])),
            password_hash($data['password'], PASSWORD_BCRYPT),
            trim($data['first_name']),
            trim($data['last_name']),
            $data['role'] ?? 'user',
            $data['status'] ?? 'active',
            $data['signature'] ?? null,
            $data['timezone'] ?? 'UTC',
            $data['theme'] ?? 'light',
            ($data['must_change_password'] ?? true) ? 1 : 0,
        ]);
        return (int)$this->pdo->lastInsertId();
    }

    public function update(int $id, array $data): bool
    {
        $fields = [];
        $params = [];
        $revokeSessions = false;

        if (isset($data['role']) || isset($data['status'])) {
            $currentStmt = $this->pdo->prepare("SELECT role, status FROM users WHERE id = ? LIMIT 1");
            $currentStmt->execute([$id]);
            $current = $currentStmt->fetch();
            if ($current) {
                if (isset($data['role']) && $current['role'] !== $data['role']) {
                    $revokeSessions = true;
                }
                if (isset($data['status']) && $current['status'] !== $data['status']) {
                    $revokeSessions = true;
                }
            }
        }

        if (isset($data['first_name'])) {
            $fields[] = "first_name = ?";
            $params[] = trim($data['first_name']);
        }
        if (isset($data['last_name'])) {
            $fields[] = "last_name = ?";
            $params[] = trim($data['last_name']);
        }
        if (isset($data['role'])) {
            $fields[] = "role = ?";
            $params[] = $data['role'];
        }
        if (isset($data['status'])) {
            $fields[] = "status = ?";
            $params[] = $data['status'];
        }
        if (isset($data['signature'])) {
            $fields[] = "signature = ?";
            $params[] = $data['signature'];
        }
        if (isset($data['timezone'])) {
            $fields[] = "timezone = ?";
            $params[] = $data['timezone'];
        }
        if (isset($data['theme'])) {
            $fields[] = "theme = ?";
            $params[] = in_array($data['theme'], ['light', 'dark'], true) ? $data['theme'] : 'light';
        }
        if (isset($data['avatar'])) {
            $fields[] = "avatar = ?";
            $params[] = $data['avatar'];
        }

        if (empty($fields)) {
            return false;
        }

        if ($revokeSessions) {
            $fields[] = "session_epoch = session_epoch + 1";
        }
        $fields[] = "updated_at = NOW()";
        $params[] = $id;

        $sql = "UPDATE users SET " . implode(', ', $fields) . " WHERE id = ?";
        $stmt = $this->pdo->prepare($sql);
        return $stmt->execute($params);
    }

    public function updatePassword(int $id, string $newPassword, bool $mustChange = false): bool
    {
        $hashed = password_hash($newPassword, PASSWORD_BCRYPT);
        $stmt = $this->pdo->prepare("
            UPDATE users
            SET password = ?, must_change_password = ?, session_epoch = session_epoch + 1, updated_at = NOW()
            WHERE id = ?
        ");
        return $stmt->execute([$hashed, $mustChange ? 1 : 0, $id]);
    }

    public function delete(int $id): bool
    {
        $stmt = $this->pdo->prepare("DELETE FROM users WHERE id = ?");
        return $stmt->execute([$id]);
    }

    public function getAll(int $limit = 50, int $offset = 0, ?string $status = null): array
    {
        $sql = "SELECT id, email, first_name, last_name, role, status, timezone, theme, created_at, updated_at FROM users";
        $params = [];

        if ($status !== null) {
            $sql .= " WHERE status = ?";
            $params[] = $status;
        }

        $sql .= " ORDER BY id DESC LIMIT {$limit} OFFSET {$offset}";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    public function countAll(?string $status = null): int
    {
        $sql = "SELECT COUNT(*) FROM users";
        $params = [];

        if ($status !== null) {
            $sql .= " WHERE status = ?";
            $params[] = $status;
        }

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return (int)$stmt->fetchColumn();
    }
}
