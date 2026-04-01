<?php
/**
 * app/mailer.php
 * SMTP (PHPMailer), mail(), and log drivers with CID-embedded logo + HTTPS fallback.
 * - Auto-embeds logo from $config['mail']['logo_path'] unless recipient is a Microsoft webmail domain
 * - Falls back to $config['mail']['logo_url'] (HTTPS) otherwise
 * - UTF-8 safe headers, Reply-To, and transactional headers
 */
declare(strict_types=1);

/* MIME-safe subject encoding for mail() path */
function _encode_subject_utf8(string $s): string {
  return preg_match('/[^\x20-\x7E]/', $s)
    ? '=?UTF-8?B?' . base64_encode($s) . '?='
    : $s;
}

/* MIME-safe display name for From: in mail() path */
function _encode_name_utf8(string $name): string {
  return preg_match('/[^\x20-\x7E]/', $name)
    ? '=?UTF-8?B?' . base64_encode($name) . '?='
    : $name;
}

/* Detect Microsoft webmail domains (prefer HTTPS images over cid: for these) */
function _is_ms_webmail(string $email): bool {
  $at = strrpos($email, '@');
  if ($at === false) return false;
  $dom = strtolower(substr($email, $at + 1));
  return in_array($dom, [
    'outlook.com', 'hotmail.com', 'live.com', 'msn.com',
    // add your variations if needed (e.g., outlook.se etc.)
  ], true);
}

/**
 * Send an email using configured driver.
 *
 * @param array  $config   App config with ['mail' => [...]] from .env
 * @param string $to       Recipient email
 * @param string $subject  Subject line (UTF-8 ok)
 * @param string $html     HTML body produced by mail_render() (contains {{LOGO_SRC}} placeholder)
 * @param array  $opts     Optional: [
 *                           'reply_to'=>['email','name'],
 *                           'attachments'=>[['path'=>'','name'=>'']],
 *                           'headers'=>['Header-Name'=>'value'],
 *                           'force_embed'=>bool   // override domain-based decision
 *                         ]
 * @return bool
 */
