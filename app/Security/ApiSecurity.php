<?php
declare(strict_types=1);

namespace App\Security;

/**
 * ApiSecurity - Secure API endpoints against tampering
 * Implements request signing and verification using HMAC-SHA256
 */
class ApiSecurity
{
    /**
     * Generate API request signature
     *
     * @param string $method HTTP method (GET, POST, etc.)
     * @param string $endpoint API endpoint path
     * @param array $data Request data/params
     * @param string $secret Shared secret key
     * @return string HMAC signature
     */
    public static function generateSignature(string $method, string $endpoint, array $data, string $secret): string
    {
        // Create canonical request string
        $sortedData = array_filter($data, fn($v) => $v !== null && $v !== '');
        ksort($sortedData);

        $canonical = sprintf(
            "%s|%s|%s|%d",
            strtoupper($method),
            $endpoint,
            json_encode($sortedData),
            time()
        );

        return hash_hmac('sha256', $canonical, $secret);
    }

    /**
     * Verify API request signature
     *
     * @param string $method HTTP method
     * @param string $endpoint API endpoint
     * @param array $data Request data
     * @param string $signature Signature to verify
     * @param string $secret Shared secret key
     * @param int $maxAgeSeconds Maximum age of signature (default 5 minutes)
     * @return bool True if signature is valid
     */
    public static function verifySignature(
        string $method,
        string $endpoint,
        array $data,
        string $signature,
        string $secret,
        int $maxAgeSeconds = 300
    ): bool {
        // Regenerate signature
        $expectedSignature = self::generateSignature($method, $endpoint, $data, $secret);

        // Use constant-time comparison to prevent timing attacks
        if (!hash_equals($expectedSignature, $signature)) {
            return false;
        }

        // Check timestamp is within acceptable window
        if (time() - intval($data['_timestamp'] ?? 0) > $maxAgeSeconds) {
            return false;
        }

        return true;
    }

    /**
     * Verify webhook signature (for PayPal, etc.)
     *
     * @param string $payload Raw webhook payload
     * @param array $headers Request headers
     * @param string $secret Webhook secret
     * @return bool True if webhook is legitimate
     */
    public static function verifyWebhookSignature(string $payload, array $headers, string $secret): bool
    {
        // Construct signature string according to service specification
        $signatureString = $payload . $secret;
        $expectedSignature = hash('sha256', $signatureString);

        $providedSignature = $headers['X-Webhook-Signature'] ?? $headers['x-webhook-signature'] ?? null;

        if (!$providedSignature) {
            return false;
        }

        return hash_equals($expectedSignature, $providedSignature);
    }

    /**
     * Generate JWT-like token for API authentication
     *
     * @param array $claims Token claims (user_id, permissions, etc.)
     * @param string $secret Secret key for signing
     * @param int $expiresIn Token expiration time in seconds
     * @return string Base64-encoded token
     */
    public static function generateToken(array $claims, string $secret, int $expiresIn = 3600): string
    {
        // Add standard claims
        $claims['iat'] = time();
        $claims['exp'] = time() + $expiresIn;

        $header = base64_encode(json_encode(['alg' => 'HS256', 'typ' => 'JWT']));
        $payload = base64_encode(json_encode($claims));

        $signature = base64_encode(
            hash_hmac('sha256', "{$header}.{$payload}", $secret, true)
        );

        return "{$header}.{$payload}.{$signature}";
    }

    /**
     * Verify and decode JWT token
     *
     * @param string $token JWT token
     * @param string $secret Secret key
     * @return array|null Decoded claims or null if invalid
     */
    public static function verifyToken(string $token, string $secret): ?array
    {
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            return null;
        }

        list($header, $payload, $signature) = $parts;

        // Verify signature
        $expectedSignature = base64_encode(
            hash_hmac('sha256', "{$header}.{$payload}", $secret, true)
        );

        if (!hash_equals($expectedSignature, $signature)) {
            return null;
        }

        // Decode payload
        $claims = json_decode(base64_decode($payload), true);

        if (!is_array($claims)) {
            return null;
        }

        // Check expiration
        if (isset($claims['exp']) && $claims['exp'] < time()) {
            return null;
        }

        return $claims;
    }

    /**
     * Sanitize API response to prevent information disclosure
     *
     * @param mixed $data Data to sanitize
     * @return mixed Sanitized data
     */
    public static function sanitizeResponse($data): mixed
    {
        if (is_array($data)) {
            return array_map([self::class, 'sanitizeResponse'], $data);
        }

        if (is_string($data)) {
            // Remove potentially sensitive strings
            $sensitive = [
                '/\/\/.+/m',  // Comments
                '/password/i',
                '/secret/i',
                '/token/i',
                '/api.?key/i',
            ];

            foreach ($sensitive as $pattern) {
                $data = preg_replace($pattern, '[REDACTED]', $data);
            }
        }

        return $data;
    }
}
