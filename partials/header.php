<?php
/**
 * Header — no-flicker collapse (asymmetric thresholds) + logo hover + theme dropdown
 * - Burger on right, logo centered, text-nav + right actions collapse together
 * - Staff/VIP badge next to username trigger
 * - UPDATED: user display name uses Name FX (safe HTML) via nickname_helpers + name_effects.
 * - FIXED: Make Vote & Donate prominent in burger dropdown and right-actions (better visibility)
 */

declare(strict_types=1);

$u = function_exists('auth_user') ? auth_user() : ($_SESSION['user'] ?? null);

/* Helpers */
if (!function_exists('e')) {
  function e($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
}
if (!function_exists('base_url')) {
  function base_url(string $p=''): string { return '/'.ltrim($p,'/'); }
}
if (!function_exists('__thorium_root')) {
  function __thorium_root(): string {
    $dir = __DIR__;
    for ($i=0;$i<12;$i++){
      if (is_dir($dir.'/app') && is_dir($dir.'/public')) return $dir;
      $p = dirname($dir);
      if ($p === $dir) break;
      $dir = $p;
    }
    return __DIR__;
  }
}
$ROOT = __thorium_root();

/* Deps */
require_once $ROOT . '/app/modules_view.php';
require_once $ROOT . '/app/points_repo.php';

/* Nickname + Name FX (optional, safe) */
$HAS_NFX = false;
$NFX_FILE  = $ROOT . '/app/name_effects.php';
$NICK_FILE = $ROOT . '/app/nickname_helpers.php';
if (is_file($NICK_FILE)) require_once $NICK_FILE;
if (is_file($NFX_FILE)) {
  require_once $NFX_FILE;
  $HAS_NFX = true;
  if (function_exists('nfx_print_styles_once')) {
    nfx_print_styles_once(); // ensure .nfx styles exist in the header
  }
}

/** Build safe HTML for the current user's display name with Name FX if available. */
function header_user_display_html(?array $u): array {
  if (!$u) return ['plain'=>'','html'=>''];

  $plainFallback = (string)($u['nickname'] ?? $u['username'] ?? 'User');
  $pdo = $GLOBALS['pdo'] ?? null;

  // Preferred: centralized helpers (respect effect + emoji toggle)
  if ($pdo instanceof PDO && function_exists('get_user_display_name_html') && function_exists('get_user_display_name_plain')) {
    try {
      return [
        'plain' => get_user_display_name_plain($pdo, (int)$u['id']),
        'html'  => get_user_display_name_html($pdo, (int)$u['id']),
      ];
    } catch (Throwable $__) { /* fall through */ }
  }

  // Fallback: manual render (still respect emoji pref if available)
  if ($pdo instanceof PDO && function_exists('nfx_render_html') && function_exists('nfx_active_code')) {
    try {
      $code = nfx_active_code($pdo, (int)$u['id']);
      $includeEmoji = function_exists('nfx_include_emoji') ? nfx_include_emoji($pdo, (int)$u['id']) : true;
      return ['plain'=>$plainFallback, 'html'=>nfx_render_html($plainFallback, $code, $includeEmoji)];
    } catch (Throwable $__) { /* fall through */ }
  }

  // Last resort: escaped plain
  return ['plain'=>$plainFallback, 'html'=>e($plainFallback)];
}

$HAS_theme_path  = function_exists('theme_path');
$HAS_theme_asset = function_exists('theme_asset_url');

/* badges.css (force load) */
$badgeCssFs=null; $badgeCssUrl=null; $badgeVer='20250912';
if ($HAS_theme_path) {
  foreach (['css/badges.css','assets/css/badges.css','assets/badges.css','badges.css'] as $rel) {
    $fs = theme_path($rel);
    if ($fs && is_file($fs)) { $badgeCssFs=$fs; if ($HAS_theme_asset) $badgeCssUrl = theme_asset_url($rel).'?v='.$badgeVer; break; }
  }
}
if (!$badgeCssFs) {
  $cands = [
    $ROOT.'/public/css/badges.css' => base_url('public/css/badges.css').'?v='.$badgeVer,
    $ROOT.'/assets/css/badges.css' => base_url('assets/badges.css').'?v='.$badgeVer,
    $ROOT.'/assets/badges.css'     => base_url('assets/badges.css').'?v='.$badgeVer,
  ];
  foreach ($cands as $fs=>$url){ if (is_file($fs)) { $badgeCssFs=$fs; $badgeCssUrl=$url; break; } }
}
if ($badgeCssUrl) echo '<link rel="stylesheet" href="'.e($badgeCssUrl).'" id="badges-css">'.PHP_EOL;
if ($badgeCssFs && is_readable($badgeCssFs)) { echo "<style id='badges-css-inline'>"; readfile($badgeCssFs); echo "</style>".PHP_EOL; }
else if (!$badgeCssUrl) echo "<style id='badges-css-seed'>.role-chip{display:inline-flex;align-items:center;padding:.25rem .6rem;border-radius:6px;font-weight:900;background:#4c1d95;color:#fff}</style>".PHP_EOL;

/* RBAC / badge / points */
$userIsAdmin = false;
if ($u) {
  global $authPdo, $config;
  if (!empty($authPdo) && function_exists('auth_is_admin')) {
    $minPermissionId = (int)($config['admin_min_permission_id'] ?? 191);
    $userIsAdmin = auth_is_admin($authPdo, (int)$u['id'], $minPermissionId);
  } else { $userIsAdmin = !empty($u['is_admin']); }
}

$badge_html = '';
if ($u) {
  try {
    $role_name = 'Player';
    if (!empty($authPdo) && function_exists('auth_get_role_name')) {
      $tmp = (string)auth_get_role_name($authPdo, (int)$u['id']); if ($tmp!=='') $role_name=$tmp;
    }
    if ($role_name !== 'Player') {
      $map = ['Owner'=>'owner','Co-Owner'=>'co_owner','Staff Manager'=>'staff_manager','Administrator'=>'administrator','Head GM'=>'head_gm','Senior GM'=>'senior_gm','Initiate GM'=>'initiate_gm','Trial GM'=>'trial_gm'];
      $slug = $map[$role_name] ?? preg_replace('/_+/', '_', trim(strtolower(preg_replace('/[^a-z0-9]+/i','_', $role_name)), '_'));
      $badge_html = '<span class="role-chip role-'.e($slug).' has-sheen" title="'.e($role_name).'">'.e($role_name).'</span>';
    } else {
      $vip_level = 0;
      $repo = $ROOT . '/app/Repositories/donation_repository.php';
      if (is_file($repo)) {
        require_once $repo;
        if (!empty($GLOBALS['pdo']) && $GLOBALS['pdo'] instanceof PDO) {
          $donation_repo = new DonationRepository($GLOBALS['pdo']);
          $total_spent   = (float)$donation_repo->getUserTotalSpent((int)$u['id']);
          
          // Use the SAME tier logic as donation page for consistency
          $vip_tiers = [
              1 => 10,   2 => 20,   3 => 40,   4 => 60,
              5 => 80,  6 => 120,  7 => 160,  8 => 200
          ];
          
          // Calculate VIP level by checking against tier thresholds
          foreach ($vip_tiers as $tier => $amount) {
            if ($total_spent >= $amount) {
              $vip_level = $tier;
            }
          }
        }
      }
      if ($vip_level > 0) $badge_html = '<span class="vip-chip vip-l'.(int)$vip_level.' has-sheen" title="VIP Level '.(int)$vip_level.'">VIP'.(int)$vip_level.'</span>';
    }
  } catch (Throwable $e) { error_log('Header badge error: '.$e->getMessage()); }
}

$vote_points_num=0; $donation_points_num=0; $points_html='';
if ($u) {
  try {
    $balances = points_get_main((int)$u['id']);
    $vote_points_num = (int)($balances['vote'] ?? 0);
    $donation_points_num = (int)($balances['donation'] ?? 0);
    if ($vote_points_num>0 || $donation_points_num>0 || !empty($GLOBALS['authPdo']) || !empty($GLOBALS['pdo'])) {
      $points_html =
        '<span class="inline-flex items-center px-2 py-1 text-xs border border-emerald-400/40 bg-emerald-400/10 text-emerald-300">'.e((string)$vote_points_num)." VP</span> " .
        '<span class="inline-flex items-center px-2 py-1 text-xs border border-amber-400/40 bg-amber-400/10 text-amber-300">'.e((string)$donation_points_num)." DP</span>";
    }
  } catch (Throwable $e) { /* ignore */ }
}

/* Nav + logo */
$user_display = header_user_display_html($u);
$user_display_name_plain = $user_display['plain'];   // for titles/aria etc if needed
$user_display_name_html  = $user_display['html'];    // safe HTML with .nfx if active

$showArmory = function_exists('module_is_enabled') ? module_is_enabled('armory') : false;
$nav = [
  ['slug'=>'news','label'=>'News'],
  ['slug'=>'features','label'=>'Features'],
  ['slug'=>'how-to','label'=>'How to Connect'],
];
if ($showArmory) $nav[] = ['slug'=>'armory','label'=>'Armory'];
$nav[] = ['slug'=>'status','label'=>'Status'];
$scrollTargets = ['news'=>'#news','status'=>'#status'];

$logoCandidates = ['img/1thorium.png','assets/Testwebsitelogo.png','1thorium.png'];
$logoRel = null;
if ($HAS_theme_path) { foreach ($logoCandidates as $rel) { if (is_file(theme_path($rel))) { $logoRel = $rel; break; } } }

/* Navbar background asset (safe fallback if theme_asset_url missing) */
$navbarBgUrl = $HAS_theme_asset ? theme_asset_url('assets/NavbarBase.png') : base_url('assets/NavbarBase.png');
?>
<style>
/* ===== Scoped header styles (no flicker + hover; dropdown look from theme.css) ===== */
#site-header{
  position: sticky;
  top: max(0px, env(safe-area-inset-top)); /* always fully visible when stuck */
  z-index: 100;
  isolation: isolate;
  transform: translateY(var(--header-offset, 0)); /* applied only when not stuck */
}
#site-header.is-stuck{ transform: none; }

