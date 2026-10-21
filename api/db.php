<?php
// ── HamaraService — shared DB, auth and response helpers ──
require_once __DIR__ . '/config.php';

function getDB() {
    static $pdo = null;
    if ($pdo !== null) return $pdo;
    if (DB_NAME === '' || JWT_SECRET === '') {
        http_response_code(500);
        echo json_encode(['success'=>false,'error'=>'Server not configured (hs-secrets.php missing)']);
        exit;
    }
    $dsn = "mysql:host=".DB_HOST.";dbname=".DB_NAME.";charset=".DB_CHARSET;
    $options = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ];
    try {
        $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
        $pdo->exec("SET time_zone = '+05:30'");   // match PHP (Asia/Kolkata)
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['success'=>false,'error'=>'Database connection failed']);
        exit;
    }
    return $pdo;
}

// ── CORS ──────────────────────────────────────────────────
// Mobile apps send no Origin header and are unaffected. Browsers are
// limited to our own domains.
function setCorsHeaders() {
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    if ($origin !== '' && in_array($origin, ALLOWED_ORIGINS, true)) {
        header('Access-Control-Allow-Origin: ' . $origin);
        header('Vary: Origin');
    }
    header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Admin-Key, X-Requested-With');
    header('Access-Control-Max-Age: 86400');
    header('Content-Type: application/json; charset=utf-8');
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
        http_response_code(200); exit;
    }
}

// ── Response helpers ──────────────────────────────────────
function ok($data = [], $code = 200) {
    http_response_code($code);
    echo json_encode(['success'=>true, 'data'=>$data]);
    exit;
}
function err($message, $code = 400) {
    http_response_code($code);
    echo json_encode(['success'=>false, 'error'=>$message]);
    exit;
}
function getBody() {
    return json_decode(file_get_contents('php://input'), true) ?? [];
}

// Cryptographically secure numeric OTP
function makeOtp($digits = 4) {
    return str_pad((string)random_int(0, 10 ** $digits - 1), $digits, '0', STR_PAD_LEFT);
}

// ── base64url ─────────────────────────────────────────────
function b64url_enc($data) { return rtrim(strtr(base64_encode($data), '+/', '-_'), '='); }
function b64url_dec($data) {
    $data = strtr($data, '-_', '+/');
    return base64_decode($data . str_repeat('=', (4 - strlen($data) % 4) % 4));
}

// ── Firebase ID token verification (RS256, securetoken certs) ──
function firebaseCerts() {
    $cache = sys_get_temp_dir() . '/hs_fb_certs.json';
    if (is_readable($cache) && filemtime($cache) > time() - 3600) {
        $c = json_decode(file_get_contents($cache), true);
        if (is_array($c) && $c) return $c;
    }
    $ctx  = stream_context_create(['http'=>['timeout'=>5]]);
    $resp = @file_get_contents('https://www.googleapis.com/robot/v1/metadata/x509/securetoken@system.gserviceaccount.com', false, $ctx);
    $c = $resp ? json_decode($resp, true) : null;
    if (is_array($c) && $c) { @file_put_contents($cache, $resp); return $c; }
    return [];
}

function verifyFirebaseToken($token) {
    if (empty($token)) return null;
    $parts = explode('.', $token);
    if (count($parts) !== 3) return null;
    [$h64, $p64, $s64] = $parts;
    $header  = json_decode(b64url_dec($h64), true);
    $payload = json_decode(b64url_dec($p64), true);
    if (!$header || !$payload || ($header['alg'] ?? '') !== 'RS256') return null;

    $certs = firebaseCerts();
    $cert  = $certs[$header['kid'] ?? ''] ?? null;
    if (!$cert) return null;
    $pub = openssl_pkey_get_public($cert);
    if (!$pub || openssl_verify("$h64.$p64", b64url_dec($s64), $pub, OPENSSL_ALGO_SHA256) !== 1) return null;

    $now = time();
    if (($payload['aud'] ?? '') !== FIREBASE_PROJECT_ID) return null;
    if (($payload['iss'] ?? '') !== 'https://securetoken.google.com/' . FIREBASE_PROJECT_ID) return null;
    if (($payload['exp'] ?? 0) < $now - 60) return null;
    if (($payload['iat'] ?? 0) > $now + 300) return null;
    if (empty($payload['sub'])) return null;

    return [
        'uid'   => $payload['sub'],
        'email' => $payload['email'] ?? '',
        'name'  => $payload['name']  ?? '',
        'phone' => $payload['phone_number'] ?? '',
        'email_verified' => !empty($payload['email_verified']),
    ];
}

