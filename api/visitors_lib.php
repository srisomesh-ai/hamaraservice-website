<?php
// ═══════════════════════════════════════════════════════
// HamaraService — first-party visitor analytics helpers
// Used by api/track.php (collector), api/visitors.php (admin) and api/cron.php.
// No third-party scripts: only the visitor IP is sent to IPinfo (server side,
// cached 30 days per IP) to get an approximate city / ISP.
// ═══════════════════════════════════════════════════════

const VS_SESSION_IDLE_MIN = 30;     // a visit ends after 30 min without activity (client decides; server mirrors it)
const VS_ONLINE_SEC       = 120;    // "online now" = seen in the last 2 minutes
const VS_GEO_TTL_DAYS     = 30;     // IP → location cache lifetime
const VS_GEO_RETRY_MIN    = 60;     // a failed lookup is retried after 1 h

function vsCut($v, $n) {
    $v = trim(preg_replace('/[\x00-\x1F\x7F]+/u', ' ', (string)$v));
    return function_exists('mb_substr') ? mb_substr($v, 0, $n, 'UTF-8') : substr($v, 0, $n);
}

// Visitor / session / page ids from the browser: 8–32 chars [A-Za-z0-9_-]
function vsId($v) {
    return (is_string($v) && preg_match('/^[A-Za-z0-9_-]{8,32}$/', $v)) ? $v : '';
}

// ── client IP ─────────────────────────────────────────
// REMOTE_ADDR, unless TRUST_PROXY is on (site behind Cloudflare / a proxy).
function vsClientIp() {
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    if (TRUST_PROXY) {
        $cf = trim($_SERVER['HTTP_CF_CONNECTING_IP'] ?? '');
        if ($cf !== '' && filter_var($cf, FILTER_VALIDATE_IP)) return $cf;
        $xff = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '';
        foreach (explode(',', $xff) as $c) {   // left-most valid address = the client
            $c = trim($c);
            if ($c !== '' && filter_var($c, FILTER_VALIDATE_IP)) return $c;
        }
    }
    return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : '';
}

function vsPublicIp($ip) {
    return (bool)filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
}

// ── bots / crawlers / headless browsers ───────────────
function vsIsBot($ua) {
    $ua = (string)$ua;
    if (strlen(trim($ua)) < 12) return true;   // missing or junk UA
    return (bool)preg_match('~bot\b|bot/|crawl|spider|slurp|archiver|headless|phantomjs|selenium|puppeteer|playwright|webdriver|lighthouse|pagespeed|chrome-lighthouse|gtmetrix|pingdom|uptime|monitor|statuscake|facebookexternalhit|facebookcatalog|whatsapp/|telegrambot|twitterbot|linkedinbot|slackbot|discordbot|embedly|quora link|preview|curl/|wget/|python|java/|go-http|okhttp|axios|node-fetch|undici|httpclient|libwww|scrapy|feedfetcher|mediapartners|adsbot|bingpreview|yandex|baiduspider|duckduck|applebot|petalbot|bytespider|gptbot|chatgpt|claudebot|anthropic|ccbot|perplexity|semrush|ahrefs|mj12|dotbot|screaming frog|sitebulb|dataprovider|ia_archiver~i', $ua);
}

