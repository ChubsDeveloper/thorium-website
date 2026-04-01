<?php
/**
 * Announcements Module — unique looks per priority label
 * Priorities (ENUM): normal, important, urgent, critical, maintenance, event
 */
declare(strict_types=1);

if (!function_exists('e')) { function e($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); } }

// Ensure parse_wow_colors is available
if (!function_exists('parse_wow_colors')) {
    $helperFile = dirname(__DIR__, 2) . '/app/helpers.php';
    if (is_file($helperFile)) {
        require_once $helperFile;
    }
}

$items = [];

/* ───────────────────────────── Autolink + sanitize helpers ───────────────────────────── */

/** True if the string likely already contains HTML tags we care about. */
if (!function_exists('ann_looks_html')) {
  function ann_looks_html(string $s): bool {
    if (strpos($s, '<') === false || strpos($s, '>') === false) return false;
    return (bool)preg_match('~<\s*(p|br|ul|ol|li|div|section|article|h[1-6]|strong|em|b|i|a|pre|code|blockquote)\b~i', $s);
  }
}

/** Autolink already-escaped text (https://… or www.…). */
if (!function_exists('ann_autolink')) {
  function ann_autolink(string $escaped): string {
    return preg_replace_callback(
      '~(^|[\s\(\[\{>])((?:https?://|www\.)[^\s<>"\']+?)([)\]\}\.,!?;:]*)(?=\s|$)~i',
      function($m){
        $lead  = $m[1] ?? '';
        $vis   = $m[2];
        $trail = $m[3] ?? '';
        $hrefRaw = stripos($vis, 'www.') === 0 ? ('https://' . $vis) : $vis;
        $href = htmlspecialchars($hrefRaw, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        return $lead.'<a href="'.$href.'" target="_blank" rel="nofollow noopener noreferrer">'.$vis.'</a>'.$trail;
      },
      $escaped
    );
  }
}

/** Plaintext -> paragraphs/bullets + autolink (handles literal "\n"). */
if (!function_exists('ann_plain_to_html')) {
  function ann_plain_to_html(string $txt): string {
    if ($txt === '') return '';
    $txt = str_replace(["\r\n","\r"], "\n", $txt);
    $txt = str_replace(["\\r\\n","\\n","\\r"], "\n", $txt);
    $txt = trim($txt);

    if (strpos($txt, "\n") === false && preg_match('/\*\s+/', $txt)) {
      $txt = preg_replace('/\s*\*\s+/', "\n* ", $txt);
    }

    $lines = explode("\n", $txt);
    $out=[]; $inList=false; $buf=[];

    $flush = function() use (&$out,&$buf){
      if (!$buf) return;
      $p = e(implode("\n", $buf));
      $p = ann_autolink($p);
      $p = nl2br($p, false);
      $out[] = "<p>{$p}</p>";
      $buf=[];
    };
    $endList = function() use (&$out,&$inList){ if ($inList){ $out[]='</ul>'; $inList=false; } };

    foreach ($lines as $line){
      if (preg_match('/^\s*[\*\-]\s+(.+)$/', $line, $m)) {
        $flush();
        if (!$inList){ $out[]='<ul>'; $inList=true; }
        $item = e($m[1]);
        $item = ann_autolink($item);
        $item = preg_replace('/\*\*(.+?)\*\*/','<strong>$1</strong>', $item);
        $item = preg_replace('/\*(.+?)\*/','<em>$1</em>', $item);
        $out[] = "<li>{$item}</li>";
        continue;
      }
      if (trim($line) === '') { $endList(); $flush(); continue; }
      $endList(); $buf[] = $line;
    }
    $endList(); $flush();
    return implode("\n", $out);
  }
}

/** Allow-list sanitizer; ensures <a> has safe href + rel/target. */
if (!function_exists('ann_sanitize_html')) {
  function ann_sanitize_html(string $html): string {
    if ($html === '') return '';
    $allowed = '<p><br><ul><ol><li><strong><b><em><i><a><code><pre><blockquote><h1><h2><h3><h4><span>';
    $html = strip_tags($html, $allowed);

    // strip event attrs
    $html = preg_replace('/\s+on\w+\s*=\s*(?:"[^"]*"|\'[^\']*\'|[^\s>]+)/i', '', $html);
    // preserve safe color styles on spans, strip other styles
    $html = preg_replace_callback('/<span\s+[^>]*style\s*=\s*["\']([^"\']*)["\'][^>]*>/i', function($m){
      $style = $m[1];
      // Only allow color property with hex colors (safe)
      if (preg_match('~^color\s*:\s*#[0-9a-fA-F]{6}\s*(?:;)?$~i', $style)) {
        return $m[0];
      }
      return preg_replace('/\s+style\s*=\s*["\'][^"\']*["\']/i', '', $m[0]);
    }, $html);

    // sanitize <a>
    $html = preg_replace_callback('/<a\b([^>]*)>/i', function($m){
      $attrs = $m[1];
      $href = '';
      if (preg_match('/\bhref\s*=\s*("([^"]*)"|\'([^\']*)\'|([^\s>]+))/i', $attrs, $hm)) {
        $href = html_entity_decode($hm[2] ?? $hm[3] ?? $hm[4] ?? '', ENT_QUOTES | ENT_HTML5, 'UTF-8');
      }
      $ok = preg_match('~^(https?:|mailto:|#)~i', $href);
      $safeHref = $ok ? e($href) : '#';

      $title = '';
      if (preg_match('/\btitle\s*=\s*("([^"]*)"|\'([^\']*)\')/i', $attrs, $tm)) {
        $title = ' title="'.e(html_entity_decode($tm[2] ?? $tm[3] ?? '', ENT_QUOTES | ENT_HTML5, 'UTF-8')).'"';
      }
      $isExternal = preg_match('~^(https?:|mailto:)~i', $href);
      $target  = $isExternal ? ' target="_blank"' : '';
      $relAttr = ' rel="nofollow noopener noreferrer"';

      return '<a href="'.$safeHref.'"'.$relAttr.$target.$title.'>';
    }, $html);

    return trim($html);
  }
}

/** Build safe, clickable HTML for the announcement body. */
if (!function_exists('ann_body_html')) {
  function ann_body_html(array $row): string {
    $src = (string)($row['body'] ?? $row['content'] ?? $row['message'] ?? '');
    if ($src === '') return '';
    $html = ann_looks_html($src) ? $src : ann_plain_to_html($src);

    // Parse WoW color codes before sanitization
    if (!function_exists('parse_wow_colors')) {
      // Inline parser if helpers not loaded
      if (strpos($html, '|cff') !== false) {
        $html = str_replace(['&#124;', '&pipe;', '&#x7C;'], '|', $html);
        $html = preg_replace_callback('~\|cff([0-9a-fA-F]{6})(.*?)\|r~s', function($m) {
          return '<span style="color: #' . strtolower($m[1]) . ';">' . $m[2] . '</span>';
        }, $html);
      }
    } else {
      $html = parse_wow_colors($html);
    }

    return ann_sanitize_html($html);
  }
}

/* ───────────────────────────── Data fetch ───────────────────────────── */

/* Preferred path: repository */
if (function_exists('app')) {
  try {
    $app  = app();
    $repo = new \App\Repositories\announcements_repository($app);
    $items = $repo->get_active(3); // returns priority_label + priority (numeric weight)
  } catch (\Throwable $e) {
    // fallback below
  }
}

/* Fallback: direct PDO with inline evaluator */
if (!$items && isset($GLOBALS['pdo']) && $GLOBALS['pdo'] instanceof PDO) {
  try {
    $sql = "
      SELECT
        id,title,body,
        priority       AS priority_label,
        priority_weight,
        cta_text,cta_url,
        is_dismissible,version,
        starts_at,ends_at,
        is_recurring,recurrence_type,recurrence_pattern,
        recurrence_start_time,recurrence_end_time,timezone
      FROM announcements
      WHERE
        is_recurring = 1
        OR
        (
          is_recurring = 0
          AND (starts_at IS NULL OR starts_at <= UTC_TIMESTAMP())
          AND (ends_at   IS NULL OR ends_at   >= UTC_TIMESTAMP())
        )
      ORDER BY priority_weight DESC, id DESC
      LIMIT 50
    ";
    $rows = $GLOBALS['pdo']->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $items = [];
    foreach ($rows as $r) {
      $r['id']             = (int)($r['id'] ?? 0);
      $r['priority']       = (int)($r['priority_weight'] ?? 0);          // numeric weight
      $r['priority_label'] = (string)($r['priority_label'] ?? 'normal'); // string label
      $r['is_dismissible'] = (int)($r['is_dismissible'] ?? 1);
      $r['version']        = (int)($r['version'] ?? 1);
      $r['is_recurring']   = (int)($r['is_recurring'] ?? 0);

      if ($r['is_recurring'] === 1) {
        if (ann_should_recurring_be_active($r)) $items[] = $r;
      } else {
        $now = (new DateTime('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s');
        $after = empty($r['starts_at']) || $r['starts_at'] <= $now;
        $before= empty($r['ends_at'])   || $r['ends_at']   >= $now;
        if ($after && $before) $items[] = $r;
      }
    }

    usort($items, function($a,$b){
      if ($a['priority'] !== $b['priority']) return $b['priority'] <=> $a['priority'];
      return $b['id'] <=> $a['id'];
    });
    $items = array_slice($items, 0, 3);
  } catch (\Throwable $e) {
    $items = [];
  }
}

if (!$items) return;

/* ---------- helpers (fallback schedule eval) ---------- */
function ann_norm_time(?string $t): ?string {
  if (!$t) return null; $t=trim($t);
  if ($t==='') return null;
  if (preg_match('/^\d{2}:\d{2}:\d{2}$/',$t)) return $t;
  if (preg_match('/^\d{2}:\d{2}$/',$t)) return $t.':00';
  return null;
}
function ann_time_ok(DateTime $now, ?string $start, ?string $end): bool {
  if (!$start && !$end) return true;
  $cur=$now->format('H:i:s');
  if ($start && $end) {
    if ($end >= $start) return ($cur >= $start && $cur <= $end);
    return ($cur >= $start || $cur <= $end);
  } elseif ($start) return ($cur >= $start);
  else return ($cur <= $end);
}
function ann_week_list(string $pattern): array {
  $map = [
    'mon'=>1,'monday'=>1,'mån'=>1,'måndag'=>1,
    'tue'=>2,'tuesday'=>2,'tis'=>2,'tisdag'=>2,
    'wed'=>3,'wednesday'=>3,'ons'=>3,'onsdag'=>3,
    'thu'=>4,'thursday'=>4,'tor'=>4,'tors'=>4,'torsdag'=>4,
    'fri'=>5,'friday'=>5,'fre'=>5,'fredag'=>5,
    'sat'=>6,'saturday'=>6,'lör'=>6,'lördag'=>6,'lordag'=>6,'lardag'=>6,
    'sun'=>7,'sunday'=>7,'sön'=>7,'söndag'=>7,'sondag'=>7,
  ];
  $out=[]; foreach (explode(',', strtolower($pattern)) as $tok) {
    $t=trim($tok); if ($t==='') continue;
    if (isset($map[$t])) { $out[]=$map[$t]; continue; }
    if (ctype_digit($t)) { $n=(int)$t; if ($n>=1&&$n<=7) $out[]=$n; }
  }
  $out=array_values(array_unique($out)); sort($out); return $out;
}
function ann_month_days(string $pattern,int $last): array {
  $out=[]; foreach (explode(',', strtolower($pattern)) as $tok) {
    $t=trim($tok); if ($t==='') continue;
    if ($t==='last'){ $out[]=$last; continue; }
    if (ctype_digit($t)){ $n=(int)$t; if($n>=1&&$n<=$last)$out[]=$n; }
  }
  $out=array_values(array_unique($out)); sort($out); return $out;
}
function ann_year_list(string $pattern): array {
  $out=[]; foreach (explode(',', strtolower($pattern)) as $tok) {
    $t=trim($tok); if ($t==='') continue;
    if (preg_match('/^(0[1-9]|1[0-2])-(0[1-9]|[12]\d|3[01])$/',$t)) $out[]=$t;
  }
  $out=array_values(array_unique($out)); sort($out); return $out;
}
function ann_should_recurring_be_active(array $a): bool {
  $type=strtolower((string)($a['recurrence_type']??'')); $pattern=trim((string)($a['recurrence_pattern']??''));
  if ($type===''||$pattern==='') return false;
  $tzName=trim((string)($a['timezone']??'')); if ($tzName==='') $tzName='Europe/Stockholm';
  try{ $tz=new DateTimeZone($tzName);}catch(\Throwable){$tz=new DateTimeZone('Europe/Stockholm');}
  $now=new DateTime('now',$tz);
  $start=ann_norm_time($a['recurrence_start_time']??null); $end=ann_norm_time($a['recurrence_end_time']??null);

  if ($type==='weekly') {
    $allowed=ann_week_list($pattern); if (!$allowed) return false;
    $dow=(int)$now->format('N'); if(!in_array($dow,$allowed,true)) return false;
    return ann_time_ok($now,$start,$end);
  } elseif ($type==='monthly') {
    $day=(int)$now->format('j'); $last=(int)$now->format('t');
    if(!in_array($day,ann_month_days($pattern,$last),true)) return false;
    return ann_time_ok($now,$start,$end);
  } elseif ($type==='yearly') {
    $md=$now->format('m-d'); if(!in_array($md,ann_year_list($pattern),true)) return false;
    return ann_time_ok($now,$start,$end);
  }
  return false;
}

/* ---------- UI mapping: unique class + label + icon per priority ---------- */

function ann_style(string $label, int $weight): array {
  $label = strtolower(trim($label));
  // Known labels (ENUM)
  $map = [
    'critical'    => ['ann-critical',    'Critical',    'triangle'],
    'urgent'      => ['ann-urgent',      'Urgent',      'exclam-circle'],
    'important'   => ['ann-important',   'Important',   'bell'],
    'maintenance' => ['ann-maintenance', 'Maintenance', 'wrench'],
    'event'       => ['ann-event',       'Event',       'calendar'],
    'normal'      => ['ann-normal',      'Notice',      'note'],
  ];
  if (isset($map[$label])) return $map[$label];

  // Fallback by weight (in case of future labels not in CSS yet)
  if ($weight >= 3) return ['ann-critical',  'Critical',  'triangle'];
  if ($weight === 2) return ['ann-urgent',    'Urgent',    'exclam-circle'];
  if ($weight === 1) return ['ann-important', 'Important', 'bell'];
  return ['ann-normal', 'Notice', 'note'];
}

function ann_icon_svg(string $kind): string {
  return match ($kind) {
    'triangle' => '<svg width="18" height="18" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M1 21h22L12 2 1 21zm12-3h-2v2h2v-2zm0-8h-2v6h2V10z"/></svg>',
    'exclam-circle' => '<svg width="18" height="18" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M12 2a10 10 0 100 20 10 10 0 000-20zm1 15h-2v-2h2v2zm0-4h-2V7h2v6z"/></svg>',
    'bell' => '<svg width="18" height="18" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M12 22a2 2 0 002-2H10a2 2 0 002 2zm6-6V11a6 6 0 10-12 0v5l-2 2v1h16v-1l-2-2z"/></svg>',
    'wrench' => '<svg width="18" height="18" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M22.7 19.3l-6.4-6.4a6 6 0 01-7.6-7.6l3.4 3.4 2.8-.6.6-2.8L12 1.7a6 6 0 017.6 7.6l6.4 6.4-3.3 3.3zM4 20a2 2 0 110-4 2 2 0 010 4z"/></svg>',
    'calendar' => '<svg width="18" height="18" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M7 2h2v2h6V2h2v2h3a2 2 0 012 2v14a2 2 0 01-2 2H4a2 2 0 01-2-2V6a2 2 0 012-2h3V2zm13 8H4v10h16V10z"/></svg>',
    'note' => '<svg width="18" height="18" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M13 2H6a2 2 0 00-2 2v7h2V4h7V2zm8 6h-6a2 2 0 00-2 2v12l5-3 5 3V10a2 2 0 00-2-2z"/></svg>',
    default => '<svg width="18" height="18" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><circle cx="12" cy="12" r="10"/></svg>',
  };
}
?>

<section id="announcements" class="pt-8 pb-6 relative scroll-target">
  <div class="container max-w-3xl mx-auto px-6">
    <div class="text-center mb-8 animate-on-scroll">
      <p class="kicker">Important Updates</p>
      <h2 class="h-display text-3xl font-bold">Server Announcements</h2>
    </div>

    <div class="space-y-6 cards-deferred" data-ann-list>
      <?php foreach ($items as $a):
        $weight = (int)($a['priority'] ?? 0);                    // numeric 0..3 (for sorting/fallback)
        $label  = (string)($a['priority_label'] ?? 'normal');    // enum label
        $dismissible = (int)($a['is_dismissible'] ?? 1) === 1;
        $key = 'ann-'.($a['id'] ?? 0).'-v'.($a['version'] ?? 1);

        [$priorityClass, $badgeText, $iconKind] = ann_style($label, $weight);

        $bodyHtml = ann_body_html($a);
      ?>
      <article class="rough-card rough-card-hover ann-card <?= $priorityClass ?>" data-ann-card data-key="<?= e($key) ?>">
        <div class="card-radial"></div>
        <div class="p-5">
          <div class="flex items-start gap-4">
            <div class="ann-icon"><?= ann_icon_svg($iconKind) ?></div>

            <div class="flex-1 min-w-0">
              <div class="flex items-center gap-3 mb-3">
                <span class="ann-badge <?= $priorityClass ?>"><?= e($badgeText) ?></span>
                <?php if ($dismissible): ?>
                  <button type="button" class="ann-dismiss ml-auto" data-ann-dismiss title="Dismiss">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M18.3 5.71L12 12.01l-6.3-6.3-1.41 1.41 6.3 6.3-6.3 6.3 1.41 1.41 6.3-6.3 6.3 6.3 1.41-1.41-6.3-6.3 6.3-6.3z"/></svg>
                  </button>
                <?php endif; ?>
              </div>

              <h3 class="ann-title"><?php
                $title = (string)($a['title'] ?? '');
                // Parse WoW colors in title
                if (strpos($title, '|cff') !== false) {
                  $title = str_replace(['&#124;', '&pipe;', '&#x7C;'], '|', $title);
                  $title = preg_replace_callback('~\|cff([0-9a-fA-F]{6})(.*?)\|r~s', function($m) {
                    return '<span style="color: #' . strtolower($m[1]) . ';">' . htmlspecialchars($m[2], ENT_QUOTES, 'UTF-8') . '</span>';
                  }, $title);
                  echo $title;
                } else {
                  echo e($title);
                }
              ?></h3>
              <?php if ($bodyHtml !== ''): ?>
                <div class="ann-body"><?= $bodyHtml ?></div>
              <?php endif; ?>

              <?php if (!empty($a['cta_url']) && !empty($a['cta_text'])): ?>
                <div class="mt-4">
                  <a href="<?= e($a['cta_url']) ?>" class="btn btn-warm btn-sm"><?= e($a['cta_text']) ?></a>
                </div>
              <?php endif; ?>
            </div>
          </div>
        </div>
      </article>
      <?php endforeach; ?>
    </div>
  </div>
</section>

<style>
#announcements .ann-card { position: relative; }

/* Any decorative overlay must not eat clicks */
#announcements .ann-card .card-radial { 
  position: absolute;
  inset: 0;
  z-index: 0;
  pointer-events: none;
}

/* If your theme uses ::before/::after on rough cards, disable their hitbox too */
#announcements .ann-card::before,
#announcements .ann-card::after {
  pointer-events: none !important;
}

