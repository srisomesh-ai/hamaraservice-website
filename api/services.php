<?php
// ═══════════════════════════════════════════════════════
// HamaraService — Services Catalog API
// Endpoints:
//   GET  ?action=all           — all 34 services
//   GET  ?action=categories    — list of categories
//   GET  ?action=get&id=SVC001 — single service detail
//   GET  ?action=prices&id=X   — reference prices for a service
//   GET  ?action=price_ranges  — min/max from all providers per service
//   POST ?action=save_prices   — admin saves reference prices
// ═══════════════════════════════════════════════════════
require_once __DIR__ . '/db.php';
setCorsHeaders();

// Global error handler — always return JSON even on fatal errors
set_exception_handler(function($e) {
  http_response_code(500);
  echo json_encode(['success' => false, 'error' => 'Server: ' . $e->getMessage()]);
  exit;
});
register_shutdown_function(function() {
  $err = error_get_last();
  if ($err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Fatal: ' . $err['message']]);
  }
});

$action = $_GET['action'] ?? '';

// Version check — no DB needed
if ($action === 'version') {
  echo json_encode(['success' => true, 'version' => 'v2026-08-20-1', 'file' => 'services.php']);
  exit;
}

$db     = getDB();

function buildPriceRanges($db, $city = '') {
  /* 1. Provider-set ranges (what approved providers actually charge) */
  $sql = "
    SELECT ps.svc_id,
           MIN(ps.min_price) as lowest_min,
           MAX(ps.max_price) as highest_max,
           COUNT(DISTINCT ps.provider_id) as provider_count
    FROM provider_services ps
    INNER JOIN providers p ON p.id = ps.provider_id
    WHERE p.status = 'approved'
      AND ps.enabled = 1
      AND ps.min_price > 0
  ";
  $params = [];
  if (!empty($city)) { $sql .= " AND p.city LIKE ?"; $params[] = "%$city%"; }
  $sql .= " GROUP BY ps.svc_id";
  $stmt = $db->prepare($sql);
  $stmt->execute($params);
  $result = [];
  foreach ($stmt->fetchAll() as $row) {
    $result[$row['svc_id']] = [
      'min' => (int)$row['lowest_min'],
      'max' => (int)$row['highest_max'],
      'provider_count' => (int)$row['provider_count'],
    ];
  }
  /* 2. Admin reference + min/max per option */
  try { $db->exec("ALTER TABLE services ADD COLUMN price_data JSON NULL"); } catch (Throwable $e) {}
  foreach ($db->query("SELECT id, price_data FROM services WHERE price_data IS NOT NULL")->fetchAll() as $row) {
    $pd = json_decode($row['price_data'], true);
    if (!$pd) continue;
    if (!isset($result[$row['id']])) $result[$row['id']] = ['min' => 0, 'max' => 0, 'provider_count' => 0];
    $result[$row['id']]['admin_prices'] = $pd;
  }
  return $result;
}

