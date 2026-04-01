<?php
declare(strict_types=1);

namespace App\Core\Security;

class auth
{
    private $app;

    public function __construct($app)
    {
        $this->app = $app;
    }

    public function getCurrentUser(): ?array
    {
        return $_SESSION['user'] ?? null;
    }

    public function isLoggedIn(): bool
    {
        return $this->getCurrentUser() !== null;
    }

    public function isAdmin(): bool
    {
        $user = $this->getCurrentUser();
        return $user && !empty($user['is_admin']);
    }
}
