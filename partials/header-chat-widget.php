<?php
/**
 * Header chat widget — pinned bar + auto-grow + reply-to + edit messages
 * - Pinned bar under header (outside scroll)
 * - Staff pin/unpin per-message + remove-from-pinbar
 * - Discord-style replies: per-message "Reply", reply bar ABOVE composer (no pushdown), reply context on messages (PUBLIC)
 * - Edit own messages: click edit button, inline editing with save/cancel
 * - Slightly smaller height + 90vh popup cap
 * - Robust open/close (guards so a JS error can't block opening)
 * - Keeps: chronology insert-by-ts, lastTs polling, new-pill, linkify, Name FX,
 *          moderation modal, delete, seen-sync, sticky bottom, mute banner
 * - OPEN/CLOSE: delegated click (survives header remount) + dropdown portaled to <body>
 * - Online Users: dedicated popover anchored to the "Online" button (doesn't affect chat layout)
 * 
 * FIXED VERSION - Eliminates staff badge bug via server authority + robust popover positioning
 */
declare(strict_types=1);

static $header_chat_widget_loaded = false;
if ($header_chat_widget_loaded) { echo "<!-- Header chat widget already loaded -->"; return; }
$header_chat_widget_loaded = true;

$chat_enabled = false;
if (function_exists('module_enabled') && module_enabled('chat')) $chat_enabled = true;
elseif (function_exists('clean_module_enabled') && clean_module_enabled('header_chat')) $chat_enabled = true;
if (!$chat_enabled) { echo "<!-- Header chat widget disabled -->"; return; }

$user = function_exists('auth_user') ? (auth_user() ?? null) : null;
$is_guest = !$user;

if (!function_exists('e')) { function e($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); } }
if (!function_exists('base_url')) { function base_url($p=''){ return '/'.ltrim($p,'/'); } }

/* CSRF */
$csrf_token = '';
if (!$is_guest && isset($_SESSION)) {
    if (empty($_SESSION['_csrf'])) $_SESSION['_csrf'] = bin2hex(random_bytes(16));
    $csrf_token = $_SESSION['_csrf'];
}

/* Staff check - match header.php logic exactly */
$userIsStaff = false;
if (!$is_guest) {
  try {
    if (!empty($GLOBALS['authPdo']) && function_exists('auth_is_admin')) {
      $minPermissionId = (int)($GLOBALS['config']['admin_min_permission_id'] ?? 191);
      $userIsStaff = auth_is_admin($GLOBALS['authPdo'], (int)$user['id'], $minPermissionId);
    }
    if (!$userIsStaff && !empty($GLOBALS['authPdo']) && function_exists('auth_get_role_name')) {
      $role_name = (string)auth_get_role_name($GLOBALS['authPdo'], (int)$user['id']);
      if ($role_name !== '' && $role_name !== 'Player') $userIsStaff = true;
    }
    if (!$userIsStaff && !function_exists('auth_is_admin')) {
      $userIsStaff = !empty($user['is_admin']);
    }
  } catch (Throwable $__) {}
}

/* Chat state */
$current_room = 'general';
$unseen_count = 0;
try {
    if (function_exists('app') && !$is_guest) {
        $app = app();
        if (class_exists('\App\Repositories\chat_repository')) {
            $chat_repo = new \App\Repositories\chat_repository($app);
            $unseen_count = $chat_repo->get_unseen_count((int)$user['id'], $current_room);
        }
    }
} catch (Exception $e) {}

$badgeHref = function_exists('theme_asset_url') ? theme_asset_url('css/badges.css') : '/css/badges.css';
$widget_id = 'header-chat-widget-' . uniqid();

/* API base (root-safe) */
$API_BASE = '/api/';
if (function_exists('base_url')) {
    $apiUrl = base_url('api/');
    $API_BASE = rtrim(parse_url($apiUrl, PHP_URL_PATH) ?: '/api/', '/') . '/';
}

/* Load Name Effects CSS */
$ROOT = function_exists('__thorium_root') ? __thorium_root() : dirname(__DIR__);
$NFX_FILE = $ROOT . '/app/name_effects.php';
$HAS_NFX = false;
if (is_file($NFX_FILE)) {
    require_once $NFX_FILE;
    $HAS_NFX = true;
}
?>
<link rel="preload" as="style" href="<?= e($badgeHref) ?>">
<link rel="stylesheet" href="<?= e($badgeHref) ?>" data-badges-css="1">

<style>
/* =========================
   Header Chat CSS
   ========================= */

/* Scope anchors */
#<?= $widget_id ?>-messages { overflow-anchor: none; position: relative; }

/* Reveal inline action buttons on hover */
#<?= $widget_id ?>-dropdown .chat-message .chat-delete-btn,
#<?= $widget_id ?>-dropdown .chat-message .chat-pin-btn,
#<?= $widget_id ?>-dropdown .chat-message .chat-reply-btn,
#<?= $widget_id ?>-dropdown .chat-message .chat-edit-btn { 
  opacity:0; transition:opacity .15s ease;
}
#<?= $widget_id ?>-dropdown .chat-message:hover .chat-delete-btn,
#<?= $widget_id ?>-dropdown .chat-message:hover .chat-pin-btn,
#<?= $widget_id ?>-dropdown .chat-message:hover .chat-reply-btn,
#<?= $widget_id ?>-dropdown .chat-message:hover .chat-edit-btn { 
  opacity:1;
}

/* Popup frame (fixed + capped height) */
.chat-floater{
  position:fixed !important;
  width:min(90vw, 800px);
  max-height:90vh;
  inset:auto auto auto auto;
  z-index:99999;
}

#<?= $widget_id ?>-composer-wrap .flex.gap-10.items-end{
  align-items: center !important;
}

/* ——— Send button only ——— */
#<?= $widget_id ?>-send{
  /* size & layout */
  
  height: calc(3.25rem + 6px) !important;
  line-height: 1;    
  display: inline-flex;
  align-items: center;
  justify-content: center;
  box-sizing: border-box;
  padding: .55rem 4rem;         /* complements px-6 py-2 */
  position: relative;
  border-radius: .1rem !important;
  overflow: hidden;

  /* visuals */
  border: 1px solid rgba(255,255,255,.16);
  background:
    linear-gradient(180deg, rgba(255,255,255,.08), rgba(255,255,255,.02)),
    linear-gradient(180deg, rgba(16,125,129,.96), rgba(5,150,105,.96)) !important; /* emerald-600→700 */
  color: #fff;
  box-shadow: inset 0 1px 0 rgba(255,255,255,.18), 0 6px 16px rgba(0,0,0,.35);

  /* feel */
  transition:
    transform .12s ease,
    box-shadow .2s ease,
    border-color .2s ease,
    filter .2s ease,
    background .2s ease;
  cursor: pointer;
  -webkit-tap-highlight-color: transparent;
}

#<?= $widget_id ?>-send:hover{
  transform: translateY(-1px);
  filter: brightness(1.02);
  border-color: rgba(255,255,255,.28);
  box-shadow: inset 0 1px 0 rgba(255,255,255,.25), 0 10px 22px rgba(0,0,0,.45);
}

#<?= $widget_id ?>-send:active{
  transform: translateY(0);
  box-shadow: inset 0 1px 0 rgba(0,0,0,.20), 0 3px 10px rgba(0,0,0,.35);
}

#<?= $widget_id ?>-send:focus-visible{
  outline: none;
  box-shadow:
    0 0 0 2px rgba(245,158,11,.55),  /* amber focus ring to match input */
    0 8px 22px rgba(0,0,0,.45);
  border-color: rgba(255,255,255,.35);
}

#<?= $widget_id ?>-send[disabled]{
  opacity: .6;
  transform: none;
  box-shadow: inset 0 1px 0 rgba(255,255,255,.08);
  cursor: not-allowed;
}

/* soft sheen */
#<?= $widget_id ?>-send::before{
  content: "";
  position: absolute; inset: 0;
  border-radius: inherit;
  background: linear-gradient(180deg, rgba(255,255,255,.12), rgba(255,255,255,0) 42%);
  pointer-events: none;
}

/* Messages viewport height */
#<?= $widget_id ?>-messages{
  height: clamp(300px, 48vh, 680px);
}

/* Mod card placement for short viewports */
#<?= $widget_id ?>-mod-card { transform: translateY(6vh); }
@media (max-height: 640px) { #<?= $widget_id ?>-mod-card { transform: translateY(3vh); } }

/* Mute status */
.mute-status { background: rgba(239, 68, 68, 0.1); border: 1px solid rgba(239, 68, 68, 0.3); }
.mute-status-text { color: rgb(248, 113, 113); }

/* Moderate / Online buttons (glass chip, low-radius, clear affordance) */
.btn-moderate{
  position:relative; display:inline-flex; align-items:center; gap:.45rem;
  padding:.4rem .7rem; border-radius:3px; font-size:.75rem; font-weight:600;
  color:#e5e7eb; border:1px solid rgba(255,255,255,.16);
  background:linear-gradient(180deg, rgba(255,255,255,.06), rgba(255,255,255,.02));
  box-shadow:inset 0 0 0 1px rgba(255,255,255,.04), 0 2px 10px rgba(0,0,0,.28);
  backdrop-filter:blur(8px);
  cursor:pointer; user-select:none;
  transition:transform .12s ease, border-color .2s ease, box-shadow .2s ease, background .2s ease, color .2s ease;
}
.btn-moderate:hover{
  transform:translateY(-1px);
  border-color:rgba(255,255,255,.35);
  box-shadow:inset 0 0 0 1px rgba(255,255,255,.10), 0 8px 22px rgba(0,0,0,.45);
  background:linear-gradient(180deg, rgba(255,255,255,.10), rgba(255,255,255,.04));
}
.btn-moderate:active{
  transform:translateY(0);
  box-shadow:inset 0 0 0 1px rgba(0,0,0,.25), 0 2px 8px rgba(0,0,0,.35);
  background:linear-gradient(180deg, rgba(0,0,0,.05), rgba(0,0,0,.15));
}
.btn-moderate:focus-visible{
  outline:none;
  box-shadow:0 0 0 2px rgba(245,158,11,.55), 0 8px 22px rgba(0,0,0,.45);
}
.btn-moderate .icon{ width:14px; height:14px; opacity:.9; transition:transform .15s ease; }
.btn-moderate:hover .icon{ transform:translateY(-1px); }

/* Keep Online button “active” while popover is open */
#<?= $widget_id ?>-open-online[aria-expanded="true"]{
  border-color: rgba(52,211,153,.55);
  background: linear-gradient(180deg, rgba(34,197,94,.16), rgba(34,197,94,.08));
  color: #eaffef;
  box-shadow: inset 0 0 0 1px rgba(52,211,153,.28), 0 8px 22px rgba(0,0,0,.45);
}

/* --------------------------
   ★ Refined List Message UI
   -------------------------- */

/* Base message row */
#<?= $widget_id ?>-messages .chat-message{
  background:
    linear-gradient(180deg, rgba(255,255,255,.035), rgba(255,255,255,.02)),
    rgba(10,10,12,.55) !important;
  border:1px solid rgba(255,255,255,.10) !important;
  border-radius:2px !important;
  padding:10px 12px !important;
  box-shadow:0 10px 30px rgba(0,0,0,.30), inset 0 1px 0 rgba(255,255,255,.05);
  transition:border-color .15s ease, box-shadow .15s ease, transform .08s ease, background .15s ease;
}
#<?= $widget_id ?>-messages .chat-message:hover{
  border-color:rgba(255,255,255,.18) !important;
  box-shadow:0 16px 38px rgba(0,0,0,.36), inset 0 1px 0 rgba(255,255,255,.07);
  transform:translateY(-1px);
}

/* Subtle row spacing (container already uses space-y) */
#<?= $widget_id ?>-messages{ gap:.5rem !important; }

/* Header (name / badge / time) — centered alignment + crisp spacing */
#<?= $widget_id ?>-messages .chat-message .flex.items-baseline{
  align-items:center !important;   /* <-- only change to fix vertical alignment */
  margin-bottom:.28rem !important;
  gap:.4rem .30rem !important;
}
#<?= $widget_id ?>-messages .chat-message .chat-name-btn{
  color:#fff !important; font-weight:700 !important; padding:0 !important;
  text-underline-offset:2px;
}
#<?= $widget_id ?>-messages .chat-message .text-xs.text-neutral-500{
  color:#9aa0a6 !important; letter-spacing:.01em;
}

/* Message text */
#<?= $widget_id ?>-messages .chat-message .msg{
  white-space: pre-wrap; overflow-wrap: break-word; word-break: normal; hyphens: none;
  color:#e7e9ee !important;
  line-height:1.5rem !important;
  font-size:.95rem !important;
  max-width:78ch;
}

/* Links (linkify) */
#<?= $widget_id ?>-messages .msg a.chat-link{
  color:#8ef5be !important; text-decoration:underline;
}
#<?= $widget_id ?>-messages .msg a.chat-link:hover{ filter:brightness(1.05); }

/* Reply context — compact, readable */
#<?= $widget_id ?>-messages .chat-message .reply-context{
  margin:.65rem 0 .5rem !important;
  padding:.4rem .55rem !important;
  border-radius:3px !important;
  background:rgba(255,255,255,.045) !important;
  border:1px solid rgba(255,255,255,.08) !important;
  color:#d7dbe2 !important;
  font-size:.82rem !important;
}
#<?= $widget_id ?>-messages .chat-message .reply-jump{
  color:#e4e8ef !important; text-decoration:underline; cursor:pointer;
}
.chat-highlight{ outline:2px solid rgba(34,197,94,.5); outline-offset:2px; transition: outline-color .8s ease; }

/* Edited flag */
#<?= $widget_id ?>-messages .chat-message .edited-indicator{
  color:#a7adb6 !important; font-size:.72rem !important;
}

