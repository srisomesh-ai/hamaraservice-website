// HamaraService Provider — Background Booking Notifier
// Upload this file to the SAME folder as provider-dashboard.html on Hostinger

var FB_DB = 'https://hamaraservice-s009-default-rtdb.asia-southeast1.firebasedatabase.app';
var seen  = {};
var pid   = '';
var svcs  = [];

// ── Lifecycle ────────────────────────────────────────────────────────────────
self.addEventListener('install',  function() { self.skipWaiting(); });
self.addEventListener('activate', function(e) { e.waitUntil(self.clients.claim()); });

// ── Messages from main page ───────────────────────────────────────────────────
self.addEventListener('message', function(e) {
  if (!e.data) return;
  if (e.data.type === 'HS_INIT') {
    pid  = e.data.pid  || '';
    svcs = e.data.svcs || [];
  }
  if (e.data.type === 'HS_SEEN')     seen[e.data.id] = 'seen';
  if (e.data.type === 'HS_REJECTED') seen[e.data.id] = 'rejected';
});

// ── Notification click: open dashboard and relay action ──────────────────────
self.addEventListener('notificationclick', function(e) {
  e.notification.close();
  var bkId   = e.notification.tag;
  var action = e.action; // 'accept' or 'reject'

  e.waitUntil(
    clients.matchAll({ type: 'window', includeUncontrolled: true }).then(function(cs) {
      // Focus existing dashboard window
      for (var i = 0; i < cs.length; i++) {
        if (cs[i].url.indexOf('provider-dashboard') > -1) {
          cs[i].focus();
          cs[i].postMessage({ type: 'HS_NOTIF_CLICK', action: action, id: bkId });
          return;
        }
      }
      // No open window — open it
      return clients.openWindow('provider-dashboard.html').then(function(win) {
        if (win) win.postMessage({ type: 'HS_NOTIF_CLICK', action: action, id: bkId });
      });
    })
  );
});

// ── Poll Firebase every 30s for new bookings ──────────────────────────────────
function pollBookings() {
  if (!pid) return;

  fetch(FB_DB + '/active_bookings.json?shallow=true')
    .then(function(r) { return r.json(); })
    .then(function(keys) {
      if (!keys || typeof keys !== 'object') return;

      Object.keys(keys).forEach(function(k) {
        if (seen[k]) return;

        fetch(FB_DB + '/active_bookings/' + k + '.json')
          .then(function(r2) { return r2.json(); })
          .then(function(bk) {
            if (!bk) return;
            if (bk.status !== 'searching') return;
            if (bk.acceptedBy && bk.acceptedBy.id) return;

            // Filter by provider's services
            if (svcs.length > 0) {
              var sn = (bk.service || '').toLowerCase();
              var ok = svcs.some(function(s) {
                return sn.includes(s) || s.includes(sn.split(' ')[0]);
              });
              if (!ok) return;
            }

            // Check page is already showing alert — don't double notify
            seen[k] = 'notified';

            var title = 'New Booking — ' + bk.service;
            var body  = (bk.customer || 'Customer') +
                        '  |  Rs ' + (bk.priceVal || bk.price || '') +
                        '  |  ' + (bk.address || '');

            self.registration.showNotification(title, {
              silent: false,
              body:             body,
              tag:              bk.id,
              requireInteraction: true,
              vibrate:          [300, 100, 300, 100, 300],
              icon:             '/favicon.ico',
              badge:            '/favicon.ico',
              data:             { bkId: bk.id },
              actions: [
                { action: 'accept', title: 'Accept Job' },
                { action: 'reject', title: 'Reject'     }
              ]
            });
          })
          .catch(function() {});
      });
    })
    .catch(function() {});
}

setInterval(pollBookings, 30000);
// Also poll once shortly after SW activates
setTimeout(pollBookings, 3000);
