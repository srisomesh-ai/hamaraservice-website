<?php
// ============================================================
//  api/verify-payment.php
//  Verifies a Razorpay payment + records it (Firebase RTDB legacy paths,
//  and the MySQL booking when one exists).
//  Nothing financial is taken from the client: amount, booking, provider
//  and customer all come from the Razorpay order/payment fetched server-side.
//  Keys: RAZORPAY_KEY_ID / RAZORPAY_KEY_SECRET from config (see config.example.php)
// ============================================================
require_once __DIR__ . '/db.php';

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
header('Vary: Origin');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Content-Type: application/json');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST')    { http_response_code(405); exit; }

function vpFail($msg, $code = 400) {
    http_response_code($code);
    echo json_encode(['error' => $msg]);
    exit;
}

$body = getBody();

$rzpOrderId   = (string)($body['razorpay_order_id']   ?? '');
$rzpPaymentId = (string)($body['razorpay_payment_id'] ?? '');
$rzpSignature = (string)($body['razorpay_signature']  ?? '');
$bookingId    = preg_replace('/[^A-Za-z0-9\-]/', '', (string)($body['booking_id'] ?? ''));

if (!$rzpOrderId || !$rzpPaymentId || !$rzpSignature || !$bookingId) vpFail('Missing required fields');
if (!preg_match('/^order_[A-Za-z0-9]+$/', $rzpOrderId) || !preg_match('/^pay_[A-Za-z0-9]+$/', $rzpPaymentId)) vpFail('Invalid payment ids');

// ── STEP 1: Verify signature — prevents fraud ────────────────
if (!razorpaySignatureValid($rzpOrderId, $rzpPaymentId, $rzpSignature)) vpFail('Invalid payment signature');

// ── STEP 1a: Fetch the real order + payment from Razorpay ────
$order   = razorpayGet('orders/' . rawurlencode($rzpOrderId));
$payment = razorpayGet('payments/' . rawurlencode($rzpPaymentId));
if (!$order || !$payment) vpFail('Could not verify payment with Razorpay. Please retry.', 502);
if (($payment['order_id'] ?? '') !== $rzpOrderId) vpFail('Payment does not belong to this order');
if (!in_array($payment['status'] ?? '', ['captured', 'authorized'], true)) vpFail('Payment not completed');
$notes = is_array($order['notes'] ?? null) ? $order['notes'] : [];
$noteBooking = (string)($notes['bookingId'] ?? $notes['booking_id'] ?? '');
if ($noteBooking !== $bookingId) vpFail('Payment does not belong to this booking');

$amount     = intdiv((int)($payment['amount'] ?? 0), 100);   // rupees, from Razorpay
$providerId = preg_replace('/[^A-Za-z0-9_\-]/', '', (string)($notes['provider_id'] ?? ''));
$customerId = (string)($notes['customer_id'] ?? '');

// ── MySQL booking (if this is a MySQL booking) — idempotent ──
try {
    $db = getDB();
    $st = $db->prepare("SELECT * FROM bookings WHERE id = ?");
    $st->execute([$bookingId]);
    $bk = $st->fetch();
    if ($bk && bookingPayable($bk) * 100 === (int)($payment['amount'] ?? -1)) {
        $db->prepare("UPDATE bookings SET payment_status = 'paid', payment_method = 'razorpay',
                        razorpay_payment_id = ?, razorpay_order_id = ?
                      WHERE id = ? AND payment_status != 'paid'")
           ->execute([$rzpPaymentId, $rzpOrderId, $bookingId]);
    }
} catch (Throwable $e) { error_log('verify-payment mysql: ' . $e->getMessage()); }

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
// (skip when unknown — a PUT to "provider_wallet/" would overwrite every wallet)
if ($providerId !== '') {
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
}

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
