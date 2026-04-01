<?php
/**
 * pages/forgot_username.php
 * Sends username reminder(s) to a provided email without revealing existence.
 */
declare(strict_types=1);

require_once __DIR__ . '/../app/auth.php';    // $config, $authPdo, helpers
require_once __DIR__ . '/../app/mailer.php';  // mail_send(), mail_render()

$sent  = false;
$error = '';

/**
 * Fetch usernames for an email from the Auth DB.
 * Returns an array of usernames (strings).
 */
function auth_usernames_by_email(PDO $authPdo, string $email): array {
  $sql = "SELECT username FROM account WHERE LOWER(email) = LOWER(:email) LIMIT 50";
  $st  = $authPdo->prepare($sql);
  $st->execute([':email' => $email]);
  $rows = $st->fetchAll(PDO::FETCH_COLUMN, 0);
  if (!is_array($rows)) return [];
  // Normalize to strings
  return array_values(array_filter(array_map('strval', $rows), static fn($u) => $u !== ''));
}

/**
 * Build the username reminder email HTML using mail_render().
 */
function build_username_reminder_html(array $usernames): array {
  $subject = 'Thorium WoW – Username reminder';
  if (empty($usernames)) {
    // Generic message (don’t reveal non-existence)
    $body = '<p>If accounts exist for this email, their usernames are listed below.</p><p>(No matching usernames were found.)</p>';
  } else {
    $items = '';
    foreach ($usernames as $u) {
      $safe = htmlspecialchars($u, ENT_QUOTES, 'UTF-8');
      $items .= "<li><code>{$safe}</code></li>";
    }
    $body = "<p>Here are the usernames associated with this email address:</p><ul>{$items}</ul>";
  }
  $html = mail_render('Your username reminder', $body . '<p>If you didn’t request this, you can ignore this email.</p>');
  return [$subject, $html];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (!csrf_check($_POST['csrf'] ?? '')) {
    $error = 'Invalid CSRF token.';
  } elseif (!$authPdo) {
    $error = 'Database not available.';
  } else {
    $email = trim((string)($_POST['email'] ?? ''));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
      $error = 'Please enter a valid email.';
    } else {
      try {
        // Always behave the same UX-wise to prevent user enumeration
        $usernames = auth_usernames_by_email($authPdo, $email);

        // Build + send email (even if empty list -> generic wording)
        [$subj, $html] = build_username_reminder_html($usernames);
        // MAIL_FROM should be a verified sender at your domain
        mail_send($config, $email, $subj, $html);

        $sent = true;
      } catch (Throwable $e) {
        // Log internally if you have a logger; keep UX generic
        $sent = true; // Still show success to avoid probing
      }
    }
  }
}
?>
<section class="container px-4 pt-10 max-w-md">
  <h1 class="h-display text-3xl font-extrabold">Forgot username</h1>
  <p class="mt-1 muted">Enter your email. If accounts exist, we’ll email their usernames.</p>

  <?php if ($sent): ?>
    <div class="mt-4 rough-card p-3 text-sm text-amber-300 border-amber-500/30">
      If accounts exist for that email, a reminder has been sent.
    </div>
  <?php elseif ($error): ?>
    <div class="mt-4 rough-card p-3 text-sm text-red-300 border-red-500/30"><?= e($error) ?></div>
  <?php endif; ?>

  <form method="post" class="mt-6 rough-card p-5 space-y-4">
    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
    <div>
      <label class="block text-sm text-neutral-300 mb-1">Email</label>
      <input name="email" type="email" required class="w-full rounded-xl bg-black/30 border border-white/10 px-3 py-2 focus:outline-none focus:ring-2 focus:ring-amber-500"/>
    </div>
    <button class="btn-warm w-full">Send reminder</button>
  </form>
</section>
