<?php
/**
 * PayPal Service
 * 
 * Comprehensive PayPal API integration service handling order creation, capture,
 * webhook verification, and secure payment processing. Supports both sandbox
 * and production environments with proper error handling and request idempotency.
 */
declare(strict_types=1);

namespace App\Services;

use Exception;

class PayPalService
{
    private array $config;
    private ?string $accessToken = null;

    /**
     * Initialize PayPal service with configuration
     * 
     * Required config keys:
     * - api_base: PayPal API base URL (sandbox or production)
     * - client_id: PayPal application client ID
     * - client_secret: PayPal application client secret
     * 
     * Optional config keys:
     * - webhook_id: Webhook ID for signature verification
     * - use_custom_id: Whether to include custom ID in orders (default: true)
     * 
     * @param array $config PayPal configuration array
     * @throws Exception When required configuration is missing
     */
    public function __construct(array $config)
    {
        foreach (['api_base','client_id','client_secret'] as $k) {
            if (empty($config[$k])) {
                throw new Exception("PayPal config '$k' is missing.");
            }
        }
        $this->config = $config + ['use_custom_id' => true];
    }

    /**
     * Create PayPal order with capture intent
     * 
     * @param float $amount Order amount
     * @param string $currency Currency code (default: USD)
     * @param array $metadata Additional metadata (user_id, package_sku, points)
     * @return array PayPal order response
     * @throws Exception On API errors or configuration issues
     */
    public function createOrder(float $amount, string $currency = 'USD', array $metadata = []): array
    {
        $url   = rtrim($this->config['api_base'], '/') . '/v2/checkout/orders';
        $token = $this->getAccessToken();

        // Strong idempotency key to avoid duplicates across retries
        $requestId = $this->makeRequestId('ord', $metadata['user_id'] ?? null);

        $headers = [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $token,
            'PayPal-Request-Id: ' . $requestId,
        ];

        [$returnUrl, $cancelUrl] = $this->buildReturnCancelUrls();

        // Safe description (≤127 chars, ASCII)
        $brand = $GLOBALS['config']['server_name'] ?? 'Thorium WoW';
        $description = $this->ascii(substr('Donation to ' . $brand, 0, 127));

        // Optional, compact custom_id (≤127 chars; safe chars only)
        $customId = $this->config['use_custom_id'] ? $this->buildCustomId($metadata) : null;

        $purchaseUnit = [
            'amount' => [
                'currency_code' => strtoupper($currency),
                'value'         => number_format($amount, 2, '.', ''),
            ],
            'description' => $description,
        ];
        if ($customId !== null && $customId !== '') {
            $purchaseUnit['custom_id'] = $customId;
        }

        $orderData = [
            'intent'         => 'CAPTURE',
            'purchase_units' => [ $purchaseUnit ],
            'application_context' => [
                'return_url'   => $returnUrl,
                'cancel_url'   => $cancelUrl,
                'brand_name'   => $brand,
                'landing_page' => 'BILLING',
                'user_action'  => 'PAY_NOW',
            ],
        ];

        error_log("PayPal createOrder → {$amount} {$currency}, req={$requestId}");

        return $this->makeRequest($url, 'POST', $headers, json_encode($orderData, JSON_UNESCAPED_SLASHES));
    }

    /**
     * Capture funds from an approved PayPal order
     * 
     * @param string $orderId PayPal order ID to capture
     * @return array PayPal capture response
     * @throws Exception When order ID is empty or API call fails
     */
    public function captureOrder(string $orderId): array
    {
        if ($orderId === '') throw new Exception('Cannot capture without orderId.');
        $url   = rtrim($this->config['api_base'], '/') . '/v2/checkout/orders/' . rawurlencode($orderId) . '/capture';
        $token = $this->getAccessToken();

        $headers = [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $token,
            'PayPal-Request-Id: ' . $this->makeRequestId('cap', $orderId),
        ];
        return $this->makeRequest($url, 'POST', $headers, '{}');
    }

