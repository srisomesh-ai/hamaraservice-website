<?php
// ═══════════════════════════════════════════════════════
// HamaraService — Admin push notifications (replaces the open
// notify_booking.php / send_notification.php relays)
//   POST ?action=send {target:'provider'|'customer', id, title, body}
//   POST ?action=broadcast {audience:'providers'|'customers', title, body}
// ═══════════════════════════════════════════════════════
require_once __DIR__ . '/db.php';
setCorsHeaders();
requireAdmin();

$action = $_GET['action'] ?? '';
$db = getDB();
$b  = getBody();
$title = trim($b['title'] ?? '');
$body  = trim($b['body'] ?? '');
if ($title === '' || $body === '') err('title and body required');

switch ($action) {
  case 'send': {
    $table = ($b['target'] ?? '') === 'customer' ? 'customers' : 'providers';
    $s = $db->prepare("SELECT fcm_token FROM $table WHERE id = ?");
    $s->execute([$b['id'] ?? '']);
    $tok = $s->fetchColumn();
    if (!$tok) err('No device token for this user', 404);
    sendPush($tok, $title, $body, ['event' => 'admin_broadcast']);
    ok(['sent' => 1]);
  }
  case 'broadcast': {
    $sql = ($b['audience'] ?? '') === 'customers'
      ? "SELECT fcm_token FROM customers WHERE fcm_token IS NOT NULL AND fcm_token != ''"
      : "SELECT fcm_token FROM providers WHERE status = 'approved' AND fcm_token IS NOT NULL AND fcm_token != ''";
    $n = 0;
    foreach ($db->query($sql)->fetchAll(PDO::FETCH_COLUMN) as $tok) { sendPush($tok, $title, $body, ['event' => 'admin_broadcast']); $n++; }
    ok(['sent' => $n]);
  }
  default:
    err('Invalid action');
}
