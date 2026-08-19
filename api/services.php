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

$action = $_GET['action'] ?? '';
$db     = getDB();

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
    if (empty($id)) err('id required');

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
    $city = $_GET['city'] ?? '';

    // 1. Get provider-set price ranges (what providers actually charge)
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
    if (!empty($city)) {
      $sql .= " AND p.city LIKE ?";
      $params[] = "%$city%";
    }
    $sql .= " GROUP BY ps.svc_id";
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    $result = [];
    foreach ($rows as $row) {
      $result[$row['svc_id']] = [
        'min'            => (int)$row['lowest_min'],
        'max'            => (int)$row['highest_max'],
        'provider_count' => (int)$row['provider_count'],
      ];
    }

    // 2. Get admin price_data (ref + min/max per option) from services table
    try {
      $db->exec("ALTER TABLE services ADD COLUMN price_data JSON NULL");
    } catch (Exception \$e) {}

    $adminRows = $db->query("SELECT id, price_data FROM services WHERE price_data IS NOT NULL")
                    ->fetchAll();

    $adminPrices = [];
    foreach ($adminRows as $row) {
      $pd = json_decode($row['price_data'], true);
      if ($pd) $adminPrices[$row['id']] = $pd;
    }

    // Merge admin prices into result
    foreach ($adminPrices as $svcId => $pd) {
      if (!isset($result[$svcId])) {
        $result[$svcId] = ['min' => 0, 'max' => 0, 'provider_count' => 0];
      }
      // Add full admin price_data so provider app can show ref+min+max per option
      $result[$svcId]['admin_prices'] = $pd;
    }

    ok($result);
  }

  // ── ADMIN: SAVE REFERENCE PRICES ─────────────────────
  case 'save_prices': {
    requireAdmin();
    $b = getBody();
    $svc_id = $b['svc_id'] ?? '';
    $data   = $b['data']   ?? ($b['prices'] ?? []);
    if (empty($svc_id)) err('svc_id required');

    // Ensure price_data column exists
    try { $db->exec("ALTER TABLE services ADD COLUMN price_data JSON NULL"); } catch(Exception $e) {}

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
      } catch (Exception $e) { /* row exists with same data */ }
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
    // Check if service_prices column exists in services table, add if not
    try { $db->exec("ALTER TABLE services ADD COLUMN price_data JSON NULL"); } catch(Exception $e) {}

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