    /**
     * Retrieve order details from PayPal
     * 
     * @param string $orderId PayPal order ID
     * @return array Order details
     * @throws Exception On API errors
     */
    public function getOrder(string $orderId): array
    {
        $url   = rtrim($this->config['api_base'], '/') . '/v2/checkout/orders/' . rawurlencode($orderId);
        $token = $this->getAccessToken();
        $headers = ['Content-Type: application/json','Authorization: Bearer ' . $token];
        return $this->makeRequest($url, 'GET', $headers);
    }

    /**
     * Extract checkout approval URL from order response
     * 
     * @param array $order PayPal order response
     * @return string|null Approval URL for customer checkout
     */
    public function getCheckoutUrl(array $order): ?string
    {
        foreach ($order['links'] ?? [] as $link) {
            if (($link['rel'] ?? '') === 'approve') {
                return $link['href'] ?? null;
            }
        }
        return null;
    }

    /**
     * Verify PayPal webhook signature for security
     * 
     * @param string $payload Raw webhook payload
     * @param array $headers HTTP headers from webhook request
     * @return bool True if signature is valid
     */
    public function verifyWebhook(string $payload, array $headers): bool
    {
        if (empty($this->config['webhook_id'])) return false;

        $h = [];
        foreach ($headers as $k => $v) $h[strtolower($k)] = $v;

        foreach (['paypal-transmission-id','paypal-transmission-time','paypal-transmission-sig','paypal-cert-url','paypal-auth-algo'] as $need) {
            if (empty($h[$need])) return false;
        }

        $token = $this->getAccessToken();
        $url   = rtrim($this->config['api_base'], '/') . '/v1/notifications/verify-webhook-signature';
        $body  = [
            'transmission_id'   => $h['paypal-transmission-id'],
            'transmission_time' => $h['paypal-transmission-time'],
            'transmission_sig'  => $h['paypal-transmission-sig'],
            'cert_url'          => $h['paypal-cert-url'],
            'auth_algo'         => $h['paypal-auth-algo'],
            'webhook_id'        => $this->config['webhook_id'],
            'webhook_event'     => json_decode($payload, true),
        ];

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json','Authorization: Bearer ' . $token],
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($body, JSON_UNESCAPED_SLASHES),
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_USERAGENT      => 'ThoriumWoW/1.0',
        ]);
        $resp = curl_exec($ch);
        if ($resp === false) { curl_close($ch); return false; }
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $j = json_decode($resp, true);
        return ($code === 200 && isset($j['verification_status']) && strtoupper((string)$j['verification_status']) === 'SUCCESS');
    }

    /*  */

    private function getAccessToken(): string
    {
        if ($this->accessToken) return $this->accessToken;

        $url         = rtrim($this->config['api_base'], '/') . '/v1/oauth2/token';
        $credentials = base64_encode($this->config['client_id'] . ':' . $this->config['client_secret']);

        $headers = [
            'Accept: application/json',
            'Accept-Language: en_US',
            'Authorization: Basic ' . $credentials,
            'Content-Type: application/x-www-form-urlencoded',
        ];

        $response = $this->makeRequest($url, 'POST', $headers, 'grant_type=client_credentials');
        if (empty($response['access_token'])) {
            throw new Exception('Failed to get PayPal access token.');
        }
        return $this->accessToken = $response['access_token'];
    }

    /** Build compact, safe custom_id (≤127 chars; ASCII; [A-Za-z0-9._-]). */
    private function buildCustomId(array $meta): ?string
    {
        if (!$meta) return null;

        $parts = [];
        if (isset($meta['user_id']))     $parts[] = 'u'   . $this->onlySafe($meta['user_id']);
        if (isset($meta['package_sku'])) $parts[] = 'sku' . $this->onlySafe($meta['package_sku']);
        if (isset($meta['points']))      $parts[] = 'p'   . (int)$meta['points'];
        // tiny disambiguator
        $parts[] = 't' . $this->shortToken(4);

        // Join with '-' to avoid illegal characters
        $cid = implode('-', array_filter($parts));
        $cid = substr($cid, 0, 127);
        return $cid !== '' ? $cid : null;
    }

    /** Strong idempotency key (accepts int|string|null). */
    private function makeRequestId(string $prefix, $hint = null): string
    {
        $hintStr = ($hint === null) ? 'na' : $this->slug($hint);
        return "{$prefix}_{$hintStr}_" . $this->shortToken(9);
    }

    private function shortToken(int $bytes = 6): string
    {
        $raw = random_bytes(max(2, $bytes));
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    private function ascii(string $s): string
    {
        // Best-effort ASCII (strip non-ASCII)
        return preg_replace('/[^\x20-\x7E]/', '', $s) ?? '';
    }

    private function onlySafe(mixed $v): string
    {
        $s = is_scalar($v) ? (string)$v : json_encode($v, JSON_UNESCAPED_SLASHES);
        $s = $this->ascii($s);
        // Keep only A-Z a-z 0-9 . _ -
        $s = preg_replace('/[^A-Za-z0-9._-]/', '', $s) ?? '';
        return $s;
    }

    private function slug(mixed $v): string
    {
        $s = is_scalar($v) ? (string)$v : json_encode($v, JSON_UNESCAPED_SLASHES);
        $s = preg_replace('~[^A-Za-z0-9]+~', '-', $s);
        $s = trim($s ?? '', '-');
        return $s ?: 'x';
    }

    private function buildReturnCancelUrls(): array
    {
        $baseUrl = $_ENV['BASE_URL'] ?? '';
        if (!$baseUrl) {
            $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
            $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
            $baseUrl = $scheme . '://' . $host;
        }
        if (!str_starts_with($baseUrl, 'http')) {
            $baseUrl = 'https://' . ltrim($baseUrl, '/');
        }
        $returnUrl = rtrim($baseUrl, '/') . '/api/paypal/success.php';
        $cancelUrl = rtrim($baseUrl, '/') . '/donate?cancelled=1';
        return [$returnUrl, $cancelUrl];
    }

    /** cURL wrapper with rich error propagation. */
    private function makeRequest(string $url, string $method, array $headers, ?string $data = null): array
    {
        $ch = curl_init($url);
        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST  => strtoupper($method),
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_USERAGENT      => 'ThoriumWoW/1.0',
        ];
        if ($data !== null) {
            $isForm = false;
            foreach ($headers as $h) {
                if (stripos($h, 'Content-Type: application/x-www-form-urlencoded') === 0) { $isForm = true; break; }
            }
            $opts[CURLOPT_POSTFIELDS] = $isForm ? $data : (is_string($data) ? $data : json_encode($data, JSON_UNESCAPED_SLASHES));
        }
        curl_setopt_array($ch, $opts);

        $resp = curl_exec($ch);
        if ($resp === false) {
            $err = curl_error($ch);
            curl_close($ch);
            throw new Exception('PayPal request failed: ' . $err);
        }
        $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $decoded = json_decode($resp, true);
        if ($decoded === null && json_last_error() !== JSON_ERROR_NONE) {
            throw new Exception('Invalid JSON from PayPal: ' . substr($resp, 0, 200));
        }

        if ($http < 200 || $http >= 300) {
            // Build a precise, dev-friendly error string
            $name = $decoded['name'] ?? $decoded['message'] ?? 'Unknown error';
            $detailStr = '';
            if (!empty($decoded['details'][0])) {
                $d = $decoded['details'][0];
                $bits = [];
                if (!empty($d['issue']))        $bits[] = 'issue=' . $d['issue'];
                if (!empty($d['description']))  $bits[] = 'desc=' . $d['description'];
                if (!empty($d['field']))        $bits[] = 'field=' . $d['field'];
                if (!empty($d['value']))        $bits[] = 'value=' . json_encode($d['value']);
                $detailStr = ' [' . implode('; ', $bits) . ']';
            }
            error_log("PayPal API Error: HTTP {$http} - {$name}{$detailStr}");
            if ($data) error_log("PayPal Request Body: " . (is_string($data) ? $data : json_encode($data, JSON_UNESCAPED_SLASHES)));
            throw new Exception("PayPal API error: {$name}{$detailStr} (HTTP {$http})");
        }

        return $decoded;
    }
}
