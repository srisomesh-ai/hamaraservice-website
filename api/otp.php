<?php
// ═══════════════════════════════════════════════════════
// HamaraService — Email OTP for registration
//   POST ?action=send   {email, purpose}        → {sent, email, expires_in, resend_in}
//   POST ?action=verify {email, purpose, code}  → {otp_token, email, purpose, expires_in}
// purpose: 'customer_register' | 'provider_register'
// The otp_token (HS256 JWT, 30 min) is sent with customers.php register /
// web_register and providers.php register. Enforced when REQUIRE_EMAIL_OTP.
// The code itself is NEVER returned by the API — only emailed.
// ═══════════════════════════════════════════════════════
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/schema.php';
require_once __DIR__ . '/mailer.php';
require_once __DIR__ . '/otp_lib.php';
setCorsHeaders();

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') err('Method not allowed', 405);

$action = $_GET['action'] ?? '';
$db     = getDB();
ensureSchema($db);

$b       = getBody();
$email   = strtolower(trim((string)($b['email'] ?? '')));
$purpose = (string)($b['purpose'] ?? '');
if ($email === '' || strlen($email) > 190 || !filter_var($email, FILTER_VALIDATE_EMAIL)) err('Enter a valid email');
if (!in_array($purpose, OTP_PURPOSES, true)) err('Invalid purpose');

function otpTooMany($msg, $wait) {
    $wait = max(1, (int)$wait);
    header('Retry-After: ' . $wait);
    http_response_code(429);
    echo json_encode(['success' => false, 'error' => $msg, 'retry_after' => $wait]);
    exit;
}
function otpWaitText($sec) {
    $sec = max(1, (int)$sec);
    if ($sec < 120) return "$sec seconds";
    return ceil($sec / 60) . " minutes ($sec seconds)";
}

