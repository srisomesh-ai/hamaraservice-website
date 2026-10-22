<?php
// ============================================================
//  api/email.php
//  HamaraService Email System
//  Handles all transactional emails via Hostinger SMTP
//
//  SETUP: Fill in your email password below (line ~20)
//  Then upload to /public_html/api/email.php
// ============================================================

// ── CONFIG (secrets come from ~/hs-secrets.php via config.php) ──
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/mailer.php';   // emailBase(), sendSMTP() …

setCorsHeaders();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') err('Method not allowed', 405);
// Only the admin panel / server may trigger emails (was an open mail relay)
requireAdmin();

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

  // 5. Admin panel "Send test email" — shows the real error so it can be fixed
  case 'test': {
    $to = trim((string)($body['to'] ?? '')) ?: ADMIN_EMAIL;
    $r  = sendSMTP($to, 'HamaraService Admin', 'HamaraService test email', emailBase('Test email',
      '<p style="color:#374151;line-height:1.7;margin:0;">If you can read this, HamaraService emails (registration codes, alerts) are working.</p>'));
    if (empty($r['success'])) err('Email NOT sent: ' . ($r['error'] ?? 'unknown error'), 502);
    ok(['sent' => true, 'to' => $to, 'via' => $r['via'] ?? 'smtp',
        'smtp_configured' => SMTP_USER !== '' && SMTP_PASS !== '', 'smtp_error' => $r['smtp_error'] ?? '']);
  }

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
      Need help? Reply to this email or call us at <strong>+91 93462 20090</strong>
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
        Call or WhatsApp us at <strong>+91 93462 20090</strong> with your Provider ID <strong>' . esc($id) . '</strong>
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
    ' . ctaButton('Go to Provider Dashboard', SITE_URL . '/app/provider.html') . '
    <p style="font-size:13px;color:#9ca3af;margin:24px 0 0;text-align:center;">
      Questions? Call us at <strong>+91 93462 20090</strong> or reply to this email.
    </p>
  ');

  return sendSMTP($email, $name, $subject, $html);
}
