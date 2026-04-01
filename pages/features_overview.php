<?php
/**
 * pages/features_overview.php
 * Features overview page showing all game systems - CONTENT ONLY
 */
declare(strict_types=1);

if (!function_exists('e')) {
    function e($value) { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); }
}

$features = [
    [
        'slug' => 'heart-of-azeroth',
        'title' => 'Heart of Azeroth',
        'icon' => '<svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 8l1.5-3L12 8l3 1.5L12 11l-1.5 3L9 11l-3-1.5L9 8zm8 6l.75-1.5L20 14l1.5.75L20 16l-.75 1.5L18 16l-1.5-.75L18 14z"/></svg>',
        'description' => 'Custom Class Artifact—level it with Artifact Energy and tailor your build.',
        'color' => 'text-amber-400',
        'border' => 'border-amber-400/30',
        'bg' => 'from-amber-500/10',
        'details' => [
            'Complete a unique quest chain to receive an interactive inventory item',
            'Earn Artifact Energy by killing creatures and world bosses',
            'Unlock scaling buffs and customize with Sinergy Points',
            'Weekend bonus: double Artifact XP every weekend'
        ]
    ],
    [
        'slug' => 'bloodmarking',
        'title' => 'Bloodmarking',
        'icon' => '<svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M12 3c3.5 5 6 7.9 6 11a6 6 0 11-12 0c0-3.1 2.5-6 6-11z"/></svg>',
        'description' => 'Play anything, get stronger. Track progress with your Guide Book.',
        'color' => 'text-rose-400',
        'border' => 'border-rose-400/30',
        'bg' => 'from-rose-500/10',
        'details' => [
            'Earn BMXP from open-world kills, elites, dungeons, and world bosses',
            'Level up your Bloodmarking for better rewards and unlocks',
            'Receive loot bags, emblems, and progression materials',
            'Unlock a unique mount at level 1000'
        ]
    ],
    [
        'slug' => 'custom-content',
        'title' => 'Custom Content',
        'icon' => '<svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9.5 4.5L4 6.5v13l5.5-2 5 2L20 17.5v-13l-5.5 2-5-2z"/></svg>',
        'description' => 'Explore new zones, currencies, upgrades, visuals, and class cosmetics.',
        'color' => 'text-emerald-400',
        'border' => 'border-emerald-400/30',
        'bg' => 'from-emerald-500/10',
        'details' => [
            'Custom currencies: Bloody Tokens and Azerite Fragments',
            'Upgrading Hammer with guided UI for gear progression',
            'Visual enchant system with any weapon enchant effect',
            'Druid transformation system with Legion Artifact appearances'
        ]
    ]
];
?>

<section class="container mx-auto px-4 py-12">
    <!-- Header -->
    <div class="text-center mb-12">
        <h1 class="text-4xl md:text-5xl font-bold text-white mb-4">
            Game <span class="text-brand-400">Features</span>
        </h1>
        <p class="text-xl text-neutral-300 max-w-3xl mx-auto">
            Discover the unique systems that make Thorium: Reforged a truly exceptional WotLK experience.
        </p>
    </div>

    <!-- Feature Grid -->
    <div class="grid md:grid-cols-2 lg:grid-cols-3 gap-8 mb-12">
        <?php foreach ($features as $feature): ?>
            <article class="group relative">
                <a href="<?= e(base_url('features/' . $feature['slug'])) ?>" 
                   class="flex flex-col h-full rounded-2xl border <?= e($feature['border']) ?> bg-gradient-to-b <?= e($feature['bg']) ?> to-transparent p-6 transition-all duration-300 hover:border-opacity-60 hover:transform hover:scale-105">
                    
                    <!-- Icon and Title -->
                    <div class="flex items-start gap-4 mb-4">
                        <div class="rounded-lg bg-white/5 p-3 ring-1 ring-white/10 <?= e($feature['color']) ?>">
                            <?= $feature['icon'] ?>
                        </div>
                        <div class="flex-1">
                            <h3 class="text-xl font-bold text-white mb-2"><?= e($feature['title']) ?></h3>
                            <p class="text-neutral-300 text-sm leading-relaxed"><?= e($feature['description']) ?></p>
                        </div>
                    </div>

                    <!-- Feature Details -->
                    <div class="flex-1 mb-4">
                        <ul class="space-y-2">
                            <?php foreach ($feature['details'] as $detail): ?>
                                <li class="flex items-start gap-2 text-sm text-neutral-400">
                                    <div class="w-1.5 h-1.5 rounded-full bg-gradient-to-r from-brand-400 to-emerald-300 mt-2 flex-shrink-0"></div>
                                    <span><?= e($detail) ?></span>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    </div>

                    <!-- Learn More Link - Now at bottom -->
                    <div class="flex items-center justify-between mt-auto">
                        <span class="text-brand-400 font-medium group-hover:text-brand-300 transition-colors">
                            Learn More
                        </span>
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 <?= e($feature['color']) ?> group-hover:translate-x-1 transition-transform" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/>
                        </svg>
                    </div>
                </a>
            </article>
        <?php endforeach; ?>
    </div>

    <!-- Bottom CTA -->
    <div class="text-center">
        <div class="inline-flex flex-col sm:flex-row gap-4">
            <a href="<?= e(base_url('how-to')) ?>" 
               class="btn btn-primary px-8 py-3 rounded-xl font-semibold transition-all hover:scale-105">
                How to Connect
            </a>
            <a href="<?= e(base_url('status')) ?>" 
               class="btn btn-ghost px-8 py-3 rounded-xl font-semibold transition-all hover:scale-105">
                Server Status
            </a>
        </div>
        <p class="text-neutral-400 text-sm mt-4">
            Join the adventure on Patch 3.3.5a with custom progression systems.
        </p>
    </div>
</section>