<?php
// /sitemap.xml — rewritten here by .htaccess. Lists every public page.
require_once __DIR__ . '/lib.php';

$root = dirname(__DIR__);
$S = hs_services(); $C = hs_cities();
$seoMod = hs_lastmod();
[$jobsPosted] = hs_job_dates();
$jobsMod = max($seoMod, $jobsPosted);
$urls = [];
$add = function ($path, $mod, $prio) use (&$urls) { $urls[] = [$path, $mod, $prio]; };
$fmod = function ($f) use ($root) { return is_file("$root/$f") ? date('Y-m-d', filemtime("$root/$f")) : date('Y-m-d'); };

$add('/', $fmod('index.html'), '1.0');
$add('/get-app.html', $fmod('get-app.html'), '0.9');
$add('/cities', $seoMod, '0.8');
$add('/jobs', $jobsMod, '0.8');
foreach ($S as $s) $add('/' . $s['page'], $fmod($s['page']), '0.8');
foreach ($C as $ck => $c) {
    $add('/services-in-' . $ck, $seoMod, '0.8');
    $add('/jobs-in-' . $ck, $jobsMod, '0.7');
}
foreach ($S as $sk => $s) foreach ($C as $ck => $c) $add(hs_svc_url($sk, $ck), $seoMod, '0.7');
foreach ($S as $sk => $s) foreach ($C as $ck => $c) $add(hs_job_url($s['job_slug'], $ck), $jobsMod, '0.6');
$add('/legal-pages.html', $fmod('legal-pages.html'), '0.3');

header('Content-Type: application/xml; charset=utf-8');
header('Cache-Control: public, max-age=3600');
echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
foreach ($urls as $u) {
    echo '  <url><loc>' . htmlspecialchars(HS_SITE . $u[0], ENT_XML1) . '</loc><lastmod>' . $u[1] . '</lastmod><priority>' . $u[2] . "</priority></url>\n";
}
echo "</urlset>\n";
