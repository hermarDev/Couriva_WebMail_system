<?php
/**
 * Couriva - Spam Reports & Sender Blocking
 * User evidence submission and administrator review.
 */

namespace App\Services;

use PDO;
use InvalidArgumentException;

class SpamService
{
    protected PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public function report(int $userId, int $emailId, string $reason = ''): int
    {
        $stmt = $this->pdo->prepare("
            SELECT id, sender_email_snapshot, subject FROM emails
            WHERE id = ? AND (recipient_id = ? OR (sender_id = ? AND recipient_id IS NULL))
            LIMIT 1
        ");
        $stmt->execute([$emailId, $userId, $userId]);
        $email = $stmt->fetch();
        if (!$email) {
            throw new InvalidArgumentException('Message not found.');
        }

        $this->pdo->beginTransaction();
        try {
            $move = $this->pdo->prepare("
                UPDATE emails SET folder = 'spam', updated_at = NOW()
                WHERE id = ? AND recipient_id = ?
            ");
            $move->execute([$emailId, $userId]);

            $insert = $this->pdo->prepare("
                INSERT INTO spam_reports (reporter_id, email_id, reason, status, created_at)
                VALUES (?, ?, ?, 'open', NOW())
                ON DUPLICATE KEY UPDATE reason = VALUES(reason), status = 'open', reviewed_at = NULL,
                    reviewer_id = NULL, reviewer_note = NULL
            ");
            $insert->execute([$userId, $emailId, mb_substr(trim($reason), 0, 500)]);

            $idStmt = $this->pdo->prepare("SELECT id FROM spam_reports WHERE email_id = ? AND reporter_id = ? LIMIT 1");
            $idStmt->execute([$emailId, $userId]);
            $reportId = (int)$idStmt->fetchColumn();

            $this->pdo->commit();
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }

        notify_admins(
            'spam_report',
            'New spam report',
            ($_SESSION['user_email'] ?? 'A user') . ' reported: ' . mb_substr((string)$email['subject'], 0, 180),
            'admin/spam-reports.php'
        );
        log_activity($userId, 'SPAM_REPORTED', 'SUCCESS', 'Reported email ID ' . $emailId);

        return $reportId;
    }

    public function blockSender(int $userId, string $senderEmail): bool
    {
        $senderEmail = strtolower(trim($senderEmail));
        if (!filter_var($senderEmail, FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException('Invalid sender address.');
        }

        $stmt = $this->pdo->prepare("
            INSERT IGNORE INTO blocked_senders (user_id, sender_email, created_at)
            VALUES (?, ?, NOW())
        ");
        $stmt->execute([$userId, $senderEmail]);
        log_activity($userId, 'SENDER_BLOCKED', 'SUCCESS', 'Blocked sender: ' . $senderEmail);
        return $stmt->rowCount() > 0;
    }

    public function unblockSender(int $userId, int $blockId): bool
    {
        $stmt = $this->pdo->prepare("DELETE FROM blocked_senders WHERE id = ? AND user_id = ?");
        $stmt->execute([$blockId, $userId]);
        return $stmt->rowCount() > 0;
    }

    public function unblockSenderByEmail(int $userId, string $senderEmail): bool
    {
        $senderEmail = strtolower(trim($senderEmail));
        if (!filter_var($senderEmail, FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException('Invalid sender address.');
        }

        $stmt = $this->pdo->prepare("DELETE FROM blocked_senders WHERE user_id = ? AND sender_email = ?");
        $stmt->execute([$userId, $senderEmail]);
        if ($stmt->rowCount() > 0) {
            log_activity($userId, 'SENDER_UNBLOCKED', 'SUCCESS', 'Unblocked sender: ' . $senderEmail);
            return true;
        }
        return false;
    }

    public function getBlockedSenders(int $userId): array
    {
        $stmt = $this->pdo->prepare("SELECT * FROM blocked_senders WHERE user_id = ? ORDER BY sender_email ASC");
        $stmt->execute([$userId]);
        return $stmt->fetchAll();
    }

    public function getOpenReports(int $limit = 100): array
    {
        $limit = max(1, min(200, $limit));
        $stmt = $this->pdo->query("
            SELECT sr.*, e.subject, e.body, e.sender_email_snapshot, e.sender_name_snapshot,
                   e.recipient_id, e.folder,
                   reporter.email AS reporter_email,
                   reporter.first_name AS reporter_first_name,
                   reporter.last_name AS reporter_last_name,
                   reviewer.email AS reviewer_email
            FROM spam_reports sr
            JOIN emails e ON e.id = sr.email_id
            JOIN users reporter ON reporter.id = sr.reporter_id
            LEFT JOIN users reviewer ON reviewer.id = sr.reviewer_id
            ORDER BY FIELD(sr.status, 'open', 'resolved', 'dismissed'), sr.created_at DESC
            LIMIT {$limit}
        ");
        return $stmt->fetchAll();
    }

    public function getReport(int $reportId): ?array
    {
        $stmt = $this->pdo->prepare("
            SELECT sr.*, e.subject, e.body, e.sender_email_snapshot, e.sender_name_snapshot,
                   e.recipient_id, e.folder,
                   reporter.email AS reporter_email,
                   reporter.first_name AS reporter_first_name,
                   reporter.last_name AS reporter_last_name
            FROM spam_reports sr
            JOIN emails e ON e.id = sr.email_id
            JOIN users reporter ON reporter.id = sr.reporter_id
            WHERE sr.id = ?
            LIMIT 1
        ");
        $stmt->execute([$reportId]);
        return $stmt->fetch() ?: null;
    }

    /**
     * Administrator decision: resolved blocks the sender for the reporter and
     * keeps the message in Spam; dismissed restores it to Inbox.
     */
    public function review(int $adminId, int $reportId, string $decision, string $note = ''): bool
    {
        if (!in_array($decision, ['resolved', 'dismissed'], true)) {
            throw new InvalidArgumentException('Invalid review decision.');
        }

        $report = $this->getReport($reportId);
        if (!$report) {
            throw new InvalidArgumentException('Report not found.');
        }

        $this->pdo->beginTransaction();
        try {
            $stmt = $this->pdo->prepare("
                UPDATE spam_reports
                SET status = ?, reviewer_id = ?, reviewer_note = ?, reviewed_at = NOW()
                WHERE id = ? AND status = 'open'
            ");
            $stmt->execute([$decision, $adminId, mb_substr(trim($note), 0, 500), $reportId]);
            if ($stmt->rowCount() === 0) {
                $this->pdo->rollBack();
                return false;
            }

            if ($decision === 'resolved') {
                $this->pdo->prepare("
                    UPDATE emails SET folder = 'spam', updated_at = NOW() WHERE id = ?
                ")->execute([(int)$report['email_id']]);

                $senderEmail = strtolower((string)$report['sender_email_snapshot']);
                if (filter_var($senderEmail, FILTER_VALIDATE_EMAIL)) {
                    $block = $this->pdo->prepare("
                        INSERT IGNORE INTO blocked_senders (user_id, sender_email, created_at)
                        VALUES (?, ?, NOW())
                    ");
                    $block->execute([(int)$report['reporter_id'], $senderEmail]);
                }
            } else {
                $this->pdo->prepare("
                    UPDATE emails SET folder = 'inbox', updated_at = NOW() WHERE id = ?
                ")->execute([(int)$report['email_id']]);
            }

            $this->pdo->commit();
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }

        create_notification(
            (int)$report['reporter_id'],
            'spam_review',
            $decision === 'resolved' ? 'Spam report resolved' : 'Spam report dismissed',
            $decision === 'resolved'
                ? 'The reported sender has been blocked for your mailbox.'
                : 'The reported message was returned to your Inbox.',
            $decision === 'resolved' ? 'public/spam.php' : 'public/inbox.php'
        );
        log_activity($adminId, 'SPAM_REPORT_REVIEWED', 'SUCCESS', "Report {$reportId}: {$decision}");

        return true;
    }
}
