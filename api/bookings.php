<?php
// ═══════════════════════════════════════════════════════
// HamaraService — Bookings API  (single source of truth: MySQL)
//
// Status machine:
//   searching → price_quoted → (negotiating → negotiation_final)
//   → confirmed  [start OTP]  → active  [completion OTP]  → completed
//   any pre-active state → cancelled
// Payment is tracked separately: payment_status pending → paid (→ refunded)
//
// Customer  (Firebase ID token or web JWT):
//   POST create, negotiate, confirm_price, decline, search_another,
//        cancel, generate_start_otp, create_order, razorpay_confirm, review
// Provider  (provider JWT, approved):
//   GET  open
//   POST accept, final_offer, accept_counter, release, verify_otp,
//        request_completion_otp, complete, cash_collected, request_review
// Any party of the booking:
//   GET  get&id=X, active, history
// Admin:
//   GET  admin_list   POST cancel
// ═══════════════════════════════════════════════════════
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/schema.php';
require_once __DIR__ . '/razorpay.php';
setCorsHeaders();

$action = $_GET['action'] ?? '';
$db     = getDB();
ensureSchema($db);
if (in_array($action, ['get','active','open','confirm_price','accept','history','admin_list'], true)) {
  try { expireStaleQuotes($db); } catch (Throwable $e) {}
}

const PRE_ACTIVE = ['searching','price_quoted','negotiating','negotiation_final','confirmed'];

// ── Helpers ───────────────────────────────────────────
function getFcm($db, $table, $id) {
    if (empty($id)) return '';
    $table = $table === 'providers' ? 'providers' : 'customers';
    $stmt = $db->prepare("SELECT fcm_token FROM $table WHERE id = ?");
    $stmt->execute([$id]);
    return $stmt->fetchColumn() ?: '';
}

