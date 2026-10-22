<?php
// ═══════════════════════════════════════════════════════
// HamaraService — shared helpers + layout for the SEO landing pages
// (seo/service-city.php, seo/jobs-city.php, seo/city.php, seo/jobs-hub.php,
//  seo/cities.php, seo/sitemap.php, seo/build-static.php)
// ═══════════════════════════════════════════════════════

const HS_SITE = 'https://hamaraservice.com';
const HS_LOGO = 'https://hamaraservice.com/images/logo-square.png';
const HS_OG   = 'https://hamaraservice.com/images/og-share.png';
const HS_WA   = '919346220090';

function hs_data() {
    static $d = null;
    if ($d === null) $d = require __DIR__ . '/data.php';
    return $d;
}
function hs_services() { return hs_data()['services']; }
function hs_cities()   { return hs_data()['cities']; }

// The first launch cities: featured in footers and hubs.
function hs_popular_cities() {
    return ['visakhapatnam','hyderabad','vijayawada','delhi','mumbai','bengaluru','chennai','kolkata','pune','ahmedabad'];
}
// Cities to link from a city's pages: same state first, then the popular ones.
function hs_related_cities($cs, $limit = 24) {
    $C = hs_cities(); $state = $C[$cs]['state'] ?? '';
    $out = [];
    foreach ($C as $k => $c) if ($k !== $cs && $c['state'] === $state) $out[] = $k;
    foreach (hs_popular_cities() as $k) if ($k !== $cs && !in_array($k, $out, true)) $out[] = $k;
    return array_slice($out, 0, $limit);
}
// [state => [slug => city]] sorted by state, popular states first.
function hs_cities_by_state() {
    $C = hs_cities(); $by = [];
    foreach ($C as $k => $c) $by[$c['state']][$k] = $c;
    $first = ['Andhra Pradesh','Telangana'];
    uksort($by, function ($a, $b) use ($first) {
        $ia = array_search($a, $first); $ib = array_search($b, $first);
        if ($ia !== false || $ib !== false) return ($ia === false ? 99 : $ia) <=> ($ib === false ? 99 : $ib);
        return strcmp($a, $b);
    });
    return $by;
}

function e($s) { return htmlspecialchars((string)$s, ENT_QUOTES | ENT_HTML5, 'UTF-8'); }
function hs_len($s) { return mb_strlen($s, 'UTF-8'); }

// First candidate that fits, else the last one cut at a word boundary.
function hs_fit(array $candidates, $max) {
    foreach ($candidates as $c) if (hs_len($c) <= $max) return $c;
    $c = end($candidates);
    $c = mb_substr($c, 0, $max - 1, 'UTF-8');
    return preg_replace('/\s+\S*$/u', '', $c) . '…';
}

function hs_price($v) {
    if (is_int($v)) return '₹' . number_format($v, 0, '.', ',');
    return (string)$v;
}

function hs_join(array $items, $and = 'and') {
    $items = array_values($items);
    if (count($items) < 2) return implode('', $items);
    return implode(', ', array_slice($items, 0, -1)) . ' ' . $and . ' ' . end($items);
}

// A stable, slug-dependent rotation so each page highlights different localities.
function hs_rotate(array $list, $seed, $n = null) {
    $k = count($list) ? (crc32($seed) % count($list)) : 0;
    $r = array_merge(array_slice($list, $k), array_slice($list, 0, $k));
    return $n ? array_slice($r, 0, $n) : $r;
}

function hs_svc_url($svcSlug, $citySlug) { return '/' . $svcSlug . '-in-' . $citySlug; }
function hs_job_url($jobSlug, $citySlug) { return '/' . $jobSlug . '-jobs-in-' . $citySlug; }
function hs_find_job($jobSlug) {
    foreach (hs_services() as $slug => $s) if ($s['job_slug'] === $jobSlug) return [$slug, $s];
    return [null, null];
}

