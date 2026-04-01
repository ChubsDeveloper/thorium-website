<?php
/**
 * Live Chat Module (single-field moderation: ID / username / nickname)
 * - Root-safe API base (/api/)
 * - data-username/data-nickname for quick prefill from transcript
 * - VIP level enriched from total_spent (DonationRepository, fallback to accounts/users)
 * UPDATED: Integrates with chat-muted-status.php for real-time mute status
 */
declare(strict_types=1);

if (!function_exists('e')) { function e($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); } }
function role_slugify(string $label): string {
    $slug = strtolower(trim($label));
    $slug = str_replace(['co owner','co-owner','co–owner','co—owner'], 'co_owner', $slug);
    $slug = preg_replace('~[^\p{L}\p{Nd}\-_ ]+~u','',$slug);
    $slug = str_replace([' ', '-'], '_', $slug);
    return $slug ?: 'player';
}

/** Basic VIP extractor from arbitrary keys/badges (will be overridden by DB enrichment below) */
function vip_level_from(array $m): int {
    $candidates = ['vip_level','vip','vipLevel','user_vip_level','userVipLevel','vip_rank','vipRank','vip_tier','vipTier','tier','membership_tier','supporter_tier','donor_level','premium_level'];
    foreach ($candidates as $k) {
        if (array_key_exists($k, $m) && $m[$k] !== null && $m[$k] !== '') {
            $n = (int)preg_replace('~[^\d]+~', '', (string)$m[$k]);
            if ($n > 0) return min($n, 99);
        }
    }
    if (!empty($m['badges']) && is_string($m['badges'])) {
        if (preg_match('~vip\s*([0-9]+)~i', $m['badges'], $mm)) {
            $n = (int)($mm[1] ?? 0);
            if ($n > 0) return min($n, 99);
        }
    }
    if (!empty($m['badges']) && is_array($m['badges'])) {
        foreach ($m['badges'] as $b) {
            if (is_string($b) && preg_match('~vip\s*([0-9]+)~i', $b, $mm)) {
                $n = (int)($mm[1] ?? 0); if ($n > 0) return min($n, 99);
            } elseif (is_array($b)) {
                $txt = (string)($b['label'] ?? $b['name'] ?? '');
                if ($txt && preg_match('~vip\s*([0-9]+)~i', $txt, $mm)) {
                    $n = (int)($mm[1] ?? 0); if ($n > 0) return min($n, 99);
                }
            }
        }
    }
    return 0;
}

/** Locate project root (folder that contains /app) */
function __chat_root(): ?string {
    $dir = __DIR__;
    for ($i=0; $i<8; $i++) {
        if (is_dir($dir . '/app')) return $dir . '/app';
        $dir = dirname($dir);
    }
    return null;
}

/** PDO helper for this page */
function chat_get_pdo(): ?PDO {
    try {
        if (!empty($GLOBALS['pdo']) && $GLOBALS['pdo'] instanceof PDO) return $GLOBALS['pdo'];
        if (function_exists('app')) { $app = app(); if ($app && method_exists($app,'getPdo')) { $pdo = $app->getPdo(); if ($pdo instanceof PDO) return $pdo; } }
    } catch (Throwable $__) {}
    return null;
}

/** VIP from DB (DonationRepository preferred, fallback to total_spent columns) */
function chat_vip_from_db(int $uid): int {
    if ($uid <= 0) return 0;
    $pdo = chat_get_pdo(); $total = 0.0;

    // 1) DonationRepository
    try {
        $root = __chat_root();
        if ($root && is_file($root . '/Repositories/donation_repository.php')) {
            require_once $root . '/Repositories/donation_repository.php';
            if (class_exists('DonationRepository') && $pdo instanceof PDO) {
                $dr = new \DonationRepository($pdo);
                $t  = (float)$dr->getUserTotalSpent($uid);
                if ($t > 0) $total = max($total, $t);
            }
        }
    } catch (Throwable $__) {}

    // 2) Fallback columns
    if ($pdo instanceof PDO) {
        foreach (['accounts','users'] as $tbl) {
            try {
                $st = $pdo->prepare("SELECT total_spent FROM {$tbl} WHERE id=? LIMIT 1");
                if ($st && $st->execute([$uid])) {
                    $v = $st->fetchColumn();
                    if ($v !== false && $v !== null) $total = max($total, (float)$v);
                }
            } catch (Throwable $__) {}
        }
    }

    return (int)min(8, max(0, floor($total / 25)));
}

$user = function_exists('auth_user') ? auth_user() : null;
$is_guest = !$user;

/* staff check */
$userIsStaff = false;
try {
    if (!$is_guest) {
        if (function_exists('auth_get_role_name') && !empty($GLOBALS['authPdo'])) {
            $rn = (string)auth_get_role_name($GLOBALS['authPdo'], (int)$user['id']);
            if ($rn && $rn !== 'Player') $userIsStaff = true;
        } elseif (!empty($user['is_admin']) || !empty($user['is_staff'])) {
            $userIsStaff = true;
        }
    }
} catch (Throwable $__) {}

try {
    if (!function_exists('app')) return;
    $app = app();
    $chat_repo = new \App\Repositories\chat_repository($app);
    if (!$is_guest) $online_repo = new \App\Repositories\online_tracking_repository($app);
} catch (Exception $e) { return; }

