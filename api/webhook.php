<?php
// ============================================================
//  api/webhook.php — Razorpay webhook → MySQL
//  Razorpay Dashboard → Settings → Webhooks
//  URL: https://hamaraservice.com/api/webhook.php
//  Events: payment.captured, payment.failed, refund.created
//  Secret: RZP_WEBHOOK_SECRET in ~/hs-secrets.php
//  Settles bookings the app could not confirm (app killed, network loss).
// ============================================================
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/schema.php';
require_once __DIR__ . '/razorpay.php';

header('Content-Type: application/json');

$payload   = file_get_contents('php://input');
$signature = $_SERVER['HTTP_X_RAZORPAY_SIGNATURE'] ?? '';
if (RZP_WEBHOOK_SECRET === '' || $signature === ''
    || !hash_equals(hash_hmac('sha256', $payload, RZP_WEBHOOK_SECRET), $signature)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid webhook signature']);
    exit;
}

$db = getDB();
ensureSchema($db);
$event   = json_decode($payload, true);
$type    = $event['event'] ?? '';
$payment = $event['payload']['payment']['entity'] ?? [];

function bookingByOrder($db, $orderId) {
    if (!$orderId) return null;
    $s = $db->prepare("SELECT * FROM bookings WHERE razorpay_order_id = ?");
    $s->execute([$orderId]);
    return $s->fetch() ?: null;
}

switch ($type) {
    case 'payment.captured': {
        // Provider wallet top-up?
        $t = $db->prepare("SELECT * FROM wallet_topups WHERE razorpay_order_id = ?");
        $t->execute([$payment['order_id'] ?? '']);
        if ($top = $t->fetch()) {
            if ((int)($payment['amount'] ?? 0) === (int)$top['amount'] * 100) creditTopup($db, $top, $payment['id'] ?? '');
            break;
        }
        $bk = bookingByOrder($db, $payment['order_id'] ?? '');
        if (!$bk || $bk['status'] !== 'completed' || (int)$bk['settled'] === 1) break;
        $expected = ((int)$bk['amount'] ?: (int)$bk['confirmed_price']) * 100;
        if ((int)($payment['amount'] ?? 0) !== $expected) {
            error_log("webhook: amount mismatch for {$bk['id']}: got {$payment['amount']} expected $expected");
            break;
        }
        $upd = $db->prepare("
            UPDATE bookings SET payment_status = 'paid', payment_method = 'online',
                   razorpay_payment_id = ?, paid_at = CURRENT_TIMESTAMP, settled = 1
            WHERE id = ? AND settled = 0
        ");
        $upd->execute([$payment['id'] ?? '', $bk['id']]);
        if ($upd->rowCount() === 1 && !empty($bk['provider_id'])) {
            $db->prepare("UPDATE providers SET total_earned = total_earned + ?, pending_earned = pending_earned + ? WHERE id = ?")
               ->execute([(int)$bk['provider_earns'], (int)$bk['provider_earns'], $bk['provider_id']]);
        }
        break;
    }

    case 'payment.failed':
        // Nothing to change: booking stays payment_status=pending, customer can retry.
        break;

    case 'refund.created': {
        $refund = $event['payload']['refund']['entity'] ?? [];
        $s = $db->prepare("SELECT * FROM bookings WHERE razorpay_payment_id = ?");
        $s->execute([$refund['payment_id'] ?? '']);
        $bk = $s->fetch();
        if (!$bk || $bk['payment_status'] === 'refunded') break;
        $upd = $db->prepare("UPDATE bookings SET payment_status = 'refunded' WHERE id = ? AND payment_status = 'paid'");
        $upd->execute([$bk['id']]);
        // Reverse the provider credit for a full refund of an online payment
        if ($upd->rowCount() === 1 && $bk['payment_method'] === 'online' && !empty($bk['provider_id'])
            && (int)($refund['amount'] ?? 0) >= (int)$bk['amount'] * 100) {
            $db->prepare("UPDATE providers SET total_earned = total_earned - ?, pending_earned = pending_earned - ? WHERE id = ?")
               ->execute([(int)$bk['provider_earns'], (int)$bk['provider_earns'], $bk['provider_id']]);
        }
        break;
    }
}

http_response_code(200);
echo json_encode(['status' => 'ok']);