// JobPosting dates: roll forward in 30-day steps from jobs_epoch, so datePosted
// stays stable for a month and validThrough (datePosted + 60 days) is always
// at least 30 days in the future. No cron or manual refresh needed.
function hs_job_dates($now = null) {
    $tz = new DateTimeZone('Asia/Kolkata');
    $epoch = new DateTimeImmutable(hs_data()['jobs_epoch'], $tz);
    $now = $now ? new DateTimeImmutable($now, $tz) : new DateTimeImmutable('now', $tz);
    $days = max(0, (int)$epoch->diff($now)->format('%r%a'));
    $posted = $epoch->modify('+' . (intdiv($days, 30) * 30) . ' days');
    return [$posted->format('Y-m-d'), $posted->modify('+60 days')->format('Y-m-d')];
}

function hs_lastmod() {
    $t = max(filemtime(__DIR__ . '/data.php'), filemtime(__DIR__ . '/lib.php'));
    return date('Y-m-d', $t);
}

function hs_breadcrumb_ld(array $crumbs) {
    $items = [];
    foreach ($crumbs as $i => $c) {
        $items[] = ['@type' => 'ListItem', 'position' => $i + 1, 'name' => $c[0], 'item' => HS_SITE . $c[1]];
    }
    return ['@context' => 'https://schema.org', '@type' => 'BreadcrumbList', 'itemListElement' => $items];
}
function hs_faq_ld(array $faqs) {
    $q = [];
    foreach ($faqs as $f) $q[] = ['@type' => 'Question', 'name' => $f[0], 'acceptedAnswer' => ['@type' => 'Answer', 'text' => $f[1]]];
    return ['@context' => 'https://schema.org', '@type' => 'FAQPage', 'mainEntity' => $q];
}
function hs_org() {
    return ['@type' => 'Organization', 'name' => 'HamaraService', 'url' => HS_SITE . '/', 'logo' => HS_LOGO];
}

// City-specific advice paragraph for a service (keeps pages genuinely different).
function hs_city_tip(array $s, array $c) {
    $city = $c['name']; $p = $s['plural']; $cl = $c['climate'];
    $base = $city . ' has ' . $c['season'] . '. ';
    switch ($s['tip']) {
        case 'cooling':
            return $base . ($cl === 'coastal'
                ? 'Salty, humid air corrodes AC coils and outdoor units faster here, so a service every few months keeps cooling efficient and bills lower.'
                : ($cl === 'mild'
                    ? 'ACs run fewer hours than in hotter cities, but filters still clog with dust — a service before the warm months keeps cooling efficient.'
                    : 'AC technicians are busiest just before and during peak summer, so booking a service early avoids the rush and long waits for repairs.'));
        case 'cleaning':
            return $base . ($cl === 'coastal'
                ? 'Humid air makes dust and grease stick, so kitchens and floors need deeper cleaning more often. Festival weeks and move-in dates are the busiest times — book a few days ahead.'
                : 'Dry, dusty months leave fine dust on fans, grills and windows. Festival weeks and move-in dates are the busiest times for ' . $p . ' — book a few days ahead.');
        case 'damp':
            return $base . ($cl === 'coastal'
                ? 'In humid weather bathrooms, sofas and wooden doors pick up mould, odour and swelling faster, so regular cleaning and timely repairs help.'
                : 'The monsoon months bring damp walls, swollen doors and musty upholstery; work done just before or after the rains lasts longest.');
        case 'pest':
            return $base . ($cl === 'coastal' || $cl === 'mild'
                ? 'Warm, humid weather keeps cockroaches, mosquitoes and termites active for most of the year; a treatment before and after the monsoon works best.'
                : 'Pests move indoors during the monsoon and the hottest months; a treatment before the rains, with a follow-up, works best.');
        case 'plumbing':
            return $base . ($cl === 'coastal'
                ? 'Humidity and salt in the air corrode older fittings and pipes, so leaking taps and joints are common calls for ' . $p . '.'
                : 'Hard water and summer water-supply changes cause tap scaling, low pressure, motor and tank problems — common calls for ' . $p . '.');
        case 'electrical':
            return $base . 'Heavy summer power load and monsoon storms trip MCBs, burn switches and damage appliances — some of the most common calls for ' . $p . ' here.';
        case 'vehicle':
            return $base . 'Traffic, dust and monsoon roads are hard on vehicles; doorstep service saves a trip to the garage and time in traffic.';
        case 'care':
            return 'Families across ' . $city . ' book ' . $p . ' for planned visits and regular support. Meet the provider, share routines and any medical details, and agree timings in the app before the first visit.';
        case 'beauty':
            return 'Wedding and festival seasons are the busiest times for ' . $p . ' in ' . $city . '; book early for event dates and mention the look or products you prefer in the notes.';
        case 'outdoor':
            return $base . ($cl === 'hot' || $cl === 'extreme'
                ? 'Strong sun and dust mean plants dry out and rooftop surfaces get dusty quickly, so regular visits help.'
                : 'Rain and humidity mean faster plant growth and more frequent trimming and clean-up.');
        default:
            return 'Households across ' . $city . ' book ' . $p . ' for one-time and regular needs. Share the details in the app and agree the price with the provider before confirming.';
    }
}

