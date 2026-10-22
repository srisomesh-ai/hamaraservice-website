<?php
// ═══════════════════════════════════════════════════════
// HamaraService — runtime configuration
//
// NO SECRETS LIVE IN THIS REPOSITORY.
// Secrets are loaded (first match wins) from:
//   1. environment variables (HS_DB_PASS, HS_JWT_SECRET, …)
//   2. a PHP file OUTSIDE the web root:
//        <home>/hs-secrets.php   (one level above public_html)
//      or the path in env HS_SECRETS_FILE.
// Copy api/hs-secrets.sample.php → ~/hs-secrets.php on the server
// and fill in freshly ROTATED values.
// ═══════════════════════════════════════════════════════

date_default_timezone_set('Asia/Kolkata');

$__hsSecrets = [];
$__hsCandidates = array_filter([
    getenv('HS_SECRETS_FILE') ?: null,
    dirname(__DIR__, 2) . '/hs-secrets.php',   // ~/hs-secrets.php when site is ~/public_html
    dirname(__DIR__, 3) . '/hs-secrets.php',
]);
foreach ($__hsCandidates as $__f) {
    if (is_readable($__f)) { $__hsSecrets = require $__f; break; }
}

function hsCfg($key, $default = '') {
    global $__hsSecrets;
    $env = getenv('HS_' . $key);
    if ($env !== false && $env !== '') return $env;
    return $__hsSecrets[$key] ?? $default;
}

define('DB_HOST',    hsCfg('DB_HOST', 'localhost'));
define('DB_NAME',    hsCfg('DB_NAME'));
define('DB_USER',    hsCfg('DB_USER'));
define('DB_PASS',    hsCfg('DB_PASS'));
define('DB_CHARSET', 'utf8mb4');

define('FIREBASE_PROJECT_ID', hsCfg('FIREBASE_PROJECT_ID', 'hamaraservice-s009'));
// Public Firebase *web/client* API key (not a secret — it ships in the apps).
// Lets web login/register use the same Firebase accounts as the mobile app.
// Set to '' to keep web-only accounts.
define('FIREBASE_WEB_API_KEY', hsCfg('FIREBASE_WEB_API_KEY', 'AIzaSyDpMpewyKVlfsfSeKfoS3GJf0V_t14Qb7k'));
define('FIREBASE_AUTH_URL',    hsCfg('FIREBASE_AUTH_URL', 'https://identitytoolkit.googleapis.com/v1'));

define('JWT_SECRET', hsCfg('JWT_SECRET'));

// Admin panel login (server-side). ADMIN_PASSWORD_HASH = password_hash('…', PASSWORD_BCRYPT)
define('ADMIN_USER',          hsCfg('ADMIN_USER', 'admin'));
define('ADMIN_PASSWORD_HASH', hsCfg('ADMIN_PASSWORD_HASH'));
// Optional machine key for server-to-server admin calls (header X-Admin-Key only)
define('ADMIN_KEY',           hsCfg('ADMIN_KEY'));

// Razorpay — ONE key pair, used for order creation AND verification
define('RZP_KEY_ID',         hsCfg('RZP_KEY_ID'));
define('RZP_KEY_SECRET',     hsCfg('RZP_KEY_SECRET'));
define('RZP_WEBHOOK_SECRET', hsCfg('RZP_WEBHOOK_SECRET'));

// Push relay (Cloud Function notifyBooking) + shared key it requires
define('PUSH_URL', hsCfg('PUSH_URL', 'https://notifybooking-mlchyp6tra-as.a.run.app'));
define('PUSH_KEY', hsCfg('PUSH_KEY'));
// Direct FCM (no Cloud Function, no billing): Firebase service-account JSON
// saved as hs-firebase.json next to hs-secrets.php (outside public_html).
define('FCM_SA_FILE', hsCfg('FCM_SA_FILE') ?: (is_file(dirname(__DIR__, 2) . '/hs-firebase.json')
    ? dirname(__DIR__, 2) . '/hs-firebase.json' : dirname(__DIR__, 3) . '/hs-firebase.json'));
define('FCM_OAUTH_URL', hsCfg('FCM_OAUTH_URL') ?: 'https://oauth2.googleapis.com/token');
define('FCM_API_BASE',  hsCfg('FCM_API_BASE')  ?: 'https://fcm.googleapis.com');

// Business rules
define('DEFAULT_COMMISSION_PCT', (int)hsCfg('DEFAULT_COMMISSION_PCT', 15));
define('MIN_JOB_MINUTES',        (int)hsCfg('MIN_JOB_MINUTES', 5));
// Customer must accept a quote / final offer within this many minutes
define('QUOTE_TTL_MIN',          (int)hsCfg('QUOTE_TTL_MIN', 5));
// A provider whose balance (cash commission owed) drops below this cannot accept new jobs
define('CASH_DUE_LIMIT',         (int)hsCfg('CASH_DUE_LIMIT', -1000));

