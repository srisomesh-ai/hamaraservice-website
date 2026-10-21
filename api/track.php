<?php
// ═══════════════════════════════════════════════════════
// HamaraService — website visitor analytics collector
//   POST /api/track.php   body: JSON (sent as text/plain so navigator.sendBeacon
//                         and fetch need no CORS pre-flight), max 4 KB
//   {a:'pageview',  vid, sid, pid, path, title, ref, utm:{source,medium,campaign,term,content},
//                   gclid:0|1, fbclid:0|1, screen:'390x844', lang, tz}
//   {a:'heartbeat', vid, sid, pid, secs}      secs = active (visible) seconds on that page so far
//   {a:'event',     vid, sid, pid, type:'install_click'|'whatsapp_click'|'call_click',
//                   who:'customer'|'provider', target, path}
// Always answers quickly; bots, foreign origins and floods are ignored.
// Sender: js/hs-track.js. Admin read side: api/visitors.php.
// ═══════════════════════════════════════════════════════
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/schema.php';
require_once __DIR__ . '/visitors_lib.php';

setCorsHeaders();
header('Cache-Control: no-store');
header('X-Robots-Tag: noindex');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') err('POST only', 405);

// Answer now, keep working afterwards (geo lookup / cleanup) when the SAPI allows it.
function trkRespond(array $data, $code = 200) {
    http_response_code($code);
    $out = json_encode(['success' => $code < 400, 'data' => $data]);
    header('Content-Length: ' . strlen($out));
    echo $out;
    if (function_exists('fastcgi_finish_request')) fastcgi_finish_request();
    elseif (function_exists('litespeed_finish_request')) litespeed_finish_request();
    else { @ob_flush(); @flush(); }
}
function trkIgnore($why, $code = 200) { trkRespond(['ignored' => $why], $code); exit; }

$raw = file_get_contents('php://input', false, null, 0, 4097);
if ($raw === false || $raw === '' || strlen($raw) > 4096) trkIgnore('size', 413);
$b = json_decode($raw, true);
if (!is_array($b)) trkIgnore('json', 400);

$ua = (string)($_SERVER['HTTP_USER_AGENT'] ?? '');
if (vsIsBot($ua)) trkIgnore('bot');

// Only our own pages may report (a missing Origin is fine — older beacons)
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if ($origin !== '' && $origin !== 'null' && !in_array($origin, ALLOWED_ORIGINS, true)) {
    $oh = strtolower((string)parse_url($origin, PHP_URL_HOST)) . (parse_url($origin, PHP_URL_PORT) ? ':' . parse_url($origin, PHP_URL_PORT) : '');
    if ($oh !== strtolower((string)($_SERVER['HTTP_HOST'] ?? ''))) trkIgnore('origin', 403);
}

$ip = vsClientIp();

// Rate limit per IP (fixed one-minute window, temp file)
$rlFile = sys_get_temp_dir() . '/hs_trk_' . md5(DB_NAME . '|' . $ip);
$min = (int)floor(time() / 60);
$fh = @fopen($rlFile, 'c+');
if ($fh) {
    @flock($fh, LOCK_EX);
    $cur = explode(' ', trim((string)stream_get_contents($fh)));
    $n = ((int)($cur[0] ?? 0) === $min) ? (int)($cur[1] ?? 0) + 1 : 1;
    ftruncate($fh, 0); rewind($fh); fwrite($fh, $min . ' ' . $n);
    @flock($fh, LOCK_UN); fclose($fh);
    if ($n > TRACK_RATE_PER_MIN) { header('Retry-After: 60'); trkIgnore('rate', 429); }
}

$a   = (string)($b['a'] ?? '');
$vid = vsId($b['vid'] ?? '');
$sid = vsId($b['sid'] ?? '');
$pid = vsId($b['pid'] ?? '');
if (!in_array($a, ['pageview', 'heartbeat', 'event'], true)) trkIgnore('action', 400);
if ($vid === '' || $sid === '' || $pid === '') trkIgnore('ids', 400);

$db = getDB();
ensureSchema($db);

// The session must belong to this visitor
$q = $db->prepare("SELECT vid, city, country_code FROM visitor_sessions WHERE sid = ?");
$q->execute([$sid]);
$sess = $q->fetch();
if ($sess && $sess['vid'] !== $vid) trkIgnore('session');

