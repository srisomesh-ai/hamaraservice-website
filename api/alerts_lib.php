<?php
// ============================================================
//  api/alerts_lib.php — "No provider found" admin alert (library)
//
//  A booking still in status 'searching' (no provider) NO_PROVIDER_ALERT_MIN
//  minutes after it was created is e-mailed to ADMIN_ALERT_EMAIL — once per
//  booking (bookings.unassigned_alerted_at is claimed atomically).
//
//  Triggered cheaply from polling endpoints (admin panel, customer
//  bookings.php get/active, provider open jobs) via maybeCheckUnassigned(),
//  which runs the check at most once per 60 s (temp-file timestamp), and
//  from api/cron.php?action=unassigned (optional Hostinger cron).
//  Never throws.
// ============================================================
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/mailer.php';

const HS_UNASSIGNED_WINDOW_H = 24;   // older 'searching' jobs are no longer offered to providers

// Bookings waiting for a provider longer than the threshold (admin list + alert).
function unassignedBookings(PDO $db, $minMinutes = null) {
    $min = max(0, (int)($minMinutes ?? NO_PROVIDER_ALERT_MIN));
    $st = $db->prepare("
        SELECT id, svc_name, svc_icon, address, landmark, city, slot_date, slot_time,
               customer_name, customer_phone, contact_name, contact_phone, created_at,
               unassigned_alerted_at,
               TIMESTAMPDIFF(MINUTE, created_at, NOW()) AS minutes_waiting
        FROM bookings
        WHERE status = 'searching' AND provider_id IS NULL
          AND created_at <= NOW() - INTERVAL ? MINUTE
          AND created_at >  NOW() - INTERVAL " . HS_UNASSIGNED_WINDOW_H . " HOUR
        ORDER BY created_at ASC
        LIMIT 200");
    $st->execute([$min]);
    return $st->fetchAll();
}

function hsPhone10($p) {
    return substr(preg_replace('/\D/', '', (string)$p), -10);
}

function unassignedAlertHtml(array $b) {
    $name  = trim((string)($b['contact_name'] ?: $b['customer_name'])) ?: 'Customer';
    $phone = hsPhone10($b['contact_phone'] ?: $b['customer_phone']);
    $svc   = (string)$b['svc_name'];
    $city  = trim((string)$b['city']);
    $slot  = trim(($b['slot_date'] ?? '') . ' ' . ($b['slot_time'] ?? ''));
    $mins  = (int)$b['minutes_waiting'];
    $lm    = trim((string)($b['landmark'] ?? ''));
    $addr  = trim((string)$b['address'] . ($lm !== '' ? ' (' . (preg_match('/^near\b/i', $lm) ? $lm : 'near ' . $lm) . ')' : ''));

    $wa = '';
    if ($phone !== '') {
        $msg = "Hi $name, this is HamaraService. We're still finding a provider for your $svc booking ({$b['id']}). "
             . "We're on it and will update you shortly — sorry for the wait!";
        $wa = 'https://wa.me/91' . $phone . '?text=' . rawurlencode($msg);
    }
    $btn = function ($text, $url, $bg) {
        return '<a href="' . esc($url) . '" style="display:inline-block;margin:4px 6px 4px 0;padding:10px 18px;background:' . $bg
             . ';color:#fff;text-decoration:none;border-radius:10px;font-size:14px;font-weight:700;">' . esc($text) . '</a>';
    };

    $c  = '<p style="font-size:14px;color:#374151;line-height:1.6;margin:0 0 16px;">No provider has accepted this booking for <strong>'
        . $mins . ' minutes</strong>. Please call the customer or assign a provider.</p>' . "\n";
    $c .= '<table width="100%" cellpadding="0" cellspacing="0" style="font-size:13px;color:#0f1117;border-collapse:collapse;">';
    $rows = [
        'Booking'   => $b['id'],
        'Service'   => $svc,
        'City'      => $city ?: '—',
        'Address'   => $addr ?: '—',
        'Slot'      => $slot ?: '—',
        'Customer'  => $name,
        'Phone'     => $phone ? '+91 ' . $phone : '—',
        'Waiting'   => $mins . ' min (booked ' . $b['created_at'] . ')',
    ];
    foreach ($rows as $k => $v) {
        $c .= '<tr><td style="padding:7px 0;border-bottom:1px solid #f3f4f6;color:#6b7280;width:90px;vertical-align:top;">' . esc($k) . ': '
            . '</td><td style="padding:7px 0;border-bottom:1px solid #f3f4f6;font-weight:700;">' . esc($v) . "</td></tr>\n";
    }
    $c .= "</table>\n<div style=\"margin:20px 0 8px;\">";
    if ($phone !== '') {
        $c .= $btn('📞 Call ' . $name, 'tel:+91' . $phone, '#0b7285');
        $c .= $btn('💬 WhatsApp', $wa, '#25D366');
    }
    $c .= $btn('Open admin panel', 'https://hamaraservice.com/admin.html', '#E8251A');
    $c .= '</div>';
    return emailBase('No provider found yet — ' . esc($svc) . ($city !== '' ? ' in ' . esc($city) : ''), $c);
}

// Finds overdue unassigned bookings, claims each one atomically and e-mails
// the admin once per booking. Returns the number of alerts sent. Never throws.
function checkUnassignedBookings(PDO $db) {
    $sent = 0;
    try {
        $to = trim((string)ADMIN_ALERT_EMAIL);
        if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) return 0;
        foreach (unassignedBookings($db) as $b) {
            if (!empty($b['unassigned_alerted_at'])) continue;
            $claim = $db->prepare("UPDATE bookings SET unassigned_alerted_at = NOW()
                                   WHERE id = ? AND unassigned_alerted_at IS NULL
                                     AND status = 'searching' AND provider_id IS NULL");
            $claim->execute([$b['id']]);
            if ($claim->rowCount() !== 1) continue;   // another request claimed it / status changed
            try {
                $city = trim((string)$b['city']);
                $subject = 'No provider found yet — ' . $b['svc_name'] . ($city !== '' ? ' in ' . $city : '') . ' (' . $b['id'] . ')';
                $r = sendSMTP($to, 'HamaraService Admin', $subject, unassignedAlertHtml($b));
                if (!empty($r['error'])) error_log('[hs] unassigned alert ' . $b['id'] . ' mail failed: ' . $r['error']);
                else $sent++;
            } catch (Throwable $e) {
                error_log('[hs] unassigned alert ' . $b['id'] . ' mail error: ' . $e->getMessage());
            }
        }
    } catch (Throwable $e) {
        error_log('[hs] checkUnassignedBookings: ' . $e->getMessage());
    }
    return $sent;
}

// Cheap trigger for polling endpoints: runs the check at most once per
// $everySec seconds (shared temp-file timestamp, non-blocking lock).
function maybeCheckUnassigned(PDO $db, $everySec = 60) {
    try {
        $f = sys_get_temp_dir() . '/hs_unassigned_' . md5(DB_NAME) . '.ts';
        $h = @fopen($f, 'c+');
        if (!$h) return;
        if (!flock($h, LOCK_EX | LOCK_NB)) { fclose($h); return; }
        $last = (int)stream_get_contents($h);
        if (time() - $last >= $everySec) {
            ftruncate($h, 0); rewind($h); fwrite($h, (string)time()); fflush($h);
            checkUnassignedBookings($db);
        }
        flock($h, LOCK_UN);
        fclose($h);
    } catch (Throwable $e) {
        error_log('[hs] maybeCheckUnassigned: ' . $e->getMessage());
    }
}