// ── HS256 JWT (provider / web-customer / admin) ───────────
function makeJWT($payload, $days = 30) {
    $payload['iat'] = time();
    $payload['exp'] = time() + (int)round($days * 86400);
    $h = b64url_enc(json_encode(['alg'=>'HS256','typ'=>'JWT']));
    $p = b64url_enc(json_encode($payload));
    $s = b64url_enc(hash_hmac('sha256', "$h.$p", JWT_SECRET, true));
    return "$h.$p.$s";
}
function generateJWT($providerId) {
    return makeJWT(['type' => 'provider', 'id' => $providerId], 30);
}
function verifyJWT($token) {
    if (empty($token) || JWT_SECRET === '') return null;
    $parts = explode('.', $token);
    if (count($parts) !== 3) return null;
    [$h, $p, $s] = $parts;
    $expected = b64url_enc(hash_hmac('sha256', "$h.$p", JWT_SECRET, true));
    if (!hash_equals($expected, $s)) return null;
    $data = json_decode(b64url_dec($p), true);
    if (!is_array($data) || ($data['exp'] ?? 0) < time()) return null;
    return $data;
}

function bearerToken() {
    $auth = $_SERVER['HTTP_AUTHORIZATION']
        ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION']
        ?? (function_exists('apache_request_headers') ? (apache_request_headers()['Authorization'] ?? '') : '');
    return preg_match('/Bearer\s+(\S+)/i', $auth, $m) ? $m[1] : '';
}

// ── Auth middleware ───────────────────────────────────────
// Returns ['role'=>'customer'|'provider'|'admin', 'id'=>…, …] or null
function currentUser() {
    static $cached = false;
    if ($cached !== false) return $cached;
    $token = bearerToken();
    $cached = null;
    if ($token === '') return null;

    $jwt = verifyJWT($token);
    if ($jwt) {
        // email-verification tokens (otp.php) are never login tokens
        if (($jwt['typ'] ?? '') === 'email_otp') return null;
        $type = $jwt['type'] ?? '';
        if ($type === 'customer' && !empty($jwt['uid'])) {
            $cached = ['role'=>'customer','id'=>$jwt['uid'],'uid'=>$jwt['uid'],'email'=>$jwt['email'] ?? '','name'=>$jwt['name'] ?? ''];
        } elseif ($type === 'admin') {
            $cached = ['role'=>'admin','id'=>'admin'];
        } elseif (($type === 'provider' || $type === '') && !empty($jwt['id'])) {
            // legacy provider tokens carry no type
            $cached = ['role'=>'provider','id'=>$jwt['id']];
        }
        return $cached;
    }
    $fb = verifyFirebaseToken($token);
    if ($fb) $cached = array_merge(['role'=>'customer','id'=>$fb['uid']], $fb);
    return $cached;
}

function requireCustomer() {
    $u = currentUser();
    if (!$u || $u['role'] !== 'customer') err('Unauthorized', 401);
    return $u;
}

// $approved = true → provider account must be approved (for job actions)
function requireProvider($approved = false) {
    $u = currentUser();
    if (!$u || $u['role'] !== 'provider') err('Unauthorized', 401);
    $stmt = getDB()->prepare("SELECT status FROM providers WHERE id = ?");
    $stmt->execute([$u['id']]);
    $status = $stmt->fetchColumn();
    if ($status === false) err('Unauthorized', 401);
    if (in_array($status, ['suspended','rejected','deleted'], true)) err('Account ' . $status, 403);
    if ($approved && $status !== 'approved') err('Account not approved yet', 403);
    $u['status'] = $status;
    return $u;
}

function isAdminRequest() {
    $u = currentUser();
    if ($u && $u['role'] === 'admin') return true;
    $key = $_SERVER['HTTP_X_ADMIN_KEY'] ?? '';
    return ADMIN_KEY !== '' && $key !== '' && hash_equals(ADMIN_KEY, $key);
}
function requireAdmin() {
    if (!isAdminRequest()) err('Unauthorized', 401);
}

