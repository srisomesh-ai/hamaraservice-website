<?php
// /cities — every city with links to its services and partner jobs
require_once __DIR__ . '/lib.php';

$S = hs_services(); $C = hs_cities();
$path = '/cities';
$crumbs = [['Home', '/'], ['Cities', $path]];
$top = ['electrician', 'plumber', 'ac-service', 'house-maid', 'deep-cleaning', 'driver'];
hs_page_start([
    'title' => 'Cities – Home Services & Partner Jobs | HamaraService',
    'desc'  => 'Book home services or join as a partner in Visakhapatnam, Hyderabad, Vijayawada, Delhi NCR, Mumbai, Bengaluru, Chennai, Kolkata, Pune and Ahmedabad.',
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
<?php foreach ($C as $k => $c): ?>
  <section class="sec card" style="margin-top:18px">
    <h2><a href="/services-in-<?= e($k) ?>" style="text-decoration:none"><?= e($c['name']) ?></a> <small class="muted" style="font-size:14px"><?= e($c['state']) ?></small></h2>
    <p><?= e($c['intro']) ?></p>
    <div class="links">
      <a href="/services-in-<?= e($k) ?>"><?= e('All services in ' . $c['name']) ?></a>
      <?php foreach ($top as $t): ?><a href="<?= hs_svc_url($t, $k) ?>"><?= e($S[$t]['name']) ?></a><?php endforeach; ?>
      <a href="/jobs-in-<?= e($k) ?>"><?= e('Partner jobs in ' . $c['name']) ?></a>
    </div>
  </section>
<?php endforeach; ?>
</main>
<?php hs_page_end();
