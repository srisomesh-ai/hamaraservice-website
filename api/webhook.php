<?php
// ============================================================
//  api/webhook.php
//  Razorpay Dashboard → Settings → Webhooks
//  URL: https://hamaraservice.in/api/webhook.php
//  Events: payment.captured, payment.failed, refund.created
//  Set a Webhook Secret in Razorpay and paste it below
// ============================================================
define('RZP_WEBHOOK_SECRET', 'HamaraSvr@2026');
define('RZP_KEY_SECRET',     '7XI9IQ3TT1OyNs5ALYeSxiuz');
define('COMMISSION_PCT', 12);
define('FB_URL', 'https://hamaraservice-s009-default-rtdb.asia-southeast1.firebasedatabase.app');

header('Content-Type: application/json');

$payload   = file_get_contents('php://input');
$signature = $_SERVER['HTTP_X_RAZORPAY_SIGNATURE'] ?? '';

// Verify webhook signature — ALWAYS required
if (!$signature) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing webhook signature']);
    exit;
}
$expectedSig = hash_hmac('sha256', $payload, RZP_WEBHOOK_SECRET);
if (!hash_equals($expectedSig, $signature)) {
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
        $bookingId  = $notes['booking_id'] ?? '';
        $providerId = $notes['provider_id'] ?? '';
        $customerId = $notes['customer_id'] ?? '';

        if ($bookingId && $txnId) {
            // Avoid double credit — check if already settled
            $existing = fb_get('transactions/' . $txnId);
            if (empty($existing['status'])) {
                $amount          = $amountPs / 100;
                $commAmt         = (int) round($amount * COMMISSION_PCT / 100);
                $provAmt         = $amount - $commAmt;
                $now             = date('c');

                fb_patch('bookings/'        . $bookingId, [
                    'status'          => 'paid',
                    'paidAt'          => $now,
                    'txnId'           => $txnId,
                    'paymentMethod'   => 'razorpay',
                    'totalAmount'     => $amount,
                    'commissionAmt'   => $commAmt,
                    'providerEarning' => $provAmt,
                    'commissionPct'   => COMMISSION_PCT,
                ]);
                fb_patch('active_bookings/' . $bookingId, ['status'=>'paid','paidAt'=>$now,'txnId'=>$txnId]);

                if ($providerId) {
                    $wallet = fb_get('provider_wallet/' . $providerId);
                    fb_put('provider_wallet/' . $providerId, [
                        'providerId'      => $providerId,
                        'balance'         => floatval($wallet['balance']         ?? 0) + $provAmt,
                        'totalEarned'     => floatval($wallet['totalEarned']     ?? 0) + $provAmt,
                        'totalCommission' => floatval($wallet['totalCommission'] ?? 0) + $commAmt,
                        'lastCredited'    => $now,
                        'lastBookingId'   => $bookingId,
                    ]);
                }

                fb_put('transactions/' . $txnId, [
                    'txnId'           => $txnId,
                    'bookingId'       => $bookingId,
                    'providerId'      => $providerId,
                    'customerId'      => $customerId,
                    'totalAmount'     => $amount,
                    'commissionAmt'   => $commAmt,
                    'providerEarning' => $provAmt,
                    'commissionPct'   => COMMISSION_PCT,
                    'paymentMethod'   => 'razorpay',
                    'rzpOrderId'      => $orderId,
                    'rzpPaymentId'    => $txnId,
                    'status'          => 'settled',
                    'createdAt'       => $now,
                    'source'          => 'webhook',
                ]);

                // Mirror admin earnings record (parity with verify-payment.php)
                fb_put('admin_earnings/' . date('Y-m') . '/' . $bookingId, [
                    'bookingId'  => $bookingId,
                    'commission' => $commAmt,
                    'total'      => $amount,
                    'month'      => date('Y-m'),
                    'createdAt'  => $now,
                    'source'     => 'webhook',
                ]);
            }
        }
        break;

    case 'payment.failed':
        $notes     = $payment['notes']    ?? [];
        $bookingId = $notes['booking_id'] ?? '';
        if ($bookingId) {
            fb_patch('bookings/' . $bookingId, [
                'paymentFailed'   => true,
                'paymentFailedAt' => date('c'),
                'paymentError'    => $payment['error_description'] ?? 'Payment failed',
            ]);
        }
        break;

    case 'refund.created':
        $refund    = $event['payload']['refund']['entity'] ?? [];
        $refId     = $refund['id']         ?? '';
        $payId     = $refund['payment_id'] ?? '';
        $refAmtPs  = intval($refund['amount'] ?? 0);
        $txn       = fb_get('transactions/' . $payId);
        if (!empty($txn['bookingId'])) {
            fb_patch('bookings/' . $txn['bookingId'], [
                'status'     => 'refunded',
                'refundId'   => $refId,
                'refundAmt'  => $refAmtPs / 100,
                'refundedAt' => date('c'),
            ]);
        }
        break;
}

http_response_code(200);
echo json_encode(['status' => 'ok']);

function fb_patch($path, $data) {
    $ch = curl_init(FB_URL . '/' . $path . '.json');
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_CUSTOMREQUEST=>'PATCH', CURLOPT_POSTFIELDS=>json_encode($data), CURLOPT_HTTPHEADER=>['Content-Type: application/json'], CURLOPT_TIMEOUT=>6]);
    curl_exec($ch); curl_close($ch);
}
function fb_put($path, $data) {
    $ch = curl_init(FB_URL . '/' . $path . '.json');
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_CUSTOMREQUEST=>'PUT', CURLOPT_POSTFIELDS=>json_encode($data), CURLOPT_HTTPHEADER=>['Content-Type: application/json'], CURLOPT_TIMEOUT=>6]);
    curl_exec($ch); curl_close($ch);
}
function fb_get($path) {
    $ch = curl_init(FB_URL . '/' . $path . '.json');
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_TIMEOUT=>6]);
    $r = curl_exec($ch); curl_close($ch);
    return json_decode($r, true) ?? [];
}
