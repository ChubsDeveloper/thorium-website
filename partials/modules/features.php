<?php
/**
 * partials/modules/features.php
 * Module template — “Server Features” grid (with Bloodmarks-style header).
 */

declare(strict_types=1);

// Uses APP_ROOT from modules_view.php
require_once APP_ROOT . '/features_repo.php';

$rows = features_all(24);

/** Map 'icon' names to inline SVGs (currentColor). Size ~20px. */
function feature_icon_svg(string $k): string {
    switch (strtolower(trim($k))) {
        /* ===== UI/General ===== */
        case 'check':         return '<svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 13l4 4L19 7"/></svg>';
        case 'x':             return '<svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2"><path d="M6 6l12 12M6 18L18 6"/></svg>';
        case 'plus':          return '<svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 5v14M5 12h14"/></svg>';
        case 'minus':         return '<svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2"><path d="M5 12h14"/></svg>';
        case 'star':          return '<svg viewBox="0 0 24 24" width="20" height="20" fill="currentColor"><path d="M12 2l3.4 6.9 7.6 1.1-5.5 5.3 1.3 7.7L12 19l-6.8 4 1.3-7.7L1 10l7.6-1.1L12 2z"/></svg>';
        case 'sparkles':      return '<svg viewBox="0 0 24 24" width="20" height="20" fill="currentColor"><path d="M12 2l2 5 5 2-5 2-2 5-2-5-5-2 5-2 2-5zM19 13l1 3 3 1-3 1-1 3-1-3-3-1 3-1 1-3zM4 13l1 2 2 1-2 1-1 2-1-2-2-1 2-1 1-2z"/></svg>';
        case 'heart':         return '<svg viewBox="0 0 24 24" width="20" height="20" fill="currentColor"><path d="M12 21s-7.5-4.8-9.5-8.4C.7 9.1 2.6 6 5.7 6c1.9 0 3.1 1 4.3 2.4C11.1 7 12.3 6 14.3 6c3.1 0 5 3.1 3.2 6.6C19.5 16.2 12 21 12 21z"/></svg>';
        case 'home':          return '<svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 12l9-7 9 7v9h-6v-6H9v6H3z"/></svg>';
        case 'search':        return '<svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="7"/><path d="M21 21l-3.65-3.65"/></svg>';
        case 'bell':          return '<svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 8a6 6 0 10-12 0c0 7-3 7-3 7h18s-3 0-3-7"/><path d="M13.73 21a2 2 0 01-3.46 0"/></svg>';
        case 'message':       return '<svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15a4 4 0 01-4 4H8l-5 3V7a4 4 0 014-4h10a4 4 0 014 4z"/></svg>';
        case 'users':         return '<svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 00-4-4H7a4 4 0 00-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 00-3-3.87"/><path d="M16 3.13a4 4 0 010 7.75"/></svg>';
        case 'user':          return '<svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="7" r="4"/><path d="M5 21a7 7 0 0114 0"/></svg>';
        case 'globe':         return '<svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="9"/><path d="M3 12h18M12 3c3 3 3 15 0 18M12 3c-3 3-3 15 0 18"/></svg>';
        case 'link':          return '<svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2"><path d="M10 13a5 5 0 007.07 0l2.12-2.12a5 5 0 00-7.07-7.07L10 5"/><path d="M14 11a5 5 0 01-7.07 0L4.8 8.88a5 5 0 017.07-7.07L14 3"/></svg>';
        case 'download':      return '<svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 3v12"/><path d="M7 10l5 5 5-5"/><path d="M5 21h14"/></svg>';
        case 'upload':        return '<svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 21V9"/><path d="M7 14l5-5 5 5"/><path d="M5 3h14"/></svg>';
        case 'lock':          return '<svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2"><rect x="4" y="10" width="16" height="10" rx="2"/><path d="M8 10V7a4 4 0 118 0v3"/></svg>';
        case 'unlock':        return '<svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2"><rect x="4" y="10" width="16" height="10" rx="2"/><path d="M16 10V7a4 4 0 00-8 0"/></svg>';
        case 'exclamation': case 'bang': case '!':
                              return '<svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="M12 7v6"/><path d="M12 17h.01"/></svg>';
        case 'info':          return '<svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="M12 16v-4"/><path d="M12 8h.01"/></svg>';
        case 'question':      return '<svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="M9.1 9a3 3 0 015.8 1c0 2-3 2-3 4"/><path d="M12 17h.01"/></svg>';
        case 'gear': case 'settings':
                              return '<svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 00.33 1.82l.06.06a2 2 0 01-2.83 2.83l-.06-.06a1.65 1.65 0 00-1.82-.33 1.65 1.65 0 00-1 1.51V22a2 2 0 01-4 0v-.17a1.65 1.65 0 00-1-1.51 1.65 1.65 0 00-1.82.33l-.06.06a2 2 0 11-2.83-2.83l.06-.06a1.65 1.65 0 00.33-1.82 1.65 1.65 0 00-1.51-1H2a2 2 0 010-4h.17a1.65 1.65 0 001.51-1 1.65 1.65 0 00-.33-1.82l-.06-.06A2 2 0 115.12 3.8l.06.06a1.65 1.65 0 001.82.33H7A1.65 1.65 0 008.51 3V3a2 2 0 014 0v.17A1.65 1.65 0 0014 4.68h.01a1.65 1.65 0 001.82-.33l.06-.06a2 2 0 112.83 2.83l-.06.06a1.65 1.65 0 00-.33 1.82V9a1.65 1.65 0 001.51 1H22a2 2 0 010 4h-.17a1.65 1.65 0 00-1.51 1z"/></svg>';

        /* ===== Time/Status ===== */
        case 'clock':         return '<svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="9"/><path d="M12 7v6l4 2"/></svg>';
        case 'calendar':      return '<svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="5" width="18" height="16" rx="2"/><path d="M16 3v4M8 3v4M3 11h18"/></svg>';
        case 'hourglass':     return '<svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2"><path d="M6 2h12M6 22h12"/><path d="M7 3c0 5 10 5 10 10s-10 5-10 10"/></svg>';
        case 'trophy':        return '<svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2"><path d="M8 21h8M10 17h4"/><path d="M7 4h10v3a5 5 0 01-10 0z"/><path d="M3 5h4v2a3 3 0 01-3 3H3zM21 5h-4v2a3 3 0 003 3h1z"/></svg>';

        /* ===== Web/Server ===== */
        case 'server':        return '<svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="6" rx="2"/><rect x="3" y="14" width="18" height="6" rx="2"/><circle cx="7" cy="7" r="1"/><circle cx="7" cy="17" r="1"/></svg>';
        case 'database':      return '<svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2"><ellipse cx="12" cy="5" rx="8" ry="3"/><path d="M4 5v6c0 1.7 3.6 3 8 3s8-1.3 8-3V5"/><path d="M4 11v6c0 1.7 3.6 3 8 3s8-1.3 8-3v-6"/></svg>';
        case 'cloud':         return '<svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2"><path d="M7 19a5 5 0 010-10 7 7 0 0113 3 4 4 0 010 8H7z"/></svg>';

        /* ===== Economy/Rewards ===== */
        case 'coin':          return '<svg viewBox="0 0 24 24" width="20" height="20" fill="currentColor"><circle cx="12" cy="12" r="9"/><circle cx="12" cy="12" r="5" fill="none" stroke="currentColor" stroke-width="2"/></svg>';
        case 'gem': case 'diamond':
                              return '<svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 9l4-5h10l4 5-9 12z"/><path d="M7 9l5 12 5-12M7 9h10"/></svg>';
        case 'crown':         return '<svg viewBox="0 0 24 24" width="20" height="20" fill="currentColor"><path d="M3 9l4 2 5-6 5 6 4-2-2 10H5L3 9z"/></svg>';
        case 'gift':          return '<svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 12v8a2 2 0 01-2 2H6a2 2 0 01-2-2v-8h16z"/><path d="M2 7h20"/><path d="M12 22V7"/><path d="M12 7c-1.5 0-3-1-3-2s1-2 3-2 3 1 3 2-1.5 2-3 2z"/></svg>';
        case 'bag':           return '<svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2"><path d="M6 7h12l1 13H5L6 7z"/><path d="M9 7a3 3 0 016 0"/></svg>';
        case 'chest':         return '<svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="7" width="18" height="12" rx="2"/><path d="M3 11h18M12 11v4"/></svg>';

        /* ===== Fantasy/Gameplay ===== */
        case 'sword':         return '<svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 3l7 7-2 2-7-7z"/><path d="M11 6l-8 8v3h3l8-8"/></svg>';
        case 'crossed-swords': case 'swords':
                              return '<svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2"><path d="M2 21l7-7M9 21L3 15M22 21l-7-7M15 21l6-6"/><path d="M7 4l5 5M17 4l-5 5"/></svg>';
        case 'axe':           return '<svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 21l9-9"/><path d="M17 3l4 4-6 6-4-4 6-6z"/></svg>';
        case 'bow':           return '<svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 12a9 9 0 0018 0A9 9 0 003 12z"/><path d="M3 12h18"/></svg>';
        case 'shield':        return '<svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 2l7 4v6c0 5-3.5 9-7 10-3.5-1-7-5-7-10V6l7-4z"/></svg>';
        case 'helmet':        return '<svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 13a8 8 0 0116 0v3H4v-3z"/><path d="M10 16v6M14 16v6"/></svg>';
        case 'skull':         return '<svg viewBox="0 0 24 24" width="20" height="20" fill="currentColor"><path d="M12 2a9 9 0 00-9 9c0 3 2 5 4 6v3h4v-2h2v2h4v-3c2-1 4-3 4-6a9 9 0 00-9-9z"/><circle cx="9" cy="11" r="1.5"/><circle cx="15" cy="11" r="1.5"/></svg>';
        case 'potion': case 'flask':
                              return '<svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2"><path d="M10 2h4M10 2v4l-5 7a6 6 0 005 9h4a6 6 0 005-9l-5-7V2"/></svg>';
        case 'scroll':        return '<svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 7a3 3 0 103 3h10a3 3 0 100-6H7a3 3 0 00-3 3z"/><path d="M7 10v7a3 3 0 003 3h7"/></svg>';
        case 'book':          return '<svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 4h12a4 4 0 014 4v12H8a4 4 0 01-4-4z"/><path d="M8 4v16"/></svg>';
        case 'quill': case 'feather':
                              return '<svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 3C12 5 6 11 4 20l7-2 9-9V3z"/></svg>';
        case 'map':           return '<svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 3l6 2 6-2v18l-6 2-6-2-6 2V5z"/><path d="M9 3v18M15 5v18"/></svg>';
        case 'compass':       return '<svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="9"/><path d="M15 9l-3 6-3-3 6-3z"/></svg>';
        case 'flag':          return '<svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 3v18"/><path d="M4 4h10l-1.5 3H20v7h-8l1.5-3H4z"/></svg>';
        case 'portal':        return '<svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2"><ellipse cx="12" cy="12" rx="8" ry="10"/><ellipse cx="12" cy="12" rx="3" ry="5"/></svg>';

        /* ===== Nature/Elements ===== */
        case 'leaf':          return '<svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 21C11 16 3 15 3 9c0-3.866 3.582-7 8-7 6 0 10 5 10 10 0 5-4 9-9 9z"/><path d="M8 13l8-8"/></svg>';
        case 'tree':          return '<svg viewBox="0 0 24 24" width="20" height="20" fill="currentColor"><path d="M12 2l6 8h-4l4 6h-4l3 6H7l3-6H6l4-6H6z"/></svg>';
        case 'mountain':      return '<svg viewBox="0 0 24 24" width="20" height="20" fill="currentColor"><path d="M2 20l8-12 4 6 2-3 6 9z"/></svg>';
        case 'fire':          return '<svg viewBox="0 0 24 24" width="20" height="20" fill="currentColor"><path d="M12 2s4 4 4 8a4 4 0 11-8 0c0-2 1-3.5 2-5-5 2-7 7-5 11a8 8 0 0014 0c2-4-1-9-7-14z"/></svg>';
        case 'snow': case 'snowflake':
                              return '<svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 2v20M4.2 6.2l15.6 11.6M4.2 17.8L19.8 6.2"/><path d="M7 4l5 3 5-3M7 20l5-3 5 3"/></svg>';
        case 'water': case 'drop':
                              return '<svg viewBox="0 0 24 24" width="20" height="20" fill="currentColor"><path d="M12 3c-5 6-7 9-7 12a7 7 0 0014 0c0-3-2-6-7-12z"/></svg>';
        case 'wind':          return '<svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 12h10a3 3 0 100-6M3 18h13a2 2 0 100-4"/></svg>';
        case 'bolt':          return '<svg viewBox="0 0 24 24" width="20" height="20" fill="currentColor"><path d="M13 2L3 14h6l-2 8 10-12h-6l2-8z"/></svg>';

        /* ===== Tools/Professions ===== */
        case 'hammer':        return '<svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 21l8-8"/><path d="M14 3l7 7-4 4-7-7z"/></svg>';
        case 'anvil':         return '<svg viewBox="0 0 24 24" width="20" height="20" fill="currentColor"><path d="M3 18h18v2H3zM5 16h10l2-4H8l-3 4z"/></svg>';
        case 'wrench':        return '<svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 16l-5-5a5 5 0 10-7 7l5 5 7-7z"/></svg>';
        case 'pickaxe':       return '<svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 7c6-6 12-2 18-2M12 6l-1 12"/><path d="M8 18h8"/></svg>';
        case 'gearwork':      return feature_icon_svg('settings'); // alias

        /* ===== Music/Media ===== */
        case 'music':         return '<svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 18a3 3 0 11-2-2.83V6l10-2v9.17A3 3 0 1115 14"/></svg>';
        case 'play':          return '<svg viewBox="0 0 24 24" width="20" height="20" fill="currentColor"><path d="M6 4l14 8-14 8z"/></svg>';
        case 'pause':         return '<svg viewBox="0 0 24 24" width="20" height="20" fill="currentColor"><path d="M6 4h4v16H6zM14 4h4v16h-4z"/></svg>';

        /* ===== Alerts/Markers ===== */
        case 'alert': case 'warning':
                              return '<svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 2l10 18H2L12 2z"/><path d="M12 9v4M12 17h.01"/></svg>';
        case 'pin':           return '<svg viewBox="0 0 24 24" width="20" height="20" fill="currentColor"><path d="M12 22s7-7 7-12a7 7 0 10-14 0c0 5 7 12 7 12z"/></svg>';

        /* ===== Misc ===== */
        case 'key':           return '<svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2"><circle cx="7" cy="12" r="3"/><path d="M10 12h11l-3 3 3 3"/></svg>';
        case 'campfire':      return '<svg viewBox="0 0 24 24" width="20" height="20" fill="currentColor"><path d="M12 3c3 3 3 6 1.5 8S9 14 9 10c0-2 1.5-3.5 3-7z"/><path d="M5 21l14-5M5 16l14 5"/></svg>';
        case 'banner':        return '<svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2"><path d="M5 3v18"/><path d="M5 4h12l-4 4 4 4H5z"/></svg>';
        case 'route':         return '<svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2"><circle cx="7" cy="7" r="3"/><circle cx="17" cy="17" r="3"/><path d="M9.5 9.5l5 5"/></svg>';

        default:
            return '<svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="9"/></svg>';
    }
}

