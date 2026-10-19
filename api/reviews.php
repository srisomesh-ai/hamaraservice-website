<?php
// ═══════════════════════════════════════════════════════
// HamaraService — Reviews API
// POST ?action=submit        — customer reviews a completed booking
//                              (same logic as bookings.php?action=review)
// GET  ?action=provider&id=X — public reviews of a provider
//                              (provider app may omit id → own reviews)
// ═══════════════════════════════════════════════════════
require_once __DIR__ . "/db.php";
require_once __DIR__ . "/schema.php";
setCorsHeaders();

$action = $_GET["action"] ?? "";
$db     = getDB();
ensureSchema($db);

switch ($action) {

  case "submit": {
    $user = requireCustomer();
    $b    = getBody();
    $bookingId = $b["booking_id"] ?? "";
    $rating    = (int)($b["rating"] ?? 0);
    $comment   = trim($b["comment"] ?? $b["review"] ?? "");
    if (empty($bookingId)) err("booking_id required");
    if ($rating < 1 || $rating > 5) err("rating must be 1-5");

    $stmt = $db->prepare("SELECT * FROM bookings WHERE id = ? AND customer_id = ?");
    $stmt->execute([$bookingId, $user["id"]]);
    $bk = $stmt->fetch();
    if (!$bk) err("Booking not found", 404);
    if ($bk["status"] !== "completed") err("Booking not completed yet");
    if (!empty($bk["rating"])) err("Already reviewed", 409);
    if (empty($bk["provider_id"])) err("No provider on this booking");

    $db->prepare("UPDATE bookings SET rating = ?, review = ? WHERE id = ?")
       ->execute([$rating, $comment, $bookingId]);
    $db->prepare("
      INSERT INTO reviews (booking_id, customer_id, provider_id, rating, comment)
      VALUES (?, ?, ?, ?, ?)
      ON DUPLICATE KEY UPDATE rating = VALUES(rating), comment = VALUES(comment)
    ")->execute([$bookingId, $user["id"], $bk["provider_id"], $rating, $comment]);

    $avg = $db->prepare("SELECT AVG(rating) AS a, COUNT(*) AS c FROM reviews WHERE provider_id = ?");
    $avg->execute([$bk["provider_id"]]);
    $row = $avg->fetch();
    $db->prepare("UPDATE providers SET rating = ?, review_count = ? WHERE id = ?")
       ->execute([round((float)$row["a"], 1), (int)$row["c"], $bk["provider_id"]]);

    ok(["submitted" => true, "new_avg" => round((float)$row["a"], 1)]);
  }

  case "provider": {
    $id = $_GET["id"] ?? "";
    if ($id === "") {
      $me = currentUser();
      if ($me && $me["role"] === "provider") $id = $me["id"];
    }
    if (empty($id)) err("id required");

    $stmt = $db->prepare("
      SELECT r.booking_id, r.rating, r.comment, r.created_at,
             c.name AS customer_name, b.svc_name
      FROM reviews r
      LEFT JOIN customers c ON c.id = r.customer_id
      LEFT JOIN bookings  b ON b.id = r.booking_id
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