// ── user agent → device / os / browser (simple regexes) ──
function vsParseUA($ua) {
    $ua = (string)$ua;
    $device = 'desktop';
    if (preg_match('/iPad|Tablet|PlayBook|Silk|Kindle|SM-T\d|Tab\b/i', $ua)
        || (preg_match('/Android/i', $ua) && !preg_match('/Mobile/i', $ua))) $device = 'tablet';
    elseif (preg_match('/Mobi|iPhone|iPod|Android.*Mobile|Opera Mini|IEMobile|BlackBerry|KaiOS/i', $ua)) $device = 'mobile';

    $os = 'Other';
    if (preg_match('/Android\s*([\d]+)?/i', $ua, $m))            $os = 'Android' . (!empty($m[1]) ? ' ' . $m[1] : '');
    elseif (preg_match('/(iPhone|iPad|iPod).*?OS (\d+)/i', $ua, $m)) $os = 'iOS ' . $m[2];
    elseif (preg_match('/iPhone|iPad|iPod/i', $ua))                $os = 'iOS';
    elseif (preg_match('/Windows NT 10/i', $ua))                   $os = 'Windows';
    elseif (preg_match('/Windows/i', $ua))                         $os = 'Windows';
    elseif (preg_match('/CrOS/i', $ua))                            $os = 'ChromeOS';
    elseif (preg_match('/Mac OS X|Macintosh/i', $ua))              $os = 'macOS';
    elseif (preg_match('/KaiOS/i', $ua))                           $os = 'KaiOS';
    elseif (preg_match('/Linux/i', $ua))                           $os = 'Linux';

    $browser = 'Other';
    if (preg_match('/FBAN|FBAV|FB_IAB/i', $ua))                    $browser = 'Facebook app';
    elseif (preg_match('/Instagram/i', $ua))                       $browser = 'Instagram app';
    elseif (preg_match('/WhatsApp/i', $ua))                        $browser = 'WhatsApp';
    elseif (preg_match('/SamsungBrowser/i', $ua))                  $browser = 'Samsung';
    elseif (preg_match('/MiuiBrowser|XiaoMi/i', $ua))              $browser = 'Mi Browser';
    elseif (preg_match('/UCBrowser|UCWEB/i', $ua))                 $browser = 'UC Browser';
    elseif (preg_match('/OPR\/|Opera/i', $ua))                     $browser = 'Opera';
    elseif (preg_match('/Edg(e|A|iOS)?\//i', $ua))                 $browser = 'Edge';
    elseif (preg_match('/Firefox|FxiOS/i', $ua))                   $browser = 'Firefox';
    elseif (preg_match('/Chrome|CriOS/i', $ua))                    $browser = 'Chrome';
    elseif (preg_match('/Safari/i', $ua))                          $browser = 'Safari';
    return [$device, $os, $browser];
}

// ── source of the visit ───────────────────────────────
// Google / Bing / WhatsApp / Facebook / Instagram / YouTube / Direct / Other.
// Order: click ids (gclid → Google, fbclid → Facebook/Instagram), utm_source,
// then the referrer host. Referrers from our own site count as Direct.
function vsSourceName($s) {
    $s = strtolower((string)$s);
    if ($s === '') return '';
    if (preg_match('/(^|\.)google\.|^google$|googlequicksearchbox|^gads|adwords/', $s)) return 'Google';
    if (preg_match('/(^|\.)bing\.com|^bing$/', $s)) return 'Bing';
    if (preg_match('/whatsapp|^wa$|(^|\.)wa\.me/', $s)) return 'WhatsApp';
    if (preg_match('/instagram|^ig$/', $s)) return 'Instagram';
    if (preg_match('/facebook|^fb$|(^|\.)fb\.com|^meta$|(^|\.)fb\.me/', $s)) return 'Facebook';
    if (preg_match('/youtube|youtu\.be|^yt$/', $s)) return 'YouTube';
    return '';
}

function vsClassifySource($referrer, $utmSource, $gclid, $fbclid, $selfHost) {
    $refHost = '';
    $ref = (string)$referrer;
    if ($ref !== '') {
        if (preg_match('~^android-app://([^/]+)~i', $ref, $m)) $refHost = strtolower($m[1]);
        else $refHost = strtolower((string)parse_url($ref, PHP_URL_HOST));
    }
    $self = preg_replace('/^www\./', '', strtolower((string)$selfHost));
    $internal = $refHost !== '' && (preg_replace('/^www\./', '', $refHost) === $self
        || preg_match('/(^|\.)hamaraservice\.(com|in)$/', $refHost));
    if ($gclid) return ['Google', $internal ? '' : $refHost];
    if ($fbclid) return [vsSourceName($refHost) === 'Instagram' || vsSourceName($utmSource) === 'Instagram' ? 'Instagram' : 'Facebook', $internal ? '' : $refHost];
    $u = vsSourceName($utmSource);
    if ($u !== '') return [$u, $internal ? '' : $refHost];
    if ($refHost === '' || $internal) return [trim((string)$utmSource) !== '' ? 'Other' : 'Direct', ''];
    $r = vsSourceName($refHost);
    return [$r !== '' ? $r : 'Other', $refHost];
}

