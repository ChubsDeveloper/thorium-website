<?php
/**
 * app/init.php
 * Application initialization file — environment, security headers, session, DB.
 */
declare(strict_types=1);

/* 0) Load .env */
$envFile = __DIR__ . '/../.env';
if (is_file($envFile) && is_readable($envFile)) {
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        $t = trim($line);
        if ($t === '' || $t[0] === '#') continue;
        $pos = strpos($line, '=');
        if ($pos === false) continue;
        $key = trim(substr($line, 0, $pos));
        $val = trim(substr($line, $pos + 1));
        if ((str_starts_with($val, '"') && str_ends_with($val, '"')) ||
            (str_starts_with($val, "'") && str_ends_with($val, "'"))) {
            $val = substr($val, 1, -1);
        }
        $_ENV[$key] = $val;
        putenv($key . '=' . $val);
    }
}

/* env helper */
if (!function_exists('envv')) {
    function envv(string $key, $default = null) {
        $v = $_ENV[$key] ?? getenv($key);
        return ($v === false || $v === null || $v === '') ? $default : $v;
    }
}

/* 1) Error handling */
if (!defined('DB_AUTO_DDL')) {
    $raw = envv('DB_AUTO_DDL', null);
    define('DB_AUTO_DDL', $raw === null || $raw === '' ? true : (bool)filter_var($raw, FILTER_VALIDATE_BOOLEAN));
}
$debug = (bool)filter_var(envv('APP_DEBUG', envv('DEBUG', false)), FILTER_VALIDATE_BOOLEAN);
@ini_set('display_errors', $debug ? '1' : '0');
@ini_set('log_errors', '1');
error_reporting($debug ? E_ALL : (E_ALL & ~E_NOTICE & ~E_DEPRECATED & ~E_STRICT));

// Setup secure error handler
if (!defined('SECURITY_HANDLER_SETUP')) {
    require_once __DIR__ . '/Security/ErrorHandler.php';
    \App\Security\ErrorHandler::setup($debug, __DIR__ . '/../storage/logs');
    define('SECURITY_HANDLER_SETUP', true);
}

/* 2) Bootstrap clean system (fallback to legacy) */
$bootstrap_file = __DIR__ . '/bootstrap.php';
$has_new_system = false;
$config = [];
if (file_exists($bootstrap_file)) {
    try {
        require_once $bootstrap_file;
        $has_new_system = true;
        $GLOBALS['new_app'] = app();
        $config = $GLOBALS['config'] ?? (app()->getConfig() ?? []);
        $GLOBALS['has_clean_system'] = true;
    } catch (\Throwable $e) {
        $has_new_system = false;
        $config = require __DIR__ . '/config.php';
        error_log('[bootstrap] clean system failed, using legacy config: ' . $e->getMessage());
    }
} else {
    $config = require __DIR__ . '/config.php';
}

/* 2.1) Map .env → $config (BASE_URL + MAIL) */
$baseUrl = envv('BASE_URL', $config['base_url'] ?? null);
if ($baseUrl) $config['base_url'] = rtrim((string)$baseUrl, '/');

$config['mail'] = array_merge([
    'driver' => 'log', 'host' => '', 'port' => 587, 'username' => '', 'password' => '',
    'encryption' => 'tls', 'from' => 'support@thorium-reforged.org', 'from_name' => 'Thorium Reforged',
    'reply_to' => null, 'reply_to_name' => null, 'logo_path' => null, 'logo_url' => null,
], $config['mail'] ?? [], [
    'driver'        => strtolower((string) envv('MAIL_DRIVER',     $config['mail']['driver']     ?? 'log')),
    'host'          => (string)          envv('MAIL_HOST',         $config['mail']['host']       ?? ''),
    'port'          => (int)             envv('MAIL_PORT',         $config['mail']['port']       ?? 587),
    'username'      => (string)          envv('MAIL_USERNAME',     $config['mail']['username']   ?? ''),
    'password'      => (string)          envv('MAIL_PASSWORD',     $config['mail']['password']   ?? ''),
    'encryption'    => strtolower((string) envv('MAIL_ENCRYPTION', $config['mail']['encryption'] ?? 'tls')),
    'from'          => (string)          envv('MAIL_FROM',         $config['mail']['from']       ?? 'support@thorium-reforged.org'),
    'from_name'     => (string)          envv('MAIL_FROM_NAME',    $config['mail']['from_name']  ?? 'Thorium Reforged'),
    'reply_to'      => envv('MAIL_REPLY_TO',      $config['mail']['reply_to']      ?? null),
    'reply_to_name' => envv('MAIL_REPLY_TO_NAME', $config['mail']['reply_to_name'] ?? null),
    'logo_path'     => envv('MAIL_LOGO_PATH',     $config['mail']['logo_path']     ?? null),
    'logo_url'      => envv('MAIL_LOGO_URL',      $config['mail']['logo_url']      ?? null),
]);