/* Right-side action buttons as quiet pills */
#<?= $widget_id ?>-dropdown .chat-message .chat-delete-btn,
#<?= $widget_id ?>-dropdown .chat-message .chat-pin-btn,
#<?= $widget_id ?>-dropdown .chat-message .chat-reply-btn,
#<?= $widget_id ?>-dropdown .chat-message .chat-edit-btn{
  background:rgba(0,0,0,.35); border:1px solid rgba(255,255,255,.10);
  border-radius:3px; padding:.28rem; line-height:0;
  transition:background .15s ease, border-color .15s ease, opacity .12s ease, transform .08s ease;
}
#<?= $widget_id ?>-dropdown .chat-message .chat-reply-btn { color:#d4d4d8; }
#<?= $widget_id ?>-dropdown .chat-message .chat-edit-btn  { color:#7bb0ff; }
#<?= $widget_id ?>-dropdown .chat-message .chat-pin-btn   { color:#34d399; }
#<?= $widget_id ?>-dropdown .chat-message .chat-delete-btn{ color:#ff8b8b; }
#<?= $widget_id ?>-dropdown .chat-message .chat-pin-btn.is-active{
  box-shadow:inset 0 0 0 1px rgba(52,211,153,.45);
}
#<?= $widget_id ?>-dropdown .chat-message .chat-delete-btn:hover{ border-color:rgba(255,0,0,.35); transform:translateY(-1px); }
#<?= $widget_id ?>-dropdown .chat-message .chat-edit-btn:hover{   border-color:rgba(120,170,255,.35); transform:translateY(-1px); }
#<?= $widget_id ?>-dropdown .chat-message .chat-reply-btn:hover{  border-color:rgba(255,255,255,.20); transform:translateY(-1px); }
#<?= $widget_id ?>-dropdown .chat-message .chat-pin-btn:hover{    border-color:rgba(52,211,153,.35); transform:translateY(-1px); }

/* Edit mode */
.chat-message.is-editing .msg { display: none; }
.chat-message.is-editing .edit-controls { display: block; }
.chat-message .edit-controls { display: none; }
.chat-message .edit-textarea{
  width:100%; min-height:2rem; max-height:8rem; padding:.5rem; border-radius:3px;
  background: rgba(0,0,0,0.45); border: 1px solid rgba(255,255,255,0.22); color: white;
  font-size:.9rem; line-height:1.25rem; resize: vertical; font-family: inherit;
}
.chat-message .edit-textarea:focus{ outline: none; border-color: rgba(245, 158, 11, 0.55); }
.edit-buttons{ display:flex; gap:.5rem; margin-top:.5rem; }
.edit-btn{ padding:.28rem .8rem; border-radius:3px; font-size:.78rem; font-weight:700; border:1px solid; }
.edit-btn-save{ background:#22c55e; border-color:#22c55e; color:#fff; }
.edit-btn-save:hover{ background:#16a34a; border-color:#16a34a; }
.edit-btn-cancel{ background: transparent; border-color: rgba(255,255,255,0.28); color:#e5e7eb; }
.edit-btn-cancel:hover{ background: rgba(255,255,255,0.08); border-color: rgba(255,255,255,0.45); }

/* "New messages" pill */
#<?= $widget_id ?>-new-pill{
  position: sticky; bottom:.5rem; left:50%; transform:translateX(-50%);
  box-shadow:0 12px 28px rgba(0,0,0,.35);
  border:1px solid rgba(255,255,255,.14);
}

/* ----------------
   Pinned bar style
   ---------------- */
#<?= $widget_id ?>-pinbar{ display:none; }
#<?= $widget_id ?>-pinbar.is-visible{ display:block; }
#<?= $widget_id ?>-pinbar .pin-shell{
  background:linear-gradient(180deg, rgba(34,197,94,.12), rgba(34,197,94,.06));
  border:1px solid rgba(34,197,94,.35); color:#eaffef; border-radius:3px; padding:.6rem .8rem;
  box-shadow:inset 0 1px 0 rgba(255,255,255,.05);
}
#<?= $widget_id ?>-pinbar .pin-title{
  font-size:.75rem; letter-spacing:.05em; text-transform:uppercase; color:#9af3bf;
  display:flex; gap:.4rem; align-items:center; margin-bottom:.25rem;
}
#<?= $widget_id ?>-pinbar .pin-body a.chat-link{ text-decoration:underline; color:#86efac; word-break: break-all; overflow-wrap:anywhere; }
#<?= $widget_id ?>-dropdown .chat-message .chat-pin-btn.is-active{ opacity:1 !important; color:#34d399; }

/* ----------------------------
   Composer + reply bar polish
   ---------------------------- */
#<?= $widget_id ?>-composer-wrap { position: relative; }
#<?= $widget_id ?>-replybar{
  position:absolute; left:0; right:0; top:0;
  transform: translateY(calc(-100% - 8px));
  background: rgba(255,255,255,.05);
  border: 1px dashed rgba(255,255,255,.18);
  border-radius: 4px; padding: .5rem .65rem; display:none; z-index: 2; backdrop-filter: blur(2px);
}
#<?= $widget_id ?>-replybar.is-visible{ display:flex; }

#<?= $widget_id ?>-input{
  min-height: 2.75rem; max-height: 10rem; overflow-y:auto; resize:none; line-height:1.25rem;
  background: linear-gradient(180deg, rgba(255,255,255,.03), rgba(255,255,255,.015)), rgba(0,0,0,.45) !important;
  border:1px solid rgba(255,255,255,.16) !important;
  color:#eef2f7 !important;
  border-radius:3px !important;
}
#<?= $widget_id ?>-input::placeholder{ color:#9aa0a6; }
#<?= $widget_id ?>-input:focus{ border-color:rgba(245,158,11,.55) !important; }

/* -------------------------
   Online Users Popover (UI)
   ------------------------- */
#<?= $widget_id ?>-online-panel{
  position: fixed; z-index: 2147483645; display:none;
  width: min(340px, 92vw);
}
#<?= $widget_id ?>-online-card{
  border-radius: 4px;
  border: 1px solid rgba(255,255,255,.12);
  background:
    linear-gradient(180deg, rgba(255,255,255,.06), rgba(255,255,255,.02)),
    rgba(0,0,0,.72);
  backdrop-filter: blur(10px);
  box-shadow:
    inset 0 1px 0 rgba(255,255,255,.06),
    0 12px 34px rgba(0,0,0,.55);
}
#<?= $widget_id ?>-online-head{
  padding:.6rem .8rem; border-bottom:1px solid rgba(255,255,255,.08);
  display:flex; align-items:center; justify-content:space-between;
}
#<?= $widget_id ?>-online-searchbar{
  padding:.45rem .65rem .5rem; border-bottom:1px solid rgba(255,255,255,.06);
  display:flex; gap:.5rem; align-items:center;
}
#<?= $widget_id ?>-online-search{
  flex:1; min-width:0; background:rgba(0,0,0,.35);
  border:1px solid rgba(255,255,255,.18);
  color:#e5e7eb; border-radius:4px; padding:.45rem .6rem; font-size:.85rem;
}
#<?= $widget_id ?>-online-search::placeholder{ color:#a3a3a3; }
#<?= $widget_id ?>-online-list{ max-height: 280px; overflow-y:auto; padding:.35rem; }

/* Sections */
.online-section{ margin:.2rem 0 .4rem; }
.online-section-head{
  display:flex; align-items:center; gap:.5rem;
  padding:.25rem .4rem; margin:.2rem 0 .25rem;
  font-size:.7rem; text-transform:uppercase; letter-spacing:.06em;
  color:#d4d4d8; opacity:.85;
}
.online-section-head .count{
  font-size:.7rem; padding:.05rem .45rem; border-radius:999px;
  background:rgba(255,255,255,.1); border:1px solid rgba(255,255,255,.12);
}

/* Items */
.online-user-item{
  display:flex; align-items:center; gap:.45rem;
  padding:.38rem .45rem; border-radius:4px;
  transition:background .15s ease, transform .06s ease;
}
.online-user-item:hover{ background:rgba(255,255,255,.06); }
.online-user-item:active{ transform:translateY(1px); }
.online-user-status{ width:8px; height:8px; border-radius:50%; background:#22c55e; flex-shrink:0; }
.online-user-main{ display:flex; align-items:center; gap:.35rem; min-width:0; }
.online-user-main .chat-name-btn{
  padding:0; margin:0; background:none; border:0; color:#fff; font-weight:600; cursor:pointer;
}
.online-user-main .chat-name-btn:hover{ filter:brightness(1.05); }
#<?= $widget_id ?>-online-list .online-chip{ transform:translateY(-.5px); }

/* Subtle scrollbar */
#<?= $widget_id ?>-online-list::-webkit-scrollbar{ width:10px; }
#<?= $widget_id ?>-online-list::-webkit-scrollbar-track{ background:transparent; }
#<?= $widget_id ?>-online-list::-webkit-scrollbar-thumb{
  background:rgba(255,255,255,.14); border:2px solid transparent; background-clip:padding-box; border-radius:999px;
}

/* -------------
   Minor polish
   ------------- */
#<?= $widget_id ?>-messages .chat-message .flex-1{ min-width: 0; }
#<?= $widget_id ?>-pinbar .pin-body a.chat-link{ word-break: break-all; overflow-wrap:anywhere; }
</style>

<?php
/* Print Name Effects CSS if available */
if ($HAS_NFX && function_exists('nfx_print_styles_once')) {
    nfx_print_styles_once();
}
?>



<script>(function(){var s=document.querySelector('style[data-badges-fallback],#badges-fallback'); if(s) try{s.remove();}catch(_){}})()</script>

<div class="relative" id="<?= $widget_id ?>">
  <button id="<?= $widget_id ?>-toggle" type="button"
    class="relative p-2 text-neutral-400 hover:text-white transition-colors focus:outline-none focus:text-white"
    title="Live Chat" aria-controls="<?= $widget_id ?>-dropdown" aria-expanded="false">
    <svg width="20" height="20" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
      <path d="M20 2H4a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h4l4 4 4-4h4a2 2 0 0 0 2-2V4a2 2 0 0 0-2-2z"/>
    </svg>

    <?php if (!$is_guest && $unseen_count > 0): ?>
      <span id="<?= $widget_id ?>-badge" class="absolute -top-1 -right-1 bg-red-500 text-white text-xs rounded-full h-5 w-5 flex items-center justify-center font-bold">
        <?= $unseen_count > 9 ? '9+' : $unseen_count ?>
      </span>
    <?php endif; ?>

    <span class="absolute -bottom-0.5 -right-0.5 w-3 h-3 bg-green-400 rounded-full border-2 border-gray-900" aria-hidden="true"></span>
  </button>

  <!-- Dropdown -->
  <div id="<?= $widget_id ?>-dropdown" class="right-0 top-full mt-2 rough-card p-0 overflow-hidden shadow-2xl z-[9999] hidden chat-floater" role="dialog" aria-label="Live Chat" style="display:none">
    <div class="px-6 py-4 border-b border-white/10 bg-black/20">
      <div class="flex items-center justify-between">
        <div class="flex items-center gap-2">
          <div class="w-3 h-3 rounded-full bg-green-400 animate-pulse" aria-hidden="true"></div>
          <span class="font-semibold">Live Chat</span>
        </div>
        <div class="flex items-center gap-3 text-sm text-neutral-400">
          <!-- FIXED: Online popover button -->
          <button
  id="<?= $widget_id ?>-open-online"
  type="button"
  class="btn-moderate btn-online"
  title="Show online users"
  aria-haspopup="dialog"
  aria-expanded="false"
>
  <svg class="icon" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
    <path d="M16 11c1.66 0 2.99-1.34 2.99-3S17.66 5 16 5s-3 1.34-3 3 1.34 3 3 3zm-8 0c1.66 0 2.99-1.34 2.99-3S9.66 5 8 5 5 6.34 5 8s1.34 3 3 3zm0 2c-2.33 0-7 1.17-7 3.5V19h14v-2.5C15 14.17 10.33 13 8 13zm8 0c-.29 0-.62.02-.97.05 1.16.84 1.97 1.93 1.97 3.45V19h6v-2.5C23 14.17 18.33 13 16 13z"/>
  </svg>
  <span>Online</span>
  <span id="<?= $widget_id ?>-online-pill" class="count">0</span>
