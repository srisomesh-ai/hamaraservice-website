<?php
// Copy this file to ~/hs-secrets.php on the server (OUTSIDE public_html),
// chmod 600, and fill in NEW values. Every secret that was ever committed
// to git must be considered leaked and rotated.
return [
    'DB_HOST'             => 'localhost',
    'DB_NAME'             => '',
    'DB_USER'             => '',
    'DB_PASS'             => '',            // new password — do not reuse elsewhere

    'JWT_SECRET'          => '',            // 64+ random chars: php -r "echo bin2hex(random_bytes(32));"

    'ADMIN_USER'          => 'admin',
    'ADMIN_PASSWORD_HASH' => '',            // php -r "echo password_hash('YourStrongPass', PASSWORD_BCRYPT);"
    'ADMIN_KEY'           => '',            // optional, random; header X-Admin-Key only

    'RZP_KEY_ID'          => '',            // rzp_test_… while testing, rzp_live_… after
    'RZP_KEY_SECRET'      => '',
    'RZP_WEBHOOK_SECRET'  => '',            // set the same in Razorpay → Webhooks

    'PUSH_URL'            => 'https://notifybooking-mlchyp6tra-as.a.run.app',
    'PUSH_KEY'            => '',            // same value as Firebase secret HS_PUSH_KEY

    'DEFAULT_COMMISSION_PCT' => 15,
    'MIN_JOB_MINUTES'        => 5,
    'QUOTE_TTL_MIN'          => 5,
    'CASH_DUE_LIMIT'         => -1000,

    // Public site settings
    'SUPPORT_WHATSAPP'    => '919346220090',  // e.g. '919876543210' (country code + number)
    'SUPPORT_EMAIL'       => 'info@hamaraservice.com',
    'CUSTOMER_APP_URL'    => '',            // https://play.google.com/store/apps/details?id=com.hamaraservice.customer (once live)
    'PROVIDER_APP_URL'    => '',            // https://play.google.com/store/apps/details?id=com.hamaraservice.provider (once live)
    'LAUNCH_CODE'         => '',            // optional: director's code for the launch button (built-in default otherwise)
    'WEB_LOGIN_MODE'      => 'app',         // 'app' = no website login/booking, buttons send people to the apps; 'web' = re-enable the web app

    'SMTP_HOST'           => 'smtp.hostinger.com',
    'SMTP_PORT'           => 587,
    'SMTP_USER'           => '',
    'SMTP_PASS'           => '',
];
