<?php
// ── HamaraService shared bootstrap ────────────────────────
// Secrets are NOT stored here. See api/config.example.php.
// Load order: ../../hs-config.php (outside web root) → api/config.php → getenv().
(function () {
    $candidates = [
        dirname(__DIR__, 2) . '/hs-config.php',
        __DIR__ . '/config.php',
    ];
    foreach ($candidates as $f) {
        if (is_file($f) && is_readable($f)) { require_once $f; break; }
    }
})();

function hsDefineFromEnv($name, $default = '') {
    if (defined($name)) return;
    $v = getenv($name);
    define($name, ($v === false || $v === '') ? $default : $v);
}
foreach ([
    'DB_HOST'                 => 'localhost',
    'DB_NAME'                 => '',
    'DB_USER'                 => '',
    'DB_PASS'                 => '',
    'DB_CHARSET'              => 'utf8mb4',
    'FIREBASE_PROJECT_ID'     => 'hamaraservice-s009',
    'JWT_SECRET'              => '',
    'ADMIN_KEY'               => '',
    'SMTP_HOST'               => 'smtp.hostinger.com',
    'SMTP_PORT'               => 587,
    'SMTP_USER'               => '',
    'SMTP_PASS'               => '',
    'RAZORPAY_KEY_ID'         => '',
    'RAZORPAY_KEY_SECRET'     => '',
    'RAZORPAY_WEBHOOK_SECRET' => '',
    'SERVICE_ACCOUNT_PATH'    => '',
] as $__k => $__d) { hsDefineFromEnv($__k, $__d); }
unset($__k, $__d);

// Never leak exception text (SQL, paths) to clients — log it instead.
set_exception_handler(function ($e) {
    error_log('HamaraService uncaught: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    hsFail('Server error');
});

// Plain JSON 500 without depending on the ok()/err() envelope
function hsFail($message, $code = 500) {
    if (!headers_sent()) {
        http_response_code($code);
        header('Content-Type: application/json; charset=utf-8');
    }
    echo json_encode(['success'=>false, 'error'=>$message]);
    exit;
}

function getDB() {
    static $pdo = null;
    if ($pdo !== null) return $pdo;
    if (DB_NAME === '' || DB_USER === '') {
        error_log('HamaraService: DB credentials missing — create hs-config.php (see api/config.example.php)');
        hsFail('Server not configured');
    }
    $dsn = "mysql:host=".DB_HOST.";dbname=".DB_NAME.";charset=".DB_CHARSET;
    $options = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ];
    try {
        $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
    } catch (PDOException $e) {
        error_log('HamaraService DB connect failed: ' . $e->getMessage());
        hsFail('Database connection failed');
    }
    return $pdo;
}

// Run a list of idempotent schema statements (ALTER ... ADD COLUMN etc.) at most
// once per server instead of on every request. A marker file in the system temp
// dir remembers it; if the marker is lost the statements simply run again
// (each failure — e.g. "duplicate column" — is ignored as before).
// Entries may be SQL strings or callables taking the PDO handle.
function runMigrationsOnce($db, $key, array $sqls) {
    $flag = rtrim(sys_get_temp_dir(), '/\\') . '/hs_mig_' . md5(DB_NAME . '|' . $key) . '.done';
    if (is_file($flag)) return;
    $complete = true;
    foreach ($sqls as $sql) {
        try { is_callable($sql) ? $sql($db) : $db->exec($sql); }
        catch (Throwable $e) {
            // 1060 duplicate column, 1061 duplicate key name, 1062 duplicate
            // entry (unique index impossible on legacy data) = nothing to do.
            // Anything else (e.g. table missing on a fresh DB) → retry next time.
            $code = ($e instanceof PDOException) ? (int)($e->errorInfo[1] ?? 0) : 0;
            if (!in_array($code, [1060, 1061, 1062], true)) $complete = false;
        }
    }
    if ($complete) @file_put_contents($flag, date('c'));
}

