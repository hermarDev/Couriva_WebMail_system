<?php
/**
 * Couriva - Email Controller
 */

namespace App\Controllers;

use App\Models\Email;
use App\Services\MailService;
use App\Services\AttachmentService;
use PDO;

class EmailController
{
    protected PDO $pdo;
    protected Email $emailModel;
    protected MailService $mailService;
    protected AttachmentService $attachmentService;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
        $this->emailModel = new Email($pdo);
        $this->mailService = new MailService($pdo);
        $this->attachmentService = new AttachmentService($pdo);
    }

    public function index(int $userId, string $folder = 'inbox', array $criteria = [], int $page = 1, int $perPage = 25): array
    {
        return $this->mailService->searchEmails($userId, $folder, $criteria, $page, $perPage);
    }

    public function show(int $emailId, int $userId): ?array
    {
        $email = $this->emailModel->findById($emailId);
        if (!$email) {
            return null;
        }

        // Strict ownership check
        if ((int)$email['sender_id'] !== $userId && (int)($email['recipient_id'] ?? 0) !== $userId) {
            return null;
        }

        return $email;
    }

    public function batch(int $userId, string $action, array $emailIds): int
    {
        switch ($action) {
            case 'mark_read':
                return $this->mailService->batchMarkRead($userId, $emailIds, true);
            case 'mark_unread':
                return $this->mailService->batchMarkRead($userId, $emailIds, false);
            case 'trash':
                return $this->mailService->batchMoveFolder($userId, $emailIds, 'trash');
            case 'spam':
                return $this->mailService->batchMoveFolder($userId, $emailIds, 'spam');
            case 'restore':
                return $this->mailService->batchMoveFolder($userId, $emailIds, 'inbox');
            case 'permanent_delete':
                return $this->mailService->batchDelete($userId, $emailIds, true);
            case 'star':
                return $this->mailService->batchToggleStar($userId, $emailIds, true);
            case 'unstar':
                return $this->mailService->batchToggleStar($userId, $emailIds, false);
            default:
                return 0;
        }
    }

    public function saveDraft(int $userId, ?int $draftId, array $data, array $files = []): int
    {
        return $this->mailService->saveOrUpdateDraft($userId, $draftId, $data, $files);
    }
}
