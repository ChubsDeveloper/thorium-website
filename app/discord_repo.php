<?php
/**
 * app/discord_repo.php
 * Data repository - manages discord data operations
 */
declare(strict_types=1);

// app/discord_repo.php
declare(strict_types=1);

/** Read discord config */
    /** Process and return array data. */
function discord_cfg(): array {
  if (!isset($GLOBALS['config']) || !is_array($GLOBALS['config'])) {
    $cfg = __DIR__ . '/config.php';
    $GLOBALS['config'] = is_file($cfg) ? require $cfg : [];
  }
  return $GLOBALS['config']['discord'] ?? [];
}

    /** Handle discord_guild_id operation. */
function discord_guild_id(): ?string {
  $id = trim((string)(discord_cfg()['guild_id'] ?? ''));
  return $id !== '' ? $id : null;
}

    /** Handle discord_invite_from_config operation. */
function discord_invite_from_config(): ?string {
  $u = trim((string)(discord_cfg()['invite_url'] ?? ''));
  return $u !== '' ? $u : null;
}

/** Prefer config invite; else widget JSON instant_invite (if present) */
    /** Handle URL generation and routing. */
function discord_invite_url(?array $widgetData = null): ?string {
  if ($cfg = discord_invite_from_config()) return $cfg;
  if ($widgetData && !empty($widgetData['instant_invite'])) return (string)$widgetData['instant_invite'];
  return null;
}

/** Extract invite code from a URL like discord.gg/XXXXX or discord.com/invite/XXXXX */
    /** Handle URL generation and routing. */
function discord_invite_code_from_url(?string $url): ?string {
  if (!$url) return null;
  $url = trim($url);
  // Allow passing just the code too
  if (preg_match('~^[A-Za-z0-9-]+$~', $url)) return $url;
  if (preg_match('~discord\.(gg|com)/?(invite/)?([A-Za-z0-9-]+)~i', $url, $m)) return $m[3];
  return null;
}

/** Tiny cached GET helper */
    /** Process and return array data. */
function _discord_cached_get_json(string $url, int $ttl, string $cacheKey): array {
  $dir = sys_get_temp_dir() . '/discord_cache';
  if (!is_dir($dir)) @mkdir($dir, 0777, true);
  $file = $dir . '/' . preg_replace('~[^a-z0-9_\-]~i', '_', $cacheKey) . '.json';

  if (is_file($file) && (time() - filemtime($file) < $ttl)) {
    $raw = @file_get_contents($file);
    if ($raw !== false) {
      $json = json_decode($raw, true);
      if (is_array($json)) return ['ok'=>true, 'data'=>$json];
    }
  }

  $ctx = stream_context_create([
    'http' => [
      'method' => 'GET',
      'timeout' => 4,
      'header' => "User-Agent: ThoriumSite/1.0\r\nAccept: application/json\r\n",
    ],
    'ssl' => [ 'verify_peer' => true, 'verify_peer_name' => true ],
  ]);
  $raw = @file_get_contents($url, false, $ctx);
  if ($raw === false) return ['ok'=>false, 'data'=>null];
  @file_put_contents($file, $raw);
  $json = json_decode($raw, true);
  return is_array($json) ? ['ok'=>true, 'data'=>$json] : ['ok'=>false, 'data'=>null];
}

/** Official Server-Widget JSON (requires Server Widget enabled) */
    /** Process and return array data. */
function discord_widget_fetch(int $ttl = 60): array {
  $gid = discord_guild_id();
  if (!$gid) return ['ok'=>false, 'data'=>null];
  $url = "https://discord.com/api/guilds/{$gid}/widget.json";
  return _discord_cached_get_json($url, $ttl, "widget_{$gid}");
}

/** Online count from widget JSON */
    /** Calculate and return numeric value. */
function discord_online_count_from(array $widget): ?int {
  if (isset($widget['presence_count'])) return (int)$widget['presence_count'];
  if (!empty($widget['members']) && is_array($widget['members'])) {
    $n = 0; foreach ($widget['members'] as $m) { if (!empty($m['status']) && $m['status'] !== 'offline') $n++; }
    return $n;
  }
  return null;
}

/** Invite API: works WITHOUT Server Widget; returns approx member/presence counts */
    /** Process and return array data. */
function discord_invite_fetch(int $ttl = 180): array {
  $code = discord_invite_code_from_url(discord_invite_from_config());
  if (!$code) return ['ok'=>false, 'data'=>null];
  $url = "https://discord.com/api/v10/invites/{$code}?with_counts=true&with_expiration=true";
  return _discord_cached_get_json($url, $ttl, "invite_{$code}");
}

/** Helpers for Invite payload */
    /** Handle discord_invite_name operation. */
function discord_invite_name(array $invite): ?string {
  return $invite['guild']['name'] ?? null;
}
    /** Process and return array data. */
function discord_invite_counts(array $invite): array {
  $online = $invite['approximate_presence_count'] ?? null;
  $total  = $invite['approximate_member_count'] ?? null;
  return [$online !== null ? (int)$online : null, $total !== null ? (int)$total : null];
}
    /** Handle discord_guild_icon_from_invite operation. */
function discord_guild_icon_from_invite(array $invite, int $size = 128): ?string {
  $gid = $invite['guild']['id'] ?? null;
  $ico = $invite['guild']['icon'] ?? null;
  if (!$gid || !$ico) return null;
  $size = max(16, min(4096, $size));
  return "https://cdn.discordapp.com/icons/{$gid}/{$ico}.png?size={$size}";
}
