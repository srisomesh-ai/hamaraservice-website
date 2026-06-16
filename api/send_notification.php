<?php
// HamaraService Push Notification Sender
// Place at: hamaraservice.com/api/send_notification.php
// Requires: service-account.json in same folder

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

// ── Load service account ──────────────────────────────────────
$serviceAccountPath = __DIR__ . '/service-account.json';
if (!file_exists($serviceAccountPath)) {
    echo json_encode(['error' => 'Service account not found']);
    exit;
}
$serviceAccount = json_decode(file_get_contents($serviceAccountPath), true);

// ── Get OAuth2 access token ──────────────────────────────────
function getAccessToken($serviceAccount) {
    $now = time();
    $header = base64url_encode(json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
    $payload = base64url_encode(json_encode([
        'iss'   => $serviceAccount['client_email'],
        'scope' => 'https://www.googleapis.com/auth/firebase.messaging',
        'aud'   => 'https://oauth2.googleapis.com/token',
        'iat'   => $now,
        'exp'   => $now + 3600,
    ]));
    $toSign = "$header.$payload";
    openssl_sign($toSign, $signature, $serviceAccount['private_key'], 'SHA256');
    $jwt = "$toSign." . base64url_encode($signature);

    $ch = curl_init('https://oauth2.googleapis.com/token');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POSTFIELDS => http_build_query([
            'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
            'assertion'  => $jwt,
        ]),
    ]);
    $response = json_decode(curl_exec($ch), true);
    curl_close($ch);
    return $response['access_token'] ?? null;
}

function base64url_encode($data) {
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

// ── Send FCM notification ────────────────────────────────────
function sendFCM($token, $title, $body, $data = [], $serviceAccount) {
    $accessToken = getAccessToken($serviceAccount);
    if (!$accessToken) return ['error' => 'Could not get access token'];

    $projectId = $serviceAccount['project_id'];
    $url = "https://fcm.googleapis.com/v1/projects/$projectId/messages:send";

    $message = [
        'message' => [
            'token' => $token,
            'notification' => [
                'title' => $title,
                'body'  => $body,
            ],
            'android' => [
                'priority' => 'high',
                'notification' => [
                    'channel_id'  => 'hamaraservice_high_priority',
                    'sound'       => 'default',
                    'priority'    => 'high',
                    'default_vibrate_timings' => true,
                    'visibility'  => 'PUBLIC',
                ],
            ],
            'apns' => [
                'headers' => ['apns-priority' => '10'],
                'payload' => [
                    'aps' => [
                        'sound'             => 'default',
                        'badge'             => 1,
                        'content-available' => 1,
                    ],
                ],
            ],
            'data' => array_map('strval', $data),
        ],
    ];

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bearer ' . $accessToken,
            'Content-Type: application/json',
        ],
        CURLOPT_POSTFIELDS => json_encode($message),
    ]);
    $response = json_decode(curl_exec($ch), true);
    curl_close($ch);
    return $response;
}

// ── Main: receive request and send notification ──────────────
$input = json_decode(file_get_contents('php://input'), true);
if (!$input) $input = $_POST;

$token  = $input['token']  ?? '';
$title  = $input['title']  ?? 'HamaraService';
$body   = $input['body']   ?? '';
$data   = $input['data']   ?? [];

if (empty($token) || empty($body)) {
    echo json_encode(['error' => 'token and body required']);
    exit;
}

$result = sendFCM($token, $title, $body, $data, $serviceAccount);
echo json_encode($result);
