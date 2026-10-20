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

  // ── WIPE TEST DATA (launch prep) ──
  // Body: {confirm:'DELETE TEST DATA', bookings:bool, customers:bool, providers:bool}
  // Never touches services, prices, commission settings or admin login.
  case 'wipe_test_data': {
    requireAdmin();
    $b = getBody();
    if (($b['confirm'] ?? '') !== 'DELETE TEST DATA') err('Type DELETE TEST DATA to confirm');
    $doBk = !empty($b['bookings']); $doCu = !empty($b['customers']); $doPr = !empty($b['providers']);
    if (!$doBk && !$doCu && !$doPr) err('Choose what to delete');
    if (($doCu || $doPr) && !$doBk) err('Bookings must be deleted together with customers or providers');
    require_once __DIR__ . '/schema.php';
    $db = getDB();
    ensureSchema($db);
    $count = function ($t) use ($db) { try { return (int)$db->query("SELECT COUNT(*) FROM $t")->fetchColumn(); } catch (Throwable $e) { return 0; } };
    $done = [];
    $db->exec('SET FOREIGN_KEY_CHECKS = 0');
    try {
      if ($doBk) {
        foreach (['reviews','bookings','payouts','wallet_topups','notifications'] as $t) {
          $done[$t] = $count($t);
          try { $db->exec("DELETE FROM $t"); } catch (Throwable $e) {}
        }
        // provider counters / wallets / ratings back to zero
        $db->exec("UPDATE providers SET total_bookings = 0, completed_bookings = 0, total_earned = 0,
                   pending_earned = 0, rating = 0, review_count = 0");
      }
      if ($doCu) { $done['customers'] = $count('customers'); $db->exec("DELETE FROM customers"); }
      if ($doPr) {
        $done['providers'] = $count('providers');
        $db->exec("DELETE FROM provider_services");
        $db->exec("DELETE FROM providers");
      }
    } finally {
      $db->exec('SET FOREIGN_KEY_CHECKS = 1');
    }
    error_log('[admin] wipe_test_data ' . json_encode($done) . ' from ' . ($_SERVER['REMOTE_ADDR'] ?? '?'));
    ok(['deleted' => $done]);
  }

  case 'data_counts': {
    requireAdmin();
    $db = getDB();
    $out = [];
    foreach (['bookings','reviews','payouts','customers','providers'] as $t) {
      try { $out[$t] = (int)$db->query("SELECT COUNT(*) FROM $t")->fetchColumn(); } catch (Throwable $e) { $out[$t] = 0; }
    }
    ok($out);
  }

  case 'me': {
    requireAdmin();
    ok(['admin' => true]);
  }

  default:
    err('Invalid action');
}
