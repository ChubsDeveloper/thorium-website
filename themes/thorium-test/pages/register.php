<?php
/**
 * themes/thorium-test/pages/register.php
 * Theme page override - customizes page template for this theme
 */

// themes/thorium-emeraldforest/pages/register.php

// Resolve app root safely (themes/<theme>/pages → htdocs/)
$APP_ROOT = dirname(__DIR__, 3);
$authPath = $APP_ROOT . '/app/auth.php';
if (!is_file($authPath)) {
  http_response_code(500);
  echo "Missing: " . htmlspecialchars($authPath);
  exit;
}
require_once $authPath;

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (!csrf_check($_POST['csrf'] ?? '')) {
    $error = 'Invalid CSRF token.';
  } elseif (!$pdo || !$authPdo) {
    $error = 'Database not available.';
  } else {
    try {
      $u = trim($_POST['username'] ?? '');
      $m = trim($_POST['email'] ?? '');
      $p = $_POST['password'] ?? '';

      // Basic hardening (server-side; complements HTML constraints)
      if ($u === '' || !preg_match('/^[A-Za-z0-9_]{3,32}$/', $u)) {
        throw new RuntimeException('Username must be 3–32 chars (A–Z, 0–9, underscore).');
      }
      if (!filter_var($m, FILTER_VALIDATE_EMAIL)) {
        throw new RuntimeException('Please enter a valid email address.');
      }
      if (strlen($p) < 6) {
        throw new RuntimeException('Password must be at least 6 characters.');
      }

      // Create in site DB + auth DB (function from app/auth.php)
      $created = register_site_and_auth($config, $u, $m, $p);

      // Auto-login using site hash just like your login flow
      site_login_attempt($pdo, $authPdo, $u, $p, $config['admin_min_security_level']);

      // Go home 🎉
      redirect(base_url(''));
    } catch (Throwable $e) {
      $error = $e->getMessage();
    }
  }
}
?>

<section class="container px-4 pt-16 md:pt-52 pb-24 md:pb-40">
  <div class="relative rough-card overflow-hidden p-0 shine max-w-2xl mx-auto">
    <div class="absolute inset-y-0 left-0 w-1.5 bg-gradient-to-b from-emerald-400/80 to-emerald-500/60"></div>

    <div class="p-6 md:p-8">
      <div class="mb-4">
        <p class="kicker">Welcome</p>
        <h1 class="h-display text-3xl md:text-4xl font-extrabold">Create account</h1>
        <p class="mt-1 muted">One account for the website &amp; in-game.</p>
      </div>

      <?php if ($error): ?>
        <div class="mt-2 rough-card p-3 text-sm text-red-300 border-red-500/30 bg-red-950/20"><?= e($error) ?></div>
      <?php endif; ?>

      <form method="post" class="mt-6 space-y-4">
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">

        <div>
          <label class="block text-sm text-neutral-300 mb-1">Username</label>
          <input
            name="username"
            required
            pattern="[A-Za-z0-9_]{3,32}"
            placeholder="YourName"
            class="w-full rounded-xl bg-black/30 border border-white/10 px-3 py-2 focus:outline-none focus:ring-2 focus:ring-amber-500"
          />
          <p class="mt-1 text-[12px] opacity-60">3–32 characters • letters, numbers, underscore</p>
        </div>

        <div>
          <label class="block text-sm text-neutral-300 mb-1">Email</label>
          <input
            name="email"
            type="email"
            required
            placeholder="you@email.com"
            class="w-full rounded-xl bg-black/30 border border-white/10 px-3 py-2 focus:outline-none focus:ring-2 focus:ring-amber-500"
          />
        </div>

        <div>
          <label class="block text-sm text-neutral-300 mb-1">Password</label>
          <div class="relative">
            <input
              id="pw"
              name="password"
              type="password"
              minlength="6"
              required
              placeholder="••••••••"
              class="w-full rounded-xl bg-black/30 border border-white/10 px-3 py-2 pr-10 focus:outline-none focus:ring-2 focus:ring-amber-500"
            />
            <button
              type="button"
              id="pwToggle"
              class="absolute right-2 top-1/2 -translate-y-1/2 text-neutral-300/80 hover:text-neutral-100 transition"
              title="Show/Hide password"
            >
              👁
            </button>
          </div>
          <p class="mt-1 text-[12px] opacity-60">At least 6 characters. Use a unique password.</p>
        </div>

        <button class="btn-warm w-full">Create account</button>

        <p class="mt-3 text-sm text-neutral-300/80">
          Already have an account?
          <a class="text-amber-300 hover:text-ember-500" href="<?= e(base_url('login')) ?>">Sign in</a>.
        </p>
      </form>
    </div>
  </div>
</section>

<!-- Tiny helper for show/hide password -->
<script>
  document.getElementById('pwToggle')?.addEventListener('click', () => {
    const input = document.getElementById('pw');
    if (!input) return;
    input.type = input.type === 'password' ? 'text' : 'password';
  });
</script>