function mail_send(array $config, string $to, string $subject, string $html, array $opts = []): bool {
  $m         = $config['mail'] ?? [];
  $from      = (string)($m['from']      ?? 'support@thorium-reforged.org');
  $fromNm    = (string)($m['from_name'] ?? 'Thorium Reforged');
  $driver    = strtolower((string)($m['driver'] ?? 'log'));
  $replyToE  = (string)($opts['reply_to'][0] ?? ($m['reply_to'] ?? $from));
  $replyToN  = (string)($opts['reply_to'][1] ?? ($m['reply_to_name'] ?? $fromNm));
  $logoURL   = (string)($m['logo_url']  ?? 'https://thorium-reforged.org/assets/Testwebsitelogo.png');
  $logoPath  = (string)($m['logo_path'] ?? '');
  $logBase   = "[driver={$driver}] ";

  // Decide whether to embed or link (Microsoft webmail prefers HTTPS links)
  $forceEmbed     = array_key_exists('force_embed', $opts) ? (bool)$opts['force_embed'] : null;
  $msWebmail      = _is_ms_webmail($to);
  $shouldEmbedCid = ($forceEmbed !== null) ? $forceEmbed : !$msWebmail;

  // Build headers for mail() path (UTF-8)
  $headers  = "MIME-Version: 1.0\r\n";
  $headers .= "Content-Type: text/html; charset=UTF-8\r\n";
  $headers .= "From: " . _encode_name_utf8($fromNm) . " <{$from}>\r\n";
  if ($replyToE) $headers .= "Reply-To: " . _encode_name_utf8($replyToN) . " <{$replyToE}>\r\n";
  // Transactional-friendly hints
  $headers .= "Auto-Submitted: auto-generated\r\n";
  $headers .= "X-Auto-Response-Suppress: All\r\n";
  if (!empty($opts['headers']) && is_array($opts['headers'])) {
    foreach ($opts['headers'] as $hName => $hVal) {
      $headers .= $hName . ': ' . $hVal . "\r\n";
    }
  }

  // --- SMTP via PHPMailer (recommended) ---
  if ($driver === 'smtp') {
    $autoload = __DIR__ . '/../vendor/autoload.php';
    if (!is_file($autoload)) {
      _mail_log($to, $subject, $html, $logBase . "SMTP ERROR: vendor/autoload.php missing (composer install?)");
      return false;
    }
    require_once $autoload;

    try {
      $mail = new PHPMailer\PHPMailer\PHPMailer(true);

      // UTF-8 safe headers & body
      $mail->CharSet  = 'UTF-8';
      $mail->Encoding = 'base64';

      $mail->isSMTP();
      $mail->Host       = (string)($m['host'] ?? '');
      $mail->Port       = (int)   ($m['port'] ?? 587);
      $mail->SMTPAuth   = true;
      $mail->Username   = (string)($m['username'] ?? '');
      $mail->Password   = (string)($m['password'] ?? '');
      $enc              = strtolower((string)($m['encryption'] ?? 'tls'));
      $mail->SMTPSecure = ($enc === 'ssl')
        ? PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS
        : PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;

      // Practical timeouts on Windows
      $mail->Timeout = 12;
      $mail->SMTPKeepAlive = false;

      $mail->setFrom($from, $fromNm);
      $mail->addAddress($to);

      // Reply-To
      if ($replyToE) $mail->addReplyTo($replyToE, $replyToN);

      // Attachments
      if (!empty($opts['attachments']) && is_array($opts['attachments'])) {
        foreach ($opts['attachments'] as $a) {
          if (!empty($a['path']) && is_file($a['path'])) {
            $mail->addAttachment($a['path'], (string)($a['name'] ?? ''));
          }
        }
      }

      // Custom headers
      $mail->addCustomHeader('Auto-Submitted', 'auto-generated');
      $mail->addCustomHeader('X-Auto-Response-Suppress', 'All');
      if (!empty($opts['headers']) && is_array($opts['headers'])) {
        foreach ($opts['headers'] as $hName => $hVal) {
          if ($hName && $hVal) $mail->addCustomHeader((string)$hName, (string)$hVal);
        }
      }

      // Logo handling
      $used = '';
      if ($shouldEmbedCid && $logoPath !== '' && is_file($logoPath)) {
        $ext  = strtolower(pathinfo($logoPath, PATHINFO_EXTENSION));
        $mime = ($ext === 'jpg' || $ext === 'jpeg') ? 'image/jpeg' : (($ext === 'gif') ? 'image/gif' : 'image/png');
        $mail->addEmbeddedImage($logoPath, 'logo', basename($logoPath), 'base64', $mime);
        $html = str_replace('{{LOGO_SRC}}', 'cid:logo', $html);
        $used = "CID embed (path: {$logoPath})";
      } else {
        $html = str_replace('{{LOGO_SRC}}', $logoURL, $html);
        $used = $shouldEmbedCid ? "URL fallback (missing path: {$logoPath})" : "URL (MS webmail detected)";
      }

      $mail->isHTML(true);
      $mail->Subject = $subject; // PHPMailer encodes with UTF-8/base64
      $mail->Body    = $html;
      $mail->AltBody = trim(html_entity_decode(strip_tags($html), ENT_QUOTES, 'UTF-8'));

      $mail->send();
      _mail_log($to, $subject, $html, $logBase . "SMTP OK | Logo: {$used}");
      return true;
    } catch (Throwable $e) {
      $reason = $mail->ErrorInfo ?: $e->getMessage();
      _mail_log($to, $subject, $html, $logBase . "SMTP ERROR: {$reason}");
      return false;
    }
  }

  // --- PHP's mail() ---
  if ($driver === 'mail') {
    // In mail() path we can’t embed; replace placeholder with URL
    $bodyHtml = str_replace('{{LOGO_SRC}}', $logoURL, $html);
    $ok = @mail($to, _encode_subject_utf8($subject), $bodyHtml, $headers);
    _mail_log($to, $subject, $bodyHtml, $logBase . ($ok ? "mail() OK (logo URL)" : "mail() ERROR (logo URL)"));
    return $ok;
  }

  // --- Log-only (default safe fallback) ---
  _mail_log($to, $subject, str_replace('{{LOGO_SRC}}', $logoURL, $html), $logBase . "LOG ONLY (logo URL)");
  return true;
}

