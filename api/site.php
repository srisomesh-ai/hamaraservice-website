<?php
// ═══════════════════════════════════════════════════════
// HamaraService — public site settings (no secrets)
//   GET ?action=config → support contacts, app links (Play / App Store), web login
//                      mode, devices allowed on the web apps, email_otp_required
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
  'web_access'       => WEB_ACCESS === '' ? [] : explode(',', WEB_ACCESS),
  'ios_customer_app_url' => IOS_CUSTOMER_APP_URL,
  'ios_provider_app_url' => IOS_PROVIDER_APP_URL,
  'firebase_web_app_id'  => FIREBASE_WEB_APP_ID,
  'firebase_vapid_key'   => FIREBASE_VAPID_KEY,
  'email_otp_required' => REQUIRE_EMAIL_OTP,
  'ref_discount'     => REF_DISCOUNT,        // ₹ off a customer's first booking through a partner's status card
]);