#site-header .header-inner { position:relative; container-type: inline-size; }

/* Layout anchors */
#site-header .left-rail{ display:flex; align-items:center; justify-content:flex-end; flex:0 1 auto; min-width:0; }
#site-header .right-rail{ position:absolute; right:0; top:50%; transform:translateY(-50%); padding-right:1rem; z-index:20; }
#site-header [data-site-logo]{ position:absolute; left:50%; top:50%; transform:translate(-50%,-50%); z-index:10; }

/* Logo hover glow */
@media (hover:hover){
  #site-header [data-site-logo]{ transition: transform .24s cubic-bezier(.4,0,.2,1), filter .24s cubic-bezier(.4,0,.2,1); }
  #site-header [data-site-logo]:hover{
    transform: translate(-50%,-50%) scale(1.03);
    filter: drop-shadow(0 0 12px rgba(52,211,153,.25));
  }
}

/* Burger pinned to right on mobile by default */
#site-header .burger-btn{ position:absolute; right:clamp(10px,3vw,18px); top:50%; transform:translateY(-50%); z-index:60; display:inline-flex; align-items:center; }

/* Base visibility (mobile-first) — dropdown needs overflow visible */
#site-header .nav-rail,
#site-header .right-actions-content{ display:none; white-space:nowrap; overflow:visible; }

