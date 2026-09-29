<?php
// ============================================================
//  api/webhook.php
//  Razorpay Dashboard → Settings → Webhooks
//  URL: https://hamaraservice.in/api/webhook.php
//  Events: payment.captured, payment.failed, refund.created
//  Secret: RAZORPAY_WEBHOOK_SECRET in config (see config.example.php) —
//  must equal the secret entered in the Razorpay webhook settings.
//  MySQL is the only data store — nothing is written to Firebase RTDB.
// ============================================================
require_once __DIR__ . '/db.php';

header('Content-Type: application/json');

$payload   = file_get_contents('php://input');
$signature = $_SERVER['HTTP_X_RAZORPAY_SIGNATURE'] ?? '';

// Verify webhook signature — ALWAYS required
if (!$signature) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing webhook signature']);
    exit;
}
if (RAZORPAY_WEBHOOK_SECRET === '') {
    error_log('HamaraService: RAZORPAY_WEBHOOK_SECRET not configured');
    http_response_code(500);
    echo json_encode(['error' => 'Webhook not configured']);
    exit;
}
$expectedSig = hash_hmac('sha256', $payload, RAZORPAY_WEBHOOK_SECRET);
if (!hash_equals($expectedSig, (string)$signature)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid webhook signature']);
    exit;
}

$event      = json_decode($payload, true);
$eventType  = $event['event'] ?? '';
$payment    = $event['payload']['payment']['entity'] ?? [];

switch ($eventType) {

    case 'payment.captured':
        $txnId      = $payment['id']       ?? '';
        $orderId    = $payment['order_id'] ?? '';
        $amountPs   = intval($payment['amount'] ?? 0);
        $notes      = $payment['notes']    ?? [];
        // Checkout usually doesn't copy order notes onto the payment — fall
        // back to the order (created by create-order.php with bookingId notes)
        if (empty($notes['booking_id']) && empty($notes['bookingId']) && $orderId) {
            $ord = razorpayGet('orders/' . rawurlencode($orderId));
            if ($ord && is_array($ord['notes'] ?? null)) $notes = $ord['notes'];
        }
        $bookingId  = (string)($notes['booking_id'] ?? $notes['bookingId'] ?? '');

        // MySQL booking: mark paid if the captured amount matches (idempotent)
        if ($bookingId && $txnId) {
            try {
                $db = getDB();
                $st = $db->prepare("SELECT * FROM bookings WHERE id = ?");
                $st->execute([$bookingId]);
                $bk = $st->fetch();
                if ($bk && bookingPayable($bk) * 100 === $amountPs) {
                    $db->prepare("UPDATE bookings SET payment_status = 'paid', payment_method = 'razorpay',
                                    razorpay_payment_id = ?, razorpay_order_id = ?
                                  WHERE id = ? AND payment_status != 'paid'")
                       ->execute([$txnId, $orderId, $bookingId]);
                }
            } catch (Throwable $e) {
                // 500 → Razorpay retries the delivery later
                error_log('webhook mysql: ' . $e->getMessage());
                http_response_code(500);
                echo json_encode(['error' => 'Temporary failure']);
                exit;
            }
        }
        break;

    case 'payment.failed':
        // Nothing to store: the booking stays unpaid and the customer can retry.
        error_log('Razorpay payment.failed: ' . ($payment['id'] ?? '?') . ' ' . substr((string)($payment['error_description'] ?? ''), 0, 200));
        break;

    case 'refund.created':
        // Refunds are handled manually by admin; log for the audit trail.
        $refund = $event['payload']['refund']['entity'] ?? [];
        error_log('Razorpay refund.created: ' . ($refund['id'] ?? '?') . ' for payment ' . ($refund['payment_id'] ?? '?')
                  . ' amount ' . (int)($refund['amount'] ?? 0));
        break;
}

http_response_code(200);
echo json_encode(['status' => 'ok']);
