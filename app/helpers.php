<?php
/**
 * Global utility functions - HTML escaping, URL generation, and text formatting
 * Provides common helper functions used throughout the application
 */

// HTML escaping for safe output
function e($str) { 
    return htmlspecialchars($str ?? '', ENT_QUOTES, 'UTF-8'); 
}

// URL and routing helper functions
function current_slug(): string {
    $script = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '/index.php');
    $base   = rtrim(dirname($script), '/');
    $path   = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/';

    if ($base !== '' && $base !== '/' && str_starts_with($path, $base)) {
        $path = substr($path, strlen($base));
    }
    $seg = array_values(array_filter(explode('/', trim($path, '/'))));
    return $seg[0] ?? 'home';
}

function route_is(string $slug): bool { 
    return current_slug() === trim($slug, '/'); 
}

function active_class(string $slug): string {
    return route_is($slug) ? 'text-indigo-300' : 'text-neutral-300 hover:text-indigo-300';
}

// Database connection helper
function db_or_null(array $cfg) {
    try {
        $dsn = sprintf('mysql:host=%s;dbname=%s;charset=%s', $cfg['host'], $cfg['name'], $cfg['charset']);
        $pdo = new PDO($dsn, $cfg['user'], $cfg['pass'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        return $pdo;
    } catch (Throwable $e) {
        return null;
    }
}

// Text formatting utilities
function format_date(?string $dt): string {
    if (!$dt) return '';
    $ts = strtotime($dt);
    return $ts ? date('M j, Y', $ts) : $dt;
}

function excerpt(string $text, int $len = 160): string {
    $text = trim(strip_tags($text));
    if (mb_strlen($text) <= $len) return $text;
    return rtrim(mb_substr($text, 0, $len - 1)) . '…';
}

// URL generation utilities
function url_base_prefix(): string {
    $cfg = $GLOBALS['config'] ?? [];

    if (!empty($cfg['app_base']) && is_string($cfg['app_base'])) {
        $b = '/' . ltrim($cfg['app_base'], '/');
        return rtrim($b, '/');
    }

    $reqPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/';
    $script  = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '/index.php');

    foreach (['/public'] as $candidate) {
        if (str_starts_with($reqPath, $candidate . '/') || $reqPath === $candidate || str_starts_with($script, $candidate . '/')) {
            return $candidate;
        }
    }

    return '';
}

function base_url(string $path = ''): string {
    $base = url_base_prefix();
    $url  = $base . '/' . ltrim($path, '/');
    return preg_replace('~//+~', '/', $url);
}

function asset_url(string $path = ''): string {
    if (function_exists('theme_asset_url')) return theme_asset_url(ltrim($path, '/'));
    return base_url(ltrim($path, '/'));
}

// Advanced database and HTTP utilities
function pdo_server(array $conf): ?PDO {
    try {
        $dsn = "mysql:host={$conf['host']};charset={$conf['charset']}";
        $pdo = new PDO($dsn, $conf['user'], $conf['pass'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        return $pdo;
    } catch (Throwable $e) {
        return null;
    }
}

function redirect(string $url, int $code = 302): void {
    if (!headers_sent()) {
        header('Location: ' . $url, true, $code);
        exit;
    }
    $u = e($url);
    echo '<meta http-equiv="refresh" content="0;url='.$u.'">';
    echo '<script>location.href='.json_encode($url).'</script>';
    exit;
}

function site_origin(): string {
    $tls    = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['SERVER_PORT'] ?? '') == '443');
    $scheme = $tls ? 'https' : 'http';
    $host   = $_SERVER['HTTP_HOST']
           ?? (($_SERVER['SERVER_NAME'] ?? 'localhost') . (($_SERVER['SERVER_PORT'] ?? '') ? ':' . $_SERVER['SERVER_PORT'] : ''));
    return $scheme . '://' . $host;
}

function absolute_url(string $path = ''): string {
    $cfg = $GLOBALS['config'] ?? [];
    if (!empty($cfg['app_url'])) {
        return rtrim($cfg['app_url'], '/') . '/' . ltrim($path, '/');
    }
    $origin = rtrim(site_origin(), '/');
    $base   = url_base_prefix();
    return $origin . $base . '/' . ltrim($path, '/');
}

// ============================================================================
// SECURITY HELPERS - Keep application and user data safe
// ============================================================================

/**
 * Get client IP address (proxy-aware for Cloudflare, reverse proxies, etc.)
 */
function get_client_ip(): string {
    if (!empty($_SERVER['HTTP_CF_CONNECTING_IP'])) {
        return $_SERVER['HTTP_CF_CONNECTING_IP'];
    }
    if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        $ips = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
        return trim($ips[0]);
    }
    if (!empty($_SERVER['HTTP_X_FORWARDED_IP'])) {
        return $_SERVER['HTTP_X_FORWARDED_IP'];
    }
    return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
}

