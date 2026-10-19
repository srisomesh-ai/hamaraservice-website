// Retired service worker (kill switch).
// The old provider-dashboard.html registered /sw.js to poll Firebase for bookings.
// That dashboard is gone (providers use /app/provider.html, which has its own worker
// under /app/). Browsers that still have the old worker installed will fetch this file
// on their next update check; it unregisters itself and stops polling.
// Safe to delete this file a few months after release.
self.addEventListener('install', function () { self.skipWaiting(); });
self.addEventListener('activate', function (e) {
  e.waitUntil(self.registration.unregister());
});
