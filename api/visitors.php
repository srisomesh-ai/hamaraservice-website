<?php
// ═══════════════════════════════════════════════════════
// HamaraService — website visitor analytics (admin only)
//   GET ?action=summary&range=today|7d|30d
//   GET ?action=list&range=…&kind=visitors|sessions&source=&city=&converted=customer|provider|any|none&q=&page=1&per=25
//   GET ?action=detail&vid=…        one visitor: sessions → pages timeline + clicks
//   GET ?action=live                visits active in the last 2 minutes
//   POST ?action=cleanup            run the retention clean-up now
// Data is collected by js/hs-track.js → api/track.php.
// ═══════════════════════════════════════════════════════
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/schema.php';
require_once __DIR__ . '/visitors_lib.php';

setCorsHeaders();
header('Cache-Control: no-store');
requireAdmin();

$db = getDB();
ensureSchema($db);
vsCleanup($db);   // at most once a day

$action = $_GET['action'] ?? '';

function vsRangeStart($r) {
    switch ($r) {
        case '30d': return date('Y-m-d 00:00:00', strtotime('-29 days'));
        case '7d':  return date('Y-m-d 00:00:00', strtotime('-6 days'));
        default:    return date('Y-m-d 00:00:00');
    }
}
$range = in_array($_GET['range'] ?? '', ['today', '7d', '30d'], true) ? $_GET['range'] : 'today';
$from = vsRangeStart($range);
$ONLINE = VS_ONLINE_SEC;

function vsAll(PDO $db, $sql, array $p = []) { $s = $db->prepare($sql); $s->execute($p); return $s->fetchAll(); }
function vsOne(PDO $db, $sql, array $p = []) { $s = $db->prepare($sql); $s->execute($p); return $s->fetchColumn(); }
function vsInts(array $rows, array $keys) {
    foreach ($rows as &$r) foreach ($keys as $k) if (isset($r[$k])) $r[$k] = (int)$r[$k];
    return $rows;
}

