<?php
/**
 * Couriva - Scheduled Mail Service
 * Stores future dispatches against the owner's draft and delivers them through
 * the existing MailService when they become due.
 */

namespace App\Services;

use App\Models\Attachment;
use PDO;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use Throwable;

require_once __DIR__ . '/MailService.php';
require_once dirname(__DIR__) . '/Models/Attachment.php';

class ScheduledMailService
{
    protected PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * Create or replace a scheduled dispatch for one of the user's drafts.
     */
    public function schedule(int $userId, int $draftId, string $scheduledAt, string $timezone = 'UTC'): int
    {
        $draftStmt = $this->pdo->prepare("
            SELECT id FROM emails
            WHERE id = ? AND sender_id = ? AND (folder = 'draft' OR is_draft = 1)
            LIMIT 1
        ");
        $draftStmt->execute([$draftId, $userId]);
        if (!$draftStmt->fetch()) {
            throw new InvalidArgumentException('Draft not found.');
        }

        try {
            $zone = new DateTimeZone($timezone !== '' ? $timezone : 'UTC');
            $when = new DateTimeImmutable($scheduledAt, $zone);
            $whenUtc = $when->setTimezone(new DateTimeZone('UTC'));
        } catch (Throwable $e) {
            throw new InvalidArgumentException('Invalid schedule date and time.');
        }

        $timestamp = $whenUtc->getTimestamp();
        if ($timestamp <= time() + 30 || $timestamp > strtotime('+1 year')) {
            throw new InvalidArgumentException('Choose a schedule time between one minute and one year from now.');
        }

        $stmt = $this->pdo->prepare("
            UPDATE scheduled_emails
            SET status = 'cancelled', updated_at = NOW()
            WHERE user_id = ? AND draft_email_id = ? AND status IN ('scheduled', 'processing')
        ");
        $stmt->execute([$userId, $draftId]);

        $insert = $this->pdo->prepare("
            INSERT INTO scheduled_emails (user_id, draft_email_id, scheduled_at, status, created_at, updated_at)
            VALUES (?, ?, ?, 'scheduled', NOW(), NOW())
        ");
        $insert->execute([$userId, $draftId, $whenUtc->format('Y-m-d H:i:s')]);
        return (int)$this->pdo->lastInsertId();
    }

    public function cancel(int $userId, int $scheduleId): bool
    {
        $stmt = $this->pdo->prepare("
            UPDATE scheduled_emails
            SET status = 'cancelled', updated_at = NOW()
            WHERE id = ? AND user_id = ? AND status = 'scheduled'
        ");
        $stmt->execute([$scheduleId, $userId]);
        return $stmt->rowCount() > 0;
    }

    public function getForUser(int $userId): array
    {
        $stmt = $this->pdo->prepare("
            SELECT s.*,
                   COALESCE(d.subject, sent.subject, '(Deleted message)') AS subject,
                   COALESCE(d.id, s.sent_email_id) AS reference_email_id,
                   sent.created_at AS sent_at
            FROM scheduled_emails s
            LEFT JOIN emails d ON d.id = s.draft_email_id
            LEFT JOIN emails sent ON sent.id = s.sent_email_id
            WHERE s.user_id = ?
            ORDER BY s.scheduled_at DESC
            LIMIT 200
        ");
        $stmt->execute([$userId]);
        return $stmt->fetchAll();
    }

    public function countPending(int $userId): int
    {
        $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM scheduled_emails WHERE user_id = ? AND status = 'scheduled'");
        $stmt->execute([$userId]);
        return (int)$stmt->fetchColumn();
    }

    /**
     * Deliver due dispatches. Safe to call from a CLI cron job or a web request.
     *
     * @return array{processed:int,sent:int,failed:int}
     */
    public function processDue(int $limit = 10): array
    {
        $limit = max(1, min(50, $limit));
        $now = date('Y-m-d H:i:s');
        $staleThreshold = date('Y-m-d H:i:s', time() - 600);

        // First, reclaim stuck 'processing' rows older than 10 minutes (worker crash recovery)
        $reclaim = $this->pdo->prepare("
            UPDATE scheduled_emails
            SET status = 'scheduled', updated_at = ?
            WHERE status = 'processing' AND updated_at <= ?
        ");
        $reclaim->execute([$now, $staleThreshold]);

        $stmt = $this->pdo->prepare("
            SELECT * FROM scheduled_emails
            WHERE status = 'scheduled' AND scheduled_at <= ?
            ORDER BY scheduled_at ASC
            LIMIT {$limit}
        ");
        $stmt->execute([$now]);
        $due = $stmt->fetchAll();

        $summary = ['processed' => 0, 'sent' => 0, 'failed' => 0];

        foreach ($due as $row) {
            $claim = $this->pdo->prepare("
                UPDATE scheduled_emails
                SET status = 'processing', updated_at = ?
                WHERE id = ? AND status = 'scheduled'
            ");
            $claim->execute([$now, (int)$row['id']]);
            if ($claim->rowCount() === 0) {
                continue;
            }

            $summary['processed']++;
            $result = $this->dispatch($row);
            if ($result['success']) {
                $summary['sent']++;
            } else {
                $summary['failed']++;
            }
        }

        return $summary;
    }

    protected function dispatch(array $schedule): array
    {
        $scheduleId = (int)$schedule['id'];
        $userId = (int)$schedule['user_id'];
        $draftId = (int)($schedule['draft_email_id'] ?? 0);

        try {
            if ($draftId <= 0) {
                throw new InvalidArgumentException('The linked draft no longer exists.');
            }

            $draftStmt = $this->pdo->prepare("
                SELECT * FROM emails
                WHERE id = ? AND sender_id = ? AND (folder = 'draft' OR is_draft = 1)
                LIMIT 1
            ");
            $draftStmt->execute([$draftId, $userId]);
            $draft = $draftStmt->fetch();
            if (!$draft) {
                throw new InvalidArgumentException('The linked draft no longer exists.');
            }

            $recipientStmt = $this->pdo->prepare("
                SELECT email_address, recipient_type FROM email_recipients
                WHERE email_id = ?
                ORDER BY id ASC
            ");
            $recipientStmt->execute([$draftId]);
            $recipients = ['to' => [], 'cc' => [], 'bcc' => []];
            foreach ($recipientStmt->fetchAll() as $recipient) {
                $type = $recipient['recipient_type'] ?: 'to';
                if (isset($recipients[$type])) {
                    $recipients[$type][] = $recipient['email_address'];
                }
            }

            if (empty($recipients['to'])) {
                throw new InvalidArgumentException('The draft has no valid recipient.');
            }

            $attachmentStmt = $this->pdo->prepare("SELECT * FROM attachments WHERE email_id = ?");
            $attachmentStmt->execute([$draftId]);
            $attachments = $attachmentStmt->fetchAll();

            $mailService = new MailService($this->pdo);
            $sendResult = $mailService->sendEmail(
                $userId,
                $recipients,
                (string)$draft['subject'],
                (string)$draft['body'],
                !empty($draft['parent_email_id']) ? (int)$draft['parent_email_id'] : null,
                $attachments
            );

            if (empty($sendResult['success'])) {
                throw new InvalidArgumentException($sendResult['error'] ?? 'Delivery failed.');
            }

            // Check if any delivery_status rows show failed/unknown for SMTP
            $sentEmailId = (int)$sendResult['sent_email_id'];
            $deliveryCheck = $this->pdo->prepare("
                SELECT status, COUNT(*) as cnt FROM delivery_status
                WHERE email_id = ? AND transport = 'smtp'
                GROUP BY status
            ");
            $deliveryCheck->execute([$sentEmailId]);
            $deliveryStatuses = $deliveryCheck->fetchAll(PDO::FETCH_KEY_PAIR);

            $hasFailed = isset($deliveryStatuses['failed']);
            $hasUnknown = isset($deliveryStatuses['unknown']);

            // Share immutable attachment files with the sent and inbox copies.
            $attachmentModel = new Attachment($this->pdo);
            $copies = array_merge([$sentEmailId], array_map('intval', $sendResult['inbox_email_ids'] ?? []));
            foreach ($attachments as $attachment) {
                foreach ($copies as $emailId) {
                    $attachmentModel->create([
                        'email_id'          => $emailId,
                        'original_filename' => $attachment['original_filename'],
                        'stored_filename'   => $attachment['stored_filename'],
                        'filepath'          => $attachment['filepath'],
                        'mime_type'         => $attachment['mime_type'],
                        'filesize'          => $attachment['filesize'],
                    ]);
                }
            }

            // Determine final status based on delivery tracking
            $finalStatus = 'sent';
            $lastError = NULL;
            
            if ($hasFailed) {
                $finalStatus = 'failed';
                $lastError = 'SMTP delivery failed for one or more recipients.';
            } elseif ($hasUnknown) {
                // Unknown outcome = capped retries (3 attempts max)
                $attempts = (int)($schedule['attempts'] ?? 0) + 1;
                if ($attempts < 3) {
                    $finalStatus = 'scheduled';  // Will be retried
                    $lastError = 'SMTP outcome uncertain for some recipients. Retrying...';
                    $nextRun = date('Y-m-d H:i:s', time() + 300);
                    $nowTs = date('Y-m-d H:i:s');
                    $retryStmt = $this->pdo->prepare("
                        UPDATE scheduled_emails
                        SET status = 'scheduled', attempts = ?, scheduled_at = ?,
                            last_error = ?, updated_at = ?
                        WHERE id = ?
                    ");
                    $retryStmt->execute([$attempts, $nextRun, $lastError, $nowTs, $scheduleId]);
                    $finishStmt = $this->pdo->prepare("
                        UPDATE scheduled_emails
                        SET sent_email_id = ?, delivery_status_id = (
                            SELECT id FROM delivery_status WHERE email_id = ? ORDER BY id DESC LIMIT 1
                        )
                        WHERE id = ?
                    ");
                    $finishStmt->execute([$sentEmailId, $sentEmailId, $scheduleId]);
                    return ['success' => false, 'retry' => true];
                } else {
                    $finalStatus = 'failed';
                    $lastError = 'SMTP outcome uncertain after 3 attempts. Giving up.';
                }
            }

            $nowTs = date('Y-m-d H:i:s');
            $finish = $this->pdo->prepare("
                UPDATE scheduled_emails
                SET status = ?, sent_email_id = ?, last_error = ?, updated_at = ?,
                    delivery_status_id = (
                        SELECT id FROM delivery_status WHERE email_id = ? ORDER BY id DESC LIMIT 1
                    )
                WHERE id = ?
            ");
            $finish->execute([$finalStatus, $sentEmailId, $lastError, $nowTs, $sentEmailId, $scheduleId]);

            // Remove the now-delivered draft copy only when sent.
            if ($finalStatus === 'sent') {
                $deleteDraft = $this->pdo->prepare("DELETE FROM emails WHERE id = ? AND sender_id = ?");
                $deleteDraft->execute([$draftId, $userId]);
            }

            create_notification(
                $userId,
                'schedule',
                $finalStatus === 'sent' ? 'Scheduled message sent' : 'Scheduled message could not be sent',
                mb_substr((string)$draft['subject'], 0, 200),
                $finalStatus === 'sent' ? 'public/sent.php' : 'public/scheduled.php'
            );
            log_activity($userId, 'SCHEDULED_EMAIL_SENT', $finalStatus === 'sent' ? 'SUCCESS' : 'FAILED', 'Scheduled message ' . $finalStatus . ': ' . $draft['subject']);

            return ['success' => $finalStatus === 'sent'];
        } catch (Throwable $e) {
            error_log('Scheduled dispatch failed: ' . $e->getMessage());

            $attempts = (int)($schedule['attempts'] ?? 0) + 1;
            $nowTs = date('Y-m-d H:i:s');
            if ($attempts < 3) {
                $nextRun = date('Y-m-d H:i:s', time() + 300);
                $retry = $this->pdo->prepare("
                    UPDATE scheduled_emails
                    SET status = 'scheduled', attempts = ?, scheduled_at = ?,
                        last_error = ?, updated_at = ?
                    WHERE id = ?
                ");
                $retry->execute([$attempts, $nextRun, 'Delivery attempt failed. Couriva will retry automatically.', $nowTs, $scheduleId]);
            } else {
                $fail = $this->pdo->prepare("
                    UPDATE scheduled_emails
                    SET status = 'failed', attempts = ?, last_error = ?, updated_at = ?
                    WHERE id = ?
                ");
                $fail->execute([$attempts, 'Delivery failed after multiple attempts.', $nowTs, $scheduleId]);
            }

            create_notification(
                $userId,
                'schedule',
                'Scheduled message could not be sent',
                'Open Scheduled to review the message.',
                'public/scheduled.php'
            );

            return ['success' => false];
        }
    }
}
