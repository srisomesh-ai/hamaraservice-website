<?php
// ═══════════════════════════════════════════════════════
// HamaraService — Bookings API
// Endpoints:
//   POST ?action=create          — customer creates booking
//   POST ?action=accept          — provider accepts + quotes price
//   POST ?action=negotiate       — customer sends counter price
//   POST ?action=final_offer     — provider sends final price
//   POST ?action=accept_counter  — provider accepts customer counter
//   POST ?action=confirm_price   — customer confirms price
//   POST ?action=search_another  — customer releases provider
//   POST ?action=verify_otp      — provider verifies OTP
//   POST ?action=complete        — mark booking completed + calc commission
//   GET  ?action=get&id=X        — get booking details
//   GET  ?action=active&id=X     — get active booking for customer/provider
//   GET  ?action=history&id=X&role=customer/provider — booking history
//   POST ?action=cancel          — cancel booking
//   GET  ?action=admin_list      — admin: all bookings
// ═══════════════════════════════════════════════════════
require_once __DIR__ . '/db.php';
setCorsHeaders();

$action = $_GET['action'] ?? '';
$db     = getDB();

// ── Helper: send FCM via Cloud Function ───────────────
function sendPush($fcmToken, $title, $body, $data = []) {
    if (empty($fcmToken)) return;
    $payload = json_encode([
        'event'    => $data['event'] ?? 'notification',
        'fcmToken' => $fcmToken,
        'data'     => array_merge(['title'=>$title,'body'=>$body], $data),
    ]);
    $ctx = stream_context_create(['http'=>[
        'method'  => 'POST',
        'header'  => "Content-Type: application/json
",
        'content' => $payload,
        'timeout' => 5,
    ]]);
    @file_get_contents('https://notifybooking-mlchyp6tra-as.a.run.app', false, $ctx);
}

// ── Helper: get FCM token ─────────────────────────────
function getFcm($db, $table, $id) {
    $stmt = $db->prepare("SELECT fcm_token FROM $table WHERE id = ?");
    $stmt->execute([$id]);
    return $stmt->fetchColumn() ?: '';
}

// ── Helper: generate booking ID ───────────────────────
function makeBookingId() {
    return 'BK-' . strtoupper(substr(uniqid(), -6)) . rand(10,99);
}

// ── Helper: generate OTP ──────────────────────────────
function makeOtp() {
    return str_pad(rand(1000,9999), 4, '0', STR_PAD_LEFT);
}

// ── auto-migrate: completion otp + reviews ──
try { $db->exec("ALTER TABLE bookings ADD COLUMN completion_otp VARCHAR(8) NULL"); } catch (Exception $e) {}
try { $db->exec("ALTER TABLE bookings ADD COLUMN rating INT NULL"); } catch (Exception $e) {}
try { $db->exec("ALTER TABLE bookings ADD COLUMN review TEXT NULL"); } catch (Exception $e) {}

// ── auto-migrate: full flow fields ──
try { $db->exec("ALTER TABLE bookings ADD COLUMN start_otp VARCHAR(8) NULL"); } catch (Exception $e) {}
try { $db->exec("ALTER TABLE bookings ADD COLUMN started_at DATETIME NULL"); } catch (Exception $e) {}
try { $db->exec("ALTER TABLE bookings ADD COLUMN min_duration_min INT DEFAULT 5"); } catch (Exception $e) {}
try { $db->exec("ALTER TABLE bookings ADD COLUMN review_requested TINYINT DEFAULT 0"); } catch (Exception $e) {}

