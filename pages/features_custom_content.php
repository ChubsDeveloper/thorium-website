<?php
/**
 * pages/features_custom_content.php
 * Detailed Custom Content page
 */
if (!function_exists('e')) {
    function e($value) { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); }
}

$currencies = [
    [
        'name' => 'Bloody Tokens',
        'color' => 'rose',
        'source' => 'Earned alongside Bloodmarking progression',
        'uses' => ['Scaled food buffs', 'Toys and vanity items', 'Custom mounts', 'Cosmetic rewards'],
        'vendor' => 'Bloodmarking Quartermaster',
        'location' => 'The Bloodmarking Zone (Emerald Forest)'
    ],
    [
        'name' => 'Azerite Fragments',
        'color' => 'amber',
        'source' => 'Earned while progressing Bloodmarking and Heart of Azeroth',
        'uses' => ['Transmogs and cosmetics', 'Mounts', 'Reputation items', 'Buffs and rewards', 'Diablo-style visuals'],
        'vendor' => 'Various vendors',
        'location' => 'Multiple locations'
    ]
];

$druidForms = [
    ['name' => 'Cat Form', 'includes' => 'All Legion Artifact appearances'],
    ['name' => 'Bear Form', 'includes' => 'All Legion Artifact appearances'],
    ['name' => 'Travel Form', 'includes' => 'Multiple visual variants'],
    ['name' => 'Aquatic Form', 'includes' => 'Swimming variants'],
    ['name' => 'Flight Form', 'includes' => 'Flying variants'],
    ['name' => 'Tree of Life', 'includes' => 'Healing form variants'],
    ['name' => 'Moonkin Form', 'includes' => 'Balance form variants']
];
?>

