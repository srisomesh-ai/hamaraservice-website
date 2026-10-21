<?php
// ═══════════════════════════════════════════════════════
// CLI ONLY:  php seo/build-static.php
// Writes SEO head tags (title, description, canonical, Open Graph, JSON-LD)
// and the "Popular cities / Popular searches / Partner jobs" link block into
// the static pages (svc-*.html, index.html) from seo/data.php.
// Safe to re-run: it only rewrites the parts between its HS-SEO markers.
// ═══════════════════════════════════════════════════════
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/lib.php';

$root = dirname(__DIR__);
$S = hs_services(); $C = hs_cities();

function hs_replace_block($html, $name, $content) {
    $re = '/<!-- ' . $name . ':start -->.*?<!-- ' . $name . ':end -->/s';
    $block = '<!-- ' . $name . ':start -->' . $content . '<!-- ' . $name . ':end -->';
    if (preg_match($re, $html)) return preg_replace_callback($re, function () use ($block) { return $block; }, $html, 1);
    return null;
}

$linksCss = '<style>
.hs-seo-links{background:#0f3d47;color:#cfe3e8;padding:26px 16px 120px;font-family:system-ui,-apple-system,"Segoe UI",sans-serif;font-size:14px;line-height:1.6}
.hs-seo-links .in{max-width:1040px;margin:0 auto}
.hs-seo-links h3{color:#fff;font-size:16px;margin:0 0 8px}
.hs-seo-links a{color:#fff;text-decoration:none;display:inline-block;margin:0 12px 6px 0}
.hs-seo-links .row{margin-bottom:16px}
.hs-fl{display:grid;grid-template-columns:1fr 1.4fr 1.4fr;gap:18px}
@media(max-width:760px){.hs-fl{grid-template-columns:1fr}}
.hs-fl h4{margin:0 0 8px;color:#fff;font-size:15px}
.hs-fl a{display:inline-block;margin:0 12px 6px 0}
footer .hs-fl{margin:0 0 32px;font-size:14px}
footer .hs-fl h4{font-size:13px;text-transform:uppercase;letter-spacing:.8px;color:rgba(255,255,255,.85)}
footer .hs-fl a{color:rgba(255,255,255,.55);text-decoration:none}
footer .hs-fl a:hover{color:#fff}
</style>';

$n = 0;
foreach ($S as $sk => $s) {
    $file = "$root/{$s['page']}";
    $html = file_get_contents($file);
    $path = '/' . $s['page'];
    $title = hs_fit([
        "{$s['title']} – Prices & Booking | HamaraService",
        "{$s['name']} – Prices & Booking | HamaraService",
        "{$s['name']} | HamaraService",
    ], 60);
    $desc = hs_fit([
        "{$s['desc']} From " . hs_price($s['from']) . " ({$s['unit']}). See options and prices, then book in the HamaraService app.",
        "{$s['desc']} See options and prices, then book in the HamaraService app.",
    ], 155);
    $areas = [];
    foreach ($C as $c) $areas[] = ['@type' => 'City', 'name' => $c['name']];
    $ld = ['@context' => 'https://schema.org', '@type' => 'Service', 'name' => $s['title'], 'serviceType' => $s['title'],
           'description' => $s['desc'], 'url' => HS_SITE . $path, 'provider' => hs_org(), 'areaServed' => $areas,
           'availableChannel' => ['@type' => 'ServiceChannel', 'serviceUrl' => HS_SITE . '/get-app.html?for=customer', 'name' => 'HamaraService Android app']];
    $head = "\n" . '<meta name="description" content="' . e($desc) . '"/>
<link rel="canonical" href="' . HS_SITE . $path . '"/>
<link rel="icon" href="/images/rlogo-tight.png"/>
<meta property="og:type" content="website"/>
<meta property="og:site_name" content="HamaraService"/>
<meta property="og:locale" content="en_IN"/>
<meta property="og:title" content="' . e($title) . '"/>
<meta property="og:description" content="' . e($desc) . '"/>
<meta property="og:url" content="' . HS_SITE . $path . '"/>
<meta property="og:image" content="' . HS_OG . '"/>
<meta property="og:image:width" content="1200"/>
<meta property="og:image:height" content="630"/>
<meta name="twitter:card" content="summary_large_image"/>
<meta name="twitter:title" content="' . e($title) . '"/>
<meta name="twitter:description" content="' . e($desc) . '"/>
<meta name="twitter:image" content="' . HS_OG . '"/>
<script type="application/ld+json">' . json_encode($ld, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) . '</script>' . "\n";

    $cityLinks = ''; $jobLinks = '';
    foreach ($C as $ck => $c) {
        $cityLinks .= '<a href="' . hs_svc_url($sk, $ck) . '">' . e($s['name'] . ' in ' . $c['name']) . '</a>';
        $jobLinks  .= '<a href="' . hs_job_url($s['job_slug'], $ck) . '">' . e($c['name']) . '</a>';
    }
    $links = "\n" . $linksCss . '
<section class="hs-seo-links" id="hsSeoLinks"><div class="in">
<div class="row"><h3>' . e($s['name']) . ' by city</h3>' . $cityLinks . '</div>
<div class="row"><h3>' . e($s['job_title']) . ' jobs</h3>' . $jobLinks . '</div>
' . hs_footer_links_html() . '
<div class="row" style="margin-top:10px"><a href="/">Home</a><a href="/get-app.html">Get the app</a><a href="/cities">Cities</a><a href="/jobs">Partner jobs</a><a href="/legal-pages.html#contact">Contact</a> · 📞 <a href="tel:+919346220090">+91 93462 20090</a></div>
</div></section>
<script>window.__hsSeoLinks=document.getElementById("hsSeoLinks").outerHTML;</script>
<script src="/js/hs-site.js"></script>
<script src="/js/hs-svc-app.js" defer></script>
';

    // <html lang>, <title>
    $html = preg_replace('/<html lang="[^"]*">/', '<html lang="en-IN">', $html, 1);
    $html = preg_replace('/<title>.*?<\/title>/s', '<title>' . e($title) . '</title>', $html, 1);
    // head block
    $r = hs_replace_block($html, 'HS-SEO-HEAD', $head);
    if ($r === null) {
        $html = preg_replace('/(<title>.*?<\/title>)/s', '$1' . "\n" . '<!-- HS-SEO-HEAD:start -->' . str_replace('$', '\\$', $head) . '<!-- HS-SEO-HEAD:end -->', $html, 1);
    } else $html = $r;
    // links block + scripts before </body>
    $r = hs_replace_block($html, 'HS-SEO-LINKS', $links);
    if ($r === null) {
        $pos = strrpos($html, '</body>');
        $html = substr($html, 0, $pos) . '<!-- HS-SEO-LINKS:start -->' . $links . '<!-- HS-SEO-LINKS:end -->' . "\n" . substr($html, $pos);
    } else $html = $r;
    file_put_contents($file, $html);
    $n++;
}

// index.html footer: popular cities / searches / jobs (markers already in the footer)
$idx = "$root/index.html";
$html = file_get_contents($idx);
$r = hs_replace_block($html, 'HS-SEO-LINKS', "\n" . $linksCss . "\n" . hs_footer_links_html() . "\n    ");
if ($r !== null) { file_put_contents($idx, $r); $n++; }

echo "Updated $n files\n";
