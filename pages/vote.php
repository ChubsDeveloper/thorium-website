<?php
/**
 * pages/vote.php
 * Clean voting page — fixed: cooldown uses REAL site id.
 * Weekend bonus: Fri/Sat/Sun → 2× display + historical sums.
 * UPDATED: Nickname support using helper functions
 */
declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) session_start();

if (!isset($_SESSION['user'])) {
    header('Location: ' . base_url('/login?redirect=' . urlencode('/vote')));
    exit;
}

// Project root
if (!function_exists('__thorium_root')) {
    function __thorium_root(): string {
        $dir = __DIR__;
        for ($i = 0; $i < 12; $i++) {
            if (is_dir($dir . DIRECTORY_SEPARATOR . 'app') && is_dir($dir . DIRECTORY_SEPARATOR . 'public')) return $dir;
            $parent = dirname($dir);
            if ($parent === $dir) break;
            $dir = $parent;
        }
        return __DIR__;
    }
}
$ROOT = __thorium_root();
require_once $ROOT . '/app/Repositories/vote_repository.php';

// ADDED: Include nickname helpers
if (file_exists($ROOT . '/app/nickname_helpers.php')) {
    require_once $ROOT . '/app/nickname_helpers.php';
}

if (!function_exists('e')) {
    function e(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }
}

// DB
$db = $GLOBALS['pdo'] ?? (function_exists('get_database_connection') ? get_database_connection() : null);
if (!$db) { http_response_code(500); die('Database connection not available'); }

// Repo + user
$vote_repo = new VoteRepository($db);
$user_id   = (int)$_SESSION['user']['id'];

// Page data (personalized links)
$vote_sites    = $vote_repo->getVoteSites($user_id);  // may be keyed by id or numeric indices
$user_points   = $vote_repo->getUserVotePoints($user_id);
$vote_history  = $vote_repo->getUserVoteHistory($user_id, 5);
$top_voters    = $vote_repo->getTopVoters(10);

/** ------------------------------------------------------------------
 * Lifetime community stats (historical Fri/Sat/Sun double)
 * MySQL: DAYOFWEEK(): 1=Sun, 2=Mon, ..., 7=Sat → Fri=6, Sat=7, Sun=1
 * ------------------------------------------------------------------ */
function get_lifetime_stats(PDO $db): array {
    $sql = "
        SELECT 
            COUNT(DISTINCT v.account_id) AS unique_voters,
            COUNT(*)                     AS total_votes,
            COUNT(CASE WHEN v.status IN ('callback_confirmed','manual_confirmed','auto_confirmed') THEN 1 END) AS confirmed_votes,
            COALESCE(SUM(
                CASE
                    WHEN v.status IN ('callback_confirmed','manual_confirmed','auto_confirmed')
                    THEN (vs.points * vs.multiplier) *
                         (CASE WHEN DAYOFWEEK(v.voted_at) IN (1,6,7) THEN 2 ELSE 1 END)
                    ELSE 0
                END
            ), 0) AS total_points_awarded
        FROM votes v
        JOIN vote_sites vs ON v.site_id = vs.id
    ";
    $row = $db->query($sql)->fetch(PDO::FETCH_ASSOC) ?: [];
    return [
        'unique_voters'        => (int)($row['unique_voters'] ?? 0),
        'total_votes'          => (int)($row['total_votes'] ?? 0),
        'confirmed_votes'      => (int)($row['confirmed_votes'] ?? 0),
        'total_points_awarded' => (int)($row['total_points_awarded'] ?? 0),
    ];
}
$lifetime = get_lifetime_stats($db);

/** Your lifetime awarded VP (confirmed-only) with historical Fri/Sat/Sun double */
function get_user_lifetime_awarded(PDO $db, int $user_id): int {
    $sql = "
        SELECT COALESCE(SUM(
            CASE
              WHEN v.status IN ('callback_confirmed','manual_confirmed','auto_confirmed')
              THEN (vs.points * vs.multiplier) *
                   (CASE WHEN DAYOFWEEK(v.voted_at) IN (1,6,7) THEN 2 ELSE 1 END)
              ELSE 0
            END
        ), 0) AS lifetime_awarded
        FROM votes v
        JOIN vote_sites vs ON v.site_id = vs.id
        WHERE v.account_id = :uid
    ";
    $stmt = $db->prepare($sql);
    $stmt->execute([':uid' => $user_id]);
    return (int)$stmt->fetchColumn();
}
$user_lifetime_awarded = get_user_lifetime_awarded($db, $user_id);

