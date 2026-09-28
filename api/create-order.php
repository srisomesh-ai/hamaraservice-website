<?php
// ============================================================
//  api/create-order.php
//  Creates a Razorpay order for a MySQL booking.
//  - Auth: the booking's customer (Authorization: Bearer <web JWT | Firebase ID token>)
//  - The amount is read from the booking in the DB (never from the client)
//  Keys come from config (RAZORPAY_KEY_ID / RAZORPAY_KEY_SECRET) — see config.example.php
//  Request:  POST {bookingId}
//  Response: {order_id, amount (paise), currency, key_id, booking_id}
// ============================================================
require_once __DIR__ . '/db.php';

define('COMMISSION_PCT', 12);

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
if ($_SERVER['REQUEST_METHOD'] !== 'POST')    { http_response_code(405); echo json_encode(['error'=>'Method not allowed']); exit; }

function orderFail($msg, $code = 400) {
    http_response_code($code);
    echo json_encode(['error' => $msg]);
    exit;
}

if (RAZORPAY_KEY_ID === '' || RAZORPAY_KEY_SECRET === '') {
    error_log('HamaraService: Razorpay keys not configured');
    orderFail('Payments are not configured', 500);
}

$body      = getBody();
$bookingId = preg_replace('/[^A-Za-z0-9\-]/', '', (string)($body['bookingId'] ?? $body['booking_id'] ?? ''));
if (!$bookingId) orderFail('bookingId required');

// Who is paying? Must be the booking's customer.
$user = requireCustomer();

$db   = getDB();
$stmt = $db->prepare("SELECT * FROM bookings WHERE id = ?");
$stmt->execute([$bookingId]);
$bk = $stmt->fetch();
if (!$bk || (string)$bk['customer_id'] !== (string)$user['uid']) orderFail('Booking not found', 404);
if (($bk['payment_status'] ?? '') === 'paid') orderFail('Booking is already paid', 409);
if (!in_array($bk['status'], ['confirmed', 'active', 'completed'], true)) orderFail('Booking is not payable yet');

$amount = bookingPayable($bk);          // whole rupees
if ($amount <= 0) orderFail('Booking has no payable amount');

$orderData = [
    'amount'   => $amount * 100,   // paise
    'currency' => 'INR',
    'receipt'  => substr($bookingId, 0, 40),
    'notes'    => [
        'bookingId'   => $bookingId,
        'booking_id'  => $bookingId,          // legacy key read by webhook.php
        'service'     => (string)($bk['svc_name'] ?? 'Home Service'),
        'customer_id' => (string)$bk['customer_id'],
        'provider_id' => (string)($bk['provider_id'] ?? ''),
        'commission'  => COMMISSION_PCT . '%',
    ],
];

$ch = curl_init('https://api.razorpay.com/v1/orders');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => json_encode($orderData),
    CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
    CURLOPT_USERPWD        => RAZORPAY_KEY_ID . ':' . RAZORPAY_KEY_SECRET,
    CURLOPT_TIMEOUT        => 15,
]);
$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlErr  = curl_error($ch);
curl_close($ch);

if ($curlErr) { error_log('create-order network: ' . $curlErr); orderFail('Network error contacting payment gateway', 502); }
$order = json_decode($response, true);
if ($httpCode !== 200 || empty($order['id'])) {
    error_log('create-order razorpay error: ' . substr((string)$response, 0, 500));
    $desc = is_array($order) ? ($order['error']['description'] ?? '') : '';
    http_response_code(502);
    echo json_encode(['error' => 'Razorpay error', 'details' => ['error' => ['description' => $desc]]]);
    exit;
}

$db->prepare("UPDATE bookings SET razorpay_order_id = ? WHERE id = ?")->execute([$order['id'], $bookingId]);

echo json_encode([
    'order_id'   => $order['id'],
    'amount'     => $amount * 100,
    'currency'   => 'INR',
    'key_id'     => RAZORPAY_KEY_ID,
    'booking_id' => $bookingId,
]);
