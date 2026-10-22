<?php
// ============================================================
//  api/mailer.php — email helpers (library, not an endpoint)
//  Branded HTML template (emailBase) + native SMTP sender (sendSMTP).
//  Used by api/email.php (admin-triggered emails) and api/otp.php.
//  Settings: SMTP_HOST / SMTP_PORT / SMTP_USER / SMTP_PASS in hs-secrets.php.
// ============================================================
require_once __DIR__ . '/config.php';

if (!defined('FROM_NAME'))   define('FROM_NAME',   'HamaraService');
if (!defined('FROM_EMAIL'))  define('FROM_EMAIL',  SMTP_USER ?: 'info@hamaraservice.com');
if (!defined('ADMIN_EMAIL')) define('ADMIN_EMAIL', SMTP_USER ?: 'info@hamaraservice.com');
if (!defined('SITE_URL'))    define('SITE_URL',    'https://hamaraservice.com');

// ════════════════════════════════════════════════════════════════
// HTML TEMPLATE HELPERS
// ════════════════════════════════════════════════════════════════

function emailBase($title, $content) {
  return '<!DOCTYPE html><html><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>' . esc($title) . '</title></head>
<body style="margin:0;padding:0;background:#f3f4f6;font-family:\'DM Sans\',Arial,sans-serif;">
<table width="100%" cellpadding="0" cellspacing="0" style="background:#f3f4f6;padding:32px 16px;">
  <tr><td align="center">
    <table width="100%" style="max-width:560px;">
      <!-- Header -->
      <tr><td style="background:linear-gradient(135deg,#E8251A,#c41d13);border-radius:16px 16px 0 0;padding:28px 32px;text-align:center;">
        <div style="font-size:28px;font-weight:900;color:white;letter-spacing:-1px;">🏠 HamaraService</div>
        <div style="font-size:13px;color:rgba(255,255,255,.75);margin-top:4px;">Your Home Service Partner</div>
      </td></tr>
      <!-- Body -->
      <tr><td style="background:white;padding:32px;">
        <h2 style="font-size:20px;font-weight:800;color:#0f1117;margin:0 0 20px;">' . $title . '</h2>
        ' . $content . '
      </td></tr>
      <!-- Footer -->
      <tr><td style="background:#f7f8fc;border-radius:0 0 16px 16px;padding:20px 32px;text-align:center;">
        <p style="font-size:12px;color:#9ca3af;margin:0;">
          © ' . date('Y') . ' HamaraService · <a href="' . SITE_URL . '" style="color:#E8251A;text-decoration:none;">hamaraservice.com</a><br/>
          info@hamaraservice.com · +91 93462 20090
        </p>
      </td></tr>
    </table>
  </td></tr>
</table>
</body></html>';
}

function ctaButton($text, $url) {
  return '<div style="text-align:center;margin:24px 0;">
    <a href="' . esc($url) . '" style="display:inline-block;padding:14px 32px;background:#E8251A;color:white;text-decoration:none;border-radius:12px;font-size:15px;font-weight:700;letter-spacing:.2px;">' . esc($text) . '</a>
  </div>';
}

function detailRow($label, $value) {
  if (!$value) return '';
  return '<div style="display:flex;justify-content:space-between;padding:8px 0;border-bottom:1px solid #f3f4f6;font-size:13px;">
    <span style="color:#6b7280;">' . esc($label) . '</span>
    <strong style="color:#0f1117;">' . esc($value) . '</strong>
  </div>';
}

function esc($str) { return htmlspecialchars((string)$str, ENT_QUOTES, 'UTF-8'); }


// ════════════════════════════════════════════════════════════════
// SMTP SEND (native PHP — no PHPMailer needed)
// ════════════════════════════════════════════════════════════════

function smtp_read($sock) {
  $buf = '';
  while (!feof($sock)) {
    $line = fgets($sock, 515);
    if ($line === false) break;
    $buf .= $line;
    if (strlen($line) < 4 || $line[3] === ' ') break;
  }
  return $buf;
}