function hs_cta_button($who, $label, $cls = 'btn-cta') {
    return '<a class="' . $cls . '" data-hs-getapp="' . $who . '" href="/get-app.html?for=' . $who . '">' . $label . '</a>';
}

// ── Layout ───────────────────────────────────────────────
function hs_page_start(array $o) {
    $title = $o['title']; $desc = $o['desc']; $url = HS_SITE . $o['path'];
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: public, max-age=3600');
    $ld = '';
    foreach ($o['ld'] ?? [] as $block) {
        $ld .= '<script type="application/ld+json">' . json_encode($block, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) . "</script>\n";
    }
    $robots = !empty($o['noindex']) ? '<meta name="robots" content="noindex"/>' . "\n" : '';
    echo '<!DOCTYPE html>
<html lang="en-IN">
<head>
<meta charset="UTF-8"/>
<meta name="viewport" content="width=device-width, initial-scale=1"/>
<meta name="theme-color" content="#1B6B7A"/>
<title>' . e($title) . '</title>
<meta name="description" content="' . e($desc) . '"/>
' . $robots . '<link rel="canonical" href="' . e($url) . '"/>
<link rel="icon" href="/favicon.ico" sizes="48x48"/>
<link rel="icon" type="image/png" sizes="192x192" href="/favicon-192x192.png"/>
<link rel="apple-touch-icon" href="/apple-touch-icon.png"/>
<link rel="manifest" href="/site.webmanifest"/>
<meta property="og:type" content="website"/>
<meta property="og:site_name" content="HamaraService"/>
<meta property="og:locale" content="en_IN"/>
<meta property="og:title" content="' . e($title) . '"/>
<meta property="og:description" content="' . e($desc) . '"/>
<meta property="og:url" content="' . e($url) . '"/>
<meta property="og:image" content="' . HS_OG . '"/>
<meta property="og:image:width" content="1200"/>
<meta property="og:image:height" content="630"/>
<meta name="twitter:card" content="summary_large_image"/>
<meta name="twitter:title" content="' . e($title) . '"/>
<meta name="twitter:description" content="' . e($desc) . '"/>
<meta name="twitter:image" content="' . HS_OG . '"/>
' . $ld . '<style>' . hs_css() . '</style>
</head>
<body>
<header class="hd"><div class="wrap hd-in">
  <a class="hd-logo" href="/"><img src="/images/rlogo-tight.png" alt="HamaraService" width="132" height="36"/></a>
  <nav class="hd-nav"><a href="/cities">Services</a><a href="/jobs">Jobs</a>' . hs_cta_button('customer', 'Get the app', 'hd-app') . '</nav>
</div></header>
';
}

function hs_breadcrumbs_html(array $crumbs) {
    $h = '<nav class="crumbs" aria-label="Breadcrumb">';
    $n = count($crumbs);
    foreach ($crumbs as $i => $c) {
        $h .= $i < $n - 1 ? '<a href="' . e($c[1]) . '">' . e($c[0]) . '</a><span>›</span>' : '<span aria-current="page">' . e($c[0]) . '</span>';
    }
    return $h . '</nav>';
}