$after = null;   // work to run after the response
switch ($a) {
  case 'pageview': {
    $path  = vsCleanPath($b['path'] ?? '/');
    $title = vsCut(strip_tags((string)($b['title'] ?? '')), 150);
    // referrer: scheme + host + path only (no query string — it may hold search terms / personal data)
    $refRaw = vsCut((string)($b['ref'] ?? ''), 500);
    $ref = '';
    if ($refRaw !== '') {
        if (preg_match('~^android-app://[A-Za-z0-9._-]+~', $refRaw, $m)) $ref = $m[0];
        elseif (preg_match('~^https?://~i', $refRaw)) {
            $pu = parse_url($refRaw);
            $ref = vsCut(strtolower($pu['scheme'] ?? 'https') . '://' . strtolower($pu['host'] ?? '') . preg_replace('#[^A-Za-z0-9/._~%-]#', '', $pu['path'] ?? ''), 255);
        }
    }
    $utm = is_array($b['utm'] ?? null) ? $b['utm'] : [];
    $u = [];
    foreach (['source', 'medium', 'campaign', 'term', 'content'] as $k) $u[$k] = vsCut(strip_tags((string)($utm[$k] ?? '')), 100);
    $gclid = !empty($b['gclid']) ? 1 : 0;
    $fbclid = !empty($b['fbclid']) ? 1 : 0;
    $screen = preg_match('/^\d{2,5}x\d{2,5}$/', (string)($b['screen'] ?? '')) ? $b['screen'] : '';
    $lang = vsCut(preg_replace('/[^A-Za-z0-9,;=._-]/', '', (string)($b['lang'] ?? '')), 20);
    $tz = vsCut(preg_replace('~[^A-Za-z0-9/_+-]~', '', (string)($b['tz'] ?? '')), 50);
    [$device, $os, $browser] = vsParseUA($ua);
    [$source, $refHost] = vsClassifySource($ref, $u['source'], $gclid, $fbclid, $_SERVER['HTTP_HOST'] ?? '');
    $ipStore = $ip !== '' ? $ip : null;

    // cached location (no network call here)
    $geo = null; $geoStale = false;
    if ($ip !== '' && vsPublicIp($ip)) {
        $c = vsGeoCached($db, $ip);
        if ($c && (int)$c['ok'] === 1) {
            $geo = $c;
            $geoStale = time() - strtotime($c['looked_up_at']) >= VS_GEO_TTL_DAYS * 86400;
        }
    }
    $g = $geo ?: ['city' => '', 'region' => '', 'country' => '', 'country_code' => '', 'org' => ''];

    $db->prepare("INSERT IGNORE INTO visitors (vid, first_seen, last_seen, first_source, first_referrer, first_landing,
                    utm_source, utm_medium, utm_campaign, device, os, browser, last_ip)
                  VALUES (?, NOW(), NOW(), ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)")
       ->execute([$vid, $source, $ref, $path, $u['source'], $u['medium'], $u['campaign'], $device, $os, $browser, $ipStore]);

    $newSession = false;
    if (!$sess) {
        $ins = $db->prepare("INSERT IGNORE INTO visitor_sessions (sid, vid, started_at, last_seen, source, referrer, landing,
                    utm_source, utm_medium, utm_campaign, utm_term, utm_content, has_gclid, has_fbclid, ip,
                    city, region, country, country_code, org, device, os, browser, screen, lang, tz)
                  VALUES (?, ?, NOW(), NOW(), ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $ins->execute([$sid, $vid, $source, $ref, $path, $u['source'], $u['medium'], $u['campaign'], $u['term'], $u['content'],
                       $gclid, $fbclid, $ipStore, $g['city'], $g['region'], $g['country'], $g['country_code'], $g['org'],
                       $device, $os, $browser, $screen, $lang, $tz]);
        if ($ins->rowCount() === 1) {
            $newSession = true;
            $db->prepare("UPDATE visitors SET visits = visits + 1 WHERE vid = ?")->execute([$vid]);
            $db->prepare("UPDATE visitor_sessions s JOIN visitors v ON v.vid = s.vid SET s.visit_no = v.visits WHERE s.sid = ?")->execute([$sid]);
        }
        $sess = ['vid' => $vid, 'city' => $g['city'], 'country_code' => $g['country_code']];
    }

    // one row per page view (a resent pageview with the same pid is ignored)
    $chk = $db->prepare("SELECT id FROM visitor_pageviews WHERE sid = ? AND pid = ?");
    $chk->execute([$sid, $pid]);
    if (!$chk->fetchColumn()) {
        $db->prepare("INSERT INTO visitor_pageviews (sid, vid, pid, path, title, at) VALUES (?, ?, ?, ?, ?, NOW())")
           ->execute([$sid, $vid, $pid, $path, $title]);
        $db->prepare("UPDATE visitor_sessions SET pageviews = pageviews + 1, last_seen = NOW() WHERE sid = ?")->execute([$sid]);
        $db->prepare("UPDATE visitors SET pageviews = pageviews + 1, last_seen = NOW(), last_ip = COALESCE(?, last_ip),
                        device = ?, os = ?, browser = ? WHERE vid = ?")
           ->execute([$ipStore, $device, $os, $browser, $vid]);
    }
    if ($geo && ($newSession || ($sess['city'] === '' && $sess['country_code'] === ''))) vsApplyGeo($db, $sid, $vid, $geo);
    // not known yet, or older than 30 days → look it up after answering
    if ($ip !== '' && vsPublicIp($ip) && ($geoStale || (!$geo && $sess['country_code'] === ''))) {
        $after = function () use ($db, $ip, $sid, $vid) {
            $gl = vsGeoLookup($db, $ip);
            if ($gl) vsApplyGeo($db, $sid, $vid, $gl);
        };
    }
    trkRespond(['ok' => 1, 'new_session' => $newSession]);
    break;
  }

  case 'heartbeat': {
    if (!$sess) trkIgnore('session');
    $s = $db->prepare("SELECT id, seconds, TIMESTAMPDIFF(SECOND, at, NOW()) AS age FROM visitor_pageviews WHERE sid = ? AND pid = ?");
    $s->execute([$sid, $pid]);
    $pv = $s->fetch();
    if (!$pv) trkIgnore('pageview');
    // active seconds can never exceed the wall-clock time since the page view (max 4 h)
    $secs = max(0, min((int)($b['secs'] ?? 0), (int)$pv['age'] + 5, 4 * 3600));
    $delta = max(0, $secs - (int)$pv['seconds']);
    if ($delta > 0) {
        $db->prepare("UPDATE visitor_pageviews SET seconds = ? WHERE id = ? AND seconds < ?")->execute([$secs, $pv['id'], $secs]);
    }
    $db->prepare("UPDATE visitor_sessions SET seconds = seconds + ?, last_seen = NOW() WHERE sid = ?")->execute([$delta, $sid]);
    $db->prepare("UPDATE visitors SET total_seconds = total_seconds + ?, last_seen = NOW() WHERE vid = ?")->execute([$delta, $vid]);
    trkRespond(['ok' => 1, 'added' => $delta]);
    break;
  }

  case 'event': {
    if (!$sess) trkIgnore('session');
    $type = (string)($b['type'] ?? '');
    if (!in_array($type, ['install_click', 'whatsapp_click', 'call_click'], true)) trkIgnore('type', 400);
    $who = in_array($b['who'] ?? '', ['customer', 'provider'], true) ? $b['who'] : '';
    $path = vsCleanPath($b['path'] ?? '/');
    // where the click went: scheme + host + path only (a tel: number is our own support line)
    $t = vsCut((string)($b['target'] ?? ''), 300);
    if (preg_match('~^tel:~i', $t)) $t = 'tel:' . substr(preg_replace('/[^0-9+]/', '', substr($t, 4)), 0, 20);
    elseif (preg_match('~^https?://~i', $t)) { $pu = parse_url($t); $t = vsCut(($pu['host'] ?? '') . preg_replace('#[^A-Za-z0-9/._~%-]#', '', $pu['path'] ?? ''), 200); if (!empty($pu['query']) && preg_match('/(?:^|&)(id|for)=([A-Za-z0-9._-]+)/', $pu['query'], $m)) $t .= '?' . $m[1] . '=' . $m[2]; }
    elseif ($t !== '' && $t[0] === '/') $t = vsCleanPath($t);
    else $t = vsCut(preg_replace('/[^A-Za-z0-9_:.\/-]/', '', $t), 100);
    // the same click reported twice (link + JS helper) within 3 s counts once
    $d = $db->prepare("SELECT id FROM visitor_events WHERE sid = ? AND type = ? AND who = ? AND at >= NOW() - INTERVAL 3 SECOND");
    $d->execute([$sid, $type, $who]);
    if ($d->fetchColumn()) trkIgnore('duplicate');
    $db->prepare("INSERT INTO visitor_events (sid, vid, type, who, path, target, at) VALUES (?, ?, ?, ?, ?, ?, NOW())")
       ->execute([$sid, $vid, $type, $who, $path, $t]);
    if ($type === 'install_click' && $who !== '') {
        $col = $who === 'provider' ? 'converted_provider' : 'converted_customer';
        $db->prepare("UPDATE visitors SET $col = 1, last_seen = NOW() WHERE vid = ?")->execute([$vid]);
    }
    $db->prepare("UPDATE visitor_sessions SET last_seen = NOW() WHERE sid = ?")->execute([$sid]);
    trkRespond(['ok' => 1]);
    break;
  }
}

// ── after the response ──
ignore_user_abort(true);
try {
    if ($after) $after();
    if (vsCleanup($db) !== null) {
        foreach (glob(sys_get_temp_dir() . '/hs_trk_*') ?: [] as $f) { if (@filemtime($f) < time() - 3600) @unlink($f); }
    }
} catch (Throwable $e) { error_log('[track] ' . $e->getMessage()); }
exit;
