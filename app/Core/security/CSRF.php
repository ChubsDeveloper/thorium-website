<?php
declare(strict_types=1);

namespace App\Core\Security;

class csrf
{
    private const TOKEN_NAME = '_csrf';

    public function generateToken(): string
    {
        if (empty($_SESSION[self::TOKEN_NAME])) {
            $_SESSION[self::TOKEN_NAME] = bin2hex(random_bytes(32));
        }
        return $_SESSION[self::TOKEN_NAME];
    }

    public function getToken(): string
    {
        return $_SESSION[self::TOKEN_NAME] ?? '';
    }

    public function validateToken(string $token): bool
    {
        $session_token = $_SESSION[self::TOKEN_NAME] ?? '';
        return $session_token !== '' && hash_equals($session_token, $token);
    }
}
