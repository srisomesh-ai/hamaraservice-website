<?php
// ═══════════════════════════════════════════════════════
// HamaraService — Firebase → MySQL Migration Tool
// Run once: hamaraservice.com/api/migrate.php?key=hamaraAdmin2024
// Reads providers and customers from Firebase REST API
// and inserts them into MySQL
// ═══════════════════════════════════════════════════════
require_once __DIR__ . '/db.php';
setCorsHeaders();

$key = $_GET['key'] ?? '';
if ($key !== 'hamaraAdmin2024') err('Unauthorized', 401);

$db = getDB();

// ── Firebase REST config ──────────────────────────────
$FB_URL = 'https://hamaraservice-s009-default-rtdb.asia-southeast1.firebasedatabase.app';

function fbGet($path) {
    global $FB_URL;
    $url  = "$FB_URL/$path.json";
    $ctx  = stream_context_create(['http' => ['timeout' => 15]]);
    $resp = @file_get_contents($url, false, $ctx);
    if (!$resp) return null;
    return json_decode($resp, true);
}

$results = [];
$errors  = [];

// ── 1. Migrate Providers ─────────────────────────────
$provData = fbGet('providers');
$provCount = 0;
if ($provData && is_array($provData)) {
    $stmt = $db->prepare("
        INSERT INTO providers
          (id, name, phone, email, whatsapp, password_hash,
           gender, experience, bio, id_type, id_number,
           address, city, lat, lng, radius_km,
           status, available, rating, review_count,
           total_bookings, completed_bookings, total_earned, fcm_token,
           registered_at)
        VALUES
          (:id, :name, :phone, :email, :whatsapp, :pwd,
           :gender, :exp, :bio, :idtype, :idnum,
           :address, :city, :lat, :lng, :radius,
           :status, :available, :rating, :reviews,
           :total_bk, :comp_bk, :earned, :fcm,
           :reg_at)
        ON DUPLICATE KEY UPDATE
          name      = VALUES(name),
          status    = VALUES(status),
          available = VALUES(available),
          rating    = VALUES(rating),
          fcm_token = VALUES(fcm_token),
          city      = VALUES(city),
          lat       = VALUES(lat),
          lng       = VALUES(lng)
    ");

    foreach ($provData as $id => $p) {
        if (!is_array($p)) continue;
        $name = $p['name'] ?? '';
        if (empty($name)) continue;

        // Extract city from address
        $address = $p['address'] ?? '';
        $city    = $p['city']    ?? '';
        if (empty($city) && strpos($address, ',') !== false) {
            $parts = explode(',', $address);
            $city  = trim(end($parts));
        }

        // Use Firebase ID as provider ID
        $providerId = $p['id'] ?? $id;

        try {
            $stmt->execute([
                ':id'        => $providerId,
                ':name'      => $name,
                ':phone'     => $p['phone']      ?? '',
                ':email'     => strtolower($p['email'] ?? ''),
                ':whatsapp'  => $p['whatsapp']   ?? $p['phone'] ?? '',
                ':pwd'       => $p['password']   ?? password_hash('changeme123', PASSWORD_BCRYPT),
                ':gender'    => $p['gender']     ?? '',
                ':exp'       => $p['experience'] ?? '',
                ':bio'       => $p['bio']        ?? '',
                ':idtype'    => $p['idType']     ?? '',
                ':idnum'     => $p['idNum']      ?? '',
                ':address'   => $address,
                ':city'      => $city,
                ':lat'       => (float)($p['lat'] ?? 0),
                ':lng'       => (float)($p['lng'] ?? 0),
                ':radius'    => (int)($p['radius'] ?? $p['radius_km'] ?? 5),
                ':status'    => $p['status']     ?? 'pending',
                ':available' => (int)($p['available'] ?? 0),
                ':rating'    => (float)($p['rating']  ?? 0),
                ':reviews'   => (int)($p['reviewCount'] ?? 0),
                ':total_bk'  => (int)($p['totalBookings'] ?? 0),
                ':comp_bk'   => (int)($p['completedBookings'] ?? 0),
                ':earned'    => (int)($p['totalEarned'] ?? 0),
                ':fcm'       => $p['fcmToken']   ?? '',
                ':reg_at'    => $p['registeredAt'] ?? $p['createdAt'] ?? date('Y-m-d H:i:s'),
            ]);

            // Migrate provider services
            $services = $p['services'] ?? [];
            if (!empty($services)) {
                $svcStmt = $db->prepare("
                    INSERT INTO provider_services
                      (provider_id, svc_id, svc_name, svc_icon, svc_cat, enabled, min_price, max_price)
                    VALUES (?, ?, ?, ?, ?, 1, ?, ?)
                    ON DUPLICATE KEY UPDATE
                      enabled   = VALUES(enabled),
                      min_price = VALUES(min_price),
                      max_price = VALUES(max_price)
                ");

                if (is_array($services)) {
                    foreach ($services as $svcKey => $svcVal) {
                        if (is_bool($svcVal) || $svcVal === true) {
                            // Old format: {SVC001: true}
                            $svcStmt->execute([$providerId, $svcKey, $svcKey, '🔧', 'Service', 0, 0]);
                        } elseif (is_array($svcVal)) {
                            // New format: {SVC001: {enabled:true, min:100, max:300}}
                            $svcId   = $svcVal['id']  ?? $svcKey;
                            $enabled = (int)($svcVal['enabled'] ?? 1);
                            $min     = (int)($svcVal['min'] ?? $svcVal['min_price'] ?? 0);
                            $max     = (int)($svcVal['max'] ?? $svcVal['max_price'] ?? 0);
                            $svcName = $svcVal['name'] ?? $svcId;
                            $svcIcon = $svcVal['icon'] ?? '🔧';
                            $svcCat  = $svcVal['cat']  ?? 'Service';
                            $svcStmt->execute([$providerId, $svcId, $svcName, $svcIcon, $svcCat, $min, $max]);
                        } elseif (is_array($svcKey) || (is_string($svcKey) && strlen($svcKey) > 3)) {
                            // Array of service objects
                            $svcStmt->execute([$providerId, $svcVal['id'] ?? '', $svcVal['name'] ?? '', $svcVal['icon'] ?? '🔧', $svcVal['cat'] ?? 'Service', 0, 0]);
                        }
                    }
                }
            }

            $provCount++;
        } catch (Exception $e) {
            $errors[] = "Provider $providerId: " . $e->getMessage();
        }
    }
}
$results[] = "✅ Providers migrated: $provCount";

// ── 2. Migrate Customers ─────────────────────────────
$custData  = fbGet('customers');
$custCount = 0;
if ($custData && is_array($custData)) {
    $stmt = $db->prepare("
        INSERT INTO customers (id, name, phone, email, gender, address, city, lat, lng, fcm_token, auth_method, created_at)
        VALUES (:id, :name, :phone, :email, :gender, :address, :city, :lat, :lng, :fcm, :auth, :created)
        ON DUPLICATE KEY UPDATE
          name      = VALUES(name),
          phone     = VALUES(phone),
          fcm_token = VALUES(fcm_token),
          city      = VALUES(city)
    ");

    foreach ($custData as $uid => $c) {
        if (!is_array($c)) continue;
        $custId = $c['uid'] ?? $c['id'] ?? $uid;
        if (empty($custId)) continue;

        $address = $c['address'] ?? '';
        $city    = $c['city']    ?? '';
        if (empty($city) && strpos($address, ',') !== false) {
            $parts = explode(',', $address);
            $city  = trim(end($parts));
        }

        try {
            $stmt->execute([
                ':id'      => $custId,
                ':name'    => $c['name']    ?? $c['displayName'] ?? '',
                ':phone'   => $c['phone']   ?? $c['mobile']      ?? '',
                ':email'   => $c['email']   ?? '',
                ':gender'  => $c['gender']  ?? '',
                ':address' => $address,
                ':city'    => $city,
                ':lat'     => (float)($c['lat'] ?? 0),
                ':lng'     => (float)($c['lng'] ?? 0),
                ':fcm'     => $c['fcmToken'] ?? '',
                ':auth'    => $c['authMethod'] ?? 'email',
                ':created' => $c['createdAt']  ?? date('Y-m-d H:i:s'),
            ]);
            $custCount++;
        } catch (Exception $e) {
            $errors[] = "Customer $custId: " . $e->getMessage();
        }
    }
}
$results[] = "✅ Customers migrated: $custCount";

// ── 3. Migrate Bookings ──────────────────────────────
$bkData  = fbGet('bookings');
$bkCount = 0;
if ($bkData && is_array($bkData)) {
    $stmt = $db->prepare("
        INSERT INTO bookings
          (id, customer_id, customer_name, customer_phone,
           provider_id, provider_name, svc_id, svc_name,
           address, city, lat, lng, status,
           confirmed_price, amount, payment_status,
           otp, otp_verified, created_at, completed_at)
        VALUES
          (:id, :cid, :cname, :cphone,
           :pid, :pname, :svc_id, :svc_name,
           :address, :city, :lat, :lng, :status,
           :price, :amount, :pay_status,
           :otp, :otp_v, :created, :completed)
        ON DUPLICATE KEY UPDATE
          status         = VALUES(status),
          payment_status = VALUES(payment_status),
          completed_at   = VALUES(completed_at)
    ");

    foreach ($bkData as $bkId => $b) {
        if (!is_array($b)) continue;
        $id = $b['id'] ?? $bkId;

        try {
            $stmt->execute([
                ':id'        => $id,
                ':cid'       => $b['customerId']   ?? $b['uid']         ?? '',
                ':cname'     => $b['customer']      ?? $b['customerName'] ?? '',
                ':cphone'    => $b['customerPhone'] ?? '',
                ':pid'       => $b['providerId']    ?? $b['acceptedBy']['id'] ?? '',
                ':pname'     => $b['providerName']  ?? $b['acceptedBy']['name'] ?? '',
                ':svc_id'    => $b['svcId']         ?? $b['serviceId']   ?? '',
                ':svc_name'  => $b['service']       ?? $b['svcName']     ?? '',
                ':address'   => $b['address']       ?? '',
                ':city'      => $b['city']          ?? '',
                ':lat'       => (float)($b['lat']   ?? 0),
                ':lng'       => (float)($b['lng']   ?? 0),
                ':status'    => $b['status']        ?? 'active',
                ':price'     => (int)($b['confirmedPrice'] ?? $b['priceVal'] ?? $b['amountPaid'] ?? 0),
                ':amount'    => (int)($b['amountPaid']     ?? $b['priceVal'] ?? 0),
                ':pay_status'=> $b['paymentStatus'] ?? 'pending',
                ':otp'       => $b['otp']           ?? '',
                ':otp_v'     => (int)($b['otpVerified'] ?? 0),
                ':created'   => $b['createdAt']     ?? $b['date'] ?? date('Y-m-d H:i:s'),
                ':completed' => $b['completedAt']   ?? null,
            ]);
            $bkCount++;
        } catch (Exception $e) {
            $errors[] = "Booking $id: " . $e->getMessage();
        }
    }
}
$results[] = "✅ Bookings migrated: $bkCount";

// ── Summary ──────────────────────────────────────────
ok([
    'message' => 'Migration complete',
    'results' => $results,
    'errors'  => array_slice($errors, 0, 20), // first 20 errors
    'total_errors' => count($errors),
]);