/* Ensure real content sits above the overlay and can receive clicks */
#announcements .ann-card > .p-5,
#announcements .ann-card .ann-body,
#announcements .ann-card .ann-body a,
#announcements .ann-card .ann-title,
#announcements .ann-card .ann-badge,
#announcements .ann-card .ann-icon,
#announcements .ann-card .ann-dismiss {
  position: relative;
  z-index: 1;
  pointer-events: auto;
}



/* Base card + icon look */
.ann-icon{display:flex;align-items:center;justify-content:center;width:36px;height:36px;border-radius:0;background:var(--white-05);border:1px solid var(--border-muted);box-shadow:inset 0 1px 0 var(--white-06);flex-shrink:0;color:var(--text-muted)}
.ann-badge{display:inline-flex;align-items:center;padding:.25rem .5rem;font-size:11px;font-weight:700;letter-spacing:.02em;text-transform:uppercase;border-radius:0;border:1px solid;box-shadow:0 1px 2px var(--shadow-ink-250),inset 0 1px 0 var(--white-25)}
.ann-title{font-size:18px;font-weight:700;line-height:1.3;color:var(--text);margin-bottom:8px;text-shadow:0 1px 2px var(--shadow-ink-400)}
.ann-body{color:var(--text-muted);line-height:1.6;font-size:14px}
.ann-dismiss{display:inline-flex;align-items:center;justify-content:center;width:24px;height:24px;color:var(--white-40);background:var(--white-04);border:1px solid var(--border-subtle);border-radius:0;transition:.2s;cursor:pointer}
.ann-dismiss:hover{color:var(--white-60);background:var(--white-06);border-color:var(--border-muted)}
@media (max-width:768px){.ann-title{font-size:16px}.ann-body{font-size:13px}.ann-icon{width:32px;height:32px}.ann-icon svg{width:16px;height:16px}}

