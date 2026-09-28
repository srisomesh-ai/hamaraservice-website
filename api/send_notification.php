<?php
// HamaraService Push Notification Sender
// Place at: hamaraservice.com/api/send_notification.php
//
// Service account JSON path comes from config: SERVICE_ACCOUNT_PATH
// (keep the file OUTSIDE the web root — see api/config.example.php).
//
// Direct HTTP use requires the admin key (X-Admin-Key) or a valid
// provider / customer token (Authorization: Bearer ...).
// When included by another script (notify_booking.php) only the
// functions below are defined; the request handler does not run.

require_once __DIR__ . '/db.php';

// ── Load service account ──────────────────────────────────────
function loadServiceAccount() {
    $path = SERVICE_ACCOUNT_PATH;
    if ($path === '' || !is_file($path) || !is_readable($path)) {
        error_log('HamaraService: SERVICE_ACCOUNT_PATH not configured or unreadable');
        return null;
    }
    $sa = json_decode((string)file_get_contents($path), true);
    return (is_array($sa) && !empty($sa['private_key']) && !empty($sa['client_email'])) ? $sa : null;
}

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
        CURLOPT_TIMEOUT => 10,
        CURLOPT_POSTFIELDS => http_build_query([
            'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
            'assertion'  => $jwt,
        ]),
    ]);
    $response = json_decode((string)curl_exec($ch), true);
    curl_close($ch);
    return $response['access_token'] ?? null;
}

if (!function_exists('base64url_encode')) {
    function base64url_encode($data) {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
}

// ── Send FCM notification ────────────────────────────────────
function sendFCM($token, $title, $body, $data, $serviceAccount) {
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
            'data' => array_map('strval', array_filter((array)$data, 'is_scalar')),
        ],
    ];

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bearer ' . $accessToken,
            'Content-Type: application/json',
        ],
        CURLOPT_POSTFIELDS => json_encode($message),
    ]);
    $response = json_decode((string)curl_exec($ch), true);
    curl_close($ch);
    return $response;
}

// ── Main: only when this file is the requested script ────────
if (basename(__FILE__) === basename($_SERVER['SCRIPT_FILENAME'] ?? '')) {
    setCorsHeaders();
    if (!isAdminRequest() && !resolveAuth()) err('Unauthorized', 401);

    $input = getBody();
    if (!$input) $input = $_POST;

    $token  = (string)($input['token']  ?? '');
    $title  = (string)($input['title']  ?? 'HamaraService');
    $body   = (string)($input['body']   ?? '');
    $data   = is_array($input['data'] ?? null) ? $input['data'] : [];

    if ($token === '' || $body === '') err('token and body required');

    $serviceAccount = loadServiceAccount();
    if (!$serviceAccount) err('Push service not configured', 500);

    $result = sendFCM($token, $title, $body, $data, $serviceAccount);
    echo json_encode($result);
}