// Path + query without personal data: drops ids/contact/secret parameters and
// any value that looks like an e-mail or phone number; utm_* / click ids are stored separately.
function vsCleanPath($p) {
    $p = (string)$p;
    if ($p === '' || $p[0] !== '/') $p = '/' . ltrim($p, '/');
    $parts = explode('?', $p, 2);
    $path = preg_replace('#[^A-Za-z0-9/._~%!$&\'()*+,;=:@-]#', '', $parts[0]);
    $path = preg_replace('~[^/]*@[^/]*~', '[email]', $path);
    $q = [];
    if (isset($parts[1])) {
        parse_str(explode('#', $parts[1])[0], $arr);
        foreach ($arr as $k => $v) {
            if (!is_string($v)) continue;
            $kl = strtolower((string)$k);
            if (preg_match('/^(utm_|gclid|fbclid|gbraid|wbraid|msclkid|_ga|_gl)/', $kl)) continue;
            if (preg_match('/mail|phone|mobile|tel|token|otp|pass|pwd|code|key|auth|secret|session|sid|name|address|addr|upi|account|card|pin|aadhaar|pan|lat|lng|lon/', $kl)) continue;
            if (preg_match('/@|\d{7,}/', $v)) continue;
            $q[(string)$k] = vsCut($v, 60);
        }
    }
    $out = vsCut($path, 200);
    if ($q) $out .= '?' . http_build_query($q);
    return vsCut($out, 255);
}

// ── IP → location (IPinfo), cached in ip_geo ──────────
function vsCountryName($cc) {
    $cc = strtoupper((string)$cc);
    if ($cc === '') return '';
    if (class_exists('Locale')) {
        $n = \Locale::getDisplayRegion('-' . $cc, 'en');
        if ($n && $n !== $cc) return $n;
    }
    $map = ['IN'=>'India','US'=>'United States','GB'=>'United Kingdom','AE'=>'United Arab Emirates','SA'=>'Saudi Arabia',
            'SG'=>'Singapore','CA'=>'Canada','AU'=>'Australia','DE'=>'Germany','NL'=>'Netherlands','FR'=>'France',
            'NP'=>'Nepal','BD'=>'Bangladesh','LK'=>'Sri Lanka','PK'=>'Pakistan','QA'=>'Qatar','KW'=>'Kuwait','OM'=>'Oman','IE'=>'Ireland','JP'=>'Japan'];
    return $map[$cc] ?? $cc;
}

// Cached location for an IP: array (possibly with ok=0) or null when never looked up.
function vsGeoCached(PDO $db, $ip) {
    $s = $db->prepare("SELECT ip, ok, city, region, country, country_code, org, looked_up_at FROM ip_geo WHERE ip = ?");
    $s->execute([$ip]);
    $r = $s->fetch();
    return $r ?: null;
}

// Look an IP up when it is public and not freshly cached. Never throws; at most ~2 s.
// Returns the location row (ok=1) or null.
function vsGeoLookup(PDO $db, $ip) {
    if ($ip === '' || !vsPublicIp($ip)) return null;
    $c = vsGeoCached($db, $ip);
    $age = $c ? time() - strtotime($c['looked_up_at']) : PHP_INT_MAX;
    if ($c && (int)$c['ok'] === 1 && $age < VS_GEO_TTL_DAYS * 86400) return $c;
    if ($c && (int)$c['ok'] === 0 && $age < VS_GEO_RETRY_MIN * 60) return null;
    // provider said "rate limited" recently → wait an hour before trying again
    $back = sys_get_temp_dir() . '/hs_ipinfo_backoff_' . md5(DB_NAME);
    if (is_readable($back) && (int)@file_get_contents($back) > time()) return ($c && (int)$c['ok'] === 1) ? $c : null;

    $url = IPINFO_URL . '/' . rawurlencode($ip) . '/json' . (IPINFO_TOKEN !== '' ? '?token=' . rawurlencode(IPINFO_TOKEN) : '');
    $ctx = stream_context_create(['http' => ['timeout' => 2, 'ignore_errors' => true, 'header' => "Accept: application/json\r\n"],
                                  'ssl'  => ['verify_peer' => true]]);
    $t0 = microtime(true);
    $res = @file_get_contents($url, false, $ctx);
    $code = 0;
    foreach (($http_response_header ?? []) as $h) if (preg_match('~^HTTP/\S+\s+(\d+)~', $h, $m)) $code = (int)$m[1];
    $j = ($res !== false && $code === 200) ? json_decode($res, true) : null;
    if ($code === 429) @file_put_contents($back, (string)(time() + 3600));
    $okRow = is_array($j) && empty($j['bogon']) && (isset($j['country']) || isset($j['city']));
    $row = [
        'ip' => $ip, 'ok' => $okRow ? 1 : 0,
        'city' => $okRow ? vsCut($j['city'] ?? '', 80) : '',
        'region' => $okRow ? vsCut($j['region'] ?? '', 80) : '',
        'country_code' => $okRow ? strtoupper(substr(preg_replace('/[^A-Za-z]/', '', (string)($j['country'] ?? '')), 0, 2)) : '',
        'org' => $okRow ? vsCut($j['org'] ?? ($j['asn']['name'] ?? ''), 120) : '',
    ];
    $row['country'] = $okRow ? vsCut($j['country_name'] ?? vsCountryName($row['country_code']), 80) : '';
    if (!$okRow) error_log('[visitors] ipinfo lookup failed for ' . $ip . ' (HTTP ' . $code . ', ' . round(microtime(true) - $t0, 2) . 's)');
    try {
        if ($okRow) {
            $db->prepare("REPLACE INTO ip_geo (ip, ok, city, region, country, country_code, org, looked_up_at) VALUES (?,1,?,?,?,?,?,NOW())")
               ->execute([$ip, $row['city'], $row['region'], $row['country'], $row['country_code'], $row['org']]);
        } elseif ($c && (int)$c['ok'] === 1) {
            // keep the older good answer; try again in about an hour
            $h = VS_GEO_TTL_DAYS * 24 - 1;
            $db->prepare("UPDATE ip_geo SET looked_up_at = NOW() - INTERVAL $h HOUR WHERE ip = ?")->execute([$ip]);
        } else {
            $db->prepare("INSERT INTO ip_geo (ip, ok, looked_up_at) VALUES (?,0,NOW()) ON DUPLICATE KEY UPDATE ok = 0, looked_up_at = NOW()")
               ->execute([$ip]);
        }
    } catch (Throwable $e) { error_log('[visitors] ip_geo: ' . $e->getMessage()); }
    if ($okRow) return $row;
    return ($c && (int)$c['ok'] === 1) ? $c : null;
}