// ── CORS ──────────────────────────────────────────────────
function setCorsHeaders() {
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
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
    static $body = null;
    if ($body === null) {
        $body = json_decode(file_get_contents('php://input'), true);
        if (!is_array($body)) $body = [];
    }
    return $body;
}

// ── base64url ─────────────────────────────────────────────
function b64urlEncode($data) {
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}
// Accepts base64url AND legacy standard base64 (with or without padding)
function b64urlDecode($s) {
    $s = strtr((string)$s, '-_', '+/');
    $pad = strlen($s) % 4;
    if ($pad) $s .= str_repeat('=', 4 - $pad);
    return base64_decode($s, true);
}

// ── HTTP GET helper (curl, falling back to streams) ──────
// Returns [status, body, headers(lowercase name => value)] or null.
function hsHttpGet($url, $timeout = 5, $userpwd = null) {
    if (function_exists('curl_init')) {
        $headers = [];
        $ch = curl_init($url);
        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_CONNECTTIMEOUT => $timeout,
            CURLOPT_HEADERFUNCTION => function ($c, $line) use (&$headers) {
                $p = strpos($line, ':');
                if ($p !== false) $headers[strtolower(trim(substr($line, 0, $p)))] = trim(substr($line, $p + 1));
                return strlen($line);
            },
        ];
        if ($userpwd !== null) $opts[CURLOPT_USERPWD] = $userpwd;
        curl_setopt_array($ch, $opts);
        $body = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($body === false) return null;
        return [$code, $body, $headers];
    }
    $hdr = $userpwd !== null ? "Authorization: Basic " . base64_encode($userpwd) . "\r\n" : '';
    $ctx = stream_context_create(['http' => ['timeout' => $timeout, 'header' => $hdr, 'ignore_errors' => true]]);
    $body = @file_get_contents($url, false, $ctx);
    if ($body === false) return null;
    $code = 0; $headers = [];
    foreach (($http_response_header ?? []) as $line) {
        if (preg_match('#^HTTP/\S+\s+(\d+)#', $line, $m)) { $code = (int)$m[1]; continue; }
        $p = strpos($line, ':');
        if ($p !== false) $headers[strtolower(trim(substr($line, 0, $p)))] = trim(substr($line, $p + 1));
    }
    return [$code, $body, $headers];
}

// ── Firebase ID token verification (customer app) ────────
define('FIREBASE_CERTS_URL', 'https://www.googleapis.com/robot/v1/metadata/x509/securetoken@system.gserviceaccount.com');

// Returns [kid => PEM cert] or null. Cached in the temp dir for Cache-Control max-age.
function getFirebaseCerts($forceRefresh = false) {
    $cacheFile = rtrim(sys_get_temp_dir(), '/\\') . '/hs_fb_certs_' . md5(FIREBASE_CERTS_URL) . '.json';
    $cached = null;
    if (is_file($cacheFile)) {
        $cached = json_decode((string)@file_get_contents($cacheFile), true);
        if (!$forceRefresh && is_array($cached) && !empty($cached['certs'])
            && ($cached['expires'] ?? 0) > time()) {
            return $cached['certs'];
        }
    }
    $resp = hsHttpGet(FIREBASE_CERTS_URL, 5);
    if ($resp && $resp[0] === 200) {
        $certs = json_decode($resp[1], true);
        if (is_array($certs) && !empty($certs)) {
            $maxAge = 3600;
            if (preg_match('/max-age=(\d+)/i', $resp[2]['cache-control'] ?? '', $m)) $maxAge = (int)$m[1];
            $tmp = $cacheFile . '.' . getmypid() . '.tmp';
            if (@file_put_contents($tmp, json_encode(['expires' => time() + $maxAge, 'certs' => $certs])) !== false) {
                @rename($tmp, $cacheFile);
            }
            return $certs;
        }
    }
    // Network failure: a stale copy is still better than nothing (Google keeps
    // old keys valid for a while after rotation).
    if (is_array($cached) && !empty($cached['certs'])) return $cached['certs'];
    return null;
}