/* Emerald link styling (like news) */
#announcements {
  --ann-link: var(--brand-400, #34d399);
  --ann-link-hover: var(--brand-300, #6ee7b7);
  --ann-link-visited: #2bbd8f;
}
#announcements .ann-body a{
  color: var(--ann-link);
  text-decoration: underline;
  text-underline-offset: 2px;
}
#announcements .ann-body a:hover{ color: var(--ann-link-hover); }
#announcements .ann-body a:visited{ color: var(--ann-link-visited); }
#announcements .ann-body a:focus-visible{
  outline:2px solid var(--ann-link); outline-offset:2px; border-radius:2px;
}

/* ========== Unique looks per priority ========== */
/* Critical — red */
.ann-card.ann-critical{border-left:4px solid var(--red-500,#ef4444)}
.ann-critical .ann-icon{color:var(--red-400,#f87171);background:linear-gradient(180deg,rgba(239,68,68,.08),rgba(0,0,0,.1));border-color:rgba(239,68,68,.2)}
.ann-badge.ann-critical{background:linear-gradient(90deg,var(--red-400,#f87171),var(--red-500,#ef4444));border-color:var(--red-400,#f87171);color:var(--chip-fg-light,#fff)}

/* Urgent — orange */
.ann-card.ann-urgent{border-left:4px solid var(--orange-500,#f97316)}
.ann-urgent .ann-icon{color:var(--orange-400,#fb923c);background:linear-gradient(180deg,rgba(249,115,22,.10),rgba(0,0,0,.1));border-color:rgba(249,115,22,.25)}
.ann-badge.ann-urgent{background:linear-gradient(90deg,var(--orange-400,#fb923c),var(--orange-500,#f97316));border-color:var(--orange-400,#fb923c);color:var(--chip-fg-light,#fff)}

/* Important — amber */
.ann-card.ann-important{border-left:4px solid var(--amber-500,#f59e0b)}
.ann-important .ann-icon{color:var(--amber-400,#fbbf24);background:linear-gradient(180deg,rgba(245,158,11,.08),rgba(0,0,0,.1));border-color:rgba(245,158,11,.2)}
.ann-badge.ann-important{background:linear-gradient(90deg,var(--amber-400,#fbbf24),var(--amber-500,#f59e0b));border-color:var(--amber-400,#fbbf24);color:var(--chip-on-amber-fg,#1a1300)}

/* Maintenance — blue */
.ann-card.ann-maintenance{border-left:4px solid var(--blue-500,#3b82f6)}
.ann-maintenance .ann-icon{color:var(--blue-400,#60a5fa);background:linear-gradient(180deg,rgba(59,130,246,.10),rgba(0,0,0,.1));border-color:rgba(59,130,246,.25)}
.ann-badge.ann-maintenance{background:linear-gradient(90deg,var(--blue-400,#60a5fa),var(--blue-500,#3b82f6));border-color:var(--blue-400,#60a5fa);color:#fff}

/* Event — violet */
.ann-card.ann-event{border-left:4px solid var(--violet-500,#8b5cf6)}
.ann-event .ann-icon{color:var(--violet-400,#a78bfa);background:linear-gradient(180deg,rgba(139,92,246,.10),rgba(0,0,0,.1));border-color:rgba(139,92,246,.25)}
.ann-badge.ann-event{background:linear-gradient(90deg,var(--violet-400,#a78bfa),var(--violet-500,#8b5cf6));border-color:var(--violet-400,#a78bfa);color:#fff}

/* Normal — brand/emerald */
.ann-card.ann-normal{border-left:4px solid var(--brand-500,#10b981)}
.ann-normal .ann-icon{color:var(--brand-400,#34d399);background:linear-gradient(180deg,var(--white-03,rgba(255,255,255,.03)),var(--shadow-ink-120,rgba(0,0,0,.12)));border-color:var(--border-card,rgba(255,255,255,.08))}
.ann-badge.ann-normal{background:linear-gradient(90deg,var(--brand-400,#34d399),var(--brand-500,#10b981));border-color:var(--brand-400,#34d399);color:var(--chip-on-emerald-fg,#072b22)}
</style>

<script>
document.addEventListener('DOMContentLoaded', () => {
  const list = document.querySelector('[data-ann-list]');
  if (!list) return;

  list.querySelectorAll('[data-ann-card]').forEach(card => {
    const key = card.getAttribute('data-key');
    if (key && localStorage.getItem('ann.dismiss.'+key) === '1') {
      card.remove();
      return;
    }
    const btn = card.querySelector('[data-ann-dismiss]');
    if (btn) btn.addEventListener('click', () => {
      if (key) try { localStorage.setItem('ann.dismiss.'+key, '1'); } catch(e){}
      card.style.opacity = '0';
      card.style.transform = 'translateY(-8px) scale(0.98)';
      card.style.transition = 'all .3s cubic-bezier(0.4,0,0.2,1)';
      setTimeout(() => card.remove(), 300);
    });
  });
});
</script>
