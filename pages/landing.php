<?php
/**
 * pages/landing.php
 * Standalone landing page for voting site visitors - bypasses normal template system
 * UPDATED: Added pre-registration modal
 */
declare(strict_types=1);

// Exit early to prevent normal header/footer loading
if (true) {
    // Helpers
    if (!function_exists('e')) {
        function e($value) { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); }
    }
    if (!function_exists('base_url')) {
        function base_url($path = '') {
            $protocol = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') || (getenv('FORCE_HTTPS') === 'true')
                ? 'https' : 'http';
            $host = $_SERVER['HTTP_HOST'] ?? (getenv('BASE_URL') ? parse_url(getenv('BASE_URL'), PHP_URL_HOST) : 'localhost');
            $base = $protocol . '://' . $host . '/';
            return $base . ltrim($path, '/');
        }
    }
    // Tiny bool parser for ENV
    $envb = function($v): bool {
        $v = is_string($v) ? strtolower(trim($v)) : $v;
        return in_array($v, [true, 1, '1', 'true', 'yes', 'on'], true);
    };

    // ── HANDLE PRE-REGISTRATION ────────────────────────────────────────────
    $registration_error = '';
    $registration_success = '';
    
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'preregister') {
        // Load necessary dependencies for registration
        if (file_exists(__DIR__ . '/../app/auth.php')) {
            require_once __DIR__ . '/../app/auth.php';
        }
        if (file_exists(__DIR__ . '/../init.php')) {
            require_once __DIR__ . '/../init.php';
        }
        
        // Start session if not already started
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        
        // CSRF check
        $csrf_valid = true;
        if (function_exists('csrf_check')) {
            $csrf_valid = csrf_check($_POST['csrf'] ?? '');
        }
        
        if (!$csrf_valid) {
            $registration_error = 'Invalid CSRF token.';
        } else {
            try {
                $username = trim((string)($_POST['username'] ?? ''));
                $email = trim((string)($_POST['email'] ?? ''));
                $password = (string)($_POST['password'] ?? '');
                
                // Server-side validation
                if ($username === '' || !preg_match('/^[A-Za-z0-9_]{3,32}$/', $username)) {
                    throw new RuntimeException('Username must be 3–32 chars (A–Z, 0–9, underscore).');
                }
                if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    throw new RuntimeException('Please enter a valid email address.');
                }
                if (strlen($password) < 6) {
                    throw new RuntimeException('Password must be at least 6 characters.');
                }
                
                // Use existing registration system with pre-register flag
                if (function_exists('register_site_and_auth') && isset($GLOBALS['config'])) {
                    register_site_and_auth($GLOBALS['config'], $username, $email, $password, true); // true = pre_register
                    $registration_success = 'Pre-registration successful! Join our Discord for future updates and teasers!';
                } else {
                    throw new RuntimeException('Registration system not available.');
                }
                
            } catch (Throwable $e) {
                $registration_error = $e->getMessage();
            }
        }
    }

    // ── Content-Security-Policy (allow Discord widget) ─────────────────────────
    if (!headers_sent()) {
        $cspParts = [
            "default-src 'self'",
            "img-src 'self' data: https:",
            "style-src 'self' 'unsafe-inline' https://fonts.googleapis.com",
            "font-src 'self' https://fonts.gstatic.com data:",
            "script-src 'self' 'unsafe-inline' 'unsafe-eval' https://cdn.tailwindcss.com",
            "connect-src 'self' https:",
            "frame-src 'self' https://discord.com https://*.discord.com https://*.discordapp.com",
            "frame-ancestors 'self'",
            "upgrade-insecure-requests"
        ];
        header('Content-Security-Policy: ' . implode('; ', $cspParts));
        header("Permissions-Policy: geolocation=(), microphone=(), camera=()");
        header("X-Content-Type-Options: nosniff");
        header("Referrer-Policy: strict-origin-when-cross-origin");
    }

    // Pull config
    $config = $GLOBALS['config'] ?? [];

    // Discord config — match how-to.php with .env fallbacks
    $dcArr = $config['discord'] ?? [];
    $discord_inv   = $dcArr['invite_url']   ?? (getenv('DISCORD_INVITE')    ?: '');
    $discord_gid   = $dcArr['guild_id']     ?? (getenv('DISCORD_GUILD_ID')  ?: '');
    $raw_theme     = $dcArr['widget_theme'] ?? (getenv('DISCORD_THEME')     ?: 'dark');
    $discord_theme = in_array($raw_theme, ['dark','light'], true) ? $raw_theme : 'dark';
    $discord_show_widget = !empty($dcArr)
        ? !empty($dcArr['show_widget'])
        : $envb(getenv('DISCORD_SHOW_WIDGET'));

    // ── Resolve logo source (pages/assets/logo.png) ──
    $logoSrc = '';
    $logoRelInPages = 'assets/logo.png';
    $logoFs = __DIR__ . '/' . $logoRelInPages;

    if (is_readable($logoFs)) {
        $logoData = @file_get_contents($logoFs);
        if ($logoData !== false) {
            $logoSrc = 'data:image/png;base64,' . base64_encode($logoData);
        }
    }
    if ($logoSrc === '') {
        if (function_exists('theme_asset_url')) {
            $logoSrc = theme_asset_url('pages/' . $logoRelInPages);
        } else {
            $logoSrc = base_url('assets/logo.png');
        }
    }

    // ── Feature data ───────────────────────────────────────────────────────────
    $features = [
      [
        'id'        => 'heart-of-azeroth',
        'title'     => 'Heart of Azeroth',
        'color'     => 'text-amber-400',
        'icon'      => '<svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 8l1.5-3L12 8l3 1.5L12 11l-1.5 3L9 11l-3-1.5L9 8zm8 6l.75-1.5L20 14l1.5.75L20 16l-.75 1.5L18 16l-1.5-.75L18 14z"/></svg>',
        'blurb'     => 'Custom Class Artifact—level it with Artifact Energy and tailor your build.',
        'clickable' => true,
        'content_html' => <<<'HTML'
          <!-- Heart of Azeroth modal content (as previously provided) -->
          <div class="mb-5 rounded-xl bg-gradient-to-r from-amber-500/15 via-yellow-400/10 to-rose-400/10 border border-amber-400/30 p-4">
            <div class="flex flex-wrap items-center gap-2">
              <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-semibold bg-amber-500/20 text-amber-200 border border-amber-400/30">
                Class Artifact
              </span>
              <span class="text-amber-200/90 text-sm">A custom twist on retail's Heart of Azeroth.</span>
            </div>
            <p class="text-neutral-200 mt-2">
              Complete a unique quest chain to receive an <span class="text-amber-300 font-medium">interactive inventory item</span> that opens a custom UI.
              Earn <span class="text-amber-300 font-medium">Artifact Energy</span> by killing creatures and world bosses to level your Artifact and unlock power.
            </p>
          </div>

          <div class="grid md:grid-cols-2 gap-4">
            <div class="rounded-lg border border-white/10 bg-white/5 p-4">
              <h4 class="text-white font-semibold mb-2">How it Works</h4>
              <ul class="bm-list text-neutral-300">
                <li>Use your <em>Class Artifact</em> item to open the Artifact UI and track your progress.</li>
                <li>Earn <span class="text-amber-200">Artifact Energy</span> from random creatures and world bosses across Azeroth.</li>
                <li>Leveling unlocks a <span class="text-emerald-200">scaling Artifact Buff</span> (auto-upgrades) that boosts Health, Damage, and more.</li>
                <li><span class="text-amber-200 font-medium">Weekend Bonus:</span> earn <strong>double Artifact XP</strong> every weekend.</li>
                <li>Certain <span class="text-amber-200">progressive items &amp; weapons</span> require specific Artifact levels to upgrade.</li>
                <li>Customize visuals with <span class="text-rose-200">Diablo-style effects</span> for <em>Azerite Fragments</em>.</li>
              </ul>
            </div>

            <div class="rounded-lg border border-white/10 bg-white/5 p-4">
              <h4 class="text-white font-semibold mb-2">Quest Chain</h4>
              <ul class="bm-list text-neutral-300">
                <li>Finish all <em>Tier Instance Quests</em> and reach <span class="text-amber-200">Shado-Pan Monastery</span>.</li>
                <li>Pick up <span class="text-amber-200 font-medium">[Examine the Monastery]</span> to begin.</li>
                <li>Complete the chain to obtain your <em>Class Artifact</em> item and unlock the UI.</li>
              </ul>
            </div>
          </div>

          <div class="mt-4 rounded-xl border border-emerald-400/30 bg-gradient-to-b from-emerald-500/10 to-transparent p-4">
            <div class="flex flex-wrap items-center gap-2">
              <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-semibold bg-emerald-500/20 text-emerald-200 border border-emerald-400/30">
                Sinergy Points
              </span>
              <span class="text-emerald-200/90 text-sm">Every 40 Artifact levels = 1 Sinergy Point.</span>
            </div>
            <p class="text-neutral-200 mt-2">
              Spend <span class="text-emerald-200">Sinergy</span> to upgrade your <em>Artifact Roles</em>. You may select and level up <strong>any 3 roles at a time</strong>.
            </p>
          </div>

          <h4 class="text-white font-semibold mt-6 mb-3">Artifact Roles</h4>
          <div class="grid lg:grid-cols-3 sm:grid-cols-2 gap-4">
            <div class="rounded-xl border border-rose-400/30 bg-gradient-to-b from-rose-500/10 to-transparent p-4">
              <span class="inline-flex px-2 py-0.5 rounded-md text-xs font-semibold bg-rose-500/20 text-rose-200 border border-rose-400/30 mb-1">Assassin</span>
              <ul class="bm-list text-neutral-200">
                <li>Increases Attack, Casting, and Movement speed.</li>
                <li>Boosts your Bloodmarking XP rate.</li>
              </ul>
            </div>
            <div class="rounded-xl border border-amber-400/30 bg-gradient-to-b from-amber-500/10 to-transparent p-4">
              <span class="inline-flex px-2 py-0.5 rounded-md text-xs font-semibold bg-amber-500/20 text-amber-200 border border-amber-400/30 mb-1">Fighter</span>
              <ul class="bm-list text-neutral-200">
                <li>Grants Attack Power and Stamina scaling with Role Rank.</li>
                <li>Boosts your Bloodmarking XP rate.</li>
              </ul>
            </div>
            <div class="rounded-xl border border-sky-400/30 bg-gradient-to-b from-sky-500/10 to-transparent p-4">
              <span class="inline-flex px-2 py-0.5 rounded-md text-xs font-semibold bg-sky-500/20 text-sky-200 border border-sky-400/30 mb-1">Arcanist</span>
              <ul class="bm-list text-neutral-200">
                <li>Grants Spell Power and Stamina scaling with Role Rank.</li>
                <li>Boosts your Bloodmarking XP rate.</li>
              </ul>
            </div>
            <div class="rounded-xl border border-emerald-400/30 bg-gradient-to-b from-emerald-500/10 to-transparent p-4">
              <span class="inline-flex px-2 py-0.5 rounded-md text-xs font-semibold bg-emerald-500/20 text-emerald-200 border border-emerald-400/30 mb-1">Support</span>
              <ul class="bm-list text-neutral-200">
                <li>Invisible shield that reduces damage taken.</li>
                <li>Boosts your Bloodmarking XP rate.</li>
              </ul>
            </div>
            <div class="rounded-xl border border-violet-400/30 bg-gradient-to-b from-violet-500/10 to-transparent p-4">
              <span class="inline-flex px-2 py-0.5 rounded-md text-xs font-semibold bg-violet-500/20 text-violet-200 border border-violet-400/30 mb-1">Vampire</span>
              <ul class="bm-list text-neutral-200">
                <li>Increases Damage done and Haste (scales with Role Rank).</li>
                <li>1% chance to drain Health from the enemy.</li>
                <li>Boosts your Bloodmarking XP rate.</li>
              </ul>
            </div>
          </div>

          <div class="mt-5 rounded-xl border border-white/10 bg-gradient-to-r from-amber-500/10 via-rose-500/10 to-emerald-500/10 p-4">
            <p class="text-neutral-200">
              <span class="font-semibold text-amber-200">TL;DR:</span> Level your Artifact by playing, unlock an auto-upgrading buff,
              earn Sinergy every 40 levels to empower three chosen roles, enjoy weekend double XP, and meet level requirements for upgrades.
            </p>
          </div>
        HTML,
      ],
      [
        'id'        => 'bloodmarking',
        'title'     => 'Bloodmarking',
        'color'     => 'text-rose-400',
        'icon'      => '<svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M12 3c3.5 5 6 7.9 6 11a6 6 0 11-12 0c0-3.1 2.5-6 6-11z"/></svg>',
        'blurb'     => 'Play anything, get stronger. Track progress with your Guide Book.',
        'clickable' => true,
        'content_html' => <<<'HTML'
          <!-- Bloodmarking modal content (as previously provided) -->
          <div class="mb-5 rounded-xl bg-gradient-to-r from-rose-500/15 via-rose-400/10 to-amber-400/10 border border-rose-400/30 p-4">
            <div class="flex items-center gap-2">
              <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-semibold bg-rose-500/20 text-rose-200 border border-rose-400/30">
                System Overview
              </span>
              <span class="text-rose-200/90 text-sm">Bloodmarking rewards you simply for playing.</span>
            </div>
            <p class="text-neutral-200 mt-2">
              Kill random creatures, world bosses, and more to earn <span class="text-rose-300 font-medium">Bloodmarking Experience (BMXP)</span>.
              Track progress in the <span class="font-medium text-amber-300">Bloodmarking Guide Book</span>.
            </p>
          </div>

          <div class="grid sm:grid-cols-2 gap-4">
            <div class="rounded-lg border border-white/10 bg-white/5 p-4">
              <h4 class="text-white font-semibold mb-2">How it Works</h4>
              <ul class="bm-list text-neutral-300">
                <li>Earn BMXP from open-world kills, elites, dungeons, world bosses, and events.</li>
                <li>Level up your <span class="text-rose-200">Bloodmarking</span> — higher levels mean better rewards.</li>
                <li>Some <span class="text-amber-200">progressive items &amp; weapons</span> require specific Bloodmarking levels.</li>
              </ul>
            </div>
            <div class="rounded-lg border border-white/10 bg-white/5 p-4">
              <h4 class="text-white font-semibold mb-2">Loot &amp; Currency</h4>
              <ul class="bm-list text-neutral-300">
                <li><span class="font-medium text-rose-200">Bloodmarking Pouches</span> / Loot Bags for bonus rewards and materials.</li>
                <li><span class="font-medium text-rose-200">Emblems</span> grant random BMXP; at level 1000 convert to Gold/Materials/Azerite.</li>
              </ul>
            </div>
          </div>

          <div class="grid md:grid-cols-3 gap-4 mt-4">
            <div class="rounded-xl border border-rose-400/30 bg-gradient-to-b from-rose-500/10 to-transparent p-4">
              <div class="text-sm text-rose-200/90 font-semibold mb-1">Milestones</div>
              <p class="text-neutral-200">Unlock cosmetics, currencies, and caches at key levels.</p>
            </div>
            <div class="rounded-xl border border-amber-400/30 bg-gradient-to-b from-amber-500/10 to-transparent p-4">
              <div class="text-sm text-amber-200/90 font-semibold mb-1">Progression Items</div>
              <p class="text-neutral-200">Higher Bloodmarking levels unlock upgrades for progressive items.</p>
            </div>
            <div class="rounded-xl border border-emerald-400/30 bg-gradient-to-b from-emerald-500/10 to-transparent p-4">
              <div class="text-sm text-emerald-200/90 font-semibold mb-1">Level 1000 Reward</div>
              <p class="text-neutral-200">Reach 1000 to receive a unique mount.</p>
            </div>
          </div>

          <div class="mt-4 grid md:grid-cols-2 gap-4">
            <div class="rounded-lg border border-white/10 bg-white/5 p-4">
              <h4 class="text-white font-semibold mb-2">Boosters</h4>
              <p class="text-neutral-300">
                Spend materials with <span class="text-rose-200 font-medium">Boosters</span> for BMXP bursts—small or large.
              </p>
            </div>
            <div class="rounded-lg border border-white/10 bg-white/5 p-4">
              <h4 class="text-white font-semibold mb-2">Tips</h4>
              <ul class="bm-list text-neutral-300">
                <li>Bank Emblems before big grinds.</li>
                <li>World bosses/events give chunkier BMXP.</li>
                <li>Guide Book shows next reward thresholds.</li>
              </ul>
            </div>
          </div>

          <div class="mt-5 rounded-xl border border-white/10 bg-gradient-to-r from-rose-500/10 via-amber-500/10 to-emerald-500/10 p-4">
            <p class="text-neutral-200">
              <span class="font-semibold text-rose-200">TL;DR:</span> Play anything, earn BMXP, unlock upgrades, and snag a unique mount at 1000.
            </p>
          </div>
        HTML,
      ],
      [
        'id'        => 'custom-content',
        'title'     => 'Custom Content',
        'color'     => 'text-emerald-400',
        'icon'      => '<svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9.5 4.5L4 6.5v13l5.5-2 5 2L20 17.5v-13l-5.5 2-5-2z"/></svg>',
        'blurb'     => 'Explore new zones, currencies, upgrades, visuals, and class cosmetics.',
        'clickable' => true,
        'content_html' => <<<'HTML'
          <!-- Custom Content modal content (as previously provided) -->
          <div class="mb-5 rounded-xl bg-gradient-to-r from-emerald-500/15 via-teal-400/10 to-sky-400/10 border border-emerald-400/30 p-4">
            <div class="flex items-center gap-2">
              <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-semibold bg-emerald-500/20 text-emerald-200 border border-emerald-400/30">
                Custom Content
              </span>
              <span class="text-emerald-200/90 text-sm">Progression, cosmetics, currencies, and systems unique to Thorium.</span>
            </div>
            <p class="text-neutral-200 mt-2">
              As you level Bloodmarking and your Heart of Azeroth, you'll unlock bespoke currencies, powerful upgrades, and visual customization—plus class-specific collections.
            </p>
          </div>

          <div class="grid md:grid-cols-2 gap-4">
            <div class="rounded-lg border border-rose-400/30 bg-gradient-to-b from-rose-500/10 to-transparent p-4">
              <div class="flex items-center gap-2 mb-1">
                <span class="inline-flex px-2 py-0.5 rounded-md text-xs font-semibold bg-rose-500/20 text-rose-200 border border-rose-400/30">Bloody Tokens</span>
              </div>
              <p class="text-neutral-200 mb-2">
                Earned alongside Bloodmarking. Spend on scaled food buffs, toys, vanity items, and <strong>custom mounts</strong>.
              </p>
              <ul class="bm-list text-neutral-300">
                <li>Vendor: <span class="text-rose-200">Bloodmarking Quartermaster</span></li>
                <li>Location: <span class="text-rose-200">The Bloodmarking Zone (Emerald Forest)</span></li>
              </ul>
            </div>

            <div class="rounded-lg border border-amber-400/30 bg-gradient-to-b from-amber-500/10 to-transparent p-4">
              <div class="flex items-center gap-2 mb-1">
                <span class="inline-flex px-2 py-0.5 rounded-md text-xs font-semibold bg-amber-500/20 text-amber-200 border border-amber-400/30">Azerite Fragments</span>
              </div>
              <p class="text-neutral-200 mb-2">
                Earned while progressing Bloodmarking and the Heart of Azeroth. Buy transmogs, mounts, reputation items, buffs, rewards, and more.
              </p>
              <p class="text-neutral-300">Also power Diablo-style visuals when enabled through your Artifact UI.</p>
            </div>
          </div>

          <div class="mt-4 rounded-xl border border-white/10 bg-white/5 p-4">
            <h4 class="text-white font-semibold mb-2">Armor &amp; Weapons Upgrading Hammer</h4>
            <p class="text-neutral-300 mb-3">
              Added to your inventory at character creation. Opens a <em>custom UI</em> showing upgradable items and required materials.
            </p>
            <div class="grid sm:grid-cols-2 gap-4">
              <div class="rounded-lg border border-white/10 p-4">
                <h5 class="text-white font-semibold mb-1">Materials</h5>
                <ul class="bm-list text-neutral-300">
                  <li>Custom Instances</li>
                  <li>World Bosses &amp; Rares</li>
                  <li>Daily Quests</li>
                </ul>
              </div>
              <div class="rounded-lg border border-white/10 p-4">
                <h5 class="text-white font-semibold mb-1">Alternative Upgrades</h5>
                <ul class="bm-list text-neutral-300">
                  <li>Use <span class="text-amber-200">Vote Tokens</span> or <span class="text-rose-200">Donation Tokens</span></li>
                  <li>Skips some requirements (e.g., Reputation or Bloodmarking Level)</li>
                </ul>
              </div>
            </div>
          </div>

          <div class="mt-4 rounded-xl border border-sky-400/30 bg-gradient-to-b from-sky-500/10 to-transparent p-4">
            <h4 class="text-white font-semibold mb-1">Visual Enchant NPC — Naarál, Mother of Light</h4>
            <p class="text-neutral-200">
              In the <em>Transmogrification Hub</em>, apply <strong>any weapon enchant visual</strong>, including unused/unreleased effects.
            </p>
            <ul class="bm-list text-neutral-300 mt-2">
              <li>Cost: <strong>500× Transmogrification Tokens</strong> per visual</li>
              <li>Changes are <em>permanent</em></li>
            </ul>
          </div>

          <div class="mt-4 rounded-xl border border-emerald-400/30 bg-gradient-to-b from-emerald-500/10 to-transparent p-4">
            <h4 class="text-white font-semibold mb-1">Druid Transformation / Appearance System</h4>
            <p class="text-neutral-200 mb-2">
              Druids get a unique <em>Idol</em> to collect <em>Runes</em> and unlock shapeshift appearances—including all <span class="text-emerald-200">Legion Artifact</span> looks for Cat &amp; Bear.
            </p>
            <div class="grid sm:grid-cols-2 gap-4">
              <div class="rounded-lg border border-white/10 p-4">
                <h5 class="text-white font-semibold mb-1">Forms Supported</h5>
                <ul class="bm-list text-neutral-300">
                  <li>Cat &amp; Bear (Legion Artifact)</li>
                  <li>Travel, Aquatic, Flight</li>
                  <li>Tree of Life &amp; Moonkin</li>
                </ul>
              </div>
              <div class="rounded-lg border border-white/10 p-4">
                <h5 class="text-white font-semibold mb-1">How to Unlock</h5>
                <ul class="bm-list text-neutral-300">
                  <li>Runes drop across Azeroth — vendors and rare drops</li>
                  <li>Get the Idol from Malfurion Stormrage's questline on <em>Timeless Isle</em></li>
                  <li>Requires level <strong>85</strong> to see the quest</li>
                </ul>
              </div>
            </div>
          </div>

          <div class="mt-4 rounded-xl border border-white/10 bg-white/5 p-4">
            <h4 class="text-white font-semibold mb-2">Quick Starts</h4>
            <ul class="bm-list text-neutral-300">
              <li><strong>Bloodmarking Quartermaster:</strong> The Bloodmarking Zone (Emerald Forest)</li>
              <li><strong>Transmog Hub:</strong> Find Naarál for Visual Enchants</li>
              <li><strong>Upgrading Hammer:</strong> Check your inventory on new characters</li>
            </ul>
          </div>

          <div class="mt-5 rounded-xl border border-white/10 bg-gradient-to-r from-emerald-500/10 via-rose-500/10 to-amber-500/10 p-4">
            <p class="text-neutral-200">
              <span class="font-semibold text-emerald-200">TL;DR:</span> Farm currencies, upgrade gear with a guided UI, apply epic visual enchants, and chase class cosmetics.
            </p>
          </div>
        HTML,
      ],
      [
        'id'        => 'and-more',
        'title'     => 'And Much More',
        'color'     => 'text-indigo-400',
        'icon'      => '<svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M12 6l1.2 2.6L16 9.9l-2.4 1.6L12 14l-1.6-2.5L8 9.9l2.8-1.3L12 6zm7 8l.8 1.7 1.7.8-1.7.8L19 19l-.8-1.7L16.5 17l1.7-.8L19 14zM4 14l.8 1.7L6.5 17l-1.7.8L4 19l-.8-1.7L1.5 17l1.7-.8L4 14z"/></svg>',
        'blurb'     => 'Events, dungeons, cosmetics, QoL upgrades—and secrets.',
        'clickable' => false,
        'content_html' => '',
      ],
    ];
    
    // Generate CSRF token for forms
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    $csrf_token = '';
    if (function_exists('csrf_token')) {
        $csrf_token = csrf_token();
    } else {
        // Fallback CSRF token generation
        if (empty($_SESSION['csrf'])) {
            $_SESSION['csrf'] = bin2hex(random_bytes(16));
        }
        $csrf_token = $_SESSION['csrf'];
    }
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
<!-- Icons -->
  <link rel="icon" type="image/svg+xml" href="<?= e(base_url('/favicon.svg')) ?>?v=5">
  <meta name="theme-color" content="#0f172a">
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Thorium: Reforged — Under Development | Wrath of the Lich King Private Server</title>
  <meta name="description" content="Thorium: Reforged is under development."/>
  <script src="https://cdn.tailwindcss.com"></script>
  <style>
    :root{
      /* Scrollbar theme colors */
      --sb-track: rgba(255,255,255,.06);
      --sb-track-border: rgba(255,255,255,.08);
      --sb-thumb: linear-gradient(180deg,#10b981,#3b82f6); /* emerald → sky */
      --sb-thumb-hover: linear-gradient(180deg,#34d399,#60a5fa);
      --sb-thumb-active: linear-gradient(180deg,#059669,#2563eb);
    }

    html{scroll-behavior:smooth}
    body{
      background:linear-gradient(135deg,#111827 0%,#1f2937 50%,#064e3b 100%);
      font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;
      margin:0;overflow-x:hidden;
      /* Firefox scrollbar */
      scrollbar-width: thin;
      scrollbar-color: #10b981 #1f2937;
      /* Prevent layout shift when scrollbars appear */
      scrollbar-gutter: stable both-edges;
    }
    .bg-clip-text{background-clip:text;-webkit-background-clip:text;-webkit-text-fill-color:transparent;}
    @keyframes pulse{0%,100%{opacity:.4;transform:scale(1)}50%{opacity:1;transform:scale(1.2)}}
    .animate-pulse-custom{animation:pulse 2s ease-in-out infinite}
    .delay-1000{animation-delay:1s}.delay-2000{animation-delay:2s}.delay-3000{animation-delay:3s}

    /* Clean, aligned bullets */
    .bm-list{list-style:none;margin:0;padding:0}
    .bm-list li{position:relative;padding-left:1.75rem;line-height:1.6}
    .bm-list li+li{margin-top:.5rem}
    .bm-list li::before{
      content:'';
      position:absolute;left:.25rem;top:.65em;
      width:.5rem;height:.5rem;border-radius:9999px;
      background:linear-gradient(180deg,rgba(16,185,129,.9),rgba(59,130,246,.9)); /* emerald→sky */
      box-shadow:0 0 0 2px rgba(16,185,129,.25);
    }

    /* Uniform feature cards */
    .feature-card{min-height:12rem; height:100%; display:flex; flex-direction:column; justify-content:space-between;}

    /* ── BEAUTIFIED SCROLLBARS (WebKit/Chromium) ─────────────────────────── */
    /* Global page scrollbars */
    body::-webkit-scrollbar{width:12px;height:12px}
    body::-webkit-scrollbar-track{
      background:var(--sb-track);
      border-radius:12px;
      box-shadow:inset 0 0 0 1px var(--sb-track-border);
    }
    body::-webkit-scrollbar-thumb{
      background:var(--sb-thumb);
      border-radius:10px;
      box-shadow:inset 0 0 0 2px rgba(17,24,39,.9); /* border illusion on dark bg */
    }
    body::-webkit-scrollbar-thumb:hover{background:var(--sb-thumb-hover)}
    body::-webkit-scrollbar-thumb:active{background:var(--sb-thumb-active)}
    body::-webkit-scrollbar-corner{background:transparent}

    /* Modal scroll container (dialog) */
    .modal-panel{
      /* Firefox fallback */
      scrollbar-width: thin;
      scrollbar-color: #22c55e rgba(255,255,255,.06);
      /* UX */
      overscroll-behavior: contain;
      scrollbar-gutter: stable both-edges;
    }
    .modal-panel::-webkit-scrollbar{width:10px;height:10px}
    .modal-panel::-webkit-scrollbar-track{
      background:var(--sb-track);
      border-radius:12px;
      box-shadow:inset 0 0 0 1px var(--sb-track-border);
    }
    .modal-panel::-webkit-scrollbar-thumb{
      background:var(--sb-thumb);
      border-radius:10px;
      box-shadow:inset 0 0 0 2px rgba(24,24,27,.85);
    }
    .modal-panel::-webkit-scrollbar-thumb:hover{background:var(--sb-thumb-hover)}
    .modal-panel::-webkit-scrollbar-thumb:active{background:var(--sb-thumb-active)}
    .modal-panel::-webkit-scrollbar-corner{background:transparent}

    /* Slightly thinner on small screens */
    @media (max-width:640px){
      body::-webkit-scrollbar{width:10px;height:10px}
      .modal-panel::-webkit-scrollbar{width:8px;height:8px}
    }
  </style>
</head>
<body>

<!-- Development Landing Page -->
<section class="relative min-h-screen flex items-center justify-center overflow-hidden">
  <!-- BG -->
  <div class="absolute inset-0 bg-gradient-to-br from-gray-900 via-gray-800 to-emerald-900"></div>

  <!-- Particles -->
  <div class="absolute inset-0 opacity-20 pointer-events-none">
    <div class="absolute top-20 left-10 w-2 h-2 bg-emerald-400 rounded-full animate-pulse-custom"></div>
    <div class="absolute top-40 right-20 w-1 h-1 bg-amber-400 rounded-full animate-pulse-custom delay-1000"></div>
    <div class="absolute bottom-32 left-1/4 w-1.5 h-1.5 bg-emerald-300 rounded-full animate-pulse-custom delay-2000"></div>
    <div class="absolute top-1/2 right-1/3 w-1 h-1 bg-purple-400 rounded-full animate-pulse-custom delay-3000"></div>
  </div>

  <div class="relative z-10 text-center px-6 max-w-6xl mx-auto">

<!-- Title as logo -->
<div class="mb-8 -mt-12">
  <h1 class="mb-4 leading-none">
    <span class="sr-only">Thorium: Reforged</span>
    <img
      src="<?= e($logoSrc) ?>"
      alt="Thorium: Reforged"
      width="286" height="234"
      class="mx-auto block w-[286px] h-[234px] select-none pointer-events-none"
      decoding="async"
      fetchpriority="high"
      loading="eager"
    />
  </h1>

  <!-- Status badge -->
  <div class="mt-6 inline-flex items-center px-5 py-2 rounded-full bg-amber-500/20 border border-amber-500/40 backdrop-blur-sm">
    <div class="w-2 h-2 bg-amber-400 rounded-full animate-pulse mr-3"></div>
    <span class="text-amber-300 font-medium">Under Development</span>
  </div>
</div>

    <!-- Intro copy -->
    <div class="mb-8">
      <p class="text-xl md:text-2xl text-neutral-300 max-w-3xl mx-auto leading-relaxed mb-6">
        We're crafting an incredible World of Warcraft experience with unique features and innovative systems on patch <strong>3.3.5a</strong>.
      </p>
      <p class="text-lg text-emerald-300 font-medium">Join our community for exclusive updates.</p>
    </div>

    <!-- Pre-Registration CTA -->
    <div class="mb-12 max-w-2xl mx-auto">
      <div class="rounded-xl border border-emerald-400/30 bg-gradient-to-r from-emerald-500/10 via-emerald-400/5 to-teal-500/10 backdrop-blur-sm p-6">
        <h2 class="text-2xl font-bold text-emerald-300 mb-3">Reserve Your Adventure</h2>
        <p class="text-neutral-300 mb-4">
          Be among the first to experience Thorium: Reforged. Create your account now and be ready when we launch.
        </p>
        <button
          type="button"
          class="inline-flex items-center justify-center bg-emerald-600 hover:bg-emerald-700 text-white px-8 py-3 rounded-lg font-semibold transition-all transform hover:scale-105 focus:outline-none focus:ring-2 focus:ring-emerald-400/60"
          id="preRegisterBtn"
        >
          <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 mr-2" fill="none" viewBox="0 0 24 24" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z" />
          </svg>
          Pre-Register Now
        </button>
      </div>
    </div>

    <!-- Features -->
    <section aria-labelledby="features-heading" class="mb-12">
      <div class="mb-6">
        <h2 id="features-heading" class="text-2xl font-bold text-white text-center">Features</h2>
      </div>

      <ul role="list" class="grid grid-cols-1 gap-5 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 items-stretch text-left">
        <?php foreach ($features as $f): ?>
          <li class="relative h-full">
            <?php if (!empty($f['clickable'])): ?>
              <button
                type="button"
                class="feature-card group w-full h-full text-left rounded-xl border border-white/10 bg-black/30 p-6 backdrop-blur-sm transition hover:border-white/20 focus:outline-none focus:ring-2 focus:ring-emerald-400/60 feature-trigger"
                data-feature-id="<?= e($f['id']) ?>"
                aria-haspopup="dialog"
                aria-controls="featureModal"
              >
                <div class="flex items-start gap-3">
                  <div class="mt-0.5 rounded-lg bg-white/5 p-2 ring-1 ring-white/10 group-hover:ring-white/20 <?= e($f['color']) ?>">
                    <?= $f['icon'] /* raw svg */ ?>
                  </div>
                  <div class="flex-1">
                    <h3 class="font-semibold text-white"><?= e($f['title']) ?></h3>
                    <p class="text-sm text-neutral-300"><?= e($f['blurb']) ?></p>
                  </div>
                </div>
                <span class="mt-3 inline-flex items-center text-emerald-300 text-sm">
                  Learn more
                  <svg xmlns="http://www.w3.org/2000/svg" class="ml-1 h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 5l7 7-7 7"/>
                  </svg>
                </span>
              </button>
            <?php else: ?>
              <div
                class="feature-card w-full h-full rounded-xl border border-white/10 bg-black/30 p-6 backdrop-blur-sm"
                aria-disabled="true"
              >
                <div class="flex items-start gap-3">
                  <div class="mt-0.5 rounded-lg bg-white/5 p-2 ring-1 ring-white/10 <?= e($f['color']) ?>">
                    <?= $f['icon'] ?>
                  </div>
                  <div class="flex-1">
                    <h3 class="font-semibold text-white"><?= e($f['title']) ?></h3>
                    <p class="text-sm text-neutral-300"><?= e($f['blurb']) ?></p>
                  </div>
                </div>
              </div>
            <?php endif; ?>
          </li>
        <?php endforeach; ?>
      </ul>
    </section>

    <!-- Community / Discord -->
    <div class="mb-12 max-w-3xl mx-auto">
      <div class="mb-3">
        <h3 class="text-xl font-bold text-white text-center">Community</h3>
      </div>

      <?php if ($discord_show_widget && $discord_gid): ?>
        <div class="discord-widget overflow-hidden rounded-xl border border-white/10 bg-black/30 backdrop-blur-sm p-4">
          <iframe
            src="https://discord.com/widget?id=<?= e($discord_gid) ?>&theme=<?= e($discord_theme) ?>"
            width="100%" height="380" class="block w-full rounded-lg"
            allowtransparency="true" frameborder="0" style="border:0;outline:0;box-shadow:none"
            sandbox="allow-popups allow-popups-to-escape-sandbox allow-same-origin allow-scripts"
            title="Discord Widget">
          </iframe>
        </div>
      <?php else: ?>
        <div class="rounded-xl border border-white/10 bg-black/30 backdrop-blur-sm p-6 text-center">
          <p class="text-neutral-300 mb-4">Join our Discord to get help and meet the community.</p>
          <a href="<?= e($discord_inv ?: base_url('discord')) ?>" class="inline-flex items-center justify-center bg-indigo-600 hover:bg-indigo-700 text-white px-6 py-3 rounded-lg font-semibold transition">
            Join Discord
          </a>
        </div>
      <?php endif; ?>
    </div>
  </div>
</section>

<!-- Hidden templates for modal content (skip non-clickable) -->
<div id="feature-templates" class="hidden">
  <?php foreach ($features as $f): if (empty($f['clickable'])) continue; ?>
    <template id="tpl-<?= e($f['id']) ?>">
      <article>
        <header class="flex items-start gap-3 mb-4">
          <div class="rounded-lg bg-white/5 p-2 ring-1 ring-white/10 <?= e($f['color']) ?>">
            <?= $f['icon'] ?>
          </div>
          <div>
            <h3 class="text-xl font-bold text-white" id="featureModalTitle"><?= e($f['title']) ?></h3>
            <p class="text-neutral-400 text-sm"><?= e($f['blurb']) ?></p>
          </div>
        </header>
        <div class="text-base leading-relaxed">
          <?= $f['content_html'] ?>
        </div>
      </article>
    </template>
  <?php endforeach; ?>
</div>

<!-- Feature modal (scrollable dialog) -->
<div id="featureModal" class="fixed inset-0 z-50 hidden" aria-hidden="true">
  <!-- Backdrop -->
  <div class="absolute inset-0 bg-black/70 backdrop-blur-sm" data-dismiss="modal" aria-hidden="true"></div>

  <!-- Dialog: added .modal-panel to target scrollbar styles -->
  <div
    role="dialog"
    aria-modal="true"
    aria-labelledby="featureModalTitle"
    class="modal-panel relative mx-auto my-8 w-[min(90vw,820px)] max-h-[85vh] overflow-y-auto rounded-2xl border border-white/10 bg-neutral-900 shadow-xl"
  >
    <button
      type="button"
      class="sticky top-3 float-right mr-3 inline-flex items-center justify-center rounded-lg border border-white/10 bg-white/10 px-2.5 py-2 text-white/80 hover:text-white hover:bg-white/15 focus:outline-none focus:ring-2 focus:ring-emerald-400/60"
      data-dismiss="modal"
      aria-label="Close"
    >
      <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M6 6l12 12M18 6L6 18"/>
      </svg>
    </button>

    <div class="p-6 clear-both">
      <div id="featureContent"></div>
    </div>
  </div>
</div>

<!-- Pre-Registration Modal -->
<div id="preRegisterModal" class="fixed inset-0 z-50 hidden" aria-hidden="true">
  <!-- Backdrop -->
  <div class="absolute inset-0 bg-black/70 backdrop-blur-sm" data-dismiss="prereg-modal" aria-hidden="true"></div>

  <!-- Dialog -->
  <div
    role="dialog"
    aria-modal="true"
    aria-labelledby="preRegisterModalTitle"
    class="modal-panel relative mx-auto my-8 w-[min(90vw,480px)] max-h-[85vh] overflow-y-auto rounded-2xl border border-white/10 bg-neutral-900 shadow-xl"
  >
    <button
      type="button"
      class="sticky top-3 float-right mr-3 inline-flex items-center justify-center rounded-lg border border-white/10 bg-white/10 px-2.5 py-2 text-white/80 hover:text-white hover:bg-white/15 focus:outline-none focus:ring-2 focus:ring-emerald-400/60"
      data-dismiss="prereg-modal"
      aria-label="Close"
    >
      <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M6 6l12 12M18 6L6 18"/>
      </svg>
    </button>

    <div class="p-6 clear-both">
      <div class="text-center mb-6">
        <div class="flex items-center justify-center w-16 h-16 mx-auto mb-4 rounded-full bg-emerald-500/20 border border-emerald-400/30">
          <svg xmlns="http://www.w3.org/2000/svg" class="h-8 w-8 text-emerald-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z" />
          </svg>
        </div>
        <h3 class="text-2xl font-bold text-white" id="preRegisterModalTitle">Create Your Account</h3>
        <p class="text-neutral-400 mt-2">Reserve your spot in Thorium: Reforged</p>
      </div>

      <?php if ($registration_success): ?>
        <div class="rounded-xl border border-emerald-400/30 bg-emerald-500/10 p-4 text-center">
          <div class="flex items-center justify-center w-12 h-12 mx-auto mb-3 rounded-full bg-emerald-500/20">
            <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6 text-emerald-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
            </svg>
          </div>
          <h4 class="text-lg font-semibold text-emerald-300 mb-2">Welcome to Thorium!</h4>
          <p class="text-emerald-200"><?= e($registration_success) ?></p>
          <p class="text-neutral-400 text-sm mt-2">You'll be notified when the server launches.</p>
        </div>
      <?php else: ?>
        <?php if ($registration_error): ?>
          <div class="mb-4 rounded-xl border border-red-400/30 bg-red-500/10 p-4">
            <p class="text-red-300"><?= e($registration_error) ?></p>
          </div>
        <?php endif; ?>

        <form method="post" class="space-y-4" novalidate>
          <input type="hidden" name="action" value="preregister">
          <input type="hidden" name="csrf" value="<?= e($csrf_token) ?>">

          <div>
            <label class="block text-sm font-medium text-neutral-300 mb-2">Username</label>
            <input
              name="username"
              type="text"
              required
              pattern="[A-Za-z0-9_]{3,32}"
              placeholder="YourName"
              class="w-full rounded-xl bg-black/30 border border-white/10 px-4 py-3 text-white placeholder-neutral-500 focus:outline-none focus:ring-2 focus:ring-emerald-500 focus:border-emerald-500"
              value="<?= e($_POST['username'] ?? '') ?>"
            />
            <p class="mt-1 text-xs text-neutral-500">3–32 characters • letters, numbers, underscore</p>
          </div>

          <div>
            <label class="block text-sm font-medium text-neutral-300 mb-2">Email</label>
            <input
              name="email"
              type="email"
              required
              placeholder="you@email.com"
              class="w-full rounded-xl bg-black/30 border border-white/10 px-4 py-3 text-white placeholder-neutral-500 focus:outline-none focus:ring-2 focus:ring-emerald-500 focus:border-emerald-500"
              value="<?= e($_POST['email'] ?? '') ?>"
            />
          </div>

          <div>
            <label class="block text-sm font-medium text-neutral-300 mb-2">Password</label>
            <div class="relative">
              <input
                id="preregPassword"
                name="password"
                type="password"
                minlength="6"
                required
                placeholder="••••••••"
                class="w-full rounded-xl bg-black/30 border border-white/10 px-4 py-3 pr-12 text-white placeholder-neutral-500 focus:outline-none focus:ring-2 focus:ring-emerald-500 focus:border-emerald-500"
              />
              <button
                type="button"
                id="preregPwToggle"
                class="absolute right-3 top-1/2 -translate-y-1/2 text-neutral-400 hover:text-neutral-200 transition"
                title="Show/Hide password"
                aria-label="Show or hide password"
              >
                <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" />
                </svg>
              </button>
            </div>
            <p class="mt-1 text-xs text-neutral-500">At least 6 characters</p>
          </div>

          <div class="pt-2">
            <button
              type="submit"
              class="w-full bg-emerald-600 hover:bg-emerald-700 text-white font-semibold py-3 px-4 rounded-xl transition-all transform hover:scale-105 focus:outline-none focus:ring-2 focus:ring-emerald-400/60"
            >
              Create Account
            </button>
          </div>

          <div class="text-center pt-2">
            <p class="text-sm text-neutral-400">
              By creating an account, you'll be ready to play when we launch.
            </p>
          </div>
        </form>
      <?php endif; ?>
    </div>
  </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function(){
  // Subtle parallax for background particles
  document.addEventListener('mousemove', function(e){
    const particles = document.querySelectorAll('.animate-pulse-custom');
    const x = e.clientX / window.innerWidth;
    const y = e.clientY / window.innerHeight;
    particles.forEach((p, i) => {
      const speed = (i + 1) * 0.5;
      p.style.transform = `translate(${(x - 0.5) * speed}px, ${(y - 0.5) * speed}px)`;
    });
  });

  // Auto-open pre-registration modal if there's a registration error or success
  <?php if ($registration_error || $registration_success): ?>
    openPreRegModal();
  <?php endif; ?>
});

// Pre-registration modal functionality
function openPreRegModal() {
  const modal = document.getElementById('preRegisterModal');
  modal.classList.remove('hidden');
  modal.setAttribute('aria-hidden', 'false');
  document.body.style.overflow = 'hidden';
  
  const focusable = modal.querySelector('input, button');
  if (focusable) focusable.focus({ preventScroll: true });
}

function closePreRegModal() {
  const modal = document.getElementById('preRegisterModal');
  modal.classList.add('hidden');
  modal.setAttribute('aria-hidden', 'true');
  document.body.style.overflow = '';
}

// Pre-registration modal event handlers
document.getElementById('preRegisterBtn')?.addEventListener('click', openPreRegModal);

document.getElementById('preRegisterModal')?.addEventListener('click', (e) => {
  if (e.target.closest && e.target.closest('[data-dismiss="prereg-modal"]')) {
    closePreRegModal();
  }
});

document.addEventListener('keydown', (e) => {
  if (e.key === 'Escape') {
    const preRegModal = document.getElementById('preRegisterModal');
    if (preRegModal && !preRegModal.classList.contains('hidden')) {
      closePreRegModal();
    }
  }
});

// Password toggle for pre-registration
document.getElementById('preregPwToggle')?.addEventListener('click', () => {
  const input = document.getElementById('preregPassword');
  if (!input) return;
  input.type = input.type === 'password' ? 'text' : 'password';
});
</script>

<!-- Feature Modal wiring -->
<script>
(function () {
  const modal = document.getElementById('featureModal');
  const content = document.getElementById('featureContent');
  const triggers = document.querySelectorAll('.feature-trigger');
  let lastFocus = null;

  const isOpen = () => !modal.classList.contains('hidden');

  function openFeature(id, pushHash = true) {
    const tpl = document.getElementById('tpl-' + id);
    if (!tpl) return;
    lastFocus = document.activeElement;

    content.innerHTML = '';
    content.appendChild(tpl.content.cloneNode(true));

    modal.classList.remove('hidden');
    modal.setAttribute('aria-hidden', 'false');
    document.body.style.overflow = 'hidden';

    const focusable = modal.querySelector('button,[href],input,select,textarea,[tabindex]:not([tabindex="-1"])');
    (focusable || modal).focus({ preventScroll: true });

    if (pushHash) history.pushState(null, '', '#' + encodeURIComponent(id));
  }

  function closeFeature(popHash = true) {
    if (!isOpen()) return;
    modal.classList.add('hidden');
    modal.setAttribute('aria-hidden', 'true');
    document.body.style.overflow = '';
    if (lastFocus) lastFocus.focus({ preventScroll: true });
    if (popHash && location.hash) {
      history.pushState('', document.title, window.location.pathname + window.location.search);
    }
  }

  // Open handlers
  triggers.forEach(btn => {
    btn.addEventListener('click', () => openFeature(btn.dataset.featureId));
  });

  // Close on backdrop or close button (works even if clicking SVG/path)
  modal.addEventListener('click', (e) => {
    if (e.target.closest && e.target.closest('[data-dismiss="modal"]')) closeFeature();
  });

  // Esc to close
  document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape' && isOpen()) closeFeature();
  });

  // Deep-link on load + back/forward
  window.addEventListener('load', () => {
    const id = decodeURIComponent(location.hash.slice(1));
    if (id) openFeature(id, false);
  });
  window.addEventListener('hashchange', () => {
    const id = decodeURIComponent(location.hash.slice(1));
    if (id) openFeature(id, false);
    else if (isOpen()) closeFeature(false);
  });
})();
</script>

</body>
</html>
<?php
    exit; // Prevent normal template system from loading
}