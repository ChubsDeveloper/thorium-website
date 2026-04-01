<?php
/**
 * pages/donate.php
 * Page template - renders the donate page with VIP progression graph
 * NOW WITH REAL PAYPAL INTEGRATION!
 */

declare(strict_types=1);

// Require authentication
if (!isset($_SESSION['user'])) {
    header('Location: ' . base_url('/login?next=' . urlencode('/donate')));
    exit;
}

// Get project root
if (!function_exists('__thorium_root')) {
    function __thorium_root(): string {
        $dir = __DIR__;
        for ($i = 0; $i < 12; $i++) {
            if (is_dir($dir . DIRECTORY_SEPARATOR . 'app') && is_dir($dir . DIRECTORY_SEPARATOR . 'public')) {
                return $dir;
            }
            $parent = dirname($dir);
            if ($parent === $dir) break;
            $dir = $parent;
        }
        return __DIR__;
    }
}

$ROOT = __thorium_root();

// Include the repository
require_once $ROOT . '/app/Repositories/donation_repository.php';

// Get database connections
$db = null;
$authDb = null;
if (isset($GLOBALS['pdo']) && $GLOBALS['pdo'] instanceof PDO) {
    $db = $GLOBALS['pdo'];
} elseif (function_exists('get_database_connection')) {
    $db = get_database_connection();
}
if (isset($GLOBALS['authPdo']) && $GLOBALS['authPdo'] instanceof PDO) {
    $authDb = $GLOBALS['authPdo'];
}
if (!$db) {
    die('Database connection not available');
}

// Create repository instance
$donation_repo = new DonationRepository($db);
$user_id = (int) $_SESSION['user']['id'];

/**
 * Map a package row to a tier slug used for styling.
 * Accepts fields: tier | name | sku (case-insensitive).
 */
function pkg_tier_slug(array $p): string {
    $s = strtolower(trim((string)($p['tier'] ?? $p['name'] ?? $p['sku'] ?? '')));
    if (str_contains($s, 'copper'))  return 'copper';
    if (str_contains($s, 'silver'))  return 'silver';
    if (str_contains($s, 'gold'))    return 'gold';
    if (str_contains($s, 'diamond')) return 'diamond';
    return 'emerald'; // fallback to site brand
}

// Get data for page
$donation_packages = $donation_repo->getDonationPackages();
$user_points       = $donation_repo->getUserDonationPoints($user_id);
$vote_points       = $donation_repo->getUserVotePoints($user_id);
$donation_history  = $donation_repo->getUserDonationHistory($user_id, 5);
$top_donors        = $donation_repo->getTopDonors(10);
$donation_stats    = $donation_repo->getDonationStats();
$total_spent       = $donation_repo->getUserTotalSpent($user_id);

// VIP Tier Thresholds
$vip_tiers = [
    1 => 10,   2 => 20,   3 => 40,   4 => 60,
    5 => 80,  6 => 120,  7 => 160,  8 => 200
];

// Calculate VIP level: Check SpecialRank first, then fall back to donations
$vip_level = 0;

// First, try to get SpecialRank from auth.account_access
if ($authDb instanceof PDO) {
    try {
        $st = $authDb->prepare("SELECT `SpecialRank` FROM `account_access` WHERE `AccountID` = ? LIMIT 1");
        if ($st->execute([$user_id])) {
            $specialRank = $st->fetchColumn();
            if ($specialRank !== false && $specialRank !== null) {
                $vip_level = (int)$specialRank;
            }
        }
    } catch (Throwable $e) {
        // If query fails, fall through to donation-based calculation
    }
}

// If SpecialRank is 0 or not found, calculate from donations
if ($vip_level === 0) {
    foreach ($vip_tiers as $tier => $amount) {
        if ($total_spent >= $amount) {
            $vip_level = $tier;
        }
    }
}

