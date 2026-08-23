<?php
// ═══════════════════════════════════════════════════════
// HamaraService — Provider API
// Endpoints:
//   POST ?action=register   — new provider registration
//   POST ?action=login      — provider login → JWT
//   GET  ?action=get&id=X   — get provider profile
//   POST ?action=update     — update profile fields
//   POST ?action=fcm        — save FCM token
//   POST ?action=available  — toggle availability
//   GET  ?action=nearby     — get nearby approved providers
//   POST ?action=services   — save provider services with min/max
//   GET  ?action=services&id=X — get provider services
//   POST ?action=approve    — admin approve provider
//   POST ?action=suspend    — admin suspend provider
// ═══════════════════════════════════════════════════════
require_once __DIR__ . '/db.php';
setCorsHeaders();

$action = $_GET['action'] ?? '';
$db     = getDB();

// ── auto-migrate: upi_id + areas ──
try { $db->exec("ALTER TABLE providers ADD COLUMN upi_id VARCHAR(100) NULL"); } catch (Exception $e) {}
try { $db->exec("ALTER TABLE providers ADD COLUMN areas TEXT NULL"); } catch (Exception $e) {}
try { $db->exec("ALTER TABLE providers ADD COLUMN photo VARCHAR(255) NULL"); } catch (Exception $e) {}