/* ≥ md: show nav + right, hide burger (unless collapsed by class) */
@media (min-width:768px){
  #site-header .nav-rail,
  #site-header .right-actions-content{ display:flex; }
  #site-header .burger-btn{ display:none; }
}

/* When JS adds .nav-collapsed, force burger on desktop and hide both rails */
@media (min-width:768px){
  header.nav-glass.canopy.nav-collapsed .nav-rail,
  header.nav-glass.canopy.nav-collapsed .right-actions-content{
    display:none !important; visibility:hidden !important;
  }
  header.nav-glass.canopy.nav-collapsed .burger-btn{
    display:inline-flex !important;
  }
}

/* Allow burger → panel at desktop when collapsed: override md:hidden */
@media (min-width:768px){
  header.nav-glass.canopy.nav-collapsed #mobile-nav[data-open="true"]{
    display:block !important; /* beats .md:hidden even with important builds */
  }
}

/* Small cosmetics */
#site-header .nav-rail .nav-sep{ display:inline-block; width:1px; height:16px; background:rgba(255,255,255,.12); margin:0 .5rem; }
#site-header .user-menu .user-trigger .role-chip,
#site-header .user-menu .user-trigger .vip-chip{ transform:scale(.92); margin-right:.4rem; white-space:nowrap; }

/* Minimal dropdown behavior only (look comes from theme.css) */
#site-header .user-menu{ position:relative; }
#site-header .menu-panel{ display:none; }
#site-header .menu-panel[aria-hidden="false"]{ display:block; }

/* === Visibility driven by [data-open] (mobile/expanded desktop) === */
@media (max-width: 767.98px){
  #site-header #mobile-nav[data-open="true"]{ display:block; }
  #site-header #mobile-nav[data-open="false"]{ display:none; }
}