$current_room = $_GET['room'] ?? 'general';
$current_room = preg_replace('/[^a-z0-9\-_]/i', '', $current_room) ?: 'general';
$messages = $chat_repo->get_recent_messages($current_room, 30);
$rooms    = $chat_repo->get_active_rooms();

/* Enrich VIP for initial render using DB (keeps parity with header + chat-poll) */
$__vip_cache = [];
foreach ($messages as &$__m) {
    $uid = (int)($__m['user_id'] ?? 0);
    $v0  = (int)($__m['vip_level'] ?? 0);
    if ($v0 <= 0) {
        if (!array_key_exists($uid, $__vip_cache)) $__vip_cache[$uid] = chat_vip_from_db($uid);
        $__m['vip_level'] = $__vip_cache[$uid];
    }
}
unset($__m, $__vip_cache);

if (!$is_guest) {
    try {
        $online_repo->update_user_heartbeat((int)$user['id'], $current_room);
        $online_count = $online_repo->get_online_count($current_room);
    } catch (\Throwable $e) {
        $online_count = $chat_repo->get_online_count($current_room);
    }
} else {
    $online_count = $chat_repo->get_online_count($current_room);
}

$csrf_token = '';
if (!$is_guest) {
    if (empty($_SESSION['_csrf'])) $_SESSION['_csrf'] = bin2hex(random_bytes(16));
    $csrf_token = $_SESSION['_csrf'];
}
$has_messages = !empty($messages);

$badgeHref = function_exists('theme_asset_url') ? theme_asset_url('css/badges.css') : '/css/badges.css';

/* Root-safe API base */
$API_BASE = '/api/';
if (function_exists('base_url')) {
    $apiUrl = base_url('api/');
    $API_BASE = rtrim(parse_url($apiUrl, PHP_URL_PATH) ?: '/api/', '/') . '/';
}
?>
<link rel="preload" as="style" href="<?= e($badgeHref) ?>">
<link rel="stylesheet" href="<?= e($badgeHref) ?>" data-badges-css="1">
<style>
#chat-messages{overflow-anchor:none}
/* Mute status styling */
.mute-status { background: rgba(239, 68, 68, 0.1); border: 1px solid rgba(239, 68, 68, 0.3); }
.mute-status-text { color: rgb(248, 113, 113); }

/* Message text: keep newlines, collapse spaces, don't split normal words */
#chat-messages .chat-message .msg{
  white-space: pre-line;     /* preserve \n only (no leading-space gaps) */
  overflow-wrap: break-word; /* break only long unbreakable tokens (URLs) */
  word-break: normal;        /* avoid mid-word breaking for normal text */
  hyphens: none;
}

/* ——— Nicer “Moderate” button ——— */
.btn-moderate{
  position:relative; display:inline-flex; align-items:center; gap:.4rem;
  padding:.35rem .7rem; border-radius:5px; font-size:.75rem; font-weight:600;
  color:#e5e7eb;
  border:1px solid rgba(255,255,255,.18);
  background:linear-gradient(180deg, rgba(255,255,255,.06), rgba(255,255,255,.02));
  box-shadow:inset 0 0 0 1px rgba(255,255,255,.04), 0 2px 8px rgba(0,0,0,.25);
  backdrop-filter:blur(6px); -webkit-backdrop-filter:blur(6px);
  transition:transform .12s ease, border-color .2s ease, box-shadow .2s ease, background .2s ease, color .2s ease;
}
.btn-moderate:hover{
  transform:translateY(-1px);
  border-color:rgba(255,255,255,.32);
  box-shadow:inset 0 0 0 1px rgba(255,255,255,.08), 0 6px 16px rgba(0,0,0,.35);
}
.btn-moderate:active{
  transform:translateY(0);
  box-shadow:inset 0 0 0 1px rgba(255,255,255,.05), 0 2px 8px rgba(0,0,0,.25);
}
.btn-moderate:focus-visible{
  outline:none;
  box-shadow:0 0 0 3px rgba(16,185,129,.35), 0 6px 16px rgba(0,0,0,.35);
}
.btn-moderate .icon{ width:14px; height:14px; opacity:.9; }
.btn-moderate .spark{
  position:absolute; inset:-1px; border-radius:inherit; pointer-events:none;
  background:
    radial-gradient(120px 60px at 0% 0%,   rgba(255,255,255,.08), rgba(255,255,255,0)),
    radial-gradient(120px 60px at 100% 100%,rgba(255,255,255,.08), rgba(255,255,255,0));
  opacity:0; transition:opacity .25s ease;
}
.btn-moderate:hover .spark{ opacity:1; }
@media (prefers-reduced-motion: reduce){
  .btn-moderate{ transition:border-color .2s ease, box-shadow .2s ease, background .2s ease, color .2s ease; }
  .btn-moderate:hover{ transform:none; }
}
</style>
<script>(function(){var s=document.querySelector('style[data-badges-fallback],#badges-fallback'); if(s) s.remove();})()</script>

