<?php
// ============================================================
//  api/cron.php — optional scheduled jobs
//
//  GET /api/cron.php?action=unassigned&key=<CRON_KEY>
//    E-mails the admin about bookings that found no provider within
//    NO_PROVIDER_ALERT_MIN minutes (once per booking). The same check also
//    runs while the admin panel / apps are polling, so the cron is only a
//    safety net for quiet hours.
//
//  Hostinger → Advanced → Cron Jobs, every 5 minutes (*/5 * * * *):
//    curl -fsS "https://hamaraservice.com/api/cron.php?action=unassigned&key=YOUR_CRON_KEY" >/dev/null
//  Set CRON_KEY in ~/hs-secrets.php; empty CRON_KEY disables this endpoint.
//
//  GET /api/cron.php?action=visitors_cleanup&key=<CRON_KEY>
//    Website analytics retention: erases visitor IPs older than
//    VISITOR_IP_RETENTION_DAYS (90) and page views older than 180 days.
//    Also runs by itself at most once a day from api/track.php / api/visitors.php.
// ============================================================
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/schema.php';
require_once __DIR__ . '/alerts_lib.php';
require_once __DIR__ . '/visitors_lib.php';

header('Content-Type: application/json');
header('Cache-Control: no-store');

$key = (string)($_GET['key'] ?? '');
if (CRON_KEY === '') err('Cron disabled (CRON_KEY not set)', 403);
if (!hash_equals(CRON_KEY, $key)) err('Forbidden', 403);

$action = $_GET['action'] ?? '';
switch ($action) {
  case 'unassigned': {
    $db = getDB();
    ensureSchema($db);
    $sent = checkUnassignedBookings($db);
    ok(['alerts_sent' => $sent]);
  }
  case 'visitors_cleanup': {
    $db = getDB();
    ensureSchema($db);
    ok(['cleanup' => vsCleanup($db, true)]);
  }
  default:
    err('Unknown action', 404);
}
