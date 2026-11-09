<?php
/**
 * Couriva - Mail Service
 * Coordinates internal database messaging (Phase 1), advanced search,
 * draft management, batch mailbox actions, and external SMTP transport (Phase 2).
 */

namespace App\Services;

use App\Models\Email;
use App\Models\User;
use App\Services\AttachmentService;
use App\Services\SmtpTransport;
use App\Services\OrganizationService;
use PDO;
use Throwable;
use InvalidArgumentException;
use RuntimeException;

require_once dirname(__DIR__) . '/Models/Email.php';
require_once dirname(__DIR__) . '/Models/User.php';
require_once __DIR__ . '/AttachmentService.php';
require_once __DIR__ . '/SmtpTransport.php';
require_once __DIR__ . '/OrganizationService.php';

class MailService
{
    protected PDO $pdo;
    protected Email $emailModel;
    protected User $userModel;
    protected AttachmentService $attachmentService;
    public array $config;

    public function __construct(PDO $pdo, ?array $mailConfig = null)
    {
        $this->pdo = $pdo;
        $this->emailModel = new Email($pdo);
        $this->userModel = new User($pdo);
        $this->attachmentService = new AttachmentService($pdo, $mailConfig);
        $this->config = $mailConfig ?? (require dirname(dirname(__DIR__)) . '/config/mail.php');
    }

