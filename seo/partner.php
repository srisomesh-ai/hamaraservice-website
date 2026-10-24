<?php
// /p/<partner id>  (e.g. /p/HS-PRO-RAVI6780) — a partner's own booking page,
// linked from their status card. Customers who book from here get REF_DISCOUNT
// off their first job with this partner (api/bookings.php, ref=…).
require_once __DIR__ . '/lib.php';
require_once dirname(__DIR__) . '/api/db.php';

$id = preg_replace('/[^A-Za-z0-9-]/', '', (string)($_GET['id'] ?? ''));
if ($id === '') hs_not_found();
$db = getDB();
$q = $db->prepare("SELECT id, name, city, photo, rating, review_count, completed_bookings, bio,
                          instagram_url, youtube_url, followers
                   FROM providers WHERE id = ? AND status = 'approved'");
try { $q->execute([$id]); $p = $q->fetch(); } catch (Throwable $e) { $p = null; }
if (!$p) hs_not_found();
$s = $db->prepare("SELECT ps.svc_id, COALESCE(NULLIF(ps.svc_name,''), sv.name, ps.svc_id) AS name,
                          COALESCE(NULLIF(ps.svc_icon,''), sv.icon, '🔧') AS icon
                   FROM provider_services ps LEFT JOIN services sv ON sv.id = ps.svc_id
                   WHERE ps.provider_id = ? AND ps.enabled = 1 ORDER BY sv.sort_order, ps.svc_id");
$s->execute([$id]);
$svcs = $s->fetchAll();

$name = $p['name']; $first = explode(' ', trim($name))[0] ?: $name;
$path = '/p/' . $p['id'];
$main = $svcs ? $svcs[0]['name'] : 'Home services';
$off = '₹' . REF_DISCOUNT;
$photo = (string)$p['photo'];

hs_page_start([
    'title' => "$name – $main on HamaraService",
    'desc'  => "Book $name ($main" . ($p['city'] ? ", {$p['city']}" : '') . ") on HamaraService and get $off off your first booking.",
    'path'  => $path,
    'noindex' => true,
]);
?>
<style>
.pp-card{display:flex;gap:16px;align-items:center}
.pp-av{width:84px;height:84px;border-radius:50%;background:#fff center/cover no-repeat;flex:none;display:flex;align-items:center;justify-content:center;font-size:34px;font-weight:900;color:var(--teal2);border:3px solid rgba(255,255,255,.6)}
.pp-off{background:#fff3e8;border:2px dashed var(--or);border-radius:16px;padding:16px;margin:0 0 16px;color:#7a3a0c}
.pp-off b{color:var(--or2);font-size:20px}
.pp-svc{display:flex;align-items:center;justify-content:space-between;gap:12px;padding:14px 0;border-bottom:1px solid var(--line)}
.pp-svc:last-child{border-bottom:0}
.pp-svc .n{font-weight:800;font-size:16px}
.pp-svc .btn-cta{padding:10px 16px;font-size:14px;white-space:nowrap}
.pp-soc a{color:#fff;font-weight:700;margin-right:14px}
</style>
<section class="hero"><div class="wrap">
  <div class="pp-card">
    <div class="pp-av" <?= $photo ? 'style="background-image:url(' . e($photo) . ')"' : '' ?>><?= $photo ? '' : e(mb_strtoupper(mb_substr($name, 0, 1))) ?></div>
    <div>
      <h1 style="margin:0 0 4px"><?= e($name) ?></h1>
      <div><?= e(implode(' · ', array_map(fn($x) => $x['name'], array_slice($svcs, 0, 3)))) ?><?= $p['city'] ? ' · 📍 ' . e($p['city']) : '' ?></div>
      <div style="margin-top:4px">⭐ <?= (float)$p['rating'] > 0 ? e(number_format((float)$p['rating'], 1)) . ' (' . (int)$p['review_count'] . ')' : 'New' ?> · <?= (int)$p['completed_bookings'] > 0 ? (int)$p['completed_bookings'] . ' jobs done · ' : '' ?>✔ Verified</div>
      <?php if ($p['instagram_url'] || $p['youtube_url']): ?><div class="pp-soc" style="margin-top:6px">
        <?php if ($p['instagram_url']): ?><a href="<?= e($p['instagram_url']) ?>" target="_blank" rel="noopener">📸 Instagram<?= $p['followers'] ? ' · ' . e($p['followers']) : '' ?></a><?php endif; ?>
        <?php if ($p['youtube_url']): ?><a href="<?= e($p['youtube_url']) ?>" target="_blank" rel="noopener">▶️ YouTube</a><?php endif; ?>
      </div><?php endif; ?>
    </div>
  </div>
</div></section>

<main class="wrap sec" style="padding-left:16px;padding-right:16px">
  <div class="pp-off">🎁 <b><?= e($off) ?> OFF</b> your first booking with <?= e($first) ?> — applied automatically when <?= e($first) ?> takes the job (on bookings of ₹<?= (int)REF_MIN_PRICE ?>+).</div>
  <div class="card">
    <h2 style="margin-top:0">Book <?= e($first) ?></h2>
    <?php foreach ($svcs as $sv): ?>
    <div class="pp-svc"><span class="n"><?= e($sv['icon'] . ' ' . $sv['name']) ?></span>
      <a class="btn-cta" href="/app/customer.html?svc=<?= e($sv['svc_id']) ?>&amp;ref=<?= e($p['id']) ?>">Book now →</a></div>
    <?php endforeach; ?>
    <?php if (!$svcs): ?><p>This partner has no services listed right now.</p><?php endif; ?>
  </div>
  <?php if (trim((string)$p['bio']) !== ''): ?><div class="card" style="margin-top:14px"><h2 style="margin-top:0">About</h2><p><?= nl2br(e($p['bio'])) ?></p></div><?php endif; ?>
  <p class="note" style="margin-top:14px">See the price before you confirm · OTP to start and finish · pay after the work.</p>
</main>
<script>
// Remember the partner for this visit, so the discount follows the customer
// through login / the service page.
try { localStorage.setItem('hs_ref', JSON.stringify({ id: <?= json_encode($p['id']) ?>, name: <?= json_encode($name) ?>, t: Date.now() })); } catch (x) {}
</script>
<?php hs_page_end();
