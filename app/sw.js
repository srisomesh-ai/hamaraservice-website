// HamaraService PWA v2 — network only, no stale pages
self.addEventListener('install', function(e){ self.skipWaiting(); });
self.addEventListener('activate', function(e){
  e.waitUntil(caches.keys().then(function(keys){
    return Promise.all(keys.map(function(k){ return caches.delete(k); }));
  }).then(function(){ return clients.claim(); }));
});
self.addEventListener('fetch', function(e){
  e.respondWith(fetch(e.request));
});
