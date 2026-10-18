// HamaraService — FCM background messaging service worker
importScripts('https://www.gstatic.com/firebasejs/10.12.2/firebase-app-compat.js');
importScripts('https://www.gstatic.com/firebasejs/10.12.2/firebase-messaging-compat.js');

firebase.initializeApp({
  apiKey: "AIzaSyDpMpewyKVlfsfSeKfoS3GJf0V_t14Qb7k",
  authDomain: "hamaraservice-s009.firebaseapp.com",
  projectId: "hamaraservice-s009",
  storageBucket: "hamaraservice-s009.firebasestorage.app",
  messagingSenderId: "1064274729048",
  // TODO(config): replace "web:placeholder" with the real Web App ID from Firebase Console →
    //   Project settings → General → Your apps (web). FCM web push will not work until this is set.
    appId: "1:1064274729048:web:placeholder"
});

const messaging = firebase.messaging();

messaging.onBackgroundMessage(function(payload) {
  const d = payload.data || payload.notification || {};
  const title = d.title || 'HamaraService';
  self.registration.showNotification(title, {
    body: d.body || '',
    icon: '/images/rlogo.png',
    badge: '/images/rlogo.png',
    vibrate: [100, 50, 100],
    data: d,
    tag: d.bookingId || 'hs-notif'
  });
});

self.addEventListener('notificationclick', function(event) {
  event.notification.close();
  const d = event.notification.data || {};
  // Data-only messages carry data.event; these events are only ever sent to providers.
  const PROVIDER_EVENTS = ['new_booking','price_negotiation','price_confirmed','payment_received','payout_approved','account_approved','booking_released'];
  const url = (d.role === 'provider' || PROVIDER_EVENTS.indexOf(d.event) >= 0) ? '/app/provider.html' : '/app/customer.html';
  event.waitUntil(clients.matchAll({type:'window',includeUncontrolled:true}).then(function(list){
    for (const c of list) { if (c.url.includes('/app/') && 'focus' in c) return c.focus(); }
    if (clients.openWindow) return clients.openWindow(url);
  }));
});
