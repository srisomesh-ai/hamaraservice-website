<?php
// ============================================================
//  api/create-order.php
// ============================================================
// Test mode keys (replace with your actual test keys from Razorpay dashboard)
define('RZP_KEY_ID',     'rzp_test_Svq7brYQvxA6kz');
define('RZP_KEY_SECRET', 'vHNYS7qh04Fyklra0YbzB6Iy');

// Live mode keys (use only after testing)
// define('RZP_KEY_ID',     'rzp_live_SncS4l8burrGsR');
// define('RZP_KEY_SECRET', '7XI9IQ3TT1OyNs5ALYeSxiuz');
define('COMMISSION_PCT', 12);

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
if ($_SERVER['REQUEST_METHOD'] !== 'POST')    { http_response_code(405); echo json_encode(['error'=>'Method not allowed']); exit; }

$body       = json_decode(file_get_contents('php://input'), true);
$bookingId  = preg_replace('/[^A-Za-z0-9\-]/', '', $body['bookingId']  ?? '');
$amount     = intval($body['amount']     ?? 0);
$service    = htmlspecialchars($body['service']    ?? 'Home Service');
$customerId = htmlspecialchars($body['customerId'] ?? '');
$providerId = htmlspecialchars($body['providerId'] ?? '');

if (!$bookingId || $amount <= 0) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid booking or amount']);
    exit;
}

$orderData = [
    'amount'   => $amount * 100,   // paise
    'currency' => 'INR',
    'receipt'  => $bookingId,
    'notes'    => [
        'booking_id'  => $bookingId,
        'service'     => $service,
        'customer_id' => $customerId,
        'provider_id' => $providerId,
        'commission'  => COMMISSION_PCT . '%',
    ],
];

$ch = curl_init('https://api.razorpay.com/v1/orders');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => json_encode($orderData),
    CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
    CURLOPT_USERPWD        => RZP_KEY_ID . ':' . RZP_KEY_SECRET,
    CURLOPT_TIMEOUT        => 15,
]);
$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlErr  = curl_error($ch);
curl_close($ch);

if ($curlErr) { http_response_code(500); echo json_encode(['error'=>'Network error: '.$curlErr]); exit; }
$order = json_decode($response, true);
if ($httpCode !== 200 || empty($order['id'])) {
    http_response_code(500);
    echo json_encode(['error'=>'Razorpay error', 'details'=>$order]);
    exit;
}

echo json_encode([
    'order_id'   => $order['id'],
    'amount'     => $amount * 100,
    'currency'   => 'INR',
    'key_id'     => RZP_KEY_ID,
    'booking_id' => $bookingId,
]);
