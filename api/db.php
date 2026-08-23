<?php
// ── HamaraService Database Config ─────────────────────────
define('DB_HOST', 'localhost');
define('DB_NAME', 'u943205660_hamaraservice');
define('DB_USER', 'u943205660_u123456_hs');
define('DB_PASS', 'Simhadriappanna@143');
define('DB_CHARSET', 'utf8mb4');

// Firebase project ID for token verification
define('FIREBASE_PROJECT_ID', 'hamaraservice-s009');

// JWT secret for provider tokens
define('JWT_SECRET', 'hamaraHS_jwt_2024_Simha@143');

function getDB() {
    static $pdo = null;
    if ($pdo !== null) return $pdo;
    $dsn = "mysql:host=".DB_HOST.";dbname=".DB_NAME.";charset=".DB_CHARSET;
    $options = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ];
    try {
        $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['success'=>false,'error'=>'Database connection failed']);
        exit;
    }
    return $pdo;
}

// ── CORS ──────────────────────────────────────────────────
function setCorsHeaders() {
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Admin-Key, X-Requested-With');
    header('Access-Control-Max-Age: 86400');
    header('Content-Type: application/json; charset=utf-8');
    if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
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

// ── Firebase token verify ─────────────────────────────────
// Verifies Firebase ID token from customer app
function verifyFirebaseToken($token) {
    if (empty($token)) return null;
    // Use Google's public endpoint to verify
    $url = 'https://oauth2.googleapis.com/tokeninfo?id_token='.urlencode($token);
    $ctx = stream_context_create(['http'=>['timeout'=>5]]);
    $resp = @file_get_contents($url, false, $ctx);
    if (!$resp) return null;
    $data = json_decode($resp, true);
    if (empty($data['sub'])) return null;
    if (($data['aud'] ?? '') !== FIREBASE_PROJECT_ID) return null;
    if (($data['exp'] ?? 0) < time()) return null;
    return [
        'uid'   => $data['sub'],
        'email' => $data['email'] ?? '',
        'name'  => $data['name']  ?? '',
    ];
}

// ── Provider JWT ──────────────────────────────────────────
function generateJWT($providerId) {
    $h = rtrim(base64_encode(json_encode(['alg'=>'HS256','typ'=>'JWT'])),  '=');
    $p = rtrim(base64_encode(json_encode([
        'id'  => $providerId,
        'iat' => time(),
        'exp' => time() + (30*24*3600),
    ])), '=');
    $s = rtrim(base64_encode(hash_hmac('sha256',"$h.$p",JWT_SECRET,true)),'=');
    return "$h.$p.$s";
}
function verifyJWT($token) {
    if (empty($token)) return null;
    $parts = explode('.', $token);
    if (count($parts) !== 3) return null;
    [$h,$p,$s] = $parts;
    $expected = rtrim(base64_encode(hash_hmac('sha256',"$h.$p",JWT_SECRET,true)),'=');
    if (!hash_equals($expected,$s)) return null;
    $data = json_decode(base64_decode($p), true);
    if (($data['exp']??0) < time()) return null;
    return $data;
}

function makeJWT($payload, $days = 30) {
    $payload['exp'] = time() + ($days * 86400);
    $h = rtrim(base64_encode(json_encode(['alg'=>'HS256','typ'=>'JWT'])), '=');
    $p = rtrim(base64_encode(json_encode($payload)), '=');
    $s = rtrim(base64_encode(hash_hmac('sha256', "$h.$p", JWT_SECRET, true)), '=');
    return "$h.$p.$s";
}

// ── Auth middleware ───────────────────────────────────────
function requireCustomer() {
    $auth  = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    $token = str_replace('Bearer ', '', $auth);
    // Web app JWT first (fast, local), then Firebase token (mobile app)
    $jwt = verifyJWT($token);
    if ($jwt && ($jwt['type'] ?? '') === 'customer') {
        return ['uid' => $jwt['uid'], 'email' => $jwt['email'] ?? '', 'name' => $jwt['name'] ?? ''];
    }
    $user = verifyFirebaseToken($token);
    if (!$user) err('Unauthorized', 401);
    return $user;
}
function requireProvider() {
    $auth  = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    $token = str_replace('Bearer ', '', $auth);
    $data  = verifyJWT($token);
    if (!$data) err('Unauthorized', 401);
    return $data;
}
function requireAdmin() {
    // Accept key from header OR query param (for CORS-restricted browsers)
    $key = $_SERVER['HTTP_X_ADMIN_KEY']
        ?? $_GET['key']
        ?? '';
    if ($key !== 'hamaraAdmin2024') err('Unauthorized', 401);
}
