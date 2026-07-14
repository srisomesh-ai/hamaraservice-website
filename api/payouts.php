<?php
// ═══════════════════════════════════════════════════════
// HamaraService — Payouts API
// Endpoints:
//   POST ?action=request     — provider requests withdrawal
//   GET  ?action=history&id=X — provider payout history
//   GET  ?action=balance&id=X — provider balance
//   POST ?action=approve     — admin approves payout
//   POST ?action=reject      — admin rejects payout
//   GET  ?action=pending     — admin: all pending payouts
// ═══════════════════════════════════════════════════════
require_once __DIR__ . '/db.php';
setCorsHeaders();

$action = $_GET['action'] ?? '';
$db     = getDB();

switch ($action) {

  // ── REQUEST WITHDRAWAL ────────────────────────────────
  case 'request': {
    $prov = requireProvider();
    $b    = getBody();

    $amount = (int)($b['amount'] ?? 0);
    if ($amount < 100) err('Minimum withdrawal is ₹100');

    // Check balance
    $stmt = $db->prepare("SELECT pending_earned FROM providers WHERE id = ?");
    $stmt->execute([$prov['id']]);
    $balance = (int)$stmt->fetchColumn();

    if ($amount > $balance) err("Insufficient balance. Available: ₹$balance");

    // Create payout request
    $stmt = $db->prepare("
      INSERT INTO payouts
        (provider_id, amount, account_type, upi_id,
         bank_name, account_no, ifsc, status)
      VALUES (?, ?, ?, ?, ?, ?, ?, 'pending')
    ");
    $stmt->execute([
      $prov['id'],
      $amount,
      $b['account_type'] ?? 'upi',
      $b['upi_id']       ?? '',
      $b['bank_name']    ?? '',
      $b['account_no']   ?? '',
      $b['ifsc']         ?? '',
    ]);

    $payoutId = $db->lastInsertId();

    // Hold amount — deduct from pending
    $db->prepare("UPDATE providers SET pending_earned = pending_earned - ? WHERE id = ?")
       ->execute([$amount, $prov['id']]);

    ok(['id' => $payoutId, 'status' => 'pending', 'amount' => $amount]);
  }

  // ── PROVIDER BALANCE ──────────────────────────────────
  case 'balance': {
    $id = $_GET['id'] ?? '';
    if (empty($id)) err('id required');

    $stmt = $db->prepare("
      SELECT total_earned, pending_earned, completed_bookings
      FROM providers WHERE id = ?
    ");
    $stmt->execute([$id]);
    $data = $stmt->fetch();
    if (!$data) err('Provider not found', 404);

    // Total withdrawn
    $wStmt = $db->prepare("
      SELECT COALESCE(SUM(amount),0) as withdrawn
      FROM payouts WHERE provider_id = ? AND status = 'approved'
    ");
    $wStmt->execute([$id]);
    $withdrawn = (int)$wStmt->fetchColumn();

    ok([
      'total_earned'       => (int)$data['total_earned'],
      'pending_earned'     => (int)$data['pending_earned'],
      'withdrawn'          => $withdrawn,
      'completed_bookings' => (int)$data['completed_bookings'],
    ]);
  }

  // ── PAYOUT HISTORY ────────────────────────────────────
  case 'history': {
    $id = $_GET['id'] ?? '';
    if (empty($id)) err('id required');

    $stmt = $db->prepare("
      SELECT id, amount, account_type, upi_id, status,
             requested_at, processed_at, note
      FROM payouts
      WHERE provider_id = ?
      ORDER BY requested_at DESC
      LIMIT 50
    ");
    $stmt->execute([$id]);
    ok($stmt->fetchAll());
  }

  // ── ADMIN: APPROVE ────────────────────────────────────
  case 'approve': {
    requireAdmin();
    $b  = getBody();
    $id = $b['id'] ?? '';
    if (empty($id)) err('id required');

    $stmt = $db->prepare("SELECT * FROM payouts WHERE id = ?");
    $stmt->execute([$id]);
    $payout = $stmt->fetch();
    if (!$payout) err('Payout not found');
    if ($payout['status'] !== 'pending') err('Already processed');

    $db->prepare("
      UPDATE payouts SET status = 'approved', processed_at = CURRENT_TIMESTAMP
      WHERE id = ?
    ")->execute([$id]);

    // Notify provider
    $fcm = '';
    $pStmt = $db->prepare("SELECT fcm_token FROM providers WHERE id = ?");
    $pStmt->execute([$payout['provider_id']]);
    $fcm = $pStmt->fetchColumn() ?: '';

    if ($fcm) {
      $amount = $payout['amount'];
      $ctx = stream_context_create(['http'=>[
        'method'  => 'POST',
        'header'  => "Content-Type: application/json
",
        'content' => json_encode([
          'event'    => 'payout_approved',
          'fcmToken' => $fcm,
          'data'     => [
            'title'  => 'Payout Approved ✅',
            'body'   => "₹$amount has been transferred to your account.",
            'amount' => "$amount",
          ],
        ]),
        'timeout' => 5,
      ]]);
      @file_get_contents('https://notifybooking-mlchyp6tra-as.a.run.app', false, $ctx);
    }

    ok(['approved' => true, 'amount' => $payout['amount'],
        'upi' => $payout['upi_id'], 'bank' => $payout['bank_name']]);
  }

  // ── ADMIN: REJECT ─────────────────────────────────────
  case 'reject': {
    requireAdmin();
    $b  = getBody();
    $id = $b['id'] ?? '';
    $note = $b['note'] ?? '';
    if (empty($id)) err('id required');

    $stmt = $db->prepare("SELECT * FROM payouts WHERE id = ?");
    $stmt->execute([$id]);
    $payout = $stmt->fetch();
    if (!$payout) err('Payout not found');
    if ($payout['status'] !== 'pending') err('Already processed');

    $db->prepare("
      UPDATE payouts SET status = 'rejected', note = ?, processed_at = CURRENT_TIMESTAMP
      WHERE id = ?
    ")->execute([$note, $id]);

    // Refund balance
    $db->prepare("
      UPDATE providers SET pending_earned = pending_earned + ? WHERE id = ?
    ")->execute([$payout['amount'], $payout['provider_id']]);

    ok(['rejected' => true]);
  }

  // ── ADMIN: PENDING LIST ───────────────────────────────
  case 'pending': {
    requireAdmin();
    $stmt = $db->query("
      SELECT p.*, pr.name as provider_name, pr.phone as provider_phone
      FROM payouts p
      LEFT JOIN providers pr ON pr.id = p.provider_id
      WHERE p.status = 'pending'
      ORDER BY p.requested_at ASC
    ");
    ok($stmt->fetchAll());
  }

  default:
    err('Invalid action');
}