// ── Push notifications ───────────────────────────────────
// Sent straight to FCM (HTTP v1) with the Firebase service account when
// hs-firebase.json is present; otherwise via the Cloud Function relay.
// Data-only messages: the apps display them (and wake the screen) themselves.
function sendPush($fcmToken, $title, $body, $data = []) {
    if (empty($fcmToken)) return;
    $sa = fcmServiceAccount();
    if ($sa) { fcmSendDirect($sa, $fcmToken, $title, $body, $data); return; }
    if (PUSH_KEY === '') return;
    $payload = json_encode([
        'event'    => $data['event'] ?? 'notification',
        'fcmToken' => $fcmToken,
        'title'    => $title,
        'body'     => $body,
        'data'     => $data,
    ]);
    $ctx = stream_context_create(['http'=>[
        'method'  => 'POST',
        'header'  => "Content-Type: application/json\r\nX-HS-Key: " . PUSH_KEY . "\r\n",
        'content' => $payload,
        'timeout' => 5,
        'ignore_errors' => true,
    ]]);
    @file_get_contents(PUSH_URL, false, $ctx);
}

function pushMode() {
    if (fcmServiceAccount()) return 'direct';
    return PUSH_KEY !== '' ? 'relay' : 'off';
}

function fcmServiceAccount() {
    static $sa = null;
    if ($sa !== null) return $sa ?: null;
    $j = is_readable(FCM_SA_FILE) ? json_decode((string)file_get_contents(FCM_SA_FILE), true) : null;
    $sa = (is_array($j) && !empty($j['client_email']) && !empty($j['private_key']) && !empty($j['project_id'])) ? $j : false;
    return $sa ?: null;
}

function fcmHttp($url, $headers, $body) {
    $ctx = stream_context_create(['http' => [
        'method' => 'POST', 'header' => implode("\r\n", $headers) . "\r\n",
        'content' => $body, 'timeout' => 5, 'ignore_errors' => true,
    ]]);
    $res = @file_get_contents($url, false, $ctx);
    return $res === false ? null : json_decode($res, true);
}

// OAuth access token from the service account (signed JWT), cached ~55 min.
function fcmAccessToken(array $sa) {
    $cache = sys_get_temp_dir() . '/hs_fcm_' . md5($sa['client_email']) . '.json';
    $c = is_readable($cache) ? json_decode((string)@file_get_contents($cache), true) : null;
    if (!empty($c['token']) && ($c['exp'] ?? 0) > time() + 60) return $c['token'];
    $b64 = function ($v) { return rtrim(strtr(base64_encode($v), '+/', '-_'), '='); };
    $now = time();
    $unsigned = $b64(json_encode(['alg' => 'RS256', 'typ' => 'JWT'])) . '.' . $b64(json_encode([
        'iss' => $sa['client_email'], 'scope' => 'https://www.googleapis.com/auth/firebase.messaging',
        'aud' => FCM_OAUTH_URL, 'iat' => $now, 'exp' => $now + 3600,
    ]));
    if (!openssl_sign($unsigned, $sig, $sa['private_key'], 'sha256WithRSAEncryption')) return null;
    $r = fcmHttp(FCM_OAUTH_URL, ['Content-Type: application/x-www-form-urlencoded'], http_build_query([
        'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer', 'assertion' => $unsigned . '.' . $b64($sig),
    ]));
    if (empty($r['access_token'])) { error_log('FCM token error: ' . json_encode($r)); return null; }
    @file_put_contents($cache, json_encode(['token' => $r['access_token'], 'exp' => $now + (int)($r['expires_in'] ?? 3600)]));
    @chmod($cache, 0600);
    return $r['access_token'];
}

function fcmSendDirect(array $sa, $fcmToken, $title, $body, $data) {
    $tok = fcmAccessToken($sa);
    if (!$tok) return false;
    $d = [];
    foreach ($data as $k => $v) $d[(string)$k] = is_scalar($v) ? (string)$v : json_encode($v);
    $d += ['event' => 'notification'];
    $d['title'] = (string)($title ?: 'HamaraService');
    $d['body']  = (string)($body ?: 'You have a new update.');
    $d['click_action'] = 'FLUTTER_NOTIFICATION_CLICK';
    $d['channel_id']   = 'hamaraservice_high_priority';
    $r = fcmHttp(FCM_API_BASE . '/v1/projects/' . rawurlencode($sa['project_id']) . '/messages:send',
        ['Content-Type: application/json', 'Authorization: Bearer ' . $tok],
        json_encode(['message' => [
            'token' => $fcmToken, 'data' => $d,
            'android' => ['priority' => 'HIGH', 'ttl' => '3600s'],
            'apns' => ['headers' => ['apns-priority' => '10', 'apns-push-type' => 'background'],
                       'payload' => ['aps' => ['content-available' => 1, 'sound' => 'default']]],
        ]]));
    if (empty($r['name'])) { error_log('FCM send error: ' . json_encode($r)); return false; }
    return true;
}
