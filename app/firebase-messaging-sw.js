// HamaraService — FCM background messaging service worker
importScripts('https://www.gstatic.com/firebasejs/10.12.2/firebase-app-compat.js');
importScripts('https://www.gstatic.com/firebasejs/10.12.2/firebase-messaging-compat.js');

firebase.initializeApp({
  apiKey: "AIzaSyDpMpewyKVlfsfSeKfoS3GJf0V_t14Qb7k",
  authDomain: "hamaraservice-s009.firebaseapp.com",
  projectId: "hamaraservice-s009",
  storageBucket: "hamaraservice-s009.firebasestorage.app",
  messagingSenderId: "1064274729048",
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
  const url = event.notification.data && event.notification.data.role === 'provider'
    ? '/app/provider.html' : '/app/customer.html';
  event.waitUntil(clients.matchAll({type:'window',includeUncontrolled:true}).then(function(list){
    for (const c of list) { if (c.url.includes('/app/') && 'focus' in c) return c.focus(); }
    if (clients.openWindow) return clients.openWindow(url);
  }));
});