switch ($action) {

  // ── ALL SERVICES ──────────────────────────────────────
  case 'all': {
    $stmt = $db->query("
      SELECT id, name, icon, category, base_price, description, is_active, sort_order
      FROM services
      WHERE is_active = 1
      ORDER BY sort_order ASC
    ");
    ok($stmt->fetchAll());
  }

  // ── CATEGORIES ────────────────────────────────────────
  case 'categories': {
    $stmt = $db->query("
      SELECT category
      FROM services
      WHERE is_active = 1
      GROUP BY category
      ORDER BY MIN(sort_order) ASC
    ");
    $cats = array_column($stmt->fetchAll(), 'category');
    ok($cats);
  }

  // ── SINGLE SERVICE ────────────────────────────────────
  case 'get': {
    $id = $_GET['id'] ?? '';
    if (empty($id)) err('id required');

    $stmt = $db->prepare("SELECT * FROM services WHERE id = ?");
    $stmt->execute([$id]);
    $svc = $stmt->fetch();
    if (!$svc) err('Service not found', 404);

    // Get reference prices
    $pstmt = $db->prepare("
      SELECT group_key, option_key, option_name, price
      FROM service_prices
      WHERE svc_id = ?
      ORDER BY group_key, price ASC
    ");
    $pstmt->execute([$id]);
    $svc['prices'] = $pstmt->fetchAll();

    ok($svc);
  }

  // ── REFERENCE PRICES FOR ONE SERVICE ─────────────────
  case 'prices': {
    $id = $_GET['id'] ?? '';
    /* Provider app calls action=prices with empty id expecting the full
       ranges payload (admin ref + min/max per option) — serve it. */
    if (empty($id)) { ok(buildPriceRanges($db, $_GET['city'] ?? '')); }

    $stmt = $db->prepare("
      SELECT group_key, option_key, option_name, price
      FROM service_prices
      WHERE svc_id = ?
      ORDER BY group_key, price ASC
    ");
    $stmt->execute([$id]);
    $rows = $stmt->fetchAll();

    // Build grouped structure: {groupKey: {optKey: price}}
    $grouped = [];
    foreach ($rows as $row) {
      $grouped[$row['group_key']][$row['option_key']] = (int)$row['price'];
    }
    ok($grouped);
  }

  // ── PROVIDER PRICE RANGES (for homepage) ─────────────
  // Returns min/max across all approved providers per service
  case 'price_ranges': {
    ok(buildPriceRanges($db, $_GET['city'] ?? ''));
  }

  // ── ADMIN: SAVE REFERENCE PRICES ─────────────────────
  case 'save_prices': {
    requireAdmin();
    $b = getBody();
    $svc_id = $b['svc_id'] ?? '';
    $data   = $b['data']   ?? ($b['prices'] ?? []);
    if (empty($svc_id)) err('svc_id required');

    // Ensure services table exists (with price_data column)
    try {
      $db->exec("CREATE TABLE IF NOT EXISTS services (
        id         VARCHAR(20) PRIMARY KEY,
        name       VARCHAR(120) NOT NULL DEFAULT '',
        icon       VARCHAR(10)  NOT NULL DEFAULT '',
        cat        VARCHAR(60)  NOT NULL DEFAULT '',
        min_price  INT NOT NULL DEFAULT 0,
        max_price  INT NOT NULL DEFAULT 0,
        price_data JSON NULL
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (Throwable $e) {}
    try { $db->exec("ALTER TABLE services ADD COLUMN price_data JSON NULL"); } catch(Throwable $e) {}
    try { $db->exec("ALTER TABLE services ADD COLUMN min_price INT NOT NULL DEFAULT 0"); } catch(Throwable $e) {}
    try { $db->exec("ALTER TABLE services ADD COLUMN max_price INT NOT NULL DEFAULT 0"); } catch(Throwable $e) {}

    // Calculate min/max from all numeric prices in data
    $all_prices = [];
    if (is_array($data)) {
      foreach ($data as $grp => $opts) {
        if ($grp === 'name') continue;
        if ($grp === 'base' && is_numeric($opts)) {
          $all_prices[] = (int)$opts;
        } elseif (is_array($opts)) {
          foreach ($opts as $k => $v) {
            // Skip _min and _max keys from min/max calc — they're bounds not prices
            if (is_numeric($v) && $v > 0 && strpos($k, '_min') === false && strpos($k, '_max') === false) {
              $all_prices[] = (int)$v;
            }
          }
        }
      }
    }
    $min_price = !empty($all_prices) ? min($all_prices) : 0;
    $max_price = !empty($all_prices) ? max($all_prices) : 0;

    $stmt = $db->prepare("UPDATE services SET price_data = ?, min_price = ?, max_price = ? WHERE id = ?");
    $stmt->execute([json_encode($data), $min_price, $max_price, $svc_id]);

    if ($stmt->rowCount() === 0) {
      // Service row may not exist — insert
      try {
        $db->prepare("INSERT INTO services (id, name, price_data, min_price, max_price)
                      VALUES (?, ?, ?, ?, ?)")
           ->execute([$svc_id, $data['name'] ?? $svc_id, json_encode($data), $min_price, $max_price]);
      } catch (Throwable $e) { /* row exists with same data */ }
    }

    ok(['saved' => true, 'svc_id' => $svc_id, 'min' => $min_price, 'max' => $max_price]);
  }

  // ── ADMIN: UPDATE SERVICE ─────────────────────────────
  case 'update': {
    requireAdmin();
    $b  = getBody();
    $id = $b['id'] ?? '';
    if (empty($id)) err('id required');

    $fields = []; $params = [':id' => $id];
    $allowed = ['name','icon','category','base_price','description','is_active'];
    foreach ($allowed as $f) {
      if (isset($b[$f])) { $fields[] = "$f = :$f"; $params[":$f"] = $b[$f]; }
    }
    if (empty($fields)) err('Nothing to update');

    $db->prepare("UPDATE services SET ".implode(', ',$fields)." WHERE id = :id")
       ->execute($params);
    ok(['updated' => true]);
  }

  // ── GET ALL PRICES (admin price manager) ────────────────────────
  case 'get_all_prices': {
    requireAdmin();
    // Ensure services table exists
    try {
      $db->exec("CREATE TABLE IF NOT EXISTS services (
        id         VARCHAR(20) PRIMARY KEY,
        name       VARCHAR(120) NOT NULL DEFAULT '',
        icon       VARCHAR(10)  NOT NULL DEFAULT '',
        cat        VARCHAR(60)  NOT NULL DEFAULT '',
        min_price  INT NOT NULL DEFAULT 0,
        max_price  INT NOT NULL DEFAULT 0,
        price_data JSON NULL
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (Throwable $e) {}
    try { $db->exec("ALTER TABLE services ADD COLUMN price_data JSON NULL"); } catch(Throwable $e) {}
    try { $db->exec("ALTER TABLE services ADD COLUMN min_price INT NOT NULL DEFAULT 0"); } catch(Throwable $e) {}
    try { $db->exec("ALTER TABLE services ADD COLUMN max_price INT NOT NULL DEFAULT 0"); } catch(Throwable $e) {}

    $rows = $db->query("SELECT id, name, price_data FROM services ORDER BY id")->fetchAll();
    $result = [];
    foreach ($rows as $row) {
      $pd = $row['price_data'] ? json_decode($row['price_data'], true) : null;
      if ($pd) $result[$row['id']] = $pd;
    }
    ok($result);
  }

  // ── RESET SERVICE PRICES ─────────────────────────────────────────
  case 'reset_prices': {
    requireAdmin();
    $b      = getBody();
    $svc_id = $b['svc_id'] ?? '';
    if (empty($svc_id)) err('svc_id required');
    $db->prepare("UPDATE services SET price_data = NULL, min_price = 0, max_price = 0 WHERE id = ?")
       ->execute([$svc_id]);
    ok(['reset' => true, 'svc_id' => $svc_id]);
  }

  default:
    err('Invalid action');
}
