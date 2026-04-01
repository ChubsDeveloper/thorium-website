<?php
// Site footer - navigation links and copyright information

// themes/thorium-emeraldforest/views/partials/footer.php
?>
<footer class="mt-auto py-16 border-t border-white/10 relative overflow-hidden">
  <!-- subtle ground fog -->
  <div class="fog pointer-events-none" aria-hidden="true"></div>

  <div class="container px-4">
    <div class="grid md:grid-cols-4 gap-8">
      <div>
        <h3 class="h-display text-xl font-bold mb-3">Thorium <span class="text-brand-400">WoW</span></h3>
        <p class="muted text-sm">Rooted in the Emerald Forest.</p>
      </div>
      <div>
        <h4 class="font-semibold mb-3">Game</h4>
        <div class="space-y-2 text-sm">
          <a href="<?= e(base_url('register')) ?>" class="muted hover:text-brand-400">Create Account</a>
          <a href="<?= e(base_url('armory')) ?>" class="muted hover:text-brand-400">Armory</a>
          <a href="<?= e(base_url('status')) ?>" class="muted hover:text-brand-400">Server Status</a>
        </div>
      </div>
      <div>
        <h4 class="font-semibold mb-3">Community</h4>
        <div class="space-y-2 text-sm">
          <a href="<?= e(base_url('news')) ?>" class="muted hover:text-brand-400">News</a>
          <a href="<?= e(base_url('shop')) ?>" class="muted hover:text-brand-400">Shop</a>
          <a href="<?= e(base_url('how-to')) ?>#discord" class="muted hover:text-brand-400">Discord</a>
        </div>
      </div>
      <div>
        <h4 class="font-semibold mb-3">Support</h4>
        <div class="space-y-2 text-sm">
          <a href="#" class="muted hover:text-brand-400">Privacy</a>
          <a href="#" class="muted hover:text-brand-400">Terms</a>
          <a href="#" class="muted hover:text-brand-400">Contact</a>
        </div>
      </div>
    </div>

    <div class="border-t border-white/10 mt-10 pt-6 text-center">
      <p class="text-sm muted">© <?= date('Y') ?> Thorium WoW. All rights reserved.</p>
    </div>
  </div>
</footer>
