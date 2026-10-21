<?php
// ═══════════════════════════════════════════════════════
// HamaraService — Customer API
// Endpoints:
//   POST ?action=register  — create/update customer after Firebase signup
//                            (creating a NEW profile needs otp_token from
//                             otp.php when REQUIRE_EMAIL_OTP is on)
//   POST ?action=update    — update profile fields
//   POST ?action=fcm       — save FCM token
//   GET  ?action=get&id=X  — get customer profile
//   GET  ?action=bookings&id=X — get customer booking history
// ═══════════════════════════════════════════════════════
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/schema.php';
require_once __DIR__ . '/otp_lib.php';
setCorsHeaders();

// ── Firebase Auth REST (same accounts as the mobile app) ──
function fbAuth($method, array $payload) {
    $ch = curl_init(FIREBASE_AUTH_URL . '/accounts:' . $method . '?key=' . urlencode(FIREBASE_WEB_API_KEY));
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_TIMEOUT => 10,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_POSTFIELDS => json_encode($payload),
    ]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $data = json_decode((string)$resp, true) ?: [];
    if ($code >= 200 && $code < 300 && empty($data['error'])) return ['ok' => true, 'data' => $data, 'error' => ''];
    $msg = (string)($data['error']['message'] ?? ($resp === false ? 'NETWORK_ERROR' : 'UNKNOWN'));
    return ['ok' => false, 'data' => $data, 'error' => trim(explode(':', $msg)[0])];
}
function fbAuthMessage($code) {
    $map = [
        'WEAK_PASSWORD' => 'Password is too weak (min 8 characters).',
        'INVALID_EMAIL' => 'Enter a valid email.',
        'USER_DISABLED' => 'This account is disabled. Contact support.',
        'TOO_MANY_ATTEMPTS_TRY_LATER' => 'Too many attempts. Try again later.',
        'NETWORK_ERROR' => 'Login service unreachable. Try again.',
    ];
    return $map[$code] ?? 'Login failed. Please try again.';
}
// Re-key a web-only customer (HS-CUST-…) to their Firebase uid, keeping bookings
function migrateCustomer($db, array $old, $newId) {
    if ($old['id'] === $newId) return $newId;
    $db->beginTransaction();
    $ex = $db->prepare("SELECT id FROM customers WHERE id = ?");
    $ex->execute([$newId]);
    if (!$ex->fetch()) {
        $db->prepare("INSERT INTO customers (id, name, phone, email, gender, address, city, lat, lng, fcm_token, auth_method, password_hash)
                      SELECT ?, name, phone, email, gender, address, city, lat, lng, fcm_token, 'email', password_hash FROM customers WHERE id = ?")
           ->execute([$newId, $old['id']]);
    }
    $db->prepare("UPDATE bookings SET customer_id = ? WHERE customer_id = ?")->execute([$newId, $old['id']]);
    try { $db->prepare("UPDATE reviews SET customer_id = ? WHERE customer_id = ?")->execute([$newId, $old['id']]); } catch (Throwable $e) {}
    $db->prepare("UPDATE customers SET email = NULL, auth_method = 'merged' WHERE id = ?")->execute([$old['id']]);
    $db->commit();
    return $newId;
}

$action = $_GET['action'] ?? '';
$db     = getDB();
ensureSchema($db);

switch ($action) {

  // ── WEB APP: REGISTER ─────────────────────────────────
  // Creates the account in Firebase Auth (same account as the mobile app),
  // so a customer can use one email + password on web and app and sees the
  // same bookings. Falls back to a web-only account if Firebase is not
  // configured (FIREBASE_WEB_API_KEY empty).
  case 'web_register': {
    $b = getBody();
    $name  = trim($b['name'] ?? '');
    $phone = substr(preg_replace('/\D/', '', $b['phone'] ?? ''), -10);
    $email = strtolower(trim($b['email'] ?? ''));
    $pwd   = $b['password'] ?? '';
    if (empty($name) || empty($email) || empty($pwd)) err('name, email, password required');
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) err('Enter a valid email');
    if (strlen($pwd) < 8) err('Password must be at least 8 characters');
    if ($phone !== '' && !preg_match('/^[6-9]\d{9}$/', $phone)) err('Enter a valid 10-digit mobile number');

    $chk = $db->prepare("SELECT id FROM customers WHERE email = ? LIMIT 1");
    $chk->execute([$email]);
    if ($chk->fetch()) err('Email already registered. Please login.');
    // Email OTP (otp.php?action=verify, purpose customer_register)
    otpCheckRegistration($b, $email, 'customer_register');

    if (FIREBASE_WEB_API_KEY !== '') {
      $r = fbAuth('signUp', ['email' => $email, 'password' => $pwd, 'returnSecureToken' => true]);
      if (!$r['ok']) {
        if ($r['error'] === 'EMAIL_EXISTS') err('Email already registered (maybe in the mobile app). Please login.');
        err(fbAuthMessage($r['error']));
      }
      $id = $r['data']['localId'];
      fbAuth('update', ['idToken' => $r['data']['idToken'], 'displayName' => $name, 'returnSecureToken' => false]);
      $method = 'email';
    } else {
      $id = 'HS-CUST-' . strtoupper(substr(preg_replace('/[^a-z]/','',explode('@',$email)[0]).'xxxx',0,4)) . random_int(100000,999999);
      $method = 'web';
    }
    $db->prepare("
      INSERT INTO customers (id, name, phone, email, password_hash, auth_method)
      VALUES (?, ?, ?, ?, ?, ?)
    ")->execute([$id, $name, $phone, $email, password_hash($pwd, PASSWORD_BCRYPT), $method]);

    $token = makeJWT(['type'=>'customer','uid'=>$id,'email'=>$email,'name'=>$name]);
    ok(['token'=>$token, 'customer'=>['id'=>$id,'name'=>$name,'phone'=>$phone,'email'=>$email]]);
  }

  // ── WEB APP: LOGIN ────────────────────────────────────
  // 1. Firebase email/password (same account as the mobile app).
  // 2. Legacy web-only account (local password) → migrated onto a Firebase
  //    account so web and app share one customer id from now on.
  case 'web_login': {
    $b = getBody();
    $email = strtolower(trim($b['email'] ?? ''));
    $pwd   = $b['password'] ?? '';
    if (empty($email) || empty($pwd)) err('email and password required');

    $stmt = $db->prepare("SELECT * FROM customers WHERE email = ? ORDER BY (auth_method = 'web') ASC LIMIT 1");
    $stmt->execute([$email]);
    $local = $stmt->fetch() ?: null;

    $uid = null; $fbErr = '';
    if (FIREBASE_WEB_API_KEY !== '') {
      $r = fbAuth('signInWithPassword', ['email' => $email, 'password' => $pwd, 'returnSecureToken' => true]);
      $fbErr = $r['error'];
      if ($r['ok']) {
        $uid = $r['data']['localId'];
      } elseif (!in_array($r['error'], ['EMAIL_NOT_FOUND','INVALID_LOGIN_CREDENTIALS','INVALID_PASSWORD'], true)) {
        err(fbAuthMessage($r['error']), $r['error'] === 'TOO_MANY_ATTEMPTS_TRY_LATER' ? 429 : 401);
      }
    }

    if ($uid === null) {
      // Legacy web-only account?
      $legacyOk = $local && !empty($local['password_hash']) && (
          password_verify($pwd, $local['password_hash'])
          || (strpos($local['password_hash'], '$2y$') !== 0 && hash_equals((string)$local['password_hash'], $pwd)));
      if (!$legacyOk) {
        if (!$local && (FIREBASE_WEB_API_KEY === '' || $fbErr === 'EMAIL_NOT_FOUND'))
          err('NOT_REGISTERED: No account found for this email. Please create an account.', 404);
        if ($local || $fbErr === 'INVALID_PASSWORD') err('Incorrect password. Please try again.', 401);
        err('Incorrect email or password. New here? Create an account.', 401);
      }
      $uid = $local['id'];
      // Move this web-only account onto Firebase so the mobile app can use it too
      if (FIREBASE_WEB_API_KEY !== '' && strpos($uid, 'HS-CUST-') === 0) {
        $r = fbAuth('signUp', ['email' => $email, 'password' => $pwd, 'returnSecureToken' => true]);
        if ($r['ok']) $uid = migrateCustomer($db, $local, $r['data']['localId']);
      }
      if (strpos($local['password_hash'], '$2y$') !== 0) {
        $db->prepare("UPDATE customers SET password_hash = ? WHERE id = ?")->execute([password_hash($pwd, PASSWORD_BCRYPT), $uid]);
      }
    }
    // NB: an older web-only row with the same email is merged ONLY after its own
    // password was verified above — never just because the emails match.

    // Make sure the shared customer row exists (e.g. account created in the app)
    $c = $db->prepare("SELECT * FROM customers WHERE id = ?");
    $c->execute([$uid]);
    $cust = $c->fetch();
    if (!$cust) {
      $db->prepare("INSERT INTO customers (id, name, email, auth_method) VALUES (?, ?, ?, 'email')")
         ->execute([$uid, ucfirst(explode('@', $email)[0]), $email]);
      $c->execute([$uid]);
      $cust = $c->fetch();
    }

    $token = makeJWT(['type'=>'customer','uid'=>$uid,'email'=>$email,'name'=>$cust['name']]);
    ok(['token'=>$token, 'customer'=>[
      'id'=>$uid,'name'=>$cust['name'],'phone'=>$cust['phone'],'email'=>$email,
      'address'=>$cust['address'],'city'=>$cust['city'],'lat'=>(float)$cust['lat'],'lng'=>(float)$cust['lng']
    ]]);
  }

  case 'register': {
    $user = requireCustomer();  // verifies Firebase token
    $b    = getBody();

    // Creating a NEW profile → email must be verified by OTP (when
    // REQUIRE_EMAIL_OTP). Updating an existing profile never needs it.
    // Accounts whose e-mail Firebase already verified are accepted too.
    $ex = $db->prepare("SELECT 1 FROM customers WHERE id = ?");
    $ex->execute([$user['uid']]);
    $isNew = !$ex->fetchColumn();
    $verifiedEmail = '';
    if ($isNew || trim((string)($b['otp_token'] ?? '')) !== '') {
      $regEmail = strtolower(trim((string)(($user['email'] ?? '') !== '' ? $user['email'] : ($b['email'] ?? ''))));
      $verifiedEmail = otpCheckRegistration($b, $regEmail, 'customer_register',
                                            REQUIRE_EMAIL_OTP && $isNew && empty($user['email_verified']));
    }

    // Upsert — insert if new, update if exists
    $stmt = $db->prepare("
      INSERT INTO customers
        (id, name, phone, email, gender, address, city, lat, lng, auth_method, fcm_token)
      VALUES
        (:id, :name, :phone, :email, :gender, :address, :city, :lat, :lng, :auth, :fcm)
      ON DUPLICATE KEY UPDATE
        name        = IF(VALUES(name)    != '', VALUES(name),    name),
        phone       = IF(VALUES(phone)   != '', VALUES(phone),   phone),
        gender      = IF(COALESCE(gender, '') = '' AND VALUES(gender) != '', VALUES(gender), gender),
        address     = IF(VALUES(address) != '', VALUES(address), address),
        city        = IF(VALUES(city)    != '', VALUES(city),    city),
        lat         = IF(VALUES(lat)     != 0,  VALUES(lat),     lat),
        lng         = IF(VALUES(lng)     != 0,  VALUES(lng),     lng),
        fcm_token   = IF(VALUES(fcm_token) != '', VALUES(fcm_token), fcm_token),
        updated_at  = CURRENT_TIMESTAMP
    ");
    // (Each named placeholder may appear only once with native prepares —
    //  the old ':name2' style raised SQLSTATE[HY093] on every call.)
    // Gender is set once: a stored gender is never changed by the customer.
    // City: an e-mail address (browser/Android autofill) is ignored.

    $name    = $b['name']    ?? $user['name']  ?? '';
    $phone   = substr(preg_replace('/\D/', '', $b['phone'] ?? ($user['phone'] ?? '')), -10);
    $email   = $verifiedEmail !== '' ? $verifiedEmail : ($b['email'] ?? $user['email'] ?? '');
    $gender  = hsCleanGender($b['gender'] ?? '');
    $address = $b['address'] ?? '';
    $city    = hsCleanCity($b['city'] ?? '') ?? '';
    $lat     = (float)($b['lat'] ?? 0);
    $lng     = (float)($b['lng'] ?? 0);
    $auth    = $b['auth_method'] ?? 'email';
    $fcm     = $b['fcm_token']   ?? '';

    $stmt->execute([
      ':id'      => $user['uid'],
      ':name'    => $name,
      ':phone'   => $phone,
      ':email'   => $email,
      ':gender'  => $gender,
      ':address' => $address,
      ':city'    => $city,
      ':lat'     => $lat,
      ':lng'     => $lng,
      ':auth'    => $auth,
      ':fcm'     => $fcm,
    ]);

    // Return customer data
    $customer = $db->prepare("SELECT * FROM customers WHERE id = ?");
    $customer->execute([$user['uid']]);
    $row = $customer->fetch() ?: [];
    unset($row['password_hash']);
    ok($row);
  }

  // ── UPDATE PROFILE ────────────────────────────────────
  case 'update': {
    $user = requireCustomer();
    $b    = getBody();

    $fields = [];
    $params = [':id' => $user['uid']];

    $ignored = false;
    if (isset($b['city'])) {
      $c = hsCleanCity($b['city']);
      if ($c === null) { unset($b['city']); $ignored = true; }   // e-mail in City → keep the old city
      else $b['city'] = $c;
    }
    if (isset($b['gender'])) {
      $b['gender'] = hsCleanGender($b['gender']);
    }
    $allowed = ['name','phone','address','city','lat','lng'];
    foreach ($allowed as $f) {
      if (isset($b[$f]) && $b[$f] !== '') {
        $fields[] = "$f = :$f";
        $params[":$f"] = $b[$f];
      }
    }
    // Gender is set once — a stored gender is never changed by the customer
    // (silently kept, so the other fields still save).
    if (!empty($b['gender'])) {
      $fields[] = "gender = IF(COALESCE(gender, '') = '', :gender, gender)";
      $params[':gender'] = $b['gender'];
    }

    if (empty($fields) && !$ignored && !isset($b['gender'])) err('Nothing to update');
    if (empty($fields)) {
      $stmt = $db->prepare("SELECT * FROM customers WHERE id = ?");
      $stmt->execute([$user['uid']]);
      $row = $stmt->fetch() ?: [];
      unset($row['password_hash']);
      ok($row);
    }

    $db->prepare("UPDATE customers SET " . implode(', ', $fields) . " WHERE id = :id")
       ->execute($params);

    $stmt = $db->prepare("SELECT * FROM customers WHERE id = ?");
    $stmt->execute([$user['uid']]);
    $row = $stmt->fetch() ?: [];
    unset($row['password_hash']);
    ok($row);
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
  // ── CHANGE PASSWORD (web app) ──
  case 'change_password': {
    $user = requireCustomer();
    $b = getBody();
    $old = $b['old_password'] ?? '';
    $new = $b['new_password'] ?? '';
    if (strlen($new) < 8) err('New password must be at least 8 characters');
    $stmt = $db->prepare("SELECT password_hash FROM customers WHERE id = ? LIMIT 1");
    $stmt->execute([$user['uid']]);
    $row = $stmt->fetch();
    if (!$row) err('Customer not found', 404);
    if (!empty($row['password_hash']) && !password_verify($old, $row['password_hash'])) {
      err('Current password is incorrect', 401);
    }
    $db->prepare("UPDATE customers SET password_hash = ? WHERE id = ?")
       ->execute([password_hash($new, PASSWORD_BCRYPT), $user['uid']]);
    ok(['changed' => true]);
  }

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
    $act = $db->prepare("SELECT id FROM bookings WHERE customer_id = ? AND status IN ('price_quoted','negotiating','negotiation_final','confirmed','active') LIMIT 1");
    $act->execute([$user['uid']]);
    if ($act->fetch()) err('Finish or cancel your active booking first', 409);
    // Anonymise (bookings are kept for accounting; deleting would cascade them away)
    $db->prepare("UPDATE customers SET name = 'Deleted user', phone = NULL, email = NULL, address = NULL,
                  lat = NULL, lng = NULL, fcm_token = NULL WHERE id = ?")->execute([$user['uid']]);
    try { $db->prepare("UPDATE customers SET password_hash = '' WHERE id = ?")->execute([$user['uid']]); } catch (Throwable $e) {}
    $db->prepare("UPDATE bookings SET customer_name = 'Deleted user', customer_phone = NULL, contact_phone = NULL
                  WHERE customer_id = ? AND status IN ('completed','cancelled')")->execute([$user['uid']]);
    $db->prepare("UPDATE bookings SET status = 'cancelled', cancelled_by = 'customer', cancel_reason = 'Account deleted'
                  WHERE customer_id = ? AND status = 'searching'")->execute([$user['uid']]);
    ok(['deleted' => true]);
  }

  // ── ADMIN: LIST ALL CUSTOMERS ───────────────────────────
  case 'list': {
    requireAdmin();
    $stmt = $db->query("
      SELECT id, name, phone, email, city, auth_method, created_at
      FROM customers
      ORDER BY created_at DESC
      LIMIT 500
    ");
    ok($stmt->fetchAll());
  }

  default:
    err('Invalid action');
}