// Quotes the customer did not answer within QUOTE_TTL_MIN go back to searching
function expireStaleQuotes($db) {
    $q = $db->prepare("SELECT id, provider_id FROM bookings
                       WHERE status IN ('price_quoted','negotiation_final') AND quote_at IS NOT NULL
                         AND quote_at < NOW() - INTERVAL ? MINUTE");
    $q->execute([QUOTE_TTL_MIN]);
    foreach ($q->fetchAll() as $r) {
        $u = $db->prepare("
            UPDATE bookings SET status = 'searching', provider_id = NULL, provider_name = NULL,
                   quoted_price = 0, counter_price = 0, final_price = 0, confirmed_price = 0, amount = 0,
                   negotiation_status = NULL, accepted_at = NULL, quote_at = NULL
            WHERE id = ? AND status IN ('price_quoted','negotiation_final')");
        $u->execute([$r['id']]);
        if ($u->rowCount() === 1 && !empty($r['provider_id'])) {
            sendPush(getFcm($db, 'providers', $r['provider_id']), "Quote expired ⌛",
              "The customer did not confirm within " . QUOTE_TTL_MIN . " minutes. The job was released.",
              ['event'=>'booking_released','bookingId'=>$r['id']]);
        }
    }
}

function makeBookingId() {
    return 'BK-' . strtoupper(bin2hex(random_bytes(5)));
}

function loadBooking($db, $id) {
    $stmt = $db->prepare("SELECT * FROM bookings WHERE id = ?");
    $stmt->execute([$id]);
    return $stmt->fetch() ?: null;
}

// Load a booking the caller is a party to, optionally requiring a status
function bookingFor($db, $id, $user, $statuses = null) {
    if (empty($id)) err('booking_id required');
    $bk = loadBooking($db, $id);
    if (!$bk) err('Booking not found', 404);
    if ($user['role'] === 'customer' && $bk['customer_id'] !== $user['id']) err('Booking not found', 404);
    if ($user['role'] === 'provider' && $bk['provider_id'] !== $user['id']) err('Booking not found', 404);
    if ($statuses !== null && !in_array($bk['status'], (array)$statuses, true)) {
        err('Not allowed in status: ' . $bk['status'], 409);
    }
    return $bk;
}

// Strip secrets / PII the caller must not see
// Can this booking be paid in cash? (provider wallet must cover the platform fee)
function cashAllowed($db, array $bk) {
    if (empty($bk['provider_id'])) return false;
    $w = $db->prepare("SELECT pending_earned FROM providers WHERE id = ?");
    $w->execute([$bk['provider_id']]);
    $bal = (int)$w->fetchColumn();
    return $bal >= platformFee($bk);
}

// Platform fee for this job (known once a price is agreed)
function platformFee(array $bk) {
    $amt = (int)($bk['amount'] ?: $bk['confirmed_price'] ?: $bk['final_price'] ?: $bk['quoted_price']);
    return (int)($bk['commission_amt'] ?: round($amt * (int)($bk['commission_pct'] ?? DEFAULT_COMMISSION_PCT) / 100));
}

function sanitizeBooking(array $bk, string $role) {
    $status = $bk['status'] ?? '';
    $otpStart = $bk['start_otp'] ?? null;
    $otpDone  = $bk['completion_otp'] ?? null;
    unset($bk['otp'], $bk['start_otp'], $bk['completion_otp'], $bk['otp_attempts'], $bk['settled']);
    $bk['quote_expires_in'] = null;
    if (in_array($status, ['price_quoted','negotiation_final'], true) && !empty($bk['quote_at'])) {
        $bk['quote_expires_in'] = max(0, strtotime($bk['quote_at']) + QUOTE_TTL_MIN * 60 - time());
    }

    if ($role === 'customer') {
        // The customer is the OTP holder: shows it to the provider at the door / at the end
        if ($status === 'confirmed' && $otpStart) $bk['start_otp'] = $otpStart;
        if ($status === 'active'    && $otpDone)  $bk['completion_otp'] = $otpDone;
        if (!in_array($status, ['confirmed','active','completed'], true)) unset($bk['provider_phone']);
    } elseif ($role === 'provider') {
        $bk['completion_otp_requested'] = $otpDone ? 1 : 0;
        if (!in_array($status, ['confirmed','active','completed'], true)) {
            unset($bk['customer_phone'], $bk['contact_phone']);
        }
        unset($bk['razorpay_order_id'], $bk['razorpay_payment_id']);
    } elseif ($role === 'admin') {
        $bk['start_otp_set'] = $otpStart ? 1 : 0;
    }
    return $bk;
}

function commissionFor($db, $svcId) {
    try {
        $stmt = $db->prepare("SELECT commission_pct FROM services WHERE id = ?");
        $stmt->execute([$svcId]);
        $pct = $stmt->fetchColumn();
        if ($pct !== false && $pct !== null && (int)$pct >= 0 && (int)$pct <= 50) return (int)$pct;
    } catch (Throwable $e) {}
    return DEFAULT_COMMISSION_PCT;
}

// Apply the money movement exactly once.
//   online: platform collected the money → provider balance += provider_earns
//   cash:   provider collected the money → provider owes commission → balance -= commission_amt
function settleBooking($db, $bk, $method, $paymentId = null) {
    $upd = $db->prepare("
        UPDATE bookings SET payment_status = 'paid', payment_method = ?,
               razorpay_payment_id = COALESCE(?, razorpay_payment_id),
               paid_at = CURRENT_TIMESTAMP, settled = 1
        WHERE id = ? AND settled = 0 AND status = 'completed'
    ");
    $upd->execute([$method, $paymentId, $bk['id']]);
    if ($upd->rowCount() !== 1) return false;   // already settled

    $earn = (int)$bk['provider_earns'];
    $comm = (int)$bk['commission_amt'];
    if (!empty($bk['provider_id'])) {
        if ($method === 'cash') {
            $db->prepare("UPDATE providers SET total_earned = total_earned + ?, pending_earned = pending_earned - ? WHERE id = ?")
               ->execute([$earn, $comm, $bk['provider_id']]);
        } else {
            $db->prepare("UPDATE providers SET total_earned = total_earned + ?, pending_earned = pending_earned + ? WHERE id = ?")
               ->execute([$earn, $earn, $bk['provider_id']]);
        }
    }
    return true;
}

function providerOffers($db, $providerId, $svcId) {
    $c = $db->prepare("SELECT COUNT(*) FROM provider_services WHERE provider_id = ?");
    $c->execute([$providerId]);
    if ((int)$c->fetchColumn() === 0) return true;   // legacy: never saved a list → offers everything
    $s = $db->prepare("SELECT 1 FROM provider_services WHERE provider_id = ? AND svc_id = ? AND enabled = 1");
    $s->execute([$providerId, $svcId]);
    return (bool)$s->fetchColumn();
}

switch ($action) {

  // ── CREATE BOOKING ────────────────────────────────────
  case 'create': {
    $user = requireCustomer();
    $b    = getBody();

    foreach (['svc_id','svc_name','address'] as $f) {
      if (empty($b[$f])) err("$f is required");
    }

    $active = $db->prepare("
      SELECT id FROM bookings
      WHERE customer_id = ? AND status NOT IN ('completed','cancelled')
      LIMIT 1
    ");
    $active->execute([$user['id']]);
    if ($row = $active->fetch()) err('You already have an active booking (' . $row['id'] . ')', 409);

    $cust = $db->prepare("SELECT name, phone, city FROM customers WHERE id = ?");
    $cust->execute([$user['id']]);
    $custData = $cust->fetch();
    if (!$custData) err('Complete your profile first', 412);

    $contactPhone = preg_replace('/\D/', '', $b['contact_phone'] ?? $b['phone'] ?? '');
    $contactPhone = substr($contactPhone, -10);
    if ($contactPhone !== '' && !preg_match('/^[6-9]\d{9}$/', $contactPhone)) err('Enter a valid 10-digit mobile number');

    $city = trim($b['city'] ?? '') ?: ($custData['city'] ?? '');
    $slotDate = $b['slot_date'] ?? null;
    if ($slotDate && (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $slotDate) || $slotDate < date('Y-m-d'))) err('Invalid date');

    $id = makeBookingId();
    $summary = $b['summary'] ?? null;
    if (is_array($summary)) $summary = json_encode($summary, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    $stmt = $db->prepare("
      INSERT INTO bookings
        (id, customer_id, customer_name, customer_phone,
         contact_name, contact_phone,
         svc_id, svc_name, svc_icon,
         address, landmark, city, lat, lng,
         slot_date, slot_time, notes, summary, est_price,
         commission_pct, min_duration_min, status, payment_status)
      VALUES
        (:id, :cid, :cname, :cphone,
         :ctname, :ctphone,
         :svc_id, :svc_name, :svc_icon,
         :address, :landmark, :city, :lat, :lng,
         :date, :time, :notes, :summary, :est,
         :comm, :mindur, 'searching', 'pending')
    ");
    $stmt->execute([
      ':id'       => $id,
      ':cid'      => $user['id'],
      ':cname'    => $custData['name']  ?? '',
      ':cphone'   => $custData['phone'] ?? '',
      ':ctname'   => trim($b['contact_name'] ?? $b['customer'] ?? '') ?: ($custData['name'] ?? ''),
      ':ctphone'  => $contactPhone ?: ($custData['phone'] ?? ''),
      ':svc_id'   => $b['svc_id'],
      ':svc_name' => $b['svc_name'],
      ':svc_icon' => $b['svc_icon'] ?? '',
      ':address'  => $b['address'],
      ':landmark' => $b['landmark'] ?? '',
      ':city'     => $city,
      ':lat'      => (float)($b['lat'] ?? 0),
      ':lng'      => (float)($b['lng'] ?? 0),
      ':date'     => $slotDate,
      ':time'     => mb_substr(trim((string)($b['slot_time'] ?? '')), 0, 80),
      ':notes'    => $b['notes'] ?? '',
      ':summary'  => $summary,
      ':est'      => max(0, (int)($b['est_price'] ?? $b['price'] ?? 0)),
      ':comm'     => commissionFor($db, $b['svc_id']),
      ':mindur'   => MIN_JOB_MINUTES,
    ]);

    // ── Notify matching online providers ──
    try {
      $blat = (float)($b['lat'] ?? 0);
      $blng = (float)($b['lng'] ?? 0);
      $svcId = $b['svc_id'];
      $pstmt = $db->prepare("
        SELECT p.id, p.fcm_token,
               COALESCE(NULLIF(p.base_lat, 0), p.lat) AS lat, COALESCE(NULLIF(p.base_lng, 0), p.lng) AS lng,
               p.radius_km, p.city, p.areas
        FROM providers p
        WHERE p.status = 'approved' AND p.available = 1
          AND p.fcm_token IS NOT NULL AND p.fcm_token != ''
          AND (p.last_seen IS NULL OR p.last_seen > NOW() - INTERVAL 30 MINUTE)
          AND (
            NOT EXISTS (SELECT 1 FROM provider_services x WHERE x.provider_id = p.id)
            OR EXISTS (SELECT 1 FROM provider_services ps WHERE ps.provider_id = p.id AND ps.svc_id = ? AND ps.enabled = 1)
          )
      ");
      $pstmt->execute([$svcId]);
      foreach ($pstmt->fetchAll() as $pr) {
        $plat = (float)$pr['lat']; $plng = (float)$pr['lng'];
        if ($blat && $plat) {
          $ok = haversineKm($blat, $blng, $plat, $plng) <= max(1, (int)($pr['radius_km'] ?: 10));
        } else {
          $hay = strtolower(($pr['city'] ?? '') . ' ' . ($pr['areas'] ?? ''));
          $ok = ($city === '' || strpos($hay, strtolower($city)) !== false);
        }
        if (!$ok) continue;
        sendPush($pr['fcm_token'], "New Job Nearby 🔔",
          "{$b['svc_name']} request near you. Open to quote your price.",
          ['event'=>'new_booking','bookingId'=>$id,'role'=>'provider']);
      }
    } catch (Throwable $e) { /* never block booking on push failure */ }

    ok(['id' => $id, 'status' => 'searching']);
  }

  // ── OPEN BOOKINGS (provider polling) ─────────────────
  case 'open': {
    $prov = requireProvider(true);
    $db->prepare("UPDATE providers SET last_seen = CURRENT_TIMESTAMP WHERE id = ?")->execute([$prov['id']]);

    $p = $db->prepare("SELECT COALESCE(NULLIF(base_lat, 0), lat) AS lat, COALESCE(NULLIF(base_lng, 0), lng) AS lng,
                              radius_km, city, areas, available FROM providers WHERE id = ?");
    $p->execute([$prov['id']]);
    $me = $p->fetch();

    $svc = $db->prepare("SELECT svc_id, enabled FROM provider_services WHERE provider_id = ?");
    $svc->execute([$prov['id']]);
    $rows = $svc->fetchAll();
    $mySvcs = array_values(array_map(fn($r) => $r['svc_id'], array_filter($rows, fn($r) => (int)$r['enabled'] === 1)));
    if ($rows && !$mySvcs) ok([]);   // every service switched off → no jobs

    $sql = "SELECT id, svc_id, svc_name, svc_icon, address, landmark, city, lat, lng,
                   slot_date, slot_time, notes, summary, est_price, status, created_at
            FROM bookings
            WHERE status = 'searching' AND created_at > NOW() - INTERVAL 1 DAY";
    $params = [];
    if ($mySvcs) {
      $sql .= " AND svc_id IN (" . implode(',', array_fill(0, count($mySvcs), '?')) . ")";
      $params = $mySvcs;
    }
    $sql .= " ORDER BY created_at DESC LIMIT 100";
    $stmt = $db->prepare($sql);
    $stmt->execute($params);

    $plat = (float)$me['lat']; $plng = (float)$me['lng'];
    $radius = max(1, (int)($me['radius_km'] ?: 10));
    $hay = strtolower(($me['city'] ?? '') . ' ' . ($me['areas'] ?? ''));
    $out = [];
    foreach ($stmt->fetchAll() as $bk) {
      $blat = (float)$bk['lat']; $blng = (float)$bk['lng'];
      $bk['distance_km'] = null;
      if ($plat && $blat) {
        $d = haversineKm($plat, $plng, $blat, $blng);
        if ($d > $radius) continue;
        $bk['distance_km'] = round($d, 1);
      } elseif (!empty($bk['city']) && $hay !== ' ' && strpos($hay, strtolower($bk['city'])) === false) {
        continue;
      }
      unset($bk['lat'], $bk['lng']);   // exact location only after confirmation
      $out[] = $bk;
      if (count($out) >= 20) break;
    }
    ok($out);
  }

  // ── PROVIDER ACCEPTS + QUOTES PRICE (atomic) ─────────
  case 'accept': {
    $prov = requireProvider(true);
    $b    = getBody();
    $id   = $b['booking_id'] ?? '';
    $price = (int)($b['quoted_price'] ?? 0);
    if (empty($id)) err('booking_id required');
    if ($price <= 0) err('quoted_price must be > 0');

    $pStmt = $db->prepare("SELECT name, pending_earned FROM providers WHERE id = ?");
    $pStmt->execute([$prov['id']]);
    $me = $pStmt->fetch();
    if ((int)$me['pending_earned'] < CASH_DUE_LIMIT) {
      err('Please clear your pending commission dues (₹' . abs((int)$me['pending_earned']) . ') to accept new jobs', 402);
    }

    $busy = $db->prepare("SELECT id FROM bookings WHERE provider_id = ? AND status IN ('confirmed','active') LIMIT 1");
    $busy->execute([$prov['id']]);
    if ($busy->fetch()) err('Finish your current job first', 409);

    $bk = loadBooking($db, $id);
    if (!$bk || $bk['status'] !== 'searching') err('Booking no longer available', 409);
    if (!providerOffers($db, $prov['id'], $bk['svc_id'])) err('You do not offer this service', 403);

    // Price sanity: within the provider's own range for this service (if set)
    $rng = $db->prepare("SELECT min_price, max_price FROM provider_services WHERE provider_id = ? AND svc_id = ?");
    $rng->execute([$prov['id'], $bk['svc_id']]);
    if ($r = $rng->fetch()) {
      if ((int)$r['max_price'] > 0 && $price > (int)$r['max_price'] * 3) err('Quote is far above your listed price range');
    }

    $upd = $db->prepare("
      UPDATE bookings SET
        provider_id = ?, provider_name = ?, status = 'price_quoted',
        quoted_price = ?, counter_price = 0, final_price = 0,
        negotiation_status = 'quoted', accepted_at = CURRENT_TIMESTAMP, quote_at = CURRENT_TIMESTAMP
      WHERE id = ? AND status = 'searching' AND provider_id IS NULL
    ");
    $upd->execute([$prov['id'], $me['name'], $price, $id]);
    if ($upd->rowCount() !== 1) err('Another provider already took this job', 409);

    sendPush(getFcm($db, 'customers', $bk['customer_id']),
      "Provider Quoted ₹$price 💰",
      "{$me['name']} quoted ₹$price for your booking. Tap to view.",
      ['event'=>'price_quoted','quotedPrice'=>"$price",'bookingId'=>$id]);

    ok(['status'=>'price_quoted','quoted_price'=>$price,'booking_id'=>$id]);
  }

  // ── CUSTOMER NEGOTIATES ───────────────────────────────
  case 'negotiate': {
    $user = requireCustomer();
    $b    = getBody();
    $bk   = bookingFor($db, $b['booking_id'] ?? '', $user, ['price_quoted']);
    $counter = (int)($b['counter_price'] ?? 0);
    if ($counter <= 0 || $counter >= (int)$bk['quoted_price']) err('Counter price must be below the quote');

    $db->prepare("
      UPDATE bookings SET status = 'negotiating', counter_price = ?, negotiation_status = 'customer_countered'
      WHERE id = ? AND status = 'price_quoted'
    ")->execute([$counter, $bk['id']]);

    sendPush(getFcm($db, 'providers', $bk['provider_id']),
      "Price Negotiation 💬", "Customer countered with ₹$counter. Respond now.",
      ['event'=>'price_negotiation','counterPrice'=>"$counter",'bookingId'=>$bk['id']]);

    ok(['status'=>'negotiating','counter_price'=>$counter]);
  }

  // ── PROVIDER SENDS FINAL OFFER ────────────────────────
  case 'final_offer': {
    $prov  = requireProvider(true);
    $b     = getBody();
    $bk    = bookingFor($db, $b['booking_id'] ?? '', $prov, ['negotiating']);
    $price = (int)($b['final_price'] ?? 0);
    if ($price <= 0) err('final_price required');
    if ($price > (int)$bk['quoted_price']) err('Final price cannot exceed your quote');

    $db->prepare("
      UPDATE bookings SET status = 'negotiation_final', final_price = ?, negotiation_status = 'provider_final', quote_at = CURRENT_TIMESTAMP
      WHERE id = ? AND status = 'negotiating'
    ")->execute([$price, $bk['id']]);

    sendPush(getFcm($db, 'customers', $bk['customer_id']),
      "Final Price Offer 💰", "Provider's final price: ₹$price. Accept or search another.",
      ['event'=>'negotiation_final','finalPrice'=>"$price",'bookingId'=>$bk['id']]);

    ok(['status'=>'negotiation_final','final_price'=>$price]);
  }

  // ── PROVIDER ACCEPTS CUSTOMER COUNTER ────────────────
  case 'accept_counter': {
    $prov = requireProvider(true);
    $b    = getBody();
    $bk   = bookingFor($db, $b['booking_id'] ?? '', $prov, ['negotiating']);
    $confirmed = (int)$bk['counter_price'];
    if ($confirmed <= 0) err('No counter price to accept');

    $upd = $db->prepare("
      UPDATE bookings SET status = 'confirmed', confirmed_price = ?, amount = ?,
             start_otp = ?, otp = NULL, otp_attempts = 0, negotiation_status = 'confirmed'
      WHERE id = ? AND status = 'negotiating'
    ");
    $upd->execute([$confirmed, $confirmed, makeOtp(), $bk['id']]);
    if ($upd->rowCount() !== 1) err('Booking changed, refresh', 409);

    sendPush(getFcm($db, 'customers', $bk['customer_id']),
      "Price Confirmed ✅", "Provider accepted ₹$confirmed. Your start OTP is in the app.",
      ['event'=>'price_confirmed','confirmedPrice'=>"$confirmed",'bookingId'=>$bk['id']]);

    ok(['status'=>'confirmed','confirmed_price'=>$confirmed]);
  }

  // ── CUSTOMER CONFIRMS PRICE (quote or final offer) ───
  case 'confirm_price': {
    $user = requireCustomer();
    $b    = getBody();
    $bk   = bookingFor($db, $b['booking_id'] ?? '', $user, ['price_quoted','negotiation_final']);
    // Price is decided by the server, never by the client
    $price = $bk['status'] === 'negotiation_final' ? (int)$bk['final_price'] : (int)$bk['quoted_price'];
    if ($price <= 0) err('No price to confirm');

    $upd = $db->prepare("
      UPDATE bookings SET status = 'confirmed', confirmed_price = ?, amount = ?,
             start_otp = ?, otp = NULL, otp_attempts = 0, negotiation_status = 'confirmed'
      WHERE id = ? AND status IN ('price_quoted','negotiation_final')
    ");
    $upd->execute([$price, $price, makeOtp(), $bk['id']]);
    if ($upd->rowCount() !== 1) err('Booking changed, refresh', 409);

    sendPush(getFcm($db, 'providers', $bk['provider_id']),
      "Job Confirmed ✅", "Customer confirmed ₹$price. Head to the location.",
      ['event'=>'booking_confirmed','confirmedPrice'=>"$price",'bookingId'=>$bk['id']]);

    ok(['status'=>'confirmed','confirmed_price'=>$price]);
  }

  // ── CUSTOMER DECLINES QUOTE / SEARCHES ANOTHER ───────
  case 'decline':
  case 'search_another': {
    $user = requireCustomer();
    $b    = getBody();
    $bk   = bookingFor($db, $b['booking_id'] ?? '', $user, ['price_quoted','negotiating','negotiation_final','confirmed']);

    $db->prepare("
      UPDATE bookings SET status = 'searching', provider_id = NULL, provider_name = NULL,
             quoted_price = 0, counter_price = 0, final_price = 0, confirmed_price = 0, amount = 0,
             start_otp = NULL, otp = NULL, otp_attempts = 0,
             negotiation_status = NULL, accepted_at = NULL, quote_at = NULL
      WHERE id = ?
    ")->execute([$bk['id']]);

    if (!empty($bk['provider_id'])) {
      sendPush(getFcm($db, 'providers', $bk['provider_id']),
        "Booking Released", "Customer chose another provider.",
        ['event'=>'booking_released','bookingId'=>$bk['id']]);
    }
    ok(['status'=>'searching']);
  }

  // ── PROVIDER RELEASES A JOB (before it starts) ───────
  case 'release': {
    $prov = requireProvider();
    $b    = getBody();
    $bk   = bookingFor($db, $b['booking_id'] ?? '', $prov, ['price_quoted','negotiating','negotiation_final','confirmed']);

    $db->prepare("
      UPDATE bookings SET status = 'searching', provider_id = NULL, provider_name = NULL,
             quoted_price = 0, counter_price = 0, final_price = 0, confirmed_price = 0, amount = 0,
             start_otp = NULL, otp = NULL, otp_attempts = 0,
             negotiation_status = NULL, accepted_at = NULL, quote_at = NULL
      WHERE id = ?
    ")->execute([$bk['id']]);

    sendPush(getFcm($db, 'customers', $bk['customer_id']),
      "🔍 Searching Another Provider", "Provider declined. We are finding another provider for you.",
      ['event'=>'provider_declined','bookingId'=>$bk['id']]);
    ok(['status'=>'searching']);
  }

  // ── CUSTOMER: (re)read start OTP ──────────────────────
  case 'generate_start_otp': {
    $user = requireCustomer();
    $b    = getBody();
    $bk   = bookingFor($db, $b['booking_id'] ?? '', $user, ['confirmed']);
    $otp  = $bk['start_otp'] ?: makeOtp();
    if (!$bk['start_otp']) $db->prepare("UPDATE bookings SET start_otp = ? WHERE id = ?")->execute([$otp, $bk['id']]);
    ok(['start_otp' => $otp]);
  }

  // ── PROVIDER ENTERS START OTP → job active ───────────
  case 'verify_otp': {
    $prov = requireProvider(true);
    $b    = getBody();
    $bk   = bookingFor($db, $b['booking_id'] ?? '', $prov, ['confirmed']);
    $otp  = trim((string)($b['otp'] ?? ''));
    if ($otp === '') err('otp required');
    if ((int)$bk['otp_attempts'] >= 5) err('Too many wrong attempts. Ask the customer to contact support.', 429);
    if (empty($bk['start_otp']) || !hash_equals($bk['start_otp'], $otp)) {
      $db->prepare("UPDATE bookings SET otp_attempts = otp_attempts + 1 WHERE id = ?")->execute([$bk['id']]);
      err('Invalid OTP');
    }

    $db->prepare("
      UPDATE bookings SET otp_verified = 1, otp_attempts = 0, status = 'active', started_at = CURRENT_TIMESTAMP
      WHERE id = ? AND status = 'confirmed'
    ")->execute([$bk['id']]);

    sendPush(getFcm($db, 'customers', $bk['customer_id']),
      "Service Started ✅", "OTP verified. Your service has started.",
      ['event'=>'service_started','bookingId'=>$bk['id']]);

    ok(['verified'=>true,'status'=>'active','amount'=>(int)$bk['confirmed_price']]);
  }

  // ── PROVIDER ASKS FOR COMPLETION OTP ─────────────────
  // The code is sent to the CUSTOMER only — never returned to the provider.
  case 'request_completion_otp': {
    $prov = requireProvider(true);
    $b    = getBody();
    $bk   = bookingFor($db, $b['booking_id'] ?? '', $prov, ['active']);
    $minMin = (int)($bk['min_duration_min'] ?? MIN_JOB_MINUTES);
    if ($minMin > 0 && !empty($bk['started_at'])) {
      $elapsed = (time() - strtotime($bk['started_at'])) / 60;
      if ($elapsed < $minMin) err('TOO_EARLY:' . (int)ceil($minMin - $elapsed), 425);
    }
    $cotp = $bk['completion_otp'] ?: makeOtp();
    $db->prepare("UPDATE bookings SET completion_otp = ?, otp_attempts = 0 WHERE id = ?")->execute([$cotp, $bk['id']]);
    sendPush(getFcm($db, 'customers', $bk['customer_id']),
      "Completion OTP 🔑", "Your provider has finished. Open the app and share the completion OTP if you are satisfied.",
      ['event'=>'completion_otp_ready','bookingId'=>$bk['id']]);
    ok(['requested'=>true]);
  }

  // ── PROVIDER COMPLETES (completion OTP required) ─────
  case 'complete': {
    $prov = requireProvider(true);
    $b    = getBody();
    $bk   = bookingFor($db, $b['booking_id'] ?? '', $prov, ['active']);
    if (empty($bk['completion_otp'])) err('COMPLETION_OTP_NOT_REQUESTED');
    $cotp = trim((string)($b['completion_otp'] ?? ''));
    if ($cotp === '') err('COMPLETION_OTP_REQUIRED');
    if ((int)$bk['otp_attempts'] >= 5) err('Too many wrong attempts. Contact support.', 429);
    if (!hash_equals($bk['completion_otp'], $cotp)) {
      $db->prepare("UPDATE bookings SET otp_attempts = otp_attempts + 1 WHERE id = ?")->execute([$bk['id']]);
      err('Invalid completion OTP');
    }

    $amount    = (int)$bk['confirmed_price'];
    $commPct   = (int)($bk['commission_pct'] ?? DEFAULT_COMMISSION_PCT);
    $commAmt   = (int)round($amount * $commPct / 100);
    $provEarns = $amount - $commAmt;

    $upd = $db->prepare("
      UPDATE bookings SET status = 'completed', payment_status = 'pending', amount = ?,
             commission_pct = ?, commission_amt = ?, provider_earns = ?,
             otp_attempts = 0, completed_at = CURRENT_TIMESTAMP
      WHERE id = ? AND status = 'active'
    ");
    $upd->execute([$amount, $commPct, $commAmt, $provEarns, $bk['id']]);
    if ($upd->rowCount() !== 1) err('Booking changed, refresh', 409);

    $db->prepare("UPDATE providers SET completed_bookings = completed_bookings + 1, total_bookings = total_bookings + 1 WHERE id = ?")
       ->execute([$prov['id']]);

    sendPush(getFcm($db, 'customers', $bk['customer_id']),
      "Service Completed 🎉", "Please pay ₹$amount — online in the app or in cash to your provider.",
      ['event'=>'completed','bookingId'=>$bk['id'],'amount'=>(string)$amount]);

    ok(['status'=>'completed','amount'=>$amount,'commission_amt'=>$commAmt,'provider_earns'=>$provEarns]);
  }

  // ── PROVIDER CONFIRMS CASH RECEIVED ──────────────────
  case 'cash_collected': {
    $prov = requireProvider(true);
    $b    = getBody();
    $bk   = bookingFor($db, $b['booking_id'] ?? '', $prov, ['completed']);
    if ($bk['payment_status'] === 'paid') ok(['payment_status'=>'paid','already'=>true]);
    // Cash: the provider keeps the money, so the platform fee comes out of the
    // wallet — allowed only when the wallet can cover it.
    $w = $db->prepare("SELECT pending_earned FROM providers WHERE id = ?");
    $w->execute([$prov['id']]);
    $bal = (int)$w->fetchColumn();
    $fee = (int)$bk['commission_amt'];
    if ($bal < $fee) {
      err('CASH_NOT_ALLOWED: Wallet balance ₹' . max(0, $bal) . ' is less than the platform fee ₹' . $fee .
          '. Ask the customer to pay online, or add money to your wallet.', 402);
    }
    settleBooking($db, $bk, 'cash');
    sendPush(getFcm($db, 'customers', $bk['customer_id']),
      "Payment Recorded ✅", "Cash payment of ₹" . (int)$bk['amount'] . " recorded. Please rate your provider.",
      ['event'=>'payment_done','bookingId'=>$bk['id']]);
    ok(['payment_status'=>'paid','payment_method'=>'cash','commission_due'=>(int)$bk['commission_amt']]);
  }

  // ── CUSTOMER: CREATE RAZORPAY ORDER (amount from server) ──
  case 'create_order': {
    $user = requireCustomer();
    $b    = getBody();
    $bk   = bookingFor($db, $b['booking_id'] ?? '', $user, ['completed']);
    if ($bk['payment_status'] === 'paid') err('Already paid', 409);
    $amount = (int)$bk['amount'] ?: (int)$bk['confirmed_price'];
    if ($amount <= 0) err('Nothing to pay');

    try {
      $order = rzpRequest('POST', '/v1/orders', [
        'amount'   => $amount * 100,
        'currency' => 'INR',
        'receipt'  => substr($bk['id'], 0, 40),
        'notes'    => ['booking_id' => $bk['id'], 'customer_id' => $bk['customer_id'], 'provider_id' => (string)$bk['provider_id']],
      ]);
    } catch (Throwable $e) {
      err('Payment gateway error: ' . $e->getMessage(), 502);
    }
    $db->prepare("UPDATE bookings SET razorpay_order_id = ? WHERE id = ?")->execute([$order['id'], $bk['id']]);
    ok([
      'order_id'   => $order['id'],
      'amount'     => $amount * 100,
      'currency'   => 'INR',
      'key_id'     => RZP_KEY_ID,
      'booking_id' => $bk['id'],
    ]);
  }

  // ── CUSTOMER: CONFIRM RAZORPAY PAYMENT ───────────────
  case 'razorpay_confirm': {
    $user = requireCustomer();
    $b    = getBody();
    $bk   = bookingFor($db, $b['booking_id'] ?? '', $user, ['completed']);
    $oid  = $b['razorpay_order_id'] ?? '';
    $pid  = $b['razorpay_payment_id'] ?? '';
    $sig  = $b['razorpay_signature'] ?? '';
    if ($oid === '' || $pid === '' || $sig === '') err('Missing payment fields');
    if (empty($bk['razorpay_order_id']) || !hash_equals($bk['razorpay_order_id'], $oid)) err('Order does not belong to this booking');
    if (!rzpVerifySignature($oid, $pid, $sig)) err('Invalid payment signature');

    if ($bk['payment_status'] !== 'paid') {
      settleBooking($db, $bk, 'online', $pid);
      sendPush(getFcm($db, 'providers', $bk['provider_id']),
        "Payment Received 💰", "Customer paid ₹" . (int)$bk['amount'] . " online. ₹" . (int)$bk['provider_earns'] . " added to your wallet.",
        ['event'=>'payment_received','bookingId'=>$bk['id'],'amount'=>(string)$bk['provider_earns']]);
    }
    ok(['payment_status'=>'paid','payment_id'=>$pid]);
  }

  case 'mark_paid': {
    err('Customers cannot mark a booking paid. Pay online, or the provider confirms cash.', 410);
  }

  // ── GET BOOKING ───────────────────────────────────────
  case 'get': {
    $user = currentUser();
    if (!$user) err('Unauthorized', 401);
    if ($user['role'] === 'provider') requireProvider();
    $id = $_GET['id'] ?? '';
    if (empty($id)) err('id required');

    $stmt = $db->prepare("
      SELECT b.*,
             p.phone AS provider_phone, p.rating AS provider_rating,
             p.photo AS provider_photo, p.review_count AS provider_reviews,
             p.lat AS provider_lat, p.lng AS provider_lng
      FROM bookings b
      LEFT JOIN providers p ON p.id = b.provider_id
      WHERE b.id = ?
    ");
    $stmt->execute([$id]);
    $bk = $stmt->fetch();
    if (!$bk) err('Booking not found', 404);

    $role = $user['role'];
    if ($role === 'customer' && $bk['customer_id'] !== $user['id']) err('Booking not found', 404);
    if ($role === 'provider' && $bk['provider_id'] !== $user['id']) {
      // Unassigned provider may only peek at a still-open job (to know if it's gone)
      if ($bk['status'] !== 'searching') ok(['id'=>$bk['id'],'status'=>'taken']);
      ok(['id'=>$bk['id'],'status'=>'searching','svc_id'=>$bk['svc_id'],'svc_name'=>$bk['svc_name']]);
    }

    $bk['distance_km'] = null;
    if ((float)$bk['provider_lat'] && (float)$bk['lat']) {
      $bk['distance_km'] = round(haversineKm((float)$bk['lat'], (float)$bk['lng'], (float)$bk['provider_lat'], (float)$bk['provider_lng']), 1);
    }
    if ($role === 'customer' && !in_array($bk['status'], ['confirmed','active'], true)) {
      unset($bk['provider_lat'], $bk['provider_lng']);
    }
    if ($role === 'provider' && !in_array($bk['status'], ['confirmed','active','completed'], true)) {
      unset($bk['lat'], $bk['lng']);
    }
    $bk['cash_allowed'] = cashAllowed($db, $bk) ? 1 : 0;
    $bk['platform_fee'] = platformFee($bk);
    ok(sanitizeBooking($bk, $role));
  }

  // ── ACTIVE BOOKING (for the logged-in party) ─────────
  case 'active': {
    $user = currentUser();
    if (!$user || !in_array($user['role'], ['customer','provider'], true)) err('Unauthorized', 401);
    if ($user['role'] === 'provider') requireProvider();
    $col = $user['role'] === 'provider' ? 'provider_id' : 'customer_id';
    // include completed-but-unpaid so both sides see the payment step
    $stmt = $db->prepare("
      SELECT b.*, p.phone AS provider_phone, p.lat AS provider_lat, p.lng AS provider_lng
      FROM bookings b LEFT JOIN providers p ON p.id = b.provider_id
      WHERE b.$col = ?
        AND (b.status NOT IN ('completed','cancelled')
             OR (b.status = 'completed' AND b.payment_status != 'paid'))
      ORDER BY (b.status = 'completed') ASC, b.created_at DESC
      LIMIT 1
    ");
    $stmt->execute([$user['id']]);
    $bk = $stmt->fetch();
    if ($bk) { $bk['cash_allowed'] = cashAllowed($db, $bk) ? 1 : 0; $bk['platform_fee'] = platformFee($bk); }
    ok($bk ? sanitizeBooking($bk, $user['role']) : null);
  }

  // ── BOOKING HISTORY ───────────────────────────────────
  case 'history': {
    $user = currentUser();
    if (!$user || !in_array($user['role'], ['customer','provider'], true)) err('Unauthorized', 401);
    $isProv = $user['role'] === 'provider';
    $col = $isProv ? 'provider_id' : 'customer_id';
    $other = $isProv ? 'customer_name' : 'provider_name';
    $stmt = $db->prepare("
      SELECT id, svc_id, svc_name, svc_icon, $other AS other_party, provider_id,
             status, quoted_price, confirmed_price, amount, commission_pct, commission_amt, provider_earns,
             payment_status, payment_method, slot_date, slot_time, address, landmark, city,
             rating, review, cancel_reason, cancelled_by, created_at, completed_at, paid_at
      FROM bookings
      WHERE $col = ?
      ORDER BY created_at DESC
      LIMIT 100
    ");
    $stmt->execute([$user['id']]);
    ok($stmt->fetchAll());
  }

  // ── CANCEL (customer before job starts, or admin) ────
  case 'cancel': {
    $b  = getBody();
    $id = $b['booking_id'] ?? '';
    $reason = trim($b['reason'] ?? '');
    if (isAdminRequest()) {
      $bk = loadBooking($db, $id);
      if (!$bk) err('Booking not found', 404);
      $by = 'admin';
    } else {
      $user = requireCustomer();
      $bk = bookingFor($db, $id, $user, PRE_ACTIVE);
      $by = 'customer';
    }
    if (in_array($bk['status'], ['completed','cancelled'], true)) err('Booking already ' . $bk['status'], 409);

    $db->prepare("UPDATE bookings SET status = 'cancelled', cancel_reason = ?, cancelled_by = ? WHERE id = ?")
       ->execute([$reason, $by, $bk['id']]);

    if (!empty($bk['provider_id'])) {
      sendPush(getFcm($db, 'providers', $bk['provider_id']), "Booking Cancelled ❌",
        "The {$bk['svc_name']} booking was cancelled.",
        ['event'=>'booking_cancelled','bookingId'=>$bk['id']]);
    }
    ok(['cancelled'=>true]);
  }

  // ── CUSTOMER REVIEW ───────────────────────────────────
  case 'review': {
    $user = requireCustomer();
    $b    = getBody();
    $bk   = bookingFor($db, $b['booking_id'] ?? '', $user, ['completed']);
    $rating = (int)($b['rating'] ?? 0);
    $review = trim($b['review'] ?? $b['comment'] ?? '');
    if ($rating < 1 || $rating > 5) err('rating 1-5 required');
    if (!empty($bk['rating'])) err('Already reviewed', 409);

    $db->prepare("UPDATE bookings SET rating = ?, review = ? WHERE id = ?")->execute([$rating, $review, $bk['id']]);
    if (!empty($bk['provider_id'])) {
      try {
        $db->prepare("INSERT INTO reviews (booking_id, customer_id, provider_id, rating, comment) VALUES (?,?,?,?,?)
                      ON DUPLICATE KEY UPDATE rating = VALUES(rating), comment = VALUES(comment)")
           ->execute([$bk['id'], $user['id'], $bk['provider_id'], $rating, $review]);
      } catch (Throwable $e) {}
      $agg = $db->prepare("SELECT AVG(rating) a, COUNT(rating) c FROM bookings WHERE provider_id = ? AND rating IS NOT NULL");
      $agg->execute([$bk['provider_id']]);
      $r = $agg->fetch();
      $db->prepare("UPDATE providers SET rating = ?, review_count = ? WHERE id = ?")
         ->execute([round((float)$r['a'], 1), (int)$r['c'], $bk['provider_id']]);
      sendPush(getFcm($db, 'providers', $bk['provider_id']), "New Review ⭐", "$rating★ from a customer",
        ['event'=>'new_review','bookingId'=>$bk['id'],'rating'=>(string)$rating]);
    }
    ok(['rating'=>$rating]);
  }

  // ── PROVIDER ASKS FOR A REVIEW ────────────────────────
  case 'request_review': {
    $prov = requireProvider();
    $b    = getBody();
    $bk   = bookingFor($db, $b['booking_id'] ?? '', $prov, ['completed']);
    if ($bk['payment_status'] !== 'paid') err('PAYMENT_PENDING');
    $db->prepare("UPDATE bookings SET review_requested = 1 WHERE id = ?")->execute([$bk['id']]);
    sendPush(getFcm($db, 'customers', $bk['customer_id']), "Review Request ⭐",
      ($bk['provider_name'] ?: 'Your provider') . " is asking for a review.",
      ['event'=>'review_requested','bookingId'=>$bk['id']]);
    ok(['review_requested'=>1]);
  }

  // ── ADMIN: LIST ALL ───────────────────────────────────
  case 'admin_list': {
    requireAdmin();
    $status = $_GET['status'] ?? '';
    $city   = $_GET['city']   ?? '';
    $limit  = min(500, max(1, (int)($_GET['limit'] ?? 100)));

    $sql    = "SELECT * FROM bookings WHERE 1=1";
    $params = [];
    if (!empty($status)) { $sql .= " AND status = ?";  $params[] = $status; }
    if (!empty($city))   { $sql .= " AND city LIKE ?"; $params[] = "%$city%"; }
    $sql .= " ORDER BY created_at DESC LIMIT $limit";

    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    ok(array_map(fn($r) => sanitizeBooking($r, 'admin'), $stmt->fetchAll()));
  }

  // ── ADMIN: FINANCE SUMMARY ────────────────────────────
  case 'admin_finance': {
    requireAdmin();
    $row = $db->query("
      SELECT COUNT(*) AS paid_jobs,
             COALESCE(SUM(amount),0) AS gmv,
             COALESCE(SUM(commission_amt),0) AS commission,
             COALESCE(SUM(CASE WHEN payment_method='online' THEN amount END),0) AS online_collected,
             COALESCE(SUM(CASE WHEN payment_method='cash' THEN commission_amt END),0) AS cash_commission_due,
             COALESCE(SUM(provider_earns),0) AS provider_earnings
      FROM bookings WHERE payment_status = 'paid'
    ")->fetch();
    $row['unpaid_completed'] = (int)$db->query("SELECT COUNT(*) FROM bookings WHERE status='completed' AND payment_status != 'paid'")->fetchColumn();
    $row['provider_balances'] = (int)$db->query("SELECT COALESCE(SUM(GREATEST(pending_earned,0)),0) FROM providers")->fetchColumn();
    $row['provider_dues']     = (int)$db->query("SELECT COALESCE(SUM(LEAST(pending_earned,0)),0) FROM providers")->fetchColumn();
    $row['payments_mode'] = strpos(RZP_KEY_ID, 'rzp_live_') === 0 ? 'live' : (RZP_KEY_ID === '' ? 'not configured' : 'test');
    ok($row);
  }

  default:
    err('Invalid action');
}

function haversineKm($lat1, $lng1, $lat2, $lng2) {
    $r = 6371;
    $dLat = deg2rad($lat2 - $lat1);
    $dLng = deg2rad($lng2 - $lng1);
    $a = sin($dLat/2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng/2) ** 2;
    return $r * 2 * atan2(sqrt($a), sqrt(1 - $a));
}