switch ($action) {
  case 'summary': {
    $visitors = (int)vsOne($db, "SELECT COUNT(DISTINCT vid) FROM visitor_sessions WHERE last_seen >= ?", [$from]);
    $new      = (int)vsOne($db, "SELECT COUNT(*) FROM visitors WHERE first_seen >= ?", [$from]);
    $sess     = $db->prepare("SELECT COUNT(*) AS n, COALESCE(AVG(seconds),0) AS avg_s, COALESCE(SUM(seconds),0) AS sum_s FROM visitor_sessions WHERE last_seen >= ?");
    $sess->execute([$from]); $sess = $sess->fetch();
    $pageviews = (int)vsOne($db, "SELECT COUNT(*) FROM visitor_pageviews WHERE at >= ?", [$from]);
    $clicks = ['install_customer' => 0, 'install_provider' => 0, 'install_other' => 0, 'whatsapp' => 0, 'call' => 0,
               'install_customer_visitors' => 0, 'install_provider_visitors' => 0];
    foreach (vsAll($db, "SELECT type, who, COUNT(*) AS n, COUNT(DISTINCT vid) AS v FROM visitor_events WHERE at >= ? GROUP BY type, who", [$from]) as $r) {
        if ($r['type'] === 'install_click') {
            $k = $r['who'] === 'provider' ? 'install_provider' : ($r['who'] === 'customer' ? 'install_customer' : 'install_other');
            $clicks[$k] += (int)$r['n'];
            if ($k !== 'install_other') $clicks[$k . '_visitors'] += (int)$r['v'];
        } elseif ($r['type'] === 'whatsapp_click') $clicks['whatsapp'] += (int)$r['n'];
        elseif ($r['type'] === 'call_click') $clicks['call'] += (int)$r['n'];
    }
    $online = (int)vsOne($db, "SELECT COUNT(DISTINCT vid) FROM visitor_sessions WHERE last_seen >= NOW() - INTERVAL $ONLINE SECOND");
    $cities = vsAll($db, "SELECT city, region, country, country_code, COUNT(DISTINCT vid) AS n FROM visitor_sessions WHERE last_seen >= ?
                          GROUP BY city, region, country, country_code ORDER BY n DESC LIMIT 10", [$from]);
    $sources = vsAll($db, "SELECT source, COUNT(*) AS n, COUNT(DISTINCT vid) AS visitors FROM visitor_sessions WHERE last_seen >= ? GROUP BY source ORDER BY n DESC", [$from]);
    $campaigns = vsAll($db, "SELECT utm_source, utm_medium, utm_campaign, COUNT(*) AS n FROM visitor_sessions WHERE last_seen >= ? AND utm_source <> ''
                             GROUP BY utm_source, utm_medium, utm_campaign ORDER BY n DESC LIMIT 10", [$from]);
    $landing = vsAll($db, "SELECT landing AS path, COUNT(*) AS n FROM visitor_sessions WHERE last_seen >= ? GROUP BY landing ORDER BY n DESC LIMIT 10", [$from]);
    $pages = vsAll($db, "SELECT path, MAX(title) AS title, COUNT(*) AS n, ROUND(AVG(seconds)) AS avg_s FROM visitor_pageviews WHERE at >= ? GROUP BY path ORDER BY n DESC LIMIT 10", [$from]);
    $devices = vsAll($db, "SELECT device, COUNT(*) AS n FROM visitor_sessions WHERE last_seen >= ? GROUP BY device ORDER BY n DESC", [$from]);
    $daily = $range === 'today' ? [] : vsAll($db, "SELECT DATE(started_at) AS day, COUNT(DISTINCT vid) AS visitors, COUNT(*) AS sessions
                          FROM visitor_sessions WHERE started_at >= ? GROUP BY DATE(started_at) ORDER BY day", [$from]);
    ok([
        'range' => $range, 'from' => $from,
        'online_now' => $online,
        'visitors' => $visitors, 'new_visitors' => min($new, $visitors), 'returning_visitors' => max(0, $visitors - $new),
        'sessions' => (int)$sess['n'], 'avg_session_seconds' => (int)round($sess['avg_s']), 'total_seconds' => (int)$sess['sum_s'],
        'pageviews' => $pageviews,
        'clicks' => $clicks,
        'top_cities' => vsInts($cities, ['n']), 'top_sources' => vsInts($sources, ['n', 'visitors']),
        'top_campaigns' => vsInts($campaigns, ['n']),
        'top_landing' => vsInts($landing, ['n']), 'top_pages' => vsInts($pages, ['n', 'avg_s']),
        'devices' => vsInts($devices, ['n']), 'daily' => vsInts($daily, ['visitors', 'sessions']),
    ]);
  }

  case 'list': {
    $kind = ($_GET['kind'] ?? '') === 'sessions' ? 'sessions' : 'visitors';
    $page = max(1, (int)($_GET['page'] ?? 1));
    $per  = min(100, max(5, (int)($_GET['per'] ?? 25)));
    $src  = vsCut($_GET['source'] ?? '', 20);
    $city = vsCut($_GET['city'] ?? '', 80);
    $conv = $_GET['converted'] ?? '';
    $q    = vsCut($_GET['q'] ?? '', 80);

    if ($kind === 'sessions') {
        $w = ['s.last_seen >= ?']; $p = [$from];
        if ($src !== '')  { $w[] = 's.source = ?'; $p[] = $src; }
        if ($city !== '') { $w[] = 's.city = ?'; $p[] = $city; }
        if ($conv === 'customer') $w[] = "EXISTS (SELECT 1 FROM visitor_events e WHERE e.sid = s.sid AND e.type = 'install_click' AND e.who = 'customer')";
        elseif ($conv === 'provider') $w[] = "EXISTS (SELECT 1 FROM visitor_events e WHERE e.sid = s.sid AND e.type = 'install_click' AND e.who = 'provider')";
        elseif ($conv === 'any') $w[] = "EXISTS (SELECT 1 FROM visitor_events e WHERE e.sid = s.sid AND e.type = 'install_click')";
        elseif ($conv === 'none') $w[] = "NOT EXISTS (SELECT 1 FROM visitor_events e WHERE e.sid = s.sid AND e.type = 'install_click')";
        if ($q !== '') { $w[] = '(s.ip LIKE ? OR s.city LIKE ? OR s.region LIKE ? OR s.org LIKE ? OR s.vid LIKE ? OR s.landing LIKE ? OR s.utm_campaign LIKE ?)'; array_push($p, ...array_fill(0, 7, '%' . $q . '%')); }
        $where = implode(' AND ', $w);
        $total = (int)vsOne($db, "SELECT COUNT(*) FROM visitor_sessions s WHERE $where", $p);
        $off = ($page - 1) * $per;
        $rows = vsAll($db, "SELECT s.*, (s.last_seen >= NOW() - INTERVAL $ONLINE SECOND) AS online,
                  (SELECT GROUP_CONCAT(DISTINCT e.who) FROM visitor_events e WHERE e.sid = s.sid AND e.type = 'install_click') AS installs
                  FROM visitor_sessions s WHERE $where ORDER BY s.last_seen DESC LIMIT $per OFFSET $off", $p);
        ok(['kind' => $kind, 'total' => $total, 'page' => $page, 'per' => $per,
            'items' => vsInts($rows, ['seconds', 'pageviews', 'visit_no', 'has_gclid', 'has_fbclid', 'online'])]);
    }

    $w = ['v.last_seen >= ?']; $p = [$from];
    if ($src !== '')  { $w[] = 'EXISTS (SELECT 1 FROM visitor_sessions s WHERE s.vid = v.vid AND s.source = ? AND s.last_seen >= ?)'; $p[] = $src; $p[] = $from; }
    if ($city !== '') { $w[] = 'v.last_city = ?'; $p[] = $city; }
    if ($conv === 'customer') $w[] = 'v.converted_customer = 1';
    elseif ($conv === 'provider') $w[] = 'v.converted_provider = 1';
    elseif ($conv === 'any') $w[] = '(v.converted_customer = 1 OR v.converted_provider = 1)';
    elseif ($conv === 'none') $w[] = 'v.converted_customer = 0 AND v.converted_provider = 0';
    if ($q !== '') { $w[] = '(v.last_ip LIKE ? OR v.last_city LIKE ? OR v.last_region LIKE ? OR v.last_org LIKE ? OR v.vid LIKE ? OR v.first_landing LIKE ? OR v.utm_campaign LIKE ?)'; array_push($p, ...array_fill(0, 7, '%' . $q . '%')); }
    $where = implode(' AND ', $w);
    $total = (int)vsOne($db, "SELECT COUNT(*) FROM visitors v WHERE $where", $p);
    $off = ($page - 1) * $per;
    $rows = vsAll($db, "SELECT v.*, (v.last_seen >= NOW() - INTERVAL $ONLINE SECOND) AS online FROM visitors v
                        WHERE $where ORDER BY v.last_seen DESC LIMIT $per OFFSET $off", $p);
    // latest visit of each listed visitor (its source / landing page / screen)
    if ($rows) {
        $ids = array_column($rows, 'vid');
        $in = implode(',', array_fill(0, count($ids), '?'));
        $last = [];
        foreach (vsAll($db, "SELECT s.vid, s.sid, s.source, s.referrer, s.landing, s.utm_source, s.utm_medium, s.utm_campaign, s.has_gclid, s.has_fbclid,
                                    s.screen, s.lang, s.pageviews, s.seconds, s.started_at
                             FROM visitor_sessions s JOIN (SELECT vid, MAX(visit_no) AS m FROM visitor_sessions WHERE vid IN ($in) GROUP BY vid) x
                               ON x.vid = s.vid AND x.m = s.visit_no", $ids) as $s) $last[$s['vid']] = vsInts([$s], ['has_gclid', 'has_fbclid', 'pageviews', 'seconds'])[0];
        foreach ($rows as &$r) $r['last_session'] = $last[$r['vid']] ?? null;
        unset($r);
    }
    ok(['kind' => $kind, 'total' => $total, 'page' => $page, 'per' => $per,
        'items' => vsInts($rows, ['visits', 'total_seconds', 'pageviews', 'converted_customer', 'converted_provider', 'online'])]);
  }

  case 'detail': {
    $vid = vsId($_GET['vid'] ?? '');
    if ($vid === '') err('vid required');
    $v = vsAll($db, "SELECT v.*, (v.last_seen >= NOW() - INTERVAL $ONLINE SECOND) AS online FROM visitors v WHERE v.vid = ?", [$vid]);
    if (!$v) err('Visitor not found', 404);
    $v = vsInts($v, ['visits', 'total_seconds', 'pageviews', 'converted_customer', 'converted_provider', 'online'])[0];
    $sessions = vsInts(vsAll($db, "SELECT s.*, (s.last_seen >= NOW() - INTERVAL $ONLINE SECOND) AS online FROM visitor_sessions s
                                   WHERE s.vid = ? ORDER BY s.started_at DESC, s.visit_no DESC LIMIT 50", [$vid]),
                       ['seconds', 'pageviews', 'visit_no', 'has_gclid', 'has_fbclid', 'online']);
    if ($sessions) {
        $sids = array_column($sessions, 'sid');
        $in = implode(',', array_fill(0, count($sids), '?'));
        $pv = []; $ev = [];
        foreach (vsAll($db, "SELECT sid, path, title, at, seconds FROM visitor_pageviews WHERE sid IN ($in) ORDER BY id", $sids) as $r) { $r['seconds'] = (int)$r['seconds']; $pv[$r['sid']][] = $r; }
        foreach (vsAll($db, "SELECT sid, type, who, path, target, at FROM visitor_events WHERE sid IN ($in) ORDER BY id", $sids) as $r) $ev[$r['sid']][] = $r;
        foreach ($sessions as &$s) { $s['pages'] = $pv[$s['sid']] ?? []; $s['events'] = $ev[$s['sid']] ?? []; }
        unset($s);
    }
    ok(['visitor' => $v, 'sessions' => $sessions]);
  }

  case 'live': {
    $rows = vsAll($db, "SELECT s.sid, s.vid, s.visit_no, s.started_at, s.last_seen, s.seconds, s.pageviews, s.source, s.referrer, s.landing,
                               s.utm_source, s.utm_campaign, s.has_gclid, s.has_fbclid, s.ip, s.city, s.region, s.country, s.country_code, s.org,
                               s.device, s.os, s.browser,
                               (SELECT p.path FROM visitor_pageviews p WHERE p.sid = s.sid ORDER BY p.id DESC LIMIT 1) AS current_path,
                               (SELECT p.title FROM visitor_pageviews p WHERE p.sid = s.sid ORDER BY p.id DESC LIMIT 1) AS current_title,
                               TIMESTAMPDIFF(SECOND, s.last_seen, NOW()) AS idle_seconds
                        FROM visitor_sessions s WHERE s.last_seen >= NOW() - INTERVAL $ONLINE SECOND
                        ORDER BY s.last_seen DESC LIMIT 100");
    ok(['online_now' => count(array_unique(array_column($rows, 'vid'))),
        'items' => vsInts($rows, ['visit_no', 'seconds', 'pageviews', 'has_gclid', 'has_fbclid', 'idle_seconds'])]);
  }

  case 'cleanup': {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') err('POST only', 405);
    ok(['cleanup' => vsCleanup($db, true), 'ip_retention_days' => VISITOR_IP_RETENTION_DAYS,
        'pageview_retention_days' => VISITOR_PAGEVIEW_RETENTION_DAYS]);
  }

  default:
    err('Unknown action', 404);
}
