<?php
// ═══════════════════════════════════════════════════════
// HamaraService — Admin auth
//   POST ?action=login  {username, password} → {token}   (12h admin JWT)
//   GET  ?action=me     → {admin:true} if token valid
// Credentials: ADMIN_USER / ADMIN_PASSWORD_HASH in ~/hs-secrets.php
// ═══════════════════════════════════════════════════════
require_once __DIR__ . '/db.php';
setCorsHeaders();

$action = $_GET['action'] ?? '';

switch ($action) {
  case 'login': {
    $b = getBody();
    $user = trim($b['username'] ?? '');
    $pass = $b['password'] ?? '';
    if (ADMIN_PASSWORD_HASH === '') err('Admin login not configured', 500);

    // Simple brute-force throttle per IP (file based)
    $ip   = $_SERVER['REMOTE_ADDR'] ?? 'x';
    $f    = sys_get_temp_dir() . '/hs_admin_' . md5($ip);
    $log  = is_readable($f) ? (json_decode(file_get_contents($f), true) ?: []) : [];
    $log  = array_values(array_filter($log, fn($t) => $t > time() - 900));
    if (count($log) >= 10) err('Too many attempts. Try again in 15 minutes.', 429);

    if (!hash_equals(ADMIN_USER, $user) || !password_verify($pass, ADMIN_PASSWORD_HASH)) {
      $log[] = time();
      @file_put_contents($f, json_encode($log));
      err('Invalid username or password', 401);
    }
    @unlink($f);
    ok(['token' => makeJWT(['type' => 'admin', 'sub' => ADMIN_USER], 0.5)]);
  }

  case 'me': {
    requireAdmin();
    ok(['admin' => true]);
  }

  default:
    err('Invalid action');
}