/** Accent → CSS var() color token */
function feature_accent_var(?string $a): string {
    $k = strtolower(trim((string)$a));
    $map = [
        'brand'=>'var(--brand-400,#34d399)','emerald'=>'var(--brand-400,#34d399)',
        'emerald-300'=>'var(--brand-300,#6ee7b7)','emerald-400'=>'var(--brand-400,#34d399)',
        'emerald-500'=>'var(--brand-500,#10b981)','emerald-700'=>'var(--brand-700,#047857)',

        'amber'=>'var(--amber-500,#f59e0b)','amber-400'=>'var(--amber-400,#fbbf24)',
        'amber-500'=>'var(--amber-500,#f59e0b)','amber-600'=>'var(--amber-600,#b45309)',
        'amber-700'=>'var(--amber-700,#92400e)',

        'copper'=>'var(--copper-400,#b87333)','bronze'=>'var(--bronze-400,#cd7f32)',
        'silver'=>'var(--silver-400,#cfd8dc)','gold'=>'var(--gold-400,#d4af37)',
        'gold-300'=>'var(--gold-300,#e6c75e)','gold-400'=>'var(--gold-400,#d4af37)',
        'gold-500'=>'var(--gold-500,#b88900)','platinum'=>'var(--platinum-400,#e5e4e2)',
        'diamond'=>'var(--diamond-400,#9bd3f7)',

        'ruby'=>'var(--red-500,#ef4444)','sapphire'=>'var(--blue-500,#3b82f6)',
        'amethyst'=>'var(--purple-500,#8b5cf6)','topaz'=>'#ffb347','emerald-gem'=>'var(--brand-500,#10b981)',
        'obsidian'=>'#3b3b3b','mithril'=>'#a7bdc4',

        'blue'=>'var(--blue-500,#3b82f6)','blue-400'=>'var(--blue-400,#60a5fa)',
        'purple'=>'var(--purple-500,#8b5cf6)','purple-400'=>'var(--purple-400,#a78bfa)',
        'red'=>'var(--red-500,#ef4444)','red-400'=>'var(--red-400,#f87171)',
        'pink'=>'var(--pink-500,#ec4899)','pink-400'=>'var(--pink-400,#f472b6)',
        'teal'=>'#14b8a6','cyan'=>'#06b6d4','sky'=>'#0ea5e9','indigo'=>'#6366f1',
        'violet'=>'#8b5cf6','rose'=>'#f43f5e','orange'=>'#f97316','yellow'=>'#eab308','lime'=>'#84cc16',

        'gray'=>'var(--gray-400,#9ca3af)','grey'=>'var(--gray-400,#9ca3af)','white'=>'#ffffff','black'=>'#000000',
    ];
    return $map[$k] ?? 'var(--brand-400,#34d399)';
}
?>
<section class="pt-8 pb-6 relative animate-on-scroll">
  <div class="container max-w-6xl mx-auto px-6">

    <!-- Header matches Bloodmarks: centered kicker + h2 -->
    <div class="text-center mb-8">
      <p class="kicker">Feature Showcase</p>
      <h2 class="h-display text-3xl font-bold">Server Features</h2>
    </div>

    <?php if (empty($rows)): ?>
      <div class="rough-card p-6 text-center">
        <p class="muted">No features published yet.</p>
      </div>
    <?php else: ?>
      <!-- Symmetric padding: top == bottom -->
      <div class="rough-card p-0 overflow-hidden">
        <div class="px-6 py-6">
          <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
            <?php foreach ($rows as $f): ?>
              <?php
                $title  = (string)$f['title'];
                $blurb  = (string)$f['blurb'];
                $icon   = (string)($f['icon'] ?? '');
                $url    = (string)($f['url']  ?? '');
                $accent = feature_accent_var($f['accent'] ?? null);
              ?>
              <article
                class="rounded-xl border border-white/10 bg-white/[0.03] p-4 tilt group hover:border-white/20 transition"
                style="box-shadow: inset 0 0 0 1px rgba(255,255,255,.05), 0 18px 40px -24px rgba(0,0,0,.6);">

                <!-- Title row: icon ALWAYS inline with title -->
<h3 class="font-bold leading-tight flex items-center gap-2"
    style="color: <?= e($accent) ?>;">
  <span class="feature-title-icon"
        style="display:inline-flex; line-height:0; transform: translateY(1px);">
    <?= feature_icon_svg($icon) ?>
  </span>
  <span class="truncate"><?= e($title) ?></span>
</h3>

                <!-- Blurb -->
                <p class="text-sm text-neutral-300 mt-2"><?= e($blurb) ?></p>

                <!-- Optional link -->
                <?php if ($url !== ''): ?>
                  <a href="<?= e($url) ?>" class="inline-flex items-center gap-1 text-xs mt-3"
                     style="color: <?= e($accent) ?>;">
                    Learn more
                    <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2">
                      <path d="M7 17L17 7M8 7h9v9"/>
                    </svg>
                  </a>
                <?php endif; ?>
              </article>
            <?php endforeach; ?>
          </div>
        </div>
      </div>
    <?php endif; ?>
  </div>
</section>