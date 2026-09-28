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
//
// Auth: customer = web JWT or Firebase ID token; provider = provider JWT;
//       admin = X-Admin-Key header (or ?key=).
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
    return 'BK-' . strtoupper(substr(uniqid(), -6)) . random_int(10,99);
}

// ── Helper: generate OTP (CSPRNG) ─────────────────────
function makeOtp() {
    return str_pad((string)random_int(0, 9999), 4, '0', STR_PAD_LEFT);
}

// ── Helper: load booking or 404 ───────────────────────
function loadBooking($db, $id) {
    $stmt = $db->prepare("SELECT * FROM bookings WHERE id = ?");
    $stmt->execute([$id]);
    $bk = $stmt->fetch();
    if (!$bk) err('Booking not found', 404);
    return $bk;
}

// ── Helper: is $auth the booking's customer / assigned provider ──
function isOwnerCustomer($auth, $bk) {
    return $auth && $auth['role'] === 'customer' && (string)$bk['customer_id'] === (string)$auth['uid'];
}
function isAssignedProvider($auth, $bk) {
    return $auth && $auth['role'] === 'provider' && !empty($bk['provider_id'])
        && (string)$bk['provider_id'] === (string)$auth['id'];
}

// ── Helper: remove fields a viewer must not see ───────
// OTPs are the customer's secrets; the assigned provider gets customer contact,
// other providers (browsing open jobs) do not.
function redactBookingFor($bk, $auth) {
    if (!$bk) return $bk;
    if ($auth['role'] === 'admin' || isOwnerCustomer($auth, $bk)) return $bk;
    unset($bk['otp'], $bk['start_otp'], $bk['completion_otp'], $bk['customer_fcm_token']);
    if (!isAssignedProvider($auth, $bk)) {
        unset($bk['customer_phone'], $bk['provider_upi'], $bk['razorpay_order_id'], $bk['razorpay_payment_id']);
    }
    return $bk;
}

// Statuses a booking is still "open" (visible to any provider) in
$OPEN_STATUSES = ['searching'];
// Statuses from which the job may be completed / paid
$PAYABLE_STATUSES = ['confirmed', 'active'];

