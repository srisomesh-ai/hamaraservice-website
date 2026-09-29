<?php
// ═══════════════════════════════════════════════════════
// HamaraService — Provider penalties
// Endpoints:
//   GET  ?action=list[&provider_id=X] — admin (all / one provider) or
//                                       provider (own penalties only)
//   POST ?action=create {provider_id, booking_id?, amount, reason} — admin
//        → status 'applied', amount deducted from providers.pending_earned
//          (the balance may go negative)
//   POST ?action=waive {id} — admin; refunds the amount if it was applied
// ═══════════════════════════════════════════════════════
require_once __DIR__ . '/db.php';
setCorsHeaders();

$action = $_GET['action'] ?? '';
$db     = getDB();

function penaltyRow($r) {
  $r['id']     = (int)$r['id'];
  $r['amount'] = (int)$r['amount'];
  return $r;
}

switch ($action) {

  case 'list': {
    $pid = (string)($_GET['provider_id'] ?? '');
    if (!isAdminRequest()) {
      $prov = requireProvider();
      if ($pid !== '' && $pid !== (string)$prov['id']) err('Forbidden', 403);
      $pid = (string)$prov['id'];
    }
    $sql = "SELECT pe.id, pe.provider_id, pr.name AS provider_name, pe.booking_id, pe.amount,
                   pe.reason, pe.status, pe.created_at, pe.waived_at
            FROM penalties pe
            LEFT JOIN providers pr ON pr.id = pe.provider_id";
    $params = [];
    if ($pid !== '') { $sql .= " WHERE pe.provider_id = ?"; $params[] = $pid; }
    $sql .= " ORDER BY pe.created_at DESC, pe.id DESC LIMIT 500";
    $st = $db->prepare($sql);
    $st->execute($params);
    ok(array_map('penaltyRow', $st->fetchAll()));
  }

  case 'create': {
    requireAdmin();
    $b      = getBody();
    $pid    = trim((string)($b['provider_id'] ?? ''));
    $bkId   = trim((string)($b['booking_id'] ?? ''));
    $amount = (int)($b['amount'] ?? 0);
    $reason = trim(str_replace(["\r", "\n", "\0"], ' ', (string)($b['reason'] ?? '')));
    if ($pid === '') err('provider_id required');
    if ($amount <= 0) err('amount must be > 0');
    if ($reason === '') err('reason required');
    $reason = function_exists('mb_substr') ? mb_substr($reason, 0, 255) : substr($reason, 0, 255);

    $p = $db->prepare("SELECT id, fcm_token FROM providers WHERE id = ?");
    $p->execute([$pid]);
    $prov = $p->fetch();
    if (!$prov) err('Provider not found', 404);
    if ($bkId !== '') {
      $q = $db->prepare("SELECT provider_id FROM bookings WHERE id = ?");
      $q->execute([$bkId]);
      $bp = $q->fetchColumn();
      if ($bp === false) err('Booking not found', 404);
      if ((string)$bp !== $pid) err('Booking is not assigned to this provider');
    }

    $db->beginTransaction();
    $db->prepare("INSERT INTO penalties (provider_id, booking_id, amount, reason, status)
                  VALUES (?, ?, ?, ?, 'applied')")
       ->execute([$pid, $bkId !== '' ? $bkId : null, $amount, $reason]);
    $id = (int)$db->lastInsertId();
    $db->prepare("UPDATE providers SET pending_earned = pending_earned - ? WHERE id = ?")
       ->execute([$amount, $pid]);
    $db->commit();

    hsSendPush($prov['fcm_token'] ?? '', 'Penalty Applied',
      "A penalty of ₹$amount was applied: $reason", ['event' => 'penalty_applied', 'amount' => "$amount"]);

    ok(['id' => $id, 'status' => 'applied', 'provider_id' => $pid, 'amount' => $amount]);
  }

  case 'waive': {
    requireAdmin();
    $b  = getBody();
    $id = (int)($b['id'] ?? 0);
    if ($id <= 0) err('id required');

    $db->beginTransaction();
    $st = $db->prepare("SELECT * FROM penalties WHERE id = ? FOR UPDATE");
    $st->execute([$id]);
    $pen = $st->fetch();
    if (!$pen) { $db->rollBack(); err('Penalty not found', 404); }
    if ($pen['status'] === 'waived') { $db->rollBack(); ok(['waived' => true, 'id' => $id, 'refunded' => 0]); }

    $u = $db->prepare("UPDATE penalties SET status = 'waived', waived_at = CURRENT_TIMESTAMP
                       WHERE id = ? AND status = 'applied'");
    $u->execute([$id]);
    $refund = 0;
    if ($u->rowCount() > 0) {
      $refund = (int)$pen['amount'];
      $db->prepare("UPDATE providers SET pending_earned = pending_earned + ? WHERE id = ?")
         ->execute([$refund, $pen['provider_id']]);
    }
    $db->commit();
    ok(['waived' => true, 'id' => $id, 'refunded' => $refund]);
  }

  default:
    err('Invalid action');
}
