// HamaraService — the one service worker for /app/ (registered by js/hs-webpush.js
// as /app/firebase-messaging-sw.js?appId=<Firebase web app id>).
//  - Web push (Firebase Cloud Messaging) when the app id is set.
//  - Network-only fetch handler (no stale pages), so the web app can be added
//    to the Home Screen on iPhone / Android.
const APP_ID = new URL(self.location.href).searchParams.get('appId') || '';

self.addEventListener('install', function () { self.skipWaiting(); });
self.addEventListener('activate', function (e) {
  // Clear caches left by the old /app/sw.js worker.
  e.waitUntil(caches.keys().then(function (keys) {
    return Promise.all(keys.map(function (k) { return caches.delete(k); }));
  }).then(function () { return self.clients.claim(); }));
});
self.addEventListener('fetch', function (e) {
  e.respondWith(fetch(e.request));
});

if (APP_ID) {
  importScripts('https://www.gstatic.com/firebasejs/10.12.2/firebase-app-compat.js');
  importScripts('https://www.gstatic.com/firebasejs/10.12.2/firebase-messaging-compat.js');
  firebase.initializeApp({
    apiKey: 'AIzaSyDpMpewyKVlfsfSeKfoS3GJf0V_t14Qb7k',
    authDomain: 'hamaraservice-s009.firebaseapp.com',
    projectId: 'hamaraservice-s009',
    storageBucket: 'hamaraservice-s009.firebasestorage.app',
    messagingSenderId: '1064274729048',
    appId: APP_ID
  });
  const messaging = firebase.messaging();

  // Messages with a notification block (everything the server sends) are shown
  // by Firebase itself, with the click link from webpush.fcm_options.
  // Data-only messages are shown here.
  messaging.onBackgroundMessage(function (payload) {
    if (payload.notification) return;
    const d = payload.data || {};
    return self.registration.showNotification(d.title || 'HamaraService', {
      body: d.body || '',
      icon: '/favicon-192x192.png',
      badge: '/favicon-96x96.png',
      vibrate: [200, 100, 200],
      tag: d.bookingId || d.event || 'hs-notif',
      renotify: true,
      data: d
    });
  });
}

// Taps on data-only notifications: open (or focus) the right web app.
self.addEventListener('notificationclick', function (event) {
  const d = (event.notification && event.notification.data) || {};
  if (d.FCM_MSG) return;   // Firebase handles its own notifications
  event.notification.close();
  const url = d.role === 'provider' ? '/app/provider.html' : '/app/customer.html';
  event.waitUntil(self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then(function (list) {
    for (const c of list) { if (c.url.indexOf(url) >= 0 && 'focus' in c) return c.focus(); }
    if (self.clients.openWindow) return self.clients.openWindow(url);
  }));
});