// ── auto-migrate (runs once per server, not per request) ──
runMigrationsOnce($db, 'bookings_v1', [
  "ALTER TABLE bookings ADD COLUMN completion_otp VARCHAR(8) NULL",
  "ALTER TABLE bookings ADD COLUMN rating INT NULL",
  "ALTER TABLE bookings ADD COLUMN review TEXT NULL",
  "ALTER TABLE bookings ADD COLUMN start_otp VARCHAR(8) NULL",
  "ALTER TABLE bookings ADD COLUMN started_at DATETIME NULL",
  "ALTER TABLE bookings ADD COLUMN min_duration_min INT DEFAULT 5",
  "ALTER TABLE bookings ADD COLUMN review_requested TINYINT DEFAULT 0",
  "ALTER TABLE bookings ADD COLUMN payment_method VARCHAR(20) NULL",
  "ALTER TABLE bookings ADD COLUMN razorpay_payment_id VARCHAR(50) NULL",
  "ALTER TABLE providers ADD COLUMN upi_id VARCHAR(100) NULL",
  "ALTER TABLE providers ADD COLUMN areas TEXT NULL",
  "ALTER TABLE providers ADD COLUMN photo VARCHAR(255) NULL",
]);

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
      WHERE customer_id = ? AND status NOT IN ('completed','cancelled','reviewed')
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
      // approved + available providers offering this service (provider_services),
      // matched by radius (if GPS) or city/area
      $pstmt = $db->prepare("
        SELECT p.id, p.fcm_token, p.lat, p.lng, p.radius_km, p.city, p.areas,
          CASE WHEN p.lat IS NOT NULL AND p.lat != 0 AND :blat != 0
            THEN 6371 * ACOS(LEAST(1, COS(RADIANS(:blat2)) * COS(RADIANS(p.lat)) *
                 COS(RADIANS(p.lng) - RADIANS(:blng)) +
                 SIN(RADIANS(:blat3)) * SIN(RADIANS(p.lat))))
            ELSE NULL END AS dist
        FROM providers p
        INNER JOIN provider_services ps
                ON ps.provider_id = p.id AND ps.svc_id = :svc AND ps.enabled = 1
        WHERE p.status = 'approved' AND p.available = 1 AND p.fcm_token IS NOT NULL AND p.fcm_token != ''
      ");
      $pstmt->execute([':blat'=>$blat, ':blat2'=>$blat, ':blat3'=>$blat, ':blng'=>$blng, ':svc'=>$svcId]);
      $provs = $pstmt->fetchAll();
      $svcName = $b['svc_name'] ?? 'a service';
      foreach ($provs as $pr) {
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

    if (($prov['status'] ?? '') !== 'approved') err('Your account is not approved yet', 403);

    // Check booking is still available
    // ('active' with no provider = legacy "open" status; an in-progress
    //  'active' job always has a provider and must not be taken over)
    $stmt = $db->prepare("SELECT * FROM bookings WHERE id = ?
                          AND (status = 'searching' OR (status = 'active' AND (provider_id IS NULL OR provider_id = '')))");
    $stmt->execute([$id]);
    $bk = $stmt->fetch();
    if (!$bk) err('Booking not available');

    // Get provider name
    $pStmt = $db->prepare("SELECT name FROM providers WHERE id = ?");
    $pStmt->execute([$prov['id']]);
    $pName = $pStmt->fetchColumn();

    // Conditional update — only one provider can win the race
    $upd = $db->prepare("
      UPDATE bookings SET
        provider_id         = ?,
        provider_name       = ?,
        status              = 'price_quoted',
        quoted_price        = ?,
        negotiation_status  = 'quoted',
        accepted_at         = CURRENT_TIMESTAMP
      WHERE id = ?
        AND (status = 'searching' OR (status = 'active' AND (provider_id IS NULL OR provider_id = '')))
    ");
    $upd->execute([$prov['id'], $pName, $price, $id]);
    if ($upd->rowCount() === 0) err('Booking not available');

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
    if (empty($bk['provider_id']) || !in_array($bk['status'], ['price_quoted','negotiating','negotiation_final'], true))
      err('Booking is not in negotiation');
    if ($counter < 0) err('Invalid counter_price');

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
    if (!in_array($bk['status'], ['price_quoted','negotiating','negotiation_final'], true))
      err('Booking is not in negotiation');

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

    if (!in_array($bk['status'], ['price_quoted','negotiating','negotiation_final'], true))
      err('Booking is not in negotiation');

    $confirmed = (int)($bk['counter_price'] ?: $bk['quoted_price']);
    if ($confirmed <= 0) err('No price to accept');
    $otp = makeOtp();

    $db->prepare("
      UPDATE bookings SET
        status             = 'confirmed',
        confirmed_price    = ?,
        amount             = ?,
        otp                = ?,
        negotiation_status = 'confirmed'
      WHERE id = ?
    ")->execute([$confirmed, $confirmed, $otp, $id]);

    $fcm = getFcm($db, 'customers', $bk['customer_id']);
    sendPush($fcm,
      "Price Confirmed ✅",
      "Provider accepted ₹$confirmed. Booking confirmed!",
      ['event'=>'price_confirmed','confirmedPrice'=>"$confirmed",'bookingId'=>$id]
    );

    // The start OTP belongs to the customer (visible to them via ?action=get)
    ok(['status'=>'confirmed','confirmed_price'=>$confirmed]);
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

    // Already confirmed — idempotent
    if (in_array($bk['status'], ['confirmed','active'], true) && (int)$bk['confirmed_price'] > 0) {
      ok(['status'=>$bk['status'],'confirmed_price'=>(int)$bk['confirmed_price'],'otp'=>$bk['otp'] ?? '']);
    }
    if (empty($bk['provider_id']) || !in_array($bk['status'], ['price_quoted','negotiating','negotiation_final'], true))
      err('Booking has no price to confirm');
    // The customer can only accept a price the provider actually offered
    $offered = array_filter([(int)$bk['quoted_price'], (int)$bk['final_price']]);
    if (!in_array($price, $offered, true)) err('Price does not match the provider offer');

    $otp = $bk['otp'] ?: makeOtp();
    $db->prepare("
      UPDATE bookings SET
        status             = 'confirmed',
        confirmed_price    = ?,
        amount             = ?,
        otp                = ?,
        negotiation_status = 'confirmed'
      WHERE id = ?
    ")->execute([$price, $price, $otp, $id]);

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

    if (in_array($bk['status'], ['active','completed','cancelled'], true) && !empty($bk['provider_id']))
      err('Booking can no longer be re-assigned');

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
        confirmed_price    = 0,
        amount             = 0,
        otp                = NULL,
        start_otp          = NULL,
        completion_otp     = NULL,
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
    if ($bk['status'] === 'active' && (int)$bk['otp_verified'] === 1) ok(['verified'=>true,'amount'=>(int)$bk['confirmed_price']]);
    if ($bk['status'] !== 'confirmed') err('Booking is not confirmed');
    if (empty($bk['otp']) || !hash_equals((string)$bk['otp'], (string)$otp)) err('Invalid OTP');

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
  // Allowed: the booking's customer, the assigned provider (needs the
  // customer's completion OTP), or admin. Idempotent: provider earnings are
  // credited exactly once, by whichever request flips the status.
  case 'complete': {
    $auth = requireAuth();
    $b  = getBody();
    $id = $b['booking_id'] ?? '';
    if (empty($id)) err('booking_id required');

    $bk = loadBooking($db, $id);
    $isCust = isOwnerCustomer($auth, $bk);
    $isProv = isAssignedProvider($auth, $bk);
    if (!$isCust && !$isProv && $auth['role'] !== 'admin') err('Forbidden', 403);

    // Already completed → return the stored result, never credit twice
    if ($bk['status'] === 'completed') {
      ok(['status'=>'completed','amount'=>bookingPayable($bk),'provider_earns'=>(int)$bk['provider_earns']]);
    }
    if (!in_array($bk['status'], $PAYABLE_STATUSES, true)) err('Booking cannot be completed in status: ' . $bk['status']);

    // Completion OTP gate — the provider needs the OTP the customer shares
    // only when satisfied with the work (customer/admin are the consenting party)
    if ($isProv) {
      if (empty($bk['completion_otp'])) err('COMPLETION_OTP_REQUIRED');
      $cotp = trim((string)($b['completion_otp'] ?? ''));
      if ($cotp === '') err('COMPLETION_OTP_REQUIRED');
      if (!hash_equals((string)$bk['completion_otp'], $cotp)) err('Invalid completion OTP');
    }

    $amount = bookingPayable($bk);
    $commPct = (int)($bk['commission_pct'] ?? 15);
    $commAmt = (int)round($amount * $commPct / 100);
    $provEarns = $amount - $commAmt;

    $db->beginTransaction();
    $upd = $db->prepare("
      UPDATE bookings SET
        status         = 'completed',
        payment_status = IF(payment_status = 'paid', 'paid', 'pending'),
        commission_pct = ?,
        commission_amt = ?,
        provider_earns = ?,
        completed_at   = CURRENT_TIMESTAMP
      WHERE id = ? AND status IN ('confirmed','active')
    ");
    $upd->execute([$commPct, $commAmt, $provEarns, $id]);
    if ($upd->rowCount() === 0) {
      // Lost a race with a concurrent completion — report current state
      $db->rollBack();
      $bk = loadBooking($db, $id);
      if ($bk['status'] === 'completed')
        ok(['status'=>'completed','amount'=>bookingPayable($bk),'provider_earns'=>(int)$bk['provider_earns']]);
      err('Booking cannot be completed in status: ' . $bk['status']);
    }
    // Update provider earnings (same transaction as the status flip)
    if (!empty($bk['provider_id'])) {
      $db->prepare("
        UPDATE providers SET
          completed_bookings = completed_bookings + 1,
          total_bookings     = total_bookings + 1,
          total_earned       = total_earned + ?,
          pending_earned     = pending_earned + ?
        WHERE id = ?
      ")->execute([$provEarns, $provEarns, $bk['provider_id']]);
    }
    $db->commit();

    // Notify customer — job done, please pay
    $cfcm = getFcm($db, 'customers', $bk['customer_id']);
    sendPush($cfcm, "Service Completed 🎉", "Please pay ₹$amount to your provider.", ['event'=>'completed','bookingId'=>$id,'amount'=>(string)$amount]);

    if (!empty($bk['provider_id'])) {
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
  // Customer: own bookings only. Provider: assigned bookings, or open
  // (searching) ones; for anything else a minimal status stub is returned so
  // provider apps can see that a job was taken/cancelled. Admin: all.
  case 'get': {
    $auth = requireAuth();
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

    if ($auth['role'] === 'customer' && !isOwnerCustomer($auth, $bk)) err('Booking not found', 404);
    if ($auth['role'] === 'provider' && !isAssignedProvider($auth, $bk)
        && !in_array($bk['status'], $OPEN_STATUSES, true)) {
      ok([
        'id'          => $bk['id'],
        'status'      => $bk['status'],
        'provider_id' => $bk['provider_id'],
        'svc_id'      => $bk['svc_id'],
        'svc_name'    => $bk['svc_name'],
        'svc_icon'    => $bk['svc_icon'],
      ]);
    }
    ok(redactBookingFor($bk, $auth));
  }

  // ── ACTIVE BOOKING ────────────────────────────────────
  case 'active': {
    $auth = requireAuth();
    $id   = $_GET['id']   ?? '';
    $role = $_GET['role'] ?? 'customer';
    if ($role !== 'provider') $role = 'customer';
    // Default the id to the caller's own id
    if (empty($id)) $id = $auth['role'] === 'customer' ? $auth['uid'] : ($auth['role'] === 'provider' ? $auth['id'] : '');
    if (empty($id)) err('id required');
    if ($auth['role'] !== 'admin') {
      if ($role !== $auth['role']) err('Forbidden', 403);
      $self = $auth['role'] === 'customer' ? $auth['uid'] : $auth['id'];
      if ((string)$id !== (string)$self) err('Forbidden', 403);
    }

    $col  = $role === 'provider' ? 'provider_id' : 'customer_id';
    // include recently-completed-but-unpaid so provider still sees payment status
    $stmt = $db->prepare("
      SELECT * FROM bookings
      WHERE $col = ?
        AND (status NOT IN ('completed','cancelled','reviewed')
             OR (status = 'completed' AND payment_status != 'paid'
                 AND completed_at > (NOW() - INTERVAL 2 HOUR)))
      ORDER BY (status='completed') ASC, created_at DESC
      LIMIT 1
    ");
    $stmt->execute([$id]);
    $bk = $stmt->fetch();
    ok($bk ? redactBookingFor($bk, $auth) : null);
  }

  // ── BOOKING HISTORY ───────────────────────────────────
  case 'history': {
    $auth = requireAuth();
    $id   = $_GET['id']   ?? '';
    $role = $_GET['role'] ?? 'customer';
    if ($role !== 'provider') $role = 'customer';
    if (empty($id)) $id = $auth['role'] === 'customer' ? $auth['uid'] : ($auth['role'] === 'provider' ? $auth['id'] : '');
    if (empty($id)) err('id required');
    if ($auth['role'] !== 'admin') {
      if ($role !== $auth['role']) err('Forbidden', 403);
      $self = $auth['role'] === 'customer' ? $auth['uid'] : $auth['id'];
      if ((string)$id !== (string)$self) err('Forbidden', 403);
    }
    $col  = $role === 'provider' ? 'provider_id' : 'customer_id';

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
  // Allowed: the booking's customer, the assigned provider, or admin.
  case 'cancel': {
    $auth = requireAuth();
    $b  = getBody();
    $id = $b['booking_id'] ?? '';
    if (empty($id)) err('booking_id required');

    $bk = loadBooking($db, $id);
    $isCust = isOwnerCustomer($auth, $bk);
    $isProv = isAssignedProvider($auth, $bk);
    if (!$isCust && !$isProv && $auth['role'] !== 'admin') err('Forbidden', 403);
    if ($bk['status'] === 'cancelled') ok(['cancelled'=>true]);
    if ($bk['status'] === 'completed') err('Completed bookings cannot be cancelled');

    $db->prepare("UPDATE bookings SET status = 'cancelled' WHERE id = ? AND status NOT IN ('completed','cancelled')")
       ->execute([$id]);

    // Notify the other party
    if (!$isProv && !empty($bk['provider_id'])) {
      $fcm = getFcm($db, 'providers', $bk['provider_id']);
      sendPush($fcm, "Booking Cancelled", "A booking was cancelled.",
        ['event'=>'booking_cancelled','bookingId'=>$id]);
    }
    if (!$isCust) {
      $fcm = getFcm($db, 'customers', $bk['customer_id']);
      sendPush($fcm, "Booking Cancelled", "Your booking was cancelled.",
        ['event'=>'booking_cancelled','bookingId'=>$id]);
    }

    ok(['cancelled'=>true]);
  }

  // ── OPEN BOOKINGS (provider polling) ─────────────────
  case 'open': {
    $prov  = requireProvider();
    $city  = $_GET['city'] ?? '';
    $limit = max(1, min(100, (int)($_GET['limit'] ?? 20)));

    $sql    = "SELECT * FROM bookings WHERE status = 'searching'";
    $params = [];
    if (!empty($city)) { $sql .= " AND city LIKE ?"; $params[] = "%$city%"; }
    $sql .= " ORDER BY created_at DESC LIMIT $limit";

    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $auth = ['role'=>'provider','id'=>$prov['id']];
    ok(array_map(function ($r) use ($auth) { return redactBookingFor($r, $auth); }, $stmt->fetchAll()));
  }

  // ── ADMIN: LIST ALL ───────────────────────────────────
  case 'admin_list': {
    requireAdmin();
    $status = $_GET['status'] ?? '';
    $city   = $_GET['city']   ?? '';
    $limit  = max(1, min(1000, (int)($_GET['limit'] ?? 50)));

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
    $stmt->execute([$id, $cust['uid']]);
    $bk = $stmt->fetch();
    if (!$bk) err('Booking not found');
    if ($bk['status'] !== 'completed') err('Booking not completed yet');
    if (!empty($bk['rating'])) err('Already reviewed');
    $dup = $db->prepare("SELECT id FROM reviews WHERE booking_id = ? LIMIT 1");
    $dup->execute([$id]);
    if ($dup->fetch()) err('Already reviewed');

    $upd = $db->prepare("UPDATE bookings SET rating = ?, review = ? WHERE id = ? AND rating IS NULL");
    $upd->execute([$rating, $review, $id]);
    if ($upd->rowCount() === 0) err('Already reviewed');

    // Recompute provider rating
    if (!empty($bk['provider_id'])) {
      // keep reviews table (read by reviews.php?action=provider) in sync
      try {
        $db->prepare("INSERT INTO reviews (booking_id, customer_id, provider_id, rating, comment) VALUES (?, ?, ?, ?, ?)")
           ->execute([$id, $cust['uid'], $bk['provider_id'], $rating, $review]);
      } catch (Throwable $e) { error_log('review insert: ' . $e->getMessage()); }
      recomputeProviderRating($db, $bk['provider_id']);
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
    if (in_array($bk['status'], ['active','completed','cancelled'], true) && !empty($bk['provider_id']))
      err('Booking can no longer be re-assigned');
    $db->prepare("UPDATE bookings SET status = 'searching', provider_id = NULL, provider_name = NULL,
                    quoted_price = 0, counter_price = 0, final_price = 0, confirmed_price = 0, amount = 0,
                    otp = NULL, start_otp = NULL, completion_otp = NULL, negotiation_status = NULL
                  WHERE id = ?")->execute([$id]);
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
    $otp = $bk['start_otp'] ?: makeOtp();
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
    // [TESTING] minimum-duration gate disabled
    // $minMin = (int)($bk['min_duration_min'] ?: 5);
    // if (!empty($bk['started_at'])) {
    //   $elapsed = (time() - strtotime($bk['started_at'])) / 60;
    //   if ($elapsed < $minMin) err('TOO_EARLY:' . ceil($minMin - $elapsed));
    // }
    $cotp = $bk['completion_otp'] ?: makeOtp();
    $db->prepare("UPDATE bookings SET completion_otp = ? WHERE id = ?")->execute([$cotp, $id]);
    $fcm = getFcm($db, 'customers', $bk['customer_id']);
    sendPush($fcm, "Completion OTP 🔑", "Your provider finished. Share the completion OTP if satisfied.", ['event'=>'completion_otp_ready','bookingId'=>$id]);
    // The OTP is the customer's proof of satisfaction — never returned to the provider.
    ok(['requested'=>true]);
  }

  // ── MARK PAID (cash) ──────────────────────────────────
  // Customer: can only *claim* a cash payment → payment_status 'cash_pending'.
  // Assigned provider (confirms cash received) or admin: → 'paid'.
  // Online payments are marked paid only by razorpay_confirm / webhook.
  case 'mark_paid': {
    $auth = requireAuth();
    $bd   = getBody();
    $id   = $bd['booking_id'] ?? '';
    if (empty($id)) err('booking_id required');
    $bk = loadBooking($db, $id);
    $isCust = isOwnerCustomer($auth, $bk);
    $isProv = isAssignedProvider($auth, $bk);
    if (!$isCust && !$isProv && $auth['role'] !== 'admin') err('Booking not found', 404);
    if (($bk['payment_status'] ?? '') === 'paid') ok(['payment_status'=>'paid']);
    if (!in_array($bk['status'], ['confirmed','active','completed'], true)) err('Booking is not payable yet');

    if ($isCust) {
      $db->prepare("UPDATE bookings SET payment_status = 'cash_pending', payment_method = 'cash'
                    WHERE id = ? AND payment_status != 'paid'")->execute([$id]);
      $fcm = getFcm($db, 'providers', $bk['provider_id']);
      sendPush($fcm, "Cash Payment 💵", "Customer says they paid ₹" . bookingPayable($bk) . " in cash. Please confirm you received it.",
        ['event'=>'cash_payment_claimed','bookingId'=>$id]);
      ok(['payment_status'=>'cash_pending']);
    }

    $db->prepare("UPDATE bookings SET payment_status = 'paid',
                    payment_method = IF(payment_method IS NULL OR payment_method = '', 'cash', payment_method)
                  WHERE id = ? AND payment_status != 'paid'")->execute([$id]);
    $fcm = getFcm($db, 'customers', $bk['customer_id']);
    sendPush($fcm, "Payment Confirmed ✅", "Your payment was confirmed. Thank you!", ['event'=>'payment_done','bookingId'=>$id]);
    if ($auth['role'] === 'admin' && !empty($bk['provider_id'])) {
      $pf = getFcm($db, 'providers', $bk['provider_id']);
      sendPush($pf, "Payment Received 💰", "Payment for booking $id was confirmed.", ['event'=>'payment_done','bookingId'=>$id]);
    }
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
    if (($bk['payment_status'] ?? '') !== 'paid') err('PAYMENT_PENDING');
    $db->prepare("UPDATE bookings SET review_requested = 1 WHERE id = ?")->execute([$id]);
    $fcm = getFcm($db, 'customers', $bk['customer_id']);
    sendPush($fcm, "Review Request ⭐", ($bk['provider_name'] ?: 'Your provider') . " is asking for a review.", ['event'=>'review_requested','bookingId'=>$id]);
    ok(['review_requested'=>1]);
  }

  // ── RAZORPAY: confirm online payment ─────────────────
  // 1) signature (order|payment) with the configured key secret
  // 2) order fetched from Razorpay: amount == booking payable (paise),
  //    notes bookingId/booking_id == this booking
  // 3) payment fetched from Razorpay: belongs to the order, captured/authorized
  // Idempotent: re-confirming the same payment returns success.
  case 'razorpay_confirm': {
    $user = requireCustomer();
    $bd   = getBody();
    $id   = (string)($bd['booking_id'] ?? '');
    $oid  = (string)($bd['razorpay_order_id'] ?? '');
    $pid  = (string)($bd['razorpay_payment_id'] ?? '');
    $sig  = (string)($bd['razorpay_signature'] ?? '');
    if ($id === '' || $oid === '' || $pid === '' || $sig === '') err('Missing payment fields');
    if (!preg_match('/^order_[A-Za-z0-9]+$/', $oid) || !preg_match('/^pay_[A-Za-z0-9]+$/', $pid)) err('Invalid payment ids');

    if (!razorpaySignatureValid($oid, $pid, $sig)) err('Invalid payment signature');

    $stmt = $db->prepare("SELECT * FROM bookings WHERE id = ? AND customer_id = ?");
    $stmt->execute([$id, $user['uid']]);
    $bk = $stmt->fetch();
    if (!$bk) err('Booking not found');

    if (($bk['payment_status'] ?? '') === 'paid') {
      if (($bk['razorpay_payment_id'] ?? '') === $pid) ok(['payment_status'=>'paid','payment_id'=>$pid]);
      err('Booking is already paid', 409);
    }
    if (!in_array($bk['status'], ['confirmed','active','completed'], true)) err('Booking is not payable yet');

    $expectedPaise = bookingPayable($bk) * 100;
    if ($expectedPaise <= 0) err('Booking has no payable amount');

    $order = razorpayGet('orders/' . rawurlencode($oid));
    if (!$order) err('Could not verify payment with Razorpay. Please retry.', 502);
    $notes = is_array($order['notes'] ?? null) ? $order['notes'] : [];
    $noteBk = (string)($notes['bookingId'] ?? $notes['booking_id'] ?? '');
    if ($noteBk !== $id) err('Payment does not belong to this booking');
    if ((int)($order['amount'] ?? 0) !== $expectedPaise) err('Payment amount mismatch');
    if (($order['currency'] ?? 'INR') !== 'INR') err('Payment currency mismatch');

    $payment = razorpayGet('payments/' . rawurlencode($pid));
    if (!$payment) err('Could not verify payment with Razorpay. Please retry.', 502);
    if (($payment['order_id'] ?? '') !== $oid) err('Payment does not belong to this order');
    if (!in_array($payment['status'] ?? '', ['captured','authorized'], true)) err('Payment not completed');
    if ((int)($payment['amount'] ?? 0) !== $expectedPaise) err('Payment amount mismatch');

    $upd = $db->prepare("UPDATE bookings SET payment_status = 'paid', payment_method = 'razorpay',
                           razorpay_payment_id = ?, razorpay_order_id = ?
                         WHERE id = ? AND payment_status != 'paid'");
    $upd->execute([$pid, $oid, $id]);
    if ($upd->rowCount() === 0) {
      $bk2 = loadBooking($db, $id);
      if (($bk2['razorpay_payment_id'] ?? '') === $pid) ok(['payment_status'=>'paid','payment_id'=>$pid]);
      err('Booking is already paid', 409);
    }

    $fcm = getFcm($db, 'providers', $bk['provider_id']);
    sendPush($fcm, "Payment Received 💰", "Customer paid ₹" . bookingPayable($bk) . " online.", ['event'=>'payment_done','bookingId'=>$id]);

    ok(['payment_status'=>'paid','payment_id'=>$pid]);
  }

  default:
    err('Invalid action');
}
