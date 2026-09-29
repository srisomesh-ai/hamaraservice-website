// HamaraService Provider — legacy root service worker
//
// This worker used to poll the Firebase Realtime Database every 30 s for new
// bookings. RTDB is no longer used (MySQL API is the only data store), so the
// polling is gone. The file is kept so browsers that registered it pick up
// this update and stop polling. It now only:
//   • activates immediately and claims open pages, and
//   • relays notification clicks (Accept / Reject) to provider-dashboard.html.
// New-job alerts come from the dashboard's own bookings.php?action=open poll
// while it is open, and from server-sent FCM pushes otherwise.
// There is no fetch handler, so this worker never intercepts network requests.

self.addEventListener('install',  function() { self.skipWaiting(); });
self.addEventListener('activate', function(e) { e.waitUntil(self.clients.claim()); });

// Old pages may still post HS_INIT / HS_SEEN / HS_REJECTED — ignore them.
self.addEventListener('message', function() {});

// ── Notification click: open dashboard and relay action ──────────────────────
self.addEventListener('notificationclick', function(e) {
  e.notification.close();
  var bkId   = e.notification.tag;
  var action = e.action; // 'accept' or 'reject'

  e.waitUntil(
    self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then(function(cs) {
      for (var i = 0; i < cs.length; i++) {
        if (cs[i].url.indexOf('provider-dashboard') > -1) {
          cs[i].focus();
          cs[i].postMessage({ type: 'HS_NOTIF_CLICK', action: action, id: bkId });
          return;
        }
      }
      return self.clients.openWindow('provider-dashboard.html').then(function(win) {
        if (win) win.postMessage({ type: 'HS_NOTIF_CLICK', action: action, id: bkId });
      });
    })
  );
});