/* 3) Security headers (skip in CLI) */
if (PHP_SAPI !== 'cli') {
    header('X-Frame-Options: DENY');
    header('X-Content-Type-Options: nosniff');
    header('X-XSS-Protection: 1; mode=block');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header("Permissions-Policy: geolocation=(), camera=(), microphone=(), payment=()");
    header("Content-Security-Policy: default-src 'self'; img-src 'self' data:; style-src 'self' 'unsafe-inline' https://cdn.tailwindcss.com https://fonts.googleapis.com; font-src 'self' data: https://fonts.gstatic.com; script-src 'self' 'unsafe-inline' https://cdn.tailwindcss.com; object-src 'none'; base-uri 'self'; frame-ancestors 'none'; upgrade-insecure-requests");
    header('Strict-Transport-Security: max-age=31536000; includeSubDomains; preload');

    // CORS: Allow only same origin for sensitive operations
    if (isset($_SERVER['HTTP_ORIGIN'])) {
        $origin = $_SERVER['HTTP_ORIGIN'];
        $baseUrl = envv('BASE_URL', '');
        if (!empty($baseUrl) && strpos($origin, parse_url($baseUrl, PHP_URL_HOST)) !== false) {
            header('Access-Control-Allow-Origin: ' . $origin);
            header('Access-Control-Allow-Credentials: true');
            header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
            header('Access-Control-Allow-Headers: Content-Type, X-CSRF-Token, Authorization');
            header('Access-Control-Max-Age: 86400');
        }
    }
}

/* 4) Session (Cloudflare/tunnel aware) */
if (!function_exists('req_is_https')) {
    function req_is_https(): bool {
        if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') return true;
        if (isset($_SERVER['SERVER_PORT']) && (int)$_SERVER['SERVER_PORT'] === 443) return true;
        $xfp = $_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '';
        if ($xfp && stripos($xfp, 'https') !== false) return true;
        $xfs = $_SERVER['HTTP_X_FORWARDED_SSL'] ?? '';
        if ($xfs && strtolower($xfs) === 'on') return true;
        if (!empty($_SERVER['HTTP_CF_VISITOR'])) {
            $v = json_decode($_SERVER['HTTP_CF_VISITOR'], true);
            if (is_array($v) && strtolower((string)($v['scheme'] ?? '')) === 'https') return true;
        }
        return false;
    }
}
if (!function_exists('cookie_domain_for_host')) {
    function cookie_domain_for_host(string $host): ?string {
        $explicit = envv('SESSION_DOMAIN', null);
        if ($explicit !== null && $explicit !== '') return (string)$explicit;
        $strategy = strtolower((string)envv('SESSION_DOMAIN_STRATEGY', 'host')); // 'host' | 'registrable'
        $h = preg_replace('~:\d+$~', '', strtolower($host));
        if ($strategy !== 'registrable') return null;
        if (filter_var($h, FILTER_VALIDATE_IP) || $h === 'localhost') return null;
        $parts = explode('.', $h);
        if (count($parts) >= 2) return '.' . implode('.', array_slice($parts, -2));
        return null;
    }
}

