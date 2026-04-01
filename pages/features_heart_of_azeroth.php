<?php
/**
 * pages/features_heart_of_azeroth.php
 * Heart of Azeroth feature page - CONTENT ONLY
 */
declare(strict_types=1);

if (!function_exists('e')) {
    function e($value) { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); }
}

$roles = [
    ['name' => 'Assassin', 'color' => 'rose', 'description' => 'Increases Attack, Casting, and Movement speed. Boosts your Bloodmarking XP rate.', 'focus' => 'Speed & Agility'],
    ['name' => 'Fighter', 'color' => 'amber', 'description' => 'Grants Attack Power and Stamina scaling with Role Rank. Boosts your Bloodmarking XP rate.', 'focus' => 'Physical Damage'],
    ['name' => 'Arcanist', 'color' => 'sky', 'description' => 'Grants Spell Power and Stamina scaling with Role Rank. Boosts your Bloodmarking XP rate.', 'focus' => 'Magical Damage'],
    ['name' => 'Support', 'color' => 'emerald', 'description' => 'Invisible shield that reduces damage taken. Boosts your Bloodmarking XP rate.', 'focus' => 'Defense & Survival'],
    ['name' => 'Vampire', 'color' => 'violet', 'description' => 'Increases Damage done and Haste (scales with Role Rank). 1% chance to drain Health from the enemy. Boosts your Bloodmarking XP rate.', 'focus' => 'Lifesteal & Haste']
];
?>

