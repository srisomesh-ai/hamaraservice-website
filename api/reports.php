<?php
// ═══════════════════════════════════════════════════════
// HamaraService — Admin reports (X-Admin-Key)
// Endpoints:
//   GET ?action=balance_sheet[&from=YYYY-MM-DD&to=YYYY-MM-DD]
//       rows     — completed bookings that are paid (payment_status 'paid',
//                  or cash claimed: payment_status 'cash_pending'), by completed_at
//       payouts  — payout requests in the range (by requested_at)
//       totals   — gmv, commission, provider_earnings (from rows);
//                  paid_out (approved) / pending_payouts (pending) from payouts;
//                  penalties (applied penalties created in the range)
//   GET ?action=summary — dashboard counts
// ═══════════════════════════════════════════════════════
require_once __DIR__ . '/db.php';
setCorsHeaders();

$action = $_GET['action'] ?? '';
$db     = getDB();
requireAdmin();

function reportDate($key) {
  $v = trim((string)($_GET[$key] ?? ''));
  if ($v === '') return null;
  $d = DateTime::createFromFormat('!Y-m-d', $v);
  if (!$d || $d->format('Y-m-d') !== $v) err("$key must be YYYY-MM-DD");
  return $v;
}
// [sqlCondition, params] for a DATETIME column restricted to [from, to] (inclusive days)
function dateRange($col, $from, $to) {
  $c = []; $p = [];
  if ($from !== null) { $c[] = "$col >= ?"; $p[] = "$from 00:00:00"; }
  if ($to   !== null) { $c[] = "$col < (? + INTERVAL 1 DAY)"; $p[] = "$to 00:00:00"; }
  return [$c ? ' AND ' . implode(' AND ', $c) : '', $p];
}

switch ($action) {

  case 'balance_sheet': {
    $from = reportDate('from');
    $to   = reportDate('to');
    if ($from !== null && $to !== null && $from > $to) err('from must be on or before to');

    [$bc, $bp] = dateRange('b.completed_at', $from, $to);
    $st = $db->prepare("
      SELECT DATE(b.completed_at) AS date, b.id AS booking_id, b.provider_id,
             COALESCE(NULLIF(b.provider_name, ''), p.name) AS provider_name,
             b.customer_name, b.svc_name AS service,
             IF(b.confirmed_price > 0, b.confirmed_price, b.amount) AS amount,
             b.commission_amt AS commission, b.provider_earns,
             b.payment_method, b.payment_status
      FROM bookings b
      LEFT JOIN providers p ON p.id = b.provider_id
      WHERE b.status = 'completed'
        AND (b.payment_status = 'paid' OR b.payment_status = 'cash_pending')
        $bc
      ORDER BY b.completed_at ASC, b.id ASC
    ");
    $st->execute($bp);
    $rows = [];
    $gmv = 0; $comm = 0; $earn = 0;
    foreach ($st->fetchAll() as $r) {
      $r['amount']         = (int)$r['amount'];
      $r['commission']     = (int)$r['commission'];
      $r['provider_earns'] = (int)$r['provider_earns'];
      if (($r['payment_method'] ?? '') === '' || $r['payment_method'] === null)
        $r['payment_method'] = $r['payment_status'] === 'cash_pending' ? 'cash' : '';
      $gmv += $r['amount']; $comm += $r['commission']; $earn += $r['provider_earns'];
      $rows[] = $r;
    }

    [$pc, $pp] = dateRange('po.requested_at', $from, $to);
    $st = $db->prepare("
      SELECT po.id, po.provider_id, pr.name AS provider_name, po.amount, po.status,
             po.account_type, po.upi_id, po.bank_name, po.requested_at, po.processed_at, po.note
      FROM payouts po
      LEFT JOIN providers pr ON pr.id = po.provider_id
      WHERE 1=1 $pc
      ORDER BY po.requested_at ASC, po.id ASC
    ");
    $st->execute($pp);
    $payouts = [];
    $paidOut = 0; $pendingPay = 0;
    foreach ($st->fetchAll() as $r) {
      $r['id'] = (int)$r['id']; $r['amount'] = (int)$r['amount'];
      if ($r['status'] === 'approved') $paidOut += $r['amount'];
      if ($r['status'] === 'pending')  $pendingPay += $r['amount'];
      $payouts[] = $r;
    }

    [$nc, $np] = dateRange('created_at', $from, $to);
    $st = $db->prepare("SELECT COALESCE(SUM(amount), 0) FROM penalties WHERE status = 'applied' $nc");
    $st->execute($np);
    $penalties = (int)$st->fetchColumn();

    ok([
      'from'    => $from,
      'to'      => $to,
      'rows'    => $rows,
      'payouts' => $payouts,
      'totals'  => [
        'gmv'               => $gmv,
        'commission'        => $comm,
        'provider_earnings' => $earn,
        'paid_out'          => $paidOut,
        'pending_payouts'   => $pendingPay,
        'penalties'         => $penalties,
      ],
    ]);
  }

  case 'summary': {
    $one = function ($sql) use ($db) { return (int)$db->query($sql)->fetchColumn(); };
    $paid = "status = 'completed' AND payment_status IN ('paid','cash_pending')
             AND completed_at >= DATE_FORMAT(CURDATE(), '%Y-%m-01')";
    ok([
      'providers_total'   => $one("SELECT COUNT(*) FROM providers WHERE status != 'deleted'"),
      'providers_pending' => $one("SELECT COUNT(*) FROM providers WHERE status = 'pending'"),
      'customers'         => $one("SELECT COUNT(*) FROM customers WHERE status != 'deleted'"),
      'bookings_today'    => $one("SELECT COUNT(*) FROM bookings WHERE created_at >= CURDATE()"),
      'bookings_active'   => $one("SELECT COUNT(*) FROM bookings
                                   WHERE status IN ('searching','price_quoted','negotiating','negotiation_final','confirmed','active')"),
      'gmv_month'         => $one("SELECT COALESCE(SUM(IF(confirmed_price > 0, confirmed_price, amount)), 0) FROM bookings WHERE $paid"),
      'commission_month'  => $one("SELECT COALESCE(SUM(commission_amt), 0) FROM bookings WHERE $paid"),
    ]);
  }

  default:
    err('Invalid action');
}
