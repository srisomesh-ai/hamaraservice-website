<?php
// ============================================================
//  api/verify-payment.php
//  Verifies Razorpay signature + updates Firebase via REST
//  No Firebase Secret needed — uses Firebase open rules
// ============================================================
define('RZP_KEY_ID',     'rzp_test_Sp87HrFA8UHblM');
define('RZP_KEY_SECRET', 'FGo78kZC0992nb0Ug6nxNFB1');
define('COMMISSION_PCT', 12);
define('FB_URL', 'https://hamaraservice-s009-default-rtdb.asia-southeast1.firebasedatabase.app');

$allowed = [
  'https://hamaraservice.com','https://www.hamaraservice.com',
  'https://hamaraservice.in','https://www.hamaraservice.in',
  'http://hamaraservice.in','http://www.hamaraservice.in',
  'http://173.212.234.131','https://173.212.234.131'
];
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
header('Access-Control-Allow-Origin: ' . (in_array($origin, $allowed) ? $origin : $allowed[0]));
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('Content-Type: application/json');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST')    { http_response_code(405); exit; }

$body = json_decode(file_get_contents('php://input'), true);

$rzpOrderId   = $body['razorpay_order_id']   ?? '';
$rzpPaymentId = $body['razorpay_payment_id'] ?? '';
$rzpSignature = $body['razorpay_signature']  ?? '';
$bookingId    = preg_replace('/[^A-Za-z0-9\-]/', '', $body['booking_id']   ?? '');
$amount       = intval($body['amount']    ?? 0);
$providerId   = $body['provider_id'] ?? '';
$customerId   = $body['customer_id'] ?? '';

if (!$rzpOrderId || !$rzpPaymentId || !$rzpSignature || !$bookingId) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing required fields']);
    exit;
}

// ── STEP 1: Verify signature — prevents fraud ────────────────
$expectedSig = hash_hmac('sha256', $rzpOrderId . '|' . $rzpPaymentId, RZP_KEY_SECRET);

// Debug logging (remove in production)
error_log("Signature verification:");
error_log("Order ID: " . $rzpOrderId);
error_log("Payment ID: " . $rzpPaymentId);
error_log("Received signature: " . $rzpSignature);
error_log("Expected signature: " . $expectedSig);

if (!hash_equals($expectedSig, $rzpSignature)) {
    http_response_code(400);
    echo json_encode([
        'error' => 'Invalid payment signature',
        'debug' => [
            'received' => $rzpSignature,
            'expected' => $expectedSig,
            'order_id' => $rzpOrderId,
            'payment_id' => $rzpPaymentId
        ]
    ]);
    exit;
}

// ── STEP 1b: Idempotency — never credit the same payment twice ──
// (Webhook may have already settled this txn; double-click guard.)
$existingTxn = fb_get('transactions/' . $rzpPaymentId);
if (!empty($existingTxn['status'])) {
    echo json_encode([
        'success'         => true,
        'idempotent'      => true,
        'txnId'           => $rzpPaymentId,
        'totalAmount'     => floatval($existingTxn['totalAmount'] ?? $amount),
        'commissionAmt'   => floatval($existingTxn['commissionAmt'] ?? 0),
        'providerEarning' => floatval($existingTxn['providerEarning'] ?? 0),
        'commissionPct'   => COMMISSION_PCT,
        'status'          => 'paid',
    ]);
    exit;
}

// ── STEP 2: Calculate split ───────────────────────────────────
$totalAmount     = $amount;
$commissionAmt   = (int) round($totalAmount * COMMISSION_PCT / 100);
$providerEarning = $totalAmount - $commissionAmt;
$txnId           = $rzpPaymentId;
$paidAt          = date('c');

// ── STEP 3: Update Firebase via REST (no secret needed) ───────
// 3a. Update booking
fb_patch('bookings/' . $bookingId, [
    'status'          => 'paid',
    'paidAt'          => $paidAt,
    'txnId'           => $txnId,
    'paymentMethod'   => 'razorpay',
    'rzpOrderId'      => $rzpOrderId,
    'totalAmount'     => $totalAmount,
    'commissionAmt'   => $commissionAmt,
    'providerEarning' => $providerEarning,
    'commissionPct'   => COMMISSION_PCT,
]);

// 3b. Update active_bookings — provider polls this
fb_patch('active_bookings/' . $bookingId, [
    'status' => 'paid',
    'paidAt' => $paidAt,
    'txnId'  => $txnId,
]);

// 3c. Read provider wallet and credit earnings
$wallet          = fb_get('provider_wallet/' . $providerId);
$curBal          = floatval($wallet['balance']        ?? 0);
$curEarned       = floatval($wallet['totalEarned']    ?? 0);
$curCommission   = floatval($wallet['totalCommission'] ?? 0);

fb_put('provider_wallet/' . $providerId, [
    'providerId'       => $providerId,
    'balance'          => $curBal + $providerEarning,
    'totalEarned'      => $curEarned + $providerEarning,
    'totalCommission'  => $curCommission + $commissionAmt,
    'lastCredited'     => $paidAt,
    'lastBookingId'    => $bookingId,
]);

// 3d. Write transaction record
fb_put('transactions/' . $txnId, [
    'txnId'            => $txnId,
    'bookingId'        => $bookingId,
    'customerId'       => $customerId,
    'providerId'       => $providerId,
    'totalAmount'      => $totalAmount,
    'commissionAmt'    => $commissionAmt,
    'providerEarning'  => $providerEarning,
    'commissionPct'    => COMMISSION_PCT,
    'paymentMethod'    => 'razorpay',
    'rzpOrderId'       => $rzpOrderId,
    'rzpPaymentId'     => $txnId,
    'status'           => 'settled',
    'createdAt'        => $paidAt,
]);

// 3e. Admin earnings record (by month)
fb_put('admin_earnings/' . date('Y-m') . '/' . $bookingId, [
    'bookingId'  => $bookingId,
    'commission' => $commissionAmt,
    'total'      => $totalAmount,
    'month'      => date('Y-m'),
    'createdAt'  => $paidAt,
]);

// ── Return success ────────────────────────────────────────────
echo json_encode([
    'success'         => true,
    'txnId'           => $txnId,
    'totalAmount'     => $totalAmount,
    'commissionAmt'   => $commissionAmt,
    'providerEarning' => $providerEarning,
    'commissionPct'   => COMMISSION_PCT,
    'status'          => 'paid',
]);

// ── Firebase REST helpers ─────────────────────────────────────
function fb_patch($path, $data) {
    $ch = curl_init(FB_URL . '/' . $path . '.json');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST  => 'PATCH',
        CURLOPT_POSTFIELDS     => json_encode($data),
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_TIMEOUT        => 8,
    ]);
    curl_exec($ch); curl_close($ch);
}
function fb_put($path, $data) {
    $ch = curl_init(FB_URL . '/' . $path . '.json');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST  => 'PUT',
        CURLOPT_POSTFIELDS     => json_encode($data),
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_TIMEOUT        => 8,
    ]);
    curl_exec($ch); curl_close($ch);
}
function fb_get($path) {
    $ch = curl_init(FB_URL . '/' . $path . '.json');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 6,
    ]);
    $r = curl_exec($ch); curl_close($ch);
    return json_decode($r, true) ?? [];
}
