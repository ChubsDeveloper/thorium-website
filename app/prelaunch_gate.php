<?php
/**
 * Prelaunch access gate - Controls public access during prelaunch phase
 * Allows testers via signed cookies, role-based access, or IP allowlists
 * Include this from public/index.php after init/bootstrap for environment loading
 */
declare(strict_types=1);

if (PHP_SAPI === 'cli') { return; }

// Environment variable reader with fallback hierarchy
$read_env = function(string $key, $default = null) {
  $lk = $key;
  $kl = strtolower($key);

  // Direct environment variables
  $val = getenv($lk);
  if ($val !== false && $val !== null) return $val;
  if (isset($_ENV[$lk]))    return $_ENV[$lk];
  if (isset($_SERVER[$lk])) return $_SERVER[$lk];

  // Configuration fallbacks
  if (isset($GLOBALS['config']) && is_array($GLOBALS['config'])) {
    $cfg = $GLOBALS['config'];
    if (isset($cfg['prelaunch']) && is_array($cfg['prelaunch'])) {
      $pl = $cfg['prelaunch'];
      if (isset($pl[$lk])) return $pl[$lk];
      if (isset($pl[$kl])) return $pl[$kl];
    }
    if (isset($cfg[$lk])) return $cfg[$lk];
    if (isset($cfg[$kl])) return $cfg[$kl];
  }
  return $default;
};

// Boolean environment variable parser
$envb = function($v): bool {
  if (is_bool($v)) return $v;
  $v = strtolower(trim((string)$v));
  return in_array($v, ['1','true','yes','on'], true);
};

$DEBUG_GATE  = $envb($read_env('DEBUG_GATE', 'false'));
$why         = '';

// Configuration loading
$PRELAUNCH   = $envb($read_env('PRELAUNCH_MODE', 'false'));
if (!$PRELAUNCH) { if ($DEBUG_GATE) header('X-Prelaunch: off'); return; }

$COOKIE_NAME = (string)$read_env('PRELAUNCH_COOKIE_NAME', 'tw_beta');
$DAYS        = (int)($read_env('PRELAUNCH_EXPIRES_DAYS', 14));
$TTL         = max(1, $DAYS) * 86400;

$ACCESS_KEY  = (string)$read_env('PRELAUNCH_ACCESS_KEY', '');
$ROLES_RAW   = (string)$read_env('PRELAUNCH_ALLOWED_ROLES', '');
$ROLE_BYPASS = $envb($read_env('PRELAUNCH_ROLE_BYPASS', 'true')); // Set to false to force gate even for admins
$ALLOW_IPS   = array_filter(array_map('trim', explode(',', (string)$read_env('PRELAUNCH_ALLOW_IPS', ''))));

$HTTPS       = $envb($read_env('SECURE_COOKIES', 'true'));
$SIGN_KEY    = (string)($read_env('APP_KEY', '') ?: $read_env('HASH_SALT', 'thorium-fallback'));

