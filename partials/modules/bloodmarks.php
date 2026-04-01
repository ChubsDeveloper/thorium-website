<?php
/**
 * partials/modules/bloodmarks.php
 * Bloodmarking Leaderboard — shows top by BM level (then marks, victims).
 */
declare(strict_types=1);

require_once APP_ROOT . '/bloodmarks_repo.php';

$limit = 10;
$table = 'character_bloodmarks';
$rows  = bloodmarks_top($limit, $table);

/** Blizzard class colors (by class id) */
$CLASS_COLORS = [
  1=>'#C79C6E',2=>'#F58CBA',3=>'#ABD473',4=>'#FFF569',5=>'#FFFFFF',
  6=>'#C41F3B',7=>'#0070DE',8=>'#69CCF0',9=>'#9482C9',
  10=>'#00FF96',11=>'#FF7D0A',12=>'#A330C9',13=>'#33937F',
];

function rank_badge_classes(int $rank): string {
  return match ($rank) {
    1 => 'bg-gradient-to-b from-amber-300/95 to-amber-900/90 ring-2 ring-amber-400/50 text-bark-900 shadow-[0_0_18px_rgba(245,158,11,.28)]',
    2 => 'bg-gradient-to-b from-neutral-200/95 to-neutral-800/90 ring-2 ring-neutral-300/50 text-neutral-900 shadow-[0_0_16px_rgba(255,255,255,.18)]',
    3 => 'bg-gradient-to-b from-orange-300/95 to-amber-900/90 ring-2 ring-orange-400/50 text-stone-950 shadow-[0_0_16px_rgba(245,158,11,.20)]',
    default => 'bg-white/5 text-neutral-200 ring-1 ring-white/10',
  };
}
?>
<section class="pt-8 pb-6 relative animate-on-scroll">
  <div class="container max-w-6xl mx-auto px-6">
    <div class="text-center mb-8">
      <p class="kicker">Bloodmarking Progression</p>
      <h2 class="h-display text-3xl font-bold">Bloodmarking Leaderboard</h2>
    </div>

    <?php if (!$rows): ?>
      <div class="rough-card p-6 text-center">
        <p class="muted">No bloodmarking data yet.</p>
      </div>
    <?php else: ?>
      <div class="rough-card overflow-hidden">
        <div class="grid grid-cols-12 gap-x-4 md:gap-x-6 text-[11px] uppercase tracking-wide text-neutral-300/80 px-8 py-4 border-b border-white/10 bg-black/10">
          <div class="col-span-1">#</div>
          <div class="col-span-6">Character</div>
          <div class="col-span-2 text-right">Victims</div>
          <div class="col-span-1 text-right">BM Lv</div>
          <div class="col-span-2 text-right">Lvl</div>
        </div>

        <?php foreach ($rows as $i => $r): ?>
          <?php
            $rank  = $i + 1;
            $cls   = (int)($r['class'] ?? 0);
            $color = $CLASS_COLORS[$cls] ?? '#E5E7EB';
            $badge = rank_badge_classes($rank);
          ?>
          <div class="grid grid-cols-12 gap-x-4 md:gap-x-6 items-center px-8 py-4 border-t border-white/5 first:border-t-0 hover:bg-white/[0.03] transition last:pb-6 last:-translate-y-[1px]">
            <div class="col-span-1">
              <span class="inline-flex items-center justify-center w-7 h-7 rounded-full font-extrabold text-[12px] leading-none tabular-nums tracking-tight <?= $badge ?>">
                <?= $rank ?>
              </span>
            </div>
            <div class="col-span-6 flex items-center gap-3 min-w-0">
              <span class="font-medium inline-block truncate" style="color: <?= e($color) ?>">
                <?php $armory_enabled = module_enabled('armory'); ?>
                <?php if ($armory_enabled): ?>
                  <a href="<?= e(base_url('armory?name=' . urlencode($r['name']))) ?>" class="hover:text-white transition-colors duration-200" title="View <?= e($r['name']) ?> in armory"><?= e($r['name']); ?></a>
                <?php else: ?>
                  <?= e($r['name']); ?>
                <?php endif; ?>
              </span>
            </div>
            <div class="col-span-2 text-right tabular-nums"><?= (int)$r['victims']; ?></div>
            <div class="col-span-1 text-right tabular-nums"><?= (int)$r['bm_level']; ?></div>
            <div class="col-span-2 text-right tabular-nums"><?= (int)$r['char_level']; ?></div>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>
</section>
