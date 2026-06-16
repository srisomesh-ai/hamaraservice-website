<?php
// HamaraService — Booking Event Notifier
// Called by Firebase Realtime Database via cron or triggered from app
// Place at: hamaraservice.com/api/notify_booking.php

header('Content-Type: application/json');
require_once __DIR__ . '/send_notification.php';

// ── Notification templates ────────────────────────────────────
function getNotification($event, $data) {
    switch ($event) {
        // Customer notifications
        case 'booking_accepted':
            return [
                'title' => '✅ Provider Accepted!',
                'body'  => "{$data['providerName']} accepted your {$data['service']} booking.",
            ];
        case 'provider_on_way':
            return [
                'title' => '🚗 Provider On The Way!',
                'body'  => "{$data['providerName']} is heading to your location.",
            ];
        case 'otp_requested':
            return [
                'title' => '🔐 OTP Required',
                'body'  => "Share OTP {$data['otp']} with your provider to complete the service.",
            ];
        case 'booking_completed':
            return [
                'title' => '🎉 Service Completed!',
                'body'  => "Your {$data['service']} is done. Please rate your experience.",
            ];
        case 'booking_cancelled_by_provider':
            return [
                'title' => '❌ Booking Cancelled',
                'body'  => "Your provider cancelled the {$data['service']} booking. We\'ll find you another.",
            ];

        // Provider notifications
        case 'new_booking':
            return [
                'title' => '🔔 New Job Alert!',
                'body'  => "New {$data['service']} request near you. Rs.{$data['price']}. Tap to accept!",
            ];
        case 'payment_received':
            return [
                'title' => '💰 Payment Received!',
                'body'  => "Rs.{$data['amount']} received for {$data['service']}. Great work!",
            ];
        case 'new_review':
            return [
                'title' => '⭐ New Review!',
                'body'  => "You got a {$data['rating']}★ review for {$data['service']}.",
            ];
        case 'payout_approved':
            return [
                'title' => '✅ Payout Approved!',
                'body'  => "Your withdrawal of Rs.{$data['amount']} has been approved.",
            ];
        default:
            return ['title' => 'HamaraService', 'body' => $data['message'] ?? ''];
    }
}

// ── Process request ───────────────────────────────────────────
$input = json_decode(file_get_contents('php://input'), true);
if (!$input) $input = $_POST;

$event    = $input['event']    ?? '';
$fcmToken = $input['fcmToken'] ?? '';
$data     = $input['data']     ?? [];

if (empty($event) || empty($fcmToken)) {
    echo json_encode(['error' => 'event and fcmToken required']);
    exit;
}

$notification = getNotification($event, $data);
$serviceAccount = json_decode(file_get_contents(__DIR__ . '/service-account.json'), true);

$result = sendFCM(
    $fcmToken,
    $notification['title'],
    $notification['body'],
    array_merge($data, ['event' => $event]),
    $serviceAccount
);

echo json_encode([
    'sent'   => isset($result['name']),
    'event'  => $event,
    'result' => $result,
]);
