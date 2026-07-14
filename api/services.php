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
      SELECT DISTINCT category
      FROM services
      WHERE is_active = 1
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

    // Key by svc_id
    $result = [];
    foreach ($rows as $row) {
      $result[$row['svc_id']] = [
        'min'            => (int)$row['lowest_min'],
        'max'            => (int)$row['highest_max'],
        'provider_count' => (int)$row['provider_count'],
      ];
    }
    ok($result);
  }

  // ── ADMIN: SAVE REFERENCE PRICES ─────────────────────
  case 'save_prices': {
    requireAdmin();
    $b = getBody();

    // Expected: { svc_id: 'SVC001', prices: [{group_key, option_key, option_name, price}] }
    $svc_id = $b['svc_id'] ?? '';
    $prices = $b['prices'] ?? [];
    if (empty($svc_id)) err('svc_id required');

    // Delete existing prices for this service
    $db->prepare("DELETE FROM service_prices WHERE svc_id = ?")
       ->execute([$svc_id]);

    // Insert new
    $stmt = $db->prepare("
      INSERT INTO service_prices (svc_id, group_key, option_key, option_name, price)
      VALUES (?, ?, ?, ?, ?)
    ");
    foreach ($prices as $p) {
      $stmt->execute([
        $svc_id,
        $p['group_key']    ?? '',
        $p['option_key']   ?? '',
        $p['option_name']  ?? '',
        (int)($p['price']  ?? 0),
      ]);
    }

    ok(['saved' => count($prices)]);
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

  default:
    err('Invalid action');
}
