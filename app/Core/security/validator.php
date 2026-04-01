<?php
declare(strict_types=1);

namespace App\Core\Security;

class Validator
{
    public static function sanitizeString(string $input, int $maxLength = 255): string
    {
        return substr(trim($input), 0, $maxLength);
    }

    public static function sanitizeAlphanumeric(string $input): string
    {
        return preg_replace('/[^a-z0-9\-_]/i', '', $input);
    }

    public static function sanitizeEmail(string $email): string
    {
        return filter_var(trim($email), FILTER_SANITIZE_EMAIL);
    }

    public static function validateEmail(string $email): bool
    {
        return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
    }

    public static function validateUrl(string $url): bool
    {
        return filter_var($url, FILTER_VALIDATE_URL) !== false;
    }

    public static function validateInteger(string $value): bool
    {
        return filter_var($value, FILTER_VALIDATE_INT) !== false;
    }

    public static function validateRequired(array $data, array $required): array
    {
        $errors = [];
        foreach ($required as $field) {
            if (empty($data[$field])) {
                $errors[] = "Field '{$field}' is required";
            }
        }
        return $errors;
    }

    public static function hasSecurePath(string $path): bool
    {
        return strpos($path, '..') === false && 
               strpos($path, "\0") === false &&
               !preg_match('/[<>:"|?*]/', $path);
    }

    public static function escapeHtml(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }

    public static function validateJson(string $json): bool
    {
        json_decode($json);
        return json_last_error() === JSON_ERROR_NONE;
    }

    /**
     * Sanitize SQL-like input (additional layer beyond prepared statements)
     */
    public static function sanitizeSqlString(string $input): string
    {
        // Remove potential SQL keywords and suspicious patterns
        $dangerous = ['DROP', 'DELETE', 'UNION', 'INSERT', 'UPDATE', '--', '/*', '*/', 'xp_', 'sp_'];
        $input = str_ireplace($dangerous, '', $input);
        return trim($input);
    }

    /**
     * Validate file upload is safe
     */
    public static function validateFileUpload(array $file, array $allowedMimes = [], int $maxSize = 10485760): array
    {
        $errors = [];

        // Check for upload errors
        if ($file['error'] !== UPLOAD_ERR_OK) {
            $errors[] = 'File upload failed with error code ' . $file['error'];
            return $errors;
        }

        // Check file size
        if ($file['size'] > $maxSize) {
            $errors[] = 'File size exceeds maximum allowed size';
        }

        // Check MIME type
        if (!empty($allowedMimes)) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $mimeType = finfo_file($finfo, $file['tmp_name']);
            finfo_close($finfo);

            if (!in_array($mimeType, $allowedMimes, true)) {
                $errors[] = 'File type not allowed: ' . $mimeType;
            }
        }

        // Check for executable content
        if (self::isExecutableFile($file['tmp_name'])) {
            $errors[] = 'Executable files are not permitted';
        }

        return $errors;
    }

    /**
     * Check if uploaded file might be executable
     */
    private static function isExecutableFile(string $filepath): bool
    {
        $dangerous = ['php', 'php3', 'php4', 'php5', 'phtml', 'phar', 'exe', 'com', 'bat', 'sh'];
        $ext = strtolower(pathinfo($filepath, PATHINFO_EXTENSION));
        return in_array($ext, $dangerous, true);
    }

    /**
     * Validate username format
     */
    public static function validateUsername(string $username): bool
    {
        return preg_match('/^[a-zA-Z0-9_-]{3,20}$/', $username) === 1;
    }

    /**
     * Validate password strength
     */
    public static function validatePasswordStrength(string $password, int $minLength = 8): array
    {
        $errors = [];

        if (strlen($password) < $minLength) {
            $errors[] = "Password must be at least {$minLength} characters";
        }

        if (!preg_match('/[a-z]/', $password)) {
            $errors[] = 'Password must contain lowercase letters';
        }

        if (!preg_match('/[A-Z]/', $password)) {
            $errors[] = 'Password must contain uppercase letters';
        }

        if (!preg_match('/[0-9]/', $password)) {
            $errors[] = 'Password must contain numbers';
        }

        return $errors;
    }

    /**
     * Sanitize SQL array for IN clause (with prepared statements)
     */
    public static function prepareInClause(array $values, string $type = 'string'): array
    {
        $sanitized = [];
        foreach ($values as $value) {
            if ($type === 'int') {
                $sanitized[] = (int)$value;
            } else {
                $sanitized[] = (string)$value;
            }
        }
        return $sanitized;
    }

    /**
     * Check if string contains potential XSS vectors
     */
    public static function containsXssVector(string $input): bool
    {
        $xssPatterns = [
            '/<script[^>]*>.*?<\/script>/is',
            '/javascript:/i',
            '/on\w+\s*=/i',
            '/<iframe/i',
            '/<embed/i',
            '/<object/i',
        ];

        foreach ($xssPatterns as $pattern) {
            if (preg_match($pattern, $input)) {
                return true;
            }
        }

        return false;
    }
}