switch ($action) {

  // ── REGISTER ──────────────────────────────────────────
  case 'register': {
    $b = getBody();

    // Validate required fields
    $required = ['name','phone','email','password'];
    foreach ($required as $f) {
      if (empty($b[$f])) err("$f is required");
    }

    // Check email not already registered
    $check = $db->prepare("SELECT id FROM providers WHERE email = ?");
    $check->execute([strtolower($b['email'])]);
    if ($check->fetch()) err('Email already registered');

    // Generate provider ID: HS-PRO-NAME1234
    $namePart  = strtoupper(preg_replace('/[^a-zA-Z]/', '', $b['name']));
    $namePart  = str_pad(substr($namePart, 0, 4), 4, 'X');
    $phonePart = substr($b['phone'], -4);
    $id        = "HS-PRO-{$namePart}{$phonePart}";

    // Check ID not taken (add suffix if needed)
    $exists = $db->prepare("SELECT id FROM providers WHERE id = ?");
    $exists->execute([$id]);
    if ($exists->fetch()) {
      $id = $id . rand(10,99);
    }

    // Hash password
    $hash = password_hash($b['password'], PASSWORD_BCRYPT);

    // Extract city from address
    $address = $b['address'] ?? '';
    $city    = $b['city'] ?? '';
    // Extract city from address if not provided
    if (empty($city) && !empty($address)) {
      if (strpos($address, ',') !== false) {
        $parts = explode(',', $address);
        $city  = trim(end($parts));
      } else {
        $city = $address;
      }
    }

    // Insert provider
    $stmt = $db->prepare("
      INSERT INTO providers
        (id, name, phone, email, whatsapp, password_hash,
         gender, experience, bio, id_type, id_number,
         address, city, lat, lng, radius_km, areas, status)
      VALUES
        (:id, :name, :phone, :email, :whatsapp, :pwd,
         :gender, :exp, :bio, :idtype, :idnum,
         :address, :city, :lat, :lng, :radius, :areas, 'pending')
    ");
    $stmt->execute([
      ':id'      => $id,
      ':name'    => $b['name'],
      ':phone'   => $b['phone'],
      ':email'   => strtolower($b['email']),
      ':whatsapp'=> $b['whatsapp'] ?? $b['phone'],
      ':pwd'     => $hash,
      ':gender'  => $b['gender']     ?? '',
      ':exp'     => $b['experience'] ?? '',
      ':bio'     => $b['bio']        ?? '',
      ':idtype'  => $b['id_type']    ?? $b['idType']  ?? '',
      ':idnum'   => $b['id_number']  ?? $b['idNum'] ?? '',
      ':address' => $address,
      ':city'    => $city,
      ':lat'     => (float)($b['lat'] ?? 0),
      ':lng'     => (float)($b['lng'] ?? 0),
      ':radius'  => (int)($b['radius_km'] ?? $b['radius'] ?? 5),
      ':areas'   => json_encode($b['areas'] ?? []),
    ]);

    // Save services if provided
    if (!empty($b['services']) && is_array($b['services'])) {
      $svcStmt = $db->prepare("
        INSERT IGNORE INTO provider_services
          (provider_id, svc_id, svc_name, svc_icon, svc_cat, enabled, min_price, max_price)
        VALUES (?, ?, ?, ?, ?, 1, 0, 0)
      ");
      foreach ($b['services'] as $svc) {
        $svcStmt->execute([
          $id,
          $svc['id']   ?? '',
          $svc['name'] ?? '',
          $svc['icon'] ?? '',
          $svc['cat']  ?? '',
        ]);
      }
    }

    // ── Email notifications ──────────────────────────────
    $provName  = $b['name']  ?? '';
    $provEmail = $b['email'] ?? '';
    $provPhone = $b['phone'] ?? '';
    $provCity  = $b['city']  ?? '';
    $svcNames  = implode(', ', array_column($b['services'] ?? [], 'name'));

    // 1. Email to admin
    $adminSubject = 'New Provider Registration — ' . $provName;
    $adminBody = "New provider registered on HamaraService.

"
      . "Name:     $provName
"
      . "Email:    $provEmail
"
      . "Phone:    $provPhone
"
      . "City:     $provCity
"
      . "Services: $svcNames
"
      . "ID:       $id

"
      . "Review at: https://hamaraservice.com/admin.html
";
    @mail('info@hamaraservice.com', $adminSubject, $adminBody,
      implode("
", [
        'From: HamaraService <info@hamaraservice.com>',
        'Reply-To: ' . $provEmail,
        'Content-Type: text/plain; charset=UTF-8',
        'X-Mailer: HamaraService-PHP'
      ])
    );

    // 2. Email to provider
    if (!empty($provEmail)) {
      $provSubject = 'Application Received — HamaraService';
      $provBody = "Dear $provName,

"
        . "Thank you for registering as a provider on HamaraService!

"
        . "Your application is under review. We will approve your account within 24-48 hours.

"
        . "Provider ID: $id

"
        . "Once approved, login at:
https://hamaraservice.com/provider-portal.html

"
        . "Or use the HamaraService Provider App.

"
        . "Support: info@hamaraservice.com

"
        . "Best regards,
HamaraService Team";
      @mail($provEmail, $provSubject, $provBody,
        implode("
", [
          'From: HamaraService <info@hamaraservice.com>',
          'Content-Type: text/plain; charset=UTF-8',
          'X-Mailer: HamaraService-PHP'
        ])
      );
    }

    ok(['id' => $id, 'status' => 'pending',
        'message' => 'Registration successful. Awaiting admin approval.']);
  }

  // ── CHECK EMAIL EXISTS ───────────────────────────────
  case 'check_email': {
    $email = strtolower(trim($_GET['email'] ?? ''));
    if (empty($email)) err('email required');
    $stmt = $db->prepare("SELECT id FROM providers WHERE email = ? LIMIT 1");
    $stmt->execute([$email]);
    $row = $stmt->fetch();
    echo json_encode(['exists' => !empty($row), 'success' => true]);
    exit;
  }

  // ── LOGIN ─────────────────────────────────────────────
  case 'login': {
    $b     = getBody();
    $email = strtolower($b['email'] ?? '');
    $pwd   = $b['password'] ?? '';

    if (empty($email) || empty($pwd)) err('Email and password required');

    $stmt = $db->prepare("SELECT * FROM providers WHERE email = ?");
    $stmt->execute([$email]);
    $provider = $stmt->fetch();

    if (!$provider) err('Email or password incorrect');
    // Check password — support both hashed and plain (migration)
    $pwdMatch = password_verify($pwd, $provider['password_hash']);
    if (!$pwdMatch && $pwd === $provider['password_hash']) {
      // Plain text password — upgrade to hash
      $newHash = password_hash($pwd, PASSWORD_BCRYPT);
      $db->prepare("UPDATE providers SET password_hash = ? WHERE id = ?")
         ->execute([$newHash, $provider['id']]);
      $pwdMatch = true;
    }
    if (!$pwdMatch) err('Email or password incorrect');

    // Status check
    if ($provider['status'] === 'suspended')
      err('Account suspended. Contact support.');
    if ($provider['status'] === 'rejected')
      err('Application rejected. Contact support.');

    // Remove sensitive fields
    unset($provider['password_hash']);

    // Generate JWT
    $token = generateJWT($provider['id']);

    ok([
      'token'    => $token,
      'provider' => $provider,
      'status'   => $provider['status'],
    ]);
  }

  // ── GET PROFILE ───────────────────────────────────────
  case 'get': {
    $id = $_GET['id'] ?? '';
    if (empty($id)) err('id required');

    $stmt = $db->prepare("
      SELECT id, name, phone, email, whatsapp, gender, experience, bio,
             id_type, id_number, address, city, lat, lng, radius_km,
             upi_id, areas, photo,
             status, available, rating, review_count,
             total_bookings, completed_bookings, total_earned, pending_earned,
             registered_at
      FROM providers WHERE id = ?
    ");
    $stmt->execute([$id]);
    $p = $stmt->fetch();
    if (!$p) err('Provider not found', 404);

    // Get their services
    $svc = $db->prepare("SELECT * FROM provider_services WHERE provider_id = ? AND enabled = 1");
    $svc->execute([$id]);
    $p['services'] = $svc->fetchAll();

    ok($p);
  }

  // ── UPDATE PROFILE ────────────────────────────────────
  case 'update': {
    $prov = requireProvider();
    $b    = getBody();

    $fields = [];
    $params = [':id' => $prov['id']];
    $allowed = ['name','phone','whatsapp','gender','experience','bio',
                'address','city','lat','lng','radius_km','available','upi_id','areas','photo'];
    foreach ($allowed as $f) {
      if (isset($b[$f])) {
        $fields[]   = "$f = :$f";
        $params[":$f"] = $b[$f];
      }
    }
    if (empty($fields)) err('Nothing to update');

    $db->prepare("UPDATE providers SET " . implode(', ', $fields) . " WHERE id = :id")
       ->execute($params);

    ok(['updated' => true]);
  }

  // ── SAVE FCM TOKEN ────────────────────────────────────
  case 'fcm': {
    $prov = requireProvider();
    $b    = getBody();
    $fcm  = $b['fcm_token'] ?? '';
    if (empty($fcm)) err('fcm_token required');

    $db->prepare("UPDATE providers SET fcm_token = ? WHERE id = ?")
       ->execute([$fcm, $prov['id']]);
    ok(['updated' => true]);
  }

  // ── TOGGLE AVAILABILITY ───────────────────────────────
  case 'available': {
    $prov = requireProvider();
    $b    = getBody();
    $avail = isset($b['available']) ? (int)(bool)$b['available'] : null;

    // Toggle if not specified
    if ($avail === null) {
      $cur = $db->prepare("SELECT available FROM providers WHERE id = ?");
      $cur->execute([$prov['id']]);
      $avail = $cur->fetchColumn() ? 0 : 1;
    }

    $db->prepare("UPDATE providers SET available = ? WHERE id = ?")
       ->execute([$avail, $prov['id']]);
    ok(['available' => (bool)$avail]);
  }

  // ── NEARBY PROVIDERS ──────────────────────────────────
  // Used by customer radar to find providers
  case 'nearby': {
    $lat    = (float)($_GET['lat']    ?? 0);
    $lng    = (float)($_GET['lng']    ?? 0);
    $svc_id = $_GET['svc_id'] ?? '';
    $radius = (float)($_GET['radius'] ?? 20); // km

    $city = trim($_GET['city'] ?? '');

    // Haversine distance in SQL
    $sql = "
      SELECT p.id, p.name, p.phone, p.rating, p.review_count,
             p.lat, p.lng, p.radius_km, p.city,
             (6371 * ACOS(
               COS(RADIANS(:lat)) * COS(RADIANS(p.lat))
               * COS(RADIANS(p.lng) - RADIANS(:lng))
               + SIN(RADIANS(:lat2)) * SIN(RADIANS(p.lat))
             )) AS distance_km
      FROM providers p
    ";

    $params = [':lat'=>$lat, ':lng'=>$lng, ':lat2'=>$lat];

    // Filter by service if provided
    if (!empty($svc_id)) {
      $sql .= " INNER JOIN provider_services ps
                ON ps.provider_id = p.id
                AND ps.svc_id = :svc_id
                AND ps.enabled = 1";
      $params[':svc_id'] = $svc_id;
    }

    if ($lat != 0 && $lng != 0) {
      // GPS mode: distance filter, but include providers without GPS if city matches
      $sql .= "
        WHERE p.status    = 'approved'
          AND p.available = 1
        HAVING (p.lat != 0 AND distance_km <= :radius)";
      if (!empty($city)) {
        $sql .= " OR (p.lat = 0 AND (p.city LIKE :city OR p.areas LIKE :city2))";
        $params[':city'] = "%$city%";
        $params[':city2'] = "%$city%";
      }
      $sql .= " ORDER BY distance_km ASC LIMIT 20";
      $params[':radius'] = $radius;
    } else {
      // No GPS: match by city only
      $sql .= " WHERE p.status = 'approved' AND p.available = 1";
      if (!empty($city)) {
        $sql .= " AND (p.city LIKE :city OR p.areas LIKE :city2)";
        $params[':city'] = "%$city%";
        $params[':city2'] = "%$city%";
      }
      $sql .= " ORDER BY p.rating DESC LIMIT 20";
    }

    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $providers = $stmt->fetchAll();

    // Add service price range per provider
    if (!empty($svc_id) && !empty($providers)) {
      $ids = array_column($providers, 'id');
      $placeholders = implode(',', array_fill(0, count($ids), '?'));
      $priceStmt = $db->prepare("
        SELECT provider_id, min_price, max_price
        FROM provider_services
        WHERE provider_id IN ($placeholders) AND svc_id = ?
      ");
      $priceStmt->execute([...$ids, $svc_id]);
      $prices = [];
      foreach ($priceStmt->fetchAll() as $row) {
        $prices[$row['provider_id']] = $row;
      }
      foreach ($providers as &$p) {
        $p['min_price'] = $prices[$p['id']]['min_price'] ?? 0;
        $p['max_price'] = $prices[$p['id']]['max_price'] ?? 0;
      }
    }

    ok($providers);
  }

  // ── SAVE SERVICES (min/max prices) ───────────────────
  case 'services': {
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
      // GET services for a provider
      $id = $_GET['id'] ?? '';
      if (empty($id)) err('id required');
      $stmt = $db->prepare("SELECT * FROM provider_services WHERE provider_id = ?");
      $stmt->execute([$id]);
      ok($stmt->fetchAll());
    }

    // POST — save/update services
    $prov = requireProvider();
    $b    = getBody();

    if (empty($b['services']) || !is_array($b['services']))
      err('services array required');

    $upsert = $db->prepare("
      INSERT INTO provider_services
        (provider_id, svc_id, svc_name, svc_icon, svc_cat, enabled, min_price, max_price)
      VALUES (?, ?, ?, ?, ?, ?, ?, ?)
      ON DUPLICATE KEY UPDATE
        svc_name  = VALUES(svc_name),
        svc_icon  = VALUES(svc_icon),
        svc_cat   = VALUES(svc_cat),
        enabled   = VALUES(enabled),
        min_price = IF(VALUES(min_price) > 0, VALUES(min_price), min_price),
        max_price = IF(VALUES(max_price) > 0, VALUES(max_price), max_price)
    ");

    $saved = 0;
    foreach ($b['services'] as $svc) {
      $svcId = $svc['svc_id'] ?? '';
      if (empty($svcId)) continue;

      // Lookup svc_name/icon/cat from services table if not provided
      $svcName = $svc['svc_name'] ?? '';
      $svcIcon = $svc['svc_icon'] ?? '';
      $svcCat  = $svc['svc_cat']  ?? '';
      if (empty($svcName)) {
        $lookup = $db->prepare("SELECT name, icon, category FROM services WHERE id = ?");
        $lookup->execute([$svcId]);
        $row = $lookup->fetch();
        if ($row) {
          $svcName = $row['name'];
          $svcIcon = $row['icon'];
          $svcCat  = $row['category'];
        }
      }

      $upsert->execute([
        $prov['id'],
        $svcId,
        $svcName,
        $svcIcon,
        $svcCat,
        (int)($svc['enabled']   ?? 1),
        (int)($svc['min_price'] ?? 0),
        (int)($svc['max_price'] ?? 0),
      ]);
      $saved++;
    }

    ok(['saved' => $saved]);
  }

  // ── ADMIN: DELETE ────────────────────────────────────
  case 'delete': {
    requireAdmin();
    $b  = getBody();
    $id = $b['id'] ?? ($_GET['id'] ?? '');
    if (empty($id)) err('id required');
    $db->prepare("UPDATE providers SET status = 'deleted', available = 0 WHERE id = ?")
       ->execute([$id]);
    ok(['deleted' => true, 'id' => $id]);
  }

  // ── ADMIN: RESTORE ────────────────────────────────────
  case 'restore': {
    requireAdmin();
    $b  = getBody();
    $id = $b['id'] ?? '';
    if (empty($id)) err('id required');
    $db->prepare("UPDATE providers SET status = 'pending' WHERE id = ?")
       ->execute([$id]);
    ok(['restored' => true, 'id' => $id]);
  }

  // ── ADMIN: APPROVE ────────────────────────────────────
  case 'approve': {
    requireAdmin();
    $b  = getBody();
    $id = $b['id'] ?? '';
    if (empty($id)) err('id required');

    $db->prepare("UPDATE providers SET status = 'approved' WHERE id = ?")
       ->execute([$id]);

    // Get FCM token to notify provider
    $stmt = $db->prepare("SELECT fcm_token, name FROM providers WHERE id = ?");
    $stmt->execute([$id]);
    $p = $stmt->fetch();

    // Send approval email to provider
    if (!empty($p['email'] ?? '')) {
      $aSubject = 'Account Approved — HamaraService';
      $aBody = "Dear {$p['name']},

"
        . "Congratulations! Your HamaraService provider account has been approved.

"
        . "You can now login and start receiving jobs:
"
        . "https://hamaraservice.com/provider-portal.html

"
        . "Or login on the HamaraService Provider App.

"
        . "Support: info@hamaraservice.com

"
        . "Best regards,
HamaraService Team";
      @mail($p['email'], $aSubject, $aBody,
        implode("
", [
          'From: HamaraService <info@hamaraservice.com>',
          'Content-Type: text/plain; charset=UTF-8',
        ])
      );
    }
    ok(['approved' => true, 'fcm_token' => $p['fcm_token'] ?? '']);
  }

  // ── ADMIN: SUSPEND ────────────────────────────────────
  case 'suspend': {
    requireAdmin();
    $b  = getBody();
    $id = $b['id'] ?? '';
    if (empty($id)) err('id required');

    $db->prepare("UPDATE providers SET status = 'suspended', available = 0 WHERE id = ?")
       ->execute([$id]);
    ok(['suspended' => true]);
  }

  // ── ADMIN: LIST ALL ───────────────────────────────────
  case 'list': {
    requireAdmin();
    $status = $_GET['status'] ?? '';
    $city   = $_GET['city']   ?? '';

    $sql    = "SELECT id, name, phone, email, whatsapp, gender, experience, bio,
                       id_type, id_number, address, city, lat, lng, radius_km,
                       status, available, rating, review_count,
                       total_bookings, completed_bookings, total_earned,
                       fcm_token, registered_at
               FROM providers WHERE status != 'deleted'";
    $params = [];

    if (!empty($status)) { $sql .= " AND status = ?"; $params[] = $status; }
    if (!empty($city))   { $sql .= " AND city LIKE ?"; $params[] = "%$city%"; }

    $sql .= " ORDER BY registered_at DESC";

    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    ok($stmt->fetchAll());
  }

  // ── SAVE PER-OPTION PRICES ───────────────────────────────
  case 'save_option_prices': {
    $prov = requireProvider();
    $b    = getBody();
    $svc_id = $b['svc_id'] ?? '';
    $prices = $b['option_prices'] ?? [];
    if (empty($svc_id)) err('svc_id required');

    // Store in provider_service_prices table (create if needed)
    // Using service_prices table with provider_id prefix
    // We store as provider_id:svc_id:group_opt → price
    // Simple approach: store as JSON in provider_services extras column
    // Actually use a dedicated JSON column approach via provider_services notes

    // Store prices as JSON in a new column
    // Check if column exists, add if not
    try {
      $db->exec("ALTER TABLE provider_services ADD COLUMN option_prices JSON NULL");
    } catch (Exception $e) {} // Ignore if already exists

    $stmt = $db->prepare("
      INSERT INTO provider_services
        (provider_id, svc_id, enabled, min_price, max_price, option_prices)
      VALUES (?, ?, 1, 0, 0, ?)
      ON DUPLICATE KEY UPDATE
        option_prices = VALUES(option_prices)
    ");
    $stmt->execute([
      $prov['id'],
      $svc_id,
      json_encode($prices),
    ]);

    // Also update min/max from the prices
    if (!empty($prices)) {
      $vals = array_values($prices);
      $min = min($vals); $max = max($vals);
      $db->prepare("UPDATE provider_services SET min_price=?, max_price=? WHERE provider_id=? AND svc_id=?")
         ->execute([$min, $max, $prov['id'], $svc_id]);
    }

    ok(['saved' => true, 'svc_id' => $svc_id]);
  }

  // ── GET PER-OPTION PRICES ─────────────────────────────────
  case 'get_option_prices': {
    $prov = requireProvider();

    try {
      $db->exec("ALTER TABLE provider_services ADD COLUMN option_prices JSON NULL");
    } catch (Exception $e) {}

    $stmt = $db->prepare("
      SELECT svc_id, option_prices, min_price, max_price
      FROM provider_services
      WHERE provider_id = ? AND option_prices IS NOT NULL
    ");
    $stmt->execute([$prov['id']]);
    $rows = $stmt->fetchAll();

    $result = [];
    foreach ($rows as $row) {
      $prices = json_decode($row['option_prices'], true) ?? [];
      $result[$row['svc_id']] = $prices;
    }
    ok($result);
  }

  default:
    err('Invalid action');
}
