<?php
// ============================================================
//  api/email.php
//  HamaraService Email System
//  Handles all transactional emails via Hostinger SMTP
//
//  SETUP: Fill in your email password below (line ~20)
//  Then upload to /public_html/api/email.php
// ============================================================

// ── CONFIG ────────────────────────────────────────────────────
define('SMTP_HOST',   'smtp.hostinger.com');
define('SMTP_PORT',   587);
define('SMTP_USER',   'support@hamaraservice.in');
define('SMTP_PASS',   'Simhadriappanna@143');  // ← fill this
define('FROM_NAME',   'HamaraService');
define('FROM_EMAIL',  'support@hamaraservice.in');
define('ADMIN_EMAIL', 'support@hamaraservice.in');   // admin gets notified here
define('SITE_URL',    'https://hamaraservice.in');

// ── CORS ──────────────────────────────────────────────────────
$allowed = ['https://hamaraservice.in','https://www.hamaraservice.in','http://hamaraservice.in','http://www.hamaraservice.in'];
$origin  = $_SERVER['HTTP_ORIGIN'] ?? '';
header('Access-Control-Allow-Origin: ' . (in_array($origin, $allowed) ? $origin : $allowed[0]));
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('Content-Type: application/json');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST')    { http_response_code(405); echo json_encode(['error'=>'Method not allowed']); exit; }

// ── INPUT ─────────────────────────────────────────────────────
$body = json_decode(file_get_contents('php://input'), true);
$type = $body['type'] ?? '';

switch ($type) {

  // 1. Customer welcome email after registration
  case 'customer_welcome':
    $result = sendCustomerWelcome(
      $body['name']  ?? 'Customer',
      $body['email'] ?? ''
    );
    break;

  // 2. Service provider welcome email after registration (pending)
  case 'provider_welcome':
    $result = sendProviderWelcome(
      $body['name']  ?? 'Provider',
      $body['email'] ?? '',
      $body['id']    ?? '',
      $body['service'] ?? ''
    );
    break;

  // 3. Admin notification — new provider needs approval
  case 'admin_provider_pending':
    $result = sendAdminProviderPending(
      $body['name']    ?? '',
      $body['email']   ?? '',
      $body['id']      ?? '',
      $body['phone']   ?? '',
      $body['service'] ?? '',
      $body['city']    ?? ''
    );
    break;

  // 4. Provider approved — send access email
  case 'provider_approved':
    $result = sendProviderApproved(
      $body['name']  ?? 'Provider',
      $body['email'] ?? '',
      $body['id']    ?? ''
    );
    break;

  default:
    http_response_code(400);
    echo json_encode(['error' => 'Unknown email type: '.$type]);
    exit;
}

echo json_encode($result);


// ════════════════════════════════════════════════════════════════
// EMAIL BUILDERS
// ════════════════════════════════════════════════════════════════

