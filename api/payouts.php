<?php
// ═══════════════════════════════════════════════════════
// HamaraService — Payouts API
// Wallet model (providers.pending_earned = withdrawable balance):
//   + provider_earns for every ONLINE-paid job
//   − commission_amt for every CASH-paid job (provider holds the cash)
//   − amount held when a withdrawal is requested (returned if rejected)
// A negative balance = commission the provider owes the platform.
//
// Endpoints:
//   POST ?action=request      — provider requests withdrawal
//   GET  ?action=balance      — provider's own balance (admin: &id=X)
//   GET  ?action=history      — provider's own payouts (admin: &id=X)
//   POST ?action=approve      — admin approves payout (after transferring)
//   POST ?action=reject       — admin rejects payout (balance returned)
//   GET  ?action=pending      — admin: pending payouts
//   GET  ?action=all          — admin: all payouts
// ═══════════════════════════════════════════════════════
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/schema.php';
setCorsHeaders();

$action = $_GET['action'] ?? '';
$db     = getDB();
ensureSchema($db);

// Provider sees own data; admin may pass ?id=
function payoutSubject() {
    if (isAdminRequest()) {
        $id = $_GET['id'] ?? '';
        if ($id === '') err('id required');
        return $id;
    }
    return requireProvider()['id'];
}

switch ($action) {

  case 'request': {
    $prov = requireProvider(true);
    $b    = getBody();

    $amount = (int)($b['amount'] ?? 0);
    if ($amount < 100) err('Minimum withdrawal is ₹100');

    $type = ($b['account_type'] ?? 'upi') === 'bank' ? 'bank' : 'upi';
    $upi  = trim($b['upi_id'] ?? '');
    $acct = preg_replace('/\s/', '', $b['account_no'] ?? '');
    $ifsc = strtoupper(trim($b['ifsc'] ?? ''));
    $bank = trim($b['bank_name'] ?? '');
    if ($type === 'upi' && !preg_match('/^[\w.\-]{2,}@[a-zA-Z]{2,}$/', $upi)) err('Enter a valid UPI ID');
    if ($type === 'bank') {
      if (!preg_match('/^\d{6,18}$/', $acct)) err('Enter a valid account number');
      if (!preg_match('/^[A-Z]{4}0[A-Z0-9]{6}$/', $ifsc)) err('Enter a valid IFSC code');
    }

    $db->beginTransaction();
    // Hold the amount atomically — fails if balance is insufficient
    $hold = $db->prepare("UPDATE providers SET pending_earned = pending_earned - ? WHERE id = ? AND pending_earned >= ?");
    $hold->execute([$amount, $prov['id'], $amount]);
    if ($hold->rowCount() !== 1) {
      $db->rollBack();
      $bal = $db->prepare("SELECT pending_earned FROM providers WHERE id = ?");
      $bal->execute([$prov['id']]);
      err('Insufficient balance. Available: ₹' . max(0, (int)$bal->fetchColumn()));
    }
    $db->prepare("
      INSERT INTO payouts (provider_id, amount, account_type, upi_id, bank_name, account_no, ifsc, status)
      VALUES (?, ?, ?, ?, ?, ?, ?, 'pending')
    ")->execute([$prov['id'], $amount, $type, $upi, $bank, $acct, $ifsc]);
    $payoutId = $db->lastInsertId();
    $db->commit();

    ok(['id' => $payoutId, 'status' => 'pending', 'amount' => $amount]);
  }

  case 'balance': {
    $id = payoutSubject();
    $stmt = $db->prepare("SELECT total_earned, pending_earned, completed_bookings FROM providers WHERE id = ?");
    $stmt->execute([$id]);
    $data = $stmt->fetch();
    if (!$data) err('Provider not found', 404);

    $w = $db->prepare("SELECT
        COALESCE(SUM(CASE WHEN status='approved' THEN amount END),0) AS withdrawn,
        COALESCE(SUM(CASE WHEN status='pending'  THEN amount END),0) AS in_process
      FROM payouts WHERE provider_id = ?");
    $w->execute([$id]);
    $wd = $w->fetch();

    $bal = (int)$data['pending_earned'];
    ok([
      'total_earned'       => (int)$data['total_earned'],
      'pending_earned'     => $bal,                 // raw wallet (may be negative)
      'available'          => max(0, $bal),         // withdrawable now
      'commission_due'     => max(0, -$bal),        // owed to platform from cash jobs
      'withdrawn'          => (int)$wd['withdrawn'],
      'in_process'         => (int)$wd['in_process'],
      'completed_bookings' => (int)$data['completed_bookings'],
    ]);
  }

  case 'history': {
    $id = payoutSubject();
    $stmt = $db->prepare("
      SELECT id, amount, account_type, upi_id, bank_name, RIGHT(account_no, 4) AS account_last4, ifsc,
             status, requested_at, processed_at, note
      FROM payouts WHERE provider_id = ?
      ORDER BY requested_at DESC LIMIT 50
    ");
    $stmt->execute([$id]);
    ok($stmt->fetchAll());
  }

  case 'approve': {
    requireAdmin();
    $b  = getBody();
    $id = $b['id'] ?? '';
    if (empty($id)) err('id required');

    $upd = $db->prepare("UPDATE payouts SET status = 'approved', note = ?, processed_at = CURRENT_TIMESTAMP WHERE id = ? AND status = 'pending'");
    $upd->execute([trim($b['note'] ?? ''), $id]);
    if ($upd->rowCount() !== 1) err('Payout not found or already processed', 409);

    $stmt = $db->prepare("SELECT p.*, pr.fcm_token FROM payouts p LEFT JOIN providers pr ON pr.id = p.provider_id WHERE p.id = ?");
    $stmt->execute([$id]);
    $payout = $stmt->fetch();
    sendPush($payout['fcm_token'] ?? '', 'Payout Approved ✅', "₹{$payout['amount']} has been transferred to your account.",
      ['event'=>'payout_approved','amount'=>(string)$payout['amount']]);

    ok(['approved' => true, 'amount' => (int)$payout['amount']]);
  }

  case 'reject': {
    requireAdmin();
    $b  = getBody();
    $id = $b['id'] ?? '';
    if (empty($id)) err('id required');

    $stmt = $db->prepare("SELECT * FROM payouts WHERE id = ?");
    $stmt->execute([$id]);
    $payout = $stmt->fetch();
    if (!$payout) err('Payout not found', 404);

    $db->beginTransaction();
    $upd = $db->prepare("UPDATE payouts SET status = 'rejected', note = ?, processed_at = CURRENT_TIMESTAMP WHERE id = ? AND status = 'pending'");
    $upd->execute([trim($b['note'] ?? ''), $id]);
    if ($upd->rowCount() !== 1) { $db->rollBack(); err('Already processed', 409); }
    $db->prepare("UPDATE providers SET pending_earned = pending_earned + ? WHERE id = ?")
       ->execute([$payout['amount'], $payout['provider_id']]);
    $db->commit();

    ok(['rejected' => true]);
  }

  case 'pending':
  case 'all': {
    requireAdmin();
    $where = $action === 'pending' ? "WHERE p.status = 'pending'" : '';
    $stmt = $db->query("
      SELECT p.*, pr.name AS provider_name, pr.phone AS provider_phone, pr.pending_earned AS provider_balance
      FROM payouts p
      LEFT JOIN providers pr ON pr.id = p.provider_id
      $where
      ORDER BY p.requested_at DESC
      LIMIT 500
    ");
    ok($stmt->fetchAll());
  }

  default:
    err('Invalid action');
}
