<?php
/**
 * Couriva - Contact Controller
 */

namespace App\Controllers;

use App\Models\Contact;
use PDO;

class ContactController
{
    protected PDO $pdo;
    protected Contact $contactModel;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
        $this->contactModel = new Contact($pdo);
    }

    public function list(int $userId, ?string $search = null): array
    {
        return $this->contactModel->getByUserId($userId, $search);
    }
}
