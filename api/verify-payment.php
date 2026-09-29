<?php
// ============================================================
//  api/verify-payment.php
//  Verifies a Razorpay payment and records it on the MySQL booking
//  (MySQL is the only data store — nothing is written to Firebase RTDB).
//  Nothing financial is taken from the client: amount, booking, provider
//  and customer all come from the Razorpay order/payment fetched server-side.
//  Keys: RAZORPAY_KEY_ID / RAZORPAY_KEY_SECRET from config (see config.example.php)
// ============================================================
require_once __DIR__ . '/db.php';


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

$amountPaise = (int)($payment['amount'] ?? 0);

// ── STEP 2: Record in MySQL (the only data store) — idempotent ──
$db = getDB();
$st = $db->prepare("SELECT * FROM bookings WHERE id = ?");
$st->execute([$bookingId]);
$bk = $st->fetch();
if (!$bk) vpFail('Booking not found', 404);
if (bookingPayable($bk) * 100 !== $amountPaise) vpFail('Payment amount mismatch');

$idempotent = false;
if (($bk['payment_status'] ?? '') === 'paid') {
    // Already settled (webhook / razorpay_confirm / double click) with this payment
    if (($bk['razorpay_payment_id'] ?? '') !== $rzpPaymentId) vpFail('Booking is already paid', 409);
    $idempotent = true;
} else {
    $upd = $db->prepare("UPDATE bookings SET payment_status = 'paid', payment_method = 'razorpay',
                           razorpay_payment_id = ?, razorpay_order_id = ?
                         WHERE id = ? AND payment_status != 'paid'");
    $upd->execute([$rzpPaymentId, $rzpOrderId, $bookingId]);
    if ($upd->rowCount() === 0) {
        $st->execute([$bookingId]);
        $bk2 = $st->fetch();
        if (($bk2['razorpay_payment_id'] ?? '') !== $rzpPaymentId) vpFail('Booking is already paid', 409);
        $idempotent = true;
    }
}

// ── STEP 3: Split (informational — provider earnings are credited on completion) ──
$totalAmount     = intdiv($amountPaise, 100);
$commissionPct   = (int)($bk['commission_pct'] ?? 15);
$commissionAmt   = (int)round($totalAmount * $commissionPct / 100);
$providerEarning = $totalAmount - $commissionAmt;

echo json_encode([
    'success'         => true,
    'idempotent'      => $idempotent,
    'txnId'           => $rzpPaymentId,
    'totalAmount'     => $totalAmount,
    'commissionAmt'   => $commissionAmt,
    'providerEarning' => $providerEarning,
    'commissionPct'   => $commissionPct,
    'status'          => 'paid',
]);