// Verifies a Firebase ID token locally (RS256 + claims).
// Returns ['uid','email','name'] or null.
function verifyFirebaseToken($token) {
    if (empty($token) || substr_count($token, '.') !== 2) return null;
    [$h64, $p64, $s64] = explode('.', $token);
    $header  = json_decode((string)b64urlDecode($h64), true);
    $payload = json_decode((string)b64urlDecode($p64), true);
    $sig     = b64urlDecode($s64);
    if (!is_array($header) || !is_array($payload) || $sig === false) return null;
    if (($header['alg'] ?? '') !== 'RS256' || empty($header['kid'])) return null;

    $certs = getFirebaseCerts();
    if ($certs === null) {
        // Could not obtain Google's certs at all — fall back to remote check
        return verifyFirebaseTokenRemote($token);
    }
    if (!isset($certs[$header['kid']])) {
        $certs = getFirebaseCerts(true);            // keys may have rotated
        if (!is_array($certs) || !isset($certs[$header['kid']])) return null;
    }
    $pub = openssl_pkey_get_public($certs[$header['kid']]);
    if ($pub === false) return null;
    if (openssl_verify("$h64.$p64", $sig, $pub, OPENSSL_ALGO_SHA256) !== 1) return null;

    $now  = time();
    $skew = 300;
    if (($payload['aud'] ?? '') !== FIREBASE_PROJECT_ID) return null;
    if (($payload['iss'] ?? '') !== 'https://securetoken.google.com/' . FIREBASE_PROJECT_ID) return null;
    if (!isset($payload['exp']) || (int)$payload['exp'] < $now) return null;
    if (!isset($payload['iat']) || (int)$payload['iat'] > $now + $skew) return null;
    if (isset($payload['auth_time']) && (int)$payload['auth_time'] > $now + $skew) return null;
    $sub = $payload['sub'] ?? '';
    if (!is_string($sub) || $sub === '' || strlen($sub) > 128) return null;

    return [
        'uid'   => $sub,
        'email' => $payload['email'] ?? '',
        'name'  => $payload['name']  ?? '',
    ];
}

// Fallback only: ask Google's tokeninfo endpoint.
function verifyFirebaseTokenRemote($token) {
    $resp = hsHttpGet('https://oauth2.googleapis.com/tokeninfo?id_token=' . urlencode($token), 5);
    if (!$resp || $resp[0] !== 200) return null;
    $data = json_decode($resp[1], true);
    if (empty($data['sub'])) return null;
    if (($data['aud'] ?? '') !== FIREBASE_PROJECT_ID) return null;
    if (($data['iss'] ?? '') !== 'https://securetoken.google.com/' . FIREBASE_PROJECT_ID) return null;
    if ((int)($data['exp'] ?? 0) < time()) return null;
    return [
        'uid'   => $data['sub'],
        'email' => $data['email'] ?? '',
        'name'  => $data['name']  ?? '',
    ];
}

