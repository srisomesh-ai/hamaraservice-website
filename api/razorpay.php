<?php
// ── Razorpay helpers (single key pair from config) ────────
require_once __DIR__ . '/config.php';

function rzpRequest($method, $path, $body = null) {
    if (RZP_KEY_ID === '' || RZP_KEY_SECRET === '') throw new Exception('Razorpay not configured');
    $ch = curl_init('https://api.razorpay.com' . $path);
    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_USERPWD        => RZP_KEY_ID . ':' . RZP_KEY_SECRET,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_TIMEOUT        => 15,
    ];
    if ($body !== null) $opts[CURLOPT_POSTFIELDS] = json_encode($body);
    curl_setopt_array($ch, $opts);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $cerr = curl_error($ch);
    curl_close($ch);
    if ($cerr) throw new Exception('Network error: ' . $cerr);
    $data = json_decode($resp, true);
    if ($code < 200 || $code >= 300 || !is_array($data)) {
        throw new Exception($data['error']['description'] ?? ('HTTP ' . $code));
    }
    return $data;
}

function rzpVerifySignature($orderId, $paymentId, $signature) {
    if (RZP_KEY_SECRET === '') return false;
    $expected = hash_hmac('sha256', $orderId . '|' . $paymentId, RZP_KEY_SECRET);
    return hash_equals($expected, $signature);
}
