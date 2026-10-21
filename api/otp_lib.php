<?php
// ═══════════════════════════════════════════════════════
// HamaraService — email OTP helpers (library, not an endpoint)
// Shared by api/otp.php (send / verify) and the register actions in
// customers.php / providers.php (otp_token enforcement).
// ═══════════════════════════════════════════════════════
require_once __DIR__ . '/db.php';

const OTP_PURPOSES        = ['customer_register', 'provider_register'];
const OTP_TTL_SEC         = 600;    // code valid 10 minutes
const OTP_RESEND_SEC      = 60;     // min gap between sends (email + purpose)
const OTP_MAX_PER_EMAIL   = 5;      // sends per email per hour
const OTP_MAX_PER_IP      = 20;     // sends per IP per hour
const OTP_MAX_ATTEMPTS    = 5;      // wrong guesses per code
const OTP_TOKEN_TTL_SEC   = 1800;   // otp_token valid 30 minutes

// Only a keyed hash of the code is stored (bound to email + purpose).
function otpHash($email, $purpose, $code) {
    return hash_hmac('sha256', strtolower($email) . '|' . $purpose . '|' . $code, 'email_otp|' . JWT_SECRET);
}

function otpMakeToken($email, $purpose) {
    return makeJWT(['typ' => 'email_otp', 'email' => strtolower($email), 'purpose' => $purpose],
                   OTP_TOKEN_TTL_SEC / 86400);
}

// Checks body['otp_token'] for a registration of $email / $purpose.
//  - token sent      → must be valid (signature, expiry, typ, purpose, email) — even if the flag is off
//  - no token sent   → allowed only when $required is false
// Returns the verified (lower-case) email, or '' when no token was sent.
function otpCheckRegistration(array $b, $email, $purpose, $required = REQUIRE_EMAIL_OTP) {
    $tok = trim((string)($b['otp_token'] ?? ''));
    if ($tok === '') {
        if ($required) err('Please verify your email with the code we send you. If you don\'t see this step, please update the app.', 400);
        return '';
    }
    $d = verifyJWT($tok);
    $email = strtolower(trim((string)$email));
    if (!$d || ($d['typ'] ?? '') !== 'email_otp' || ($d['purpose'] ?? '') !== $purpose
        || $email === '' || strtolower((string)($d['email'] ?? '')) !== $email) {
        err('Email verification expired. Please verify your email again.', 400);
    }
    return $email;
}
