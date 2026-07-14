<?php
// ═══════════════════════════════════════════════════════
// HamaraService — Customer API
// Endpoints:
//   POST ?action=register  — create/update customer after Firebase signup
//   POST ?action=update    — update profile fields
//   POST ?action=fcm       — save FCM token
//   GET  ?action=get&id=X  — get customer profile
//   GET  ?action=bookings&id=X — get customer booking history
// ═══════════════════════════════════════════════════════
require_once __DIR__ . '/db.php';
setCorsHeaders();

$action = $_GET['action'] ?? '';
$db     = getDB();

switch ($action) {

  // ── REGISTER / UPSERT ─────────────────────────────────
  // Called after Firebase Auth signup/login
  // Flutter sends Firebase ID token in Authorization header
  case 'register': {
    $user = requireCustomer();  // verifies Firebase token
    $b    = getBody();

    // Upsert — insert if new, update if exists
    $stmt = $db->prepare("
      INSERT INTO customers
        (id, name, phone, email, gender, address, city, lat, lng, auth_method, fcm_token)
      VALUES
        (:id, :name, :phone, :email, :gender, :address, :city, :lat, :lng, :auth, :fcm)
      ON DUPLICATE KEY UPDATE
        name        = IF(:name2 != '', :name2, name),
        phone       = IF(:phone2 != '', :phone2, phone),
        gender      = IF(:gender2 != '', :gender2, gender),
        address     = IF(:address2 != '', :address2, address),
        city        = IF(:city2 != '', :city2, city),
        lat         = IF(:lat2 != 0, :lat2, lat),
        lng         = IF(:lng2 != 0, :lng2, lng),
        fcm_token   = IF(:fcm2 != '', :fcm2, fcm_token),
        updated_at  = CURRENT_TIMESTAMP
    ");

    $name    = $b['name']    ?? $user['name']  ?? '';
    $phone   = $b['phone']   ?? '';
    $email   = $b['email']   ?? $user['email'] ?? '';
    $gender  = $b['gender']  ?? '';
    $address = $b['address'] ?? '';
    $city    = $b['city']    ?? '';
    $lat     = (float)($b['lat'] ?? 0);
    $lng     = (float)($b['lng'] ?? 0);
    $auth    = $b['auth_method'] ?? 'email';
    $fcm     = $b['fcm_token']   ?? '';

    $stmt->execute([
      ':id'      => $user['uid'],
      ':name'    => $name,    ':name2'    => $name,
      ':phone'   => $phone,   ':phone2'   => $phone,
      ':email'   => $email,
      ':gender'  => $gender,  ':gender2'  => $gender,
      ':address' => $address, ':address2' => $address,
      ':city'    => $city,    ':city2'    => $city,
      ':lat'     => $lat,     ':lat2'     => $lat,
      ':lng'     => $lng,     ':lng2'     => $lng,
      ':auth'    => $auth,
      ':fcm'     => $fcm,     ':fcm2'     => $fcm,
    ]);

    // Return customer data
    $customer = $db->prepare("SELECT * FROM customers WHERE id = ?");
    $customer->execute([$user['uid']]);
    ok($customer->fetch());
  }

  // ── UPDATE PROFILE ────────────────────────────────────
  case 'update': {
    $user = requireCustomer();
    $b    = getBody();

    $fields = [];
    $params = [':id' => $user['uid']];

    $allowed = ['name','phone','gender','address','city','lat','lng'];
    foreach ($allowed as $f) {
      if (isset($b[$f]) && $b[$f] !== '') {
        $fields[] = "$f = :$f";
        $params[":$f"] = $b[$f];
      }
    }

    if (empty($fields)) err('Nothing to update');

    $db->prepare("UPDATE customers SET " . implode(', ', $fields) . " WHERE id = :id")
       ->execute($params);

    $stmt = $db->prepare("SELECT * FROM customers WHERE id = ?");
    $stmt->execute([$user['uid']]);
    ok($stmt->fetch());
  }

  // ── SAVE FCM TOKEN ────────────────────────────────────
  case 'fcm': {
    $user = requireCustomer();
    $b    = getBody();
    $fcm  = $b['fcm_token'] ?? '';
    if (empty($fcm)) err('fcm_token required');

    $db->prepare("UPDATE customers SET fcm_token = ? WHERE id = ?")
       ->execute([$fcm, $user['uid']]);

    ok(['updated' => true]);
  }

  // ── GET PROFILE ───────────────────────────────────────
  case 'get': {
    $user = requireCustomer();
    $id   = $_GET['id'] ?? $user['uid'];

    // Customer can only get their own profile
    if ($id !== $user['uid']) err('Forbidden', 403);

    $stmt = $db->prepare("SELECT id, name, phone, email, gender, address, city, lat, lng, auth_method, created_at FROM customers WHERE id = ?");
    $stmt->execute([$id]);
    $customer = $stmt->fetch();
    if (!$customer) err('Customer not found', 404);
    ok($customer);
  }

  // ── BOOKING HISTORY ───────────────────────────────────
  case 'bookings': {
    $user = requireCustomer();
    $id   = $_GET['id'] ?? $user['uid'];
    if ($id !== $user['uid']) err('Forbidden', 403);

    $stmt = $db->prepare("
      SELECT id, svc_id, svc_name, svc_icon, provider_name,
             status, confirmed_price, amount, payment_status,
             slot_date, slot_time, address, city, created_at
      FROM bookings
      WHERE customer_id = ?
      ORDER BY created_at DESC
      LIMIT 50
    ");
    $stmt->execute([$id]);
    ok($stmt->fetchAll());
  }

  // ── DELETE ACCOUNT ────────────────────────────────────
  case 'delete': {
    $user = requireCustomer();
    $db->prepare("DELETE FROM customers WHERE id = ?")
       ->execute([$user['uid']]);
    ok(['deleted' => true]);
  }

  default:
    err('Invalid action');
}
