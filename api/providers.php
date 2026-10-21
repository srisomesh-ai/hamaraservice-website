<?php
// ═══════════════════════════════════════════════════════
// HamaraService — Provider API
// Endpoints:
//   POST ?action=register   — new provider registration (otp_token from
//                             otp.php required when REQUIRE_EMAIL_OTP)
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
require_once __DIR__ . '/schema.php';
require_once __DIR__ . '/otp_lib.php';
setCorsHeaders();

$action = $_GET['action'] ?? '';
$db     = getDB();
ensureSchema($db);

switch ($action) {

  // ── REGISTER ──────────────────────────────────────────
  case 'register': {
    $b = getBody();

    // Validate required fields
    $required = ['name','phone','email','password'];
    foreach ($required as $f) {
      if (empty($b[$f])) err("$f is required");
    }
    $b['phone'] = substr(preg_replace('/\D/', '', $b['phone']), -10);
    if (!preg_match('/^[6-9]\d{9}$/', $b['phone'])) err('Enter a valid 10-digit mobile number');
    if (!filter_var($b['email'], FILTER_VALIDATE_EMAIL)) err('Enter a valid email');
    if (strlen($b['password']) < 8) err('Password must be at least 8 characters');

    // Check email not already registered
    $check = $db->prepare("SELECT id FROM providers WHERE email = ?");
    $check->execute([strtolower($b['email'])]);
    if ($check->fetch()) err('Email already registered');
    // Email OTP (otp.php?action=verify, purpose provider_register)
    otpCheckRegistration($b, $b['email'], 'provider_register');

    // Generate provider ID: HS-PRO-NAME1234
    $namePart  = strtoupper(preg_replace('/[^a-zA-Z]/', '', $b['name']));
    $namePart  = str_pad(substr($namePart, 0, 4), 4, 'X');
    $phonePart = substr($b['phone'], -4);
    $id        = "HS-PRO-{$namePart}{$phonePart}";

    // Check ID not taken (add suffix if needed)
    $exists = $db->prepare("SELECT id FROM providers WHERE id = ?");
    $exists->execute([$id]);
    if ($exists->fetch()) {
      $id = $id . random_int(10,99);
    }

    // Hash password
    $hash = password_hash($b['password'], PASSWORD_BCRYPT);

    // Extract city from address
    $address = $b['address'] ?? '';
    $city    = hsCleanCity($b['city'] ?? '') ?? '';   // never an e-mail address
    // Extract city from address if not provided
    if (empty($city) && !empty($address)) {
      if (strpos($address, ',') !== false) {
        $parts = explode(',', $address);
        $city  = trim(end($parts));
      } else {
        $city = $address;
      }
      $city = hsCleanCity($city) ?? '';
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
      ':radius'  => max(1, min(50, (int)($b['radius_km'] ?? $b['radius'] ?? 5))),
      ':areas'   => json_encode($b['areas'] ?? []),
    ]);

    // Pinned service base location (map picker during registration)
    if (!empty($b['base_lat']) && !empty($b['base_lng'])) {
      $db->prepare("UPDATE providers SET base_lat = ?, base_lng = ? WHERE id = ?")
         ->execute([(float)$b['base_lat'], (float)$b['base_lng'], $id]);
    }

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
    $provCity  = $city;
    // List exactly the services saved for THIS provider (not the request
    // payload or the whole catalog).
    $sv = $db->prepare("SELECT COALESCE(NULLIF(ps.svc_name,''), s.name, ps.svc_id) AS n
                        FROM provider_services ps LEFT JOIN services s ON s.id = ps.svc_id
                        WHERE ps.provider_id = ? AND ps.enabled = 1 ORDER BY n");
    $sv->execute([$id]);
    $svcList  = $sv->fetchAll(PDO::FETCH_COLUMN);
    $svcNames = $svcList ? implode(', ', $svcList) : '—';

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
        . "Provider ID: $id\n"
        . "Services you selected: $svcNames\n\n"
        . "Once approved, login at:
https://hamaraservice.com/app/provider.html

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

    if (!$provider) err('NOT_REGISTERED: No provider account found for this email. Please register first.', 404);
    // Check password — support both hashed and plain (migration)
    $pwdMatch = password_verify($pwd, $provider['password_hash']);
    if (!$pwdMatch && strpos($provider['password_hash'], '$2y$') !== 0
        && hash_equals((string)$provider['password_hash'], $pwd)) {
      // Plain text password — upgrade to hash
      $newHash = password_hash($pwd, PASSWORD_BCRYPT);
      $db->prepare("UPDATE providers SET password_hash = ? WHERE id = ?")
         ->execute([$newHash, $provider['id']]);
      $pwdMatch = true;
    }
    if (!$pwdMatch) err('Incorrect password. Please try again.');

    // Status check
    if ($provider['status'] === 'suspended')
      err('Account suspended. Contact support.');
    if ($provider['status'] === 'rejected')
      err('Application rejected. Contact support.');
    if ($provider['status'] === 'deleted')
      err('NOT_REGISTERED: No provider account found for this email. Please register first.', 404);

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
    $me = currentUser();
    if (empty($id) && $me && $me['role'] === 'provider') $id = $me['id'];
    if (empty($id)) err('id required');
    $full = isAdminRequest() || ($me && $me['role'] === 'provider' && $me['id'] === $id);
    if (!$full) {
      // Public profile only — no phone, email, ID number, address, earnings
      $stmt = $db->prepare("SELECT id, name, gender, experience, bio, city, photo, rating, review_count, completed_bookings
                            FROM providers WHERE id = ? AND status = 'approved'");
      $stmt->execute([$id]);
      $p = $stmt->fetch();
      if (!$p) err('Provider not found', 404);
      $svc = $db->prepare("SELECT svc_id, svc_name, svc_icon, min_price, max_price FROM provider_services WHERE provider_id = ? AND enabled = 1");
      $svc->execute([$id]);
      $p['services'] = $svc->fetchAll();
      ok($p);
    }

    $stmt = $db->prepare("
      SELECT id, name, phone, email, whatsapp, gender, experience, bio,
             id_type, id_number, address, city, lat, lng, base_lat, base_lng, radius_km,
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
                'address','city','lat','lng','radius_km','available','upi_id','areas','photo',
                'base_lat','base_lng'];
    if (isset($b['city'])) {
      $c = hsCleanCity($b['city']);
      if ($c === null) unset($b['city']); else $b['city'] = $c;   // e-mail in City → keep the old city
    }
    foreach ($allowed as $f) {
      if (isset($b[$f])) {
        $fields[]   = "$f = :$f";
        $params[":$f"] = $b[$f];
      }
    }
    if (empty($fields)) err('Nothing to update');
    if (isset($b['available']) && (int)$b['available'] === 1 && $prov['status'] !== 'approved') err('Account not approved yet', 403);
    if (isset($params[':radius_km'])) $params[':radius_km'] = max(1, min(50, (int)$params[':radius_km']));
    foreach ([':base_lat', ':base_lng', ':lat', ':lng'] as $k) if (isset($params[$k])) $params[$k] = (float)$params[$k];
    if (isset($b['areas']) && is_array($b['areas'])) $params[':areas'] = json_encode($b['areas']);
    $fields[] = "last_seen = CURRENT_TIMESTAMP";

    $db->prepare("UPDATE providers SET " . implode(', ', $fields) . " WHERE id = :id")
       ->execute($params);

    ok(['updated' => true]);
  }

  // ── LIVE LOCATION (call every 30-60s while online / on a job) ──
  case 'location': {
    $prov = requireProvider();
    $b    = getBody();
    $lat = (float)($b['lat'] ?? 0); $lng = (float)($b['lng'] ?? 0);
    if (!$lat || !$lng) err('lat and lng required');
    $db->prepare("UPDATE providers SET lat = ?, lng = ?, last_seen = CURRENT_TIMESTAMP WHERE id = ?")
       ->execute([$lat, $lng, $prov['id']]);
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

    if ($avail === 1 && $prov['status'] !== 'approved') err('Account not approved yet', 403);
    $db->prepare("UPDATE providers SET available = ?, last_seen = CURRENT_TIMESTAMP WHERE id = ?")
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

    // Online, approved providers (offering the service if svc_id given).
    // Distance is computed in a subquery so the outer WHERE can filter on it
    // (the old HAVING version failed with "Unknown column p.areas").
    $params = [':lat' => $lat, ':lng' => $lng, ':lat2' => $lat];
    $svcJoin = '';
    if (!empty($svc_id)) {
      $svcJoin = " INNER JOIN provider_services ps
                   ON ps.provider_id = p.id AND ps.svc_id = :svc_id AND ps.enabled = 1";
      $params[':svc_id'] = $svc_id;
    }
    $inner = "
      SELECT p.id, p.name, p.rating, p.review_count, p.photo,
             COALESCE(NULLIF(p.base_lat, 0), p.lat, 0) AS lat, COALESCE(NULLIF(p.base_lng, 0), p.lng, 0) AS lng,
             p.radius_km, p.city, p.areas,
             CASE WHEN COALESCE(NULLIF(p.base_lat, 0), p.lat, 0) != 0 THEN
               6371 * ACOS(LEAST(1, COS(RADIANS(:lat)) * COS(RADIANS(COALESCE(NULLIF(p.base_lat, 0), p.lat)))
                 * COS(RADIANS(COALESCE(NULLIF(p.base_lng, 0), p.lng)) - RADIANS(:lng))
                 + SIN(RADIANS(:lat2)) * SIN(RADIANS(COALESCE(NULLIF(p.base_lat, 0), p.lat)))))
             END AS distance_km
      FROM providers p $svcJoin
      WHERE p.status = 'approved' AND p.available = 1
        AND (p.last_seen IS NULL OR p.last_seen > NOW() - INTERVAL 30 MINUTE)
    ";
    $cityCond = '';
    if (!empty($city)) {
      $cityCond = "(t.city LIKE :city OR t.areas LIKE :city2)";
      $params[':city']  = "%$city%";
      $params[':city2'] = "%$city%";
    }
    if ($lat != 0 && $lng != 0) {
      // GPS: within radius; providers without GPS match by city/area
      // within the search radius AND within the provider's own service range
      $where = "(t.distance_km IS NOT NULL AND t.distance_km <= :radius AND t.distance_km <= GREATEST(1, COALESCE(t.radius_km, 10)))";
      if ($cityCond) $where .= " OR (t.distance_km IS NULL AND $cityCond)";
      $params[':radius'] = $radius;
      $sql = "SELECT * FROM ($inner) t WHERE $where ORDER BY t.distance_km IS NULL, t.distance_km ASC LIMIT 20";
    } else {
      $sql = "SELECT * FROM ($inner) t" . ($cityCond ? " WHERE $cityCond" : '') . " ORDER BY t.rating DESC LIMIT 20";
    }

    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $providers = $stmt->fetchAll();
    foreach ($providers as &$pp) {
      if (!$lat || !$lng || $pp['distance_km'] === null) $pp['distance_km'] = null;
      else $pp['distance_km'] = round((float)$pp['distance_km'], 1);
      unset($pp['areas'], $pp['lat'], $pp['lng']);   // no exact provider location to the public
    }
    unset($pp);

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
    $stmt = $db->prepare("SELECT fcm_token, name, email FROM providers WHERE id = ?");
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
        . "https://hamaraservice.com/app/provider.html

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
    sendPush($p['fcm_token'] ?? '', 'Account Approved ✅', 'You can now go online and receive jobs.', ['event'=>'account_approved']);
    ok(['approved' => true]);
  }

  // ── ADMIN: SET STATUS (pending / review / rejected …) ──
  case 'set_status': {
    requireAdmin();
    $b  = getBody();
    $id = $b['id'] ?? '';
    $st = $b['status'] ?? '';
    if (empty($id) || !in_array($st, ['pending','review','approved','suspended','rejected'], true)) err('id and valid status required');
    $db->prepare("UPDATE providers SET status = ?, available = IF(? = 'approved', available, 0) WHERE id = ?")
       ->execute([$st, $st, $id]);
    ok(['id' => $id, 'status' => $st]);
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
                       total_bookings, completed_bookings, total_earned, pending_earned,
                       upi_id, photo, last_seen, registered_at
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
    // Body: {svc_id, option_prices|prices: {group_option: price}, min_price?, max_price?}
    // The provider's min/max for the service (shown to customers as the price
    // range) comes from explicit min/max if given, else from the option prices.
    $prov = requireProvider();
    $b    = getBody();
    $svc_id = $b['svc_id'] ?? '';
    if (empty($svc_id)) err('svc_id required');
    $raw = $b['option_prices'] ?? $b['prices'] ?? [];
    $prices = [];
    if (is_array($raw)) {
      foreach ($raw as $k => $v) {
        if (is_numeric($v) && (int)$v > 0) $prices[(string)$k] = (int)$v;
      }
    }
    $min = (int)($b['min_price'] ?? 0);
    $max = (int)($b['max_price'] ?? 0);
    if ($prices) {
      if ($min <= 0) $min = min($prices);
      if ($max <= 0) $max = max($prices);
    }
    if ($min <= 0 && $max <= 0 && !$prices) err('Enter at least one price');
    if ($min > 0 && $max > 0 && $max < $min) err('Max price must be greater than min price');
    if ($max <= 0) $max = $min;
    if ($min <= 0) $min = $max;

    try { $db->exec("ALTER TABLE provider_services ADD COLUMN option_prices JSON NULL"); } catch (Exception $e) {}

    $lookup = $db->prepare("SELECT name, icon, category FROM services WHERE id = ?");
    $lookup->execute([$svc_id]);
    $svc = $lookup->fetch() ?: ['name' => $svc_id, 'icon' => '', 'category' => ''];

    $db->prepare("
      INSERT INTO provider_services
        (provider_id, svc_id, svc_name, svc_icon, svc_cat, enabled, min_price, max_price, option_prices)
      VALUES (?, ?, ?, ?, ?, 1, ?, ?, ?)
      ON DUPLICATE KEY UPDATE
        enabled = 1, min_price = VALUES(min_price), max_price = VALUES(max_price),
        option_prices = VALUES(option_prices)
    ")->execute([$prov['id'], $svc_id, $svc['name'], $svc['icon'], $svc['category'],
                 $min, $max, json_encode((object)$prices)]);

    ok(['saved' => true, 'svc_id' => $svc_id, 'min_price' => $min, 'max_price' => $max]);
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