<section class="container mx-auto px-4 py-12">
    <!-- Breadcrumb -->
    <nav class="mb-8">
        <ol class="flex items-center space-x-2 text-sm text-neutral-400">
            <li><a href="<?= e(base_url('features')) ?>" class="hover:text-brand-400 transition-colors">Features</a></li>
            <li><svg class="w-4 h-4" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M7.21 14.77a.75.75 0 01.02-1.06L11.168 10 7.23 6.29a.75.75 0 111.04-1.08l4.5 4.25a.75.75 0 010 1.08l-4.5 4.25a.75.75 0 01-1.06-.02z" clip-rule="evenodd"></path></svg></li>
            <li class="text-emerald-400">Custom Content</li>
        </ol>
    </nav>

    <!-- Header -->
    <div class="text-center mb-12">
        <div class="inline-flex items-center justify-center w-16 h-16 rounded-2xl bg-gradient-to-br from-emerald-500/20 to-teal-400/10 border border-emerald-400/30 mb-6">
            <svg xmlns="http://www.w3.org/2000/svg" class="h-8 w-8 text-emerald-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9.5 4.5L4 6.5v13l5.5-2 5 2L20 17.5v-13l-5.5 2-5-2z"/>
            </svg>
        </div>
        <h1 class="text-4xl md:text-5xl font-bold text-white mb-4">
            Custom <span class="text-emerald-400">Content</span>
        </h1>
        <p class="text-xl text-neutral-300 max-w-3xl mx-auto">
            Progression, cosmetics, currencies, and systems unique to Thorium that expand your adventure beyond traditional WoW.
        </p>
    </div>

    <!-- Overview Card -->
    <div class="mb-12 rounded-2xl bg-gradient-to-r from-emerald-500/15 via-teal-400/10 to-sky-400/10 border border-emerald-400/30 p-8">
        <div class="flex items-center gap-3 mb-4">
            <span class="inline-flex items-center px-3 py-1 rounded-full text-sm font-semibold bg-emerald-500/20 text-emerald-200 border border-emerald-400/30">
                Custom Content
            </span>
            <span class="text-emerald-200/90">Progression, cosmetics, currencies, and systems unique to Thorium.</span>
        </div>
        <p class="text-neutral-200 text-lg leading-relaxed">
            As you level Bloodmarking and your Heart of Azeroth, you'll unlock bespoke currencies, powerful upgrades, and visual customization—plus class-specific collections.
        </p>
    </div>

    <!-- Custom Currencies -->
    <div class="mb-12">
        <h2 class="text-3xl font-bold text-white mb-8 text-center">Custom Currencies</h2>
        <div class="grid lg:grid-cols-2 gap-8">
            <?php foreach ($currencies as $currency): 
                $colorClass = "text-{$currency['color']}-400";
                $borderClass = "border-{$currency['color']}-400/30";
                $bgClass = "from-{$currency['color']}-500/10";
                $badgeClass = "bg-{$currency['color']}-500/20 text-{$currency['color']}-200 border-{$currency['color']}-400/30";
            ?>
                <div class="rounded-2xl <?= $borderClass ?> bg-gradient-to-b <?= $bgClass ?> to-transparent p-8">
                    <div class="flex items-center gap-3 mb-4">
                        <span class="inline-flex px-3 py-1 rounded-lg text-sm font-semibold <?= $badgeClass ?> border">
                            <?= e($currency['name']) ?>
                        </span>
                    </div>
                    
                    <div class="space-y-4">
                        <div>
                            <h3 class="text-lg font-semibold text-white mb-2">How to Earn</h3>
                            <p class="text-neutral-300"><?= e($currency['source']) ?></p>
                        </div>
                        
                        <div>
                            <h3 class="text-lg font-semibold text-white mb-2">Uses</h3>
                            <ul class="space-y-1">
                                <?php foreach ($currency['uses'] as $use): ?>
                                    <li class="flex items-start gap-2">
                                        <div class="w-1.5 h-1.5 rounded-full bg-gradient-to-r from-brand-400 to-emerald-300 mt-2 flex-shrink-0"></div>
                                        <span class="text-neutral-300"><?= e($use) ?></span>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                        
                        <div class="mt-3">
                            <p class="text-sm text-neutral-400">
                                <strong>Vendor:</strong> <?= e($currency['vendor']) ?><br>
                                <strong>Location:</strong> <?= e($currency['location']) ?>
                            </p>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- Upgrading Hammer System -->
    <div class="mb-12 rounded-2xl border border-white/10 bg-white/5 p-8">
        <h2 class="text-3xl font-bold text-white mb-6">Armor & Weapons Upgrading Hammer</h2>
        <p class="text-neutral-300 text-lg leading-relaxed mb-6">
            Added to your inventory at character creation. Opens a <em>custom UI</em> showing upgradable items and required materials.
        </p>
        
        <div class="grid md:grid-cols-2 gap-8">
            <div class="rounded-xl border border-white/10 p-6">
                <h3 class="text-xl font-semibold text-white mb-4">Materials Sources</h3>
                <ul class="space-y-3">
                    <li class="flex items-start gap-3">
                        <div class="w-2 h-2 rounded-full bg-gradient-to-r from-brand-400 to-emerald-300 mt-2.5 flex-shrink-0"></div>
                        <span class="text-neutral-300">Custom Instances</span>
                    </li>
                    <li class="flex items-start gap-3">
                        <div class="w-2 h-2 rounded-full bg-gradient-to-r from-brand-400 to-emerald-300 mt-2.5 flex-shrink-0"></div>
                        <span class="text-neutral-300">World Bosses & Rares</span>
                    </li>
                    <li class="flex items-start gap-3">
                        <div class="w-2 h-2 rounded-full bg-gradient-to-r from-brand-400 to-emerald-300 mt-2.5 flex-shrink-0"></div>
                        <span class="text-neutral-300">Daily Quests</span>
                    </li>
                </ul>
            </div>
            
            <div class="rounded-xl border border-amber-400/30 bg-gradient-to-b from-amber-500/10 to-transparent p-6">
                <h3 class="text-xl font-semibold text-white mb-4">Alternative Upgrades</h3>
                <p class="text-neutral-300 mb-3">
                    Use <span class="text-amber-200">Vote Tokens</span> or <span class="text-rose-200">Donation Tokens</span> to bypass some requirements.
                </p>
                <p class="text-sm text-neutral-400">
                    Skips some requirements like Reputation or Bloodmarking Level thresholds.
                </p>
            </div>
        </div>
    </div>

    <!-- Visual Enchant System -->
    <div class="mb-12 rounded-2xl border border-sky-400/30 bg-gradient-to-b from-sky-500/10 to-transparent p-8">
        <h2 class="text-3xl font-bold text-white mb-6">Visual Enchant System</h2>
        <div class="grid md:grid-cols-2 gap-8 items-center">
            <div>
                <h3 class="text-xl font-semibold text-white mb-4">Naarál, Mother of Light</h3>
                <p class="text-neutral-200 mb-4">
                    Located in the <em>Transmogrification Hub</em>, apply <strong>any weapon enchant visual</strong>, including unused/unreleased effects.
                </p>
                <div class="space-y-2">
                    <p class="text-neutral-300">
                        <strong>Cost:</strong> <span class="text-amber-300">500× Transmogrification Tokens</span> per visual
                    </p>
                    <p class="text-neutral-300">
                        <strong>Duration:</strong> Changes are <em>permanent</em>
                    </p>
                </div>
            </div>
            <div class="rounded-xl bg-gradient-to-r from-sky-500/20 to-purple-500/10 border border-sky-400/30 p-6 text-center">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-12 w-12 text-sky-400 mx-auto mb-3" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M13.828 10.172a4 4 0 00-5.656 0l-4 4a4 4 0 105.656 5.656l1.102-1.101m-.758-4.899a4 4 0 005.656 0l4-4a4 4 0 00-5.656-5.656l-1.1 1.1"/>
                </svg>
                <h4 class="text-lg font-semibold text-sky-300">Epic Visuals</h4>
                <p class="text-neutral-300 text-sm">Access effects never before seen in WotLK</p>
            </div>
        </div>
    </div>

    <!-- Druid Transformation System -->
    <div class="mb-12 rounded-2xl border border-emerald-400/30 bg-gradient-to-b from-emerald-500/10 to-transparent p-8">
        <h2 class="text-3xl font-bold text-white mb-6">Druid Transformation System</h2>
        <p class="text-neutral-200 text-lg leading-relaxed mb-6">
            Druids get a unique <em>Idol</em> to collect <em>Runes</em> and unlock shapeshift appearances—including all <span class="text-emerald-200">Legion Artifact</span> looks for Cat & Bear.
        </p>
        
        <div class="grid lg:grid-cols-2 gap-8">
            <div class="rounded-xl border border-white/10 p-6">
                <h3 class="text-xl font-semibold text-white mb-4">Supported Forms</h3>
                <div class="grid sm:grid-cols-2 gap-3">
                    <?php foreach ($druidForms as $form): ?>
                        <div class="p-3 rounded-lg bg-white/5 border border-white/10">
                            <h4 class="font-semibold text-emerald-300 text-sm"><?= e($form['name']) ?></h4>
                            <p class="text-xs text-neutral-400"><?= e($form['includes']) ?></p>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
            
            <div class="rounded-xl border border-white/10 p-6">
                <h3 class="text-xl font-semibold text-white mb-4">How to Unlock</h3>
                <ul class="space-y-3">
                    <li class="flex items-start gap-3">
                        <div class="w-2 h-2 rounded-full bg-gradient-to-r from-emerald-400 to-teal-300 mt-2.5 flex-shrink-0"></div>
                        <span class="text-neutral-300">Runes drop across Azeroth from vendors and rare drops</span>
                    </li>
                    <li class="flex items-start gap-3">
                        <div class="w-2 h-2 rounded-full bg-gradient-to-r from-emerald-400 to-teal-300 mt-2.5 flex-shrink-0"></div>
                        <span class="text-neutral-300">Get the Idol from Malfurion Stormrage's questline on <em>Timeless Isle</em></span>
                    </li>
                    <li class="flex items-start gap-3">
                        <div class="w-2 h-2 rounded-full bg-gradient-to-r from-emerald-400 to-teal-300 mt-2.5 flex-shrink-0"></div>
                        <span class="text-neutral-300">Requires level <strong>85</strong> to see the quest</span>
                    </li>
                </ul>
            </div>
        </div>
    </div>

    <!-- Quick Start Guide -->
    <div class="mb-12 rounded-2xl border border-white/10 bg-white/5 p-8">
        <h2 class="text-3xl font-bold text-white mb-6">Quick Start Guide</h2>
        <div class="grid md:grid-cols-3 gap-6">
            <div class="p-4 rounded-xl bg-gradient-to-r from-rose-500/10 to-red-400/5 border border-rose-400/20">
                <h3 class="text-lg font-semibold text-rose-300 mb-2">Bloodmarking Quartermaster</h3>
                <p class="text-neutral-300 text-sm">The Bloodmarking Zone (Emerald Forest)</p>
            </div>
            <div class="p-4 rounded-xl bg-gradient-to-r from-sky-500/10 to-blue-400/5 border border-sky-400/20">
                <h3 class="text-lg font-semibold text-sky-300 mb-2">Transmog Hub</h3>
                <p class="text-neutral-300 text-sm">Find Naarál for Visual Enchants</p>
            </div>
            <div class="p-4 rounded-xl bg-gradient-to-r from-amber-500/10 to-yellow-400/5 border border-amber-400/20">
                <h3 class="text-lg font-semibold text-amber-300 mb-2">Upgrading Hammer</h3>
                <p class="text-neutral-300 text-sm">Check your inventory on new characters</p>
            </div>
        </div>
    </div>

    <!-- TL;DR Summary -->
    <div class="rounded-2xl border border-white/10 bg-gradient-to-r from-emerald-500/10 via-rose-500/10 to-amber-500/10 p-8 text-center">
        <h3 class="text-xl font-bold text-emerald-200 mb-4">TL;DR</h3>
        <p class="text-neutral-200 text-lg leading-relaxed">
            Farm currencies, upgrade gear with a guided UI, apply epic visual enchants, and chase class cosmetics.
        </p>
    </div>

    <!-- Navigation -->
    <div class="mt-12 flex flex-col sm:flex-row gap-4 justify-center">
        <a href="<?= e(base_url('features/heart-of-azeroth')) ?>" 
           class="btn btn-primary px-8 py-3 rounded-xl font-semibold transition-all hover:scale-105">
            Heart of Azeroth →
        </a>
        <a href="<?= e(base_url('features')) ?>" 
           class="btn btn-ghost px-8 py-3 rounded-xl font-semibold transition-all hover:scale-105">
            ← Back to Features
        </a>
    </div>
</section>