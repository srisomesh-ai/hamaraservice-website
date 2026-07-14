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

switch ($action) {

  case "submit": {
    $user = requireCustomer();
    $b    = getBody();
    $bookingId  = $b["booking_id"]  ?? "";
    $providerId = $b["provider_id"] ?? "";
    $rating     = (int)($b["rating"] ?? 0);
    $comment    = $b["comment"] ?? "";

    if (empty($bookingId) || empty($providerId)) err("booking_id and provider_id required");
    if ($rating < 1 || $rating > 5) err("rating must be 1-5");

    // Save review
    $stmt = $db->prepare("
      INSERT INTO reviews (booking_id, customer_id, provider_id, rating, comment)
      VALUES (?, ?, ?, ?, ?)
      ON DUPLICATE KEY UPDATE rating=VALUES(rating), comment=VALUES(comment)
    ");
    $stmt->execute([$bookingId, $user["uid"], $providerId, $rating, $comment]);

    // Mark booking as rated
    $db->prepare("UPDATE bookings SET status = "reviewed" WHERE id = ?")
       ->execute([$bookingId]);

    // Recalculate provider average rating
    $avg = $db->prepare("
      SELECT AVG(rating) as avg_rating, COUNT(*) as total
      FROM reviews WHERE provider_id = ?
    ");
    $avg->execute([$providerId]);
    $row = $avg->fetch();

    $db->prepare("UPDATE providers SET rating = ?, review_count = ? WHERE id = ?")
       ->execute([
         round($row["avg_rating"], 1),
         $row["total"],
         $providerId
       ]);

    ok(["submitted" => true, "new_avg" => round($row["avg_rating"], 1)]);
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