switch ($action) {

  // ── CREATE BOOKING ────────────────────────────────────
  case 'create': {
    $user = requireCustomer();
    $b    = getBody();

    $required = ['svc_id','svc_name','address','city'];
    foreach ($required as $f) {
      if (empty($b[$f])) err("$f is required");
    }

    // Check no active booking already
    $active = $db->prepare("
      SELECT id FROM bookings
      WHERE customer_id = ? AND status NOT IN ('completed','cancelled')
      LIMIT 1
    ");
    $active->execute([$user['uid']]);
    if ($active->fetch()) err('You already have an active booking');

    $id = makeBookingId();

    // Get customer name/phone
    $cust = $db->prepare("SELECT name, phone FROM customers WHERE id = ?");
    $cust->execute([$user['uid']]);
    $custData = $cust->fetch() ?? [];

    $stmt = $db->prepare("
      INSERT INTO bookings
        (id, customer_id, customer_name, customer_phone,
         svc_id, svc_name, svc_icon,
         address, city, lat, lng,
         slot_date, slot_time, notes, status)
      VALUES
        (:id, :cid, :cname, :cphone,
         :svc_id, :svc_name, :svc_icon,
         :address, :city, :lat, :lng,
         :date, :time, :notes, 'searching')
    ");
    $stmt->execute([
      ':id'       => $id,
      ':cid'      => $user['uid'],
      ':cname'    => $custData['name']  ?? '',
      ':cphone'   => $custData['phone'] ?? '',
      ':svc_id'   => $b['svc_id'],
      ':svc_name' => $b['svc_name'],
      ':svc_icon' => $b['svc_icon'] ?? '',
      ':address'  => $b['address'],
      ':city'     => $b['city'],
      ':lat'      => (float)($b['lat'] ?? 0),
      ':lng'      => (float)($b['lng'] ?? 0),
      ':date'     => $b['slot_date'] ?? null,
      ':time'     => $b['slot_time'] ?? '',
      ':notes'    => $b['notes'] ?? '',
    ]);

    // ── Notify nearby available providers (push so they see it even when app closed) ──
    try {
      $blat = (float)($b['lat'] ?? 0);
      $blng = (float)($b['lng'] ?? 0);
      $city = $b['city'] ?? '';
      $svcId = $b['svc_id'] ?? '';
      // approved + available providers, matched by radius (if GPS) or city/area
      $pstmt = $db->prepare("
        SELECT p.id, p.fcm_token, p.lat, p.lng, p.radius_km, p.city, p.areas, p.services,
          CASE WHEN p.lat IS NOT NULL AND p.lat != 0 AND :blat != 0
            THEN 6371 * ACOS(LEAST(1, COS(RADIANS(:blat2)) * COS(RADIANS(p.lat)) *
                 COS(RADIANS(p.lng) - RADIANS(:blng)) +
                 SIN(RADIANS(:blat3)) * SIN(RADIANS(p.lat))))
            ELSE NULL END AS dist
        FROM providers p
        WHERE p.status = 'approved' AND p.available = 1 AND p.fcm_token IS NOT NULL AND p.fcm_token != ''
      ");
      $pstmt->execute([':blat'=>$blat, ':blat2'=>$blat, ':blat3'=>$blat, ':blng'=>$blng]);
      $provs = $pstmt->fetchAll();
      $svcName = $b['svc_name'] ?? 'a service';
      foreach ($provs as $pr) {
        // service filter: if provider has a services list, require this svc
        if (!empty($pr['services'])) {
          $svcList = json_decode($pr['services'], true);
          if (is_array($svcList) && !empty($svcList)) {
            $has = false;
            foreach ($svcList as $s) {
              $sid = is_array($s) ? ($s['svc_id'] ?? $s['id'] ?? '') : $s;
              if ($sid === $svcId) { $has = true; break; }
            }
            if (!$has) continue;
          }
        }
        // location filter: within radius OR city/area match OR no GPS on either side
        $ok = false;
        if ($pr['dist'] !== null) { $ok = ($pr['dist'] <= (float)($pr['radius_km'] ?: 10)); }
        else {
          $hay = strtolower(($pr['city'] ?? '') . ' ' . ($pr['areas'] ?? ''));
          $ok = ($city === '' || strpos($hay, strtolower($city)) !== false);
        }
        if (!$ok) continue;
        sendPush($pr['fcm_token'], "New Job Nearby 🔔",
          "$svcName request near you. Open to quote your price.",
          ['event'=>'new_job','bookingId'=>$id,'role'=>'provider']);
      }
    } catch (Exception $e) { /* never block booking on push failure */ }

    ok(['id' => $id, 'status' => 'searching']);
  }

  // ── PROVIDER ACCEPTS + QUOTES PRICE ──────────────────
  case 'accept': {
    $prov = requireProvider();
    $b    = getBody();
    $id   = $b['booking_id'] ?? '';
    $price = (int)($b['quoted_price'] ?? 0);
    if (empty($id)) err('booking_id required');
    if ($price <= 0) err('quoted_price must be > 0');

    // Check booking is still available
    $stmt = $db->prepare("SELECT * FROM bookings WHERE id = ? AND status IN ('searching','active')");
    $stmt->execute([$id]);
    $bk = $stmt->fetch();
    if (!$bk) err('Booking not available');

    // Get provider name
    $pStmt = $db->prepare("SELECT name FROM providers WHERE id = ?");
    $pStmt->execute([$prov['id']]);
    $pName = $pStmt->fetchColumn();

    $db->prepare("
      UPDATE bookings SET
        provider_id         = ?,
        provider_name       = ?,
        status              = 'price_quoted',
        quoted_price        = ?,
        negotiation_status  = 'quoted',
        accepted_at         = CURRENT_TIMESTAMP
      WHERE id = ?
    ")->execute([$prov['id'], $pName, $price, $id]);

    // Notify customer
    $fcm = getFcm($db, 'customers', $bk['customer_id']);
    sendPush($fcm,
      "Provider Quoted ₹$price 💰",
      "$pName quoted ₹$price for your booking. Tap to view.",
      ['event'=>'price_quoted','quotedPrice'=>"$price",'bookingId'=>$id]
    );

    ok(['status'=>'price_quoted','quoted_price'=>$price]);
  }

  // ── CUSTOMER NEGOTIATES ───────────────────────────────
  case 'negotiate': {
    $user  = requireCustomer();
    $b     = getBody();
    $id    = $b['booking_id'] ?? '';
    $counter = (int)($b['counter_price'] ?? 0);
    if (empty($id)) err('booking_id required');

    $stmt = $db->prepare("SELECT * FROM bookings WHERE id = ? AND customer_id = ?");
    $stmt->execute([$id, $user['uid']]);
    $bk = $stmt->fetch();
    if (!$bk) err('Booking not found');

    $db->prepare("
      UPDATE bookings SET
        status             = 'negotiating',
        counter_price      = ?,
        negotiation_status = 'customer_countered'
      WHERE id = ?
    ")->execute([$counter, $id]);

    // Notify provider
    $fcm = getFcm($db, 'providers', $bk['provider_id']);
    $msg = $counter > 0
      ? "Customer countered with ₹$counter. Respond now."
      : "Customer wants to negotiate the price.";
    sendPush($fcm,
      "Price Negotiation 💬",
      $msg,
      ['event'=>'price_negotiation','counterPrice'=>"$counter",'bookingId'=>$id]
    );

    ok(['status'=>'negotiating','counter_price'=>$counter]);
  }

  // ── PROVIDER SENDS FINAL OFFER ────────────────────────
  case 'final_offer': {
    $prov  = requireProvider();
    $b     = getBody();
    $id    = $b['booking_id'] ?? '';
    $price = (int)($b['final_price'] ?? 0);
    if (empty($id) || $price <= 0) err('booking_id and final_price required');

    $stmt = $db->prepare("SELECT * FROM bookings WHERE id = ? AND provider_id = ?");
    $stmt->execute([$id, $prov['id']]);
    $bk = $stmt->fetch();
    if (!$bk) err('Booking not found');

    $db->prepare("
      UPDATE bookings SET
        status             = 'negotiation_final',
        final_price        = ?,
        negotiation_status = 'provider_final'
      WHERE id = ?
    ")->execute([$price, $id]);

    // Notify customer
    $fcm = getFcm($db, 'customers', $bk['customer_id']);
    sendPush($fcm,
      "Final Price Offer 💰",
      "Provider's final price: ₹$price. Accept or search another.",
      ['event'=>'negotiation_final','finalPrice'=>"$price",'bookingId'=>$id]
    );

    ok(['status'=>'negotiation_final','final_price'=>$price]);
  }

  // ── PROVIDER ACCEPTS CUSTOMER COUNTER ────────────────
  case 'accept_counter': {
    $prov  = requireProvider();
    $b     = getBody();
    $id    = $b['booking_id'] ?? '';
    if (empty($id)) err('booking_id required');

    $stmt = $db->prepare("SELECT * FROM bookings WHERE id = ? AND provider_id = ?");
    $stmt->execute([$id, $prov['id']]);
    $bk = $stmt->fetch();
    if (!$bk) err('Booking not found');

    $confirmed = (int)($bk['counter_price'] ?: $bk['quoted_price']);
    $otp = str_pad((string)rand(0, 9999), 4, '0', STR_PAD_LEFT);

    $db->prepare("
      UPDATE bookings SET
        status             = 'confirmed',
        confirmed_price    = ?,
        otp                = ?,
        negotiation_status = 'confirmed'
      WHERE id = ?
    ")->execute([$confirmed, $otp, $id]);

    $fcm = getFcm($db, 'customers', $bk['customer_id']);
    sendPush($fcm,
      "Price Confirmed ✅",
      "Provider accepted ₹$confirmed. Booking confirmed!",
      ['event'=>'price_confirmed','confirmedPrice'=>"$confirmed",'bookingId'=>$id]
    );

    ok(['status'=>'confirmed','confirmed_price'=>$confirmed,'otp'=>$otp]);
  }

  // ── CUSTOMER CONFIRMS PRICE ───────────────────────────
  case 'confirm_price': {
    $user  = requireCustomer();
    $b     = getBody();
    $id    = $b['booking_id'] ?? '';
    $price = (int)($b['confirmed_price'] ?? 0);
    if (empty($id) || $price <= 0) err('booking_id and confirmed_price required');

    $stmt = $db->prepare("SELECT * FROM bookings WHERE id = ? AND customer_id = ?");
    $stmt->execute([$id, $user['uid']]);
    $bk = $stmt->fetch();
    if (!$bk) err('Booking not found');

    $db->prepare("
      UPDATE bookings SET
        status             = 'confirmed',
        confirmed_price    = ?,
        amount             = ?,
        negotiation_status = 'confirmed'
      WHERE id = ?
    ")->execute([$price, $price, $id]);

    // Notify provider — customer confirmed the job
    $fcm = getFcm($db, 'providers', $bk['provider_id']);
    sendPush($fcm,
      "Job Confirmed ✅",
      "Customer confirmed ₹$price. Head to the location.",
      ['event'=>'booking_confirmed','confirmedPrice'=>"$price",'bookingId'=>$id]
    );

    ok(['status'=>'confirmed','confirmed_price'=>$price,'otp'=>$otp]);
  }

  // ── CUSTOMER SEARCHES ANOTHER PROVIDER ───────────────
  case 'search_another': {
    $user = requireCustomer();
    $b    = getBody();
    $id   = $b['booking_id'] ?? '';
    if (empty($id)) err('booking_id required');

    $stmt = $db->prepare("SELECT * FROM bookings WHERE id = ? AND customer_id = ?");
    $stmt->execute([$id, $user['uid']]);
    $bk = $stmt->fetch();
    if (!$bk) err('Booking not found');

    // Notify old provider
    if (!empty($bk['provider_id'])) {
      $fcm = getFcm($db, 'providers', $bk['provider_id']);
      sendPush($fcm,
        "Booking Released",
        "Customer searched for another provider.",
        ['event'=>'booking_cancelled','bookingId'=>$id]
      );
    }

    // Reset booking to searching — new provider can pick it up
    $db->prepare("
      UPDATE bookings SET
        status             = 'searching',
        provider_id        = NULL,
        provider_name      = NULL,
        quoted_price       = 0,
        counter_price      = 0,
        final_price        = 0,
        negotiation_status = NULL,
        accepted_at        = NULL
      WHERE id = ?
    ")->execute([$id]);

    ok(['status'=>'active','message'=>'Searching for another provider']);
  }

  // ── PROVIDER VERIFIES OTP ────────────────────────────
  case 'verify_otp': {
    $prov = requireProvider();
    $b    = getBody();
    $id   = $b['booking_id'] ?? '';
    $otp  = $b['otp'] ?? '';
    if (empty($id) || empty($otp)) err('booking_id and otp required');

    $stmt = $db->prepare("SELECT * FROM bookings WHERE id = ? AND provider_id = ?");
    $stmt->execute([$id, $prov['id']]);
    $bk = $stmt->fetch();
    if (!$bk) err('Booking not found');
    if ($bk['otp'] !== $otp) err('Invalid OTP');

    $db->prepare("
      UPDATE bookings SET
        otp_verified = 1,
        status       = 'active',
        started_at   = CURRENT_TIMESTAMP
      WHERE id = ?
    ")->execute([$id]);

    // Notify customer
    $fcm = getFcm($db, 'customers', $bk['customer_id']);
    sendPush($fcm,
      "Service Started ✅",
      "OTP verified. Your service has started.",
      ['event'=>'otp_verified','bookingId'=>$id,'amount'=>(string)$bk['confirmed_price']]
    );

    ok(['verified'=>true,'amount'=>(int)$bk['confirmed_price']]);
  }

  // ── COMPLETE BOOKING ──────────────────────────────────
  case 'complete': {
    $b  = getBody();
    $id = $b['booking_id'] ?? '';
    if (empty($id)) err('booking_id required');

    $stmt = $db->prepare("SELECT * FROM bookings WHERE id = ?");
    $stmt->execute([$id]);
    $bk = $stmt->fetch();
    if (!$bk) err('Booking not found');

    // Completion OTP gate — customer shares it only when satisfied with the work
    if (!empty($bk['completion_otp'])) {
      $cotp = trim($b['completion_otp'] ?? '');
      if (empty($cotp)) err('COMPLETION_OTP_REQUIRED');
      if ($cotp !== $bk['completion_otp']) err('Invalid completion OTP');
    }

    $amount = (int)($bk['confirmed_price'] ?: $bk['amount'] ?: 0);
    $commPct = (int)($bk['commission_pct'] ?? 15);
    $commAmt = (int)round($amount * $commPct / 100);
    $provEarns = $amount - $commAmt;

    $db->prepare("
      UPDATE bookings SET
        status         = 'completed',
        payment_status = 'pending',
        commission_pct = ?,
        commission_amt = ?,
        provider_earns = ?,
        completed_at   = CURRENT_TIMESTAMP
      WHERE id = ?
    ")->execute([$commPct, $commAmt, $provEarns, $id]);

    // Notify customer — job done, please pay
    $cfcm = getFcm($db, 'customers', $bk['customer_id']);
    sendPush($cfcm, "Service Completed 🎉", "Please pay ₹$amount to your provider.", ['event'=>'completed','bookingId'=>$id,'amount'=>(string)$amount]);

    // Update provider earnings
    if (!empty($bk['provider_id'])) {
      $db->prepare("
        UPDATE providers SET
          completed_bookings = completed_bookings + 1,
          total_bookings     = total_bookings + 1,
          total_earned       = total_earned + ?,
          pending_earned     = pending_earned + ?
        WHERE id = ?
      ")->execute([$provEarns, $provEarns, $bk['provider_id']]);

      // Notify provider
      $fcm = getFcm($db, 'providers', $bk['provider_id']);
      sendPush($fcm,
        "Payment Received 💰",
        "₹$provEarns earned for {$bk['svc_name']}. Great work!",
        ['event'=>'payment_received','amount'=>"$provEarns",'bookingId'=>$id]
      );
    }

    ok(['status'=>'completed','amount'=>$amount,'provider_earns'=>$provEarns]);
  }

  // ── GET BOOKING ───────────────────────────────────────
  case 'get': {
    $id = $_GET['id'] ?? '';
    if (empty($id)) err('id required');

    $stmt = $db->prepare("
      SELECT b.*,
             p.upi_id AS provider_upi, p.rating AS provider_rating,
             p.photo AS provider_photo, p.review_count AS provider_reviews,
             p.lat AS provider_lat, p.lng AS provider_lng,
             CASE
               WHEN p.lat IS NOT NULL AND p.lat != 0 AND b.lat IS NOT NULL AND b.lat != 0
               THEN ROUND(6371 * ACOS(
                    LEAST(1, COS(RADIANS(b.lat)) * COS(RADIANS(p.lat)) *
                    COS(RADIANS(p.lng) - RADIANS(b.lng)) +
                    SIN(RADIANS(b.lat)) * SIN(RADIANS(p.lat)))), 1)
               ELSE NULL
             END AS distance_km
      FROM bookings b
      LEFT JOIN providers p ON p.id = b.provider_id
      WHERE b.id = ?
    ");
    $stmt->execute([$id]);
    $bk = $stmt->fetch();
    if (!$bk) err('Booking not found', 404);
    ok($bk);
  }

  // ── ACTIVE BOOKING ────────────────────────────────────
  case 'active': {
    $id   = $_GET['id']   ?? '';
    $role = $_GET['role'] ?? 'customer';
    if (empty($id)) err('id required');

    $col  = $role === 'provider' ? 'provider_id' : 'customer_id';
    // include recently-completed-but-unpaid so provider still sees payment status
    $stmt = $db->prepare("
      SELECT * FROM bookings
      WHERE $col = ?
        AND (status NOT IN ('completed','cancelled')
             OR (status = 'completed' AND payment_status != 'paid'
                 AND completed_at > (NOW() - INTERVAL 2 HOUR)))
      ORDER BY (status='completed') ASC, created_at DESC
      LIMIT 1
    ");
    $stmt->execute([$id]);
    $bk = $stmt->fetch();
    ok($bk ?: null);
  }

  // ── BOOKING HISTORY ───────────────────────────────────
  case 'history': {
    $id   = $_GET['id']   ?? '';
    $role = $_GET['role'] ?? 'customer';
    $col  = $role === 'provider' ? 'provider_id' : 'customer_id';
    if (empty($id)) err('id required');

    $stmt = $db->prepare("
      SELECT id, svc_id, svc_name, svc_icon,
             " . ($role==='provider' ? 'customer_name' : 'provider_name') . " as other_party,
             status, confirmed_price, amount, provider_earns,
             payment_status, slot_date, created_at, completed_at
      FROM bookings
      WHERE $col = ?
      ORDER BY created_at DESC
      LIMIT 100
    ");
    $stmt->execute([$id]);
    ok($stmt->fetchAll());
  }

  // ── CANCEL ────────────────────────────────────────────
  case 'cancel': {
    $b  = getBody();
    $id = $b['booking_id'] ?? '';
    if (empty($id)) err('booking_id required');

    $stmt = $db->prepare("SELECT * FROM bookings WHERE id = ?");
    $stmt->execute([$id]);
    $bk = $stmt->fetch();
    if (!$bk) err('Booking not found');

    $db->prepare("UPDATE bookings SET status = 'cancelled' WHERE id = ?")
       ->execute([$id]);

    // Notify the other party
    if (!empty($bk['provider_id'])) {
      $fcm = getFcm($db, 'providers', $bk['provider_id']);
      sendPush($fcm, "Booking Cancelled", "A booking was cancelled.",
        ['event'=>'booking_cancelled','bookingId'=>$id]);
    }

    ok(['cancelled'=>true]);
  }

  // ── ADMIN: LIST ALL ───────────────────────────────────
  // ── OPEN BOOKINGS (provider polling) ─────────────────
  case 'open': {
    requireProvider();
    $city  = $_GET['city'] ?? '';
    $limit = (int)($_GET['limit'] ?? 20);

    $sql    = "SELECT * FROM bookings WHERE status = 'searching'";
    $params = [];
    if (!empty($city)) { $sql .= " AND city LIKE ?"; $params[] = "%$city%"; }
    $sql .= " ORDER BY created_at DESC LIMIT $limit";

    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    ok($stmt->fetchAll());
  }

  case 'admin_list': {
    requireAdmin();
    $status = $_GET['status'] ?? '';
    $city   = $_GET['city']   ?? '';
    $limit  = (int)($_GET['limit'] ?? 50);

    $sql    = "SELECT * FROM bookings WHERE 1=1";
    $params = [];
    if (!empty($status)) { $sql .= " AND status = ?";    $params[] = $status; }
    if (!empty($city))   { $sql .= " AND city LIKE ?";   $params[] = "%$city%"; }
    $sql .= " ORDER BY created_at DESC LIMIT $limit";

    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    ok($stmt->fetchAll());
  }

  case 'review': {
    $cust = requireCustomer();
    $b    = getBody();
    $id   = $b['booking_id'] ?? '';
    $rating = (int)($b['rating'] ?? 0);
    $review = trim($b['review'] ?? '');
    if (empty($id)) err('booking_id required');
    if ($rating < 1 || $rating > 5) err('rating 1-5 required');

    $stmt = $db->prepare("SELECT * FROM bookings WHERE id = ? AND customer_id = ?");
    $stmt->execute([$id, $cust['id']]);
    $bk = $stmt->fetch();
    if (!$bk) err('Booking not found');
    if ($bk['status'] !== 'completed') err('Booking not completed yet');
    if (!empty($bk['rating'])) err('Already reviewed');

    $db->prepare("UPDATE bookings SET rating = ?, review = ? WHERE id = ?")
       ->execute([$rating, $review, $id]);

    // Recompute provider rating
    if (!empty($bk['provider_id'])) {
      $agg = $db->prepare("SELECT AVG(rating) a, COUNT(rating) c FROM bookings WHERE provider_id = ? AND rating IS NOT NULL");
      $agg->execute([$bk['provider_id']]);
      $r = $agg->fetch();
      $db->prepare("UPDATE providers SET rating = ?, review_count = ? WHERE id = ?")
         ->execute([round((float)$r['a'], 1), (int)$r['c'], $bk['provider_id']]);
      $fcm = getFcm($db, 'providers', $bk['provider_id']);
      sendPush($fcm, "New Review ⭐", "$rating stars from a customer", ['event'=>'review','bookingId'=>$id]);
    }
    ok(['rating'=>$rating]);
  }

  case 'decline': {
    $user = requireCustomer();
    $bd   = getBody();
    $id   = $bd['booking_id'] ?? '';
    if (empty($id)) err('booking_id required');
    $stmt = $db->prepare("SELECT * FROM bookings WHERE id = ? AND customer_id = ?");
    $stmt->execute([$id, $user['uid']]);
    $bk = $stmt->fetch();
    if (!$bk) err('Booking not found');
    $db->prepare("UPDATE bookings SET status = 'searching', provider_id = NULL, provider_name = NULL, quoted_price = 0, negotiation_status = NULL WHERE id = ?")->execute([$id]);
    if (!empty($bk['provider_id'])) {
      $fcm = getFcm($db, 'providers', $bk['provider_id']);
      sendPush($fcm, "Not Interested", "Customer declined your quote. Searching others.", ['event'=>'declined','bookingId'=>$id]);
    }
    ok(['status'=>'searching']);
  }

  case 'generate_start_otp': {
    $user = requireCustomer();
    $bd   = getBody();
    $id   = $bd['booking_id'] ?? '';
    if (empty($id)) err('booking_id required');
    $stmt = $db->prepare("SELECT * FROM bookings WHERE id = ? AND customer_id = ?");
    $stmt->execute([$id, $user['uid']]);
    $bk = $stmt->fetch();
    if (!$bk) err('Booking not found');
    if ($bk['status'] !== 'confirmed') err('Booking must be confirmed first');
    $otp = $bk['start_otp'] ?: str_pad((string)rand(0,9999),4,'0',STR_PAD_LEFT);
    $db->prepare("UPDATE bookings SET start_otp = ?, otp = ? WHERE id = ?")->execute([$otp, $otp, $id]);
    $fcm = getFcm($db, 'providers', $bk['provider_id']);
    sendPush($fcm, "Start OTP Ready 🔑", "Customer generated the start OTP. Ask them and enter it to begin.", ['event'=>'start_otp_ready','bookingId'=>$id]);
    ok(['start_otp'=>$otp]);
  }

  case 'request_completion_otp': {
    $prov = requireProvider();
    $bd   = getBody();
    $id   = $bd['booking_id'] ?? '';
    if (empty($id)) err('booking_id required');
    $stmt = $db->prepare("SELECT * FROM bookings WHERE id = ? AND provider_id = ?");
    $stmt->execute([$id, $prov['id']]);
    $bk = $stmt->fetch();
    if (!$bk) err('Booking not found');
    if ($bk['status'] !== 'active') err('Job not active');
    // enforce minimum duration
    $minMin = (int)($bk['min_duration_min'] ?: 5);
    if (!empty($bk['started_at'])) {
      $elapsed = (time() - strtotime($bk['started_at'])) / 60;
      if ($elapsed < $minMin) err('TOO_EARLY:' . ceil($minMin - $elapsed));
    }
    $cotp = $bk['completion_otp'] ?: str_pad((string)rand(0,9999),4,'0',STR_PAD_LEFT);
    $db->prepare("UPDATE bookings SET completion_otp = ? WHERE id = ?")->execute([$cotp, $id]);
    $fcm = getFcm($db, 'customers', $bk['customer_id']);
    sendPush($fcm, "Completion OTP 🔑", "Your provider finished. Share the completion OTP if satisfied.", ['event'=>'completion_otp_ready','bookingId'=>$id]);
    ok(['completion_otp'=>$cotp]);
  }

  case 'mark_paid': {
    $user = requireCustomer();
    $bd   = getBody();
    $id   = $bd['booking_id'] ?? '';
    if (empty($id)) err('booking_id required');
    $stmt = $db->prepare("SELECT * FROM bookings WHERE id = ? AND customer_id = ?");
    $stmt->execute([$id, $user['uid']]);
    $bk = $stmt->fetch();
    if (!$bk) err('Booking not found');
    $db->prepare("UPDATE bookings SET payment_status = 'paid' WHERE id = ?")->execute([$id]);
    $fcm = getFcm($db, 'providers', $bk['provider_id']);
    sendPush($fcm, "Payment Received 💰", "Customer marked payment as done.", ['event'=>'payment_done','bookingId'=>$id]);
    ok(['payment_status'=>'paid']);
  }

  case 'request_review': {
    $prov = requireProvider();
    $bd   = getBody();
    $id   = $bd['booking_id'] ?? '';
    if (empty($id)) err('booking_id required');
    $stmt = $db->prepare("SELECT * FROM bookings WHERE id = ? AND provider_id = ?");
    $stmt->execute([$id, $prov['id']]);
    $bk = $stmt->fetch();
    if (!$bk) err('Booking not found');
    $db->prepare("UPDATE bookings SET review_requested = 1 WHERE id = ?")->execute([$id]);
    $fcm = getFcm($db, 'customers', $bk['customer_id']);
    sendPush($fcm, "Review Request ⭐", ($bk['provider_name'] ?: 'Your provider') . " is asking for a review.", ['event'=>'review_requested','bookingId'=>$id]);
    ok(['review_requested'=>1]);
  }

  default:
    err('Invalid action');
}
