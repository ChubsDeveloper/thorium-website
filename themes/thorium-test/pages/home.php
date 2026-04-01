<?php
/**
 * themes/thorium-test/pages/home.php
 * Theme page override - customizes page template for this theme
 */

// themes/<theme>/pages/home.php

// Pull a bunch; we reveal older ones with a button
$posts = news_latest($pdo, 20);
$INITIAL_SHOW = 5;

// Optional modules loader
$APP_ROOT = dirname(__DIR__, 3);
if (is_file($APP_ROOT . '/app/modules_repo.php')) { require_once $APP_ROOT . '/app/modules_repo.php'; }
if (!function_exists('module_enabled')) { function module_enabled(string $name, ?string $theme = null): bool { return true; } }

$THEME = theme_current();

    /** Handle textify operation. */
function textify($str){ return trim((string)$str); }
?>

<!-- Fireflies (hidden by CSS in clean theme) -->
<div class="particles fixed inset-0 pointer-events-none z-0">
  <?php for ($i=0,$N=35; $i<$N; $i++):
    $left  = mt_rand(0, 100);
    $delay = number_format(mt_rand(0, 200)/10, 1);
    $dur   = number_format(mt_rand(70, 120)/10, 1);
    $size  = mt_rand(2, 4);
    $sway  = number_format(mt_rand(-70, 70)/100, 2);
  ?>
    <div class="particle"
         style="left: <?= $left ?>%; width: <?= $size ?>px; height: <?= $size ?>px;
                animation-delay: <?= $delay ?>s; animation-duration: <?= $dur ?>s; --sx: <?= $sway ?>;"></div>
  <?php endfor; ?>
</div>

<!-- FIXED HERO BACKGROUND -->
<section id="home" class="hero-emerald"
  data-hero-zoom=".6"
  data-hero-blur-factor="0.002"
  data-hero-follow="0.10"
  data-hero-max-blur="18"
  data-hero-fade-factor="0.25"
  data-hero-min-opacity="0.22"
  data-hero-src="<?= e(theme_asset_url('assets/BackgroundWebsite.png')) ?>">
  
  <img
    class="hero-img"
    src="<?= e(theme_asset_url('assets/BackgroundWebsite.png')) ?>"
    alt="Hero Background"
    decoding="async"
    fetchpriority="high"
    loading="eager"
  />

  <!-- Hero content positioned absolutely -->
  <div class="container hero-inner max-w-6xl mx-auto px-6 text-center">
    <p class="kicker mb-3 animate-on-scroll">Sanctuary of the Emerald Forest</p>
    <h1 class="h-display hero-title text-5xl md:text-7xl font-bold leading-tight animate-on-scroll">
      Welcome to <span class="shimmer-text">Thorium</span>
      <span class="block title-ornate mt-3"></span>
    </h1>
    <p class="hero-sub text-lg md:text-xl mt-6 max-w-2xl mx-auto animate-on-scroll">
      Your new home and sanctuary.
    </p>
    <div class="mt-8 flex flex-col sm:flex-row gap-4 justify-center animate-on-scroll">
      <a href="<?= e(base_url('register')) ?>" class="btn btn-warm btn-lg">Begin Journey</a>
      <?php if (module_enabled('news', $THEME)): ?>
        <a href="<?= e(base_url('')) ?>#news" class="btn btn-outline btn-lg">Latest News</a>
      <?php endif; ?>
    </div>
  </div>
</section>

<!-- ALL CONTENT BELOW SCROLLS OVER THE FIXED HERO -->
<?php if (module_enabled('bloodmarks', theme_current())): ?>
  <div class="cards-deferred">
    <?php require themed_partial_path('modules/bloodmarks'); ?>
  </div>
<?php endif; ?>

<?php if (module_enabled('realms', theme_current())): ?>
  <section id="status" class="scroll-target">
    <div class="cards-deferred">
      <?php require themed_partial_path('modules/realms'); ?>
    </div>
  </section>
<?php endif; ?>

<?php if (module_enabled('discord', theme_current())): ?>
  <div class="cards-deferred">
    <?php require themed_partial_path('modules/discord'); ?>
  </div>
<?php endif; ?>

<!-- Call to Adventure -->
<section class="py-20 relative">
  <div class="container max-w-4xl mx-auto px-6">
    <div class="rough-card p-10 text-center shine cards-deferred animate-on-scroll">
      <div class="section">
        <h2 class="h-display text-3xl font-bold mb-6">Answer the Call of the Emerald Forest</h2>
        <p class="text-xl muted mb-8 max-w-2xl mx-auto">Forge your legend. The Emerald Forest awaits.</p>
        <div class="flex flex-col sm:flex-row gap-4 justify-center">
          <a href="<?= e(base_url('register')) ?>" class="btn btn-warm btn-lg">Create Account</a>
          <a href="<?= e(base_url('shop')) ?>" class="btn btn-ghost btn-lg">Visit Shop</a>
        </div>
      </div>
    </div>
  </div>