/* === Anchor the panel so it doesn't jump sides === */
/* Mobile: full-width dropdown just below the header */
@media (max-width: 767.98px){
  #site-header { position: relative; }
  #site-header #mobile-nav{
    position: absolute;
    top: 100%;
    left: 0;
    right: 0;
    z-index: 70;
  }
}

/* Desktop-collapsed: align panel to the RIGHT under the burger */
@media (min-width:768px){
  header.nav-glass.canopy.nav-collapsed #mobile-nav{
    position: absolute;
    top: 100%;
    right: 0;
    left: auto;
    width: min(92vw, 420px);
    z-index: 70;
    display: none; /* default hidden; JS flips on [data-open="true"] */
  }
  header.nav-glass.canopy.nav-collapsed #mobile-nav[data-open="true"]{
    display: block !important;
  }
}

/* =========================================
   ACTION CHIPS — Vote / Donate (Clean & Elegant)
   ========================================= */

.action-chip{
  display: inline-flex;
  align-items: center;
  gap: 0.5rem;
  font-size: 0.875rem;
  font-weight: 700;
  letter-spacing: 0.025em;
  padding: 0.6rem 1rem;
  border-radius: 0;
  border: 1px solid transparent;
  text-decoration: none;
  user-select: none;
  position: relative;
  overflow: hidden;
  transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);
}

.action-chip:hover{
  transform: translateY(-1px);
}

.action-chip:active{
  transform: none;
}

.action-chip:focus-visible{
  outline: none;
  box-shadow: 0 0 0 2px var(--tui-ring);
}

.action-chip > svg{
  inline-size: 1rem;
  block-size: 1rem;
  flex: none;
}

/* VOTE — Clean emerald design */
.action-chip--vote{
  background: linear-gradient(135deg, var(--vote-bg-from), var(--vote-bg-to));
  border-color: var(--vote-border);
  color: var(--chip-on-emerald-fg);
  box-shadow: 
    0 2px 4px var(--vote-shadow),
    inset 0 1px 0 rgba(255, 255, 255, 0.2);
}

.action-chip--vote:hover{
  background: linear-gradient(135deg, 
    color-mix(in srgb, var(--vote-bg-from) 90%, white 10%), 
    color-mix(in srgb, var(--vote-bg-to) 90%, white 10%));
  border-color: color-mix(in srgb, var(--vote-border) 90%, white 10%);
  box-shadow: 
    0 4px 8px var(--vote-shadow-hover),
    inset 0 1px 0 rgba(255, 255, 255, 0.3),
    0 0 20px rgba(78, 179, 34, 0.2);
}

/* DONATE — Clean teal-blue design */
.action-chip--donate{
  background: linear-gradient(135deg, var(--donate-bg-from), var(--donate-bg-to));
  border-color: var(--donate-border);
  color: var(--chip-on-amber-fg);
  box-shadow: 
    0 2px 4px var(--donate-shadow),
    inset 0 1px 0 rgba(255, 255, 255, 0.2);
}

.action-chip--donate:hover{
  background: linear-gradient(135deg, 
    color-mix(in srgb, var(--donate-bg-from) 90%, white 10%), 
    color-mix(in srgb, var(--donate-bg-to) 90%, white 10%));
  border-color: color-mix(in srgb, var(--donate-border) 90%, white 10%);
  box-shadow: 
    0 4px 8px var(--donate-shadow-hover),
    inset 0 1px 0 rgba(255, 255, 255, 0.3),
    0 0 20px rgba(79, 145, 163, 0.2);
}

