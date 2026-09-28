<?php
// ═══════════════════════════════════════════════════════
// HamaraService — Reviews API
// POST ?action=submit  — customer submits review
// GET  ?action=provider&id=X — get provider reviews
// ═══════════════════════════════════════════════════════
require_once __DIR__ . "/db.php";
setCorsHeaders();

$action = $_GET["action"] ?? "";
$db     = getDB();

runMigrationsOnce($db, "reviews_v1", [
  "ALTER TABLE bookings ADD COLUMN rating INT NULL",
  "ALTER TABLE bookings ADD COLUMN review TEXT NULL",
  // one review per booking (silently skipped if legacy duplicates exist)
  "ALTER TABLE reviews ADD UNIQUE KEY uniq_review_booking (booking_id)",
]);

switch ($action) {

  case "submit": {
    $user = requireCustomer();
    $b    = getBody();
    $bookingId  = $b["booking_id"]  ?? "";
    $rating     = (int)($b["rating"] ?? 0);
    $comment    = trim((string)($b["comment"] ?? ""));

    if (empty($bookingId)) err("booking_id required");
    if ($rating < 1 || $rating > 5) err("rating must be 1-5");

    // Booking must belong to this customer and be completed; the provider
    // comes from the booking, never from the client.
    $stmt = $db->prepare("SELECT * FROM bookings WHERE id = ? AND customer_id = ?");
    $stmt->execute([$bookingId, $user["uid"]]);
    $bk = $stmt->fetch();
    if (!$bk) err("Booking not found", 404);
    if ($bk["status"] !== "completed") err("Booking not completed yet");
    $providerId = $bk["provider_id"] ?? "";
    if (empty($providerId)) err("Booking has no provider");
    if (!empty($b["provider_id"]) && (string)$b["provider_id"] !== (string)$providerId) err("provider_id does not match booking");

    // One review per booking
    $dup = $db->prepare("SELECT id FROM reviews WHERE booking_id = ? LIMIT 1");
    $dup->execute([$bookingId]);
    if ($dup->fetch() || !empty($bk["rating"])) err("Already reviewed", 409);

    $db->beginTransaction();
    // Claim the booking's rating slot first (guards against concurrent submits)
    $claim = $db->prepare("UPDATE bookings SET rating = ?, review = ? WHERE id = ? AND rating IS NULL");
    $claim->execute([$rating, $comment, $bookingId]);
    if ($claim->rowCount() === 0) { $db->rollBack(); err("Already reviewed", 409); }

    // Save review
    $db->prepare("
      INSERT INTO reviews (booking_id, customer_id, provider_id, rating, comment)
      VALUES (?, ?, ?, ?, ?)
    ")->execute([$bookingId, $user["uid"], $providerId, $rating, $comment]);
    $db->commit();

    // Recalculate provider average rating
    // (booking status is left as 'completed' — setting it to 'reviewed' made the
    //  booking look "active" and blocked the customer from booking again)
    $newAvg = recomputeProviderRating($db, $providerId);

    ok(["submitted" => true, "new_avg" => $newAvg]);
  }

  case "provider": {
    $id = $_GET["id"] ?? "";
    if (empty($id)) err("id required");

    $stmt = $db->prepare("
      SELECT r.rating, r.comment, r.created_at,
             c.name as customer_name
      FROM reviews r
      LEFT JOIN customers c ON c.id = r.customer_id
      WHERE r.provider_id = ?
      ORDER BY r.created_at DESC
      LIMIT 50
    ");
    $stmt->execute([$id]);
    ok($stmt->fetchAll());
  }

  default:
    err("Invalid action");
}