// SMTP (email.php)
define('SMTP_HOST', hsCfg('SMTP_HOST', 'smtp.hostinger.com'));
define('SMTP_PORT', (int)hsCfg('SMTP_PORT', 587));
define('SMTP_USER', hsCfg('SMTP_USER'));
define('SMTP_PASS', hsCfg('SMTP_PASS'));
// TESTING ONLY: when set (env HS_SMTP_LOG_FILE), emails are appended to this
// file instead of being sent. Empty (the default) = real SMTP. Never set it in production.
define('SMTP_LOG_FILE', (string)hsCfg('SMTP_LOG_FILE', ''));

// Email OTP on registration (api/otp.php). When true, customers.php
// register (new profile) / web_register and providers.php register REQUIRE
// an otp_token from otp.php?action=verify. Turn ON only after the updated
// customer + partner apps are live on Google Play — older app versions
// cannot send the token. A token that IS sent is always checked.
define('REQUIRE_EMAIL_OTP', filter_var(hsCfg('REQUIRE_EMAIL_OTP', false), FILTER_VALIDATE_BOOLEAN));

// Public site settings (served by api/site.php — safe to expose)
define('LAUNCH_CODE', (string)hsCfg('LAUNCH_CODE'));   // director's launch code (optional)
define('SUPPORT_WHATSAPP',  preg_replace('/\D/', '', hsCfg('SUPPORT_WHATSAPP') ?: '919346220090'));   // e.g. 919876543210
define('SUPPORT_EMAIL',     hsCfg('SUPPORT_EMAIL', 'info@hamaraservice.com'));
// "No provider found" admin alert (api/alerts_lib.php): a booking still
// searching for a provider after NO_PROVIDER_ALERT_MIN minutes is e-mailed
// once to ADMIN_ALERT_EMAIL (default SUPPORT_EMAIL).
define('NO_PROVIDER_ALERT_MIN', max(1, (int)hsCfg('NO_PROVIDER_ALERT_MIN', 5)));
define('ADMIN_ALERT_EMAIL',     trim((string)hsCfg('ADMIN_ALERT_EMAIL', '')) ?: SUPPORT_EMAIL);
// Key for api/cron.php (optional Hostinger cron). Empty = cron endpoint disabled.
define('CRON_KEY',              (string)hsCfg('CRON_KEY', ''));
// App links (Play Store). Empty = not published yet → /get-app.html shows
// "Coming soon on Google Play — WhatsApp us for early access".
define('CUSTOMER_APP_URL',  hsCfg('CUSTOMER_APP_URL') ?: 'https://play.google.com/store/apps/details?id=com.hamaraservice.customer');   // e.g. https://play.google.com/store/apps/details?id=com.hamaraservice.customer
define('PROVIDER_APP_URL',  hsCfg('PROVIDER_APP_URL') ?: 'https://play.google.com/store/apps/details?id=com.hamaraservice.provider');
// 'app' (default) = the website has NO login/booking: every login/book/register button
//                   sends people to install the customer / partner app, and
//                   /app/customer.html + /app/provider.html show an "use the app" panel.
// 'web'           = re-enables the website login + booking web app (code is kept).
// The API (used by the Android apps) works the same in both modes.
define('WEB_LOGIN_MODE',    hsCfg('WEB_LOGIN_MODE', 'app') === 'web' ? 'web' : 'app');

// ── Website visitor analytics (js/hs-track.js → api/track.php, admin: api/visitors.php) ──
// IP → city/region/country/ISP via IPinfo, cached per IP for 30 days.
// IPINFO_TOKEN is optional (free account at https://ipinfo.io = 50k lookups/month);
// without it IPinfo still answers, but with a low daily limit.
define('IPINFO_TOKEN', (string)hsCfg('IPINFO_TOKEN', ''));
define('IPINFO_URL',   rtrim((string)hsCfg('IPINFO_URL', 'https://ipinfo.io'), '/'));
// true ONLY when the site sits behind Cloudflare / a reverse proxy: the visitor IP is
// then read from CF-Connecting-IP / X-Forwarded-For (otherwise these headers are ignored
// because anyone can fake them).
define('TRUST_PROXY',  filter_var(hsCfg('TRUST_PROXY', false), FILTER_VALIDATE_BOOLEAN));
// Privacy: visitor IP addresses are erased after N days (city/country are kept);
// page-view history is deleted after N days.
define('VISITOR_IP_RETENTION_DAYS',       max(1, (int)hsCfg('VISITOR_IP_RETENTION_DAYS', 90)));
define('VISITOR_PAGEVIEW_RETENTION_DAYS', max(1, (int)hsCfg('VISITOR_PAGEVIEW_RETENTION_DAYS', 180)));
// Max tracking hits per IP per minute (more are dropped)
define('TRACK_RATE_PER_MIN', max(1, (int)hsCfg('TRACK_RATE_PER_MIN', 120)));

// Allowed browser origins for CORS
define('ALLOWED_ORIGINS', [
    'https://hamaraservice.com', 'https://www.hamaraservice.com',
    'https://hamaraservice.in',  'https://www.hamaraservice.in',
]);