/* Fallback for browsers without color-mix */
@supports not (color: color-mix(in srgb, black 0%, white 0%)) {
  .action-chip--vote:hover{
    background: linear-gradient(135deg, #59c52a, #6fe030);
    border-color: #7beb4d;
  }
  .action-chip--donate:hover{
    background: linear-gradient(135deg, #457e8d, #5aa0b4);
    border-color: #6bb5c6;
  }
}

/* Mobile full-width helper */
.action-chip.blockwide{ 
  display: flex; 
  justify-content: center; 
  width: 100%; 
}

/* Refined mobile action group — centered pill buttons */
.mobile-actions { 
  display: flex !important; 
  justify-content: center; 
  padding: 0.6rem 0; 
  visibility: visible !important;
}

.mobile-actions-inner { 
  display: flex !important; 
  gap: 0.6rem; 
  max-width: 380px; 
  width: 100%; 
  justify-content: center; 
  visibility: visible !important;
}

.mobile-actions .action-chip { 
  border-radius: 0px; 
  padding: 0.5rem 0.9rem; 
  font-weight: 800; 
  box-shadow: 0 6px 18px rgba(0,0,0,.12); 
  justify-content: center;   /* centers the inline-flex content horizontally */
  text-align: center;        /* ensures text stays centered if wrapping */
}

.mobile-actions .action-chip--vote,
.mobile-actions .action-chip--donate { 
  min-width: 160px; 
  justify-content: center;   /* redundant but ensures proper alignment */
  text-align: center; 
}

#mobile-nav .actions-card { 
  background: linear-gradient(180deg, rgba(255,255,255,0.02), rgba(255,255,255,0.01)); 
  border: 1px solid rgba(255,255,255,0.04); 
  padding: 0.75rem; 
  border-radius: 0px; 
  margin: 0.3rem 0 0 0; 
}

#mobile-nav .actions-card + .my-3 { 
  margin-top: 0.9rem; 
}


/* keep slight spacing when actions exist in right-rail */
.right-actions-content .action-chip{ margin-left:.45rem; }
</style>