if (session_status() === PHP_SESSION_NONE) {
    $sessionName     = (string)envv('SESSION_NAME', ini_get('session.name') ?: 'PHPSESSID');
    $sessionSameSite = (string)envv('SESSION_SAMESITE', 'Lax');
    $secure          = (bool)filter_var(envv('SESSION_SECURE', req_is_https()), FILTER_VALIDATE_BOOLEAN);
    $host            = $_SERVER['HTTP_HOST'] ?? '';
    $domain          = cookie_domain_for_host($host);

    if ($sessionName) @session_name($sessionName);
    @ini_set('session.cookie_samesite', $sessionSameSite);

    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'domain'   => $domain ?: '',
        'secure'   => $secure,
        'httponly' => true,
        'samesite' => $sessionSameSite,
    ]);

    session_start();

    // Sync legacy/new keys
    if (empty($_SESSION['_csrf']) && !empty($_SESSION['csrf'])) {
        $_SESSION['_csrf'] = $_SESSION['csrf'];
    } elseif (empty($_SESSION['csrf']) && !empty($_SESSION['_csrf'])) {
        $_SESSION['csrf'] = $_SESSION['_csrf'];
    }

    // 🔐 CSRF bootstrap: ALWAYS have a token in the session
    if (empty($_SESSION['_csrf'])) {
        try {
            $_SESSION['_csrf'] = bin2hex(random_bytes(32));
        } catch (\Throwable $e) {
            $_SESSION['_csrf'] = bin2hex(openssl_random_pseudo_bytes(32));
        }
        $_SESSION['csrf'] = $_SESSION['_csrf'];
    }

    // 📣 Expose CSRF to clients via cookie + header (safe: HttpOnly=false is fine for CSRF token)
    $cookieOpts = [
        'expires'  => 0,
        'path'     => '/',
        'domain'   => $domain ?: '',
        'secure'   => $secure,
        'httponly' => false,               // must be readable by non-browser clients
        'samesite' => $sessionSameSite,
    ];
    setcookie('XSRF-TOKEN', $_SESSION['_csrf'], $cookieOpts);
    header('X-CSRF-Token: ' . $_SESSION['_csrf']);
}

/* 5) Output buffering */
if (ob_get_level() === 0) { ob_start(); }

/* 6) Helpers & theme */
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/theme.php';

/* 7) DB helper */
if (!function_exists('db_or_null')) {
    function db_or_null(array $cfg): ?PDO {
        try {
            if (empty($cfg['host']) || empty($cfg['name']) || !isset($cfg['user'])) return null;
            $port = isset($cfg['port']) ? ';port=' . (int)$cfg['port'] : '';
            $dsn  = "mysql:host={$cfg['host']}{$port};dbname={$cfg['name']};charset=" . ($cfg['charset'] ?? 'utf8mb4');
            return new PDO($dsn, (string)$cfg['user'], (string)($cfg['pass'] ?? ''), [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_TIMEOUT            => 5,
            ]);
        } catch (\Throwable $e) {
            error_log('[db_or_null] ' . $e->getMessage());
            return null;
        }
    }
}

/* 8) Initialize DBs */
if ($has_new_system && function_exists('app')) {
    try { $pdo = app()->getDb()->getPdo(); }
    catch (\Throwable $e) {
        $pdo = db_or_null($config['db'] ?? []);
        error_log('[init] clean DB failed, legacy fallback: ' . $e->getMessage());
    }
    $auth_cfg = $config['auth_db'] ?? [];
    $authPdo  = db_or_null($auth_cfg);
} else {
    $pdo     = db_or_null($config['db']      ?? []);
    $authPdo = db_or_null($config['auth_db'] ?? []);
}

/* 9) Globals */
$GLOBALS['pdo']     = $pdo;
$GLOBALS['authPdo'] = $authPdo;
$GLOBALS['config']  = $GLOBALS['config'] ?? $config;

/* 10) DB ping (optional) */
try { if ($pdo) { $pdo->query('SELECT 1'); } } catch (\Throwable $e) {
    error_log('[init] DB ping failed: ' . $e->getMessage());
}