</section>

<!-- News (STACKED & EXPAND INLINE) -->
<?php if (module_enabled('news', $THEME)): ?>
<section id="news" class="py-16 relative scroll-target">
  <div class="container max-w-3xl mx-auto px-6">
    <div class="text-center mb-10 animate-on-scroll">
      <p class="kicker">Stay Updated</p>
      <h2 class="h-display text-4xl font-bold">Chronicles of the Grove</h2>
    </div>

    <?php if (!empty($posts)): ?>
      <div class="space-y-6 cards-deferred" data-news-list>
        <?php foreach ($posts as $i => $n): ?>
          <?php
            $title    = textify($n['title'] ?? '');
            $subtitle = textify($n['subtitle'] ?? '');
            $author   = textify($n['author']  ?? '');
            $bodyRaw  = (string)($n['content'] ?? '');
            if ($bodyRaw === '') { $bodyRaw = $subtitle; } // fallback so long “subtitle” still expands
            $date     = format_date($n['published_at'] ?? null);
            $isOlder  = ($i >= $INITIAL_SHOW);
            // Home page: render as text for safety
            $bodySafe = nl2br(e(strip_tags($bodyRaw)));
          ?>
          <article class="rough-card p-6 animate-on-scroll <?= $isOlder ? 'hidden older-news' : '' ?>" data-news-item>
            <header class="mb-3">
              <div class="flex items-center gap-3 flex-wrap">
                <span class="inline-flex items-center rounded-full px-3 py-1 text-xs border border-amber-900/40 bg-amber-900/10 text-amber-200">📰 News</span>
                <span class="text-xs text-neutral-400"><?= e($date) ?></span>

                <?php if ($author !== ''): ?>
                  <span class="author-chip is-sheen-periodic ml-auto" title="Author">
                    <svg width="12" height="12" viewBox="0 0 20 20" aria-hidden="true" class="opacity-80">
                      <path fill="currentColor" d="M10 10a4 4 0 100-8 4 4 0 000 8zm-7 8a7 7 0 1114 0H3z"/>
                    </svg>
                    <?= e($author) ?>
                  </span>
                <?php endif; ?>
              </div>
              <h3 class="h-display text-2xl font-bold mt-3"><?= e($title) ?></h3>
              <?php if ($subtitle): ?>
                <p class="mt-1 text-neutral-300"><?= e($subtitle) ?></p>
              <?php endif; ?>
            </header>

            <div class="prose prose-invert max-w-none">
              <div class="news-body" data-collapsed="true" data-measure><?= $bodySafe ?></div>
            </div>

            <div class="mt-4 flex items-center justify-end">
              <button class="btn-ghost btn-sm" data-expand type="button" aria-expanded="false">Read more</button>
            </div>
          </article>
        <?php endforeach; ?>
      </div>

      <?php if (count($posts) > $INITIAL_SHOW): ?>
        <div class="text-center mt-8">
          <button class="btn btn-warm btn-lg" id="show-older" type="button">Show older posts</button>
        </div>
      <?php endif; ?>
    <?php else: ?>
      <div class="rough-card p-6 text-center text-neutral-300">No news yet—check back soon.</div>
    <?php endif; ?>
  </div>

  <script>
    document.addEventListener('DOMContentLoaded', () => {
      document.querySelectorAll('[data-news-item]').forEach((card) => {
        const body = card.querySelector('[data-measure]');
        const btn  = card.querySelector('[data-expand]');
        if (!body || !btn) return;

        requestAnimationFrame(() => {
          const overflows = body.scrollHeight - body.clientHeight > 8;
          if (!overflows) btn.classList.add('hidden');
        });

        let expanded = false;
        btn.addEventListener('click', () => {
          expanded = !expanded;
          if (expanded) {
            body.removeAttribute('data-collapsed');
            btn.textContent = 'Show less';
            btn.setAttribute('aria-expanded', 'true');
          } else {
            body.setAttribute('data-collapsed', 'true');
            btn.textContent = 'Read more';
            btn.setAttribute('aria-expanded', 'false');
          }
        });
      });

      const olderBtn = document.getElementById('show-older');
      if (olderBtn) {
        olderBtn.addEventListener('click', () => {
          document.querySelectorAll('.older-news').forEach(n => n.classList.remove('hidden'));
          olderBtn.remove();
        });
      }
    });
  </script>
</section>
<?php endif; ?>

<?php if (defined('DEBUG') && DEBUG): ?>
<script>console.log('Theme:', '<?= e($THEME) ?>');</script>
<?php endif; ?>
