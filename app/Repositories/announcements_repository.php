<?php
/**
 * Announcements Repository — Live evaluation for recurring schedules
 * One-time: uses starts_at/ends_at in UTC
 * Recurring: evaluated live each request using recurrence_* + timezone
 */
declare(strict_types=1);

namespace App\Repositories;

use PDO;
use DateTimeImmutable;
use DateTimeZone;

class announcements_repository extends base_repository
{
    protected string $table = 'announcements';

    /** Default site TZ when row.timezone is NULL/'' */
    private const SITE_DEFAULT_TZ = 'Europe/Stockholm';

    public function get_active(int $limit = 3): array
    {
        $limit = max(1, min(10, $limit));

        $sql = "
            SELECT
              id, title, body,
              priority AS priority_label,   -- ENUM label
              priority_weight,              -- generated numeric weight
              cta_text, cta_url,
              is_dismissible, version,
              starts_at, ends_at,
              is_recurring, recurrence_type, recurrence_pattern,
              recurrence_start_time, recurrence_end_time, timezone
            FROM {$this->table}
            WHERE
              (is_recurring = 1)
              OR
              (
                is_recurring = 0
                AND (starts_at IS NULL OR starts_at <= UTC_TIMESTAMP())
                AND (ends_at   IS NULL OR ends_at   >= UTC_TIMESTAMP())
              )
            ORDER BY priority_weight DESC, id DESC
            LIMIT 50
        ";

        $stmt = $this->get_pdo()->prepare($sql);
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $out = [];
        foreach ($rows as $r) {
            $r = $this->normalize($r);

            if ($r['is_recurring'] === 1) {
                if ($this->should_recurring_be_active($r)) $out[] = $r;
            } else {
                if ($this->is_one_time_active($r)) $out[] = $r;
            }
        }

        usort($out, function($a, $b) {
            if ($a['priority'] !== $b['priority']) return $b['priority'] <=> $a['priority'];
            return $b['id'] <=> $a['id'];
        });

        return array_slice($out, 0, $limit);
    }

    private function normalize(array $r): array
    {
        $r['id']             = (int)($r['id'] ?? 0);
        $r['is_dismissible'] = (int)($r['is_dismissible'] ?? 1);
        $r['version']        = (int)($r['version'] ?? 1);
        $r['is_recurring']   = (int)($r['is_recurring'] ?? 0);

        $r['title'] = (string)($r['title'] ?? '');
        $r['body']  = (string)($r['body'] ?? '');

        $r['cta_text'] = isset($r['cta_text']) ? (string)$r['cta_text'] : null;
        $r['cta_url']  = isset($r['cta_url'])  ? (string)$r['cta_url']  : null;

        $r['starts_at'] = $r['starts_at'] ?? null; // UTC DATETIME as string
        $r['ends_at']   = $r['ends_at']   ?? null;

        $r['recurrence_type']        = $r['recurrence_type']        ?? null;
        $r['recurrence_pattern']     = $r['recurrence_pattern']     ?? null;
        $r['recurrence_start_time']  = $r['recurrence_start_time']  ?? null;
        $r['recurrence_end_time']    = $r['recurrence_end_time']    ?? null;
        $r['timezone']               = $r['timezone']               ?? null;

        // New: label + numeric weight
        $label = (string)($r['priority_label'] ?? 'normal');
        $weight = isset($r['priority_weight']) ? (int)$r['priority_weight'] : 0;

        $r['priority_label'] = $label;
        $r['priority'] = $weight; // keep existing contract: 'priority' is the numeric weight used by the UI

        return $r;
    }

    private function is_one_time_active(array $a): bool
    {
        $nowUtc = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $nowStr = $nowUtc->format('Y-m-d H:i:s');

        $starts = $a['starts_at'];
        $ends   = $a['ends_at'];

        $after  = !$starts || $starts <= $nowStr;
        $before = !$ends   || $ends   >= $nowStr;

        return $after && $before;
    }

    private function should_recurring_be_active(array $a): bool
    {
        $type    = strtolower((string)($a['recurrence_type'] ?? ''));
        $pattern = trim((string)($a['recurrence_pattern'] ?? ''));
        if ($type === '' || $pattern === '') return false;

        $tzName = trim((string)($a['timezone'] ?? ''));
        $tzName = $tzName !== '' ? $tzName : self::SITE_DEFAULT_TZ;

        try { $tz = new DateTimeZone($tzName); }
        catch (\Throwable) { $tz = new DateTimeZone(self::SITE_DEFAULT_TZ); }

        $now   = new DateTimeImmutable('now', $tz);
        $start = $this->norm_time($a['recurrence_start_time'] ?? null);
        $end   = $this->norm_time($a['recurrence_end_time']   ?? null);

        return match ($type) {
            'weekly'  => $this->weekly($now, $pattern, $start, $end),
            'monthly' => $this->monthly($now, $pattern, $start, $end),
            'yearly'  => $this->yearly($now, $pattern, $start, $end),
            default   => false,
        };
    }