<section class="container mx-auto px-4 py-12">
    <!-- Breadcrumb -->
    <nav class="mb-8">
        <ol class="flex items-center space-x-2 text-sm text-neutral-400">
            <li><a href="<?= e(base_url('features')) ?>" class="hover:text-brand-400 transition-colors">Features</a></li>
            <li><svg class="w-4 h-4" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M7.21 14.77a.75.75 0 01.02-1.06L11.168 10 7.23 6.29a.75.75 0 111.04-1.08l4.5 4.25a.75.75 0 010 1.08l-4.5 4.25a.75.75 0 01-1.06-.02z" clip-rule="evenodd"></path></svg></li>
            <li class="text-amber-400">Heart of Azeroth</li>
        </ol>
    </nav>

    <!-- Header -->
    <div class="text-center mb-12">
        <div class="inline-flex items-center justify-center w-16 h-16 rounded-2xl bg-gradient-to-br from-amber-500/20 to-yellow-400/10 border border-amber-400/30 mb-6">
            <svg xmlns="http://www.w3.org/2000/svg" class="h-8 w-8 text-amber-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 8l1.5-3L12 8l3 1.5L12 11l-1.5 3L9 11l-3-1.5L9 8zm8 6l.75-1.5L20 14l1.5.75L20 16l-.75 1.5L18 16l-1.5-.75L18 14z"/>
            </svg>
        </div>
        <h1 class="text-4xl md:text-5xl font-bold text-white mb-4">
            Heart of <span class="text-amber-400">Azeroth</span>
        </h1>
        <p class="text-xl text-neutral-300 max-w-3xl mx-auto">
            A custom Class Artifact system that evolves with your character, providing meaningful progression and build customization.
        </p>
    </div>

    <!-- Overview Card -->
    <div class="mb-12 rounded-2xl bg-gradient-to-r from-amber-500/15 via-yellow-400/10 to-rose-400/10 border border-amber-400/30 p-8">
        <div class="flex flex-wrap items-center gap-3 mb-4">
            <span class="inline-flex items-center px-3 py-1 rounded-full text-sm font-semibold bg-amber-500/20 text-amber-200 border border-amber-400/30">
                Class Artifact
            </span>
            <span class="text-amber-200/90">A custom twist on retail's Heart of Azeroth.</span>
        </div>
        <p class="text-neutral-200 text-lg leading-relaxed">
            Complete a unique quest chain to receive an <span class="text-amber-300 font-medium">interactive inventory item</span> that opens a custom UI.
            Earn <span class="text-amber-300 font-medium">Artifact Energy</span> by killing creatures and world bosses to level your Artifact and unlock power.
        </p>
    </div>

    <!-- How It Works -->
    <div class="grid lg:grid-cols-2 gap-8 mb-12">
        <div class="rounded-2xl border border-white/10 bg-white/5 p-8">
            <h2 class="text-2xl font-bold text-white mb-6">How it Works</h2>
            <ul class="space-y-4">
                <li class="flex items-start gap-3">
                    <div class="w-2 h-2 rounded-full bg-gradient-to-r from-amber-400 to-yellow-300 mt-2.5 flex-shrink-0"></div>
                    <div><p class="text-neutral-300">Use your <em>Class Artifact</em> item to open the Artifact UI and track your progress.</p></div>
                </li>
                <li class="flex items-start gap-3">
                    <div class="w-2 h-2 rounded-full bg-gradient-to-r from-amber-400 to-yellow-300 mt-2.5 flex-shrink-0"></div>
                    <div><p class="text-neutral-300">Earn <span class="text-amber-200">Artifact Energy</span> from random creatures and world bosses across Azeroth.</p></div>
                </li>
                <li class="flex items-start gap-3">
                    <div class="w-2 h-2 rounded-full bg-gradient-to-r from-amber-400 to-yellow-300 mt-2.5 flex-shrink-0"></div>
                    <div><p class="text-neutral-300">Leveling unlocks a <span class="text-emerald-200">scaling Artifact Buff</span> (auto-upgrades) that boosts Health, Damage, and more.</p></div>
                </li>
                <li class="flex items-start gap-3">
                    <div class="w-2 h-2 rounded-full bg-gradient-to-r from-amber-400 to-yellow-300 mt-2.5 flex-shrink-0"></div>
                    <div><p class="text-neutral-300"><span class="text-amber-200 font-medium">Weekend Bonus:</span> earn <strong>double Artifact XP</strong> every weekend.</p></div>
                </li>
            </ul>
        </div>

        <div class="rounded-2xl border border-white/10 bg-white/5 p-8">
            <h2 class="text-2xl font-bold text-white mb-6">Quest Chain</h2>
            <div class="space-y-4">
                <div class="p-4 rounded-xl bg-gradient-to-r from-amber-500/10 to-yellow-400/5 border border-amber-400/20">
                    <h3 class="text-lg font-semibold text-amber-300 mb-2">Prerequisites</h3>
                    <p class="text-neutral-300">Complete the main questline in the <span class="text-red-500">Bloodmarking Zone</span> and reach <span class="text-amber-200">level 85+</span>.</p>
                </div>
                <div class="p-4 rounded-xl bg-gradient-to-r from-emerald-500/10 to-teal-400/5 border border-emerald-400/20">
                    <h3 class="text-lg font-semibold text-emerald-300 mb-2">Starting Quest</h3>
                    <p class="text-neutral-300">Pick up <span class="text-amber-200 font-medium">[Examine the Monastery]</span> to begin the chain.</p>
                </div>
            </div>
        </div>
    </div>

    <!-- Sinergy Points -->
    <div class="mb-12 rounded-2xl border border-emerald-400/30 bg-gradient-to-b from-emerald-500/10 to-transparent p-8">
        <div class="flex flex-wrap items-center gap-3 mb-4">
            <span class="inline-flex items-center px-3 py-1 rounded-full text-sm font-semibold bg-emerald-500/20 text-emerald-200 border border-emerald-400/30">
                Sinergy Points
            </span>
            <span class="text-emerald-200/90">Every 40 Artifact levels = 1 Sinergy Point</span>
        </div>
        <p class="text-neutral-200 text-lg leading-relaxed">
            Spend <span class="text-emerald-200">Sinergy</span> to upgrade your <em>Artifact Roles</em>. You may select and level up <strong>any 3 roles at a time</strong>.
        </p>
    </div>

    <!-- Artifact Roles -->
    <div class="mb-12">
        <h2 class="text-3xl font-bold text-white mb-8 text-center">Artifact Roles</h2>
        <div class="grid md:grid-cols-2 lg:grid-cols-3 gap-6">
            <?php foreach ($roles as $role): ?>
                <div class="rounded-2xl border border-<?= e($role['color']) ?>-400/30 bg-gradient-to-b from-<?= e($role['color']) ?>-500/10 to-transparent p-6">
                    <div class="flex items-center justify-between mb-4">
                        <span class="inline-flex px-3 py-1 rounded-lg text-sm font-semibold bg-<?= e($role['color']) ?>-500/20 text-<?= e($role['color']) ?>-200 border-<?= e($role['color']) ?>-400/30 border">
                            <?= e($role['name']) ?>
                        </span>
                        <span class="text-xs text-<?= e($role['color']) ?>-400 font-medium">
                            <?= e($role['focus']) ?>
                        </span>
                    </div>
                    <p class="text-neutral-200 leading-relaxed">
                        <?= e($role['description']) ?>
                    </p>
                </div>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- Navigation -->
    <div class="mt-12 flex flex-col sm:flex-row gap-4 justify-center">
        <a href="<?= e(base_url('features/bloodmarking')) ?>" 
           class="btn btn-primary px-8 py-3 rounded-xl font-semibold transition-all hover:scale-105">
            Next: Bloodmarking →
        </a>
        <a href="<?= e(base_url('features')) ?>" 
           class="btn btn-ghost px-8 py-3 rounded-xl font-semibold transition-all hover:scale-105">
            ← Back to Features
        </a>
    </div>
</section>