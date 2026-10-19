<?php
// ── Idempotent schema upgrades ────────────────────────────
// Runs the ALTERs once per SCHEMA_VERSION (flag file in temp dir)
// instead of on every request.
const HS_SCHEMA_VERSION = '2026-10-19-1';

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
        "ALTER TABLE bookings ADD INDEX idx_rzp_order (razorpay_order_id)",
        // providers
        "ALTER TABLE providers ADD COLUMN upi_id VARCHAR(100) NULL",
        "ALTER TABLE providers ADD COLUMN areas TEXT NULL",
        "ALTER TABLE providers ADD COLUMN photo VARCHAR(255) NULL",
        "ALTER TABLE providers ADD COLUMN last_seen DATETIME NULL",
        "ALTER TABLE providers MODIFY COLUMN status ENUM('pending','review','approved','suspended','rejected','deleted') DEFAULT 'pending'",
        "ALTER TABLE providers MODIFY COLUMN pending_earned INT DEFAULT 0",
        // services — per-service commission
        "ALTER TABLE services ADD COLUMN commission_pct INT NULL",
        // customers — local password for web logins
        "ALTER TABLE customers ADD COLUMN password_hash VARCHAR(255) NOT NULL DEFAULT ''",
        // reviews — one per booking
        "ALTER TABLE reviews ADD UNIQUE KEY uniq_booking (booking_id)",
    ];
    foreach ($stmts as $s) {
        try { $db->exec($s); } catch (Throwable $e) { /* already applied */ }
    }
    @file_put_contents($flag, HS_SCHEMA_VERSION);
}
