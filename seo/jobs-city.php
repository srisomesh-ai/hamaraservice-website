<?php
// /<trade>-jobs-in-<city>  (e.g. /driver-jobs-in-hyderabad) — rewritten here by .htaccess
require_once __DIR__ . '/lib.php';

$S = hs_services(); $C = hs_cities();
$js = strtolower((string)($_GET['t'] ?? '')); $cs = strtolower((string)($_GET['c'] ?? ''));
[$ss, $s] = hs_find_job($js);
if (!$s || !isset($C[$cs])) hs_not_found();
$c = $C[$cs]; $city = $c['name']; $jt = $s['job_title']; $role = $s['job_role'];
$path = hs_job_url($js, $cs);
$locs = hs_rotate($c['localities'], 'job' . $js . $cs);
[$posted, $valid] = hs_job_dates();

$title = hs_fit([
    "$jt Jobs in $city – Join as Partner | HamaraService",
    "$jt Jobs in $city | HamaraService",
    ucfirst($role) . " Jobs in $city | HamaraService",
    ucfirst($role) . " Jobs in {$c['short']} | HamaraService",
], 60);
$desc = hs_fit([
    "$jt jobs in $city: get customer requests near {$locs[0]}, {$locs[1]} & more. Set your own price, choose your hours. Register in the Partner app.",
    "$jt jobs in $city: get customer requests near you, set your own price and choose your hours. Register in the HamaraService Partner app.",
    ucfirst($role) . " jobs in $city — set your own price, choose your hours. Join the HamaraService Partner app.",
], 155);

$req = array_merge(['Government photo ID (e.g. Aadhaar, PAN, voter ID or driving licence)'], $s['job_req'],
    ['Android smartphone with internet to receive jobs and OTPs', 'Age 18 or above, willing to work in ' . $city]);
$perks = [
    ['Set your own price', 'You quote each job yourself. Customers see your price before they confirm.'],
    ['Choose your hours', 'Go online when you want to work and offline when you do not.'],
    ['Jobs near you', 'Requests come from customers within the area you choose in ' . $city . '.'],
    ['OTP-secured jobs', 'Every job starts and finishes with the customer’s OTP, so work is recorded properly.'],
    ['Clear platform fee', 'A small platform fee per completed job — shown in the app, no hidden cuts.'],
    ['Withdraw earnings', 'Online payments go to your partner wallet; withdraw them from the app.'],
];
$faqs = [
    ['Is this a salaried job?', 'No. You join as an independent service partner. Customers pay for each job you complete, and HamaraService charges a small platform fee per completed job. There is no fixed salary or guaranteed number of jobs.'],
    ['How much can I earn?', 'You set your own price for every job. For context, customers in ' . $city . ' see ' . strtolower($s['name']) . ' prices starting from ' . hs_price($s['from']) . ' (' . $s['unit'] . ') in the app, but your earnings depend on your prices and how many jobs you accept.'],
    ['Which documents do I need?', 'A government photo ID, plus anything specific to your trade (for example a driving licence for drivers). Upload them during registration in the Partner app.'],
    ['How do I get paid?', 'Customers pay after the job — online in the app (credited to your partner wallet, which you can withdraw) or in cash where you allow it. For cash jobs the platform fee is adjusted from your partner balance.'],
    ['Which areas of ' . $city . ' are covered?', 'You choose your work area during registration. Customers book from all over ' . $city . ', including ' . hs_join(array_slice($locs, 0, 5)) . '.'],
    ['Can I register on this website?', 'No. Registration, job requests and earnings are only in the HamaraService Partner app for Android.'],
];

$descHtml = '<p>HamaraService is looking for independent ' . e($role) . ' partners in ' . e($city) . ', ' . e($c['state']) . '. ' . e($s['job_work']) . '</p>'
    . '<p>Customers book through the HamaraService app and nearby partners receive the request. You quote your own price, choose your working hours and the area you serve. This is self-employed partner work paid per job, not a salaried position.</p>'
    . '<p><strong>Requirements:</strong></p><ul>' . implode('', array_map(function ($r) { return '<li>' . e($r) . '</li>'; }, $req)) . '</ul>'
    . '<p><strong>How to apply:</strong> install the HamaraService Partner app, register with your details and government ID, and start receiving jobs after approval.</p>';

