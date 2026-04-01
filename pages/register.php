<?php
/**
 * pages/register.php
 * Page template - renders the register page
 */
declare(strict_types=1);

// app/pages/register.php
declare(strict_types=1);

// index.php already loads init.php/auth.php/etc. We just use what's there.


$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (!function_exists('csrf_check') || !csrf_check($_POST['csrf'] ?? '')) {
    $error = 'Invalid CSRF token.';
  } else {
    /** @var PDO   $pdo     Site DB (from init.php) */
    /** @var PDO   $authPdo Auth DB (from init.php) */
    /** @var array $config  App config (from init.php) */
    global $pdo, $authPdo, $config;

    if (!$pdo || !$authPdo) {
      $error = 'Database not available.';
    } else {
      try {
        $u = trim((string)($_POST['username'] ?? ''));
        $m = trim((string)($_POST['email'] ?? ''));
        $p = (string)($_POST['password'] ?? '');

        // Server-side validation
        if ($u === '' || !preg_match('/^[A-Za-z0-9_]{3,32}$/', $u)) {
          throw new RuntimeException('Username must be 3–32 chars (A–Z, 0–9, underscore).');
        }
        if (!filter_var($m, FILTER_VALIDATE_EMAIL)) {
          throw new RuntimeException('Please enter a valid email address.');
        }
        if (strlen($p) < 6) {
          throw new RuntimeException('Password must be at least 6 characters.');
        }

        // Create in BOTH DBs with same id (from app/auth.php)
        if (!function_exists('register_site_and_auth')) {
          throw new RuntimeException('Registration function missing.');
        }
        register_site_and_auth($config, $u, $m, $p);

        // Auto-login with site hash (same as login flow)
        $adminMin = (int)($config['admin_min_security_level'] ?? 3);
        if (function_exists('site_login_attempt')) {
          site_login_attempt($pdo, $authPdo, $u, $p, $adminMin);
        }

        // Go home 🎉
        $dest = function_exists('base_url') ? base_url('') : '/';
        if (function_exists('redirect')) { redirect($dest); }
        header('Location: ' . $dest);
        exit;
      } catch (Throwable $e) {
        $error = $e->getMessage();
      }
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

      <form method="post" class="mt-6 space-y-4" novalidate>
        <input type="hidden" name="csrf" value="<?= e(function_exists('csrf_token') ? csrf_token() : '') ?>">

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
              aria-label="Show or hide password"
            >👁</button>
          </div>
          <p class="mt-1 text-[12px] opacity-60">At least 6 characters. Use a unique password.</p>
        </div>

        <button class="btn-warm w-full">Create account</button>

        <p class="mt-3 text-sm text-neutral-300/80">
          Already have an account?
          <a class="text-amber-300 hover:text-ember-500" href="<?= e(function_exists('base_url') ? base_url('login') : '/login') ?>">Sign in</a>.
        </p>
      </form>
    </div>
  </div>
</section>

<script>
  document.getElementById('pwToggle')?.addEventListener('click', () => {
    const input = document.getElementById('pw');
    if (!input) return;
    input.type = input.type === 'password' ? 'text' : 'password';
  });
</script>