function sendCustomerWelcome($name, $email) {
  if (!$email) return ['error' => 'No email address'];

  $subject = '🎉 Welcome to HamaraService, ' . $name . '!';

  $html = emailBase('Welcome to HamaraService!', '
    <p style="font-size:16px;color:#374151;margin:0 0 20px;">Hi <strong>' . esc($name) . '</strong>,</p>
    <p style="color:#6b7280;line-height:1.7;margin:0 0 20px;">
      Welcome to <strong style="color:#E8251A;">HamaraService</strong>! 🏠<br/>
      Your account is ready. You can now book trusted home service professionals near you.
    </p>
    <div style="background:#f7f8fc;border-radius:14px;padding:20px;margin:0 0 24px;">
      <div style="font-size:13px;font-weight:700;color:#6b7280;text-transform:uppercase;letter-spacing:.5px;margin-bottom:14px;">What you can do</div>
      <div style="display:flex;align-items:flex-start;gap:12px;margin-bottom:12px;">
        <span style="font-size:22px;">🧹</span>
        <div><strong style="color:#0f1117;">Book Services</strong><br/><span style="font-size:13px;color:#6b7280;">House maid, AC cleaning, plumbing and 20+ services</span></div>
      </div>
      <div style="display:flex;align-items:flex-start;gap:12px;margin-bottom:12px;">
        <span style="font-size:22px;">📍</span>
        <div><strong style="color:#0f1117;">Track in Real Time</strong><br/><span style="font-size:13px;color:#6b7280;">See your professional on the way to your door</span></div>
      </div>
      <div style="display:flex;align-items:flex-start;gap:12px;">
        <span style="font-size:22px;">⭐</span>
        <div><strong style="color:#0f1117;">Rate & Review</strong><br/><span style="font-size:13px;color:#6b7280;">Help the community find great professionals</span></div>
      </div>
    </div>
    ' . ctaButton('Book a Service Now', SITE_URL . '/user-booking.html') . '
    <p style="font-size:13px;color:#9ca3af;margin:24px 0 0;text-align:center;">
      Need help? Reply to this email or call us at <strong>8985849710</strong>
    </p>
  ');

  return sendSMTP($email, $name, $subject, $html);
}


function sendProviderWelcome($name, $email, $id, $service) {
  if (!$email) return ['error' => 'No email address'];

  $subject = '✅ Application Received — HamaraService';

  $html = emailBase('Application Received!', '
    <p style="font-size:16px;color:#374151;margin:0 0 20px;">Hi <strong>' . esc($name) . '</strong>,</p>
    <p style="color:#6b7280;line-height:1.7;margin:0 0 20px;">
      Thank you for applying to join <strong style="color:#E8251A;">HamaraService</strong> as a service professional!<br/>
      Your application has been received and is under review by our admin team.
    </p>
    <div style="background:#fff7ed;border:2px solid #fed7aa;border-radius:14px;padding:20px;margin:0 0 24px;">
      <div style="display:flex;align-items:center;gap:10px;margin-bottom:14px;">
        <span style="font-size:24px;">⏳</span>
        <strong style="font-size:15px;color:#92400e;">Pending Approval</strong>
      </div>
      <p style="font-size:14px;color:#78350f;margin:0 0 12px;">Our team will review your application and respond within <strong>24–48 hours</strong>.</p>
      <div style="background:white;border-radius:10px;padding:14px;">
        <div style="display:flex;justify-content:space-between;padding:6px 0;border-bottom:1px solid #f3f4f6;font-size:13px;">
          <span style="color:#6b7280;">Provider ID</span><strong style="color:#6c4ff8;">' . esc($id) . '</strong>
        </div>
        <div style="display:flex;justify-content:space-between;padding:6px 0;border-bottom:1px solid #f3f4f6;font-size:13px;">
          <span style="color:#6b7280;">Service</span><strong>' . esc($service) . '</strong>
        </div>
        <div style="display:flex;justify-content:space-between;padding:6px 0;font-size:13px;">
          <span style="color:#6b7280;">Status</span><strong style="color:#f59e0b;">Pending Review</strong>
        </div>
      </div>
    </div>
    <div style="background:#f0fdf4;border:2px solid #bbf7d0;border-radius:14px;padding:16px;margin:0 0 24px;">
      <strong style="font-size:13px;color:#166534;">📞 Need faster approval?</strong>
      <p style="font-size:13px;color:#15803d;margin:6px 0 0;">
        Call or WhatsApp us at <strong>8985849710</strong> with your Provider ID <strong>' . esc($id) . '</strong>
      </p>
    </div>
    <p style="font-size:13px;color:#9ca3af;text-align:center;margin:0;">
      You will receive another email once your account is approved.
    </p>
  ');

  return sendSMTP($email, $name, $subject, $html);
}


function sendAdminProviderPending($name, $email, $id, $phone, $service, $city) {
  $subject = '🔔 New Provider Registration — Approval Required';

  $portalUrl = SITE_URL . '/admin.html';

  $html = emailBase('New Provider Needs Approval', '
    <p style="font-size:16px;color:#374151;margin:0 0 20px;">A new service provider has registered and is waiting for your approval.</p>
    <div style="background:#f7f8fc;border-radius:14px;padding:20px;margin:0 0 24px;">
      <div style="font-size:13px;font-weight:700;color:#6b7280;text-transform:uppercase;letter-spacing:.5px;margin-bottom:14px;">Provider Details</div>
      ' . detailRow('Full Name',   $name)    . '
      ' . detailRow('Email',       $email)   . '
      ' . detailRow('Phone',       $phone)   . '
      ' . detailRow('Provider ID', $id)      . '
      ' . detailRow('Service',     $service) . '
      ' . detailRow('City',        $city)    . '
    </div>
    ' . ctaButton('Review & Approve in Admin Panel', $portalUrl) . '
    <p style="font-size:13px;color:#9ca3af;margin:24px 0 0;text-align:center;">
      Log in to the admin panel to approve, reject or request more info.
    </p>
  ');

  return sendSMTP(ADMIN_EMAIL, 'Admin', $subject, $html);
}


function sendProviderApproved($name, $email, $id) {
  if (!$email) return ['error' => 'No email address'];

  $subject = '🎉 You\'re Approved! Start Accepting Jobs — HamaraService';

  $html = emailBase('You\'re Approved!', '
    <p style="font-size:16px;color:#374151;margin:0 0 20px;">Hi <strong>' . esc($name) . '</strong>,</p>
    <p style="color:#6b7280;line-height:1.7;margin:0 0 20px;">
      Great news! Your application has been <strong style="color:#16a34a;">approved</strong> by HamaraService. 🎉<br/>
      You can now log in to your provider dashboard and start accepting jobs!
    </p>
    <div style="background:#f0fdf4;border:2px solid #bbf7d0;border-radius:14px;padding:20px;margin:0 0 24px;">
      <div style="display:flex;align-items:center;gap:10px;margin-bottom:16px;">
        <span style="font-size:28px;">✅</span>
        <strong style="font-size:16px;color:#166534;">Account Activated</strong>
      </div>
      <div style="background:white;border-radius:10px;padding:14px;">
        <div style="display:flex;justify-content:space-between;padding:6px 0;border-bottom:1px solid #f3f4f6;font-size:13px;">
          <span style="color:#6b7280;">Provider ID</span><strong style="color:#6c4ff8;">' . esc($id) . '</strong>
        </div>
        <div style="display:flex;justify-content:space-between;padding:6px 0;font-size:13px;">
          <span style="color:#6b7280;">Status</span><strong style="color:#16a34a;">✅ Approved & Active</strong>
        </div>
      </div>
    </div>
    <div style="background:#f7f8fc;border-radius:14px;padding:20px;margin:0 0 24px;">
      <div style="font-size:13px;font-weight:700;color:#6b7280;text-transform:uppercase;letter-spacing:.5px;margin-bottom:14px;">Getting started</div>
      <div style="display:flex;align-items:flex-start;gap:12px;margin-bottom:12px;">
        <span style="font-size:20px;">1️⃣</span>
        <div><strong style="color:#0f1117;">Log in to your dashboard</strong><br/><span style="font-size:13px;color:#6b7280;">Use your registered email and password</span></div>
      </div>
      <div style="display:flex;align-items:flex-start;gap:12px;margin-bottom:12px;">
        <span style="font-size:20px;">2️⃣</span>
        <div><strong style="color:#0f1117;">Set your location & availability</strong><br/><span style="font-size:13px;color:#6b7280;">Update your service area so customers can find you</span></div>
      </div>
      <div style="display:flex;align-items:flex-start;gap:12px;">
        <span style="font-size:20px;">3️⃣</span>
        <div><strong style="color:#0f1117;">Accept your first job!</strong><br/><span style="font-size:13px;color:#6b7280;">You will get an alert when a customer books near you</span></div>
      </div>
    </div>
    ' . ctaButton('Go to Provider Dashboard', SITE_URL . '/provider-portal.html') . '
    <p style="font-size:13px;color:#9ca3af;margin:24px 0 0;text-align:center;">
      Questions? Call us at <strong>8985849710</strong> or reply to this email.
    </p>
  ');

  return sendSMTP($email, $name, $subject, $html);
}


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
          © ' . date('Y') . ' HamaraService · <a href="' . SITE_URL . '" style="color:#E8251A;text-decoration:none;">hamaraservice.in</a><br/>
          support@hamaraservice.in · 8985849710
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
  $boundary = md5(uniqid(rand(), true));
  $msgId    = '<' . uniqid() . '@hamaraservice.in>';
  $date     = date('r');

  $headers  = "Date: $date\r\n";
  $headers .= "Message-ID: $msgId\r\n";
  $headers .= "From: =?UTF-8?B?" . base64_encode($fromN) . "?= <$from>\r\n";
  $headers .= "To: =?UTF-8?B?" . base64_encode($toName) . "?= <$toEmail>\r\n";
  $headers .= "Reply-To: $from\r\n";
  $headers .= "Subject: =?UTF-8?B?" . base64_encode($subject) . "?=\r\n";
  $headers .= "MIME-Version: 1.0\r\n";
  $headers .= "Content-Type: multipart/alternative; boundary=\"$boundary\"\r\n";

  $textBody = strip_tags(preg_replace('/<br\s*\/?>/i', "\n", $htmlBody));
  $textBody = html_entity_decode($textBody, ENT_QUOTES, 'UTF-8');
  $textBody = preg_replace('/\n{3,}/', "\n\n", trim($textBody));

  $message  = "--$boundary\r\n";
  $message .= "Content-Type: text/plain; charset=UTF-8\r\n";
  $message .= "Content-Transfer-Encoding: base64\r\n\r\n";
  $message .= chunk_split(base64_encode($textBody)) . "\r\n";
  $message .= "--$boundary\r\n";
  $message .= "Content-Type: text/html; charset=UTF-8\r\n";
  $message .= "Content-Transfer-Encoding: base64\r\n\r\n";
  $message .= chunk_split(base64_encode($htmlBody)) . "\r\n";
  $message .= "--$boundary--\r\n";

  // Open SMTP socket
  $errno = 0; $errstr = '';
  $sock = @stream_socket_client("tcp://$host:$port", $errno, $errstr, 15);
  if (!$sock) return ['error' => "SMTP connect failed: $errstr ($errno)"];

  stream_set_timeout($sock, 15);

  function smtp_read($sock) {
    $buf = '';
    while (!feof($sock)) {
      $line = fgets($sock, 515);
      $buf .= $line;
      if (strlen($line) < 4 || $line[3] === ' ') break;
    }
    return $buf;
  }

  function smtp_send($sock, $cmd) {
    fwrite($sock, $cmd . "\r\n");
    return smtp_read($sock);
  }

  smtp_read($sock);                                                      // 220 greeting
  smtp_send($sock, "EHLO hamaraservice.in");                            // EHLO
  smtp_send($sock, "STARTTLS");                                         // STARTTLS
  stream_socket_enable_crypto($sock, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
  smtp_send($sock, "EHLO hamaraservice.in");                            // Re-EHLO after TLS
  smtp_send($sock, "AUTH LOGIN");                                       // AUTH
  smtp_send($sock, base64_encode($user));                               // username
  $authResp = smtp_send($sock, base64_encode($pass));                   // password

  if (strpos($authResp, '235') === false) {
    fclose($sock);
    return ['error' => 'SMTP auth failed. Check email password in api/email.php'];
  }

  smtp_send($sock, "MAIL FROM:<$from>");
  smtp_send($sock, "RCPT TO:<$toEmail>");
  smtp_send($sock, "DATA");
  fwrite($sock, $headers . "\r\n" . $message . "\r\n.\r\n");
  $dataResp = smtp_read($sock);
  smtp_send($sock, "QUIT");
  fclose($sock);

  if (strpos($dataResp, '250') !== false) {
    return ['success' => true, 'to' => $toEmail, 'subject' => $subject];
  }
  return ['error' => 'SMTP send failed: ' . trim($dataResp)];
}
