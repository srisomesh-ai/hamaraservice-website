<?php
// /jobs  (all trades and cities)  and  /jobs-in-<city>  (all trades in one city)
require_once __DIR__ . '/lib.php';

$S = hs_services(); $C = hs_cities();
$cs = strtolower((string)($_GET['c'] ?? ''));
if ($cs !== '' && !isset($C[$cs])) hs_not_found();

if ($cs === '') {
    $path = '/jobs';
    $title = 'Partner Jobs in India – Driver, Electrician & More | HamaraService';
    $title = hs_fit([$title, 'Service Partner Jobs by City | HamaraService'], 60);
    $desc = hs_fit(['Driver, electrician, plumber, beautician, maid, AC technician, security guard and more partner jobs in Hyderabad, Vizag, Vijayawada and metros. Join via the app.'], 155);
    $crumbs = [['Home', '/'], ['Partner jobs', $path]];
    hs_page_start(['title' => $title, 'desc' => $desc, 'path' => $path, 'ld' => [hs_breadcrumb_ld($crumbs)]]);
    ?>
<section class="hero job"><div class="wrap">
  <?= hs_breadcrumbs_html($crumbs) ?>
  <h1>Service partner jobs — work on your own terms</h1>
  <p>Join HamaraService as an independent partner: get job requests from customers near you, set your own price and choose your hours. Registration happens in the HamaraService Partner app.</p>
  <div class="cta-row"><?= hs_cta_button('provider', '📲 Install the Partner app') ?></div>
</div></section>
<main class="wrap">
  <section class="sec">
    <h2>Jobs by city</h2>
    <div class="tiles"><?php foreach ($C as $k => $c): ?><a class="tile" href="/jobs-in-<?= e($k) ?>"><span class="ic">📍</span><b><?= e('Jobs in ' . $c['name']) ?></b><small><?= e($c['state']) ?></small></a><?php endforeach; ?></div>
  </section>
  <section class="sec">
    <h2>Jobs by trade</h2>
    <?php foreach ($S as $k => $s): ?>
      <h3><?= $s['icon'] ?> <?= e($s['job_title'] . ' jobs') ?></h3>
      <div class="links"><?php foreach ($C as $ck => $c): ?><a href="<?= hs_job_url($s['job_slug'], $ck) ?>"><?= e($c['name']) ?></a><?php endforeach; ?></div>
    <?php endforeach; ?>
  </section>
  <section class="sec"><h2>How to join</h2><?= hs_steps_html(hs_steps_partner()) ?></section>
  <div class="band job"><div><h2>Ready to get jobs near you?</h2><p>Install the Partner app and register.</p></div><?= hs_cta_button('provider', '📲 Install the Partner app') ?></div>
</main>
<?php
    hs_page_end();
    exit;
}

$c = $C[$cs]; $city = $c['name'];
$path = '/jobs-in-' . $cs;
$locs = hs_rotate($c['localities'], 'jobs' . $cs);
$title = hs_fit(["Jobs in $city – Driver, Electrician, Maid & More | HamaraService", "Partner Jobs in $city | HamaraService"], 60);
$desc = hs_fit(["Partner jobs in $city for drivers, electricians, plumbers, maids, beauticians, AC technicians and more. Set your own price, choose your hours. Join via the app."], 155);
$crumbs = [['Home', '/'], ['Partner jobs', '/jobs'], ['Jobs in ' . $city, $path]];
hs_page_start(['title' => $title, 'desc' => $desc, 'path' => $path, 'ld' => [hs_breadcrumb_ld($crumbs)]]);
?>
<section class="hero job"><div class="wrap">
  <?= hs_breadcrumbs_html($crumbs) ?>
  <h1><?= e("Partner jobs in $city") ?></h1>
  <p><?= e("Get customer job requests near " . hs_join(array_slice($locs, 0, 4)) . " and across $city. Choose your trade below to see the work, requirements and how to join.") ?></p>
  <div class="cta-row"><?= hs_cta_button('provider', '📲 Install the Partner app') ?><a class="btn-sec" href="/services-in-<?= e($cs) ?>">Need a service in <?= e($c['short']) ?>? →</a></div>
</div></section>
<main class="wrap">
  <section class="sec">
    <h2><?= e("Trades hiring partners in $city") ?></h2>
    <div class="tiles"><?php foreach ($S as $k => $s): ?><a class="tile" href="<?= hs_job_url($s['job_slug'], $cs) ?>"><span class="ic"><?= $s['icon'] ?></span><b><?= e($s['job_title'] . ' jobs') ?></b><small><?= e($s['job_work']) ?></small></a><?php endforeach; ?></div>
  </section>
  <section class="sec"><h2>How to join</h2><?= hs_steps_html(hs_steps_partner()) ?>
    <div class="honest">Independent partner work paid per job — not a salaried position. You set your own price; a small platform fee applies per completed job.</div></section>
  <section class="sec"><h2>Jobs in other cities</h2>
    <div class="links"><?php foreach ($C as $k => $o) if ($k !== $cs): ?><a href="/jobs-in-<?= e($k) ?>"><?= e('Jobs in ' . $o['name']) ?></a><?php endif; ?></div></section>
  <div class="band job"><div><h2><?= e("Start working with HamaraService in $city") ?></h2><p>Install the Partner app and register.</p></div><?= hs_cta_button('provider', '📲 Install the Partner app') ?></div>
</main>
<?php hs_page_end();