// UPDATED: Enhanced Top Voters with nickname support using helper functions
if (empty($top_voters)) {
    $sql = "
        SELECT 
            a.id,
            a.username,
            COALESCE(SUM(
                CASE WHEN v.status IN ('callback_confirmed','manual_confirmed','auto_confirmed')
                     THEN (vs.points * vs.multiplier) *
                          (CASE WHEN DAYOFWEEK(v.voted_at) IN (1,6,7) THEN 2 ELSE 1 END)
                     ELSE 0 END
            ),0) AS vote_points,
            COUNT(CASE WHEN v.status IN ('callback_confirmed','manual_confirmed','auto_confirmed') THEN 1 END) AS confirmed_votes
        FROM accounts a
        LEFT JOIN votes v       ON a.id = v.account_id
        LEFT JOIN vote_sites vs ON v.site_id = vs.id
        GROUP BY a.id, a.username
        HAVING vote_points > 0 OR confirmed_votes > 0
        ORDER BY vote_points DESC, confirmed_votes DESC
        LIMIT 10
    ";
    $top_voters_raw = $db->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [];
    
    // ADDED: Enhance with nicknames using helper functions
    $top_voters = [];
    foreach ($top_voters_raw as $voter) {
        $voter_id = (int)$voter['id'];
        
        // Use nickname helper function to get proper display name
        if (function_exists('get_user_nickname_info')) {
            $nickname_info = get_user_nickname_info($db, $voter_id);
            $display_name = $nickname_info['display_name'];
        } elseif (function_exists('get_user_nickname')) {
            $display_name = get_user_nickname($db, $voter_id);
        } else {
            // Fallback to username if helper functions not available
            $display_name = $voter['username'];
        }
        
        $top_voters[] = [
            'id' => $voter_id,
            'username' => $voter['username'],
            'display_name' => $display_name,
            'vote_points' => (int)$voter['vote_points'],
            'confirmed_votes' => (int)$voter['confirmed_votes']
        ];
    }
}

/* ------------------------------------------------------------------
   COOLDOWNS — always use the real site id from each row
   ------------------------------------------------------------------ */
$sites_list = [];
$sites_by_id = [];
foreach ($vote_sites as $k => $row) {
    $sid = (int)($row['id'] ?? $row['site_id'] ?? $k);
    if ($sid <= 0) continue;
    $row['id'] = $sid;
    $sites_list[] = $row;
    $sites_by_id[$sid] = $row;
}

$vote_cooldowns = [];
foreach ($sites_list as $row) {
    $sid = (int)$row['id'];
    $vote_cooldowns[$sid] = $vote_repo->getTimeUntilNextVote($user_id, $sid);
}