    /**
     * Get all visible messages belonging to a conversation thread.
     * Groups messages linked by parent_email_id or normalized subject.
     *
     * @param int $emailId
     * @param int $userId
     * @return array
     */
    public function getConversationThread(int $emailId, int $userId): array
    {
        // 1. Fetch requested email to get its parent or subject
        $baseStmt = $this->pdo->prepare("
            SELECT id, parent_email_id, subject, sender_id, recipient_id
            FROM emails
            WHERE id = ? AND (recipient_id = ? OR (sender_id = ? AND recipient_id IS NULL)) AND deleted_at IS NULL
            LIMIT 1
        ");
        $baseStmt->execute([$emailId, $userId, $userId]);
        $base = $baseStmt->fetch();

        if (!$base) {
            return [];
        }

        // Normalize subject by stripping leading Re: / Fwd: prefixes
        $cleanSubject = preg_replace('/^((Re|Fwd|Fw):\s*)+/i', '', trim($base['subject']));
        $rootId = (int)($base['parent_email_id'] ?: $base['id']);

        // 2. Fetch all messages in this thread visible to the user
        $threadStmt = $this->pdo->prepare("
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
            WHERE (e.id = ? OR e.parent_email_id = ? OR e.id = ? OR e.subject LIKE ?)
              AND (e.recipient_id = ? OR (e.sender_id = ? AND e.recipient_id IS NULL))
              AND e.deleted_at IS NULL
            ORDER BY e.created_at ASC, e.id ASC
        ");
        $threadStmt->execute([$rootId, $rootId, $emailId, '%' . $cleanSubject . '%', $userId, $userId]);
        $messages = $threadStmt->fetchAll();

        if (empty($messages)) {
            return [];
        }

        // Deduplicate messages by id
        $deduped = [];
        $emailIds = [];
        foreach ($messages as $msg) {
            $id = (int)$msg['id'];
            if (!isset($deduped[$id])) {
                $deduped[$id] = $msg;
                $emailIds[] = $id;
            }
        }
        $messages = array_values($deduped);

        // Fetch attachments for all messages in thread
        if (!empty($emailIds)) {
            $inClause = implode(',', array_fill(0, count($emailIds), '?'));
            $attStmt = $this->pdo->prepare("SELECT * FROM attachments WHERE email_id IN ($inClause)");
            $attStmt->execute($emailIds);
            $attMap = [];
            while ($att = $attStmt->fetch()) {
                $attMap[(int)$att['email_id']][] = $att;
            }

            foreach ($messages as &$msg) {
                $msg['attachments'] = $attMap[(int)$msg['id']] ?? [];
            }
            unset($msg);
        }

        return $messages;
    }

    /**
     * Send email to one or multiple recipients (Phase 1 Database / Phase 2 SMTP)
     *
     * @param int $senderId
     * @param array $recipients ['to' => [...], 'cc' => [...], 'bcc' => [...]]
     * @param string $subject
     * @param string $body
     * @param int|null $parentEmailId
     * @param array $attachments Array of attachments descriptors or uploaded files
     * @return array
     */
    public function sendEmail(
        int $senderId,
        array $recipients,
        string $subject,
        string $body,
        ?int $parentEmailId = null,
        array $attachments = []
    ): array {
        $deliveryStatusIds = [];
        $smtpAcceptedAny = false;
        $messageId = null;

        // Prepare attachment descriptors - VALIDATE ALL UPFRONT (fail-closed before SMTP or DB writes)
        $smtpAttachments = [];
        $projectRoot = dirname(dirname(__DIR__));
        foreach ($attachments as $att) {
            if (!is_array($att) || empty($att['filepath'])) {
                throw new RuntimeException("Invalid attachment descriptor: missing filepath.");
            }
            $rawPath = (string)$att['filepath'];
            if (str_starts_with($rawPath, '/') || preg_match('/^[A-Za-z]:[\\\\\/]/', $rawPath)) {
                $fullPath = $rawPath;
            } else {
                $fullPath = $projectRoot . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $rawPath);
            }
            if (!file_exists($fullPath) || !is_readable($fullPath)) {
                throw new RuntimeException("Attachment file missing or unreadable: " . $att['filepath']);
            }
            $smtpAttachments[] = [
                'path' => $fullPath,
                'name' => $att['original_filename'] ?? basename($fullPath),
                'mime' => $att['mime_type'] ?? 'application/octet-stream',
            ];
        }

        try {
            $sender = $this->userModel->findById($senderId);
            if (!$sender) {
                throw new InvalidArgumentException("Invalid sender ID.");
            }

            // Normalize recipients
            $to = $this->normalizeRecipientList($recipients['to'] ?? []);
            $cc = $this->normalizeRecipientList($recipients['cc'] ?? []);
            $bcc = $this->normalizeRecipientList($recipients['bcc'] ?? []);

            if (empty($to)) {
                throw new InvalidArgumentException("At least one valid recipient address is required.");
            }

            $normalizedRecipients = ['to' => $to, 'cc' => $cc, 'bcc' => $bcc];

            // Primary recipient determination
            $primaryTo = $to[0] ?? null;
            $primaryRecipientUser = $primaryTo ? $this->userModel->findByEmail($primaryTo) : null;
            $primaryRecipientId = $primaryRecipientUser ? (int)$primaryRecipientUser['id'] : null;

            // Phase 2: If mail driver is SMTP, dispatch via SMTP socket client first (outside DB transaction so delivery_status survives DB commit failures)
            if (($this->config['driver'] ?? 'database') === 'smtp') {
                $smtpConfig = $this->config['smtp'] ?? [];
                $smtpTransport = new SmtpTransport($smtpConfig);

                $fromAddress = !empty($this->config['from']['address']) ? $this->config['from']['address'] : $sender['email'];
                $fromName = !empty($this->config['from']['name']) ? $this->config['from']['name'] : trim($sender['first_name'] . ' ' . $sender['last_name']);

                // Generate Message-ID upfront for idempotency tracking
                $messageId = '<' . bin2hex(random_bytes(16)) . '@' . (parse_url($fromAddress, PHP_URL_HOST) ?: 'couriva.local') . '>';

                // Create delivery_status rows for each recipient BEFORE SMTP (queued state)
                $allRecipientEmails = array_unique(array_merge($to, $cc, $bcc));
                foreach ($allRecipientEmails as $recipientEmail) {
                    $recipientEmail = trim(strtolower($recipientEmail));
                    if (empty($recipientEmail)) continue;
                    $type = in_array($recipientEmail, $to) ? 'to' : (in_array($recipientEmail, $cc) ? 'cc' : 'bcc');
                    $idempotencyKey = hash('sha256', $messageId . '|' . $recipientEmail);
                    $stmt = $this->pdo->prepare("
                        INSERT INTO delivery_status (message_id, email_id, recipient_email, recipient_type, transport, status, idempotency_key, attempt, max_attempts)
                        VALUES (?, NULL, ?, ?, 'smtp', 'queued', ?, 0, 3)
                    ");
                    $stmt->execute([$messageId, $recipientEmail, $type, $idempotencyKey]);
                    $deliveryStatusIds[$recipientEmail] = (int)$this->pdo->lastInsertId();
                }

                // Send via SMTP with per-recipient tracking
                $smtpResult = $smtpTransport->sendWithTracking(
                    $fromAddress,
                    $fromName,
                    $normalizedRecipients,
                    $subject,
                    $body,
                    $smtpAttachments,
                    $messageId
                );

                // Update delivery_status with SMTP results
                foreach ($smtpResult['recipients'] as $recipientEmail => $result) {
                    $deliveryStatusId = $deliveryStatusIds[$recipientEmail] ?? null;
                    if ($deliveryStatusId) {
                        $status = !empty($result['accepted']) ? 'delivered' : ($result['status'] ?? 'failed');
                        if ($status === 'delivered') {
                            $smtpAcceptedAny = true;
                        }
                        $stmt = $this->pdo->prepare("
                            UPDATE delivery_status
                            SET status = ?, smtp_response = ?, smtp_code = ?, attempt = attempt + 1, updated_at = CURRENT_TIMESTAMP
                            WHERE id = ?
                        ");
                        $stmt->execute([$status, $result['response'] ?? '', $result['code'] ?? null, $deliveryStatusId]);
                    }
                }
            }

            $this->pdo->beginTransaction();

            // 1. Create Sent mail record for sender (recipients are stored in email_recipients)
            $sentEmailId = $this->emailModel->create([
                'sender_id'       => $senderId,
                'recipient_id'    => null,
                'parent_email_id' => $parentEmailId,
                'subject'         => $subject,
                'body'            => $body,
                'is_read'         => 1,
                'is_starred'      => 0,
                'is_draft'        => 0,
                'folder'          => 'sent',
                'message_id'      => $messageId ?? null,
            ]);

            // Update delivery_status rows with the sent_email_id
            if (($this->config['driver'] ?? 'database') === 'smtp' && !empty($deliveryStatusIds)) {
                $updateStmt = $this->pdo->prepare("
                    UPDATE delivery_status SET email_id = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?
                ");
                foreach ($deliveryStatusIds as $deliveryStatusId) {
                    $updateStmt->execute([$sentEmailId, $deliveryStatusId]);
                }
            }

            // Save recipients mapping for sent email
            $this->saveRecipientsList($sentEmailId, $normalizedRecipients);

            // 2. Deliver to Inbox of each local recipient (To & Cc)
            $deliveryList = array_merge($to, $cc, $bcc);

            $inboxEmailIds = [];
            $notifications = [];
            $blockStmt = $this->pdo->prepare("
                SELECT 1 FROM blocked_senders
                WHERE user_id = ? AND sender_email = ?
                LIMIT 1
            ");

            foreach (array_unique($deliveryList) as $recipientEmail) {
                $recipientEmail = trim(strtolower($recipientEmail));
                $localUser = $this->userModel->findByEmail($recipientEmail);

                // If an approved local user exists, place it in their inbox or spam folder.
                if ($localUser && ($localUser['status'] ?? '') === 'active' && (int)$localUser['id'] !== $senderId) {
                    $blockStmt->execute([(int)$localUser['id'], strtolower((string)$sender['email'])]);
                    $isBlocked = (bool)$blockStmt->fetchColumn();
                    $targetFolder = $isBlocked ? 'spam' : 'inbox';

                    $inboxEmailId = $this->emailModel->create([
                        'sender_id'       => $senderId,
                        'recipient_id'    => (int)$localUser['id'],
                        'parent_email_id' => $parentEmailId,
                        'subject'         => $subject,
                        'body'            => $body,
                        'is_read'         => 0,
                        'is_starred'      => 0,
                        'is_draft'        => 0,
                        'folder'          => $targetFolder,
                    ]);

                    $inboxEmailIds[] = $inboxEmailId;

                    if (!$isBlocked) {
                        $notifications[] = [
                            'user_id' => (int)$localUser['id'],
                            'email_id' => $inboxEmailId,
                        ];
                    }

                    // Copy recipients to inbox message, respecting BCC privacy
                    foreach (['to', 'cc', 'bcc'] as $type) {
                        foreach ($normalizedRecipients[$type] as $addr) {
                            if ($type === 'bcc' && $addr !== $recipientEmail) {
                                continue;
                            }
                            $tUser = $this->userModel->findByEmail($addr);
                            $this->emailModel->addRecipient($inboxEmailId, $tUser ? (int)$tUser['id'] : null, $addr, $type);
                        }
                    }
                }
            }

            $this->pdo->commit();

            // Notifications are best-effort and must not roll back delivery.
            $senderName = trim(($sender['first_name'] ?? '') . ' ' . ($sender['last_name'] ?? ''))
                ?: (string)$sender['email'];
            foreach ($notifications as $notice) {
                create_notification(
                    $notice['user_id'],
                    'new_mail',
                    'New message from ' . $senderName,
                    $subject,
                    'public/read.php?id=' . $notice['email_id']
                );
            }

            return [
                'success'         => true,
                'sent_email_id'   => $sentEmailId,
                'inbox_email_ids' => $inboxEmailIds,
            ];
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            if (!$smtpAcceptedAny && !empty($deliveryStatusIds)) {
                try {
                    $failStmt = $this->pdo->prepare("
                        UPDATE delivery_status
                        SET status = CASE WHEN status = 'queued' THEN 'failed' ELSE status END,
                            last_error = COALESCE(last_error, ?),
                            attempt = CASE WHEN attempt = 0 THEN 1 ELSE attempt END,
                            updated_at = CURRENT_TIMESTAMP
                        WHERE id = ?
                    ");
                    foreach ($deliveryStatusIds as $dsId) {
                        $failStmt->execute([$e->getMessage(), $dsId]);
                    }
                } catch (Throwable $ignored) {
                }
            }
            error_log('Mail delivery failed: ' . $e->getMessage());
            return [
                'success' => false,
                'error'   => 'Message delivery is temporarily unavailable. Please try again later.',
                'smtp_accepted' => $smtpAcceptedAny,
                'message_id' => $messageId,
            ];
        }
    }

    /**
     * Save or update draft email
     *
     * @param int $userId Current user ID (draft owner)
     * @param int|null $draftId Existing draft ID to update or null to create new
     * @param array $data ['subject', 'body', 'to', 'cc', 'bcc', 'parent_email_id']
     * @param array $files Uploaded files array (e.g., from $_FILES)
     * @return int The draft ID
     */
    public function saveOrUpdateDraft(int $userId, ?int $draftId, array $data, array $files = []): int
    {
        $subject = trim($data['subject'] ?? '');
        $body = $data['body'] ?? '';
        $parentEmailId = !empty($data['parent_email_id']) ? (int)$data['parent_email_id'] : null;

        $to = $this->normalizeRecipientList($data['to'] ?? []);
        $cc = $this->normalizeRecipientList($data['cc'] ?? []);
        $bcc = $this->normalizeRecipientList($data['bcc'] ?? []);
        $normalizedRecipients = ['to' => $to, 'cc' => $cc, 'bcc' => $bcc];

        $targetDraftId = null;

        if ($draftId !== null && $draftId > 0) {
            // Verify ownership and that it is indeed a draft
            $stmt = $this->pdo->prepare("
                SELECT id FROM emails 
                WHERE id = ? AND sender_id = ? AND (folder = 'draft' OR is_draft = 1)
                LIMIT 1
            ");
            $stmt->execute([$draftId, $userId]);
            if ($stmt->fetch()) {
                $targetDraftId = $draftId;
            }
        }

        $this->pdo->beginTransaction();

        try {
            if ($targetDraftId !== null) {
                // Update existing draft
                $this->emailModel->update($targetDraftId, [
                    'subject'         => $subject !== '' ? $subject : '(Untitled Draft)',
                    'body'            => $body,
                    'parent_email_id' => $parentEmailId,
                    'folder'          => 'draft',
                    'is_draft'        => 1,
                ]);

                // Refresh recipient list
                $this->emailModel->clearRecipients($targetDraftId);
                $this->saveRecipientsList($targetDraftId, $normalizedRecipients);
            } else {
                // Create brand new draft
                $targetDraftId = $this->emailModel->create([
                    'sender_id'       => $userId,
                    'recipient_id'    => null,
                    'parent_email_id' => $parentEmailId,
                    'subject'         => $subject !== '' ? $subject : '(Untitled Draft)',
                    'body'            => $body,
                    'is_read'         => 1,
                    'is_starred'      => 0,
                    'is_draft'        => 1,
                    'folder'          => 'draft',
                ]);

                $this->saveRecipientsList($targetDraftId, $normalizedRecipients);
            }

            // Handle file attachments if provided
            $this->processUploadedFiles($targetDraftId, $files);

            $this->pdo->commit();
            return $targetDraftId;
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Copy a draft's attachments to the sent message (and recipient inbox
     * copies), then remove the draft. The draft is only touched when a single
     * scoped lookup confirms it belongs to this user and is still a draft.
     *
     * @return int Number of source attachments copied (0 for foreign/non-draft IDs)
     */
    public function transferDraftAttachmentsOnSend(
        int $draftId,
        int $userId,
        int $sentEmailId,
        array $inboxEmailIds = []
    ): int {
        if ($draftId <= 0 || $userId <= 0 || $sentEmailId <= 0) {
            return 0;
        }

        $draftStmt = $this->pdo->prepare("
            SELECT id FROM emails
            WHERE id = ? AND sender_id = ? AND (folder = 'draft' OR is_draft = 1)
            LIMIT 1
        ");
        $draftStmt->execute([$draftId, $userId]);
        if (!$draftStmt->fetchColumn()) {
            return 0;
        }

        $targetStmt = $this->pdo->prepare("
            SELECT id FROM emails
            WHERE id = ? AND sender_id = ? AND recipient_id IS NULL
            LIMIT 1
        ");
        $targetStmt->execute([$sentEmailId, $userId]);
        if (!$targetStmt->fetchColumn()) {
            return 0;
        }

        $attachmentsStmt = $this->pdo->prepare("
            SELECT * FROM attachments WHERE email_id = ? ORDER BY id ASC
        ");
        $attachmentsStmt->execute([$draftId]);
        $draftAttachments = $attachmentsStmt->fetchAll();
        if (empty($draftAttachments)) {
            $this->emailModel->permanentDelete($draftId, $userId);
            return 0;
        }

        $targetEmailIds = array_values(array_unique(array_filter(array_map(
            'intval',
            array_merge([$sentEmailId], $inboxEmailIds)
        ), fn ($id) => (int)$id > 0)));

        $this->pdo->beginTransaction();

        try {
            $insertStmt = $this->pdo->prepare("
                INSERT INTO attachments (email_id, original_filename, stored_filename, filepath, mime_type, filesize, created_at)
                VALUES (?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP)
            ");

            foreach ($draftAttachments as $attachment) {
                foreach ($targetEmailIds as $targetEmailId) {
                    $existingStmt = $this->pdo->prepare("
                        SELECT id FROM attachments
                        WHERE email_id = ? AND stored_filename = ?
                        LIMIT 1
                    ");
                    $existingStmt->execute([$targetEmailId, $attachment['stored_filename']]);
                    if ($existingStmt->fetchColumn()) {
                        continue;
                    }

                    $insertStmt->execute([
                        $targetEmailId,
                        $attachment['original_filename'],
                        $attachment['stored_filename'],
                        $attachment['filepath'],
                        $attachment['mime_type'],
                        $attachment['filesize'],
                    ]);
                }
            }

            $this->emailModel->permanentDelete($draftId, $userId);
            $this->pdo->commit();

            return count($draftAttachments);
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Batch Mark Read / Unread with strict user ownership enforcement
     *
     * @param int $userId
     * @param array $emailIds
     * @param bool $isRead
     * @return int Count of affected emails
     */
    public function batchMarkRead(int $userId, array $emailIds, bool $isRead): int
    {
        $validIds = $this->filterValidIds($emailIds);
        if (empty($validIds)) {
            return 0;
        }

        $placeholders = implode(',', array_fill(0, count($validIds), '?'));
        $sql = "
            UPDATE emails 
            SET is_read = ?, updated_at = NOW() 
            WHERE id IN ({$placeholders}) 
              AND (recipient_id = ? OR (sender_id = ? AND recipient_id IS NULL))
        ";

        $params = array_merge([$isRead ? 1 : 0], $validIds, [$userId, $userId]);
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        return $stmt->rowCount();
    }

    /**
     * Batch Move Folder with strict user ownership enforcement
     *
     * @param int $userId
     * @param array $emailIds
     * @param string $targetFolder 'inbox', 'sent', 'draft', 'trash', 'spam'
     * @return int Count of affected emails
     */
    public function batchMoveFolder(int $userId, array $emailIds, string $targetFolder): int
    {
        $validFolders = ['inbox', 'sent', 'draft', 'trash', 'spam'];
        if (!in_array($targetFolder, $validFolders, true)) {
            throw new InvalidArgumentException("Invalid target folder: {$targetFolder}");
        }

        $validIds = $this->filterValidIds($emailIds);
        if (empty($validIds)) {
            return 0;
        }

        $placeholders = implode(',', array_fill(0, count($validIds), '?'));

        if ($targetFolder === 'trash') {
            $sql = "
                UPDATE emails 
                SET folder = 'trash', deleted_at = NOW(), updated_at = NOW() 
                WHERE id IN ({$placeholders}) 
                  AND (recipient_id = ? OR (sender_id = ? AND recipient_id IS NULL))
            ";
            $params = array_merge($validIds, [$userId, $userId]);
        } else {
            $sql = "
                UPDATE emails 
                SET folder = ?, deleted_at = NULL, updated_at = NOW() 
                WHERE id IN ({$placeholders}) 
                  AND (recipient_id = ? OR (sender_id = ? AND recipient_id IS NULL))
            ";
            $params = array_merge([$targetFolder], $validIds, [$userId, $userId]);
        }

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        return $stmt->rowCount();
    }

    /**
     * Batch Delete (soft delete to trash or permanent removal)
     *
     * @param int $userId
     * @param array $emailIds
     * @param bool $permanent
     * @return int Count of deleted emails
     */
    public function batchDelete(int $userId, array $emailIds, bool $permanent = false): int
    {
        $validIds = $this->filterValidIds($emailIds);
        if (empty($validIds)) {
            return 0;
        }

        $placeholders = implode(',', array_fill(0, count($validIds), '?'));

        if ($permanent) {
            $sql = "
                DELETE FROM emails 
                WHERE id IN ({$placeholders}) 
                  AND (recipient_id = ? OR (sender_id = ? AND recipient_id IS NULL))
            ";
            $params = array_merge($validIds, [$userId, $userId]);
        } else {
            // Soft delete: move to trash with deleted_at timestamp
            $sql = "
                UPDATE emails 
                SET folder = 'trash', deleted_at = NOW(), updated_at = NOW() 
                WHERE id IN ({$placeholders}) 
                  AND (recipient_id = ? OR (sender_id = ? AND recipient_id IS NULL))
            ";
            $params = array_merge($validIds, [$userId, $userId]);
        }

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        return $stmt->rowCount();
    }

    /**
     * Batch Toggle Starred Status with strict user ownership enforcement
     *
     * @param int $userId
     * @param array $emailIds
     * @param bool $isStarred
     * @return int Count of updated emails
     */
    public function batchToggleStar(int $userId, array $emailIds, bool $isStarred): int
    {
        $validIds = $this->filterValidIds($emailIds);
        if (empty($validIds)) {
            return 0;
        }

        $placeholders = implode(',', array_fill(0, count($validIds), '?'));
        $sql = "
            UPDATE emails 
            SET is_starred = ?, updated_at = NOW() 
            WHERE id IN ({$placeholders}) 
              AND (recipient_id = ? OR (sender_id = ? AND recipient_id IS NULL))
        ";

        $params = array_merge([$isStarred ? 1 : 0], $validIds, [$userId, $userId]);
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        return $stmt->rowCount();
    }

    /**
     * Archive or unarchive messages without deleting them.
     */
    public function batchArchive(int $userId, array $emailIds, bool $archive = true): int
    {
        $validIds = $this->filterValidIds($emailIds);
        if (empty($validIds)) {
            return 0;
        }

        $placeholders = implode(',', array_fill(0, count($validIds), '?'));
        $sql = "
            UPDATE emails
            SET is_archived = ?, archived_at = " . ($archive ? 'NOW()' : 'NULL') . ",
                snoozed_until = NULL, updated_at = NOW()
            WHERE id IN ({$placeholders})
              AND (recipient_id = ? OR (sender_id = ? AND recipient_id IS NULL))
        ";
        $params = array_merge([$archive ? 1 : 0], $validIds, [$userId, $userId]);
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->rowCount();
    }

    /**
     * Snooze messages until a future timestamp. They return to Inbox automatically
     * because Inbox filters out future snoozes.
     */
    public function batchSnooze(int $userId, array $emailIds, string $until): int
    {
        $normalized = trim($until);
        if (str_contains($normalized, 'T')) {
            $normalized = str_replace('T', ' ', $normalized);
        }
        $timestamp = strtotime($normalized);
        if ($timestamp === false || $timestamp <= time() || $timestamp > strtotime('+1 year')) {
            throw new InvalidArgumentException('Choose a snooze time between now and one year from now.');
        }
        $untilSql = date('Y-m-d H:i:s', $timestamp);

        $validIds = $this->filterValidIds($emailIds);
        if (empty($validIds)) {
            return 0;
        }

        $placeholders = implode(',', array_fill(0, count($validIds), '?'));
        $sql = "
            UPDATE emails
            SET snoozed_until = ?, is_archived = 0, archived_at = NULL,
                custom_folder_id = NULL, updated_at = NOW()
            WHERE id IN ({$placeholders})
              AND (recipient_id = ? OR (sender_id = ? AND recipient_id IS NULL))
        ";
        $params = array_merge([$untilSql], $validIds, [$userId, $userId]);
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->rowCount();
    }

    /**
     * Return snoozed messages to the Inbox immediately.
     */
    public function batchUnsnooze(int $userId, array $emailIds): int
    {
        $validIds = $this->filterValidIds($emailIds);
        if (empty($validIds)) {
            return 0;
        }

        $placeholders = implode(',', array_fill(0, count($validIds), '?'));
        $sql = "
            UPDATE emails
            SET snoozed_until = NULL, updated_at = NOW()
            WHERE id IN ({$placeholders})
              AND (recipient_id = ? OR (sender_id = ? AND recipient_id IS NULL))
        ";
        $params = array_merge($validIds, [$userId, $userId]);
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->rowCount();
    }

    /**
     * Advanced Search Query
     *
     * @param int $userId
     * @param string $folder 'inbox', 'sent', 'draft', 'trash', 'spam', 'starred', 'all'
     * @param array $criteria ['q', 'date_from', 'date_to', 'has_attachment', 'is_unread', 'is_starred']
     * @param int $page
     * @param int $perPage
     * @return array ['total' => N, 'page' => N, 'per_page' => N, 'total_pages' => N, 'emails' => [...]]
     */
    public function searchEmails(
        int $userId,
        string $folder = 'inbox',
        array $criteria = [],
        int $page = 1,
        int $perPage = 25
    ): array {
        $page = max(1, $page);
        $perPage = max(1, min(100, $perPage));
        $offset = ($page - 1) * $perPage;

        $where = [];
        $params = [];

        // 1. Folder scoping & ownership enforcement
        $folder = strtolower(trim($folder));
        switch ($folder) {
            case 'inbox':
                $where[] = "e.recipient_id = ?";
                $params[] = $userId;
                $where[] = "e.folder = 'inbox'";
                $where[] = "e.deleted_at IS NULL";
                $where[] = "e.is_archived = 0";
                $where[] = "e.custom_folder_id IS NULL";
                $where[] = "(e.snoozed_until IS NULL OR e.snoozed_until <= NOW())";
                break;

            case 'sent':
                $where[] = "e.sender_id = ?";
                $params[] = $userId;
                $where[] = "e.folder = 'sent'";
                $where[] = "e.deleted_at IS NULL";
                $where[] = "e.custom_folder_id IS NULL";
                break;

            case 'draft':
            case 'drafts':
                $where[] = "e.sender_id = ?";
                $params[] = $userId;
                $where[] = "e.folder = 'draft'";
                $where[] = "e.deleted_at IS NULL";
                break;

            case 'trash':
                $where[] = "(e.recipient_id = ? OR (e.sender_id = ? AND e.recipient_id IS NULL))";
                $params[] = $userId;
                $params[] = $userId;
                $where[] = "(e.folder = 'trash' OR e.deleted_at IS NOT NULL)";
                break;

            case 'spam':
                $where[] = "e.recipient_id = ?";
                $params[] = $userId;
                $where[] = "e.folder = 'spam'";
                $where[] = "e.deleted_at IS NULL";
                break;

            case 'starred':
                $where[] = "(e.recipient_id = ? OR (e.sender_id = ? AND e.recipient_id IS NULL))";
                $params[] = $userId;
                $params[] = $userId;
                $where[] = "e.is_starred = 1";
                $where[] = "e.deleted_at IS NULL";
                break;

            case 'archive':
            case 'archived':
                $where[] = "(e.recipient_id = ? OR (e.sender_id = ? AND e.recipient_id IS NULL))";
                $params[] = $userId;
                $params[] = $userId;
                $where[] = "e.is_archived = 1";
                $where[] = "e.deleted_at IS NULL";
                break;

            case 'snoozed':
                $where[] = "e.recipient_id = ?";
                $params[] = $userId;
                $where[] = "e.folder = 'inbox'";
                $where[] = "e.deleted_at IS NULL";
                $where[] = "e.snoozed_until IS NOT NULL AND e.snoozed_until > NOW()";
                break;

            case 'custom':
                $customFolderId = (int)($criteria['folder_id'] ?? 0);
                $where[] = "(e.recipient_id = ? OR (e.sender_id = ? AND e.recipient_id IS NULL))";
                $params[] = $userId;
                $params[] = $userId;
                $where[] = "e.custom_folder_id = ?";
                $params[] = $customFolderId;
                $where[] = "e.deleted_at IS NULL";
                break;

            case 'label':
                $labelId = (int)($criteria['label_id'] ?? 0);
                $where[] = "(e.recipient_id = ? OR (e.sender_id = ? AND e.recipient_id IS NULL))";
                $params[] = $userId;
                $params[] = $userId;
                $where[] = "EXISTS (
                    SELECT 1 FROM email_labels el
                    JOIN labels l ON l.id = el.label_id
                    WHERE el.email_id = e.id AND el.label_id = ? AND l.user_id = ?
                )";
                $params[] = $labelId;
                $params[] = $userId;
                $where[] = "e.deleted_at IS NULL";
                break;

            case 'all':
            default:
                $where[] = "(e.recipient_id = ? OR (e.sender_id = ? AND e.recipient_id IS NULL))";
                $params[] = $userId;
                $params[] = $userId;
                $where[] = "e.deleted_at IS NULL";
                break;
        }

        // 2. Keyword Filter (q)
        if (!empty($criteria['q'])) {
            $keyword = '%' . trim($criteria['q']) . '%';
            $where[] = "(
                e.subject LIKE ? 
                OR e.body LIKE ? 
                OR s.first_name LIKE ? 
                OR s.last_name LIKE ? 
                OR s.email LIKE ? 
                OR r.email LIKE ? 
                OR EXISTS (
                    SELECT 1 FROM email_recipients er 
                    WHERE er.email_id = e.id AND er.email_address LIKE ?
                )
            )";
            $params[] = $keyword;
            $params[] = $keyword;
            $params[] = $keyword;
            $params[] = $keyword;
            $params[] = $keyword;
            $params[] = $keyword;
            $params[] = $keyword;
        }

        // 3. Date From Filter
        if (!empty($criteria['date_from'])) {
            $dateFrom = trim($criteria['date_from']);
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom)) {
                $where[] = "e.created_at >= ?";
                $params[] = $dateFrom . ' 00:00:00';
            }
        }

        // 4. Date To Filter
        if (!empty($criteria['date_to'])) {
            $dateTo = trim($criteria['date_to']);
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo)) {
                $where[] = "e.created_at <= ?";
                $params[] = $dateTo . ' 23:59:59';
            }
        }

        // 5. Has Attachment Filter
        if (isset($criteria['has_attachment']) && $criteria['has_attachment'] !== '') {
            $hasAtt = filter_var($criteria['has_attachment'], FILTER_VALIDATE_BOOLEAN);
            if ($hasAtt) {
                $where[] = "EXISTS (SELECT 1 FROM attachments att WHERE att.email_id = e.id)";
            } else {
                $where[] = "NOT EXISTS (SELECT 1 FROM attachments att WHERE att.email_id = e.id)";
            }
        }

        // 6. Unread Filter
        if (isset($criteria['is_unread']) && $criteria['is_unread'] !== '') {
            $isUnread = filter_var($criteria['is_unread'], FILTER_VALIDATE_BOOLEAN);
            if ($isUnread) {
                $where[] = "e.is_read = 0";
            } else {
                $where[] = "e.is_read = 1";
            }
        }

        // 7. Starred Filter
        if (isset($criteria['is_starred']) && $criteria['is_starred'] !== '') {
            $isStarred = filter_var($criteria['is_starred'], FILTER_VALIDATE_BOOLEAN);
            if ($isStarred) {
                $where[] = "e.is_starred = 1";
            } else {
                $where[] = "e.is_starred = 0";
            }
        }

        $whereClause = implode(' AND ', $where);

        // Count total matching
        $countSql = "
            SELECT COUNT(*) 
            FROM emails e
            LEFT JOIN users s ON e.sender_id = s.id
            LEFT JOIN users r ON e.recipient_id = r.id
            WHERE {$whereClause}
        ";
        $countStmt = $this->pdo->prepare($countSql);
        $countStmt->execute($params);
        $total = (int)$countStmt->fetchColumn();

        $totalPages = $total > 0 ? (int)ceil($total / $perPage) : 1;

        // Fetch paginated results
        $listSql = "
            SELECT 
                e.*,
                COALESCE(s.first_name, SUBSTRING_INDEX(e.sender_name_snapshot, ' ', 1)) AS sender_first_name,
                COALESCE(s.last_name, SUBSTRING_INDEX(e.sender_name_snapshot, ' ', -1)) AS sender_last_name,
                COALESCE(s.email, e.sender_email_snapshot) AS sender_email,
                r.first_name AS recipient_first_name,
                r.last_name AS recipient_last_name,
                r.email AS recipient_email,
                (SELECT COUNT(*) FROM attachments att WHERE att.email_id = e.id) AS attachment_count,
                (SELECT GROUP_CONCAT(email_address SEPARATOR ', ') 
                 FROM email_recipients er 
                 WHERE er.email_id = e.id AND er.recipient_type = 'to') AS to_recipients
            FROM emails e
            LEFT JOIN users s ON e.sender_id = s.id
            LEFT JOIN users r ON e.recipient_id = r.id
            WHERE {$whereClause}
            ORDER BY e.created_at DESC
            LIMIT {$perPage} OFFSET {$offset}
        ";
        $listStmt = $this->pdo->prepare($listSql);
        $listStmt->execute($params);
        $emails = $listStmt->fetchAll();

        // Attach label chips & attachment metadata in one query per page.
        if (!empty($emails)) {
            $organization = new OrganizationService($this->pdo);
            $labelMap = $organization->getLabelsForEmails(array_column($emails, 'id'), $userId);

            $emailIds = array_column($emails, 'id');
            $inClause = implode(',', array_fill(0, count($emailIds), '?'));
            $attStmt = $this->pdo->prepare("SELECT id, email_id, original_filename, filesize, mime_type FROM attachments WHERE email_id IN ($inClause)");
            $attStmt->execute($emailIds);
            $attMap = [];
            while ($att = $attStmt->fetch()) {
                $attMap[(int)$att['email_id']][] = $att;
            }

            foreach ($emails as &$emailRow) {
                $emailRow['labels'] = $labelMap[(int)$emailRow['id']] ?? [];
                $emailRow['attachments'] = $attMap[(int)$emailRow['id']] ?? [];
            }
            unset($emailRow);
        }

        return [
            'total'       => $total,
            'page'        => $page,
            'per_page'    => $perPage,
            'total_pages' => $totalPages,
            'emails'      => $emails,
        ];
    }

    /**
     * Save recipients array to email_recipients table
     */
    protected function saveRecipientsList(int $emailId, array $recipients): void
    {
        foreach (['to', 'cc', 'bcc'] as $type) {
            if (!empty($recipients[$type]) && is_array($recipients[$type])) {
                foreach ($recipients[$type] as $emailAddr) {
                    $emailAddr = trim(strtolower($emailAddr));
                    if (!filter_var($emailAddr, FILTER_VALIDATE_EMAIL)) {
                        continue;
                    }
                    $targetUser = $this->userModel->findByEmail($emailAddr);
                    $targetId = $targetUser ? (int)$targetUser['id'] : null;
                    $this->emailModel->addRecipient($emailId, $targetId, $emailAddr, $type);
                }
            }
        }
    }

    /**
     * Normalize recipient input into array of valid email addresses
     */
    protected function normalizeRecipientList($input): array
    {
        if (is_string($input)) {
            $list = array_filter(array_map('trim', explode(',', $input)));
        } elseif (is_array($input)) {
            $list = array_filter(array_map('trim', $input));
        } else {
            return [];
        }

        $valid = [];
        foreach ($list as $email) {
            $email = strtolower($email);
            if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $valid[] = $email;
            }
        }
        return array_values(array_unique($valid));
    }

    /**
     * Process uploaded file array for draft or sent email
     */
    protected function processUploadedFiles(int $emailId, array $files): void
    {
        if (empty($files)) {
            return;
        }

        // Support standard $_FILES structure with multiple files
        if (isset($files['name']) && is_array($files['name'])) {
            $count = count($files['name']);
            for ($i = 0; $i < $count; $i++) {
                if (($files['error'][$i] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
                    $fileItem = [
                        'name'     => $files['name'][$i],
                        'type'     => $files['type'][$i] ?? '',
                        'tmp_name' => $files['tmp_name'][$i],
                        'error'    => $files['error'][$i],
                        'size'     => $files['size'][$i],
                    ];
                    $this->attachmentService->storeUploadedFile($emailId, $fileItem);
                }
            }
        } elseif (isset($files['name']) && is_string($files['name'])) {
            // Single file upload array
            if (($files['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
                $this->attachmentService->storeUploadedFile($emailId, $files);
            }
        } elseif (is_array($files)) {
            // Array of file items
            foreach ($files as $fileItem) {
                if (is_array($fileItem) && ($fileItem['error'] ?? null) === UPLOAD_ERR_OK) {
                    $this->attachmentService->storeUploadedFile($emailId, $fileItem);
                }
            }
        }
    }

    /**
     * Sanitize array of integer IDs
     */
    protected function filterValidIds(array $ids): array
    {
        $filtered = [];
        foreach ($ids as $id) {
            $intId = (int)$id;
            if ($intId > 0) {
                $filtered[] = $intId;
            }
        }
        return array_values(array_unique($filtered));
    }
}