</button>


          <?php if ($userIsStaff): ?>
            <button id="<?= $widget_id ?>-open-moderate" class="btn-moderate" title="Moderate users" aria-label="Moderate users">
              <svg class="icon" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
                <path d="M5 21h14v-2H5v2zM16.05 3.05l4.9 4.9-9.9 9.9H6.15v-4.9l9.9-9.9zm-7.9 10.4v1.55h1.55l8.35-8.35-1.55-1.55L8.15 13.45z"/>
              </svg>
              Moderate
            </button>
          <?php endif; ?>
          <?php if ($is_guest): ?><span class="text-amber-400 text-xs">👁️ Guest viewing</span><?php endif; ?>
        </div>
      </div>
    </div>

    <!-- Pinned bar -->
    <div id="<?= $widget_id ?>-pinbar" class="px-6 py-2 bg-black/10 border-b border-white/10 hidden">
      <div class="pin-shell">
        <div class="flex items-start justify-between gap-3">
          <div class="flex-1 min-w-0">
            <div class="pin-title">
              <svg width="14" height="14" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
                <path d="M14 2l8 8-4 4-2-2-4 4v4H8v-4l4-4-2-2 4-4z"/>
              </svg>
              <span>Pinned</span>
            </div>
            <div id="<?= $widget_id ?>-pin-body" class="pin-body text-sm"></div>
          </div>
          <?php if ($userIsStaff): ?>
            <div class="pin-actions flex items-center gap-2 pl-2">
              <button id="<?= $widget_id ?>-pinbar-remove" type="button" class="text-emerald-300 hover:text-emerald-200" title="Remove pinned message">Remove</button>
            </div>
          <?php endif; ?>
        </div>
      </div>
    </div>

    <!-- Mute Status Banner -->
    <div id="<?= $widget_id ?>-mute-banner" class="hidden px-4 py-2 mute-status">
      <div class="flex items-center justify-between">
        <div class="flex items-center gap-2">
          <svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor" class="mute-status-text">
            <path d="M16 8A8 8 0 1 1 0 8a8 8 0 0 1 16 0zM5.354 4.646a.5.5 0 1 0-.708.708L7.293 8l-2.647 2.646a.5.5 0 0 0 .708.708L8 8.707l2.646 2.647a.5.5 0 0 0 .708-.708L8.707 8l2.647-2.646z"/>
          </svg>
          <span class="text-sm font-medium mute-status-text">You are muted</span>
        </div>
        <span id="<?= $widget_id ?>-mute-details" class="text-xs mute-status-text"></span>
      </div>
    </div>

    <div id="<?= $widget_id ?>-messages" class="overflow-y-auto p-4 space-y-3 bg-black/5">
      <div class="text-center text-neutral-500 py-8" id="<?= $widget_id ?>-empty">
        <div class="mb-3">💬</div>
        <p class="text-xs">Loading messages...</p>
      </div>
      <div data-bottom-sentinel style="height:1px;"></div>
    </div>

    <!-- Footer / Composer -->
    <div class="p-4 border-t border-white/10 bg-black/10" id="<?= $widget_id ?>-composer-wrap">
      <?php if ($is_guest): ?>
        <div class="flex gap-3 items-center">
          <div class="flex-1">
            <div class="w-full px-4 py-2 bg-black/40 border border-white/20 rounded-lg text-neutral-500 flex items-center justify-center">
              <svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor" class="mr-2" aria-hidden="true">
                <path d="M18 8h-1V6c0-2.76-2.24-5-5-5S7 3.24 7 6v2z"/>
              </svg>
              Log in to start chatting
            </div>
          </div>
          <a href="<?= e(base_url('login')) ?>" class="px-6 py-2 bg-emerald-600 hover:bg-emerald-700 text-white rounded-lg font-semibold transition-colors">Login</a>
        </div>
        <div class="text-xs text-neutral-500 mt-2">
          <a href="<?= e(base_url('register')) ?>" class="text-emerald-400 hover:text-emerald-300">Create an account</a> to join the conversation
        </div>
      <?php else: ?>
        <!-- Reply bar ABOVE composer -->
        <div id="<?= $widget_id ?>-replybar" class="items-start gap-2">
          <div class="text-xs flex-1 min-w-0">
            <div class="font-semibold text-emerald-300">
              Replying to <span id="<?= $widget_id ?>-replybar-name"></span>
            </div>
            <div id="<?= $widget_id ?>-replybar-snippet" class="truncate text-neutral-300"></div>
          </div>
          <button id="<?= $widget_id ?>-replybar-cancel" class="text-neutral-300 hover:text-white" title="Cancel">✕</button>
        </div>

        <div id="<?= $widget_id ?>-error"></div>
        <div class="flex gap-3 items-end">
          <input type="hidden" id="<?= $widget_id ?>-csrf" value="<?= e($csrf_token) ?>">
          <input type="hidden" id="<?= $widget_id ?>-room" value="<?= e($current_room) ?>">
          <div class="flex-1">
            <textarea id="<?= $widget_id ?>-input" rows="1" maxlength="500" placeholder="Type your message…"
              class="w-full px-4 py-2 bg-black/40 border border-white/20 rounded-lg focus:outline-none focus:border-amber-500 text-white placeholder-neutral-400"
              autocomplete="off"></textarea>
          </div>
          <button type="button" id="<?= $widget_id ?>-send"
            class="px-6 py-2 bg-emerald-600 hover:bg-emerald-700 text-white rounded-lg font-semibold transition-colors disabled:opacity-50 disabled:cursor-not-allowed">
            Send
          </button>
		  
        </div>
        <div class="text-xs text-neutral-500 mt-2">Enter to send • Max 500 characters</div>
      <?php endif; ?>
    </div>
  </div>
</div>