function smtp_send($sock, $cmd) {
  fwrite($sock, $cmd . "\r\n");
  return smtp_read($sock);
}

// Is outgoing mail possible at all? (SMTP credentials, the server's own
// mail() as a fallback, or the test log file)
function smtpConfigured() {
  return SMTP_LOG_FILE !== '' || (SMTP_USER !== '' && SMTP_PASS !== '') || mailFallbackAvailable();
}

function mailFallbackAvailable() {
  return SMTP_LOG_FILE === '' && function_exists('mail') && !in_array('mail', array_map('trim', explode(',', (string)ini_get('disable_functions'))), true);
}

// One SMTP session. $secure: 'tls' = STARTTLS on a plain socket (587),
// 'ssl' = implicit TLS (465). Returns ['success'=>true] or ['error'=>…].
function smtpSession($secure, $host, $port, $user, $pass, $from, $toEmail, $data) {
  $errno = 0; $errstr = '';
  $ctx  = stream_context_create(['ssl' => ['SNI_enabled' => true, 'peer_name' => $host]]);
  $sock = @stream_socket_client(($secure === 'ssl' ? 'ssl' : 'tcp') . "://$host:$port", $errno, $errstr, 15, STREAM_CLIENT_CONNECT, $ctx);
  if (!$sock) return ['error' => "SMTP connect to $host:$port failed: $errstr ($errno)"];
  stream_set_timeout($sock, 15);

  $greet = smtp_read($sock);                                             // 220 greeting
  if (strpos($greet, '220') !== 0) { fclose($sock); return ['error' => "SMTP $port greeting: " . trim($greet)]; }
  smtp_send($sock, "EHLO hamaraservice.com");
  if ($secure === 'tls') {
    smtp_send($sock, "STARTTLS");
    if (!@stream_socket_enable_crypto($sock, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
      fclose($sock);
      return ['error' => "SMTP $port TLS failed"];
    }
    smtp_send($sock, "EHLO hamaraservice.com");                          // Re-EHLO after TLS
  }
  smtp_send($sock, "AUTH LOGIN");
  smtp_send($sock, base64_encode($user));
  $authResp = smtp_send($sock, base64_encode($pass));
  if (strpos($authResp, '235') === false) {
    fclose($sock);
    return ['error' => 'SMTP login failed for ' . $user . ' (' . trim($authResp) . '). Check SMTP_USER / SMTP_PASS in hs-secrets.php', 'auth' => true];
  }

  $mf = smtp_send($sock, "MAIL FROM:<$from>");
  if (strpos($mf, '250') !== 0) { smtp_send($sock, "QUIT"); fclose($sock); return ['error' => 'SMTP sender rejected: ' . trim($mf)]; }
  $rcpt = smtp_send($sock, "RCPT TO:<$toEmail>");
  if (strpos($rcpt, '25') !== 0) {
    smtp_send($sock, "QUIT");
    fclose($sock);
    return ['error' => 'SMTP recipient rejected: ' . trim($rcpt)];
  }
  smtp_send($sock, "DATA");
  // dot-stuffing (RFC 5321 §4.5.2) — base64 bodies never start with '.', headers might
  fwrite($sock, preg_replace('/^\./m', '..', $data) . "\r\n.\r\n");
  $dataResp = smtp_read($sock);
  smtp_send($sock, "QUIT");
  fclose($sock);
  if (strpos($dataResp, '250') !== false) return ['success' => true];
  return ['error' => 'SMTP send failed: ' . trim($dataResp)];
}

// Returns ['success'=>true, …] or ['error'=>'…'] (internal detail — log it,
// don't show it to end users).
function sendSMTP($toEmail, $toName, $subject, $htmlBody) {
  if (!$toEmail || !filter_var($toEmail, FILTER_VALIDATE_EMAIL)) {
    return ['error' => 'Invalid email: ' . $toEmail];
  }

  $host   = SMTP_HOST;
  $port   = SMTP_PORT;
  $user   = SMTP_USER;
  $pass   = SMTP_PASS;
  $from   = FROM_EMAIL;
  $fromN  = FROM_NAME;

  // Build RFC 2822 message
  $boundary = md5(uniqid((string)mt_rand(), true));
  $msgId    = '<' . uniqid() . '@hamaraservice.com>';
  $date     = date('r');

  $headers  = "Date: $date\r\n";
  $headers .= "Message-ID: $msgId\r\n";
  $headers .= "From: =?UTF-8?B?" . base64_encode($fromN) . "?= <$from>\r\n";
  $headers .= "To: =?UTF-8?B?" . base64_encode($toName) . "?= <$toEmail>\r\n";
  $headers .= "Reply-To: $from\r\n";
  $headers .= "Subject: =?UTF-8?B?" . base64_encode($subject) . "?=\r\n";
  $headers .= "MIME-Version: 1.0\r\n";
  $headers .= "Content-Type: multipart/alternative; boundary=\"$boundary\"\r\n";

  $textBody = preg_replace('/<(style|title)[^>]*>.*?<\/\1>/is', '', $htmlBody);
  $textBody = strip_tags(preg_replace('/<br\s*\/?>/i', "\n", $textBody));
  $textBody = html_entity_decode($textBody, ENT_QUOTES, 'UTF-8');
  $textBody = preg_replace('/[ \t]+/', ' ', $textBody);
  $textBody = preg_replace('/\s*\n\s*/', "\n", trim($textBody));

  $message  = "--$boundary\r\n";
  $message .= "Content-Type: text/plain; charset=UTF-8\r\n";
  $message .= "Content-Transfer-Encoding: base64\r\n\r\n";
  $message .= chunk_split(base64_encode($textBody)) . "\r\n";
  $message .= "--$boundary\r\n";
  $message .= "Content-Type: text/html; charset=UTF-8\r\n";
  $message .= "Content-Transfer-Encoding: base64\r\n\r\n";
  $message .= chunk_split(base64_encode($htmlBody)) . "\r\n";
  $message .= "--$boundary--\r\n";

  // TESTING ONLY (config SMTP_LOG_FILE): write instead of sending
  if (SMTP_LOG_FILE !== '') {
    $entry = json_encode(['to' => $toEmail, 'subject' => $subject, 'text' => $textBody,
                          'html' => $htmlBody, 'at' => date('c')]) . "\n";
    if (@file_put_contents(SMTP_LOG_FILE, $entry, FILE_APPEND | LOCK_EX) === false) {
      return ['error' => 'SMTP log file not writable'];
    }
    return ['success' => true, 'to' => $toEmail, 'subject' => $subject];
  }
  $errors = [];
  if ($user !== '' && $pass !== '') {
    $data = $headers . "\r\n" . $message;
    // 587 + STARTTLS first, then 465 (implicit TLS) — some hosts block one of them
    $r = smtpSession('tls', $host, $port, $user, $pass, $from, $toEmail, $data);
    if (empty($r['success']) && empty($r['auth']) && $port != 465) {
      $errors[] = $r['error'];
      $r = smtpSession('ssl', $host, 465, $user, $pass, $from, $toEmail, $data);
    }
    if (!empty($r['success'])) return ['success' => true, 'to' => $toEmail, 'subject' => $subject, 'via' => 'smtp'];
    $errors[] = $r['error'];
  } else {
    $errors[] = 'SMTP not configured (SMTP_USER / SMTP_PASS empty in hs-secrets.php)';
  }

  // Fallback: the web server's own mail() (works on Hostinger without a password)
  if (mailFallbackAvailable()) {
    $mh = preg_replace('/^(To|Subject):.*\r\n/mi', '', $headers);
    $ok = @mail($toEmail, "=?UTF-8?B?" . base64_encode($subject) . "?=", $message, rtrim($mh, "\r\n"), '-f' . $from);
    if ($ok) return ['success' => true, 'to' => $toEmail, 'subject' => $subject, 'via' => 'mail', 'smtp_error' => implode(' | ', $errors)];
    $errors[] = 'PHP mail() failed';
  }
  return ['error' => implode(' | ', $errors)];
}