switch ($action) {

  case 'send': {
    // Already registered? (same wording as the register actions)
    if ($purpose === 'customer_register') {
      $c = $db->prepare("SELECT id FROM customers WHERE email = ? LIMIT 1");
      $c->execute([$email]);
      if ($c->fetch()) err('Email already registered. Please login.');
    } else {
      $c = $db->prepare("SELECT id FROM providers WHERE email = ? LIMIT 1");
      $c->execute([$email]);
      if ($c->fetch()) err('Email already registered');
    }
    if (!smtpConfigured()) {
      error_log('otp.php: SMTP not configured (SMTP_USER / SMTP_PASS empty)');
      err('Could not send email, please try again', 503);
    }

    $ip  = substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45);
    $now = time();
    $hourAgo = date('Y-m-d H:i:s', $now - 3600);

    // Serialise sends for this email so parallel requests can't skip the limits
    $lock = 'hs_otp_' . md5($email);
    $db->prepare("SELECT GET_LOCK(?, 5)")->execute([$lock]);
    try {
      $db->prepare("DELETE FROM email_otps WHERE created_at < ?")->execute([date('Y-m-d H:i:s', $now - 86400)]);

      // 1. resend gap per email + purpose
      $q = $db->prepare("SELECT MAX(created_at) FROM email_otps WHERE email = ? AND purpose = ?");
      $q->execute([$email, $purpose]);
      $last = $q->fetchColumn();
      if ($last && ($gap = $now - strtotime($last)) < OTP_RESEND_SEC) {
        $w = OTP_RESEND_SEC - $gap;
        otpTooMany('Please wait ' . otpWaitText($w) . ' before requesting a new code.', $w);
      }
      // 2. per email per hour
      $q = $db->prepare("SELECT COUNT(*), MIN(created_at) FROM email_otps WHERE email = ? AND created_at > ?");
      $q->execute([$email, $hourAgo]);
      [$n, $first] = $q->fetch(PDO::FETCH_NUM);
      if ((int)$n >= OTP_MAX_PER_EMAIL) {
        $w = strtotime($first) + 3600 - $now;
        otpTooMany('Too many codes requested for this email. Please try again in ' . otpWaitText($w) . '.', $w);
      }
      // 3. per IP per hour
      $q = $db->prepare("SELECT COUNT(*), MIN(created_at) FROM email_otps WHERE ip = ? AND created_at > ?");
      $q->execute([$ip, $hourAgo]);
      [$n, $first] = $q->fetch(PDO::FETCH_NUM);
      if ((int)$n >= OTP_MAX_PER_IP) {
        $w = strtotime($first) + 3600 - $now;
        otpTooMany('Too many verification requests from your network. Please try again in ' . otpWaitText($w) . '.', $w);
      }

      $code = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
      $db->prepare("INSERT INTO email_otps (email, purpose, code_hash, expires_at, attempts, ip, created_at)
                    VALUES (?, ?, ?, ?, 0, ?, ?)")
         ->execute([$email, $purpose, otpHash($email, $purpose, $code),
                    date('Y-m-d H:i:s', $now + OTP_TTL_SEC), $ip, date('Y-m-d H:i:s', $now)]);
      $rowId = (int)$db->lastInsertId();
    } finally {
      $db->prepare("SELECT RELEASE_LOCK(?)")->execute([$lock]);
    }

    $who  = $purpose === 'provider_register' ? 'partner' : '';
    $html = emailBase('Verify your email', '
      <p style="font-size:16px;color:#374151;margin:0 0 16px;">Hi,</p>
      <p style="color:#6b7280;line-height:1.7;margin:0 0 20px;">
        Use this code to verify your email and finish creating your
        <strong style="color:#E8251A;">HamaraService</strong> ' . $who . ' account:
      </p>
      <div style="background:#f7f8fc;border:2px dashed #E8251A;border-radius:14px;padding:18px;margin:0 0 20px;text-align:center;">
        <div style="font-size:13px;font-weight:700;color:#6b7280;text-transform:uppercase;letter-spacing:.5px;margin-bottom:8px;">Your HamaraService verification code</div>
        <div style="font-size:34px;font-weight:900;letter-spacing:10px;color:#0f1117;font-family:\'Courier New\',monospace;">' . $code . '</div>
        <div style="font-size:13px;color:#6b7280;margin-top:8px;">Valid for 10 minutes</div>
      </div>
      <p style="font-size:13px;color:#9ca3af;line-height:1.6;margin:0;">
        If you didn\'t request this, you can safely ignore this email — no account will be created.
        Never share this code with anyone; HamaraService staff will never ask for it.
      </p>');
    $res = sendSMTP($email, $email, "Your HamaraService verification code: $code", $html);
    if (empty($res['success'])) {
      error_log('otp.php send failed: ' . ($res['error'] ?? 'unknown'));
      // Don't count a failed send against the user's limits
      $db->prepare("DELETE FROM email_otps WHERE id = ?")->execute([$rowId]);
      err('Could not send email, please try again', 503);
    }
    ok(['sent' => true, 'email' => $email, 'expires_in' => OTP_TTL_SEC, 'resend_in' => OTP_RESEND_SEC]);
  }

  case 'verify': {
    $code = preg_replace('/\D/', '', (string)($b['code'] ?? ''));
    if (strlen($code) !== 6) err('Enter the 6-digit code');

    $q = $db->prepare("SELECT * FROM email_otps WHERE email = ? AND purpose = ? ORDER BY id DESC LIMIT 1");
    $q->execute([$email, $purpose]);
    $row = $q->fetch();
    if (!$row) err('No code found for this email. Please request a new code.');
    if (!empty($row['used_at'])) err('This code was already used. Please request a new code.');
    if (strtotime($row['expires_at']) < time()) err('Code expired. Please request a new code.');
    if ((int)$row['attempts'] >= OTP_MAX_ATTEMPTS) otpTooMany('Too many wrong attempts. Please request a new code.', 1);

    // Count the attempt atomically (parallel guesses can't exceed the limit)
    $u = $db->prepare("UPDATE email_otps SET attempts = attempts + 1 WHERE id = ? AND attempts < ? AND used_at IS NULL");
    $u->execute([$row['id'], OTP_MAX_ATTEMPTS]);
    if ($u->rowCount() !== 1) otpTooMany('Too many wrong attempts. Please request a new code.', 1);

    if (!hash_equals($row['code_hash'], otpHash($email, $purpose, $code))) {
      $left = OTP_MAX_ATTEMPTS - ((int)$row['attempts'] + 1);
      if ($left <= 0) otpTooMany('Too many wrong attempts. Please request a new code.', 1);
      err('Incorrect code. ' . $left . ($left === 1 ? ' attempt' : ' attempts') . ' left.');
    }
    $m = $db->prepare("UPDATE email_otps SET used_at = ? WHERE id = ? AND used_at IS NULL");
    $m->execute([date('Y-m-d H:i:s'), $row['id']]);
    if ($m->rowCount() !== 1) err('This code was already used. Please request a new code.');

    ok(['otp_token' => otpMakeToken($email, $purpose), 'email' => $email,
        'purpose' => $purpose, 'expires_in' => OTP_TOKEN_TTL_SEC]);
  }

  default:
    err('Invalid action');
}