$uri = (string)(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/');

// Public allowlist for static assets and landing page
$ALLOW_PREFIXES = [
  '/landing',
  '/theme-asset', '/theme-asset.php',
  '/assets', '/themes', '/public', '/static', '/dist', '/build',
  '/favicon.ico', '/robots.txt', '/humans.txt',
  '/discord',
];

// IP allowlist check
$ip = $_SERVER['REMOTE_ADDR'] ?? '';
if ($ip && !empty($ALLOW_IPS) && in_array($ip, $ALLOW_IPS, true)) {
  if ($DEBUG_GATE) { header('X-Prelaunch: allow-ip'); header('X-Prelaunch-Why: '.$ip); }
  return;
}

// Allowlisted paths check
foreach ($ALLOW_PREFIXES as $p) {
  if ($p !== '' && strpos($uri, $p) === 0) {
    if ($DEBUG_GATE) { header('X-Prelaunch: bypass-allowlist'); header('X-Prelaunch-Why: '.$p); }
    return;
  }
}

// Quick actions - clear beta access
if (isset($_GET['beta']) && $_GET['beta'] === 'clear') {
  $host   = $_SERVER['HTTP_HOST'] ?? '';
  $domain = (strpos($host, ':') !== false) ? explode(':', $host, 2)[0] : $host;
  setcookie($COOKIE_NAME, '', [
    'expires' => time() - 3600, 'path' => '/', 'domain' => $domain ?: '',
    'secure' => $HTTPS, 'httponly' => true, 'samesite' => 'Lax',
  ]);
  $qs = $_GET; unset($qs['beta']);
  $clean = $uri . (empty($qs) ? '' : '?' . http_build_query($qs));
  header('Location: ' . $clean, true, 302);
  exit;
}

// Role-based bypass (optional)
if ($ROLE_BYPASS && strlen($ROLES_RAW) > 0) {
  if (!headers_sent() && session_status() === PHP_SESSION_NONE) { @session_start(); }
  $roles = array_filter(array_map('trim', explode(',', $ROLES_RAW)));
  $user  = null;
  if (function_exists('auth_user'))        { $user = auth_user(); }
  elseif (function_exists('current_user')) { $user = current_user(); }
  elseif (isset($_SESSION['user']))        { $user = $_SESSION['user']; }

  if (is_array($user) && isset($user['role']) && in_array((string)$user['role'], $roles, true)) {
    if ($DEBUG_GATE) { header('X-Prelaunch: allow-role'); header('X-Prelaunch-Why: '.$user['role']); }
    return;
  }
}

// Cookie signing helpers
$b64u  = function(string $bin): string { return rtrim(strtr(base64_encode($bin), '+/', '-_'), '='); };
$b64ud = function(string $txt): string { $txt = strtr($txt, '-_', '+/'); return base64_decode($txt . str_repeat('=', (4 - strlen($txt) % 4) % 4)); };
$sign  = function(string $data) use ($SIGN_KEY): string { return hash_hmac('sha256', $data, $SIGN_KEY); };

// Unlock via ?beta=KEY parameter
if (isset($_GET['beta'])) {
  $provided = (string)$_GET['beta'];
  if ($ACCESS_KEY !== '' && hash_equals($ACCESS_KEY, $provided)) {
    $payload = json_encode(['iat'=>time(),'exp'=>time()+$TTL], JSON_UNESCAPED_SLASHES);
    $token   = $b64u($payload) . '.' . $sign($payload);

    $host   = $_SERVER['HTTP_HOST'] ?? '';
    $domain = (strpos($host, ':') !== false) ? explode(':', $host, 2)[0] : $host;

    setcookie($COOKIE_NAME, $token, [
      'expires'  => time() + $TTL,
      'path'     => '/',
      'domain'   => $domain ?: '',
      'secure'   => $HTTPS,
      'httponly' => true,
      'samesite' => 'Lax',
    ]);

    $qs = $_GET; unset($qs['beta']);
    $clean = $uri . (empty($qs) ? '' : '?' . http_build_query($qs));
    header('Location: ' . $clean, true, 302);
    exit;
  }
}

// Signed cookie validation
if (!empty($_COOKIE[$COOKIE_NAME])) {
  $parts = explode('.', (string)$_COOKIE[$COOKIE_NAME], 2);
  if (count($parts) === 2) {
    [$p64, $sig] = $parts;
    $payload = $b64ud($p64);
    if ($payload !== false && hash_equals($sign($payload), $sig)) {
      $data = json_decode($payload, true);
      if (is_array($data) && ($data['exp'] ?? 0) >= time()) {
        if ($DEBUG_GATE) { header('X-Prelaunch: allow-cookie'); header('X-Prelaunch-Why: valid'); }
        return;
      }
      $why = 'cookie-expired';
    } else {
      $why = 'cookie-badsig';
    }
  } else {
    $why = 'cookie-badparts';
  }
}

// Debug diagnostics endpoint
if ($DEBUG_GATE && isset($_GET['gate']) && $_GET['gate'] === 'diag') {
  header('Content-Type: text/plain');
  echo "Prelaunch: ON\n";
  echo "Why not allowed: " . ($why ?: 'n/a') . "\n";
  echo "Role bypass: " . ($ROLE_BYPASS ? 'enabled' : 'disabled') . " (roles: ".($ROLES_RAW ?: 'none').")\n";
  echo "Cookie name: {$COOKIE_NAME}\n";
  echo "Allow IPs: " . (empty($ALLOW_IPS) ? 'none' : implode(', ', $ALLOW_IPS)) . "\n";
  echo "Allowlist paths: " . implode(', ', $ALLOW_PREFIXES) . "\n";
  exit;
}

// Block access and redirect to landing page
if ($DEBUG_GATE) {
  header('X-Prelaunch: redirect-landing');
  if ($why) header('X-Prelaunch-Why: '.$why);
}
header('Location: /landing', true, 302);
exit;