<section class="py-16 relative animate-on-scroll" id="chat-section">
  <div class="container max-w-4xl mx-auto px-6">
    <div class="text-center mb-8">
      <p class="kicker">Community</p>
      <h2 class="h-display text-3xl font-bold">Live Chat</h2>
      <p class="text-neutral-400 mt-2">Connect with fellow adventurers</p>
      <p class="text-sm text-neutral-500 mt-1">💡 Try the header chat widget for quick access!</p>
    </div>

    <div class="rough-card p-0 overflow-hidden">
      <!-- Chat Header -->
      <div class="px-6 py-4 border-b border-white/10 bg-black/20">
        <div class="flex items-center justify-between">
          <div class="flex items-center gap-4">
            <div class="flex items-center gap-2">
              <div class="w-3 h-3 rounded-full bg-green-400 animate-pulse"></div>
              <span class="font-semibold"><?= e(ucfirst($current_room)) ?> Chat</span>
            </div>
            <?php if (count($rooms) > 1): ?>
              <select id="chat-room-select" class="tui-select text-sm">
                <?php foreach ($rooms as $room): ?>
                  <option value="<?= e($room['room']) ?>" <?= $room['room'] === $current_room ? 'selected' : '' ?>>
                    <?= e(ucfirst($room['room'])) ?><?php if (!empty($room['message_count'])): ?> (<?= e($room['message_count']) ?>)<?php endif; ?>
                  </option>
                <?php endforeach; ?>
              </select>
            <?php endif; ?>
          </div>
          <div class="flex items-center gap-3 text-sm text-neutral-400">
            <span id="online-count"><?= e($online_count) ?> online</span>
            <?php if ($userIsStaff): ?>
              <button id="open-moderate" class="btn-moderate" title="Moderate users" aria-label="Moderate users">
                <svg class="icon" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
                  <path d="M5 21h14v-2H5v2zM16.05 3.05l4.9 4.9-9.9 9.9H6.15v-4.9l9.9-9.9zm-7.9 10.4v1.55h1.55l8.35-8.35-1.55-1.55L8.15 13.45z"/>
                </svg>
                Moderate
                <span class="spark" aria-hidden="true"></span>
              </button>
            <?php endif; ?>
            <?php if ($is_guest): ?><span class="text-amber-400 text-xs">👁️ Guest viewing</span><?php endif; ?>
          </div>
        </div>
      </div>

      <!-- Mute Status Banner (initially hidden) -->
      <div id="mute-banner" class="hidden px-4 py-3 mute-status">
        <div class="flex items-center justify-between">
          <div class="flex items-center gap-2">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="currentColor" class="mute-status-text">
              <path d="M16 8A8 8 0 1 1 0 8a8 8 0 0 1 16 0zM5.354 4.646a.5.5 0 1 0-.708.708L7.293 8l-2.647 2.646a.5.5 0 0 0 .708.708L8 8.707l2.646 2.647a.5.5 0 0 0 .708-.708L8.707 8l2.647-2.646a.5.5 0 0 0-.708-.708L8 7.293 5.354 4.646z"/>
            </svg>
            <span class="font-medium mute-status-text">You are muted and cannot send messages</span>
          </div>
          <span id="mute-details" class="text-sm mute-status-text"></span>
        </div>
      </div>

      <!-- Chat Messages -->
      <div id="chat-messages" class="h-80 overflow-y-auto p-4 space-y-3 bg-black/5" style="overscroll-behavior:contain">
        <?php if (!$has_messages): ?>
          <div class="text-center text-neutral-500 py-8" id="empty-state">
            <div class="mb-3">💬</div>
            <p>No messages yet. <?= $is_guest ? 'Log in to start the conversation!' : 'Be the first to say hello!' ?></p>
          </div>
        <?php else: ?>
          <?php foreach ($messages as $message): ?>
            <?php
              $msg_time     = strtotime($message['created_at']);
              $time_ago     = time() - $msg_time;
              $time_display = $time_ago < 3600 ? floor($time_ago / 60) . 'm ago' : date('H:i', $msg_time);

              $user_role      = $message['user_role'] ?? 'Player';
              $is_staff_msg   = ($user_role !== '' && strcasecmp($user_role, 'Player') !== 0);

              // final vip (prefer DB-enriched vip_level; else fallback extractor)
              $vip_level      = (int)($message['vip_level'] ?? 0);
              if ($vip_level <= 0) $vip_level = vip_level_from($message);

              $role_css_class = $message['role_css_class'] ?? ('role-' . ($message['role_slug'] ?? role_slugify($user_role)));
              $display_name   = $message['display_name'] ?? $message['nickname'] ?? $message['username'] ?? 'Unknown';

              $can_delete = !$is_guest && (
                (int)$message['user_id'] === (int)($user['id'] ?? 0) || $userIsStaff
              );

              $username = $message['username'] ?? '';
              $nickname = $message['nickname'] ?? '';
            ?>
            <div class="chat-message group p-3 rounded-lg bg-black/20 hover:bg-black/30 transition-colors"
                 data-message-id="<?= e($message['id']) ?>"
                 data-user-id="<?= e($message['user_id']) ?>"
                 data-username="<?= e($username) ?>"
                 data-nickname="<?= e($nickname) ?>"
                 data-vip-level="<?= e($vip_level) ?>">
              <div class="flex items-start gap-3">
                <div class="flex-1 min-w-0">
                  <div class="flex items-baseline gap-2 mb-1 flex-wrap">
                    <?php if ($is_staff_msg): ?>
                      <span class="role-chip <?= e($role_css_class) ?> has-sheen"
                            title="<?= e($user_role) ?>"><?= e($user_role) ?></span>
                    <?php elseif ($vip_level > 0): ?>
                      <span class="role-chip vip-chip vip-l<?= e($vip_level) ?> has-sheen" title="VIP<?= e($vip_level) ?>">VIP<?= e($vip_level) ?></span>
                    <?php endif; ?>
                    <button type="button"
                            class="font-semibold text-white <?= ($is_staff_msg || $vip_level > 0) ? 'font-bold' : '' ?> hover:underline chat-name-btn"
                            title="Moderate this user">
                      <?= e($display_name) ?>
                    </button>
                    <span class="text-xs text-neutral-500"><?= e($time_display) ?></span>
                  </div>
                  <div class="msg text-neutral-300">
                    <?= e($message['message']) ?>
                  </div>
                </div>

                <?php if ($can_delete): ?>
                  <div class="flex-shrink-0">
                    <button class="chat-delete-btn opacity-0 group-hover:opacity-100 text-red-400 hover:text-red-300 p-1 transition-opacity"
                            data-message-id="<?= e($message['id']) ?>"
                            title="Delete message" aria-label="Delete message">
                      <svg width="14" height="14" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
                        <path d="M6 7h12v12a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2V7zM8 9v8h8V9H8zM10 5V3h4v2h5v2H5V5h5z"/>
                      </svg>
                    </button>
                  </div>
                <?php endif; ?>
              </div>
            </div>
          <?php endforeach; ?>
        <?php endif; ?>

        <div data-bottom-sentinel style="height:1px;"></div>
      </div>

      <!-- Chat Input -->
      <div class="p-4 border-t border-white/10 bg-black/10">
        <?php if ($is_guest): ?>
          <div class="flex gap-3 items-center">
            <div class="flex-1">
              <div class="w-full px-4 py-2 bg-black/40 border border-white/20 rounded-lg text-neutral-500 flex items-center justify-center">
                Log in to start chatting
              </div>
            </div>
            <a href="<?= e(base_url('login')) ?>" class="px-6 py-2 bg-emerald-600 hover:bg-emerald-700 text-white rounded-lg font-semibold transition-colors">Login</a>
          </div>
          <div class="text-xs text-neutral-500 mt-2">
            <a href="<?= e(base_url('register')) ?>" class="text-emerald-400 hover:text-emerald-300">Create an account</a> to join the conversation
          </div>
        <?php else: ?>
          <div class="flex gap-3" id="chat-input-wrapper">
            <input type="hidden" id="csrf-token" value="<?= e($csrf_token) ?>">
            <input type="hidden" id="current-room" value="<?= e($current_room) ?>">
            <div class="flex-1">
              <input
                type="text"
                id="chat-input"
                placeholder="Type your message..."
                maxlength="500"
                class="w-full px-4 py-2 bg-black/40 border border-white/20 rounded-lg focus:outline-none focus:border-amber-500 text-white placeholder-neutral-400"
                autocomplete="off">
            </div>
            <button
              type="button"
              class="px-6 py-2 bg-emerald-600 hover:bg-emerald-700 text-white rounded-lg font-semibold transition-colors disabled:opacity-50 disabled:cursor-not-allowed"
              id="chat-send-btn">
              Send
            </button>
          </div>
          <div class="text-xs text-neutral-500 mt-2">
            Press Enter to send • Max 500 characters
          </div>
        <?php endif; ?>
      </div>
    </div>
  </div>
