<?php
/**
 * pages/reset.php
 * Set a new password via token.
 */
declare(strict_types=1);

require_once __DIR__ . '/../app/auth.php';   // provides $config, $pdo, $authPdo, helpers
// No email is sent here; this page just applies a new password.

$token = (string)($_GET['token'] ?? $_POST['token'] ?? '');
$token = trim($token);

$error = '';
$ok    = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (!csrf_check($_POST['csrf'] ?? '')) {
    $error = 'Invalid CSRF token.';
  } elseif (!$pdo || !$authPdo) {
    $error = 'Database not available.';
  } else {
    $p1 = (string)($_POST['password']  ?? '');
    $p2 = (string)($_POST['password2'] ?? '');
    if ($p1 === '' || $p1 !== $p2 || strlen($p1) < 6) {
      $error = 'Passwords must match and be at least 6 characters.';
    } else {
      try {
        // Your existing helper should validate TTL + consume token
        if (reset_password_with_token($config, $pdo, $authPdo, $token, $p1)) {
          $ok = true;
          // Use absolute URL from BASE_URL in .env
          redirect(base_url('login?reset=1'));
          exit;
        } else {
          $error = 'The reset link is invalid or expired.';
        }
      } catch (Throwable $e) {
        $error = 'Failed to reset password.';
      }
    }
  }
}
?>
<section class="container px-4 pt-10 max-w-md">
  <h1 class="h-display text-3xl font-extrabold">Set a new password</h1>

  <?php if ($error): ?>
    <div class="mt-4 rough-card p-3 text-sm text-red-300 border-red-500/30"><?= e($error) ?></div>
  <?php endif; ?>

  <form method="post" class="mt-6 rough-card p-5 space-y-4">
    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
    <input type="hidden" name="token" value="<?= e($token) ?>">

    <div>
      <label class="block text-sm text-neutral-300 mb-1">New password</label>
      <input name="password" type="password" minlength="6" required class="w-full rounded-xl bg-black/30 border border-white/10 px-3 py-2 focus:outline-none focus:ring-2 focus:ring-amber-500"/>
    </div>
    <div>
      <label class="block text-sm text-neutral-300 mb-1">Confirm password</label>
      <input name="password2" type="password" minlength="6" required class="w-full rounded-xl bg-black/30 border border-white/10 px-3 py-2 focus:outline-none focus:ring-2 focus:ring-amber-500"/>
    </div>
    <button class="btn-warm w-full">Update password</button>
  </form>
</section>