// Copy a location onto the session + visitor rows.
function vsApplyGeo(PDO $db, $sid, $vid, array $g) {
    $db->prepare("UPDATE visitor_sessions SET city = ?, region = ?, country = ?, country_code = ?, org = ? WHERE sid = ?")
       ->execute([$g['city'], $g['region'], $g['country'], $g['country_code'], $g['org'], $sid]);
    $db->prepare("UPDATE visitors SET last_city = ?, last_region = ?, last_country = ?, last_country_code = ?, last_org = ? WHERE vid = ?")
       ->execute([$g['city'], $g['region'], $g['country'], $g['country_code'], $g['org'], $vid]);
}

// ── retention (at most once a day unless $force) ──────
// IPs older than VISITOR_IP_RETENTION_DAYS are erased (city/country stay);
// page views / events older than VISITOR_PAGEVIEW_RETENTION_DAYS are deleted.
function vsCleanup(PDO $db, $force = false) {
    $flag = sys_get_temp_dir() . '/hs_visitors_cleanup_' . md5(DB_NAME) . '.ts';
    if (!$force && is_readable($flag) && (int)@file_get_contents($flag) > time() - 86400) return null;
    @file_put_contents($flag, (string)time());
    $ipDays = VISITOR_IP_RETENTION_DAYS; $pvDays = VISITOR_PAGEVIEW_RETENTION_DAYS;
    $out = [];
    try {
        $out['session_ips_erased'] = $db->exec("UPDATE visitor_sessions SET ip = NULL WHERE ip IS NOT NULL AND last_seen < NOW() - INTERVAL $ipDays DAY");
        $out['visitor_ips_erased'] = $db->exec("UPDATE visitors SET last_ip = NULL WHERE last_ip IS NOT NULL AND last_seen < NOW() - INTERVAL $ipDays DAY");
        $geoDays = min($ipDays, VS_GEO_TTL_DAYS);
        $out['geo_cache_deleted']  = $db->exec("DELETE FROM ip_geo WHERE looked_up_at < NOW() - INTERVAL $geoDays DAY");
        $out['pageviews_deleted']  = $db->exec("DELETE FROM visitor_pageviews WHERE at < NOW() - INTERVAL $pvDays DAY");
        $out['events_deleted']     = $db->exec("DELETE FROM visitor_events WHERE at < NOW() - INTERVAL $pvDays DAY");
    } catch (Throwable $e) { error_log('[visitors] cleanup: ' . $e->getMessage()); }
    return $out;
}
