<?php
// /cities — every city with links to its services and partner jobs
require_once __DIR__ . '/lib.php';

$S = hs_services(); $C = hs_cities();
$path = '/cities';
$crumbs = [['Home', '/'], ['Cities', $path]];
$top = ['electrician', 'plumber', 'ac-service', 'house-maid', 'deep-cleaning', 'driver'];
hs_page_start([
    'title' => 'Cities – Home Services & Partner Jobs | HamaraService',
    'desc'  => 'Home services and partner jobs in ' . count($C) . ' Indian cities — Vizag, Hyderabad, Vijayawada, Guntur, Tirupati, Warangal, Bengaluru, Chennai, Mumbai, Delhi.',
    'path'  => $path, 'ld' => [hs_breadcrumb_ld($crumbs)],
]);
?>
<section class="hero"><div class="wrap">
  <?= hs_breadcrumbs_html($crumbs) ?>
  <h1>Cities we serve</h1>
  <p>Book home services or join as a service partner in these cities. Bookings and registration happen in the HamaraService Android apps.</p>
  <div class="cta-row"><?= hs_cta_button('customer', '📲 Install HamaraService app') ?><a class="btn-sec" href="/jobs">Partner jobs →</a></div>
</div></section>
<main class="wrap">
  <section class="sec card" style="margin-top:18px">
    <h2>Popular cities</h2>
    <div class="links"><?php foreach (hs_popular_cities() as $k): ?><a href="/services-in-<?= e($k) ?>"><?= e($C[$k]['name']) ?></a><?php endforeach; ?></div>
  </section>
<?php foreach (hs_cities_by_state() as $st => $list): ?>
  <section class="sec card" style="margin-top:18px">
    <h2><?= e($st) ?> <small class="muted" style="font-size:14px"><?= count($list) ?> <?= count($list) === 1 ? 'city' : 'cities' ?></small></h2>
    <?php foreach ($list as $k => $c): ?>
      <h3 style="margin:14px 0 6px"><a href="/services-in-<?= e($k) ?>" style="text-decoration:none"><?= e($c['name']) ?></a></h3>
      <div class="links">
        <a href="/services-in-<?= e($k) ?>"><?= e('All services in ' . $c['short']) ?></a>
        <?php foreach ($top as $t): ?><a href="<?= hs_svc_url($t, $k) ?>"><?= e($S[$t]['name']) ?></a><?php endforeach; ?>
        <a href="/jobs-in-<?= e($k) ?>"><?= e('Partner jobs in ' . $c['short']) ?></a>
      </div>
    <?php endforeach; ?>
  </section>
<?php endforeach; ?>
</main>
<?php hs_page_end();