<header id="site-header" class="nav-glass canopy"
        style="--navbar-img:url('<?= e($navbarBgUrl) ?>'); --header-nudge:-8px;">
  <script>document.documentElement.setAttribute('data-home', <?= json_encode(base_url('')) ?>);</script>

  <div class="container px-4 relative overflow-visible header-inner">

    <!-- LEFT: Nav -->
    <div class="left-rail">
      <nav class="nav-rail text-sm" id="navRail">
        <?php foreach ($nav as $idx => $i):
          $slug=$i['slug']; $label=$i['label'];
          $anchor=$scrollTargets[$slug]??null;
          $href=$anchor?(base_url('').$anchor):base_url($slug);
          $active = !$anchor && function_exists('route_is') && route_is($slug);
          if ($idx>0): ?><i class="nav-sep" aria-hidden="true"></i><?php endif; ?>
          <a class="nav-link <?= $active?'is-active':'' ?>" href="<?= e($href) ?>"><?= htmlspecialchars($label) ?></a>
        <?php endforeach; ?>
      </nav>
    </div>

    <!-- CENTER: Logo -->
    <a href="<?= e(base_url('')) ?>" data-site-logo aria-label="Thorium WoW Home">
      <?php if ($logoRel): ?>
        <?php $logoUrl = $HAS_theme_asset ? theme_asset_url($logoRel) : base_url($logoRel); ?>
        <img src="<?= e($logoUrl) ?>" alt="Thorium WoW" class="block select-none" style="image-rendering:auto;">
      <?php else: ?>
        <span class="h-display font-extrabold text-lg whitespace-nowrap">Thorium <span class="text-brand-400">WoW</span></span>
      <?php endif; ?>
    </a>

    <!-- RIGHT: Burger + actions -->
    <div class="right-rail">
      <button type="button" data-nav-toggle aria-controls="mobile-nav" aria-expanded="false"
              class="btn-ghost btn-sm burger-btn" aria-label="Toggle navigation">
        <svg class="w-4 h-4" fill="currentColor" viewBox="0 0 20 20" aria-hidden="true">
          <path fill-rule="evenodd" d="M3 5h14v2H3V5zm0 5h14v2H3v-2zm0 5h14v2H3v-2z" clip-rule="evenodd"></path>
        </svg>
      </button>

      <div class="right-actions-content items-center gap-2" id="rightActions">
        <?php if ($u): ?>
          <!-- Desktop visible action chips (compact but eye-catching) -->
          <a href="<?= e(base_url('vote')) ?>" class="action-chip action-chip--vote">Vote</a>
          <a href="<?= e(base_url('donate')) ?>" class="action-chip action-chip--donate">Donate</a>

          <div class="user-menu relative inline-flex">
            <button class="user-trigger" type="button" aria-haspopup="menu" aria-expanded="false" aria-controls="user-menu-panel">
              <?= $badge_html ?>
              <span class="user-name"><?= $user_display_name_html ?></span>
              <svg class="caret" viewBox="0 0 20 20" aria-hidden="true"><path d="M5.5 7.5l4.5 4.5 4.5-4.5z" fill="currentColor"/></svg>
            </button>

            <div id="user-menu-panel" class="menu-panel" role="menu" aria-hidden="true">
              <div class="menu-section balances" role="none">
                <div class="stat-tile">
                  <div class="stat-label">VP</div>
                  <div class="stat-value tabular-nums text-emerald-400"><?= number_format($vote_points_num) ?></div>
                </div>
                <div class="stat-tile">
                  <div class="stat-label">DP</div>
                  <div class="stat-value tabular-nums text-blue-400"><?= number_format($donation_points_num) ?></div>
                </div>
              </div>

              <div class="menu-section" role="none">
                <a class="menu-item" role="menuitem" href="<?= e(base_url('panel')) ?>">Panel</a>
                <?php if ($userIsAdmin): ?>
                  <a class="menu-item" role="menuitem" href="<?= e(base_url('admin')) ?>">Admin</a>
                <?php endif; ?>
                <a class="menu-item danger" role="menuitem" href="<?= e(base_url('logout')) ?>">Logout</a>
              </div>
            </div>
          </div>
        <?php else: ?>
          <a href="<?= e(base_url('login')) ?>"
             class="btn-ghost btn-sm <?= (function_exists('route_is') && route_is('login')) ? 'text-brand-400 bg-brand-400/10' : '' ?>">Login</a>
          <a href="<?= e(base_url('register')) ?>"
             class="btn-warm btn-sm ml-2 <?= (function_exists('route_is') && route_is('register')) ? 'bg-brand-400/20' : '' ?>">Register</a>
        <?php endif; ?>

        <?php if (function_exists('module_enabled') && module_enabled('header_chat')): ?>
          <?php include themed_partial_path('header-chat-widget'); ?>
        <?php endif; ?>
      </div>
    </div>

    <!-- Mobile panel -->
    <div id="mobile-nav" data-nav-panel data-open="false"
         class="md:hidden hidden border-t border-white/10 bg-bark-900/95 backdrop-blur-sm"
         role="navigation" aria-label="Mobile navigation">
      <nav class="container px-4 py-4 space-y-1">

        <?php if ($u): ?>
          <!-- PROMINENT: Put Vote/Donate first in mobile menu as full-width buttons -->
          <div class="mobile-actions">
            <div class="actions-card mobile-actions-inner">
              <a class="action-chip action-chip--vote" href="<?= e(base_url('vote')) ?>">Vote</a>
              <a class="action-chip action-chip--donate" href="<?= e(base_url('donate')) ?>">Donate</a>
            </div>
          </div>
        <?php endif; ?>

        <?php foreach ($nav as $i):
          $slug=$i['slug']; $label=$i['label'];
          $anchor=$scrollTargets[$slug]??null;
          $href=$anchor?(base_url('').$anchor):base_url($slug);
          $active = !$anchor && function_exists('route_is') && route_is($slug);
        ?>
          <a class="block py-2.5 px-3 rounded text-sm font-medium transition-colors <?= $active?'text-brand-400 bg-brand-400/10':'text-neutral-300 hover:text-brand-400 hover:bg-white/5' ?>"
             href="<?= e($href) ?>"><?= htmlspecialchars($label) ?></a>
        <?php endforeach; ?>

        <div class="my-3 h-px bg-white/10"></div>

        <?php if ($u): ?>

          <?php if ($badge_html || $points_html): ?>
            <div class="py-2 px-3 flex items-center gap-2 flex-wrap">
              <?= $badge_html ?> <?= $points_html ?>
            </div>
          <?php endif; ?>

          <div class="py-2 px-3 text-sm text-neutral-400">
            Logged in as <span class="text-brand-400 font-medium"><?= $user_display_name_html ?></span>
          </div>

          <a class="block py-2.5 px-3 rounded text-sm font-medium text-neutral-300 hover:text-brand-400 hover:bg-white/5 transition-colors"
             href="<?= e(base_url('panel')) ?>">Panel</a>
          <?php if ($userIsAdmin): ?>
            <a class="block py-2.5 px-3 rounded text-sm font-medium text-neutral-300 hover:text-brand-400 hover:bg-white/5 transition-colors"
               href="<?= e(base_url('admin')) ?>">Admin</a>
          <?php endif; ?>
          <a class="block py-2.5 px-3 rounded text-sm font-medium text-neutral-300 hover:text-red-400 hover:bg-red-400/5 transition-colors"
             href="<?= e(base_url('logout')) ?>">Logout</a>

        <?php else: ?>
          <a class="block py-2.5 px-3 rounded text-sm font-medium text-neutral-300 hover:text-brand-400 hover:bg-brand-400/10 transition-colors <?= (function_exists('route_is') && route_is('login')) ? 'text-brand-400 bg-brand-400/10' : '' ?>"
             href="<?= e(base_url('login')) ?>">Login</a>
          <a class="block py-2.5 px-3 rounded text-sm font-medium text-brand-400 hover:text-brand-300 hover:bg-brand-400/10 transition-colors <?= (function_exists('route_is') && route_is('register')) ? 'bg-brand-400/20' : '' ?>"
             href="<?= e(base_url('register')) ?>">Register</a>
        <?php endif; ?>
      </nav>

      <?php if (function_exists('module_enabled') && module_enabled('header_chat')): ?>
        <?php include themed_partial_path('header-chat-widget'); ?>
      <?php endif; ?>
    </div>
  </div>