    private function norm_time(?string $t): ?string {
        if (!$t) return null;
        $t = trim($t);
        if ($t === '') return null;
        if (preg_match('/^\d{2}:\d{2}:\d{2}$/', $t)) return $t;
        if (preg_match('/^\d{2}:\d{2}$/', $t)) return $t . ':00';
        return null;
    }

    private function in_window(DateTimeImmutable $now, ?string $start, ?string $end): bool
    {
        if (!$start && !$end) return true;
        $cur = $now->format('H:i:s');

        if ($start && $end) {
            if ($end >= $start) return ($cur >= $start && $cur <= $end);
            return ($cur >= $start || $cur <= $end); // crosses midnight
        } elseif ($start) {
            return ($cur >= $start);
        } else {
            return ($cur <= $end);
        }
    }

    private function weekly(DateTimeImmutable $now, string $pattern, ?string $start, ?string $end): bool
    {
        $allowed = $this->weekday_list($pattern);
        if (!$allowed) return false;

        $dow = (int)$now->format('N'); // 1..7 (Mon..Sun)
        if (!in_array($dow, $allowed, true)) return false;

        return $this->in_window($now, $start, $end);
    }

    private function weekday_list(string $pattern): array
    {
        $map = [
            'mon'=>1,'monday'=>1,'mån'=>1,'måndag'=>1,
            'tue'=>2,'tuesday'=>2,'tis'=>2,'tisdag'=>2,
            'wed'=>3,'wednesday'=>3,'ons'=>3,'onsdag'=>3,
            'thu'=>4,'thursday'=>4,'tor'=>4,'tors'=>4,'torsdag'=>4,
            'fri'=>5,'friday'=>5,'fre'=>5,'fredag'=>5,
            'sat'=>6,'saturday'=>6,'lör'=>6,'lördag'=>6,'lordag'=>6,'lardag'=>6,
            'sun'=>7,'sunday'=>7,'sön'=>7,'söndag'=>7,'sondag'=>7,
        ];
        $out = [];
        foreach (explode(',', strtolower($pattern)) as $tok) {
            $t = trim($tok);
            if ($t === '') continue;
            if (isset($map[$t])) { $out[] = $map[$t]; continue; }
            if (ctype_digit($t)) { $n=(int)$t; if ($n>=1 && $n<=7) $out[]=$n; }
        }
        $out = array_values(array_unique($out));
        sort($out);
        return $out;
    }

    private function monthly(DateTimeImmutable $now, string $pattern, ?string $start, ?string $end): bool
    {
        $day     = (int)$now->format('j');
        $lastDay = (int)$now->format('t');
        $targets = $this->month_days($pattern, $lastDay);

        if (!in_array($day, $targets, true)) return false;
        return $this->in_window($now, $start, $end);
    }

    private function month_days(string $pattern, int $lastDay): array
    {
        $out = [];
        foreach (explode(',', strtolower($pattern)) as $tok) {
            $t = trim($tok);
            if ($t === '') continue;
            if ($t === 'last') { $out[] = $lastDay; continue; }
            if (ctype_digit($t)) {
                $n=(int)$t; if ($n>=1 && $n<=$lastDay) $out[]=$n;
            }
        }
        $out = array_values(array_unique($out));
        sort($out);
        return $out;
    }

    private function yearly(DateTimeImmutable $now, string $pattern, ?string $start, ?string $end): bool
    {
        $md = $now->format('m-d');
        $targets = $this->md_list($pattern);
        if (!in_array($md, $targets, true)) return false;
        return $this->in_window($now, $start, $end);
    }

    private function md_list(string $pattern): array
    {
        $out = [];
        foreach (explode(',', strtolower($pattern)) as $tok) {
            $t = trim($tok);
            if ($t === '') continue;
            if (preg_match('/^(0[1-9]|1[0-2])-(0[1-9]|[12]\d|3[01])$/', $t)) $out[]=$t;
        }
        $out = array_values(array_unique($out));
        sort($out);
        return $out;
    }
}
