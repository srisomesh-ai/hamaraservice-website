<?php
// /<service>  (e.g. /anchor-emcee) — service overview page for services that
// have no hand-made svc-*.html page. Rewritten here by .htaccess; everything
// comes from seo/data.php, so a new service only needs a data.php entry.
require_once __DIR__ . '/lib.php';

$S = hs_services(); $C = hs_cities();
$ss = strtolower((string)($_GET['s'] ?? ''));
if (!isset($S[$ss]) || $S[$ss]['page'] !== $ss) hs_not_found();
$s = $S[$ss];
$name = $s['name']; $plural = $s['plural'];
$path = '/' . $ss;
$from = hs_price($s['from']);

$title = hs_fit([
    "{$s['title']} – Prices & Booking | HamaraService",
    "$name – Prices & Booking | HamaraService",
    "$name | HamaraService",
], 60);
$desc = hs_fit([
    "{$s['desc']} From $from ({$s['unit']}).",
    $s['desc'],
], 155);

$faqs = $s['faqs'];
$faqs[] = ["How much does it cost?",
    "Prices start from $from ({$s['unit']}) as an estimate. The provider who accepts your request quotes the final price in the app, and you confirm only if you agree."];
$faqs[] = ["Which cities do you cover?",
    "You can request $plural in " . count($C) . " cities across India, including " . hs_join(array_map(fn($k) => $C[$k]['name'], array_slice(hs_popular_cities(), 0, 6))) . "."];

$crumbs = [['Home', '/'], [$name, $path]];
$areas = [];
foreach ($C as $c) $areas[] = ['@type' => 'City', 'name' => $c['name']];
$ld = [
    ['@context' => 'https://schema.org', '@type' => 'Service', 'name' => $s['title'], 'serviceType' => $s['title'],
     'description' => $s['desc'], 'url' => HS_SITE . $path, 'provider' => hs_org(), 'areaServed' => $areas,
     'availableChannel' => ['@type' => 'ServiceChannel', 'serviceUrl' => HS_SITE . '/get-app.html?for=customer', 'name' => 'HamaraService app']],
    hs_breadcrumb_ld($crumbs),
    hs_faq_ld($faqs),
];

hs_page_start(['title' => $title, 'desc' => $desc, 'path' => $path, 'ld' => $ld]);
$book = 'HS_getApp(\'customer\',\'?svc=' . e($s['id']) . '\');return false;';
?>
<section class="hero"><div class="wrap">
  <?= hs_breadcrumbs_html($crumbs) ?>
  <h1><?= e($s['icon'] . ' ' . $s['title']) ?></h1>
  <p><?= e($s['desc']) ?></p>
  <div class="chips-h"><span><?= e("From $from · {$s['unit']}") ?></span><span>Verified partners</span><span>See the quote before you confirm</span><span>Pay after the event</span></div>
  <div class="cta-row">
    <a class="btn-cta" href="/get-app.html?for=customer" onclick="<?= $book ?>">📲 Book <?= e($name) ?></a>
    <a class="btn-sec" href="<?= e(hs_job_url($s['job_slug'], 'hyderabad')) ?>">Are you <?= e(preg_match('/^[aeiou]/i', $s['job_role']) ? 'an' : 'a') ?> <?= e($s['job_role']) ?>? Join →</a>
  </div>
</div></section>

<main class="wrap">
  <section class="sec grid2">
    <div class="card">
      <h2><?= e("$name prices") ?></h2>
      <table class="price">
        <?php foreach ($s['tasks'] as $t): ?><tr><td><?= e($t[0]) ?></td><td><?= e(hs_price($t[1])) ?></td></tr><?php endforeach; ?>
      </table>
      <p class="note">Starting-price estimates. The partner quotes the final price in the app before you confirm.</p>
    </div>
    <div class="card">
      <h2>What’s included</h2>
      <ul class="ticks"><?php foreach ($s['included'] as $i): ?><li><?= e($i) ?></li><?php endforeach; ?></ul>
    </div>
  </section>

  <section class="sec">
    <h2>How booking works</h2>
    <?= hs_steps_html(hs_steps_customer()) ?>
  </section>

  <section class="sec">
    <h2><?= e("$name by city") ?></h2>
    <div class="links">
      <?php foreach (hs_popular_cities() as $k): ?><a href="<?= hs_svc_url($ss, $k) ?>"><?= e("$name in {$C[$k]['name']}") ?></a><?php endforeach; ?>
      <a href="/cities">All cities →</a>
    </div>
  </section>

  <section class="sec">
    <h2>Frequently asked questions</h2>
    <?= hs_faq_html($faqs) ?>
  </section>

  <section class="sec card">
    <h2><?= e("Work as {$s['job_title']} with HamaraService") ?></h2>
    <p><?= e($s['job_work']) ?></p>
    <div class="links">
      <?php foreach (hs_popular_cities() as $k): ?><a href="<?= hs_job_url($s['job_slug'], $k) ?>"><?= e("{$s['job_title']} jobs in {$C[$k]['name']}") ?></a><?php endforeach; ?>
    </div>
  </section>

  <div class="band"><div><h2><?= e("Book $name") ?></h2><p>Booking, quotes, OTPs and payments happen in the HamaraService app — or on the web from an iPhone or computer.</p></div>
    <a class="btn-cta" href="/get-app.html?for=customer" onclick="<?= $book ?>">📲 Book now</a></div>
</main>
<?php hs_page_end();