</header>

<!-- Mobile panel toggle (click-based, debounced, [data-open]-driven) -->
<script>
(function(){
  const header = document.getElementById('site-header');
  if (!header) return;

  const panel  = header.querySelector('[data-nav-panel]');
  const toggle = header.querySelector('[data-nav-toggle]');
  if (!panel || !toggle) return;

  const html = document.documentElement;
  const mqDesktop = window.matchMedia('(min-width:768px)');

  const isDesktop   = () => mqDesktop.matches;
  const isCollapsed = () => header.classList.contains('nav-collapsed') && isDesktop();
  const isOpen      = () => panel.getAttribute('data-open') === 'true';

  function applyDisplay(open){
    if (isCollapsed()){
      // Override Tailwind's md:hidden only when collapsed on desktop
      panel.style.display = open ? 'block' : '';
    } else {
      // Mobile or expanded desktop: CSS controls via [data-open]
      panel.style.display = '';
    }
  }

  function setOpen(open){
    panel.setAttribute('data-open', open ? 'true' : 'false');
    toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
    html.classList.toggle('nav-open', open);
    // Keep a literal .hidden (from Tailwind) in sync if present
    panel.classList.toggle('hidden', !open);
    applyDisplay(open);
  }

  // Debounce to swallow duplicate handlers (click from multiple listeners)
  let last = 0;
  function onToggle(ev){
    ev.preventDefault();
    ev.stopPropagation();
    if (typeof ev.stopImmediatePropagation === 'function') ev.stopImmediatePropagation();
    const now = Date.now();
    if (now - last < 200) return;
    last = now;
    setOpen(!isOpen());
  }

  // Use 'click' to avoid “open only while pressed” on some touch stacks
  toggle.addEventListener('click', onToggle);

  // Keyboard a11y
  toggle.addEventListener('keydown', (e)=>{
    if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); onToggle(e); }
  });

  // Close on ESC anywhere
  document.addEventListener('keydown', (e)=>{
    if (e.key === 'Escape' && isOpen()) setOpen(false);
  });

  // React to header collapse/expand + resizes
  new MutationObserver(()=>applyDisplay(isOpen())).observe(header, { attributes:true, attributeFilter:['class'] });
  mqDesktop.addEventListener?.('change', ()=>applyDisplay(isOpen()));
  window.addEventListener('resize', ()=>applyDisplay(isOpen()), { passive:true });

  // Initial sync (respect existing markup state)
  setOpen(isOpen());
})();
</script>