// Footer link block — also injected into index.html and svc-*.html by build-static.php.
function hs_footer_links_html() {
    $C = hs_cities(); $S = hs_services();
    $cities = '';
    foreach (hs_popular_cities() as $cs) $cities .= '<a href="/services-in-' . $cs . '">' . e($C[$cs]['name']) . '</a>';
    $pop = [['electrician','hyderabad'],['plumber','visakhapatnam'],['ac-service','vijayawada'],['house-maid','bengaluru'],
            ['driver','mumbai'],['pest-control','chennai'],['beauty-parlour-at-home','pune'],['car-wash','delhi'],
            ['deep-cleaning','kolkata'],['appliance-repair','ahmedabad'],['electrician','visakhapatnam'],['cook','hyderabad']];
    $searches = '';
    foreach ($pop as $p) $searches .= '<a href="' . hs_svc_url($p[0], $p[1]) . '">' . e($S[$p[0]]['name'] . ' in ' . $C[$p[1]]['name']) . '</a>';
    $jp = [['driver','hyderabad'],['electrician','visakhapatnam'],['beauty-parlour','pune'],['plumber','vijayawada'],
           ['pest-control','delhi'],['house-maid','mumbai'],['ac-technician','chennai'],['security-guard','bengaluru']];
    $jobs = '';
    foreach ($jp as $p) { [, $s] = hs_find_job($p[0]); $jobs .= '<a href="' . hs_job_url($p[0], $p[1]) . '">' . e($s['job_title'] . ' jobs in ' . $C[$p[1]]['name']) . '</a>'; }
    return '<div class="hs-fl"><div><h4>Popular cities</h4>' . $cities . '<a href="/cities">All cities →</a></div>'
         . '<div><h4>Popular searches</h4>' . $searches . '</div>'
         . '<div><h4>Partner jobs</h4>' . $jobs . '<a href="/jobs">All partner jobs →</a></div></div>';
}

function hs_page_end() {
    echo '<footer class="ft"><div class="wrap">
' . hs_footer_links_html() . '
<div class="ft-bot">
  <div><a href="/">Home</a> · <a href="/get-app.html">Get the app</a> · <a href="/legal-pages.html#contact">Contact</a> · <a href="/legal-pages.html#privacy">Privacy</a> · <a href="/legal-pages.html#terms">Terms</a> · <a href="/legal-pages.html#refund">Refunds</a></div>
  <div>📞 <a href="tel:+919346220090">+91 93462 20090</a> · ✉️ <a href="mailto:info@hamaraservice.com">info@hamaraservice.com</a></div>
  <div class="muted">© ' . date('Y') . ' HamaraService. Bookings and partner registration happen only in the HamaraService Android apps.</div>
</div></div></footer>
<script src="/js/hs-site.js?v=20261023"></script>
<script src="/js/hs-track.js?v=20261023" defer></script>
</body>
</html>';
}

function hs_not_found() {
    http_response_code(404);
    hs_page_start(['title' => 'Page not found | HamaraService', 'desc' => 'This page does not exist.', 'path' => '/cities', 'noindex' => true]);
    echo '<main class="wrap sec"><h1>Page not found</h1><p>We could not find that page. Browse <a href="/cities">services by city</a> or <a href="/jobs">partner jobs</a>.</p></main>';
    hs_page_end();
    exit;
}