// VIP progression calculations
$current_tier_amount = $vip_level > 0 ? $vip_tiers[$vip_level] : 0;
$next_tier = $vip_level < 8 ? $vip_level + 1 : 8;
$next_tier_amount = $vip_tiers[$next_tier];
$progress_in_current_tier = $vip_level > 0 ? $total_spent - ($vip_level > 1 ? $vip_tiers[$vip_level - 1] : 0) : $total_spent;
$tier_range = $vip_level > 0 ? ($current_tier_amount - ($vip_level > 1 ? $vip_tiers[$vip_level - 1] : 0)) : 20;
$progress_percentage = $vip_level >= 8 ? 100 : (($total_spent / $next_tier_amount) * 100);
$remaining_for_next = $vip_level >= 8 ? 0 : max(0, $next_tier_amount - $total_spent);

// Generate CSRF token
if (!isset($_SESSION['_csrf'])) {
    $_SESSION['_csrf'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['_csrf'];
?>

<section class="container px-4 pt-16 md:pt-36 pb-24 md:pb-40">
  <!-- Header Card -->
  <div class="relative rough-card overflow-hidden p-0 shine">
    <div class="absolute inset-y-0 left-0 w-1.5 bg-gradient-to-b from-emerald-400/80 to-blue-500/60"></div>
    <div class="p-6 md:p-8">
      <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-4">
        <div>
          <p class="kicker">Support Our Community</p>
          <h1 class="h-display text-3xl md:text-4xl font-extrabold">Support <?= e($GLOBALS['config']['server_name'] ?? 'Thorium') ?></h1>
          <p class="mt-2 muted">Your donations help keep our server running and improve the gaming experience for everyone!</p>
        </div>
        <div class="flex items-center gap-2">
          <a href="<?= e(base_url('panel')) ?>" class="btn-ghost">Character Panel</a>
          <a href="<?= e(base_url('vote')) ?>" class="btn-ghost">Vote</a>
        </div>
      </div>

      <!-- Success/Error Messages -->
      <?php if (isset($_SESSION['donation_success'])): ?>
      <div class="mt-6 rough-card p-4 text-sm text-emerald-300 border-emerald-500/30">
        <div class="flex items-center gap-2">
          <svg class="w-5 h-5 text-emerald-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
          </svg>
          <?= e($_SESSION['donation_success']) ?>
        </div>
      </div>
      <?php unset($_SESSION['donation_success']); endif; ?>

      <?php if (isset($_SESSION['donation_error'])): ?>
      <div class="mt-6 rough-card p-4 text-sm text-red-300 border-red-500/30">
        <div class="flex items-center gap-2">
          <svg class="w-5 h-5 text-red-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
          </svg>
          <?= e($_SESSION['donation_error']) ?>
        </div>
      </div>
      <?php unset($_SESSION['donation_error']); endif; ?>

      <!-- Enhanced Stats Display -->
      <div class="mt-6 grid gap-4 sm:grid-cols-4">
        <div class="rounded-xl border border-white/10 p-4 bg-white/[0.03]">
          <div class="text-[11px] uppercase tracking-wide text-neutral-300/80">Vote Points</div>
          <div class="mt-1 text-2xl font-extrabold tabular-nums text-emerald-400"><?= e((string)$vote_points) ?></div>
        </div>
        <div class="rounded-xl border border-white/10 p-4 bg-white/[0.03]">
          <div class="text-[11px] uppercase tracking-wide text-neutral-300/80">Donation Points</div>
          <div class="mt-1 text-2xl font-extrabold tabular-nums text-blue-400"><?= e((string)$user_points) ?></div>
        </div>
        <div class="rounded-xl border border-white/10 p-4 bg-white/[0.03] flex items-center justify-between">
          <div>
            <div class="text-[11px] uppercase tracking-wide text-neutral-300/80">VIP Level</div>
            <div class="mt-1 text-2xl font-extrabold"><?= $vip_level > 0 ? "VIP {$vip_level}" : "None" ?></div>
          </div>
          <?php if ($vip_level > 0): ?>
          <span class="text-[11px] px-2 py-1 rounded bg-emerald-500/20 border border-emerald-500/30 text-emerald-300 font-bold">
            LEVEL <?= $vip_level ?>
          </span>
          <?php endif; ?>
        </div>
        <div class="rounded-xl border border-white/10 p-4 bg-white/[0.03]">
          <div class="text-[11px] uppercase tracking-wide text-neutral-300/80">Total Contributed</div>
          <div class="mt-1 text-2xl font-extrabold tabular-nums text-emerald-400">$<?= number_format($total_spent, 2) ?></div>
          <?php if ($total_spent > 0 && $vip_level < 8): ?>
          <div class="text-[10px] uppercase tracking-wide text-neutral-400/60 mt-1">
            $<?= number_format($remaining_for_next, 2) ?> to VIP <?= $next_tier ?>
          </div>
          <?php elseif ($vip_level >= 8): ?>
          <div class="text-[10px] uppercase tracking-wide text-emerald-400/80 mt-1">
            MAX VIP ACHIEVED!
          </div>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </div>

  <!-- Main Content Grid -->
  <div class="mt-6 grid gap-6 md:grid-cols-3">
    <!-- Donation Packages Section -->
    <div class="md:col-span-2 space-y-6">
      <div class="rough-card p-0 overflow-hidden">
        <div class="px-6 pt-5">
          <h2 class="font-semibold">
            <span class="inline-block bg-gradient-to-r from-emerald-300 via-lime-300 to-emerald-400 bg-clip-text text-transparent align-baseline [text-shadow:0_0_12px_rgba(34,197,94,.25)]">
              Donation Packages
            </span>
            <span class="text-neutral-200/90"> — Support & Rewards</span>
          </h2>
          <p class="text-sm text-neutral-400 mt-1">Choose a donation package to support the server and earn Donation Points for exclusive rewards.</p>
        </div>

        <div class="px-6 pb-6">
          <?php if (empty($donation_packages)): ?>
            <p class="mt-4 text-sm text-neutral-400">No donation packages available yet.</p>
          <?php else: ?>
            <div class="mt-4 grid gap-6 sm:grid-cols-2">
              <?php foreach ($donation_packages as $package_sku => $package): ?>
<?php $tier = pkg_tier_slug($package); ?>
<div class="rounded-xl border border-white/10 bg-white/[0.03] p-6 ring-1 ring-white/10 tilt group transition-all duration-200 hover:transform hover:scale-[1.02] relative pkg pkg-<?= e($tier) ?>">

  <?php if (!empty($package['recommended'])): ?>
  <div class="absolute -top-3 left-1/2 transform -translate-x-1/2">
    <span class="bg-emerald-400 text-emerald-900 text-xs font-bold px-3 py-1 rounded-full">RECOMMENDED</span>
  </div>
  <?php endif; ?>

  <div class="text-center mb-4">
    <!-- Title uses package tier color -->
    <h3 class="text-xl font-bold mb-2 title-accent"><?= e($package['name']) ?></h3>

    <!-- Price: white -->
    <div class="text-3xl font-extrabold text-white mb-1">
      $<?= number_format((float)$package['amount'], 2) ?>
    </div>

    <!-- Base points: emerald -->
    <div class="font-medium text-emerald-300">
      <?= e((string)$package['points']) ?> Base Points
    </div>

    <?php if (!empty($package['bonus_points']) && (int)$package['bonus_points'] > 0): ?>
      <div class="mt-2">
        <!-- Bonus badge: gold -->
        <span class="text-white px-2 py-1 rounded badge-gold font-bold">
          +<?= e((string)$package['bonus_points']) ?> BONUS
        </span>
      </div>
      <!-- Total: gold (to match BONUS) -->
      <div class="mt-1 font-semibold text-gold-weak">
        = <?= e((string)$package['total_points']) ?> Total Points
      </div>
    <?php endif; ?>
  </div>

  <!-- Checklist: emerald (bonus row in gold) -->
  <ul class="text-sm text-neutral-300 space-y-2">
    <li class="flex items-start gap-2">
      <span class="inline-flex w-4 shrink-0 justify-center mt-0.5 text-emerald-400 font-bold">✓</span>
      <span><?= e((string)$package['total_points']) ?> Donation Points</span>
    </li>

    <?php if (!empty($package['bonus_points']) && (int)$package['bonus_points'] > 0): ?>
    <li class="flex items-start gap-2">
      <span class="inline-flex w-4 shrink-0 justify-center mt-0.5 icon-gold font-bold">★</span>
      <span class="text-gold-weak">+<?= e((string)$package['bonus_points']) ?> bonus points</span>
    </li>
    <?php endif; ?>

    <li class="flex items-start gap-2">
      <span class="inline-flex w-4 shrink-0 justify-center mt-0.5 text-emerald-400 font-bold">✓</span>
      <span>Progress toward VIP status</span>
    </li>
    <li class="flex items-start gap-2">
      <span class="inline-flex w-4 shrink-0 justify-center mt-0.5 text-emerald-400 font-bold">✓</span>
      <span>Exclusive in-game items & mounts</span>
    </li>
    <li class="flex items-start gap-2">
      <span class="inline-flex w-4 shrink-0 justify-center mt-0.5 text-emerald-400 font-bold">✓</span>
      <span>Support server development</span>
    </li>
  </ul>

  <!-- REAL PAYPAL DONATION BUTTON -->
<div class="text-center mt-4">
  <button 
    class="btn-warm w-full paypal-standard-btn"
    data-package="<?= e($package_sku) ?>"
    data-amount="<?= e((string)$package['amount']) ?>"
    data-name="<?= e($package['name']) ?>"
  >
    <span class="btn-text">Donate via PayPal</span>
    <span class="btn-loading hidden">Redirecting…</span>
  </button>
</div>
</div>
<?php endforeach; ?>
            </div>
          <?php endif; ?>
        </div>
      </div>

      <!-- Information Card -->
      <div class="rough-card p-6">
        <h3 class="font-semibold mb-4">
          <span class="inline-block bg-gradient-to-r from-blue-300 to-emerald-400 bg-clip-text text-transparent">
            VIP System & Donation Guide
          </span>
        </h3>
        <div class="grid gap-6 md:grid-cols-2">
          <div>
            <h4 class="font-medium mb-2 flex items-center gap-2">
              <svg class="w-4 h-4 text-emerald-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path>
              </svg>
              VIP Progression
            </h4>
            <p class="text-sm opacity-70">Earn VIP status based on total donations. VIP 1 at $10, VIP 2 at $20, up to VIP 8 at $200.</p>
          </div>
          <div>
            <h4 class="font-medium mb-2 flex items-center gap-2">
              <svg class="w-4 h-4 text-emerald-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1"></path>
              </svg>
              Donation Points
            </h4>
            <p class="text-sm opacity-70">Use DP for exclusive items, mounts, pets, and services from the in-game Donation Shop.</p>
          </div>
          <div>
            <h4 class="font-medium mb-2 flex items-center gap-2">
              <svg class="w-4 h-4 text-emerald-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"></path>
              </svg>
              Secure Payments
            </h4>
            <p class="text-sm opacity-70">All payments are processed securely through PayPal. Your financial information is never stored on our servers.</p>
          </div>
          <div>
            <h4 class="font-medium mb-2 flex items-center gap-2">
              <svg class="w-4 h-4 text-emerald-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4.318 6.318a4.5 4.5 0 000 6.364L12 20.364l7.682-7.682a4.5 4.5 0 00-6.364-6.364L12 7.636l-1.318-1.318a4.5 4.5 0 00-6.364 0z"></path>
              </svg>
              Community Support
            </h4>
            <p class="text-sm opacity-70">Donations help cover server costs and provide exclusive rewards for our community.</p>
          </div>
        </div>
      </div>
    </div>

    <!-- Sidebar -->
    <aside class="space-y-6">
      <!-- VIP Progression Graph -->
      <div class="rough-card p-0 overflow-hidden">
        <div class="px-6 pt-5 pb-3">
          <h3 class="font-semibold flex items-center gap-2">
            <svg class="w-4 h-4 text-emerald-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7h8m0 0v8m0-8l-8 8-4-4-6 6"></path>
            </svg>
            VIP Progression
          </h3>
          <p class="text-sm text-neutral-400 mt-1">Your journey to maximum VIP status</p>
        </div>

        <div class="vip-progression px-6 pb-6">
          <!-- Progress Bar -->
          <div class="vip-track">
            <div class="vip-progress-fill" style="width: <?= number_format($progress_percentage, 1) ?>%"></div>
          </div>

          <!-- Milestones -->
<div class="vip-milestones">
  <?php foreach ($vip_tiers as $tier => $amount): ?>
    <?php
      $status = ($amount <= $total_spent) ? 'achieved' : (($tier == $next_tier) ? 'current' : '');
    ?>
    <div class="vip-milestone <?= $status ?>">
      <div class="vip-milestone-dot" aria-hidden="true"></div>
      <div class="vip-milestone-label">VIP <?= $tier ?></div>
    </div>
  <?php endforeach; ?>
</div>


          <!-- Next Goal -->
          <?php if ($vip_level < 8): ?>
          <div class="vip-next-goal">
            <div class="vip-next-goal-title">Next Goal</div>
            <div class="vip-next-goal-amount">$<?= number_format($remaining_for_next, 2) ?></div>
            <div class="vip-next-goal-description">to reach VIP <?= $next_tier ?></div>
          </div>
          <?php else: ?>
          <div class="vip-next-goal" style="background: rgba(52, 211, 153, 0.1); border-color: rgba(52, 211, 153, 0.2);">
            <div class="vip-next-goal-title">Achievement Unlocked</div>
            <div class="vip-next-goal-amount" style="color: #34d399;">MAX VIP</div>
            <div class="vip-next-goal-description" style="color: #34d399;">You've reached the highest VIP level!</div>
          </div>
          <?php endif; ?>

          <!-- VIP Tier List -->
          <div class="vip-tier-list">
            <?php foreach ($vip_tiers as $tier => $amount): ?>
            <?php 
            $status = '';
            if ($amount <= $total_spent) {
              $status = 'achieved';
            } elseif ($tier == $next_tier) {
              $status = 'current';
            }
            ?>
            <div class="vip-tier-item <?= $status ?>">
              <span class="vip-tier-name">VIP <?= $tier ?></span>
              <span class="vip-tier-amount">$<?= $amount ?></span>
            </div>
            <?php endforeach; ?>
          </div>
        </div>
      </div>

      <!-- Donation History -->
      <div class="rough-card p-6">
        <h3 class="font-semibold">Your Recent Donations</h3>

        <?php if (empty($donation_history)): ?>
          <div class="text-center py-8">
            <svg class="w-12 h-12 text-neutral-400 mx-auto mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v13m0-13V6a2 2 0 112 2h-2zm0 0V5.5A2.5 2.5 0 109.5 8H12zm-7 4h14M5 12a2 2 0 110-4h14a2 2 0 110 4M5 12v7a2 2 0 002 2h10a2 2 0 002-2v-7"></path>
            </svg>
            <p class="text-sm text-neutral-400">No donations yet</p>
            <p class="text-xs text-neutral-500 mt-1">Thank you for considering supporting us!</p>
          </div>
        <?php else: ?>
          <div class="mt-4 space-y-3">
            <?php foreach ($donation_history as $donation): ?>
            <div class="rounded-xl border border-white/10 bg-white/[0.03] p-3 hover:bg-white/[0.05] transition-colors">
              <div class="flex justify-between items-center mb-1">
                <span class="font-bold text-emerald-400">$<?= number_format((float)$donation['amount'], 2) ?></span>
                <span class="text-emerald-400 font-bold">+<?= e((string)$donation['points_earned']) ?> DP</span>
              </div>
              <div class="text-sm opacity-70"><?= date('M j, Y', strtotime($donation['created_at'])) ?></div>
            </div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>
</section>

<script>
document.addEventListener('DOMContentLoaded', function() {
  const csrfToken = '<?= $csrf_token ?>';

  document.querySelectorAll('.paypal-standard-btn').forEach(btn => {
    btn.addEventListener('click', async (e) => {
      e.preventDefault();

      const pkg = btn.dataset.package;
      const t = btn.querySelector('.btn-text');
      const l = btn.querySelector('.btn-loading');
      if (t && l) { t.classList.add('hidden'); l.classList.remove('hidden'); }
      btn.disabled = true;

      try {
        const res = await fetch('/api/paypal/standard_start.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ _csrf: csrfToken, package: pkg })
        });
        const j = await res.json();
        if (!res.ok || j.error) throw new Error(j.error || 'Could not start PayPal');

        // Send the payer to PayPal
        window.location.href = j.redirect_url;
      } catch (err) {
        console.error(err);
        alert('Payment start failed: ' + err.message);
        if (t && l) { t.classList.remove('hidden'); l.classList.add('hidden'); }
        btn.disabled = false;
      }
    });
  });
});
</script>