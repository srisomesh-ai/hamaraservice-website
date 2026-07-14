<?php
// ── HamaraService Database Config ─────────────────────────
// Replace with your Hostinger MySQL credentials

define('DB_HOST', 'localhost');
define('DB_NAME', 'your_database_name');   // e.g. u123456789_hamaraservice
define('DB_USER', 'your_database_user');   // e.g. u123456789_hs
define('DB_PASS', 'your_database_password');
define('DB_CHARSET', 'utf8mb4');

function getDB() {
    static $pdo = null;
    if ($pdo !== null) return $pdo;

    $dsn = "mysql:host=" . DB_HOST
         . ";dbname=" . DB_NAME
         . ";charset=" . DB_CHARSET;

    $options = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ];

    try {
        $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Database connection failed']);
        exit;
    }

    return $pdo;
}

// ── CORS headers ──────────────────────────────────────────
function setCorsHeaders() {
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, Authorization');
    header('Content-Type: application/json; charset=utf-8');
    if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
        http_response_code(200);
        exit;
    }
}

// ── JSON response helpers ─────────────────────────────────
function ok($data = [], $code = 200) {
    http_response_code($code);
    echo json_encode(['success' => true, 'data' => $data]);
    exit;
}

function err($message, $code = 400) {
    http_response_code($code);
    echo json_encode(['success' => false, 'error' => $message]);
    exit;
}

// ── Get request body ──────────────────────────────────────
function getBody() {
    $raw = file_get_contents('php://input');
    return json_decode($raw, true) ?? [];
}

// ── Firebase Auth token verification ─────────────────────
// Verifies the Firebase ID token sent from the Flutter app
function verifyFirebaseToken($token) {
    if (empty($token)) return null;

    // Call Firebase token verify endpoint
    $url = 'https://oauth2.googleapis.com/tokeninfo?id_token=' . urlencode($token);
    $resp = @file_get_contents($url);
    if (!$resp) return null;

    $data = json_decode($resp, true);

    // Check token is valid and for our Firebase project
    if (empty($data['sub']) || empty($data['aud'])) return null;
    if ($data['aud'] !== 'hamaraservice-s009') return null;  // your Firebase project ID

    return [
        'uid'   => $data['sub'],
        'email' => $data['email'] ?? '',
        'name'  => $data['name']  ?? '',
    ];
}

// ── Provider JWT ──────────────────────────────────────────
define('JWT_SECRET', 'hamaraHS_jwt_secret_2024_change_this');

function generateJWT($providerId) {
    $header  = base64_encode(json_encode(['alg'=>'HS256','typ'=>'JWT']));
    $payload = base64_encode(json_encode([
        'id'  => $providerId,
        'iat' => time(),
        'exp' => time() + (30 * 24 * 3600), // 30 days
    ]));
    $sig = base64_encode(hash_hmac('sha256', "$header.$payload", JWT_SECRET, true));
    return "$header.$payload.$sig";
}

function verifyJWT($token) {
    if (empty($token)) return null;
    $parts = explode('.', $token);
    if (count($parts) !== 3) return null;
    [$header, $payload, $sig] = $parts;
    $expectedSig = base64_encode(hash_hmac('sha256', "$header.$payload", JWT_SECRET, true));
    if (!hash_equals($expectedSig, $sig)) return null;
    $data = json_decode(base64_decode($payload), true);
    if ($data['exp'] < time()) return null; // expired
    return $data;
}

// ── Auth middleware ───────────────────────────────────────
function requireCustomer() {
    $auth = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    $token = str_replace('Bearer ', '', $auth);
    $user = verifyFirebaseToken($token);
    if (!$user) err('Unauthorized', 401);
    return $user;
}

function requireProvider() {
    $auth = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    $token = str_replace('Bearer ', '', $auth);
    $data = verifyJWT($token);
    if (!$data) err('Unauthorized', 401);
    return $data;
}