/**
 * Render a simple HTML email with brand header/footer.
 * Uses {{LOGO_SRC}} placeholder that mail_send() will replace with cid:logo or your HTTPS logo URL.
 */
function mail_render(string $title, string $body): string {
  $safeTitle = htmlspecialchars($title, ENT_QUOTES, 'UTF-8');
  $logoSrc   = '{{LOGO_SRC}}';

  return <<<HTML
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="color-scheme" content="light dark">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>{$safeTitle}</title>
<style>
  body{margin:0;background:#0b0b0b;color:#e6e6e6;font-family:system-ui,-apple-system,Segoe UI,Roboto,Ubuntu,Cantarell,'Helvetica Neue',Arial,sans-serif}
  .wrap{max-width:560px;margin:0 auto;padding:24px}
  .card{background:#141414;border:1px solid #2a2a2a;border-radius:12px;overflow:hidden}
  .hdr{padding:18px 20px;text-align:center;background:linear-gradient(135deg,#0e1b12,#173a24)}
  .hdr img{max-width:180px;height:auto;display:inline-block}
  .content{padding:20px}
  .content h2{margin:0 0 10px;font-size:16px}
  .content p{margin:0 0 10px;line-height:1.5}
  a.btn{display:inline-block;margin:8px 0 0;padding:10px 14px;text-decoration:none;border-radius:10px;border:1px solid #2c8d5a}
  a.btn{background:#1a6b43;color:#fff}
  .ftr{padding:14px 20px;font-size:12px;color:#a6a6a6;border-top:1px solid #222;text-align:center}
  @media (prefers-color-scheme: light){
    body{background:#f4f4f4;color:#111}
    .card{background:#fff;border-color:#e5e5e5}
    .hdr{background:linear-gradient(135deg,#dff5e8,#cbead9)}
    a.btn{background:#198754;border-color:#157347}
  }
</style>
</head>
<body>
  <div class="wrap">
    <div class="card">
      <div class="hdr"><img src="{$logoSrc}" alt="Thorium Reforged Logo" width="180" height="auto"></div>
      <div class="content">
        <h2>{$safeTitle}</h2>
        {$body}
      </div>
      <div class="ftr">© 2025 Thorium Reforged. You received this because your email is associated with a Thorium Reforged account.</div>
    </div>
  </div>
</body>
</html>
HTML;
}

/**
 * Password reset builder (60-min TTL assumed server side).
 */
function mail_build_reset_email(string $username, string $resetUrl): array {
  $user = htmlspecialchars($username, ENT_QUOTES, 'UTF-8');
  $link = htmlspecialchars($resetUrl, ENT_QUOTES, 'UTF-8');

  $subject = 'Thorium Reforged – Password reset';
  $inner   = <<<HTML
<p>Hello {$user},</p>
<p>Use the link below to reset your password (valid for 60 minutes):</p>
<p><a class="btn" href="{$link}">Reset your password</a></p>
<p>If the button doesn’t work, paste this into your browser:<br><code>{$link}</code></p>
<p>If you didn’t request this, you can safely ignore this email.</p>
HTML;

  return [$subject, mail_render('Reset your password', $inner)];
}

/**
 * Log helper.
 */
function _mail_log(string $to, string $subject, string $html, string $prefix = ''): void {
  $dir = __DIR__ . '/../storage';
  if (!is_dir($dir)) @mkdir($dir, 0775, true);
  $entry  = date('c') . " | TO: {$to} | SUBJ: {$subject}\n";
  if ($prefix) $entry .= $prefix . "\n";
  $entry .= $html . "\n\n---\n";
  @file_put_contents($dir . '/mail.log', $entry, FILE_APPEND);
}