// Helpers
function formatTimeRemaining(int $seconds): string {
    if ($seconds <= 0) return 'Available now';
    $h = (int) floor($seconds / 3600);
    $m = (int) floor(($seconds % 3600) / 60);
    return $h > 0 ? "{$h}h {$m}m" : "{$m}m";
}
$isWeekend = (int)date('N') >= 5; // Fri(5), Sat(6), Sun(7)
?>
<section class="container px-4 pt-16 md:pt-36 pb-24 md:pb-40">
  <!-- Header Card -->
  <div class="relative rough-card overflow-hidden p-0 shine">
    <div class="absolute inset-y-0 left-0 w-1.5 bg-gradient-to-b from-emerald-400/80 to-emerald-500/60"></div>
    <div class="p-6 md:p-8">
      <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-4">
        <div>
          <p class="kicker">Support Our Server</p>
          <h1 class="h-display text-3xl md:text-4xl font-extrabold">Vote for <?= e($GLOBALS['config']['server_name'] ?? 'Thorium') ?></h1>
          <p class="mt-2 muted">Finish a vote on a partner site and you'll get your points—no extra steps.</p>
          <?php if ($isWeekend): ?>
            <p class="mt-1 text-emerald-300 text-sm font-semibold">🎉 Weekend bonus active (Fri–Sun): Points are doubled!</p>
          <?php else: ?>
            <p class="mt-1 text-neutral-400 text-sm">Tip: Points double on Friday–Sunday.</p>
          <?php endif; ?>
        </div>
        <div class="flex items-center gap-2">
          <a href="<?= e(base_url('panel')) ?>" class="btn-ghost">Character Panel</a>
          <a href="<?= e(base_url('donate')) ?>" class="btn-warm">Donate</a>
        </div>
      </div>

      <!-- Flash messages -->
      <?php if (isset($_SESSION['vote_success'])): ?>
        <div class="mt-6 rough-card p-4 text-sm text-emerald-300 border-emerald-500/30"><?= e($_SESSION['vote_success']) ?></div>
        <?php unset($_SESSION['vote_success']); ?>
      <?php endif; ?>
      <?php if (isset($_SESSION['vote_error'])): ?>
        <div class="mt-6 rough-card p-4 text-sm text-red-300 border-red-500/30"><?= e($_SESSION['vote_error']) ?></div>
        <?php unset($_SESSION['vote_error']); ?>
      <?php endif; ?>

      <!-- Personal stats -->
      <div class="mt-6 grid gap-4 sm:grid-cols-2">
        <div class="rounded-xl border border-white/10 p-4 bg-white/[0.03]">
          <div class="text-[11px] uppercase tracking-wide text-neutral-300/80">Vote Points</div>
          <div class="mt-1 text-2xl font-extrabold tabular-nums text-emerald-400" id="user-points"><?= e((string)$user_points) ?></div>
        </div>
        <div class="rounded-xl border border-white/10 p-4 bg-white/[0.03]">
          <div class="text-[11px] uppercase tracking-wide text-neutral-300/80">Points Awarded</div>
          <div class="mt-1 text-2xl font-extrabold tabular-nums text-amber-200"><?= number_format((int)$user_lifetime_awarded) ?></div>
        </div>
      </div>
    </div>
  </div>

  <!-- Main Content Grid -->
  <div class="mt-6 grid gap-6 md:grid-cols-3">
    <!-- Main Content - 2 columns -->
    <div class="md:col-span-2 space-y-6">
      <!-- Vote Sites -->
      <div class="rough-card p-0 overflow-hidden">
        <div class="px-6 pt-5">
          <h2 class="font-semibold">
            <span class="inline-block bg-gradient-to-r from-emerald-300 via-lime-300 to-emerald-400 bg-clip-text text-transparent">Vote Sites</span>
            <span class="text-neutral-200/90"> — Start Your Votes</span>
          </h2>
          <p class="text-sm text-neutral-400 mt-1">Click "Vote Now". Complete your vote on the partner site to earn points.</p>
        </div>

        <div class="px-6 pb-6">
          <?php if (empty($sites_list)): ?>
            <p class="mt-4 text-sm text-neutral-400">No vote sites configured yet.</p>
          <?php else: ?>
            <div class="mt-4 grid gap-6 sm:grid-cols-2">
              <?php foreach ($sites_list as $site): ?>
              <?php
                $sid = (int)$site['id'];
                $cd  = $vote_cooldowns[$sid] ?? ['can_vote' => true, 'time_remaining' => 0];
                $can = (bool)($cd['can_vote'] ?? true);
                $rem = (int)($cd['time_remaining'] ?? 0);

                $basePoints = (int)$site['points'];
                $mult       = (float)$site['multiplier'];

                // Fri–Sun: double regardless of DB multiplier
                $effMult    = $mult * ($isWeekend ? 2.0 : 1.0);
                $dispPoints = (int) floor($basePoints * $effMult);
              ?>
              <div class="rounded-xl border border-white/10 bg-white/[0.03] p-6 ring-1 ring-white/10 tilt group transition-all duration-200 hover:transform hover:scale-[1.02]">
                <div class="text-center mb-4">
                  <h3 class="text-xl font-bold mb-2 text-white"><?= e($site['name']) ?></h3>

                  <div class="font-medium text-emerald-300">+<?= e((string)$dispPoints) ?> Vote Points</div>

                  <?php if ($isWeekend): ?>
                    <div class="mt-2">
                      <span class="text-white px-2 py-1 rounded bg-emerald-500/20 border border-emerald-500/30 font-bold text-xs">
                        Weekend bonus: 2×
                      </span>
                    </div>
                  <?php endif; ?>
                </div>

                <ul class="text-sm text-neutral-300 space-y-2 mb-4">
                  <li>Vote every <?= e((string)$site['hour_interval']) ?> hours</li>
                  <li>Points added after you complete your vote</li>
                </ul>

                <div class="text-center">
                  <?php if ($can): ?>
                    <button
                      class="btn-warm w-full start-vote-btn"
                      data-site-id="<?= e((string)$sid) ?>"
                      data-site-url="<?= e($site['url']) ?>"
                      data-site-name="<?= e($site['name']) ?>"
                    >
                      <span class="btn-text">Vote Now</span>
                      <span class="btn-loading hidden">Starting…</span>
                    </button>
                  <?php else: ?>
                    <button class="btn-ghost w-full opacity-60 cursor-not-allowed" disabled><?= formatTimeRemaining($rem) ?></button>
                  <?php endif; ?>
                </div>
              </div>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        </div>
      </div>

      <!-- Instructions -->
      <div class="rough-card p-6">
        <h3 class="font-semibold mb-4">
          <span class="inline-block bg-gradient-to-r from-emerald-300 to-emerald-400 bg-clip-text text-transparent">How It Works</span>
        </h3>
        <div class="space-y-4">
          <div class="flex gap-3">
            <span class="flex-shrink-0 w-6 h-6 rounded-full bg-emerald-500/20 flex items-center justify-center text-emerald-400 text-sm font-bold">1</span>
            <div><div class="font-medium">Start Your Vote</div><div class="text-sm opacity-70">Click "Vote Now" — we open the partner site and record your attempt.</div></div>
          </div>
          <div class="flex gap-3">
            <span class="flex-shrink-0 w-6 h-6 rounded-full bg-emerald-500/20 flex items-center justify-center text-emerald-400 text-sm font-bold">2</span>
            <div><div class="font-medium">Complete the Vote</div><div class="text-sm opacity-70">Follow their steps until they confirm it's done.</div></div>
          </div>
          <div class="flex gap-3">
            <span class="flex-shrink-0 w-6 h-6 rounded-full bg-emerald-500/20 flex items-center justify-center text-emerald-400 text-sm font-bold">3</span>
            <div><div class="font-medium">Get Points</div><div class="text-sm opacity-70">We'll add your points shortly after the site verifies your vote.</div></div>
          </div>
        </div>
      </div>
    </div>

    <!-- Sidebar -->
    <aside class="space-y-6">
      <!-- Recent Votes (confirmed only) -->
      <div class="rough-card p-6">
        <h3 class="font-semibold">Your Recent Votes</h3>
        <?php
          $confirmed_history = array_values(array_filter($vote_history, function($v) {
            return in_array($v['status'], ['callback_confirmed','manual_confirmed','auto_confirmed'], true);
          }));
        ?>
        <?php if (empty($confirmed_history)): ?>
          <div class="text-center py-8">
            <svg class="w-12 h-12 text-neutral-400 mx-auto mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path>
            </svg>
            <p class="text-sm text-neutral-400">No confirmed votes yet</p>
            <p class="text-xs text-neutral-500 mt-1">Start voting to see your history!</p>
          </div>
        <?php else: ?>
          <div class="mt-4 space-y-3">
            <?php foreach ($confirmed_history as $vote): ?>
            <?php
              // If repo provided points_earned, it's already weekend-aware.
              if (isset($vote['points_earned'])) {
                  $vh_points = (int)$vote['points_earned'];
              } else {
                  $base = (int)($vote['points'] ?? $vote['site_points'] ?? 0);
                  $mult = (float)($vote['multiplier'] ?? 1);
                  // Double historically if Fri(6)/Sat(7)/Sun(1)
                  $dow  = (int)date('w', strtotime($vote['voted_at'])); // 0=Sun..6=Sat
                  $wf   = in_array($dow, [0,5,6], true) ? 2.0 : 1.0;     // Sun=0, Fri=5, Sat=6
                  $vh_points = (int)floor($base * $mult * $wf);
              }
            ?>
            <div class="rounded-xl border border-white/10 bg-white/[0.03] p-3 hover:bg-white/[0.05] transition-colors">
              <div class="flex justify-between items-center mb-1">
                <span class="font-medium"><?= e($vote['site_name']) ?></span>
                <span class="text-blue-400 font-bold">+<?= e((string)$vh_points) ?> VP</span>
              </div>
              <div class="text-sm opacity-70">
                <?= date('M j, H:i', strtotime($vote['voted_at'])) ?>
              </div>
            </div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>

      <!-- Top Voters (UPDATED with nickname helper functions) -->
      <div class="rough-card p-6">
        <h3 class="font-semibold">Top Voters</h3>
        <?php if (empty($top_voters)): ?>
          <p class="text-sm text-neutral-400 mt-4">No votes yet. Be the first!</p>
        <?php else: ?>
          <div class="mt-4 space-y-2">
            <?php foreach ($top_voters as $index => $voter): ?>
            <?php
              $medal = [0=>'bg-amber-500 text-amber-900',1=>'bg-gray-400 text-gray-900',2=>'bg-amber-600 text-amber-100'][$index] ?? 'bg-emerald-500 text-emerald-900';
              $vp = (int)($voter['vote_points'] ?? 0);
              
              // UPDATED: Use display_name from enhanced query or fallback to username
              $display_name = $voter['display_name'] ?? $voter['username'] ?? 'Unknown';
            ?>
            <div class="flex items-center justify-between p-3 rounded-xl border border-white/10 bg-white/[0.03] hover:bg-white/[0.05] transition-colors">
              <div class="flex items-center gap-3">
                <span class="flex items-center justify-center w-6 h-6 text-xs font-bold <?= $medal ?>"><?= $index + 1 ?></span>
                <span class="font-medium"><?= e($display_name) ?></span>
                <?php if ($index < 3): ?>
                  <span class="text-xs opacity-50"><?= ['🥇','🥈','🥉'][$index] ?></span>
                <?php endif; ?>
              </div>
              <span class="text-emerald-400 font-bold"><?= number_format($vp) ?> VP</span>
            </div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>

      <!-- Community Stats (Lifetime) -->
      <div class="rough-card p-6">
        <h3 class="font-semibold">Community Stats</h3>
        <div class="mt-4 space-y-3">
          <div class="flex justify-between">
            <span class="text-neutral-400">Active Voters:</span>
            <span class="font-bold"><?= e((string)$lifetime['unique_voters']) ?></span>
          </div>
          <div class="flex justify-between">
            <span class="text-neutral-400">Total Votes:</span>
            <span class="text-emerald-400 font-bold"><?= number_format((int)$lifetime['total_votes']) ?></span>
          </div>
          <div class="flex justify-between">
            <span class="text-neutral-400">Points Awarded:</span>
            <span class="text-emerald-400 font-bold"><?= number_format((int)$lifetime['total_points_awarded']) ?></span>
          </div>
        </div>
      </div>
    </aside>
  </div>
