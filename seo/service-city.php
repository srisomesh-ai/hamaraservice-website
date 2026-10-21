<?php
// /<service>-in-<city>  (e.g. /electrician-in-hyderabad) — rewritten here by .htaccess
require_once __DIR__ . '/lib.php';

$S = hs_services(); $C = hs_cities();
$ss = strtolower((string)($_GET['s'] ?? '')); $cs = strtolower((string)($_GET['c'] ?? ''));
if (!isset($S[$ss]) || !isset($C[$cs])) hs_not_found();
$s = $S[$ss]; $c = $C[$cs];
$city = $c['name']; $name = $s['name']; $plural = $s['plural'];
$path = hs_svc_url($ss, $cs);
$locs = hs_rotate($c['localities'], $ss . $cs);
$from = hs_price($s['from']);

$title = hs_fit([
    "$name in $city – Book Near You | HamaraService",
    "$name in $city | HamaraService",
    "$name in {$c['short']} | HamaraService",
    "$name in $city",
], 60);
$desc = hs_fit([
    "Book ID-verified $plural in $city — {$locs[0]}, {$locs[1]} & more. From $from ({$s['unit']}), quote before you confirm, pay after. Get the app.",
    "Book ID-verified $plural in $city. From $from ({$s['unit']}), see the quote before you confirm, pay after the work. Get the app.",
    "Book $plural in $city on the HamaraService app. From $from, quote before you confirm, pay after.",
], 155);

$faqs = $s['faqs'];
$faqs[] = ["How much do $plural charge in $city?",
    "Prices on HamaraService start from $from ({$s['unit']}) as an estimate. The provider who accepts your request quotes the final price in the app, and you confirm only if you agree."];
$faqs[] = ["Do you cover {$locs[0]}, {$locs[1]} and {$locs[2]}?",
    "You can request $plural from any address in $city, including " . hs_join(array_slice($locs, 0, 5)) . ". The app shows whether a provider near you has accepted before you confirm."];
$faqs[] = ["Can I book on this website?",
    "No. Bookings, OTPs and payments happen only in the HamaraService Android app. This page explains the service and prices; install the app to book."];

$crumbs = [['Home', '/'], ['Services in ' . $city, '/services-in-' . $cs], [$name . ' in ' . $city, $path]];
$ld = [
    ['@context' => 'https://schema.org', '@type' => 'Service',
     'name' => "$name in $city", 'serviceType' => $s['title'], 'description' => $s['desc'],
     'url' => HS_SITE . $path, 'provider' => hs_org(),
     'areaServed' => ['@type' => 'City', 'name' => $city, 'containedInPlace' => ['@type' => 'State', 'name' => $c['state']]],
     'availableChannel' => ['@type' => 'ServiceChannel', 'serviceUrl' => HS_SITE . '/get-app.html?for=customer', 'name' => 'HamaraService Android app']],
    hs_breadcrumb_ld($crumbs),
    hs_faq_ld($faqs),
];

hs_page_start(['title' => $title, 'desc' => $desc, 'path' => $path, 'ld' => $ld]);
?>
<section class="hero"><div class="wrap">
  <?= hs_breadcrumbs_html($crumbs) ?>
  <h1><?= e("$name in $city — book verified $plural near you") ?></h1>
  <p><?= e("Looking for $plural in $city? {$s['desc']} Book for any address in " . hs_join(array_slice($locs, 0, 4)) . " or elsewhere in $city through the HamaraService app — see the provider’s price before you confirm and pay only after the work is done.") ?></p>
  <div class="chips-h"><span><?= e("From $from · {$s['unit']}") ?></span><span>ID-verified providers</span><span>OTP start &amp; finish</span><span>Pay after service</span></div>
  <div class="cta-row">
    <?= hs_cta_button('customer', '📲 Install HamaraService app to book') ?>
    <a class="btn-sec" href="/<?= e($s['page']) ?>">See all options &amp; prices →</a>
  </div>
</div></section>

<main class="wrap">
  <section class="sec grid2">
    <div class="card">
      <h2><?= e("$name prices in $city") ?></h2>
      <table class="price">
        <?php foreach ($s['tasks'] as $t): ?><tr><td><?= e($t[0]) ?></td><td><?= e(hs_price($t[1])) ?></td></tr><?php endforeach; ?>
      </table>
      <p class="note">Starting-price estimates. The provider quotes the final price in the app before you confirm; parts and materials, if any, are extra at actual cost.</p>
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

  <section class="sec card">
    <h2><?= e("$name service across $city") ?></h2>
    <p><?= e($c['intro']) ?></p>
    <p><?= e(hs_city_tip($s, $c)) ?></p>
    <p><?= e("You can tell the provider your preferred language — " . $c['lang'] . " or English — and any special instructions in the booking notes.") ?></p>
    <h3>Areas you can book from</h3>
    <div class="areas"><?php foreach ($locs as $l): ?><span><?= e($l) ?></span><?php endforeach; ?></div>
    <div class="honest"><?= e("HamaraService is newly launched in $city. We do not show made-up provider counts or ratings — the app tells you which providers near your address have accepted your request, with their price, before you confirm.") ?></div>
  </section>

  <section class="sec">
    <h2><?= e("$name in $city — FAQs") ?></h2>
    <?= hs_faq_html($faqs) ?>
  </section>

  <section class="sec">
    <h2><?= e("$name in other cities") ?></h2>
    <div class="links"><?php foreach ($C as $k => $o) if ($k !== $cs): ?><a href="<?= hs_svc_url($ss, $k) ?>"><?= e("$name in {$o['name']}") ?></a><?php endif; ?></div>
    <h2 style="margin-top:22px"><?= e("More home services in $city") ?></h2>
    <div class="links"><?php foreach ($S as $k => $o) if ($k !== $ss): ?><a href="<?= hs_svc_url($k, $cs) ?>"><?= e($o['icon'] . ' ' . $o['name']) ?></a><?php endif; ?></div>
    <p class="note"><?= e("Are you a {$s['job_role']}?") ?> <a href="<?= hs_job_url($s['job_slug'], $cs) ?>"><?= e("See {$s['job_title']} jobs in $city →") ?></a></p>
  </section>

  <div class="band">
    <div><h2><?= e("Book $plural in $city") ?></h2><p>Install the HamaraService app — bookings, OTPs and payments happen in the app.</p></div>
    <?= hs_cta_button('customer', '📲 Install the app') ?>
  </div>
</main>
<?php hs_page_end();
