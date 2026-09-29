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
//   POST ?action=cancel      — provider cancels own pending request {payout_id}
// ═══════════════════════════════════════════════════════
require_once __DIR__ . '/db.php';
setCorsHeaders();

$action = $_GET['action'] ?? '';
$db     = getDB();

// balance/history: the provider themself (id defaults to theirs) or admin (?id= required)
function payoutSubject() {
  $id = $_GET['id'] ?? '';
  if (isAdminRequest()) {
    if (empty($id)) err('id required');
    return $id;
  }
  $prov = requireProvider();
  if (!empty($id) && (string)$id !== (string)$prov['id']) err('Forbidden', 403);
  return $prov['id'];
}

switch ($action) {

  // ── REQUEST WITHDRAWAL ────────────────────────────────
  case 'request': {
    $prov = requireProvider();
    $b    = getBody();

    $amount = (int)($b['amount'] ?? 0);
    if ($amount < 100) err('Minimum withdrawal is ₹100');

    // Atomic: lock the provider row, check and deduct in one transaction so
    // concurrent requests can never withdraw more than the balance.
    $db->beginTransaction();
    $stmt = $db->prepare("SELECT pending_earned FROM providers WHERE id = ? FOR UPDATE");
    $stmt->execute([$prov['id']]);
    $balance = (int)$stmt->fetchColumn();

    if ($amount > $balance) { $db->rollBack(); err("Insufficient balance. Available: ₹$balance"); }

    // Hold amount — deduct from pending (guarded)
    $hold = $db->prepare("UPDATE providers SET pending_earned = pending_earned - ? WHERE id = ? AND pending_earned >= ?");
    $hold->execute([$amount, $prov['id'], $amount]);
    if ($hold->rowCount() === 0) { $db->rollBack(); err('Insufficient balance'); }

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
    $db->commit();

    ok(['id' => $payoutId, 'status' => 'pending', 'amount' => $amount]);
  }

  // ── PROVIDER BALANCE ──────────────────────────────────
  case 'balance': {
    $id = payoutSubject();

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
    $id = payoutSubject();

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

    $u = $db->prepare("
      UPDATE payouts SET status = 'approved', processed_at = CURRENT_TIMESTAMP
      WHERE id = ? AND status = 'pending'
    ");
    $u->execute([$id]);
    if ($u->rowCount() === 0) err('Already processed');

    // Notify provider
    $fcm = '';
    $pStmt = $db->prepare("SELECT fcm_token FROM providers WHERE id = ?");
    $pStmt->execute([$payout['provider_id']]);
    $fcm = $pStmt->fetchColumn() ?: '';

    if ($fcm) {
      $amount = $payout['amount'];
      hsSendPush($fcm, 'Payout Approved ✅', "₹$amount has been transferred to your account.",
        ['event' => 'payout_approved', 'amount' => "$amount"]);
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

    // Status flip + refund in one transaction; the status guard makes a
    // double-reject unable to refund twice.
    $db->beginTransaction();
    $u = $db->prepare("
      UPDATE payouts SET status = 'rejected', note = ?, processed_at = CURRENT_TIMESTAMP
      WHERE id = ? AND status = 'pending'
    ");
    $u->execute([$note, $id]);
    if ($u->rowCount() === 0) { $db->rollBack(); err('Already processed'); }

    // Refund balance
    $db->prepare("
      UPDATE providers SET pending_earned = pending_earned + ? WHERE id = ?
    ")->execute([$payout['amount'], $payout['provider_id']]);
    $db->commit();

    ok(['rejected' => true]);
  }

  // ── PROVIDER: CANCEL OWN PENDING REQUEST ────────────
  // Status flip + refund of the held amount in one transaction; the status
  // guard makes a double-cancel (or cancel racing approve) unable to refund twice.
  case 'cancel': {
    $prov = requireProvider();
    $b  = getBody();
    $id = (int)($b['payout_id'] ?? 0);
    if ($id <= 0) err('payout_id required');

    $db->beginTransaction();
    $stmt = $db->prepare("SELECT * FROM payouts WHERE id = ? AND provider_id = ? FOR UPDATE");
    $stmt->execute([$id, $prov['id']]);
    $payout = $stmt->fetch();
    if (!$payout) { $db->rollBack(); err('Payout not found', 404); }
    if ($payout['status'] !== 'pending') { $db->rollBack(); err('Only pending payouts can be cancelled'); }

    $u = $db->prepare("UPDATE payouts SET status = 'cancelled', processed_at = CURRENT_TIMESTAMP
                       WHERE id = ? AND status = 'pending'");
    $u->execute([$id]);
    if ($u->rowCount() === 0) { $db->rollBack(); err('Only pending payouts can be cancelled'); }
    $db->prepare("UPDATE providers SET pending_earned = pending_earned + ? WHERE id = ?")
       ->execute([(int)$payout['amount'], $prov['id']]);
    $db->commit();

    $bal = $db->prepare("SELECT pending_earned FROM providers WHERE id = ?");
    $bal->execute([$prov['id']]);
    ok(['cancelled' => true, 'id' => $id, 'amount' => (int)$payout['amount'],
        'pending_earned' => (int)$bal->fetchColumn()]);
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
