<?php
/**
 * Configuration file - Loads environment variables and defines application settings
 * Handles .env file parsing and provides typed configuration values
 */
declare(strict_types=1);

// Load environment variables from .env file
$env_file = __DIR__ . '/../.env';
if (is_file($env_file) && is_readable($env_file)) {
    $lines = file($env_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
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
    }
}

// Helper functions for typed environment variable access
$B = fn($k, $d=false) => filter_var($_ENV[$k] ?? $d, FILTER_VALIDATE_BOOLEAN);
$I = fn($k, $d=0)     => (int)($_ENV[$k] ?? $d);
$F = fn($k, $d=0.0)   => (float)($_ENV[$k] ?? $d);
$S = fn($k, $d='')    => (string)($_ENV[$k] ?? $d);
$H = fn($k, $d='')    => trim((string)($_ENV[$k] ?? $d));

$isSandbox = $B('PAYPAL_SANDBOX', true);

// Helper function to parse comma-separated integer values from environment
$CSV_INT = static function(string $key, array $fallback = []): array {
    $csv = $_ENV[$key] ?? '';
    if ($csv === '' || $csv === null) return $fallback;
    $list = array_filter(array_map('trim', explode(',', $csv)), 'strlen');
    return array_values(array_map('intval', $list));
};

return [
    // Database configurations
    'db' => [
        'host'    => $H('DB_HOST', '127.0.0.1'),
        'port'    => $I('DB_PORT', 3306),
        'name'    => $S('DB_NAME', 'thorium_website'),
        'user'    => $S('DB_USER', 'root'),
        'pass'    => $S('DB_PASS', 'ascent'),
        'charset' => 'utf8mb4',
    ],

    'characters_db' => [
        'host'    => $H('CHAR_DB_HOST', $_ENV['DB_HOST'] ?? '127.0.0.1'),
        'port'    => $I('CHAR_DB_PORT', $_ENV['DB_PORT'] ?? 3306),
        'name'    => $S('CHAR_DB_NAME', 'characters'),
        'user'    => $S('CHAR_DB_USER', $_ENV['DB_USER'] ?? 'root'),
        'pass'    => $S('CHAR_DB_PASS', $_ENV['DB_PASS'] ?? 'ascent'),
        'charset' => 'utf8mb4',
    ],

    'auth_db' => [
        'host'    => $H('AUTH_DB_HOST', $_ENV['DB_HOST'] ?? '127.0.0.1'),
        'port'    => $I('AUTH_DB_PORT', $_ENV['DB_PORT'] ?? 3306),
        'name'    => $S('AUTH_DB_NAME', 'auth'),
        'user'    => $S('AUTH_DB_USER', $_ENV['DB_USER'] ?? 'root'),
        'pass'    => $S('AUTH_DB_PASS', $_ENV['DB_PASS'] ?? 'ascent'),
        'charset' => 'utf8mb4',
    ],

    'world_db' => [
        'host'    => $H('WORLD_DB_HOST', $_ENV['DB_HOST'] ?? '127.0.0.1'),
        'port'    => $I('WORLD_DB_PORT', $_ENV['DB_PORT'] ?? 3306),
        'name'    => $S('WORLD_DB_NAME', 'world'),
        'user'    => $S('WORLD_DB_USER', $_ENV['DB_USER'] ?? 'root'),
        'pass'    => $S('WORLD_DB_PASS', $_ENV['DB_PASS'] ?? 'ascent'),
        'charset' => 'utf8mb4',
    ],

    // Application settings
    'base_url' => $S('BASE_URL', ''),

    // Theme configuration
    'theme'      => $S('DEFAULT_THEME', 'thorium-default'),
    'theme_lock' => $B('THEME_LOCK', false),

    // Download system configuration
    'downloads' => [
        'hmac_secret'  => $S('DOWNLOAD_SECRET', 'change-this-secret-key'),
        'link_ttl'     => $I('DOWNLOAD_TTL', 3600),
        'storage_path' => $S('DOWNLOAD_STORAGE', dirname(__DIR__) . '/storage/downloads'),
    ],

    // Debug mode
    'debug' => $B('DEBUG', false),

    // Discord integration settings
    'discord' => [
        'invite_url'   => $S('DISCORD_INVITE', ''),
        'guild_id'     => $S('DISCORD_GUILD_ID', ''),
        'widget_theme' => $S('DISCORD_THEME', 'dark'),
        'show_widget'  => $B('DISCORD_SHOW_WIDGET', true),
    ],

    // Game connection settings
    'connect' => [
        'realmlist'     => $S('REALMLIST', 'logon.yourserver.com'),
        'client_link'   => $S('CLIENT_DOWNLOAD', ''),
        'launcher_link' => $S('LAUNCHER_DOWNLOAD', ''),
        'patch_link'    => $S('PATCH_DOWNLOAD', ''),
        'addon_pack'    => $S('ADDON_PACK', ''),
        'expansion'     => $S('EXPANSION', '3.3.5a'),
    ],

    // Cache configuration
    'cache' => [
        'enabled' => $B('CACHE_ENABLED', true),
        'ttl'     => $I('CACHE_TTL', 300),
    ],

    // Security settings
    'security' => [
        'csrf_token_name'     => '_csrf',
        'session_lifetime'    => $I('SESSION_LIFETIME', 7200),
        'password_min_length' => $I('PASSWORD_MIN_LENGTH', 8),
    ],

    // Permission and access control
    'admin_min_permission_id' => $I('ADMIN_MIN_PERMISSION_ID', 191),

    'rbac_levels' => [
        'admin_panel'       => 191,
        'user_management'   => 195,
        'server_management' => 196,
        'system_settings'   => 197,
        'full_access'       => 198,
    ],

    'rbac_feature_access' => [
        'view_admin_panel'  => [191,192,193,194,195,196,197,198],
        'manage_users'      => [195,196,197,198],
        'manage_staff'      => [196,197,198],
        'manage_modules'    => [195,196,197,198],
        'manage_themes'     => [195,196,197,198],
        'view_logs'         => [195,196,197,198],
        'system_settings'   => [197,198],
        'database_access'   => [198],
        'full_control'      => [198],
    ],

    // Realm configuration
    'realms' => [
        'admin_only_ids' => $CSV_INT('REALMS_ADMIN_ONLY_IDS', []),
        'default_realm'  => (int)($_ENV['DEFAULT_REALM_ID'] ?? 1),
    ],

    // PayPal payment integration
    'paypal' => [
        'client_id'     => $S('PAYPAL_CLIENT_ID', ''),
        'client_secret' => $S('PAYPAL_CLIENT_SECRET', ''),
        'sandbox'       => $isSandbox,
        'webhook_id'    => $S('PAYPAL_WEBHOOK_ID', ''),
        'currency'      => $S('PAYPAL_CURRENCY', 'USD'),
        'api_base'      => $isSandbox ? 'https://api-m.sandbox.paypal.com' : 'https://api-m.paypal.com',
        'web_base'      => $isSandbox ? 'https://www.sandbox.paypal.com' : 'https://www.paypal.com',
    ],

    // Donation system configuration
    'donations' => [
        'min_amount'        => $F('DONATION_MIN_AMOUNT', 1.00),
        'max_amount'        => $F('DONATION_MAX_AMOUNT', 500.00),
        'points_per_dollar' => $I('DONATION_POINTS_PER_DOLLAR', 100),
        'enabled'           => true,
    ],

    // RBAC helper mappings
    'rbac_helpers' => [
        'permission_names' => [
            190=>'Player',191=>'Trial GM',192=>'Initiate GM',193=>'Senior GM',194=>'Head GM',
            195=>'Administrator',196=>'Staff Manager',197=>'Co-Owner',198=>'Owner',
        ],
        'admin_levels' => [191,192,193,194,195,196,197,198],
        'gm_levels'    => [191,192,193,194],
        'staff_levels' => [195,196,197,198],
        'owner_levels' => [197,198],
    ],
];