<?php if ($userIsStaff): ?>
<!-- Staff moderation modal (PORTALED TO <body>) -->
<div id="<?= $widget_id ?>-mod-overlay" class="hidden" style="position:fixed; inset:0; background:rgba(0,0,0,.6); z-index:2147483646;"></div>
<div id="<?= $widget_id ?>-mod-modal" class="hidden" style="position:fixed; inset:0; z-index:2147483647;">
  <div class="min-h-full flex items-center justify-center p-4">
    <div id="<?= $widget_id ?>-mod-card" class="w-full max-w-md rounded-xl border border-white/10 bg-black/70 backdrop-blur p-5">
      <div class="flex items-center justify-between mb-3">
        <h3 class="text-lg font-bold">Moderate user</h3>
        <button id="<?= $widget_id ?>-mod-close" class="text-neutral-400 hover:text-white" title="Close">&times;</button>
      </div>
      <form id="<?= $widget_id ?>-mod-form" class="space-y-4">
        <div>
          <label class="block text-sm text-neutral-300 mb-1">Target (ID / @username / #nickname)</label>
          <input type="text" id="<?= $widget_id ?>-mute-target"
                 class="w-full px-3 py-2 rounded-md bg-black/40 border border-white/15 focus:outline-none focus:border-amber-500 text-white"
                 placeholder="123  •  @Arthas  •  #TheLichKing">
          <div class="text-xs text-neutral-500 mt-1">
            Tip: Click a username in chat to prefill. Use <code>@</code> for username, <code>#</code> for nickname, or a numeric ID. No prefix is okay—we'll try username first, then nickname.
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
          <input id="<?= $widget_id ?>-mute-duration"
                 class="w-full px-3 py-2 rounded-md bg-black/40 border border-white/15 focus:outline-none focus:border-amber-500 text-white"
                 list="<?= $widget_id ?>-durations" placeholder="15m, 1h, 1d, perm" value="15m">
          <datalist id="<?= $widget_id ?>-durations">
            <option value="15m"></option><option value="30m"></option><option value="1h"></option>
            <option value="12h"></option><option value="1d"></option><option value="3d"></option>
            <option value="7d"></option><option value="perm"></option>
          </datalist>
        </div>

        <div>
          <label class="block text-sm text-neutral-300 mb-1">Reason (optional)</label>
          <input id="<?= $widget_id ?>-mute-reason" maxlength="128"
                 class="w-full px-3 py-2 rounded-md bg-black/40 border border-white/15 focus:outline-none focus:border-amber-500 text-white"
                 placeholder="Reason for moderation">
        </div>

        <div class="text-xs text-amber-300 hidden" id="<?= $widget_id ?>-mod-error"></div>

        <div class="flex items-center justify-end gap-3 pt-2">
          <button type="button" id="<?= $widget_id ?>-btn-unmute" class="px-3 py-2 rounded-md border border-white/20 hover:bg-white/10">Unmute</button>
          <button type="submit" class="px-4 py-2 rounded-md bg-emerald-600 hover:bg-emerald-700 text-white font-semibold">Mute</button>
        </div>
      </form>
    </div>
  </div>
</div>
<?php endif; ?>

<!-- FIXED: Online Users Popover (PORTALED TO <body>) -->
<div id="<?= $widget_id ?>-online-panel" role="dialog" aria-modal="true" aria-labelledby="<?= $widget_id ?>-online-title" style="display:none">
  <div id="<?= $widget_id ?>-online-card">
    <div id="<?= $widget_id ?>-online-head">
      <div class="flex items-center gap-2">
        <div class="w-2 h-2 rounded-full bg-green-400"></div>
        <span id="<?= $widget_id ?>-online-title" class="text-sm font-semibold text-neutral-50">Online Users</span>
        <span id="<?= $widget_id ?>-online-count" class="online-count-pill">0</span>
      </div>
      <button id="<?= $widget_id ?>-online-close" class="text-neutral-300 hover:text-white" title="Close" aria-label="Close online users">&times;</button>
    </div>

    <!-- New: search bar -->
    <div id="<?= $widget_id ?>-online-searchbar">
      <input id="<?= $widget_id ?>-online-search" type="text" placeholder="Search user or @username…" autocomplete="off">
    </div>

    <div id="<?= $widget_id ?>-online-list">
      <!-- sections rendered by JS -->
      <div class="text-center text-neutral-400 text-xs py-3">Loading…</div>
    </div>
  </div>
</div>


<script>
(function(){
  /* --------- SAFE ELEMENT LOOKUPS --------- */
  var API_BASE    = <?= json_encode($API_BASE) ?>;
  var W           = '<?= $widget_id ?>';
  var LOGIN_URL   = <?= json_encode(base_url('login')) ?>;

  var toggleBtn   = document.getElementById(W + '-toggle');
  var dropdown    = document.getElementById(W + '-dropdown');
  var msgsWrap    = document.getElementById(W + '-messages');
  var empty       = document.getElementById(W + '-empty');
  var input       = document.getElementById(W + '-input');
  var sendBtn     = document.getElementById(W + '-send');
  var csrfEl      = document.getElementById(W + '-csrf');
  var roomEl      = document.getElementById(W + '-room');
  var bottomSentinel = msgsWrap ? msgsWrap.querySelector('[data-bottom-sentinel]') : null;

  var csrfToken   = csrfEl ? csrfEl.value : '';
  var currentRoom = roomEl ? roomEl.value : 'general';
  var isGuest     = <?= $is_guest ? 'true' : 'false' ?>;
  var userIsStaff = <?= $userIsStaff ? 'true' : 'false' ?>;
  var myUserId    = <?= (int)($user['id'] ?? 0) ?>;

  /* Online elements */
  var onlineBtn   = document.getElementById(W + '-open-online');
  var onlinePill  = document.getElementById(W + '-online-pill');
  var onlinePanel = document.getElementById(W + '-online-panel');
  var onlineCard  = document.getElementById(W + '-online-card');
  var onlineHead  = document.getElementById(W + '-online-head');
  var onlineClose = document.getElementById(W + '-online-close');
  var onlineList  = document.getElementById(W + '-online-list');
  var onlineCount = document.getElementById(W + '-online-count');

  /* --------- FIXED: REMOVED CLIENT-SIDE STAFF INFERENCE - TRUST SERVER ONLY --------- */
  function roleChipForUser(u){
    // TRUST ONLY SERVER'S is_staff BOOLEAN - NO CLIENT INFERENCE
    if (u.is_staff === true) {
      var css = String(u.role_css_class || '').trim();
      var slug = String(u.role_slug || '').toLowerCase().trim();
      var cls = css || ('role-' + (slug || 'staff'));
      var label = (u.role || slug || 'Staff').replace(/_/g,' ').replace(/\b\w/g, function(s){return s.toUpperCase();});
      return '<span class="role-chip '+escapeHtml(cls)+' has-sheen online-chip" title="'+escapeAttr(label)+'">'+escapeHtml(label)+'</span>';
    }
    // Only show VIP if NOT staff (staff takes precedence)
    if (u.is_staff !== true) {
      var vip = parseInt(u.vip_level||0,10);
      if (vip>0) return '<span class="role-chip vip-chip vip-l'+vip+' has-sheen online-chip" title="VIP'+vip+'">VIP'+vip+'</span>';
    }
    return '';
  }

  /* --------- DEBUG PERMISSION STATUS --------- */
  console.log('Chat Widget Permissions:', {
    isGuest: isGuest, userIsStaff: userIsStaff, myUserId: myUserId,
    authMethodsAvailable: {
      auth_is_admin: <?= function_exists('auth_is_admin') ? 'true' : 'false' ?>,
      auth_get_role_name: <?= function_exists('auth_get_role_name') ? 'true' : 'false' ?>,
      authPdoExists: <?= !empty($GLOBALS['authPdo']) ? 'true' : 'false' ?>
    }
  });

  /* --------- PERMISSION HELPERS --------- */
  function canDeleteMessage(messageUserId) { return userIsStaff || (String(messageUserId) === String(myUserId)); }
  function canEditMessage(messageUserId)   { return !isGuest && (String(messageUserId) === String(myUserId)); }
  function canPinMessage()                 { return userIsStaff; }
  function canModerateUsers()              { return userIsStaff; }
  function canReplyToMessage()             { return !isGuest; }

  /* --------- PRESENCE HEARTBEAT --------- */
  var HB_URL = API_BASE + 'chat-heartbeat.php';
  var HB_INTERVAL_MS = 30000; // 30s
  var hbTimer = null;

  function sendHeartbeat() {
    if (isGuest) return;
    try {
      var fd = new FormData();
      fd.append('_csrf', csrfToken);
      fd.append('room', currentRoom);
      fetch(HB_URL, { method: 'POST', body: fd, credentials: 'same-origin' }).catch(function(){});
    } catch(_) {}
  }
  function startHeartbeat(immediate) {
    if (isGuest) return;
    stopHeartbeat();
    if (document.hidden) return;
    if (immediate) sendHeartbeat();
    hbTimer = setInterval(sendHeartbeat, HB_INTERVAL_MS);
  }
  function stopHeartbeat() { if (hbTimer) { clearInterval(hbTimer); hbTimer = null; } }
  document.addEventListener('visibilitychange', function(){ if (document.hidden) stopHeartbeat(); else startHeartbeat(true); });
  window.addEventListener('pagehide', stopHeartbeat);
  startHeartbeat(true);

  /* --------- Portal dropdown to <body> --------- */
  (function(){ try { if (dropdown && dropdown.parentNode !== document.body) document.body.appendChild(dropdown); } catch(_) {} })();

  /* --------- Login expired banner helper --------- */
  function showLoginExpired(){
    try{
      var box = document.getElementById(W + '-error');
      if (box) box.innerHTML = '<div class="mb-2 px-3 py-2 rounded-md bg-amber-500/15 border border-amber-400/40 text-amber-200 text-sm">Session expired. <a class="underline hover:opacity-90" href="'+LOGIN_URL+'">Log in again</a> to continue chatting.</div>';
      if (input) { input.disabled = true; input.placeholder = 'Please log in again to chat'; }
      if (sendBtn) sendBtn.disabled = true;
    }catch(_){}
  }

  /* --------- Auth-aware fetch wrapper --------- */
  (function(){
    var _fetch = window.fetch.bind(window);
    function isSameOrigin(u){ try { return new URL(u, window.location.href).origin === window.location.origin; } catch(_) { return true; } }
    function ensureHeaders(opts){
      var h = new Headers(opts && opts.headers || {});
      if (!h.has('Accept')) h.set('Accept', 'application/json, text/plain, */*');
      if ((opts && (opts.method||'GET')).toUpperCase() !== 'GET') {
        if (!h.has('X-Requested-With')) h.set('X-Requested-With', 'XMLHttpRequest');
        if (csrfToken && !h.has('X-CSRF-Token')) h.set('X-CSRF-Token', csrfToken);
      }
      return h;
    }
    window.__chatOriginalFetch = _fetch;
    fetch = function(url, opts){
      opts = opts || {};
      opts.headers = ensureHeaders(opts);
      opts.credentials = isSameOrigin(url) ? 'same-origin' : 'include';
      return _fetch(url, opts).then(async function(res){
        if (res.status === 401 || res.status === 403) { showLoginExpired(); return res; }
        var ct = (res.headers.get('content-type') || '').toLowerCase();
        if (!ct.includes('application/json')) {
          try {
            var txt = await res.clone().text();
            if (/<form[^>]+(login|sign[\s-]?in)[^>]*>/i.test(txt)) showLoginExpired();
          } catch(_) {}
          return res;
        }
        try {
          var j = await res.clone().json();
          if (j && j.success === false && /auth|login|unauthor/i.test(String(j.error||''))) showLoginExpired();
        } catch(_) {}
        return res;
      });
    };
  })();

  /* Reply bar DOM/state */
  var replyBar       = document.getElementById(W + '-replybar');
  var replyBarName   = document.getElementById(W + '-replybar-name');
  var replyBarSnippet= document.getElementById(W + '-replybar-snippet');
  var replyBarCancel = document.getElementById(W + '-replybar-cancel');
  var replyTarget = null;

  var REPLY_MAP_KEY = 'chat_reply_map_' + currentRoom;
  var replyMap = (function(){ try{ var j=localStorage.getItem(REPLY_MAP_KEY); return j?JSON.parse(j):{}; }catch(_){ return {}; }})();
  function saveReplyMap(){ try{ var keys=Object.keys(replyMap); if(keys.length>500){ keys.sort(); for(var i=0;i<keys.length-500;i++) delete replyMap[keys[i]]; } localStorage.setItem(REPLY_MAP_KEY, JSON.stringify(replyMap)); }catch(_){} }
  function rememberReply(newId, snap){ if (!newId || !snap) return; replyMap[String(newId)] = { id: snap.id, name: snap.name, html: snap.html||'', text: snap.text||'' }; saveReplyMap(); }
  var clientReplyAugments = Object.create(null);

  /* Pinned bar DOM */
  var pinbar          = document.getElementById(W + '-pinbar');
  var pinnedBody      = document.getElementById(W + '-pin-body');
  var pinbarRemoveBtn = document.getElementById(W + '-pinbar-remove');
  var currentPinnedText = '';
  var pinPoll = null;

  /* Mute status */
  var muteBanner = document.getElementById(W + '-mute-banner');
  var muteDetails = document.getElementById(W + '-mute-details');

  var stickBottom = true;
  var isOpen = false;
  var poll = null;

  /* Online polling */
  var onlineTimer = null;
  var onlineOpen  = false;

  /* Chronology / local state */
  var lastTs = 0;
  var MAX_MSGS = 80;
  var displayed = new Set ? new Set() : { has:function(){return false;}, add:function(){}, clear:function(){} };

  /* Badge */
  var unseenCount = <?= (int)$unseen_count ?>;
  function ensureBadgeEl(){
    var b = document.getElementById(W + '-badge');
    if (b) return b;
    b = document.createElement('span');
    b.id = W + '-badge';
    b.className = 'absolute -top-1 -right-1 bg-red-500 text-white text-xs rounded-full h-5 w-5 flex items-center justify-center font-bold';
    var tb = document.getElementById(W + '-toggle'); if (tb) tb.appendChild(b);
    return b;
  }
  function renderBadge(){
    var el=document.getElementById(W+'-badge');
    if(unseenCount>0){ el=el||ensureBadgeEl(); if (!el) return; el.textContent=unseenCount>9?'9+':String(unseenCount); el.style.display=''; }
    else if(el){ el.style.display='none'; }
  }
  function bumpBadge(n){ unseenCount=Math.max(0, unseenCount + n); renderBadge(); }
  function clearBadge(){ unseenCount=0; renderBadge(); }
  renderBadge();

  /* Helpers */
  function slugifyRole(label){ if(!label) return 'player'; label=(label+'').toLowerCase().trim(); label=label.replace(/co[ \-–—]?owner/,'co_owner').replace(/[^\w\- ]+/g,''); return label.replace(/[\s\-]+/g,'_')||'player'; }
  function roleClassFromMessage(m){ if(m.role_css_class) return m.role_css_class; if(m.role_slug) return 'role-'+m.role_slug; if(m.user_role) return 'role-'+slugifyRole(m.user_role); return 'role-player'; }
  function escapeHtml(s){ var d=document.createElement('div'); d.textContent=String(s||''); return d.innerHTML; }
  function escapeAttr(s){ return String(s||'').replace(/&/g,'&amp;').replace(/"/g,'&quot;').replace(/'/g,'&#39;').replace(/</g,'&lt;').replace(/>/g,'&gt;'); }
  function formatTimeAgo(tsLike){
  var t=new Date(tsLike);
  if(isNaN(t)) return '';
  var now=new Date(), d=Math.floor((now-t)/1000);
  
  // Time display (short format)
  var timeStr;
  if(d<60) timeStr = 'now';
  else if(d<3600) timeStr = Math.floor(d/60)+'m ago';
  else if(d<86400) timeStr = Math.floor(d/3600)+'h ago';
  else timeStr = Math.floor(d/86400)+'d ago';
  
  // Full date/time for tooltip
  var fullDate = t.toLocaleDateString('en-US', {weekday:'short', month:'short', day:'numeric', year:t.getFullYear()!==now.getFullYear()?'numeric':undefined});
  var fullTime = t.toLocaleTimeString('en-US', {hour:'2-digit', minute:'2-digit', second:'2-digit'});
  var tooltip = fullDate + ' at ' + fullTime;
  
  // Return HTML with title attribute for tooltip
  return '<span title="'+escapeAttr(tooltip)+'">'+timeStr+'</span>';
}
  function vipLevelFromMessage(m){
    var keys=['vip_level','vip','vipLevel','user_vip_level','userVipLevel','vip_rank','vipRank','vip_tier','vipTier','tier','membership_tier','supporter_tier','donor_level','premium_level'];
    for(var i=0;i<keys.length;i++){ var k=keys[i]; if(m && m[k]!=null && m[k]!==''){ var n=parseInt(String(m[k]).replace(/[^\d]/g,''),10); if(isFinite(n)&&n>0) return Math.min(n,99); } }
    function fromStr(s){ var mm=String(s||'').match(/vip\s*([0-9]+)/i); return mm?Math.min(parseInt(mm[1],10),99):0; }
    if (m && typeof m.badges === 'string'){ var n=fromStr(m.badges); if(n>0) return n; }
    if (m && Array.isArray(m.badges)){ for(var j=0;j<m.badges.length;j++){ var b=m.badges[j]; if(typeof b==='string'){ var n2=fromStr(b); if(n2>0) return n2; } else if(b&&typeof b==='object'){ var n3=fromStr(b.label||b.name); if(n3>0) return n3; } } }
    return 0;
  }
  function linkifyMessage(text){
    var s=String(text||''); var re=/((?:https?:\/\/|www\.)[^\s<]+)/gi;
    var out='', last=0, m;
    while((m=re.exec(s))){
      var start=m.index; var url=m[1];
      var op=(url.match(/\(/g)||[]).length, cp=(url.match(/\)/g)||[]).length;
      if(cp>op && url.endsWith(')')){ url=url.slice(0,-1); re.lastIndex--; }
      var trail=''; while(/[.,!?;:)"'\]\u2019]$/.test(url)){ trail=url.slice(-1)+trail; url=url.slice(0,-1); re.lastIndex--; }
      out+=escapeHtml(s.slice(last,start)); last=start+m[0].length;
      var href=url.startsWith('www.')?'https://'+url:url;
      out+= '<a class="chat-link" href="'+escapeAttr(href)+'" target="_blank" rel="nofollow noopener ugc noreferrer">'+escapeHtml(url)+'</a>'+escapeHtml(trail);
    }
    out+=escapeHtml(s.slice(last)); return out;
  }
  function isNearBottom(el){ return (el.scrollHeight - el.scrollTop - el.clientHeight) <= 40; }
  function nudgeBottomOnce(){ if(!msgsWrap) return; var target=msgsWrap.scrollHeight-msgsWrap.clientHeight; if(target>0) msgsWrap.scrollTo({ top: target, behavior:'auto' }); }
  function ensureBottom(frames){ frames=frames||6; var i=0; function tick(){ nudgeBottomOnce(); if(++i<frames) requestAnimationFrame(tick); } requestAnimationFrame(tick); }

  if (msgsWrap){
    msgsWrap.addEventListener('scroll', function(){
      stickBottom=isNearBottom(msgsWrap);
      if(stickBottom){ clearNewPill(); if(isOpen) markAllSeen(); }
    });
  }

  /* New messages pill */
  var pendingNewCount=0, newPill=null;
  function ensureNewPill(){ if(newPill) return newPill; newPill=document.createElement('button'); newPill.type='button'; newPill.id=W+'-new-pill'; newPill.className='px-3 py-1 rounded-full text-xs font-semibold bg-emerald-600/90 hover:bg-emerald-600 text-white shadow'; newPill.style.display='none'; newPill.addEventListener('click', function(){ pendingNewCount=0; newPill.style.display='none'; ensureBottom(4); if(stickBottom) markAllSeen(); }); if(msgsWrap) msgsWrap.appendChild(newPill); return newPill; }
  function bumpNewPill(n){ if(!msgsWrap) return; pendingNewCount+=n; var pill=ensureNewPill(); pill.textContent=(pendingNewCount===1?'1 new message':String(pendingNewCount)+' new messages'); pill.style.display=''; }
  function clearNewPill(){ pendingNewCount=0; if(newPill) newPill.style.display='none'; }

  /* Seen sync */
  var SEEN_KEY='chat_seen_'+currentRoom;
  function markSeenLocal(){ try{ localStorage.setItem(SEEN_KEY, String(Date.now())); }catch(e){} }
  function markSeenServer(){
    if(isGuest) return;
    var fd=new FormData(); fd.append('_csrf', csrfToken); fd.append('room', currentRoom);
    return fetch(API_BASE+'chat-seen.php',{method:'POST', body:fd, credentials:'same-origin'}).then(function(r){return r.json();}).catch(function(){return {success:false};});
  }
  function markAllSeen(){ clearBadge(); markSeenLocal(); markSeenServer(); }
  window.addEventListener('storage', function(ev){ if(ev.key===SEEN_KEY) clearBadge(); });

  /* Place dropdown */
  function placeDropdown(){
    if(!dropdown || !toggleBtn) return;
    toggleBtn = document.getElementById(W + '-toggle') || toggleBtn;

    var rect=toggleBtn.getBoundingClientRect();
    var width=Math.min(800, Math.floor(window.innerWidth*0.95));
    dropdown.classList.add('chat-floater');

    var prevVis = dropdown.style.visibility;
    dropdown.style.visibility='hidden';
    dropdown.style.display='block';

    var h = dropdown.offsetHeight || 360;
    var left = Math.max(8, Math.min(rect.right - width, window.innerWidth - width - 8));
    var top  = Math.min(window.innerHeight - h - 8, rect.bottom + 8);
    left = Math.max(8, Math.min(left, window.innerWidth - width - 8));
    top  = Math.max(8, Math.min(top, window.innerHeight - h - 8));

    dropdown.style.setProperty('left',   left + 'px', 'important');
    dropdown.style.setProperty('top',    top  + 'px', 'important');
    dropdown.style.setProperty('right',  'auto',      'important');
    dropdown.style.setProperty('bottom', 'auto',      'important');
    dropdown.style.setProperty('width',  width + 'px','important');

    dropdown.style.visibility = prevVis || '';
    dropdown.style.display    = 'block';
  }

  /* Toggle open/close */
  function openChat(){
    try{
      isOpen=true;
      dropdown = document.getElementById(W + '-dropdown') || dropdown;
      if (!dropdown) return;

      dropdown.classList.remove('hidden');
      dropdown.style.pointerEvents = 'auto';
      dropdown.style.visibility    = 'hidden';
	  bindOutsideChatClose();
      dropdown.style.display       = 'block';

      placeDropdown();
      dropdown.style.visibility = '';

      sendHeartbeat();

      setTimeout(function(){
        try { loadPinned(); } catch(_){}
        try { loadMessages().then(function(){ if (stickBottom) markAllSeen(); }).catch(function(){}); } catch(_){}
        try { checkMuteStatus(); } catch(_){}
        try { refreshOnlineUsers(); } catch(_){}
        if(poll) clearInterval(poll);
        poll=setInterval(function(){ try{ pollMessages(); checkMuteStatus(); }catch(_){} }, 4000);
        if(pinPoll) clearInterval(pinPoll);
        pinPoll=setInterval(function(){ try{ loadPinned(); }catch(_){ } }, 20000);
        window.addEventListener('resize', placeDropdown, { passive:true });
        window.addEventListener('scroll', placeDropdown, { passive:true });
        setTimeout(function(){ if(input && !input.disabled) input.focus(); }, 80);
      }, 0);

      var t = document.getElementById(W + '-toggle'); if (t) t.setAttribute('aria-expanded','true');
    }catch(_){}
  }
  function closeChat(){
    try{
      isOpen=false;
      if (dropdown){ dropdown.classList.add('hidden'); dropdown.style.display='none'; }
      if(poll){ clearInterval(poll); poll=null; }
      if(pinPoll){ clearInterval(pinPoll); pinPoll=null; }
      window.removeEventListener('resize', placeDropdown);
      window.removeEventListener('scroll', placeDropdown);
      var t = document.getElementById(W + '-toggle'); if (t) t.setAttribute('aria-expanded','false');
	  unbindOutsideChatClose();
      closeOnlinePanel();
    }catch(_){}
  }
  
  /* === Close chat on outside click / ESC === */
function outsideChatHandler(ev){
  if (!isOpen) return;
  var t = ev.target;

  try {
    // inside the chat dropdown?
    if (dropdown && dropdown.contains(t)) return;

    // the toggle button itself?
    var tgl = document.getElementById(W + '-toggle');
    if (tgl && tgl.contains(t)) return;

    // inside the Online panel (portaled to <body>)?
    var op = document.getElementById(W + '-online-panel');
    if (op && op.style.display !== 'none' && op.contains(t)) return;

    // inside staff modal/overlay (if present)?
    var mo = document.getElementById(W + '-mod-overlay');
    var mm = document.getElementById(W + '-mod-modal');
    if ((mo && !mo.classList.contains('hidden') && mo.contains(t)) ||
        (mm && !mm.classList.contains('hidden') && mm.contains(t))) return;

    // otherwise: outside → close
    closeChat();
  } catch(_) {}
}
function escChatHandler(ev){
  if (ev.key === 'Escape' && isOpen) closeChat();
}
function bindOutsideChatClose(){
  document.addEventListener('mousedown', outsideChatHandler, true);
  document.addEventListener('touchstart', outsideChatHandler, true);
  document.addEventListener('keydown', escChatHandler, true);
}
function unbindOutsideChatClose(){
  document.removeEventListener('mousedown', outsideChatHandler, true);
  document.removeEventListener('touchstart', outsideChatHandler, true);
  document.removeEventListener('keydown', escChatHandler, true);
}


  /* Delegated toggle */
  document.addEventListener('click', function (e) {
    var btn = e.target && e.target.closest ? e.target.closest('#' + W + '-toggle') : null;
    if (!btn) return;
    e.preventDefault();
    try { toggleBtn = document.getElementById(W + '-toggle') || toggleBtn; dropdown  = document.getElementById(W + '-dropdown') || dropdown; if (isOpen){ closeChat(); } else { openChat(); } } catch (_) {}
  }, { passive: false });

  document.addEventListener('visibilitychange', function(){ try{ if (isOpen && !document.hidden && stickBottom) markAllSeen(); }catch(_){} });

  /* Mute */
  var currentMuteStatus = { muted:false };
  async function checkMuteStatus() {
    try {
      var url = API_BASE + 'chat-muted-status.php?room=' + encodeURIComponent(currentRoom);
      var res = await fetch(url, { credentials: 'same-origin' });
      var ct = (res.headers.get('content-type') || '').toLowerCase();
      if (!ct.includes('application/json')) return;
      var data = await res.json();
      if (data && typeof data.success === 'boolean') updateMuteUI(data);
    } catch (_) {}
  }
  function makeUpdateMuteUI(cfg){
    var inputEl=cfg.inputEl, sendBtnEl=cfg.sendBtnEl, detailsEl=cfg.detailsEl, bannerEl=cfg.bannerEl;
    return function updateMuteUI(status){
      currentMuteStatus = status || { muted:false };
      if (status && status.muted) {
        var rs=+status.remaining_seconds||0;
        var left = rs<=0 ? 'Permanent' : (rs>=3600? Math.floor(rs/3600)+'h '+Math.floor((rs%3600)/60)+'m left' : Math.floor(rs/60)+'m left');
        var reason = (status.reason ?? '').toString().trim();
        var line = reason ? (left+' • Reason: '+(reason.length>120?reason.slice(0,119)+'…':reason)) : left;
        if (detailsEl) { detailsEl.textContent = line; detailsEl.title = reason ? ('Reason: '+reason) : ''; }
        if (bannerEl) bannerEl.classList.remove('hidden');
        if (inputEl) { inputEl.disabled = true; inputEl.placeholder = 'You are muted and cannot send messages'; }
        if (sendBtnEl) sendBtnEl.disabled = true;
      } else {
        if (bannerEl) bannerEl.classList.add('hidden');
        if (detailsEl) { detailsEl.textContent = ''; detailsEl.title = ''; }
        if (inputEl) { inputEl.disabled = false; inputEl.placeholder = 'Type your message…'; }
        if (sendBtnEl) { sendBtnEl.disabled = false; }
      }
    };
  }
  var updateMuteUI = makeUpdateMuteUI({ inputEl:input, sendBtnEl:sendBtn, detailsEl:muteDetails, bannerEl:muteBanner });

  /* === Online list: renderers & grouping === */
function renderOnlineUser(u){
  var nameHtml = u.display_html || escapeHtml(u.display_name || u.nickname || u.username || ("User#" + (u.id || "")));
  var badge = roleChipForUser(u); // uses server is_staff + your badge CSS
  var attrs = [
    'class="chat-name-btn font-semibold text-white"',
    'data-user-id="'+escapeAttr(String(u.id||""))+'"',
    'data-username="'+escapeAttr(String(u.username||""))+'"',
    'data-nickname="'+escapeAttr(String(u.nickname||""))+'"',
    'data-is-staff="'+(u.is_staff===true?'true':'false')+'"',
    'title="'+(canModerateUsers()?'Moderate this user':'User profile')+'"'
  ].join(' ');

  return ''+
    '<div class="online-user-item" data-filter-text="'+escapeAttr((u.display_name||u.nickname||u.username||'').toLowerCase())+'">'+
      '<div class="online-user-status" aria-hidden="true"></div>'+
      '<div class="online-user-main">'+
        '<button type="button" '+attrs+'>'+nameHtml+'</button>'+
        (badge ? badge : '')+
      '</div>'+
    '</div>';
}

function renderSection(title, items){
  if (!items || !items.length) return '';
  return ''+
    '<div class="online-section">'+
      '<div class="online-section-head">'+
        '<span>'+escapeHtml(title)+'</span>'+
        '<span class="count">'+items.length+'</span>'+
      '</div>'+
      items.map(renderOnlineUser).join('')+
    '</div>';
}

async function loadOnlineUsers(){
  if (!onlineList || !onlineCount || !onlinePill) return;
  onlineList.innerHTML = '<div class="text-center text-neutral-400 text-xs py-3">Loading…</div>';
  if (isGuest) { onlineCount.textContent = '0'; onlinePill.textContent='0'; return; }

  try{
    var res = await fetch(API_BASE + 'chat-online-users.php?room=' + encodeURIComponent(currentRoom), { credentials:'same-origin' });
    var ct = (res.headers.get('content-type')||'').toLowerCase();
    if (!ct.includes('application/json')) throw new Error('Non-JSON');
    var data = await res.json();
    if (!data || data.success !== true || !Array.isArray(data.users)) throw new Error('Bad payload');

    var total = (typeof data.count === 'number') ? data.count : data.users.length;

    // Sort (staff first, vip desc, then name)
    data.users.sort(function(a, b){
      var aStaff = a.is_staff === true, bStaff = b.is_staff === true;
      if (aStaff !== bStaff) return bStaff - aStaff;
      var av = parseInt(a.vip_level||0, 10), bv = parseInt(b.vip_level||0, 10);
      if (av !== bv) return bv - av;
      var an = (a.display_name || a.nickname || a.username || '').toLowerCase();
      var bn = (b.display_name || b.nickname || b.username || '').toLowerCase();
      return an.localeCompare(bn);
    });

    // Group
    var staff   = data.users.filter(u => u.is_staff === true);
    var players = data.users.filter(u => u.is_staff !== true);

    // Render grouped sections
    var html = '';
    html += renderSection('Staff', staff);
    html += renderSection('Players', players);
    if (!html) html = '<div class="text-center text-neutral-500 text-xs py-3">No users online</div>';

    onlineList.innerHTML = html;
    onlineCount.textContent = String(total);
    onlinePill.textContent  = String(total);

    // Apply any current search filter to freshly-rendered list
    applyOnlineSearchFilter();
  } catch(err){
    console.warn('online load failed', err);
    onlineList.innerHTML = '<div class="text-center text-neutral-500 text-xs py-3">Couldn’t load online users.</div>';
    onlineCount.textContent = '0';
    onlinePill.textContent  = '0';
  }
}

/* === Search (client-side filter) === */
var onlineSearch = document.getElementById('<?= $widget_id ?>-online-search');
function applyOnlineSearchFilter(){
  if (!onlineSearch || !onlineList) return;
  var q = onlineSearch.value.toLowerCase().trim();
  var items = onlineList.querySelectorAll('.online-user-item');
  var anyVisible = false;
  items.forEach ? items.forEach(hideCheck) : Array.prototype.forEach.call(items, hideCheck);

  function hideCheck(el){
    var hay = String(el.getAttribute('data-filter-text')||'');
    var ok = !q || hay.includes(q);
    el.style.display = ok ? '' : 'none';
    if (ok) anyVisible = true;
  }

  // hide empty section blocks if everything inside is hidden
  var sections = onlineList.querySelectorAll('.online-section');
  sections.forEach ? sections.forEach(function(sec){
    var visibleChild = sec.querySelector('.online-user-item:not([style*="display: none"])');
    sec.style.display = visibleChild ? '' : 'none';
  }) : null;

  if (!anyVisible) {
    // show a tiny empty state (non-destructive)
    if (!onlineList.querySelector('[data-empty-search]')) {
      var d = document.createElement('div');
      d.setAttribute('data-empty-search','1');
      d.className = 'text-center text-neutral-500 text-xs py-3';
      d.textContent = 'No matches';
      onlineList.appendChild(d);
    }
  } else {
    var es = onlineList.querySelector('[data-empty-search]');
    if (es) es.remove();
  }
}
if (onlineSearch) onlineSearch.addEventListener('input', applyOnlineSearchFilter);

/* === small a11y: reflect expanded state on the button === */
function openOnlinePanel(){
  if (!isOpen) openChat();
  if (!onlinePanel) return;
  onlineOpen = true;
  onlinePanel.style.display = 'block';
  placeOnlinePanel();
  refreshOnlineUsers();
  var btn = document.getElementById(W + '-open-online');
  if (btn) btn.setAttribute('aria-expanded','true');
  window.addEventListener('resize', placeOnlinePanel, { passive:true });
  window.addEventListener('scroll', placeOnlinePanel, { passive:true });
  document.addEventListener('mousedown', outsideCloseHandler, true);
  document.addEventListener('keydown', escCloseHandler, true);
}
function closeOnlinePanel(){
  onlineOpen = false;
  if (onlinePanel) onlinePanel.style.display = 'none';
  if (onlineTimer) { clearInterval(onlineTimer); onlineTimer = null; }
  var btn = document.getElementById(W + '-open-online');
  if (btn) btn.setAttribute('aria-expanded','false');
  try {
    window.removeEventListener('resize', placeOnlinePanel, { passive: true });
    window.removeEventListener('scroll', placeOnlinePanel, { passive: true });
    document.removeEventListener('mousedown', outsideCloseHandler, true);
    document.removeEventListener('keydown', escCloseHandler, true);
  } catch (_) {}
}

  function toggleOnlinePanel(){ if (onlineOpen) closeOnlinePanel(); else openOnlinePanel(); }
  
  function outsideCloseHandler(e){
    if (!e || !e.target) return;
    if (!onlineOpen) return;
    var btn = document.getElementById(W+'-open-online');
    // FIXED: Robust containment checks
    try {
      if (onlinePanel && onlinePanel.contains(e.target)) return;
      if (btn && btn.contains(e.target)) return;
    } catch (_) {
      // Fallback: if containment check fails, don't close
      return;
    }
    closeOnlinePanel();
  }
  function escCloseHandler(e){ if (e.key === 'Escape') closeOnlinePanel(); }
  function refreshOnlineUsers(){
    loadOnlineUsers();
    if (onlineTimer) clearInterval(onlineTimer);
    onlineTimer = setInterval(function(){ if (onlineOpen) loadOnlineUsers(); }, 30000);
  }
  
  // FIXED: Prevent event leakage on button clicks
  if (onlineBtn) onlineBtn.addEventListener('click', function(e){ 
    e.preventDefault(); e.stopPropagation(); toggleOnlinePanel(); 
  });
  if (onlineClose) onlineClose.addEventListener('click', function(e){ e.stopPropagation(); closeOnlinePanel(); });

  /* Composer: auto-grow + keys */
  function autoGrow(el){ if(!el) return; el.style.height='auto'; var max=160; el.style.height=Math.min(el.scrollHeight, max)+'px'; }
  if (input && input.tagName === 'TEXTAREA') {
    autoGrow(input);
    input.addEventListener('input', function(){ autoGrow(input); });
    input.addEventListener('keydown', function(e){
      if ((e.key === 'Enter' && !e.shiftKey) || ((e.ctrlKey || e.metaKey) && e.key === 'Enter')) {
        e.preventDefault();
        handleSend();
      }
    });
  }
  if (!isGuest && sendBtn && input) sendBtn.addEventListener('click', function(){ handleSend(); });

  /* -------- EDIT FUNCTIONALITY -------- */
  function enterEditMode(messageRow, messageId) {
    if (messageRow.classList.contains('is-editing')) return;
    var msgEl = messageRow.querySelector('.msg'); if (!msgEl) return;
    var originalText = msgEl.textContent || '';
    var editControls = document.createElement('div');
    editControls.className = 'edit-controls';
    editControls.innerHTML =
      '<textarea class="edit-textarea" maxlength="500">' + escapeHtml(originalText) + '</textarea>' +
      '<div class="edit-buttons">' +
        '<button type="button" class="edit-btn edit-btn-save">Save</button>' +
        '<button type="button" class="edit-btn edit-btn-cancel">Cancel</button>' +
      '</div>';
    msgEl.parentNode.insertBefore(editControls, msgEl.nextSibling);
    messageRow.classList.add('is-editing');
    var textarea = editControls.querySelector('.edit-textarea');
    if (textarea) {
      textarea.focus(); textarea.select();
      autoGrow(textarea);
      textarea.addEventListener('input', function() { autoGrow(textarea); });
      textarea.addEventListener('keydown', function(e) {
        if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); saveEdit(messageRow, messageId, textarea.value.trim()); }
        else if (e.key === 'Escape') { e.preventDefault(); cancelEdit(messageRow); }
      });
    }
    var saveBtn = editControls.querySelector('.edit-btn-save');
    var cancelBtn = editControls.querySelector('.edit-btn-cancel');
    if (saveBtn) saveBtn.addEventListener('click', function(){ var val = textarea ? textarea.value.trim() : ''; saveEdit(messageRow, messageId, val); });
    if (cancelBtn) cancelBtn.addEventListener('click', function(){ cancelEdit(messageRow); });
  }
  function cancelEdit(messageRow) {
    messageRow.classList.remove('is-editing');
    var editControls = messageRow.querySelector('.edit-controls'); if (editControls) editControls.remove();
  }
  async function saveEdit(messageRow, messageId, newText) {
    if (!newText) { alert('Message cannot be empty'); return; }
    var textarea = messageRow.querySelector('.edit-textarea');
    var saveBtn = messageRow.querySelector('.edit-btn-save');
    var cancelBtn = messageRow.querySelector('.edit-btn-cancel');
    if (textarea) textarea.disabled = true;
    if (saveBtn) { saveBtn.disabled = true; saveBtn.textContent = 'Saving…'; }
    if (cancelBtn) cancelBtn.disabled = true;
    try {
      var fd = new FormData();
      fd.append('_csrf', csrfToken); fd.append('room', currentRoom);
      fd.append('id', messageId); fd.append('message_id', messageId);
      fd.append('message', newText);
      var res = await fetch(API_BASE + 'chat-edit.php', { method: 'POST', body: fd, credentials:'same-origin' });
      var result = await res.json();
      if (result && result.success) {
        var msgEl = messageRow.querySelector('.msg'); if (msgEl) msgEl.innerHTML = linkifyMessage(newText);
        cancelEdit(messageRow);
        if (!messageRow.querySelector('.edited-indicator')) {
          var timeSpan = messageRow.querySelector('.text-xs.text-neutral-500');
          if (timeSpan) {
            var editedSpan = document.createElement('span');
            editedSpan.className = 'edited-indicator text-xs text-neutral-400 ml-1';
            editedSpan.textContent = '(edited)';
            editedSpan.title = 'This message has been edited';
            timeSpan.appendChild(editedSpan);
          }
        }
      } else { throw new Error(result && result.error ? result.error : 'Edit failed'); }
    } catch (error) {
      alert('Failed to save edit: ' + (error.message || 'Unknown error'));
      if (textarea) textarea.disabled = false;
      if (saveBtn) { saveBtn.disabled = false; saveBtn.textContent = 'Save'; }
      if (cancelBtn) cancelBtn.disabled = false;
    }
  }

function placeOnlinePanel(){
  if (!onlinePanel) return;
  var anchor = document.getElementById(W+'-open-online');
  if (!anchor) return;

  // Measure panel (temporarily show to get size)
  var prevVis = onlinePanel.style.visibility;
  var prevDisp= onlinePanel.style.display;
  onlinePanel.style.visibility = 'hidden';
  onlinePanel.style.display    = 'block';

  var ph = onlinePanel.offsetHeight || 300;
  var pw = onlinePanel.offsetWidth  || 320;
  var r  = anchor.getBoundingClientRect();
  var gap = 8;

  // ✅ Align the panel's RIGHT edge with the button's RIGHT edge (old behavior)
  var left = r.right - pw;

  // Clamp into viewport
  left = Math.min(left, window.innerWidth - pw - 8);
  left = Math.max(left, 8);

  // Prefer below; flip above if necessary
  var top = r.bottom + gap;
  if (top + ph > window.innerHeight - 8) {
    top = Math.max(8, r.top - ph - gap);
  }

  // Apply
  onlinePanel.style.setProperty('left', left + 'px', 'important');
  onlinePanel.style.setProperty('top',  top  + 'px', 'important');
  onlinePanel.style.setProperty('right','auto','important');
  onlinePanel.style.setProperty('bottom','auto','important');

  // Optional: move the little arrow to point at the button
  try {
    var arrowX = Math.round((r.left + r.width/2) - left);
    arrowX = Math.max(16, Math.min(pw - 16, arrowX));
    (onlineCard || document.getElementById(W+'-online-card'))
      ?.style.setProperty('--arrow-left', arrowX + 'px');
  } catch(_) {}

  onlinePanel.style.visibility = prevVis || '';
  onlinePanel.style.display    = prevDisp || 'block';
}


  /* -------- PUBLIC REPLIES (marker-based) -------- */
  function consumeReplyMarker(m){
    try{
      var s = String(m && m.message || '');
      var re = /^\s*\[\[\s*r\s*:\s*([0-9]+)\s*\]\]\s*/i;
      var mm = s.match(re);
      if (mm){
        var pid = mm[1];
        m.reply_to_id = pid; m.reply_to = pid; m.parent_id = pid;
        m.message = s.replace(re, '');
      }
    }catch(_){}
  }
  function bestParentId(m){
    if (!m) return null;
    var keys = [
      'reply_to_id','reply_to','reply_id','reply_message_id','replyMessageId',
      'in_reply_to','in_reply_to_id','inReplyTo','parent_id','parentId','parent',
    ];
    for (var i=0;i<keys.length;i++){
      var v = m[keys[i]];
      if (v!=null && String(v).trim()!=='') return v;
    }
    if (m.reply && m.reply.id!=null) return m.reply.id;
    return null;
  }
  function augmentFromLocalMap(m){
    try{
      var stored = replyMap && m && replyMap[String(m.id)];
      if (stored){
        if (bestParentId(m) == null) m.reply_to_id = stored.id;
        if (!m.reply_to_user) m.reply_to_user = stored.name;
        if (!m.reply_to_user_html) m.reply_to_user_html = stored.html || '';
        if (!m.reply_to_text) m.reply_to_text = stored.text;
      }
    }catch(_){}
  }
  function renderReplyContextHTML(m){
    consumeReplyMarker(m);
    augmentFromLocalMap(m);
    try {
      if (m && m.id != null) {
        var a = clientReplyAugments[String(m.id)];
        if (a) {
          if (bestParentId(m)==null) m.reply_to_id = a.id;
          if (!m.reply_to_user) m.reply_to_user = a.name;
          if (!m.reply_to_user_html) m.reply_to_user_html = a.html || '';
          if (!m.reply_to_text) m.reply_to_text = a.text;
          delete clientReplyAugments[String(m.id)];
        }
      }
    } catch(_){}
    var parentId = bestParentId(m);
    var parentUser     = m.reply_to_user || (m.reply && (m.reply.user||m.reply.username)) || m.parent_user || m.reply_user || m.reply_username || '';
    var parentUserHtml = m.reply_to_user_html || (m.reply && (m.reply.user_html||m.reply.display_html)) || m.reply_user_html || '';
    var parentText     = m.reply_to_text || m.reply_text || (m.reply && (m.reply.text||m.reply.message)) || m.parent_text || m.reply_message || '';
    if (parentId && (!parentUserHtml || !parentText)){
      var dom = document.getElementById(W + '-msg-' + String(parentId));
      if (dom){
        if (!parentUserHtml){
          var nb = dom.querySelector('.chat-name-btn');
          parentUserHtml = nb ? nb.innerHTML : '';
        }
        if (!parentText){
          var me = dom.querySelector('.msg');
          parentText = me ? (me.textContent||'').trim() : parentText;
        }
        if (!parentUser){
          var nb2 = dom.querySelector('.chat-name-btn');
          parentUser = nb2 ? (nb2.textContent||'').trim() : parentUser;
        }
      }
    }
    if (!parentId && !parentText) return '';
    var pid   = escapeAttr(String(parentId || ''));
    var snipText = String(parentText||'');
    var snip  = snipText ? escapeHtml(snipText.slice(0, 120)) + (snipText.length>120?'…':'') : '<span class="opacity-60">(message)</span>';
    var nameOut = parentUserHtml && parentUserHtml.trim() ? parentUserHtml : escapeHtml(String(parentUser || 'Message'));
    return '<div class="reply-context"><span class="reply-jump" data-jump-to="'+pid+'">'+nameOut+'</span>: '+snip+'</div>';
  }

  async function handleSend(){
    if (currentMuteStatus.muted) { alert('You are muted and cannot send messages.'); return; }
    var raw=(input && input.value || '').trim(); if(!raw) return;
    var replySnapshot = replyTarget ? { id: replyTarget.id, name: replyTarget.name, html: replyTarget.nameHtml || '', text: replyTarget.snippet } : null;
    var msgToSend = raw;
    if (replySnapshot && replySnapshot.id) msgToSend = '[[r:'+replySnapshot.id+']] ' + raw;

    input.disabled=true; sendBtn.disabled=true;
    var fd=new FormData();
    fd.append('message', msgToSend);
    fd.append('room', currentRoom);
    if (replySnapshot && replySnapshot.id) { fd.append('reply_to_id', replySnapshot.id); fd.append('reply_to', replySnapshot.id); }
    fd.append('_csrf', csrfToken);

    try{
      var res=await fetch(API_BASE+'chat-send.php',{method:'POST', body:fd, credentials:'same-origin'}).then(function(r){ return r.json(); });
      if(res && res.success){
        var newId = String((res.id ?? res.message_id ?? (res.message && res.message.id) ?? (res.data && res.data.id) ?? ''));
        if (newId && replySnapshot){ clientReplyAugments[newId] = { id: replySnapshot.id, name: replySnapshot.name, html: replySnapshot.html || '', text: replySnapshot.text }; rememberReply(newId, replySnapshot); }
        input.value=''; autoGrow(input); clearReply();
        try { pollMessages(); } catch(_){}
        if(stickBottom) setTimeout(function(){ ensureBottom(2); }, 80);
      } else if(res && (res.muted || (res.error && String(res.error).toLowerCase().includes('muted')))) {
        checkMuteStatus();
      }
    }catch(_){}
    finally{
      if (!currentMuteStatus.muted) { input.disabled=false; sendBtn.disabled=false; input.focus(); }
    }
  }

  /* Timestamp helpers */
  function extractTs(m){
    if (m && m.ts && isFinite(+m.ts)) return Math.floor(+m.ts);
    var iso=m && (m.created_at||m.createdAt||m.time||m.date)||null;
    if(iso){ var t=Date.parse(iso); if(!isNaN(t)) return Math.floor(t/1000); }
    if (m && m.id && /^\d{10,}$/.test(String(m.id))) return Math.floor(+m.id);
    return 0;
  }
  function updateLastTsAfter(messages){ var maxTs=lastTs; for(var i=0;i<messages.length;i++){ var ts=extractTs(messages[i]); if(ts>maxTs) maxTs=ts; } lastTs=maxTs; }

  /* Pin helpers */
  function normalize(s){ return String(s||'').replace(/\s+/g,' ').trim(); }
  function renderPinnedBody(textOrHtml){
    if (!pinnedBody) return;
    if (!textOrHtml) { pinnedBody.innerHTML=''; return; }
    if (typeof textOrHtml === 'object' && textOrHtml.html) pinnedBody.innerHTML = String(textOrHtml.html);
    else pinnedBody.innerHTML = linkifyMessage(String(textOrHtml||''));
  }
  function updatePinbarVisibility(){
    if (!pinbar) return;
    if (currentPinnedText) { pinbar.classList.remove('hidden'); pinbar.classList.add('is-visible'); }
    else { pinbar.classList.add('hidden'); pinbar.classList.remove('is-visible'); }
  }
  function updatePinnedHighlights(){
    if (!msgsWrap) return;
    var want = normalize(currentPinnedText);
    var btns = msgsWrap.querySelectorAll('.chat-pin-btn');
    for (var i=0;i<btns.length;i++){
      var btn = btns[i];
      var row = btn.closest('.chat-message');
      var txt = normalize((row && row.querySelector('.msg') ? row.querySelector('.msg').textContent : '') || '');
      if (want && txt === want) btn.classList.add('is-active');
      else btn.classList.remove('is-active');
    }
  }
  
  function hashHue(key){
  try{
    var s = String(key||'x'), h=0; for(var i=0;i<s.length;i++){ h = (h*31 + s.charCodeAt(i))|0; }
    return ((h>>>0) % 360);
  }catch(_){ return 200; }
}
function getAvatarHTML(m, isMe, fallbackName){
  var src = m.avatar_url || m.user_avatar_url || m.user_avatar || m.avatar || '';
  var label = (m.display_name || m.username || fallbackName || 'U').trim();
  var initial = (label.charAt(0) || 'U').toUpperCase();
  if (src) {
    return '<div class="chat-avatar"><img src="'+escapeAttr(src)+'" alt="'+escapeAttr(label)+'" loading="lazy"></div>';
  }
  var hue = hashHue(m.user_id || label);
  return '<div class="chat-avatar" style="background:hsl('+hue+',64%,38%);">'+initial+'</div>';
}

/* === PRETTIER MESSAGE ROW === */
function renderMessageRow(m, roleClass, vipLevel, displayNameHtml, timeAgo, canDelete, canEdit){
  consumeReplyMarker(m);

  var isMe = String(m.user_id||'') === String(myUserId||'');
  var whoClass = isMe ? 'from-me' : 'from-them';

  var isStaffMsg = (m.user_role||'Player') !== 'Player';
  var badge = isStaffMsg
    ? '<span class="role-chip '+roleClass+' has-sheen" title="'+escapeHtml(m.user_role||'')+'">'+escapeHtml(m.user_role||'')+'</span>'
    : (vipLevel>0 ? '<span class="role-chip vip-chip vip-l'+vipLevel+' has-sheen" title="VIP'+vipLevel+'">VIP'+vipLevel+'</span>' : '');

  var replyContextHTML = renderReplyContextHTML(m);
  var editedIndicator  = (m.edited || m.is_edited) ? '<span class="edited-indicator">(edited)</span>' : '';

  var replyButton = canReplyToMessage() ? (
    '<button class="chat-reply-btn" title="Reply" aria-label="Reply">'+
      '<svg width="14" height="14" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M10 9V5l-7 7 7 7v-4.1c4.55 0 7.72 1.53 10 4.1-1-5-4-10-10-10z"/></svg>'+
    '</button>'
  ) : '';
  var editButton = canEdit ? (
    '<button class="chat-edit-btn" title="Edit" aria-label="Edit">'+
      '<svg width="14" height="14" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M3 17.25V21h3.75L17.81 9.94l-3.75-3.75L3 17.25zM20.71 7.04c.39-.39.39-1.02 0-1.41l-2.34-2.34c-.39-.39-1.02-.39-1.41 0l-1.83 1.83 3.75 3.75 1.83-1.83z"/></svg>'+
    '</button>'
  ) : '';
  var pinButton = canPinMessage() ? (
    '<button class="chat-pin-btn" title="Pin / Unpin" aria-label="Pin or unpin">'+
      '<svg width="14" height="14" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M14 2l8 8-4 4-2-2-4 4v4H8v-4l4-4-2-2 4-4z"/></svg>'+
    '</button>'
  ) : '';
  var deleteButton = canDelete ? (
    '<button class="chat-delete-btn" data-message-id="'+escapeHtml(m.id)+'" title="Delete" aria-label="Delete">'+
      '<svg width="14" height="14" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M6 7h12v12a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2V7zM8 9v8h8V9H8zM10 5V3h4v2h5v2H5V5h5z"/></svg>'+
    '</button>'
  ) : '';

  var row = document.createElement('div');
  row.className = 'chat-message '+whoClass;
  row.setAttribute('data-message-id', m.id);
  row.id = W + '-msg-' + String(m.id);
  row.setAttribute('data-user-id', m.user_id || '');
  row.setAttribute('data-username', m.username || '');
  row.setAttribute('data-nickname', m.nickname || '');
  row.setAttribute('data-vip-level', String(vipLevel||0));
  row.setAttribute('data-ts', String(extractTs(m)||0));
  var parentPoss = bestParentId(m);
  if (parentPoss) row.setAttribute('data-reply-to-id', String(parentPoss));

  var header =
    '<div class="bubble-head">'+
      badge+
      '<button type="button" class="chat-name-btn font-semibold text-white" title="'+(canModerateUsers() ? 'Moderate this user' : 'User profile')+'">'+
        displayNameHtml+
      '</button>'+
      '<span class="time">'+timeAgo+'</span>'+
    '</div>';

  row.innerHTML =
    '<div class="msg-row">'+
      getAvatarHTML(m, isMe, (m.display_name||m.username||"U"))+
      '<div class="bubble-wrap">'+
        header+
        '<div class="bubble">'+
          (replyContextHTML || '')+
          '<div class="msg"></div>'+
          '<div class="actionbar">'+
            replyButton+editButton+pinButton+deleteButton+
          '</div>'+
        '</div>'+
        '<div class="bubble-meta">'+
          (editedIndicator || '')+
        '</div>'+
      '</div>'+
    '</div>';

  // Fill message HTML
  var msgEl = row.querySelector('.msg');
  if (msgEl) msgEl.innerHTML = linkifyMessage(m.message || '');

  // Keep pin highlight behavior
  if (canPinMessage() && currentPinnedText) {
    var pinBtn = row.querySelector('.chat-pin-btn');
    var nowTxt = (row.querySelector('.msg') ? row.querySelector('.msg').textContent : '').replace(/\s+/g,' ').trim();
    if (pinBtn && nowTxt === currentPinnedText.replace(/\s+/g,' ').trim()) pinBtn.classList.add('is-active');
  }
  return row;
}

/* === INSERT + SMART COMPACT GROUPING === */
function insertMessageSorted(m){
  if (!msgsWrap) return;
  if (msgsWrap.querySelector('[data-message-id="'+m.id+'"]')) return;

  consumeReplyMarker(m);

  var ts = extractTs(m);
  var vipLevel = vipLevelFromMessage(m);
  var roleClass = roleClassFromMessage(m);
  var displayNameHtml = (typeof m.display_html==='string' && m.display_html.trim()!=='')
    ? m.display_html
    : escapeHtml(m.display_name || m.username || 'Unknown');
  var timeAgo = formatTimeAgo(m.created_at || m.createdAt || new Date().toISOString());
  var canDelete = canDeleteMessage(m.user_id || '');
  var canEdit   = canEditMessage(m.user_id || '');

  var row = renderMessageRow(m, roleClass, vipLevel, displayNameHtml, timeAgo, canDelete, canEdit);

  // Insert by timestamp (ascending)
  var nodes = msgsWrap.querySelectorAll('.chat-message');
  var inserted = false, prevNode = null, nextNode = null;
  for (var i = nodes.length - 1; i >= 0; i--) {
    var n = nodes[i]; var nt = +n.getAttribute('data-ts') || 0;
    if (nt <= ts) {
      prevNode = n;
      nextNode = n.nextSibling && n.nextSibling.classList && n.nextSibling.classList.contains('chat-message') ? n.nextSibling : null;
      if (n.nextSibling) msgsWrap.insertBefore(row, n.nextSibling);
      else msgsWrap.insertBefore(row, bottomSentinel);
      inserted = true; break;
    }
  }
  if (!inserted) {
    nextNode = nodes[0] || null;
    if (nodes[0]) msgsWrap.insertBefore(row, nodes[0]);
    else msgsWrap.insertBefore(row, bottomSentinel);
  }

  // Compact grouping: if same author within 6 minutes -> compact
  try{
    var SIX_MIN = 360; // seconds
    var myUid = row.getAttribute('data-user-id') || '';
    // previous neighbor
    var prev = row.previousElementSibling && row.previousElementSibling.classList.contains('chat-message')
      ? row.previousElementSibling : prevNode;
    if (prev) {
      var pu = prev.getAttribute('data-user-id') || '';
      var pts= +prev.getAttribute('data-ts') || 0;
      if (pu === myUid && Math.abs(ts - pts) <= SIX_MIN) {
        row.classList.add('is-compact');
      }
    }
    // also if next neighbor is same user and close, make next compact (helps on initial loads)
    var next = row.nextElementSibling && row.nextElementSibling.classList.contains('chat-message')
      ? row.nextElementSibling : nextNode;
    if (next) {
      var nu = next.getAttribute('data-user-id') || '';
      var nts= +next.getAttribute('data-ts') || 0;
      if (nu === myUid && Math.abs(nts - ts) <= SIX_MIN) {
        next.classList.add('is-compact');
      }
    }
  }catch(_){}

  // Trim overflow
  var all = msgsWrap.querySelectorAll('.chat-message');
  if (all.length > MAX_MSGS) {
    var toRemove = all.length - MAX_MSGS;
    for (var k = 0; k < toRemove; k++) { all[k].remove(); }
  }
}

  /* Render a message row */
  function renderMessageRow(m, roleClass, vipLevel, displayNameHtml, timeAgo, canDelete, canEdit){
    consumeReplyMarker(m);
    var row=document.createElement('div');
    row.className='chat-message group p-3 rounded-lg bg-black/20 hover:bg-black/30 transition-colors';
    row.setAttribute('data-message-id', m.id);
    row.id = W + '-msg-' + String(m.id);
    row.setAttribute('data-user-id', m.user_id || '');
    row.setAttribute('data-username', m.username || '');
    row.setAttribute('data-nickname', m.nickname || '');
    row.setAttribute('data-vip-level', String(vipLevel||0));
    row.setAttribute('data-ts', String(extractTs(m)||0));
    var parentPoss = bestParentId(m);
    if (parentPoss) row.setAttribute('data-reply-to-id', String(parentPoss));

    var isStaffMsg=(m.user_role||'Player')!=='Player';
    var badge = isStaffMsg
      ? '<span class="role-chip '+roleClass+' has-sheen" title="'+escapeHtml(m.user_role||'')+'">'+escapeHtml(m.user_role||'')+'</span>'
      : (vipLevel>0 ? '<span class="role-chip vip-chip vip-l'+vipLevel+' has-sheen" title="VIP'+vipLevel+'">VIP'+vipLevel+'</span>' : '');

    var replyContextHTML = renderReplyContextHTML(m);
    var editedIndicator = (m.edited || m.is_edited) ? '<span class="edited-indicator text-xs text-neutral-400 ml-1" title="This message has been edited">(edited)</span>' : '';

    var replyButton = canReplyToMessage() ? (
      '<button class="chat-reply-btn text-neutral-300 hover:text-white p-1 transition-opacity" title="Reply" aria-label="Reply to this message">'+
        '<svg width="14" height="14" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M10 9V5l-7 7 7 7v-4.1c4.55 0 7.72 1.53 10 4.1-1-5-4-10-10-10z"/></svg>'+
      '</button>'
    ) : '';
    var editButton = canEdit ? (
      '<button class="chat-edit-btn text-blue-400 hover:text-blue-300 p-1 transition-opacity" title="Edit message" aria-label="Edit this message">'+
        '<svg width="14" height="14" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M3 17.25V21h3.75L17.81 9.94l-3.75-3.75L3 17.25zM20.71 7.04c.39-.39.39-1.02 0-1.41l-2.34-2.34c-.39-.39-1.02-.39-1.41 0l-1.83 1.83 3.75 3.75 1.83-1.83z"/></svg>'+
      '</button>'
    ) : '';
    var pinButton = canPinMessage() ? (
      '<button class="chat-pin-btn text-emerald-400 hover:text-emerald-300 p-1 transition-opacity" title="Pin / Unpin" aria-label="Pin or unpin this message">'+
        '<svg width="14" height="14" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M14 2l8 8-4 4-2-2-4 4v4H8v-4l4-4-2-2 4-4z"/></svg>'+
      '</button>'
    ) : '';
    var deleteButton = canDelete ? (
      '<button class="chat-delete-btn text-red-400 hover:text-red-300 p-1 transition-opacity" data-message-id="'+escapeHtml(m.id)+'" title="Delete message" aria-label="Delete message">'+
        '<svg width="14" height="14" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M6 7h12v12a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2V7zM8 9v8h8V9H8zM10 5V3h4v2h5v2H5V5h5z"/></svg>'+
      '</button>'
    ) : '';

    row.innerHTML = ''+
      '<div class="flex items-start justify-between gap-2">'+
        '<div class="flex-1 min-w-0">'+
          '<div class="flex items-baseline gap-2 mb-1 flex-wrap">'+
            badge+
            '<button type="button" class="chat-name-btn font-semibold text-white '+(isStaffMsg||vipLevel>0 ? 'font-bold' : '')+'" title="'+(canModerateUsers() ? 'Moderate this user' : 'User profile')+'">'+
              displayNameHtml+
            '</button>'+
            '<span class="text-xs text-neutral-500">'+timeAgo+editedIndicator+'</span>'+
          '</div>'+
          replyContextHTML+
          '<div class="msg text-neutral-300"></div>'+
        '</div>'+
        '<div class="flex items-center gap-1">'+
          replyButton+editButton+pinButton+deleteButton+
        '</div>'+
      '</div>';

    var msgEl=row.querySelector('.msg'); if(msgEl) msgEl.innerHTML=linkifyMessage(m.message||'');
    if (canPinMessage() && currentPinnedText) {
      var pinBtn=row.querySelector('.chat-pin-btn');
      var nowTxt = (row.querySelector('.msg') ? row.querySelector('.msg').textContent : '').replace(/\s+/g,' ').trim();
      if (pinBtn && nowTxt === currentPinnedText.replace(/\s+/g,' ').trim()) pinBtn.classList.add('is-active');
    }
    return row;
  }

  function insertMessageSorted(m){
    if (!msgsWrap) return;
    if (msgsWrap.querySelector('[data-message-id="'+m.id+'"]')) return;

    consumeReplyMarker(m);

    var ts=extractTs(m);
    var vipLevel=vipLevelFromMessage(m);
    var roleClass=roleClassFromMessage(m);
    var displayNameHtml=(typeof m.display_html==='string' && m.display_html.trim()!=='')
      ? m.display_html
      : escapeHtml(m.display_name || m.username || 'Unknown');
    var timeAgo=formatTimeAgo(m.created_at||m.createdAt||new Date().toISOString());
    var canDelete = canDeleteMessage(m.user_id || '');
    var canEdit = canEditMessage(m.user_id || '');

    var row=renderMessageRow(m, roleClass, vipLevel, displayNameHtml, timeAgo, canDelete, canEdit);

    var nodes=msgsWrap.querySelectorAll('.chat-message');
    var inserted=false;
    for(var i=nodes.length-1;i>=0;i--){ var n=nodes[i]; var nt=+n.getAttribute('data-ts')||0;
      if(nt<=ts){ if(n.nextSibling) msgsWrap.insertBefore(row, n.nextSibling); else msgsWrap.insertBefore(row, bottomSentinel); inserted=true; break; } }
    if(!inserted){ var first=nodes[0]; if(first) msgsWrap.insertBefore(row, first); else msgsWrap.insertBefore(row, bottomSentinel); }

    var all=msgsWrap.querySelectorAll('.chat-message');
    if(all.length>MAX_MSGS){ var toRemove=all.length-MAX_MSGS; for(var k=0;k<toRemove;k++){ all[k].remove(); } }
  }

  async function loadMessages(){
    if (!msgsWrap) return;
    if (displayed.clear) displayed.clear();
    try{
      var res=await fetch(API_BASE+'chat-poll.php?room='+encodeURIComponent(currentRoom)+'&since=0',{credentials:'same-origin'}).then(function(r){return r.json();});
      if(res && res.success && Array.isArray(res.messages)){
        var msgs=res.messages.slice(-30).sort(function(a,b){return extractTs(a)-extractTs(b);});
        for (var i=0;i<msgs.length;i++){
          consumeReplyMarker(msgs[i]);
          var id=String(msgs[i].id);
          if(!(displayed.has && displayed.has(id))){ insertMessageSorted(msgs[i]); if(displayed.add) displayed.add(id); }
        }
        if (empty) empty.style.display='none';
        updateLastTsAfter(msgs);
        ensureBottom(6);
        clearNewPill();
      } else {
        if (empty) empty.innerHTML='<div class="text-center text-neutral-500 py-8"><div class="mb-3">💬</div><p>No messages yet.</p></div>';
      }
    }catch(_){
      if (empty) empty.innerHTML='<div class="text-center text-neutral-500 py-8"><div class="mb-3">💬</div><p>Couldn\'t load messages.</p></div>';
    }
    try { updatePinnedHighlights(); } catch(_){}
  }

  async function pollMessages(){
    if (!msgsWrap) return;
    try{
      var res=await fetch(API_BASE+'chat-poll.php?room='+encodeURIComponent(currentRoom)+'&since='+(lastTs||0),{credentials:'same-origin'}).then(function(r){return r.json();});
      if(res && res.success && Array.isArray(res.messages) && res.messages.length){
        var incoming=res.messages.filter(function(m){ return !(displayed.has && displayed.has(String(m.id))); }).sort(function(a,b){return extractTs(a)-extractTs(b);});
        if(incoming.length){
          var added=0;
          for (var i=0;i<incoming.length;i++){
            consumeReplyMarker(incoming[i]);
            insertMessageSorted(incoming[i]);
            if(displayed.add) displayed.add(String(incoming[i].id));
            added++;
          }
          updateLastTsAfter(incoming);
          if (isOpen && stickBottom){ ensureBottom(2); markAllSeen(); clearNewPill(); }
          else if (isOpen && !stickBottom){ bumpNewPill(added); }
          else if (!isOpen){ bumpBadge(added); }
          try { updatePinnedHighlights(); } catch(_){}
        }
      }
    }catch(_){}
  }

  /* Click handlers: pin / delete / reply / edit / jump-to */
  if (msgsWrap){
    msgsWrap.addEventListener('click', async function(ev){
      var target = ev.target;
      var closest = target && target.closest ? target.closest.bind(target) : function(){ return null; };
      var row = closest('.chat-message');

      var replyBtn = closest('.chat-reply-btn');
      if (replyBtn && row) {
        if (!canReplyToMessage()) { alert('You must be logged in to reply to messages'); return; }
        var id   = row.getAttribute('data-message-id');
        var nameBtn = row.querySelector('.chat-name-btn');
        var name = (nameBtn ? nameBtn.textContent : '').trim() || 'User';
        var nameHtml = nameBtn ? nameBtn.innerHTML : '';
        var snip = ((row.querySelector('.msg') ? row.querySelector('.msg').textContent : '') || '').trim().slice(0, 160);
        setReply({ id: id, name: name, nameHtml: nameHtml, snippet: snip });
        return;
      }

      var editBtn = closest('.chat-edit-btn');
      if (editBtn && row) {
        var messageUserId = row.getAttribute('data-user-id') || '';
        if (!canEditMessage(messageUserId)) { alert('You can only edit your own messages'); return; }
        var messageId = row.getAttribute('data-message-id');
        enterEditMode(row, messageId);
        return;
      }

      var pinBtn = closest('.chat-pin-btn');
      if (pinBtn && row) {
        if (!canPinMessage()) { alert('Only staff members can pin/unpin messages'); return; }
        var text = ((row.querySelector('.msg') ? row.querySelector('.msg').textContent : '') || '').trim();
        if (!text) return;
        try { if (normalize(text) === normalize(currentPinnedText)) await clearPinned(); else await setPinned(text); }
        catch (e) { alert(e && e.message ? e.message : 'Pin/unpin failed'); }
        return;
      }

      var delBtn = closest('.chat-delete-btn');
      if (delBtn && row) {
        var messageUserId = row.getAttribute('data-user-id') || '';
        if (!canDeleteMessage(messageUserId)) { alert('You can only delete your own messages'); return; }
        var id2 = delBtn.getAttribute('data-message-id') || row.getAttribute('data-message-id') || '';
        id2 = String(id2).trim();
        if (!/^\d+$/.test(id2)) return alert('Delete failed: Invalid message ID');
        if (!confirm('Delete this message?')) return;
        var fd=new FormData();
        fd.append('_csrf', csrfToken); fd.append('room', currentRoom); fd.append('id', id2); fd.append('message_id', id2);
        fetch(API_BASE+'chat-delete.php',{method:'POST', body:fd, headers:{'Accept':'application/json'}, credentials:'same-origin'})
          .then(function(r){return r.json();})
          .then(function(res){ if(res && res.success){ row.remove(); if(isOpen && stickBottom) ensureBottom(2); } else { alert('Delete failed: ' + (res && res.error ? res.error : 'Unknown error')); } })
          .catch(function(){ alert('Delete failed: Network error'); });
        return;
      }

var jump = closest('.reply-jump');
if (jump) {
  var pid = jump.getAttribute('data-jump-to'); if (!pid) return;
  var targetEl = document.getElementById(W + '-msg-' + pid) ||
                 (msgsWrap.querySelector ? msgsWrap.querySelector('[data-message-id="'+pid+'"]') : null);
  if (targetEl) {
    var wrap = msgsWrap;
    var desiredPad = 14; // keep in sync with CSS scroll-padding/scroll-margin
    var wrapRect = wrap.getBoundingClientRect();
    var targetRect = targetEl.getBoundingClientRect();
    var nextTop = wrap.scrollTop + (targetRect.top - wrapRect.top) - desiredPad;

    // clamp to bounds
    nextTop = Math.max(0, Math.min(nextTop, wrap.scrollHeight - wrap.clientHeight));

    wrap.scrollTo({ top: nextTop, behavior: 'smooth' });

    targetEl.classList.add('chat-highlight');
    setTimeout(function(){ targetEl.classList.remove('chat-highlight'); }, 1500);
  }
  return;
}

    });
  }

  /* Reply bar control */
  function setReply(target){
    replyTarget = target;
    if (replyBarName)    replyBarName.innerHTML    = target.nameHtml || escapeHtml(target.name || 'Message');
    if (replyBarSnippet) replyBarSnippet.textContent = target.snippet || '';
    if (replyBar) replyBar.classList.add('is-visible');
    if (input) input.focus();
  }
  function clearReply(){
    replyTarget = null;
    if (replyBar) replyBar.classList.remove('is-visible');
    if (replyBarName)    replyBarName.textContent    = '';
    if (replyBarSnippet) replyBarSnippet.textContent = '';
  }
  if (replyBarCancel) replyBarCancel.addEventListener('click', clearReply);

  /* Pinned fetchers */
  async function loadPinned(){
    try {
      var url = API_BASE + 'chat-pin-get.php?room=' + encodeURIComponent(currentRoom);
      var res = await fetch(url, { credentials:'same-origin' });
      var text = await res.text();
      var data = null; try { data = JSON.parse(text); } catch(e){}
      if (data && data.success && data.pinned) {
        var plain = data.pinned.text || data.pinned.message || '';
        currentPinnedText = String(plain || '');
        var body = (data.pinned.html ? { html: data.pinned.html } : plain);
        renderPinnedBody(body);
      } else {
        currentPinnedText = '';
        renderPinnedBody('');
      }
    } catch (_) {}
    updatePinbarVisibility();
    try { updatePinnedHighlights(); } catch(_){}
  }
  async function setPinned(text){
    var fd=new FormData(); fd.append('_csrf', csrfToken); fd.append('room', currentRoom); fd.append('text', text);
    var r=await fetch(API_BASE+'chat-pin-set.php',{method:'POST', body:fd, credentials:'same-origin'}); var j=await r.json().catch(function(){return null;});
    if(!j||!j.success) throw new Error((j && j.error) || 'Pin failed'); await loadPinned();
  }
  async function clearPinned(){
    var fd=new FormData(); fd.append('_csrf', csrfToken); fd.append('room', currentRoom);
    var r=await fetch(API_BASE+'chat-pin-clear.php',{method:'POST', body:fd, credentials:'same-origin'}); var j=await r.json().catch(function(){return null;});
    if(!j||!j.success) throw new Error((j && j.error) || 'Unpin failed'); await loadPinned();
  }
  if (pinbarRemoveBtn) pinbarRemoveBtn.addEventListener('click', async function(){
    if(!userIsStaff || !currentPinnedText) return;
    if(!confirm('Remove the pinned message?')) return;
    try{ await clearPinned(); }catch(e){ alert(e && e.message ? e.message : 'Unpin failed'); }
  });

  /* Staff moderation (portal to body) */
  var modOpenBtn=document.getElementById(W+'-open-moderate');
  var modOverlay =document.getElementById(W+'-mod-overlay');
  var modModal   =document.getElementById(W+'-mod-modal');
  var modClose   =document.getElementById(W+'-mod-close');
  var modForm    =document.getElementById(W+'-mod-form');
  var targetEl   =document.getElementById(W+'-mute-target');
  var durationEl =document.getElementById(W+'-mute-duration');
  var reasonEl   =document.getElementById(W+'-mute-reason');
  var errEl      =document.getElementById(W+'-mod-error');
  var btnUnmute  =document.getElementById(W+'-btn-unmute');

  (function portal(){
    if (modOverlay && modOverlay.parentNode!==document.body) document.body.appendChild(modOverlay);
    if (modModal && modModal.parentNode!==document.body) document.body.appendChild(modModal);
    var op = document.getElementById(W+'-online-panel');
    if (op && op.parentNode!==document.body) document.body.appendChild(op);
  })();
  function openMod(){ if(!canModerateUsers()) { alert('Access denied: Staff only'); return; } if (modOverlay) modOverlay.classList.remove('hidden'); if (modModal) modModal.classList.remove('hidden'); setTimeout(function(){ if (targetEl) targetEl.focus(); }, 30); }
  function closeMod(){ if (modOverlay) modOverlay.classList.add('hidden'); if (modModal) modModal.classList.add('hidden'); }
  if (modOpenBtn){
    modOpenBtn.addEventListener('click', function(){
      if(!targetEl) return;
      targetEl.value='';
      if(durationEl) durationEl.value=localStorage.getItem('muteDuration')||'15m';
      var scope=localStorage.getItem('muteScope')||'room';
      if (modForm){
        var r=modForm.querySelector('input[name="mute-scope"][value="'+scope+'"]')||modForm.querySelector('input[name="mute-scope"][value="room"]');
        if(r) r.checked=true;
      }
      if(reasonEl) reasonEl.value='';
      if(errEl){ errEl.textContent=''; errEl.classList.add('hidden'); }
      openMod();
    });
  }
  if (modOverlay) modOverlay.addEventListener('click', closeMod);
  if (modClose) modClose.addEventListener('click', closeMod);
  document.addEventListener('keydown', function(e){ if(e.key==='Escape') closeMod(); });
  if (msgsWrap){
    msgsWrap.addEventListener('click', function(ev){
      if(!canModerateUsers()) return;
      var btn = ev.target.closest ? ev.target.closest('.chat-name-btn') : null;
      if(!btn) return;
      var row = btn.closest ? btn.closest('.chat-message') : null;
      if(!row) return;
      if (!userIsStaff) { alert('Access denied: Staff only'); return; }
      var un=row.getAttribute('data-username')||'';
      var nn=row.getAttribute('data-nickname')||'';
      var uid=row.getAttribute('data-user-id')||'';
      if(targetEl) targetEl.value = un?('@'+un):(nn?('#'+nn):(uid||''));
      if(durationEl) durationEl.value=localStorage.getItem('muteDuration')||'15m';
      if(errEl){ errEl.textContent=''; errEl.classList.add('hidden'); }
      openMod();
    });
  }
  function appendTarget(fd){
    var raw=(targetEl && targetEl.value || '').trim();
    if(!raw) return false;
    if(raw[0]==='@'&&raw.length>1){ fd.append('username', raw.slice(1)); return true; }
    if(raw[0]==='#'&&raw.length>1){ fd.append('nickname', raw.slice(1)); return true; }
    if(/^\d+$/.test(raw)){ fd.append('user_id', raw); return true; }
    fd.append('username', raw); fd.append('nickname', raw); return true;
  }
  if (modForm){
    modForm.addEventListener('submit', function(e){
      e.preventDefault();
      if (!canModerateUsers()) { alert('Access denied: Staff only'); return; }
      if (!userIsStaff) { alert('Access denied: Staff only'); return; }
      var scope=(modForm.querySelector('input[name="mute-scope"]:checked') ? modForm.querySelector('input[name="mute-scope"]:checked').value : 'room');
      var dur=(durationEl && durationEl.value || '15m').trim();
      var rsn=(reasonEl && reasonEl.value || '').trim();
      localStorage.setItem('muteDuration', dur);
      localStorage.setItem('muteScope', scope);
      var fd=new FormData();
      fd.append('_csrf', csrfToken);
      if(scope==='room') fd.append('room', currentRoom);
      fd.append('duration', dur);
      if(rsn) fd.append('reason', rsn);
      if(!appendTarget(fd)){ if(errEl){ errEl.textContent='Enter an ID, @username, or #nickname.'; errEl.classList.remove('hidden'); } return; }
      fetch(API_BASE+'chat-mute.php',{method:'POST', body:fd, credentials:'same-origin'}).then(function(r){return r.json();}).then(function(res){ if(!res||!res.success) throw new Error(res&&res.error?res.error:'Mute failed'); closeMod(); alert('Muted successfully.'); checkMuteStatus(); }).catch(function(err){ if(errEl){ errEl.textContent=err && err.message ? err.message : 'Mute failed'; errEl.classList.remove('hidden'); } });
    });
  }
  if (btnUnmute){
    btnUnmute.addEventListener('click', function(){
      if (!canModerateUsers()) { alert('Access denied: Staff only'); return; }
      if (!userIsStaff) { alert('Access denied: Staff only'); return; }
      var scope=(modForm && modForm.querySelector('input[name="mute-scope"]:checked') ? modForm.querySelector('input[name="mute-scope"]:checked').value : 'room');
      var fd=new FormData();
      fd.append('_csrf', csrfToken);
      if(scope==='room') fd.append('room', currentRoom);
      if(!appendTarget(fd)){ if(errEl){ errEl.textContent='Enter an ID, @username, or #nickname to unmute.'; errEl.classList.remove('hidden'); } return; }
      fetch(API_BASE+'chat-unmute.php',{method:'POST', body:fd, credentials:'same-origin'}).then(function(r){return r.json();}).then(function(res){ if(res&&res.success){ closeMod(); alert('Unmuted.'); checkMuteStatus(); } else { throw new Error(res && res.error || 'Unmute failed'); } }).catch(function(err){ if(errEl){ errEl.textContent=err && err.message ? err.message : 'Unmute failed'; errEl.classList.remove('hidden'); } });
    });
  }

  /* Init: prep display set */
  try {
    var existing = document.querySelectorAll('#'+W+'-messages .chat-message');
    for (var i=0;i<existing.length;i++){ var id=existing[i].getAttribute('data-message-id'); if(id && displayed.add) displayed.add(String(id)); }
  } catch(_){}
})();
</script>
