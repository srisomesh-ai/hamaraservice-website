<?php
// /services-in-<city>  (e.g. /services-in-hyderabad) — city hub of all services
require_once __DIR__ . '/lib.php';

$S = hs_services(); $C = hs_cities();
$cs = strtolower((string)($_GET['c'] ?? ''));
if (!isset($C[$cs])) hs_not_found();
$c = $C[$cs]; $city = $c['name'];
$path = '/services-in-' . $cs;
$locs = hs_rotate($c['localities'], 'hub' . $cs);

$title = hs_fit(["Home Services in $city – Electricians, Maids & More | HamaraService", "Home Services in $city | HamaraService"], 60);
$desc = hs_fit(["Book electricians, plumbers, maids, AC service, cleaning, drivers, beauty at home and 30+ services in $city. Quote before you confirm, pay after. Get the app."], 155);

$cats = [];
foreach ($S as $k => $s) $cats[$s['cat']][$k] = $s;
$crumbs = [['Home', '/'], ['Cities', '/cities'], ['Services in ' . $city, $path]];
$items = []; $i = 0;
foreach ($S as $k => $s) $items[] = ['@type' => 'ListItem', 'position' => ++$i, 'name' => $s['name'] . ' in ' . $city, 'url' => HS_SITE . hs_svc_url($k, $cs)];
$ld = [hs_breadcrumb_ld($crumbs), ['@context' => 'https://schema.org', '@type' => 'ItemList', 'name' => 'Home services in ' . $city, 'itemListElement' => $items]];

hs_page_start(['title' => $title, 'desc' => $desc, 'path' => $path, 'ld' => $ld]);
?>
<section class="hero"><div class="wrap">
  <?= hs_breadcrumbs_html($crumbs) ?>
  <h1><?= e("Home services in $city") ?></h1>
  <p><?= e($c['intro'] . " Book ID-verified professionals for repairs, cleaning, appliances, vehicles, beauty, care and more — from " . hs_join(array_slice($locs, 0, 4)) . " and every other part of $city.") ?></p>
  <div class="cta-row"><?= hs_cta_button('customer', '📲 Install HamaraService app') ?><a class="btn-sec" href="/jobs-in-<?= e($cs) ?>">Work with us in <?= e($c['short']) ?> →</a></div>
</div></section>
<main class="wrap">
<?php foreach ($cats as $cat => $list): ?>
  <section class="sec">
    <h2><?= e($cat) ?></h2>
    <div class="tiles"><?php foreach ($list as $k => $s): ?>
      <a class="tile" href="<?= hs_svc_url($k, $cs) ?>"><span class="ic"><?= $s['icon'] ?></span><b><?= e($s['name'] . ' in ' . $c['short']) ?></b><small><?= e('From ' . hs_price($s['from']) . ' · ' . $s['unit']) ?></small></a>
    <?php endforeach; ?></div>
  </section>
<?php endforeach; ?>
  <section class="sec card">
    <h2><?= e("Booking a service in $city") ?></h2>
    <?= hs_steps_html(hs_steps_customer()) ?>
    <h3>Areas</h3>
    <div class="areas"><?php foreach ($locs as $l): ?><span><?= e($l) ?></span><?php endforeach; ?></div>
    <div class="honest"><?= e("HamaraService is newly launched in $city. Prices shown are starting estimates; the provider’s quote in the app is the final price you confirm.") ?></div>
  </section>
  <section class="sec">
    <h2>Other cities</h2>
    <div class="links"><?php foreach ($C as $k => $o) if ($k !== $cs): ?><a href="/services-in-<?= e($k) ?>"><?= e('Services in ' . $o['name']) ?></a><?php endif; ?></div>
  </section>
  <div class="band"><div><h2><?= e("Book in $city with the app") ?></h2><p>Bookings, OTPs and payments happen in the HamaraService app.</p></div><?= hs_cta_button('customer', '📲 Install the app') ?></div>
</main>
<?php hs_page_end();
