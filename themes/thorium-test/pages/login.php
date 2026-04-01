<?php
/**
 * themes/thorium-test/pages/login.php
 * Theme page override - customizes page template for this theme
 */

// themes/thorium-emeraldforest/pages/login.php

// Resolve app root from theme page: /themes/<theme>/pages -> up 3 -> / (htdocs)
$APP_ROOT = dirname(__DIR__, 3);
$AUTH = $APP_ROOT . '/app/auth.php';
if (!is_file($AUTH)) {
  http_response_code(500);
  echo "Missing: " . htmlspecialchars($AUTH);
  exit;
}
require_once $AUTH;

$error     = '';
$username  = trim($_POST['username'] ?? '');
$nextParam = $_GET['next'] ?? $_POST['next'] ?? '';

// allow only safe, relative paths like "/panel"
$next = (is_string($nextParam) && $nextParam !== '' && $nextParam[0] === '/' && strpos($nextParam, '://') === false)
  ? $nextParam
  : '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (!csrf_check($_POST['csrf'] ?? '')) {
    $error = 'Invalid CSRF token.';
  } elseif (!$pdo || !$authPdo) {
    $error = 'Database not available.';
  } else {
    $u = $username;
    $p = $_POST['password'] ?? '';
    $user = login_any($pdo, $authPdo, $config, $u, $p, $config['admin_min_security_level']);
    if ($user) {
      redirect($next ? $next : base_url(''));
    }
    $error = 'Invalid username or password.';
  }
}
?>
<section class="container px-4 pt-16 md:pt-56 pb-24 md:pb-40 flex-1">
  <div class="mx-auto max-w-md">
    <div class="rough-card p-6 md:p-8">
      <div class="flex items-center gap-3">
        <div class="w-10 h-10 rounded-xl bg-white/5 ring-1 ring-white/10 grid place-items-center">
          <svg width="22" height="22" viewBox="0 0 24 24" fill="currentColor" class="opacity-90">
            <path d="M17 8V7a5 5 0 0 0-10 0v1H5v13h14V8h-2Zm-8 0V7a3 3 0 0 1 6 0v1H9Zm8 11H7v-9h10v9Z"/>
          </svg>
        </div>
        <div>
          <h1 class="h-display text-2xl md:text-3xl font-extrabold">Sign in</h1>
          <p class="muted mt-0.5">Use your in-game account credentials.</p>
        </div>
      </div>

      <?php if (!empty($_GET['reset'])): ?>
        <div class="mt-4 rounded-lg border border-emerald-500/30 bg-emerald-500/10 px-3 py-2 text-sm text-emerald-300" role="status" aria-live="polite">
          Password updated. Please sign in.
        </div>
      <?php endif; ?>

      <?php if ($error): ?>
        <div class="mt-4 rounded-lg border border-red-500/30 bg-red-500/10 px-3 py-2 text-sm text-red-300" role="alert" aria-live="assertive">
          <?= e($error) ?>
        </div>
      <?php endif; ?>

      <form method="post" class="mt-6 space-y-4" novalidate>
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
        <?php if ($next): ?><input type="hidden" name="next" value="<?= e($next) ?>"><?php endif; ?>

        <div>
          <label for="login-username" class="block text-sm text-neutral-300 mb-1">Username</label>
          <input
            id="login-username"
            name="username"
            value="<?= e($username) ?>"
            autocomplete="username"
            required
            class="w-full rounded-xl bg-black/30 border border-white/10 px-3 py-2 focus:outline-none focus:ring-2 focus:ring-amber-500"
            autofocus
          />
        </div>

        <div>
          <label for="login-password" class="block text-sm text-neutral-300 mb-1">Password</label>
          <div class="relative">
            <input
              id="login-password"
              name="password"
              type="password"
              autocomplete="current-password"
              required
              class="w-full rounded-xl bg-black/30 border border-white/10 px-3 py-2 pr-10 focus:outline-none focus:ring-2 focus:ring-amber-500"
            />
            <!-- Same eye button style as register -->
            <button
              type="button"
              id="pwToggle"
              class="absolute right-2 top-1/2 -translate-y-1/2 text-neutral-300/80 hover:text-neutral-100 transition"
              title="Show/Hide password"
              aria-label="Show or hide password"
            >👁</button>
          </div>
          <div id="capsWarn" class="mt-1 text-xs text-amber-300 hidden">Caps Lock is on.</div>
        </div>

        <button class="btn-warm w-full">Sign in</button>
      </form>

      <div class="mt-4 text-sm flex flex-wrap items-center justify-between gap-2 text-neutral-300">
        <div class="space-x-3">
          <a class="text-amber-300 hover:text-ember-500" href="<?= e(base_url('forgot')) ?>">Forgot password?</a>
          <a class="text-amber-300 hover:text-ember-500" href="<?= e(base_url('forgot-username')) ?>">Forgot username?</a>
        </div>
        <a class="btn-ghost btn-sm" href="<?= e(base_url('register')) ?>">Create account</a>
      </div>
    </div>
  </div>

  <script>
    (() => {
      const pw   = document.getElementById('login-password');
      const btn  = document.getElementById('pwToggle');
      const caps = document.getElementById('capsWarn');

      // Eye icon toggle (same behavior as register)
      btn?.addEventListener('click', () => {
        if (!pw) return;
        pw.type = (pw.type === 'password') ? 'text' : 'password';
      });

      // Caps Lock hint
      const checkCaps = (e) => {
        const on = e.getModifierState && e.getModifierState('CapsLock');
        caps?.classList.toggle('hidden', !on);
      };
      pw?.addEventListener('keyup', checkCaps);
      pw?.addEventListener('keydown', checkCaps);
      pw?.addEventListener('focus', checkCaps);
      pw?.addEventListener('blur', () => caps?.classList.add('hidden'));
    })();
  </script>
</section>
