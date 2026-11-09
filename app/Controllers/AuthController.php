<?php
/**
 * Couriva - Auth Controller
 */

namespace App\Controllers;

use App\Services\AuthService;
use PDO;

class AuthController
{
    protected PDO $pdo;
    protected AuthService $authService;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
        $this->authService = new AuthService($pdo);
    }

    public function login(): void
    {
        // Handled via public/login.php
    }

    public function logout(): void
    {
        // Handled via public/logout.php
    }
}
