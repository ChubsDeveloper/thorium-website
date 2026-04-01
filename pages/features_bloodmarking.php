<?php
/**
 * pages/features_bloodmarking.php
 * Detailed Bloodmarking system page - Updated with comprehensive Discord info
 */
declare(strict_types=1);

if (!function_exists('e')) {
    function e($value) { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); }
}

$milestones = [
    ['level' => 100, 'reward' => 'First cosmetic unlock'],
    ['level' => 250, 'reward' => 'Bloodmarking Cache'],
    ['level' => 500, 'reward' => 'Special currency unlock'],
    ['level' => 750, 'reward' => 'Enhanced loot bags'],
    ['level' => 1000, 'reward' => 'Title [The Bloodseeker] + World Quests unlock']
];

$worldRares = [
    'Illidan Stormrage',
    'Deathbinder Hroth', 
    'Lord and Lady Waycrest',
    'Dormus the Camel-Hoarder',
    'Amalgam of Souls',
    'Sharg and F\'harg'
];
?>

<section class="container mx-auto px-4 py-12">
    <!-- Breadcrumb -->
    <nav class="mb-8">
        <ol class="flex items-center space-x-2 text-sm text-neutral-400">
            <li><a href="<?= e(base_url('features')) ?>" class="hover:text-brand-400 transition-colors">Features</a></li>
            <li><svg class="w-4 h-4" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M7.21 14.77a.75.75 0 01.02-1.06L11.168 10 7.23 6.29a.75.75 0 111.04-1.08l4.5 4.25a.75.75 0 010 1.08l-4.5 4.25a.75.75 0 01-1.06-.02z" clip-rule="evenodd"></path></svg></li>
            <li class="text-rose-400">Bloodmarking</li>
        </ol>
    </nav>

    <!-- Header -->
    <div class="text-center mb-12">
        <div class="inline-flex items-center justify-center w-16 h-16 rounded-2xl bg-gradient-to-br from-rose-500/20 to-red-400/10 border border-rose-400/30 mb-6">
            <svg xmlns="http://www.w3.org/2000/svg" class="h-8 w-8 text-rose-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M12 3c3.5 5 6 7.9 6 11a6 6 0 11-12 0c0-3.1 2.5-6 6-11z"/>
            </svg>
        </div>
        <h1 class="text-4xl md:text-5xl font-bold text-white mb-4">
            <span class="text-rose-400">Bloodmarking</span> System
        </h1>
        <p class="text-xl text-neutral-300 max-w-3xl mx-auto">
            A progression system that rewards you simply for playing. Kill creatures, gain experience, unlock rewards.
        </p>
    </div>

    <!-- Overview Card -->
    <div class="mb-12 rounded-2xl bg-gradient-to-r from-rose-500/15 via-rose-400/10 to-amber-400/10 border border-rose-400/30 p-8">
        <div class="flex items-center gap-3 mb-4">
            <span class="inline-flex items-center px-3 py-1 rounded-full text-sm font-semibold bg-rose-500/20 text-rose-200 border border-rose-400/30">
                System Overview
            </span>
            <span class="text-rose-200/90">Bloodmarking rewards you simply for playing.</span>
        </div>
        <p class="text-neutral-200 text-lg leading-relaxed">
            Kill random creatures, world bosses, and more to earn <span class="text-rose-300 font-medium">Bloodmarking Experience (BMXP)</span>.
            Track progress in your <span class="font-medium text-amber-300">[Bloodmarking Guide Book]</span> inventory item.
        </p>
    </div>

    <!-- Getting Started -->
    <div class="mb-12 rounded-2xl border border-emerald-400/30 bg-gradient-to-b from-emerald-500/10 to-transparent p-8">
        <h2 class="text-3xl font-bold text-white mb-6">Getting Started</h2>
        <div class="grid md:grid-cols-2 gap-8">
            <div>
                <h3 class="text-xl font-semibold text-emerald-300 mb-4">Access the System</h3>
                <ul class="space-y-3">
                    <li class="flex items-start gap-3">
                        <div class="w-2 h-2 rounded-full bg-gradient-to-r from-emerald-400 to-teal-300 mt-2.5 flex-shrink-0"></div>
                        <span class="text-neutral-300">Complete the introduction questline</span>
                    </li>
                    <li class="flex items-start gap-3">
                        <div class="w-2 h-2 rounded-full bg-gradient-to-r from-emerald-400 to-teal-300 mt-2.5 flex-shrink-0"></div>
                        <span class="text-neutral-300">Visit the portal in the <span class="text-emerald-200">Global Mall</span></span>
                    </li>
                    <li class="flex items-start gap-3">
                        <div class="w-2 h-2 rounded-full bg-gradient-to-r from-emerald-400 to-teal-300 mt-2.5 flex-shrink-0"></div>
                        <span class="text-neutral-300">Speak to <span class="text-emerald-200">Broll Bearmantle</span> to begin</span>
                    </li>
                </ul>
            </div>
            <div>
                <h3 class="text-xl font-semibold text-emerald-300 mb-4">Core Mechanics</h3>
                <ul class="space-y-3">
                    <li class="flex items-start gap-3">
                        <div class="w-2 h-2 rounded-full bg-gradient-to-r from-emerald-400 to-teal-300 mt-2.5 flex-shrink-0"></div>
                        <span class="text-neutral-300">Requires <span class="text-amber-200">2000 XP per level</span></span>
                    </li>
                    <li class="flex items-start gap-3">
                        <div class="w-2 h-2 rounded-full bg-gradient-to-r from-emerald-400 to-teal-300 mt-2.5 flex-shrink-0"></div>
                        <span class="text-neutral-300">Track progress with your <span class="text-emerald-200">[Bloodmarking Guide Book]</span></span>
                    </li>
                    <li class="flex items-start gap-3">
                        <div class="w-2 h-2 rounded-full bg-gradient-to-r from-emerald-400 to-teal-300 mt-2.5 flex-shrink-0"></div>
                        <span class="text-neutral-300">Use <span class="text-emerald-200">.buff</span> command to see unlocked buffs</span>
                    </li>
                </ul>
            </div>
        </div>
    </div>

    <!-- How It Works & Rewards -->
    <div class="grid lg:grid-cols-2 gap-8 mb-12">
        <div class="rounded-2xl border border-white/10 bg-white/5 p-8">
            <h2 class="text-2xl font-bold text-white mb-6">How It Works</h2>
            <ul class="space-y-4">
                <li class="flex items-start gap-3">
                    <div class="w-2 h-2 rounded-full bg-gradient-to-r from-rose-400 to-red-300 mt-2.5 flex-shrink-0"></div>
                    <div>
                        <p class="text-neutral-300">Earn BMXP from open-world kills, elites, dungeons, world bosses, and events.</p>
                    </div>
                </li>
                <li class="flex items-start gap-3">
                    <div class="w-2 h-2 rounded-full bg-gradient-to-r from-rose-400 to-red-300 mt-2.5 flex-shrink-0"></div>
                    <div>
                        <p class="text-neutral-300">Unlock <span class="text-rose-200">character buffs</span> that increase Health, Haste, Damage, Attack Power, and Spell Power.</p>
                    </div>
                </li>
                <li class="flex items-start gap-3">
                    <div class="w-2 h-2 rounded-full bg-gradient-to-r from-rose-400 to-red-300 mt-2.5 flex-shrink-0"></div>
                    <div>
                        <p class="text-neutral-300">Some <span class="text-amber-200">progressive items & weapons</span> require specific Bloodmarking levels.</p>
                    </div>
                </li>
                <li class="flex items-start gap-3">
                    <div class="w-2 h-2 rounded-full bg-gradient-to-r from-rose-400 to-red-300 mt-2.5 flex-shrink-0"></div>
                    <div>
                        <p class="text-neutral-300">Progression continues beyond level 1000 for additional rewards.</p>
                    </div>
                </li>
            </ul>
        </div>

        <div class="rounded-2xl border border-white/10 bg-white/5 p-8">
            <h2 class="text-2xl font-bold text-white mb-6">Loot & Currency</h2>
            <div class="space-y-4">
                <div class="p-4 rounded-xl bg-gradient-to-r from-rose-500/10 to-red-400/5 border border-rose-400/20">
                    <h3 class="text-lg font-semibold text-rose-300 mb-2">Bloodmarking Pouches</h3>
                    <p class="text-neutral-300">Loot caches containing bonus rewards and materials drop during progression.</p>
                </div>
                <div class="p-4 rounded-xl bg-gradient-to-r from-amber-500/10 to-yellow-400/5 border border-amber-400/20">
                    <h3 class="text-lg font-semibold text-amber-300 mb-2">XP Emblems</h3>
                    <p class="text-neutral-300">Tokens that grant random BMXP when used. Convert to Gold, Materials, and Azerite at level 1000.</p>
                </div>
                <div class="p-4 rounded-xl bg-gradient-to-r from-emerald-500/10 to-teal-400/5 border border-emerald-400/20">
                    <h3 class="text-lg font-semibold text-emerald-300 mb-2">Boosters</h3>
                    <p class="text-neutral-300">Spend materials or donation tokens for BMXP bursts—small or large boosts available.</p>
                </div>
                <div class="p-4 rounded-xl bg-gradient-to-r from-purple-500/10 to-violet-400/5 border border-purple-400/20">
                    <h3 class="text-lg font-semibold text-purple-300 mb-2">Bloody Tokens</h3>
                    <p class="text-neutral-300">Currency earned in the Bloodmarking Zone. Exchange at vendors for custom goods and rewards.</p>
                </div>
            </div>
        </div>
    </div>

    <!-- Emerald Forest / Bloodmarking Zone -->
    <div class="mb-12 rounded-2xl border border-emerald-400/30 bg-gradient-to-r from-emerald-500/10 via-teal-400/10 to-green-500/10 p-8">
        <h2 class="text-3xl font-bold text-white mb-6">Emerald Forest (Bloodmarking Zone)</h2>
        <p class="text-neutral-200 text-lg leading-relaxed mb-6">
            A dedicated zone with questlines, repeatable dailies, and unique challenges. All creatures scale their health and damage based on your Bloodmarking level.
        </p>
        
        <div class="grid md:grid-cols-2 gap-8">
            <div>
                <h3 class="text-xl font-semibold text-emerald-300 mb-4">Zone Features</h3>
                <ul class="space-y-3">
                    <li class="flex items-start gap-3">
                        <div class="w-2 h-2 rounded-full bg-gradient-to-r from-emerald-400 to-teal-300 mt-2.5 flex-shrink-0"></div>
                        <span class="text-neutral-300">Daily and repeatable questlines</span>
                    </li>
                    <li class="flex items-start gap-3">
                        <div class="w-2 h-2 rounded-full bg-gradient-to-r from-emerald-400 to-teal-300 mt-2.5 flex-shrink-0"></div>
                        <span class="text-neutral-300"><span class="text-emerald-200">Infested Behemoths</span> and scaling wildlife</span>
                    </li>
                    <li class="flex items-start gap-3">
                        <div class="w-2 h-2 rounded-full bg-gradient-to-r from-emerald-400 to-teal-300 mt-2.5 flex-shrink-0"></div>
                        <span class="text-neutral-300">Hidden <span class="text-emerald-200">World Rares</span> with exclusive drops</span>
                    </li>
                    <li class="flex items-start gap-3">
                        <div class="w-2 h-2 rounded-full bg-gradient-to-r from-emerald-400 to-teal-300 mt-2.5 flex-shrink-0"></div>
                        <span class="text-neutral-300">Rewards scale with your Bloodmarking level</span>
                    </li>
                </ul>
            </div>
            
            <div>
                <h3 class="text-xl font-semibold text-emerald-300 mb-4">World Rares</h3>
                <p class="text-neutral-400 text-sm mb-3">Rare bosses with chances for unique transmog and mounts from Cata, MoP, WoD, BFA+</p>
                <div class="grid grid-cols-1 gap-2">
                    <?php foreach ($worldRares as $rare): ?>
                        <div class="p-2 rounded-lg bg-white/5 border border-white/10">
                            <span class="text-emerald-300 font-medium text-sm"><?= e($rare) ?></span>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Milestones -->
    <div class="mb-12">
        <h2 class="text-3xl font-bold text-white mb-8 text-center">Milestone Rewards</h2>
        <div class="grid sm:grid-cols-2 lg:grid-cols-3 gap-6">
            <?php foreach ($milestones as $milestone): ?>
                <div class="rounded-2xl border border-rose-400/30 bg-gradient-to-b from-rose-500/10 to-transparent p-6">
                    <div class="text-center">
                        <div class="inline-flex items-center justify-center w-12 h-12 rounded-full bg-rose-500/20 border border-rose-400/40 mb-4">
                            <span class="text-lg font-bold text-rose-300"><?= e($milestone['level']) ?></span>
                        </div>
                        <h3 class="text-lg font-semibold text-white mb-2">Level <?= e($milestone['level']) ?></h3>
                        <p class="text-neutral-300 text-sm leading-relaxed"><?= e($milestone['reward']) ?></p>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- Strategy Tips -->
    <div class="grid md:grid-cols-2 gap-8 mb-12">
        <div class="rounded-2xl border border-white/10 bg-white/5 p-8">
            <h2 class="text-2xl font-bold text-white mb-6">Strategy Tips</h2>
            <ul class="space-y-3">
                <li class="flex items-start gap-3">
                    <div class="w-2 h-2 rounded-full bg-gradient-to-r from-brand-400 to-emerald-300 mt-2.5 flex-shrink-0"></div>
                    <p class="text-neutral-300">Bank XP Emblems before big grinding sessions for maximum efficiency.</p>
                </li>
                <li class="flex items-start gap-3">
                    <div class="w-2 h-2 rounded-full bg-gradient-to-r from-brand-400 to-emerald-300 mt-2.5 flex-shrink-0"></div>
                    <p class="text-neutral-300">Focus on Emerald Forest World Rares for exclusive transmog and mounts.</p>
                </li>
                <li class="flex items-start gap-3">
                    <div class="w-2 h-2 rounded-full bg-gradient-to-r from-brand-400 to-emerald-300 mt-2.5 flex-shrink-0"></div>
                    <p class="text-neutral-300">Check your Guide Book regularly to see next reward thresholds.</p>
                </li>
                <li class="flex items-start gap-3">
                    <div class="w-2 h-2 rounded-full bg-gradient-to-r from-brand-400 to-emerald-300 mt-2.5 flex-shrink-0"></div>
                    <p class="text-neutral-300">Use <span class="text-emerald-200">.buff</span> command to track your unlocked character buffs.</p>
                </li>
            </ul>
        </div>

        <div class="rounded-2xl border border-emerald-400/30 bg-gradient-to-b from-emerald-500/10 to-transparent p-8">
            <h2 class="text-2xl font-bold text-white mb-6">Level 1000+ Rewards</h2>
            <div class="space-y-4">
                <div class="text-center">
                    <div class="inline-flex items-center justify-center w-16 h-16 rounded-2xl bg-emerald-500/20 border border-emerald-400/40 mb-4">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-8 w-8 text-emerald-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z" />
                        </svg>
                    </div>
                    <h3 class="text-xl font-bold text-emerald-300 mb-2">The Bloodseeker</h3>
                    <p class="text-neutral-300 leading-relaxed mb-4">
                        Reach level 1000 to earn the exclusive title <span class="text-emerald-200">[The Bloodseeker]</span> and unlock World Quests.
                    </p>
                </div>
                <div class="p-4 rounded-xl bg-emerald-500/10 border border-emerald-400/20">
                    <p class="text-neutral-300 text-sm">
                        <strong>Beyond 1000:</strong> Progression continues with additional rewards, materials, and exclusive content.
                    </p>
                </div>
            </div>
        </div>
    </div>

    <!-- Progressive Items -->
    <div class="mb-12 rounded-2xl border border-amber-400/30 bg-gradient-to-r from-amber-500/10 via-orange-500/5 to-yellow-400/10 p-8">
        <h2 class="text-2xl font-bold text-white mb-6">Progressive Items & Tier Sets</h2>
        <p class="text-neutral-200 text-lg leading-relaxed mb-4">
            Many weapons, armor pieces, and custom Tier Sets require specific Bloodmarking levels to unlock their full potential and upgrade paths.
        </p>
        <div class="grid sm:grid-cols-2 gap-4">
            <div class="p-4 rounded-xl bg-white/5 border border-white/10">
                <h3 class="text-lg font-semibold text-amber-300 mb-2">Weapon Upgrades</h3>
                <p class="text-neutral-300">Unlock enhanced versions of weapons as your Bloodmarking level increases.</p>
            </div>
            <div class="p-4 rounded-xl bg-white/5 border border-white/10">
                <h3 class="text-lg font-semibold text-amber-300 mb-2">Tier Set Evolution</h3>
                <p class="text-neutral-300">Custom Tier Sets require specific Bloodmarking levels to progress to higher tiers.</p>
            </div>
        </div>
    </div>

    <!-- TL;DR Summary -->
    <div class="rounded-2xl border border-white/10 bg-gradient-to-r from-rose-500/10 via-amber-500/10 to-emerald-500/10 p-8 text-center">
        <h3 class="text-xl font-bold text-rose-200 mb-4">TL;DR</h3>
        <p class="text-neutral-200 text-lg leading-relaxed">
            Visit Broll Bearmantle in the Global Mall, kill creatures to earn BMXP (2000 per level), unlock character buffs and rewards, explore the scaling Emerald Forest zone, and chase the exclusive [The Bloodseeker] title at level 1000.
        </p>
    </div>

    <!-- Navigation -->
    <div class="mt-12 flex flex-col sm:flex-row gap-4 justify-center">
        <a href="<?= e(base_url('features/custom-content')) ?>" 
           class="btn btn-primary px-8 py-3 rounded-xl font-semibold transition-all hover:scale-105">
            Next: Custom Content →
        </a>
        <a href="<?= e(base_url('features/heart-of-azeroth')) ?>" 
           class="btn btn-ghost px-8 py-3 rounded-xl font-semibold transition-all hover:scale-105">
            ← Previous: Heart of Azeroth
        </a>
    </div>
</section>