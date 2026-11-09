<?php
/**
 * Couriva - Account Approval Service
 * Administrators review self-registered PENDING accounts here. Keeping the
 * decision logic in one service makes the workflow testable and prevents the
 * web controller from drifting from the rules.
 */

namespace App\Services;

use PDO;
use InvalidArgumentException;
use RuntimeException;

class AccountService
{
    protected PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public function listPending(): array
    {
        return $this->pdo->query("
            SELECT * FROM users
            WHERE status = 'pending'
            ORDER BY COALESCE(requested_at, created_at) ASC
        ")->fetchAll();
    }

    public function listReviewed(int $limit = 25): array
    {
        $limit = max(1, min(100, $limit));
        return $this->pdo->query("
            SELECT u.*, reviewer.email AS reviewer_email
            FROM users u
            LEFT JOIN users reviewer ON reviewer.id = u.reviewed_by
            WHERE u.status IN ('rejected', 'suspended')
            ORDER BY u.reviewed_at DESC, u.updated_at DESC
            LIMIT {$limit}
        ")->fetchAll();
    }

    /**
     * @return array{success:bool,message:string}
     */
    public function approve(int $adminId, int $targetId): array
    {
        $context = $this->reviewContext($adminId, $targetId);
        if (!$context['allowed']) {
            return ['success' => false, 'message' => $context['message']];
        }

        $target = $context['target'];
        $stmt = $this->pdo->prepare("
            UPDATE users
            SET status = 'active', reviewed_by = ?, reviewed_at = NOW(),
                rejection_reason = NULL, must_change_password = 0,
                session_epoch = session_epoch + 1, updated_at = NOW()
            WHERE id = ? AND status = 'pending'
        ");
        $stmt->execute([$adminId, $targetId]);

        if ($stmt->rowCount() === 0) {
            return ['success' => false, 'message' => 'That account is no longer pending review.'];
        }

        log_activity($adminId, 'ACCOUNT_APPROVED', 'SUCCESS', 'Approved account: ' . $target['email']);
        create_notification(
            $targetId,
            'approval',
            'Your Couriva account was approved',
            'You can now sign in with ' . $target['email'] . '.',
            'public/login.php'
        );

        return ['success' => true, 'message' => 'Approved ' . $target['email'] . '. The user can now sign in.'];
    }

    /**
     * @return array{success:bool,message:string}
     */
    public function reject(int $adminId, int $targetId, string $reason = ''): array
    {
        $context = $this->reviewContext($adminId, $targetId);
        if (!$context['allowed']) {
            return ['success' => false, 'message' => $context['message']];
        }

        $target = $context['target'];
        $reason = mb_substr(trim($reason), 0, 500);
        $stmt = $this->pdo->prepare("
            UPDATE users
            SET status = 'rejected', reviewed_by = ?, reviewed_at = NOW(),
                rejection_reason = ?, session_epoch = session_epoch + 1, updated_at = NOW()
            WHERE id = ? AND status = 'pending'
        ");
        $stmt->execute([$adminId, $reason !== '' ? $reason : null, $targetId]);

        if ($stmt->rowCount() === 0) {
            return ['success' => false, 'message' => 'That account is no longer pending review.'];
        }

        log_activity($adminId, 'ACCOUNT_REJECTED', 'SUCCESS', 'Rejected account: ' . $target['email']);
        create_notification(
            $targetId,
            'approval',
            'Your Couriva account request was declined',
            $reason !== '' ? $reason : 'Contact an administrator for more information.',
            'public/login.php'
        );

        return ['success' => true, 'message' => 'Rejected ' . $target['email'] . '.'];
    }

    /**
     * @return array{allowed:bool,message:string,target?:array}
     */
    protected function reviewContext(int $adminId, int $targetId): array
    {
        if ($targetId <= 0) {
            return ['allowed' => false, 'message' => 'Account request not found.'];
        }
        if ($targetId === $adminId) {
            return ['allowed' => false, 'message' => 'You cannot review your own account.'];
        }

        $adminStmt = $this->pdo->prepare("SELECT role, status FROM users WHERE id = ? LIMIT 1");
        $adminStmt->execute([$adminId]);
        $admin = $adminStmt->fetch();
        if (!$admin || $admin['role'] !== 'admin' || $admin['status'] !== 'active') {
            return ['allowed' => false, 'message' => 'Administrator privileges are required.'];
        }

        $stmt = $this->pdo->prepare("SELECT * FROM users WHERE id = ? LIMIT 1");
        $stmt->execute([$targetId]);
        $target = $stmt->fetch();

        if (!$target) {
            return ['allowed' => false, 'message' => 'Account request not found.'];
        }
        if ($target['status'] !== 'pending') {
            return ['allowed' => false, 'message' => 'That account is no longer pending review.'];
        }

        return ['allowed' => true, 'message' => '', 'target' => $target];
    }
}
