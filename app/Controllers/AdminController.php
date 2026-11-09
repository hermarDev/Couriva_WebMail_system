<?php
/**
 * Couriva - Admin Controller
 */

namespace App\Controllers;

use App\Models\User;
use PDO;

class AdminController
{
    protected PDO $pdo;
    protected User $userModel;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
        $this->userModel = new User($pdo);
    }

    public function dashboard(): array
    {
        $totalUsers = $this->userModel->countAll();
        $activeUsers = $this->userModel->countAll('active');
        $disabledUsers = $this->userModel->countAll('disabled');

        $stmt = $this->pdo->query("SELECT COUNT(*) FROM emails");
        $totalEmails = (int)$stmt->fetchColumn();

        $stmt = $this->pdo->query("SELECT COALESCE(SUM(filesize), 0) FROM attachments");
        $totalAttachmentBytes = (int)$stmt->fetchColumn();

        return [
            'total_users'            => $totalUsers,
            'active_users'           => $activeUsers,
            'disabled_users'         => $disabledUsers,
            'total_emails'           => $totalEmails,
            'total_attachment_bytes' => $totalAttachmentBytes,
        ];
    }
}