function hs_steps_customer() {
    return [
        ['Install the app', 'Get the HamaraService app on your Android phone and choose the service.'],
        ['Describe the job', 'Pick the tasks, your address and a time — now or later.'],
        ['Get a quote', 'Nearby providers send their price. You see it before you confirm — accept, counter or decline.'],
        ['OTP start & finish', 'Share the start OTP when the provider arrives and the completion OTP when the work is done.'],
        ['Pay after the work', 'Pay online in the app, or in cash where the provider allows it, and rate the service.'],
    ];
}
function hs_steps_partner() {
    return [
        ['Install the Partner app', 'Download the HamaraService Partner app on your Android phone.'],
        ['Register', 'Add your name, phone, the services you offer, your work area and a government ID.'],
        ['Get approved', 'Our team reviews your details and ID before your account goes live.'],
        ['Go online & quote', 'Receive job requests near you and send your own price for each job.'],
        ['Work & earn', 'Start and finish each job with customer OTPs, then withdraw your earnings from the app.'],
    ];
}
function hs_steps_html(array $steps) {
    $h = '<ol class="steps">';
    foreach ($steps as $i => $s) $h .= '<li><b>' . ($i + 1) . '. ' . e($s[0]) . '</b><span>' . e($s[1]) . '</span></li>';
    return $h . '</ol>';
}
function hs_faq_html(array $faqs) {
    $h = '<div class="faq">';
    foreach ($faqs as $f) $h .= '<details><summary>' . e($f[0]) . '</summary><p>' . e($f[1]) . '</p></details>';
    return $h . '</div>';
}