// ── Home-made HS256 JWT (providers + web customers) ───────
function jwtSecret() {
    if (strlen((string)JWT_SECRET) < 16) {
        error_log('HamaraService: JWT_SECRET missing/too short — set it in hs-config.php');
        hsFail('Server not configured');
    }
    return JWT_SECRET;
}
function makeJWT($payload, $days = 30) {
    $payload['iat'] = $payload['iat'] ?? time();
    $payload['exp'] = time() + ($days * 86400);
    $h = b64urlEncode(json_encode(['alg'=>'HS256','typ'=>'JWT']));
    $p = b64urlEncode(json_encode($payload));
    $s = b64urlEncode(hash_hmac('sha256', "$h.$p", jwtSecret(), true));
    return "$h.$p.$s";
}
function generateJWT($providerId) {
    return makeJWT(['type' => 'provider', 'id' => $providerId], 30);
}
function verifyJWT($token) {
    if (empty($token)) return null;
    $parts = explode('.', $token);
    if (count($parts) !== 3) return null;
    [$h,$p,$s] = $parts;
    $raw = hash_hmac('sha256', "$h.$p", jwtSecret(), true);
    // base64url (current) or legacy standard-base64 signatures
    $okSig = hash_equals(b64urlEncode($raw), $s)
          || hash_equals(rtrim(base64_encode($raw), '='), $s);
    if (!$okSig) return null;
    $hdr = json_decode((string)b64urlDecode($h), true);
    if (!is_array($hdr) || ($hdr['alg'] ?? '') !== 'HS256') return null;
    $data = json_decode((string)b64urlDecode($p), true);
    if (!is_array($data)) return null;
    if ((int)($data['exp'] ?? 0) < time()) return null;
    return $data;
}

// ── Auth middleware ───────────────────────────────────────
function getBearerToken() {
    $auth = $_SERVER['HTTP_AUTHORIZATION']
         ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION']
         ?? '';
    if ($auth === '' && function_exists('getallheaders')) {
        foreach (getallheaders() as $k => $v) {
            if (strcasecmp($k, 'Authorization') === 0) { $auth = $v; break; }
        }
    }
    if (stripos($auth, 'Bearer ') === 0) $auth = substr($auth, 7);
    return trim($auth);
}

// Look up the provider row status; null if provider unknown.
function providerStatus($id) {
    $stmt = getDB()->prepare("SELECT status FROM providers WHERE id = ?");
    $stmt->execute([$id]);
    $s = $stmt->fetchColumn();
    return $s === false ? null : (string)$s;
}

// Returns provider claims from a provider JWT or null (no DB check).
function providerClaims($token) {
    $data = verifyJWT($token);
    if (!$data) return null;
    $type = $data['type'] ?? 'provider';          // legacy provider tokens carry no type
    if ($type !== 'provider') return null;
    if (empty($data['id']) || !is_string($data['id'])) return null;
    return $data;
}

function requireCustomer() {
    $token = getBearerToken();
    // Web app JWT first (fast, local), then Firebase token (mobile app)
    $jwt = verifyJWT($token);
    if ($jwt && ($jwt['type'] ?? '') === 'customer' && !empty($jwt['uid'])) {
        return ['uid' => $jwt['uid'], 'email' => $jwt['email'] ?? '', 'name' => $jwt['name'] ?? ''];
    }
    if ($jwt) err('Unauthorized', 401);          // a provider token is not a customer
    $user = verifyFirebaseToken($token);
    if (!$user) err('Unauthorized', 401);
    return $user;
}
function requireProvider() {
    $data = providerClaims(getBearerToken());
    if (!$data) err('Unauthorized', 401);
    $status = providerStatus($data['id']);
    if ($status === null) err('Unauthorized', 401);
    if (in_array($status, ['suspended','rejected','deleted'], true)) err('Account ' . $status . '. Contact support.', 403);
    $data['status'] = $status;
    return $data;
}

// Admin key from X-Admin-Key header, POST field / JSON body "key",
// or (only when $allowQuery) the ?key= query parameter.
function adminKeyFromRequest($allowQuery = true) {
    $key = $_SERVER['HTTP_X_ADMIN_KEY'] ?? '';
    if ($key === '' && isset($_POST['key'])) $key = (string)$_POST['key'];
    if ($key === '') { $b = getBody(); if (isset($b['key']) && is_string($b['key'])) $key = $b['key']; }
    if ($key === '' && $allowQuery && isset($_GET['key'])) $key = (string)$_GET['key'];
    return $key;
}
function isAdminRequest($allowQuery = true) {
    $key = adminKeyFromRequest($allowQuery);
    return ADMIN_KEY !== '' && $key !== '' && hash_equals((string)ADMIN_KEY, $key);
}
function requireAdmin($allowQuery = true) {
    if (!isAdminRequest($allowQuery)) err('Unauthorized', 401);
}

