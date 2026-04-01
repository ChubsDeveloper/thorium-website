<?php
/**
 * pages/forgot.php
 * Page template - renders the forgot page
 */

// pages/forgot.php
// Page template - renders the forgot page

require_once __DIR__ . '/../app/auth.php';

$sent = false; $error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (!csrf_check($_POST['csrf'] ?? '')) {
    $error = 'Invalid CSRF token.';
  } elseif (!$pdo || !$authPdo) {
    $error = 'Database not available.';
  } else {
    $idn = trim($_POST['identifier'] ?? '');
    if ($idn === '') {
      $error = 'Please enter your username or email.';
    } else {
      request_password_reset($config, $pdo, $authPdo, $idn);
      $sent = true;
    }
  }
}
?>
<section class="container px-4 pt-10 max-w-md">
  <h1 class="h-display text-3xl font-extrabold">Forgot password</h1>
  <p class="mt-1 muted">Enter your username or email. If an account exists, we’ll send a reset link.</p>

  <?php if ($sent): ?>
    <div class="mt-4 rough-card p-3 text-sm text-amber-300 border-amber-500/30">
      If an account exists for that identifier, a reset link has been sent.
    </div>
  <?php elseif ($error): ?>
    <div class="mt-4 rough-card p-3 text-sm text-red-300 border-red-500/30"><?= e($error) ?></div>
  <?php endif; ?>

  <form method="post" class="mt-6 rough-card p-5 space-y-4">
    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
    <div>
      <label class="block text-sm text-neutral-300 mb-1">Username or Email</label>
      <input name="identifier" class="w-full rounded-xl bg-black/30 border border-white/10 px-3 py-2 focus:outline-none focus:ring-2 focus:ring-amber-500" />
    </div>
    <button class="btn-warm w-full">Send reset link</button>
  </form>

  <p class="mt-4 text-sm"><a class="text-amber-300 hover:text-ember-500" href="<?= e(base_url('forgot-username')) ?>">Forgot username?</a></p>
</section>
