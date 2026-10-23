<?php
// ═══════════════════════════════════════════════════════
// HamaraService — Admin push notifications (replaces the open
// notify_booking.php / send_notification.php relays)
//   POST ?action=send {target:'provider'|'customer', id, title, body}
//   POST ?action=broadcast {audience:'providers'|'customers', title, body}
// ═══════════════════════════════════════════════════════
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/schema.php';
setCorsHeaders();
requireAdmin();

$action = $_GET['action'] ?? '';
$db = getDB();
ensureSchema($db);
$b  = getBody();
$title = trim($b['title'] ?? '');
$body  = trim($b['body'] ?? '');
if ($title === '' || $body === '') err('title and body required');

switch ($action) {
  case 'send': {
    $table = ($b['target'] ?? '') === 'customer' ? 'customers' : 'providers';
    $s = $db->prepare("SELECT fcm_token, web_push_token FROM $table WHERE id = ?");
    $s->execute([$b['id'] ?? '']);
    $u = $s->fetch();
    if (!$u || (empty($u['fcm_token']) && empty($u['web_push_token']))) err('No device token for this user', 404);
    sendPush($u['fcm_token'] ?? '', $title, $body, ['event' => 'admin_broadcast']);
    sendWebPush($u['web_push_token'] ?? '', $title, $body, ['event' => 'admin_broadcast'], $table);
    ok(['sent' => 1]);
  }
  case 'broadcast': {
    $cust = ($b['audience'] ?? '') === 'customers';
    $sql = $cust
      ? "SELECT fcm_token, web_push_token FROM customers WHERE COALESCE(fcm_token,'') != '' OR COALESCE(web_push_token,'') != ''"
      : "SELECT fcm_token, web_push_token FROM providers WHERE status = 'approved' AND (COALESCE(fcm_token,'') != '' OR COALESCE(web_push_token,'') != '')";
    $n = 0;
    foreach ($db->query($sql)->fetchAll() as $u) {
      sendPush($u['fcm_token'] ?? '', $title, $body, ['event' => 'admin_broadcast']);
      sendWebPush($u['web_push_token'] ?? '', $title, $body, ['event' => 'admin_broadcast'], $cust ? 'customers' : 'providers');
      $n++;
    }
    ok(['sent' => $n]);
  }
  default:
    err('Invalid action');
}
