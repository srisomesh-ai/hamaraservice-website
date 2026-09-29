<?php
// ═══════════════════════════════════════════════════════
// HamaraService — configuration TEMPLATE
//
// Copy this file and fill in real values. db.php loads the first file
// it finds, in this order:
//   1. <two levels above api/>/hs-config.php
//        e.g. /home/<user>/domains/hamaraservice.com/hs-config.php
//        (OUTSIDE public_html — recommended)
//   2. api/config.php   (inside web root; git-ignored and denied by api/.htaccess)
// Any constant not defined in the file is read from the environment
// variable of the same name (getenv).
//
// NEVER commit the real file. Generate random secrets with e.g.:
//   php -r 'echo bin2hex(random_bytes(32)), "\n";'
// ═══════════════════════════════════════════════════════

// ── MySQL ─────────────────────────────────────────────
define('DB_HOST', 'localhost');
define('DB_NAME', 'your_database_name');
define('DB_USER', 'your_database_user');
define('DB_PASS', 'your_database_password');

// ── Auth ──────────────────────────────────────────────
// HMAC secret for provider / web-customer JWTs (min 32 random chars).
// Changing it logs every provider and web customer out.
define('JWT_SECRET', 'replace-with-64-hex-chars-from-random_bytes');
// Admin panel key (sent as X-Admin-Key header). Long and random.
define('ADMIN_KEY', 'replace-with-a-long-random-admin-key');
// Firebase project used by the customer app (ID-token audience)
define('FIREBASE_PROJECT_ID', 'hamaraservice-s009');

// ── SMTP (api/email.php) ─────────────────────────────
define('SMTP_HOST', 'smtp.hostinger.com');
define('SMTP_PORT', 587);
define('SMTP_USER', 'support@hamaraservice.in');
define('SMTP_PASS', 'your_mailbox_password');

// ── Razorpay (ONE key pair used everywhere) ───────────
define('RAZORPAY_KEY_ID',         'rzp_test_xxxxxxxxxxxxxx');
define('RAZORPAY_KEY_SECRET',     'your_razorpay_key_secret');
// Razorpay Dashboard → Settings → Webhooks → secret you chose there
define('RAZORPAY_WEBHOOK_SECRET', 'your_razorpay_webhook_secret');

// ── Firebase Cloud Messaging service account ──────────
// Absolute path to the service-account JSON, OUTSIDE the web root.
define('SERVICE_ACCOUNT_PATH', '/home/youruser/hs-private/firebase-service-account.json');

// ── Push notifications (Cloud Run "notifybooking") ────
// Shared secret sent by the PHP server as header "X-HS-Notify-Key" on every
// call to the notify service. The Cloud Run function must reject requests
// without it. Apps/pages never call the notify service directly.
define('NOTIFY_KEY', 'replace-with-a-long-random-notify-key');
// Optional override of the notify service URL (default shown)
// define('NOTIFY_URL', 'https://notifybooking-mlchyp6tra-as.a.run.app');