</section>

<?php if (!$is_guest): ?>
<!-- Staff moderation modal (single target field) -->
<div id="moderate-overlay" class="fixed inset-0 bg-black/60 hidden z-40"></div>
<div id="moderate-modal" class="fixed inset-0 flex items-center justify-center hidden z-50 p-4">
  <div class="w-full max-w-md rounded-xl border border-white/10 bg-black/70 backdrop-blur p-5">
    <div class="flex items-center justify-between mb-3">
      <h3 class="text-lg font-bold">Moderate user</h3>
      <button id="mod-close" class="text-neutral-400 hover:text-white" title="Close">&times;</button>
    </div>

    <form id="mod-form" class="space-y-4" novalidate>
      <div>
        <label class="block text-sm text-neutral-300 mb-1">Target (ID / @username / #nickname)</label>
        <input type="text" id="mute-target"
               class="w-full px-3 py-2 rounded-md bg-black/40 border border-white/15 focus:outline-none focus:border-amber-500"
               placeholder="123  •  @Arthas  •  #TheLichKing">
        <div class="text-xs text-neutral-500 mt-1">
          Tip: Click a name in chat to prefill. Use <code>@</code> for username, <code>#</code> for nickname, or a numeric ID.
          If you omit prefixes, we try username first, then nickname.
        </div>
      </div>

      <div>
        <label class="block text-sm text-neutral-300 mb-1">Scope</label>
        <div class="flex items-center gap-4 text-sm">
          <label><input type="radio" name="mute-scope" value="room" checked> This room (<?= e($current_room) ?>)</label>
          <label><input type="radio" name="mute-scope" value="global"> Global</label>
        </div>
      </div>

      <div>
        <label class="block text-sm text-neutral-300 mb-1">Duration</label>
        <input id="mute-duration" class="w-full px-3 py-2 rounded-md bg-black/40 border border-white/15 focus:outline-none focus:border-amber-500"
               list="durations" placeholder="15m, 1h, 1d, perm" value="15m">
        <datalist id="durations">
          <option value="15m"></option><option value="30m"></option><option value="1h"></option>
          <option value="12h"></option><option value="1d"></option><option value="3d"></option>
          <option value="7d"></option><option value="perm"></option>
        </datalist>
      </div>

      <div>
        <label class="block text-sm text-neutral-300 mb-1">Reason (optional)</label>
        <input id="mute-reason" maxlength="128"
               class="w-full px-3 py-2 rounded-md bg-black/40 border border-white/15 focus:outline-none focus:border-amber-500"
               placeholder="Reason for moderation">
      </div>

      <div class="text-xs text-amber-300 hidden" id="mod-error"></div>

      <div class="flex items-center justify-end gap-3 pt-2">
        <button type="button" id="btn-unmute" class="px-3 py-2 rounded-md border border-white/20 hover:bg-white/10">Unmute</button>
        <button type="submit" class="px-4 py-2 rounded-md bg-emerald-600 hover:bg-emerald-700 text-white font-semibold">Mute</button>
      </div>
    </form>
  </div>
</div>

<script>
(function(){
  const API_BASE   = <?= json_encode($API_BASE) ?>;
  const csrfToken  = <?= json_encode($csrf_token) ?>;
  const roomDefault= <?= json_encode($current_room) ?>;
  const isStaff    = <?= $userIsStaff ? 'true' : 'false' ?>;

  const chatMessages = document.getElementById('chat-messages');
  const bottomSentinel= chatMessages?.querySelector('[data-bottom-sentinel]');
  const chatInput     = document.getElementById('chat-input');
  const sendBtn       = document.getElementById('chat-send-btn');
  const emptyState    = document.getElementById('empty-state');
  const roomSelect    = document.getElementById('chat-room-select');
  const roomInput     = document.getElementById('current-room');

  // Mute status elements
  const muteBanner = document.getElementById('mute-banner');
  const muteDetails = document.getElementById('mute-details');

  // Tie the updater to THIS chat's elements
  const updateMuteUI = makeUpdateMuteUI({
    inputEl:   chatInput,
    sendBtnEl: sendBtn,
    detailsEl: muteDetails,
    bannerEl:  muteBanner,
  });

  /* ===== Moderation modal ===== */
  const overlay   = document.getElementById('moderate-overlay');
  const modal     = document.getElementById('moderate-modal');
  const openBtn   = document.getElementById('open-moderate');
  const closeBtn  = document.getElementById('mod-close');
  const form      = document.getElementById('mod-form');
  const targetEl  = document.getElementById('mute-target');
  const durationEl= document.getElementById('mute-duration');
  const reasonEl  = document.getElementById('mute-reason');
  const errEl     = document.getElementById('mod-error');
  const btnUnmute = document.getElementById('btn-unmute');

  let currentMuteStatus = { muted: false };

  function openModal(){ overlay.classList.remove('hidden'); modal.classList.remove('hidden'); }
  function closeModal(){ overlay.classList.add('hidden'); modal.classList.add('hidden'); }

  // ===== Mute status (uses chat-muted-status.php) =====
  async function checkMuteStatus() {
    if (!chatInput) return; // Guest mode (no input)
    try {
      const currentRoom = roomInput?.value || roomDefault;
      const res = await fetch(API_BASE + 'chat-muted-status.php?room=' + encodeURIComponent(currentRoom), {
        credentials: 'same-origin'
      });
      const ct = (res.headers.get('content-type') || '').toLowerCase();
      if (!ct.includes('application/json')) return; // e.g., HTML error page
      const data = await res.json();
      if (data && typeof data.success === 'boolean') {
        updateMuteUI(data);
      }
    } catch (_) { /* swallow to avoid breaking UI */ }
  }

function formatMuteLeft(rs) {
  if (typeof rs !== 'number' || rs <= 0) return 'Permanent';
  const h = Math.floor(rs / 3600);
  const m = Math.floor((rs % 3600) / 60);
  const s = Math.floor(rs % 60);
  if (h > 0) return `${h}h ${m}m left`;
  if (m > 0) return `${m}m left`;
  return `${s}s left`;
}
function truncateReason(str, max = 120) {
  if (!str) return '';
  const t = String(str).trim();
  return t.length > max ? `${t.slice(0, max - 1)}…` : t;
}

/** Build a UI updater tied to specific elements */
function makeUpdateMuteUI({ inputEl, sendBtnEl, detailsEl, bannerEl }) {
  return function updateMuteUI(status) {
    currentMuteStatus = status || { muted: false };
    if (status && status.muted) {
      const left   = formatMuteLeft(status.remaining_seconds);
      const reason = (status.reason ?? '').toString().trim();
      const line   = reason ? `${left} • Reason: ${truncateReason(reason)}` : left;

      if (detailsEl) { detailsEl.textContent = line; detailsEl.title = reason ? `Reason: ${reason}` : ''; }
      if (bannerEl)  bannerEl.classList.remove('hidden');

      if (inputEl) {
        inputEl.disabled = true;
        inputEl.setAttribute('aria-disabled', 'true');
        inputEl.placeholder = 'You are muted and cannot send messages';
      }
      if (sendBtnEl) sendBtnEl.disabled = true;

    } else {
      if (bannerEl)  bannerEl.classList.add('hidden');
      if (detailsEl) { detailsEl.textContent = ''; detailsEl.title = ''; }
      if (inputEl) {
        inputEl.disabled = false;
        inputEl.removeAttribute('aria-disabled');
        inputEl.placeholder = 'Type your message...';
      }
      if (sendBtnEl) sendBtnEl.disabled = false;
    }
  };
}

  // ====================================================

  if (openBtn) openBtn.addEventListener('click', () => {
    targetEl.value = '';
    durationEl.value = localStorage.getItem('muteDuration') || '15m';
    const scope = localStorage.getItem('muteScope') || 'room';
    const r = form.querySelector(`input[name="mute-scope"][value="${scope}"]`) || form.querySelector('input[name="mute-scope"][value="room"]');
    if (r) r.checked = true;
    reasonEl.value = '';
    errEl.textContent=''; errEl.classList.add('hidden');
    openModal();
  });
  if (closeBtn) closeBtn.addEventListener('click', closeModal);
  if (overlay) overlay.addEventListener('click', closeModal);

  // Prefill by clicking a username in the transcript
  chatMessages?.addEventListener('click', (ev) => {
    if (!isStaff) return;
    const btn = ev.target.closest?.('.chat-name-btn');
    if (!btn) return;
    const row = btn.closest('.chat-message');
    if (!row) return;
    const un = row.getAttribute('data-username') || '';
    const nn = row.getAttribute('data-nickname') || '';
    const uid= row.getAttribute('data-user-id') || '';
    targetEl.value = un ? '@' + un : (nn ? '#' + nn : (uid || ''));
    durationEl.value = localStorage.getItem('muteDuration') || '15m';
    errEl.textContent=''; errEl.classList.add('hidden');
    openModal();
  });

  function appendTargetToForm(fd){
    const raw = (targetEl.value || '').trim();
    if (!raw) return false;
    if (raw.startsWith('@') && raw.length > 1) { fd.append('username', raw.slice(1)); return true; }
    if (raw.startsWith('#') && raw.length > 1) { fd.append('nickname', raw.slice(1)); return true; }
    if (/^\d+$/.test(raw))                     { fd.append('user_id', raw); return true; }
    fd.append('username', raw); fd.append('nickname', raw); return true;
  }

  form?.addEventListener('submit', (e) => {
    e.preventDefault();
    const scope = (form.querySelector('input[name="mute-scope"]:checked')?.value || 'room');
    const dur   = (durationEl.value || '15m').trim();
    const rsn   = reasonEl.value.trim();

    localStorage.setItem('muteDuration', dur);
    localStorage.setItem('muteScope', scope);

    const fd = new FormData();
    fd.append('_csrf', csrfToken);
    fd.append('duration', dur);
    if (rsn) fd.append('reason', rsn);
    if (scope !== 'global') fd.append('room', roomInput?.value || roomDefault);

    if (!appendTargetToForm(fd)) { errEl.textContent = 'Enter an ID, @username, or #nickname.'; errEl.classList.remove('hidden'); return; }

    fetch(API_BASE + 'chat-mute.php', { method:'POST', body:fd, credentials:'same-origin' })
      .then(r => r.json())
      .then(res => {
        if (!res || !res.success) { throw new Error(res && res.error ? res.error : 'Mute failed'); }
        closeModal();
        alert('Muted successfully.');
        checkMuteStatus();
      })
      .catch(err => { errEl.textContent = err.message || 'Mute failed'; errEl.classList.remove('hidden'); });
  });

  btnUnmute?.addEventListener('click', () => {
    const scope = (form.querySelector('input[name="mute-scope"]:checked')?.value || 'room');
    const fd = new FormData();
    fd.append('_csrf', csrfToken);
    if (scope !== 'global') fd.append('room', roomInput?.value || roomDefault);

    if (!appendTargetToForm(fd)) { errEl.textContent = 'Enter an ID, @username, or #nickname to unmute.'; errEl.classList.remove('hidden'); return; }

    fetch(API_BASE + 'chat-unmute.php', { method:'POST', body:fd, credentials:'same-origin' })
      .then(r=>r.json()).then(res=>{
        if (!res || !res.success) throw new Error(res && res.error ? res.error : 'Unmute failed');
        closeModal(); alert('Unmuted.');
        checkMuteStatus();
      }).catch(err=>{ errEl.textContent = err.message || 'Unmute failed'; errEl.classList.remove('hidden'); });
  });

  /* ===== Chat client ===== */
  if (!chatMessages || !bottomSentinel || !sendBtn) return;

  let lastMessageTime = Math.floor(Date.now()/1000);
  let currentRoom     = roomInput?.value || roomDefault;
  const displayedMessageIds = new Set();

  const BOTTOM_EPS = 40; let stickBottom = true;
  function isNearBottom(el){ return (el.scrollHeight - el.scrollTop - el.clientHeight) <= BOTTOM_EPS; }
  function nudgeBottomOnce(){ const t = chatMessages.scrollHeight - chatMessages.clientHeight; if (t>0) chatMessages.scrollTo({top:t, behavior:'auto'}); }
  function ensureBottom(fr=6){ let i=0; function tick(){ nudgeBottomOnce(); if(++i<fr) requestAnimationFrame(tick); } requestAnimationFrame(tick); }
  chatMessages.addEventListener('scroll', ()=>{ stickBottom = isNearBottom(chatMessages); });

  // Delete handler
  chatMessages.addEventListener('click', (ev) => {
    const btn = ev.target.closest?.('.chat-delete-btn');
    if (!btn) return;
    const id = String(btn.getAttribute('data-message-id') || btn.closest('.chat-message')?.getAttribute('data-message-id') || '').trim();
    if (!/^\d+$/.test(id)) { alert('Invalid message ID'); return; }
    if (!confirm('Delete this message?')) return;

    const fd = new FormData();
    fd.append('_csrf', csrfToken); fd.append('id', id); fd.append('message_id', id); fd.append('room', currentRoom);
    fetch(API_BASE + 'chat-delete.php', { method:'POST', body:fd, headers:{'Accept':'application/json'}, credentials:'same-origin' })
      .then(r=>r.json()).then(res=>{
        if (res && res.success) {
          const row = btn.closest('.chat-message');
          if (row) { displayedMessageIds.delete(row.getAttribute('data-message-id')); row.remove(); if (stickBottom) ensureBottom(2); }
        } else { alert('Delete failed: ' + (res && res.error ? res.error : 'Unknown error')); }
      }).catch(()=>alert('Delete failed: Network error'));
  });

  if (roomSelect) {
    roomSelect.addEventListener('change', () => {
      currentRoom = roomSelect.value || 'general';
      if (roomInput) roomInput.value = currentRoom;
      displayedMessageIds.clear();
      chatMessages.innerHTML = '';
      chatMessages.appendChild(bottomSentinel);
      lastMessageTime = 0;
      fetch(API_BASE + 'chat-poll.php?room=' + encodeURIComponent(currentRoom) + '&since=0', { credentials:'same-origin' })
        .then(r=>r.json()).then(res=>{
          if (!res || !res.success || !Array.isArray(res.messages)) return;
          if (emptyState) emptyState.style.display='none';
          res.messages.slice(-30).forEach(addIfNew);
          lastMessageTime = Math.floor(Date.now()/1000);
          ensureBottom(6);
          checkMuteStatus(); // Check mute status for new room
        }).catch(()=>{});
    });
  }

  function sendMessage(){
    if (!chatInput) return;
    if (currentMuteStatus.muted) {
      alert('You are muted and cannot send messages.');
      return;
    }
    const msg = (chatInput.value || '').trim();
    if (!msg || chatInput.disabled) return;
    chatInput.disabled = true; sendBtn.disabled = true;
    const fd = new FormData();
    fd.append('_csrf', csrfToken); fd.append('room', currentRoom); fd.append('message', msg);
    fetch(API_BASE + 'chat-send.php', { method:'POST', body:fd, credentials:'same-origin' })
      .then(r=>r.json()).then(res=>{
        if (res && res.success) {
          chatInput.value='';
          if (stickBottom) setTimeout(()=>ensureBottom(3), 80);
        }
        else if (res && (res.muted || (res.error && String(res.error).toLowerCase().includes('muted')))) {
          // Do not alert; refresh banner to show status
          checkMuteStatus();
        }
        else { alert(res && res.error ? res.error : 'Send failed'); }
      }).finally(()=>{
        if (!currentMuteStatus.muted) {
          chatInput.disabled=false;
          sendBtn.disabled=false;
          chatInput.focus();
        }
      });
  }
  sendBtn.onclick = sendMessage;
  chatInput?.addEventListener('keydown', (e)=>{ if (e.key==='Enter' && !e.shiftKey){ e.preventDefault(); sendMessage(); } });

  setInterval(() => {
    fetch(API_BASE + 'chat-poll.php?room=' + encodeURIComponent(currentRoom) + '&since=' + lastMessageTime, { credentials:'same-origin' })
      .then(r=>r.json()).then(res=>{
        if (!res || !res.success || !Array.isArray(res.messages)) return;
        let added=0;
        res.messages.forEach(m=>{ if (!displayedMessageIds.has(String(m.id))) { addMessage(m); displayedMessageIds.add(String(m.id)); added++; } });
        if (added>0){ lastMessageTime = Math.floor(Date.now()/1000); if (stickBottom) ensureBottom(2); }
      }).catch(()=>{});

    // Periodic mute status check
    checkMuteStatus();
  }, 4000);

  document.querySelectorAll('.chat-message').forEach(msg => {
    const id = msg.getAttribute('data-message-id'); if (id) displayedMessageIds.add(id);
  });
  ensureBottom(8); setTimeout(()=>ensureBottom(4),60); setTimeout(()=>ensureBottom(4),250);
  window.addEventListener('load', ()=>ensureBottom(4), { once:true });
  if (document.fonts && document.fonts.ready) document.fonts.ready.then(()=>ensureBottom(3));

  function addIfNew(m){ if (displayedMessageIds.has(String(m.id))) return; addMessage(m); displayedMessageIds.add(String(m.id)); }
  function roleClassFromMessage(m){
    if (m.role_css_class) return m.role_css_class;
    if (m.role_slug)      return 'role-' + m.role_slug;
    if (m.user_role) {
      const s = String(m.user_role).toLowerCase().trim().replace(/co[ \-–—]?owner/,'co_owner').replace(/[^\w\- ]+/g,'').replace(/[\s\-]+/g,'_');
      return 'role-' + (s || 'player');
    }
    return 'role-player';
  }
  function formatTimeAgo(ts){ const now=new Date(), t=new Date(ts), d=Math.floor((now-t)/1000); return d<3600? Math.floor(d/60)+'m ago' : t.toLocaleTimeString('en-US',{hour:'2-digit',minute:'2-digit'}); }
  function esc(s){ const d=document.createElement('div'); d.textContent=String(s); return d.innerHTML; }

  function vipLevelFromMessage(message){
    const keys = ['vip_level','vip','vipLevel','user_vip_level','userVipLevel','vip_rank','vipRank','vip_tier','vipTier','tier','membership_tier','supporter_tier','donor_level','premium_level'];
    for (const k of keys) {
      if (message && message[k] != null && message[k] !== '') {
        const n = parseInt(String(message[k]).replace(/[^\d]/g,''),10);
        if (Number.isFinite(n) && n>0) return Math.min(n, 99);
      }
    }
    const fromStr = (s)=>{ const m = String(s||'').match(/vip\s*([0-9]+)/i); return m? Math.min(parseInt(m[1],10),99) : 0; };
    if (typeof message?.badges === 'string') { const n=fromStr(message.badges); if (n>0) return n; }
    if (Array.isArray(message?.badges)) {
      for (const b of message.badges) {
        if (typeof b === 'string') { const n=fromStr(b); if (n>0) return n; }
        else if (b && typeof b === 'object') { const n=fromStr(b.label||b.name); if (n>0) return n; }
      }
    }
    return 0;
  }

  function addMessage(message){
    const isStaffMsg = String(message.user_role || 'player').toLowerCase() !== 'player';
    const vipLevel   = vipLevelFromMessage(message);

    const roleClass  = roleClassFromMessage(message);
    const displayName= message.display_name || message.username || 'Unknown';
    const timeAgo    = formatTimeAgo(message.created_at);

    const badge = isStaffMsg
      ? `<span class="role-chip ${roleClass} has-sheen" title="${esc(message.user_role||'')}">${esc(message.user_role||'')}</span>`
      : (vipLevel > 0 ? `<span class="role-chip vip-chip vip-l${vipLevel} has-sheen" title="VIP${vipLevel}">VIP${vipLevel}</span>` : '');

    const canDelete = Boolean(message.can_delete) || <?= $userIsStaff ? 'true' : 'false' ?> || (String(message.user_id) === String(<?= (int)($user['id'] ?? 0) ?>));

    const row = document.createElement('div');
    row.className = 'chat-message group p-3 rounded-lg bg-black/20 hover:bg-black/30 transition-colors';
    row.setAttribute('data-message-id', message.id);
    row.setAttribute('data-user-id', message.user_id || '');
    row.setAttribute('data-username', message.username || '');
    row.setAttribute('data-nickname', message.nickname || '');
    row.setAttribute('data-vip-level', String(vipLevel||0));
    row.innerHTML = `
      <div class="flex items-start gap-3">
        <div class="flex-1 min-w-0">
          <div class="flex items-baseline gap-2 mb-1 flex-wrap">
            ${badge}
            <button type="button" class="font-semibold text-white ${isStaffMsg||vipLevel>0 ? 'font-bold' : ''} hover:underline chat-name-btn" title="Moderate this user">${esc(displayName)}</button>
            <span class="text-xs text-neutral-500">${timeAgo}</span>
          </div>
          <div class="msg text-neutral-300">${esc(message.message||'')}</div>
        </div>
        ${canDelete ? `
        <div class="flex-shrink-0">
          <button class="chat-delete-btn opacity-0 group-hover:opacity-100 text-red-400 hover:text-red-300 p-1 transition-opacity"
                  data-message-id="${esc(message.id)}" title="Delete message" aria-label="Delete message">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
              <path d="M6 7h12v12a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2V7zM8 9v8h8V9H8zM10 5V3h4v2h5v2H5V5h5z"/>
            </svg>
          </button>
        </div>` : ``}
      </div>
    `;
    chatMessages.insertBefore(row, bottomSentinel);
    const all = chatMessages.querySelectorAll('.chat-message');
    if (all.length > 50) all[0]?.remove();
    if (stickBottom) ensureBottom(2);
  }

  // Initial mute status check
  checkMuteStatus();
})();
</script>
<?php else: ?>
<script>(function(){ /* guest-only minimal script not needed here */ })();</script>
<?php endif; ?>