</section>

<!-- JS — start vote only -->
<script>
document.addEventListener('DOMContentLoaded', function() {
  const csrfValue = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';

  document.querySelectorAll('.start-vote-btn').forEach(button => {
    button.addEventListener('click', function(e) {
      // keep a reference to the button
      const btn = this;
      const siteId   = btn.dataset.siteId;   // REAL DB site id
      const siteUrl  = btn.dataset.siteUrl;  // personalized by server (fallback)
      const siteName = btn.dataset.siteName;

      const btnText = btn.querySelector('.btn-text');
      const btnLoad = btn.querySelector('.btn-loading');

      // Immediately open a blank window in the click context so it's allowed on iOS.
      // This returns null if blocked; we handle that below.
      let voteWindow = null;
      try {
        voteWindow = window.open('about:blank', '_blank');
      } catch (err) {
        voteWindow = null;
      }

      // Update UI
      btn.disabled = true;
      if (btnText && btnLoad) { btnText.classList.add('hidden'); btnLoad.classList.remove('hidden'); }

      const formData = new FormData();
      formData.append('action', 'start');
      formData.append('site_id', siteId);
      if (csrfValue) formData.append('_csrf', csrfValue);

      // Kick off request
      fetch('/api/vote.php', { method: 'POST', body: formData, credentials: 'same-origin' })
        .then(r => {
          if (!r.ok) throw new Error('HTTP ' + r.status);
          return r.json(); // parse JSON directly
        })
        .then(data => {
          if (!data || !data.success) throw new Error(data?.message || 'Unknown server error');

          // Prefer server-provided vote_url; fall back to siteUrl attribute.
          const voteUrl = data.vote_url || siteUrl || '/';

          // If opening a blank window succeeded earlier, set its location.
          // Otherwise (null) navigate current tab as fallback.
          if (voteWindow) {
            // On some browsers setting location on about:blank is allowed.
            try {
              voteWindow.location.href = voteUrl;
            } catch (err) {
              // If for any reason we can't set location, fallback to same-tab redirect.
              window.location.href = voteUrl;
            }
          } else {
            // Popup blocked — navigate same tab (mobile-friendly)
            window.location.href = voteUrl;
          }

          // Let the vote flow happen on partner site; reload after short delay to update cooldown & points.
          setTimeout(() => window.location.reload(), 1100);
        })
        .catch(err => {
          console.error('Vote start failed:', err);
          // Restore UI
          btn.disabled = false;
          if (btnText && btnLoad) { btnText.classList.remove('hidden'); btnLoad.classList.add('hidden'); }

          // If we opened a blank window but ended up with an error, close it to avoid stray tabs.
          try { if (voteWindow && !voteWindow.closed) voteWindow.close(); } catch (e) {}

          alert('Could not start the vote: ' + (err.message || 'Unknown error'));
        });
    });
  });
});
</script>