$crumbs = [['Home', '/'], ['Jobs in ' . $city, '/jobs-in-' . $cs], [$jt . ' jobs in ' . $city, $path]];
$ld = [
    ['@context' => 'https://schema.org', '@type' => 'JobPosting',
     'title' => $jt,
     'description' => $descHtml,
     'datePosted' => $posted,
     'validThrough' => $valid . 'T23:59:59+05:30',
     'employmentType' => 'CONTRACTOR',
     'hiringOrganization' => ['@type' => 'Organization', 'name' => 'HamaraService', 'sameAs' => HS_SITE, 'logo' => HS_LOGO],
     'jobLocation' => ['@type' => 'Place', 'address' => ['@type' => 'PostalAddress',
         'addressLocality' => $city, 'addressRegion' => $c['state'], 'addressCountry' => 'IN']],
     'directApply' => false,
     'identifier' => ['@type' => 'PropertyValue', 'name' => 'HamaraService', 'value' => 'HS-' . $js . '-' . $cs],
     'industry' => 'Home services',
     'url' => HS_SITE . $path],
    hs_breadcrumb_ld($crumbs),
    hs_faq_ld($faqs),
];

hs_page_start(['title' => $title, 'desc' => $desc, 'path' => $path, 'ld' => $ld]);
?>
<section class="hero job"><div class="wrap">
  <?= hs_breadcrumbs_html($crumbs) ?>
  <h1><?= e("$jt jobs in $city — join HamaraService as a partner") ?></h1>
  <p><?= e("Looking for $role work in $city? Join HamaraService as an independent partner and get job requests from customers near " . hs_join(array_slice($locs, 0, 3)) . " and across $city. Set your own price, choose your hours, and register in the Partner app.") ?></p>
  <div class="chips-h"><span>Set your own price</span><span>Flexible hours</span><span>Jobs near you</span><span>Withdraw in app</span></div>
  <div class="cta-row">
    <?= hs_cta_button('provider', '📲 Install the Partner app to register') ?>
    <a class="btn-sec" href="https://wa.me/<?= HS_WA ?>?text=<?= rawurlencode("Hi HamaraService, I want to join as a $role in $city.") ?>" target="_blank" rel="noopener">💬 Ask on WhatsApp</a>
  </div>
</div></section>

<main class="wrap">
  <section class="sec grid2">
    <div class="card">
      <h2>The work</h2>
      <p><?= e($s['job_work']) ?></p>
      <h3>Typical jobs customers book</h3>
      <ul class="ticks"><?php foreach ($s['tasks'] as $t): ?><li><?= e($t[0]) ?></li><?php endforeach; ?></ul>
    </div>
    <div class="card">
      <h2>Requirements</h2>
      <ul class="ticks"><?php foreach ($req as $r): ?><li><?= e($r) ?></li><?php endforeach; ?></ul>
    </div>
  </section>

  <section class="sec">
    <h2>Why partner with HamaraService</h2>
    <div class="tiles"><?php foreach ($perks as $p): ?><div class="tile"><b><?= e($p[0]) ?></b><small><?= e($p[1]) ?></small></div><?php endforeach; ?></div>
    <div class="honest"><?= e("No promised income: you decide your price and how many jobs to take. For context only, customers in $city see " . strtolower($s['name']) . " prices in the app starting from " . hs_price($s['from']) . " ({$s['unit']}).") ?></div>
  </section>

  <section class="sec">
    <h2>How to join</h2>
    <?= hs_steps_html(hs_steps_partner()) ?>
  </section>

  <section class="sec card">
    <h2><?= e("Working as a $role in $city") ?></h2>
    <p><?= e($c['intro']) ?></p>
    <p><?= e("Speaking " . $c['lang'] . " or basic English helps when you talk to customers. Customers book from areas such as:") ?></p>
    <div class="areas"><?php foreach ($locs as $l): ?><span><?= e($l) ?></span><?php endforeach; ?></div>
  </section>

  <section class="sec">
    <h2><?= e("$jt jobs in $city — FAQs") ?></h2>
    <?= hs_faq_html($faqs) ?>
  </section>

  <section class="sec">
    <h2><?= e("$jt jobs in other cities") ?></h2>
    <div class="links"><?php foreach (hs_related_cities($cs) as $k): ?><a href="<?= hs_job_url($js, $k) ?>"><?= e("$jt jobs in {$C[$k]['name']}") ?></a><?php endforeach; ?><a href="/jobs">All partner jobs →</a></div>
    <h2 style="margin-top:22px"><?= e("Other partner jobs in $city") ?></h2>
    <div class="links"><?php foreach ($S as $k => $o) if ($k !== $ss): ?><a href="<?= hs_job_url($o['job_slug'], $cs) ?>"><?= e($o['icon'] . ' ' . $o['job_title']) ?></a><?php endif; ?></div>
    <p class="note">Need this service instead? <a href="<?= hs_svc_url($ss, $cs) ?>"><?= e("Book {$s['plural']} in $city →") ?></a></p>
  </section>

  <div class="band job">
    <div><h2><?= e("Start getting $role jobs in $city") ?></h2><p>Install the HamaraService Partner app and register — it takes a few minutes.</p></div>
    <?= hs_cta_button('provider', '📲 Install the Partner app') ?>
  </div>
</main>
<?php hs_page_end();