function hs_css() {
    return ':root{--teal:#1B6B7A;--teal2:#134F5C;--tsoft:#e6f4f6;--or:#E8651A;--or2:#c9510f;--osoft:#fff3eb;--ink:#17262b;--muted:#5d6f75;--line:#dde8eb;--bg:#f3f8f9}
*{box-sizing:border-box}html{-webkit-text-size-adjust:100%}
body{margin:0;font-family:system-ui,-apple-system,"Segoe UI",Roboto,sans-serif;color:var(--ink);background:var(--bg);line-height:1.6;font-size:16px}
a{color:var(--teal)}img{max-width:100%}
.wrap{max-width:1040px;margin:0 auto;padding:0 16px}
.hd{background:#fff;border-bottom:1px solid var(--line);position:sticky;top:0;z-index:20}
.hd-in{display:flex;align-items:center;justify-content:space-between;height:60px;gap:10px}
.hd-logo img{height:36px;width:auto;display:block}
.hd-nav{display:flex;align-items:center;gap:14px;font-weight:700;font-size:14px}
.hd-nav a{text-decoration:none;color:var(--ink)}
.hd-nav a.hd-app{background:var(--or);color:#fff;padding:8px 14px;border-radius:10px;white-space:nowrap}
@media(max-width:420px){.hd-nav{gap:10px;font-size:13px}.hd-nav a.hd-app{padding:7px 10px}.hd-logo img{height:30px}}
.hero{background:linear-gradient(135deg,var(--teal2),var(--teal));color:#fff;padding:22px 0 30px}
.hero.job{background:linear-gradient(135deg,#7a3410,var(--or2) 55%,var(--or))}
.crumbs{font-size:13px;color:rgba(255,255,255,.75);margin-bottom:12px;display:flex;flex-wrap:wrap;gap:6px}
.crumbs a{color:#fff;text-decoration:none}
.hero h1{font-size:clamp(24px,5vw,38px);line-height:1.2;margin:0 0 12px}
.hero p{margin:0 0 16px;color:rgba(255,255,255,.9);max-width:760px}
.chips-h{display:flex;flex-wrap:wrap;gap:8px;margin:0 0 18px}
.chips-h span{background:rgba(255,255,255,.14);border:1px solid rgba(255,255,255,.25);border-radius:100px;padding:4px 12px;font-size:13px;font-weight:600}
.cta-row{display:flex;flex-wrap:wrap;gap:10px}
.btn-cta{display:inline-block;background:var(--or);color:#fff;text-decoration:none;font-weight:800;padding:14px 22px;border-radius:12px;font-size:16px;box-shadow:0 6px 18px rgba(0,0,0,.18)}
.hero.job .btn-cta{background:#fff;color:var(--or2)}
.btn-sec{display:inline-block;color:#fff;text-decoration:none;font-weight:700;padding:13px 18px;border-radius:12px;border:1.5px solid rgba(255,255,255,.5)}
.sec{padding:26px 0 6px}
.sec h2{font-size:clamp(20px,3.5vw,26px);margin:0 0 12px;line-height:1.25}
.sec h3{font-size:17px;margin:18px 0 8px}
.card{background:#fff;border:1px solid var(--line);border-radius:16px;padding:18px;box-shadow:0 2px 10px rgba(19,79,92,.05)}
.grid2{display:grid;grid-template-columns:1fr 1fr;gap:16px}
@media(max-width:760px){.grid2{grid-template-columns:1fr}}
.ticks{list-style:none;padding:0;margin:0}
.ticks li{padding:7px 0 7px 26px;position:relative;border-bottom:1px solid #eef3f4}
.ticks li:last-child{border-bottom:0}
.ticks li:before{content:"✓";position:absolute;left:4px;color:var(--teal);font-weight:800}
.price{width:100%;border-collapse:collapse;font-size:15px}
.price td{padding:9px 4px;border-bottom:1px solid #eef3f4}
.price td:last-child{text-align:right;font-weight:800;color:var(--teal2);white-space:nowrap}
.note{font-size:13px;color:var(--muted);margin:10px 0 0}
.steps{list-style:none;padding:0;margin:0;display:grid;grid-template-columns:repeat(5,1fr);gap:12px}
@media(max-width:900px){.steps{grid-template-columns:1fr 1fr}}
@media(max-width:520px){.steps{grid-template-columns:1fr}}
.steps li{background:#fff;border:1px solid var(--line);border-radius:14px;padding:14px}
.steps b{display:block;color:var(--teal2);margin-bottom:4px}
.steps span{font-size:14px;color:var(--muted)}
.areas{display:flex;flex-wrap:wrap;gap:8px;margin:10px 0 0}
.areas span{background:var(--tsoft);color:var(--teal2);border-radius:100px;padding:5px 12px;font-size:13px;font-weight:600}
.faq details{background:#fff;border:1px solid var(--line);border-radius:12px;margin-bottom:8px;padding:0 16px}
.faq summary{cursor:pointer;font-weight:700;padding:13px 0;list-style-position:outside}
.faq p{margin:0 0 14px;color:var(--muted)}
.links{display:flex;flex-wrap:wrap;gap:8px}
.links a{background:#fff;border:1px solid var(--line);border-radius:10px;padding:7px 12px;text-decoration:none;font-size:14px;font-weight:600}
.links a:hover{border-color:var(--teal)}
.tiles{display:grid;grid-template-columns:repeat(auto-fill,minmax(210px,1fr));gap:12px}
.tile{display:block;background:#fff;border:1px solid var(--line);border-radius:14px;padding:14px;text-decoration:none;color:var(--ink)}
.tile:hover{border-color:var(--teal)}
.tile .ic{font-size:26px}.tile b{display:block;margin:4px 0 2px}.tile small{color:var(--muted)}
.band{background:linear-gradient(135deg,var(--teal2),var(--teal));color:#fff;border-radius:18px;padding:22px;margin:30px 0 10px;display:flex;flex-wrap:wrap;align-items:center;justify-content:space-between;gap:14px}
.band.job{background:linear-gradient(135deg,#7a3410,var(--or2))}
.band h2{margin:0 0 4px;font-size:22px}.band p{margin:0;color:rgba(255,255,255,.85)}
.band.job .btn-cta{background:#fff;color:var(--or2)}
.honest{background:var(--osoft);border:1px solid #f7d2bb;border-radius:12px;padding:12px 14px;font-size:14px;margin-top:14px}
.muted{color:var(--muted)}
.ft{background:#0f3d47;color:#cfe3e8;margin-top:36px;padding:28px 0 22px;font-size:14px}
.ft a{color:#fff;text-decoration:none}
.hs-fl{display:grid;grid-template-columns:1fr 1.4fr 1.4fr;gap:18px}
@media(max-width:760px){.hs-fl{grid-template-columns:1fr}}
.hs-fl h4{margin:0 0 8px;color:#fff;font-size:15px}
.hs-fl a{display:inline-block;margin:0 12px 6px 0}
.ft-bot{border-top:1px solid rgba(255,255,255,.15);margin-top:18px;padding-top:14px;display:grid;gap:6px}
.ft .muted{color:#9fbfc6}';
}
