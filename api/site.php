<?php
// ═══════════════════════════════════════════════════════
// HamaraService — public site settings (no secrets)
//   GET ?action=config → support contacts, app links, web login mode,
//                      email_otp_required
// Values come from ~/hs-secrets.php so they change without a code release.
// ═══════════════════════════════════════════════════════
require_once __DIR__ . '/db.php';
setCorsHeaders();
header('Cache-Control: public, max-age=300');

ok([
  'support_whatsapp' => SUPPORT_WHATSAPP,
  'support_email'    => SUPPORT_EMAIL,
  'customer_app_url' => CUSTOMER_APP_URL,
  'provider_app_url' => PROVIDER_APP_URL,
  'web_login_mode'   => WEB_LOGIN_MODE === 'web' ? 'web' : 'app',
  'email_otp_required' => REQUIRE_EMAIL_OTP,
]);
