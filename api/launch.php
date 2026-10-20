<?php
// ═══════════════════════════════════════════════════════
// HamaraService — official launch switch
//   GET  ?action=status  → { launched, launched_at }
//   POST ?action=launch  { code, dry_run } → director's secret code;
//        records the launch (dry_run=1 only checks the code, for rehearsal)
//   POST ?action=reset   (admin) → back to "not launched"
// The code is LAUNCH_CODE in hs-secrets.php (a built-in default otherwise).
// ═══════════════════════════════════════════════════════
require_once __DIR__ . '/db.php';
setCorsHeaders();
header('Cache-Control: no-store');

const LAUNCH_DEFAULT_SHA256 = '7d95b97a54ecb3b67633dc751b4218be2529db40bce8680f00eef085476cbc4e';

$db = getDB();
$db->exec("CREATE TABLE IF NOT EXISTS site_settings (
  k VARCHAR(50) PRIMARY KEY, v TEXT NULL,
  updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

function launchedAt(PDO $db) {
  $s = $db->prepare("SELECT v FROM site_settings WHERE k = 'launched_at'");
  $s->execute();
  return $s->fetchColumn() ?: null;
}

function launchCodeOk($code) {
  $code = strtoupper(trim((string)$code));
  if ($code === '') return false;
  if (LAUNCH_CODE !== '') return hash_equals(strtoupper(LAUNCH_CODE), $code);
  return hash_equals(LAUNCH_DEFAULT_SHA256, hash('sha256', $code));
}

switch ($_GET['action'] ?? 'status') {
  case 'status': {
    $at = launchedAt($db);
    ok(['launched' => (bool)$at, 'launched_at' => $at]);
  }
  case 'launch': {
    $b = getBody();
    if (!launchCodeOk($b['code'] ?? '')) { sleep(1); err('Wrong code', 403); }
    if (empty($b['dry_run']) && !launchedAt($db)) {
      $db->prepare("INSERT INTO site_settings (k, v) VALUES ('launched_at', ?)
                     ON DUPLICATE KEY UPDATE v = VALUES(v)")->execute([date('Y-m-d H:i:s')]);
    }
    ok(['launched' => empty($b['dry_run']), 'dry_run' => !empty($b['dry_run'])]);
  }
  case 'reset': {
    requireAdmin();
    $db->exec("DELETE FROM site_settings WHERE k = 'launched_at'");
    ok(['launched' => false]);
  }
  default: err('Unknown action', 404);
}