// Non-fatal: who is calling? Returns one of
//   ['role'=>'admin'] | ['role'=>'provider','id'=>..,'status'=>..] |
//   ['role'=>'customer','uid'=>..,'email'=>..,'name'=>..] | null
function resolveAuth() {
    if (isAdminRequest()) return ['role' => 'admin'];
    $token = getBearerToken();
    if ($token === '') return null;
    $jwt = verifyJWT($token);
    if ($jwt) {
        if (($jwt['type'] ?? '') === 'customer' && !empty($jwt['uid'])) {
            return ['role'=>'customer', 'uid'=>$jwt['uid'], 'email'=>$jwt['email'] ?? '', 'name'=>$jwt['name'] ?? ''];
        }
        $p = providerClaims($token);
        if (!$p) return null;
        $status = providerStatus($p['id']);
        if ($status === null || in_array($status, ['suspended','rejected','deleted'], true)) return null;
        return ['role'=>'provider', 'id'=>$p['id'], 'status'=>$status];
    }
    $u = verifyFirebaseToken($token);
    if ($u) return array_merge(['role' => 'customer'], $u);
    return null;
}
function requireAuth() {
    $a = resolveAuth();
    if (!$a) err('Unauthorized', 401);
    return $a;
}

// ── Razorpay REST (server-side lookups) ──────────────────
// GET https://api.razorpay.com/v1/<path> with the configured key pair.
// Returns the decoded entity, or null on any failure.
function razorpayGet($path) {
    if (RAZORPAY_KEY_ID === '' || RAZORPAY_KEY_SECRET === '') return null;
    $resp = hsHttpGet('https://api.razorpay.com/v1/' . ltrim($path, '/'), 15,
                      RAZORPAY_KEY_ID . ':' . RAZORPAY_KEY_SECRET);
    if (!$resp || $resp[0] !== 200) return null;
    $data = json_decode($resp[1], true);
    return is_array($data) ? $data : null;
}
function razorpaySignatureValid($orderId, $paymentId, $signature) {
    if (RAZORPAY_KEY_SECRET === '' || $orderId === '' || $paymentId === '' || !is_string($signature)) return false;
    $expected = hash_hmac('sha256', $orderId . '|' . $paymentId, RAZORPAY_KEY_SECRET);
    return hash_equals($expected, $signature);
}

// Amount (whole rupees) the customer owes for a booking.
function bookingPayable($bk) {
    return (int)((int)($bk['confirmed_price'] ?? 0) ?: (int)($bk['amount'] ?? 0));
}

// Provider rating = one vote per booking, from reviews table (Flutter app)
// plus bookings.rating (web app) for bookings without a reviews row.
function recomputeProviderRating($db, $providerId) {
    $q = $db->prepare("
      SELECT AVG(t.r) AS a, COUNT(*) AS c FROM (
        SELECT booking_id, rating AS r FROM reviews WHERE provider_id = ?
        UNION ALL
        SELECT b.id, b.rating FROM bookings b
         WHERE b.provider_id = ? AND b.rating IS NOT NULL
           AND NOT EXISTS (SELECT 1 FROM reviews rv WHERE rv.booking_id = b.id)
      ) t");
    $q->execute([$providerId, $providerId]);
    $r = $q->fetch();
    $avg = round((float)($r['a'] ?? 0), 1);
    $db->prepare("UPDATE providers SET rating = ?, review_count = ? WHERE id = ?")
       ->execute([$avg, (int)($r['c'] ?? 0), $providerId]);
    return $avg;
}

// Strip CR/LF and validate an e-mail address for use in mail headers.
function cleanEmail($email) {
    $email = trim(str_replace(["\r", "\n", "\0"], '', (string)$email));
    return filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : '';
}
