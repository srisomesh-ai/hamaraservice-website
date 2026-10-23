<?php
// ── Idempotent schema upgrades ────────────────────────────
// Runs the ALTERs once per SCHEMA_VERSION (flag file in temp dir)
// instead of on every request.
const HS_SCHEMA_VERSION = '2026-10-24-1';

function ensureSchema(PDO $db) {
    $flag = sys_get_temp_dir() . '/hs_schema_' . md5(DB_NAME) . '.ver';
    if (is_readable($flag) && trim(file_get_contents($flag)) === HS_SCHEMA_VERSION) return;

    $stmts = [
        // bookings — full flow fields
        "ALTER TABLE bookings ADD COLUMN landmark TEXT NULL",
        "ALTER TABLE bookings ADD COLUMN contact_name VARCHAR(100) NULL",
        "ALTER TABLE bookings ADD COLUMN contact_phone VARCHAR(15) NULL",
        "ALTER TABLE bookings ADD COLUMN est_price INT DEFAULT 0",
        "ALTER TABLE bookings ADD COLUMN summary TEXT NULL",
        "ALTER TABLE bookings ADD COLUMN start_otp VARCHAR(8) NULL",
        "ALTER TABLE bookings ADD COLUMN completion_otp VARCHAR(8) NULL",
        "ALTER TABLE bookings ADD COLUMN otp_attempts INT DEFAULT 0",
        "ALTER TABLE bookings ADD COLUMN started_at DATETIME NULL",
        "ALTER TABLE bookings ADD COLUMN min_duration_min INT DEFAULT 5",
        "ALTER TABLE bookings ADD COLUMN cancel_reason TEXT NULL",
        "ALTER TABLE bookings ADD COLUMN cancelled_by VARCHAR(20) NULL",
        "ALTER TABLE bookings ADD COLUMN rating INT NULL",
        "ALTER TABLE bookings ADD COLUMN review TEXT NULL",
        "ALTER TABLE bookings ADD COLUMN review_requested TINYINT DEFAULT 0",
        "ALTER TABLE bookings ADD COLUMN payment_method VARCHAR(20) NULL",
        "ALTER TABLE bookings ADD COLUMN razorpay_order_id VARCHAR(100) NULL",
        "ALTER TABLE bookings ADD COLUMN razorpay_payment_id VARCHAR(100) NULL",
        "ALTER TABLE bookings ADD COLUMN paid_at DATETIME NULL",
        "ALTER TABLE bookings ADD COLUMN settled TINYINT DEFAULT 0",
        "ALTER TABLE bookings ADD COLUMN quote_at DATETIME NULL",
        "ALTER TABLE bookings MODIFY COLUMN slot_time VARCHAR(80) NULL",
        "ALTER TABLE bookings ADD INDEX idx_rzp_order (razorpay_order_id)",
        // "No provider found" admin alert sent (api/alerts_lib.php)
        "ALTER TABLE bookings ADD COLUMN unassigned_alerted_at DATETIME NULL",
        // Events & Media services
        "INSERT IGNORE INTO services (id, name, icon, category, base_price, sort_order) VALUES
           ('SVC035','Anchor / Emcee','🎙️','Events & Media',2999,35),
           ('SVC036','Influencer Promotion','📱','Events & Media',999,36)",
        // partner social profile (anchors / influencers) — shown to customers with the quote
        "ALTER TABLE providers ADD COLUMN instagram_url VARCHAR(200) NULL",
        "ALTER TABLE providers ADD COLUMN youtube_url VARCHAR(200) NULL",
        "ALTER TABLE providers ADD COLUMN followers VARCHAR(20) NULL",
        // web push tokens (browser / iPhone Home Screen) — separate from the app token
        "ALTER TABLE customers ADD COLUMN web_push_token VARCHAR(255) NULL",
        "ALTER TABLE providers ADD COLUMN web_push_token VARCHAR(255) NULL",
        // provider trip: "Start trip" → "I've arrived" (start OTP sent then)
        "ALTER TABLE bookings ADD COLUMN travel_started_at DATETIME NULL",
        "ALTER TABLE bookings ADD COLUMN arrived_at DATETIME NULL",
        // providers
        "ALTER TABLE providers ADD COLUMN upi_id VARCHAR(100) NULL",
        "ALTER TABLE providers ADD COLUMN areas TEXT NULL",
        "ALTER TABLE providers ADD COLUMN photo VARCHAR(255) NULL",
        "ALTER TABLE providers ADD COLUMN last_seen DATETIME NULL",
        // pinned service base location (matching); lat/lng stay the live position
        "ALTER TABLE providers ADD COLUMN base_lat DECIMAL(10,7) NULL",
        "ALTER TABLE providers ADD COLUMN base_lng DECIMAL(10,7) NULL",
        "ALTER TABLE providers MODIFY COLUMN status ENUM('pending','review','approved','suspended','rejected','deleted') DEFAULT 'pending'",
        "ALTER TABLE providers MODIFY COLUMN pending_earned INT DEFAULT 0",
        // services — per-service commission
        "ALTER TABLE services ADD COLUMN commission_pct INT NULL",
        // customers — local password for web logins
        "ALTER TABLE customers ADD COLUMN password_hash VARCHAR(255) NOT NULL DEFAULT ''",
        // reviews — one per booking
        "ALTER TABLE reviews ADD UNIQUE KEY uniq_booking (booking_id)",
    ];
    $stmts[] = "CREATE TABLE IF NOT EXISTS wallet_topups (
        id INT AUTO_INCREMENT PRIMARY KEY,
        provider_id VARCHAR(30) NOT NULL,
        amount INT NOT NULL,
        razorpay_order_id VARCHAR(100) NOT NULL,
        razorpay_payment_id VARCHAR(100) NULL,
        status ENUM('created','paid') DEFAULT 'created',
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        paid_at DATETIME NULL,
        UNIQUE KEY uniq_order (razorpay_order_id),
        INDEX idx_provider (provider_id)
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
    // email OTP codes for registration (api/otp.php) — only a hash of the code is stored
    $stmts[] = "CREATE TABLE IF NOT EXISTS email_otps (
        id INT AUTO_INCREMENT PRIMARY KEY,
        email VARCHAR(190) NOT NULL,
        purpose ENUM('customer_register','provider_register') NOT NULL,
        code_hash VARCHAR(255) NOT NULL,
        expires_at DATETIME NOT NULL,
        attempts INT NOT NULL DEFAULT 0,
        ip VARCHAR(45) NOT NULL DEFAULT '',
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        used_at DATETIME NULL,
        INDEX idx_email_purpose (email, purpose, created_at),
        INDEX idx_ip (ip, created_at)
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
    // ── first-party visitor analytics (api/track.php, api/visitors.php) ──
    $stmts[] = "CREATE TABLE IF NOT EXISTS visitors (
        vid VARCHAR(32) NOT NULL PRIMARY KEY,
        first_seen DATETIME NOT NULL,
        last_seen DATETIME NOT NULL,
        visits INT NOT NULL DEFAULT 0,
        total_seconds INT NOT NULL DEFAULT 0,
        pageviews INT NOT NULL DEFAULT 0,
        first_source VARCHAR(20) NOT NULL DEFAULT '',
        first_referrer VARCHAR(255) NOT NULL DEFAULT '',
        first_landing VARCHAR(255) NOT NULL DEFAULT '',
        utm_source VARCHAR(100) NOT NULL DEFAULT '',
        utm_medium VARCHAR(100) NOT NULL DEFAULT '',
        utm_campaign VARCHAR(100) NOT NULL DEFAULT '',
        device VARCHAR(10) NOT NULL DEFAULT '',
        os VARCHAR(20) NOT NULL DEFAULT '',
        browser VARCHAR(20) NOT NULL DEFAULT '',
        last_ip VARCHAR(45) NULL,
        last_city VARCHAR(80) NOT NULL DEFAULT '',
        last_region VARCHAR(80) NOT NULL DEFAULT '',
        last_country VARCHAR(80) NOT NULL DEFAULT '',
        last_country_code CHAR(2) NOT NULL DEFAULT '',
        last_org VARCHAR(120) NOT NULL DEFAULT '',
        converted_customer TINYINT NOT NULL DEFAULT 0,
        converted_provider TINYINT NOT NULL DEFAULT 0,
        INDEX idx_last_seen (last_seen),
        INDEX idx_first_seen (first_seen)
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
    $stmts[] = "CREATE TABLE IF NOT EXISTS visitor_sessions (
        sid VARCHAR(32) NOT NULL PRIMARY KEY,
        vid VARCHAR(32) NOT NULL,
        visit_no INT NOT NULL DEFAULT 1,
        started_at DATETIME NOT NULL,
        last_seen DATETIME NOT NULL,
        seconds INT NOT NULL DEFAULT 0,
        pageviews INT NOT NULL DEFAULT 0,
        source VARCHAR(20) NOT NULL DEFAULT '',
        referrer VARCHAR(255) NOT NULL DEFAULT '',
        landing VARCHAR(255) NOT NULL DEFAULT '',
        utm_source VARCHAR(100) NOT NULL DEFAULT '',
        utm_medium VARCHAR(100) NOT NULL DEFAULT '',
        utm_campaign VARCHAR(100) NOT NULL DEFAULT '',
        utm_term VARCHAR(100) NOT NULL DEFAULT '',
        utm_content VARCHAR(100) NOT NULL DEFAULT '',
        has_gclid TINYINT NOT NULL DEFAULT 0,
        has_fbclid TINYINT NOT NULL DEFAULT 0,
        ip VARCHAR(45) NULL,
        city VARCHAR(80) NOT NULL DEFAULT '',
        region VARCHAR(80) NOT NULL DEFAULT '',
        country VARCHAR(80) NOT NULL DEFAULT '',
        country_code CHAR(2) NOT NULL DEFAULT '',
        org VARCHAR(120) NOT NULL DEFAULT '',
        device VARCHAR(10) NOT NULL DEFAULT '',
        os VARCHAR(20) NOT NULL DEFAULT '',
        browser VARCHAR(20) NOT NULL DEFAULT '',
        screen VARCHAR(20) NOT NULL DEFAULT '',
        lang VARCHAR(20) NOT NULL DEFAULT '',
        tz VARCHAR(50) NOT NULL DEFAULT '',
        INDEX idx_vid (vid, started_at),
        INDEX idx_started (started_at),
        INDEX idx_last_seen (last_seen),
        INDEX idx_ip (ip)
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
    $stmts[] = "CREATE TABLE IF NOT EXISTS visitor_pageviews (
        id BIGINT AUTO_INCREMENT PRIMARY KEY,
        sid VARCHAR(32) NOT NULL,
        vid VARCHAR(32) NOT NULL,
        pid VARCHAR(32) NOT NULL DEFAULT '',
        path VARCHAR(255) NOT NULL,
        title VARCHAR(150) NOT NULL DEFAULT '',
        at DATETIME NOT NULL,
        seconds INT NOT NULL DEFAULT 0,
        INDEX idx_sid (sid, pid),
        INDEX idx_at (at),
        INDEX idx_vid (vid)
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
    $stmts[] = "CREATE TABLE IF NOT EXISTS visitor_events (
        id BIGINT AUTO_INCREMENT PRIMARY KEY,
        sid VARCHAR(32) NOT NULL,
        vid VARCHAR(32) NOT NULL,
        type VARCHAR(20) NOT NULL,
        who VARCHAR(10) NOT NULL DEFAULT '',
        path VARCHAR(255) NOT NULL DEFAULT '',
        target VARCHAR(255) NOT NULL DEFAULT '',
        at DATETIME NOT NULL,
        INDEX idx_sid (sid),
        INDEX idx_vid (vid),
        INDEX idx_at (at, type)
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
    // IP → location cache (IPinfo); failed lookups (ok = 0) are retried later
    $stmts[] = "CREATE TABLE IF NOT EXISTS ip_geo (
        ip VARCHAR(45) NOT NULL PRIMARY KEY,
        ok TINYINT NOT NULL DEFAULT 0,
        city VARCHAR(80) NOT NULL DEFAULT '',
        region VARCHAR(80) NOT NULL DEFAULT '',
        country VARCHAR(80) NOT NULL DEFAULT '',
        country_code CHAR(2) NOT NULL DEFAULT '',
        org VARCHAR(120) NOT NULL DEFAULT '',
        looked_up_at DATETIME NOT NULL,
        INDEX idx_looked (looked_up_at)
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
    // one-off data fix: an e-mail address autofilled into the City field
    $stmts[] = "UPDATE customers SET city = '' WHERE city LIKE '%@%'";
    $stmts[] = "UPDATE providers SET city = '' WHERE city LIKE '%@%'";
    foreach ($stmts as $s) {
        try { $db->exec($s); } catch (Throwable $e) { /* already applied */ }
    }
    @file_put_contents($flag, HS_SCHEMA_VERSION);
}