<!-- User menu (theme look preserved) -->
<script>
(function(){
  const header = document.getElementById('site-header');
  if (!header) return;

  const qWrap   = el => el?.closest?.('.user-menu');
  const qPanel  = w  => w?.querySelector?.('#user-menu-panel');
  const qButton = w  => w?.querySelector?.('.user-trigger');

  function open(w){ const p=qPanel(w), b=qButton(w); if(!p||!b) return; p.setAttribute('aria-hidden','false'); b.setAttribute('aria-expanded','true'); }
  function close(w){ const p=qPanel(w), b=qButton(w); if(!p||!b) return; p.setAttribute('aria-hidden','true');  b.setAttribute('aria-expanded','false'); }
  function toggle(w){ const p=qPanel(w); if(!p) return; (p.getAttribute('aria-hidden')!=='false') ? open(w) : close(w); }

  // Toggle on pointerdown so we beat other click handlers
  header.addEventListener('pointerdown', (ev)=>{
    const trg = ev.target.closest('.user-trigger'); if (!trg) return;
    const wrap = qWrap(trg); if (!wrap) return;
    ev.preventDefault(); ev.stopPropagation();
    toggle(wrap);
  });

  // Close on outside pointer (capture so it always runs)
  document.addEventListener('pointerdown', (ev)=>{
    const openPanel = header.querySelector('.user-menu .menu-panel[aria-hidden="false"]');
    if (!openPanel) return;
    const wrap = openPanel.closest('.user-menu');
    if (wrap && wrap.contains(ev.target)) return;
    close(wrap);
  }, true);

  // Keyboard a11y
  header.addEventListener('keydown', (ev)=>{
    const wrap = qWrap(ev.target); if (!wrap) return;
    if ((ev.key==='Enter' || ev.key===' ') && ev.target.classList.contains('user-trigger')){ ev.preventDefault(); toggle(wrap); }
    else if (ev.key==='Escape'){ close(wrap); }
  });

  // Keep clicks inside the panel from bubbling to global closers
  header.addEventListener('click', (ev)=>{ if (ev.target.closest('.menu-panel')) ev.stopPropagation(); });

  // Close on window blur
  window.addEventListener('blur', ()=>{
    const p = header.querySelector('.user-menu .menu-panel[aria-hidden="false"]');
    if (p) close(p.closest('.user-menu'));
  });
})();
</script>

<!-- No-flicker collapse with asymmetric thresholds (burger shows sooner) -->
<script>
(function(){
  const header = document.getElementById('site-header'); if(!header) return;
  const container = header.querySelector('.header-inner');
  const nav   = header.querySelector('#navRail');
  const right = header.querySelector('#rightActions');
  const logo  = header.querySelector('[data-site-logo]');
  const panel = header.querySelector('#mobile-nav');
  const burger= header.querySelector('[data-nav-toggle]');

  // Tunables
  const GAP = 56;          // breathing room each side of logo
  const SAFETY = 4;        // extra slack
  const SHOW_LAG = 120;    // collapse at (need - SHOW_LAG) → smaller = show burger sooner
  const HIDE_EARLY = 80;   // expand at (need - HIDE_EARLY)

  let need = 992;
  let collapsed = false;
  let computed = false;
  let resizeTimer = null;

  function naturalWidth(el){
    if (!el) return 0;
    const s = el.style;
    const prev = { display:s.display, position:s.position, visibility:s.visibility, height:s.height, overflow:s.overflow };
    s.display='block'; s.position='absolute'; s.visibility='hidden'; s.height='auto'; s.overflow='visible';
    const w = Math.ceil(el.scrollWidth || el.getBoundingClientRect().width || 0);
    Object.assign(s, prev);
    return w;
  }

  function computeNeed(){
    const img = logo && logo.querySelector('img');
    const lw = Math.ceil(((img && img.getBoundingClientRect().width) || logo.getBoundingClientRect().width || 0));
    const nw = naturalWidth(nav);
    const rw = naturalWidth(right);
    need = Math.max(768, lw + 2*(GAP + Math.max(nw, rw)) + SAFETY);
    computed = true;
  }

  function closePanel(){
    if (!panel) return;
    panel.classList.add('hidden');
    panel.setAttribute('data-open','false');
    if (burger) burger.setAttribute('aria-expanded','false');
    document.documentElement.classList.remove('nav-open');
    panel.style.display = ''; // clear inline override
  }

  function apply(){
    const cw = Math.ceil(container.getBoundingClientRect().width || 0);
    if (!computed) computeNeed();
    const collapseAt = need - SHOW_LAG;
    const expandAt   = need - HIDE_EARLY;

    if (!collapsed && cw < collapseAt) {
      header.classList.add('nav-collapsed');
      collapsed = true;
    } else if (collapsed && cw > expandAt) {
      header.classList.remove('nav-collapsed');
      collapsed = false;
      closePanel(); // ensure panel is closed when rails come back
    }
  }

  function init(){ computeNeed(); apply(); }
  const go = ()=> requestAnimationFrame(init);

  const img = logo && logo.querySelector('img');
  if (img && img.complete === false) { img.decode?.().then(go).catch(go); } else { go(); }
  if (document.fonts && document.fonts.ready) document.fonts.ready.then(()=>requestAnimationFrame(()=>{ computeNeed(); apply(); }));

  window.addEventListener('resize', ()=>{ clearTimeout(resizeTimer); resizeTimer = setTimeout(apply, 120); }, { passive:true });
  window.matchMedia('(min-width: 768px)').addEventListener?.('change', ()=>{ computeNeed(); apply(); });
})();
</script>