/**
 * Verify CSRF token from request
 */
function verify_csrf_token(string $tokenName = '_csrf'): bool {
    $sessionToken = $_SESSION[$tokenName] ?? null;

    // Check POST data
    $requestToken = $_POST[$tokenName] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? null;

    if (!$sessionToken || !$requestToken) {
        return false;
    }

    return hash_equals((string)$sessionToken, (string)$requestToken);
}

/**
 * Get CSRF token for forms
 */
function get_csrf_token(string $tokenName = '_csrf'): string {
    if (empty($_SESSION[$tokenName])) {
        try {
            $_SESSION[$tokenName] = bin2hex(random_bytes(32));
        } catch (Exception $e) {
            $_SESSION[$tokenName] = bin2hex(openssl_random_pseudo_bytes(32));
        }
    }
    return $_SESSION[$tokenName];
}

/**
 * Generate secure random string
 */
function generate_random_string(int $length = 32): string {
    try {
        return bin2hex(random_bytes($length / 2));
    } catch (Exception $e) {
        return bin2hex(openssl_random_pseudo_bytes($length / 2));
    }
}

/**
 * Hash password securely using bcrypt
 */
function hash_password(string $password): string {
    return password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
}

/**
 * Verify password against hash
 */
function verify_password(string $password, string $hash): bool {
    return password_verify($password, $hash);
}

/**
 * Sanitize string for safe output (alias for e())
 */
function safe_output($str): string {
    return htmlspecialchars($str ?? '', ENT_QUOTES, 'UTF-8');
}

/**
 * Log security event
 */
function log_security_event(string $type, string $message, array $context = []): void {
    $timestamp = date('Y-m-d H:i:s');
    $ip = get_client_ip();
    $userId = $_SESSION['user_id'] ?? 'guest';

    $logDir = dirname(__DIR__) . '/storage/logs';
    if (!is_dir($logDir)) {
        @mkdir($logDir, 0750, true);
    }

    $logEntry = sprintf(
        "[%s] [%s] IP:%s User:%s | %s | %s\n",
        $timestamp,
        strtoupper($type),
        $ip,
        $userId,
        $message,
        !empty($context) ? json_encode($context) : ''
    );

    @file_put_contents($logDir . '/security.log', $logEntry, FILE_APPEND | LOCK_EX);
}

/**
 * Check if request is HTTPS
 */
function is_https(): bool {
    if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
        return true;
    }
    if (isset($_SERVER['SERVER_PORT']) && (int)$_SERVER['SERVER_PORT'] === 443) {
        return true;
    }
    if (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && strpos($_SERVER['HTTP_X_FORWARDED_PROTO'], 'https') !== false) {
        return true;
    }
    if (!empty($_SERVER['HTTP_CF_VISITOR'])) {
        $visitor = json_decode($_SERVER['HTTP_CF_VISITOR'], true);
        if (is_array($visitor) && ($visitor['scheme'] ?? '') === 'https') {
            return true;
        }
    }
    return false;
}

/**
 * Enforce HTTPS redirect
 */
function enforce_https(): void {
    if (!is_https()) {
        $url = 'https://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . ($_SERVER['REQUEST_URI'] ?? '/');
        header('Location: ' . $url, true, 301);
        exit;
    }
}

/**
 * Parse WoW color codes and convert to HTML spans
 * Format: |cffRRGGBB text |r
 * Example: |cffed9c00Gold text|r -> <span style="color: #ed9c00;">Gold text</span>
 * Also handles HTML-encoded pipes: &#124;cff or &pipe;cff
 */
function parse_wow_colors(string $text): string {
    if ($text === '') {
        return $text;
    }

    // Decode HTML entities that might represent pipes
    $text = str_replace(['&#124;', '&pipe;', '&#x7C;'], '|', $text);

    if (strpos($text, '|cff') === false) {
        // Debug: log when no color codes found
        error_log('[WoW Colors] No |cff found in text: ' . substr($text, 0, 100));
        return $text;
    }

    // Debug: log that we found color codes
    error_log('[WoW Colors] Processing text with color codes');

    return preg_replace_callback(
        '~\|cff([0-9a-fA-F]{6})(.*?)\|r~s',
        function($m) {
            $hex = strtolower($m[1]);
            $content = $m[2];

            // Validate hex color
            if (!preg_match('~^[0-9a-f]{6}$~i', $hex)) {
                return $content;
            }

            // Recursively parse nested colors
            $content = parse_wow_colors($content);

            $result = '<span style="color: #' . $hex . ';">' . $content . '</span>';
            error_log('[WoW Colors] Converted: |cff' . $hex . ' → ' . $result);
            return $result;
        },
        $text
    );
}
