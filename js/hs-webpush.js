/* HamaraService — notifications for the web apps (/app/customer.html, /app/provider.html)
 * used on iPhone / iPad and computers until the iPhone apps are out.
 *
 *   HS_PUSH.init('customer'|'provider', function (token) { save it with platform=web })
 *
 * - Push needs the Firebase web app id + VAPID key from /api/site.php
 *   (FIREBASE_WEB_APP_ID in hs-secrets.php). Without them only the in-page alerts
 *   and the e-mails (sent by the server) work.
 * - iPhone: web push works only from the Home Screen (iOS 16.4+), so Safari tabs
 *   get an "Add to Home Screen" guide; the Home Screen app gets a "Turn on" button
 *   (the permission prompt must come from a tap).
 * - One service worker for /app/: /app/firebase-messaging-sw.js.
 */
(function () {
  var FB = {
    apiKey: 'AIzaSyDpMpewyKVlfsfSeKfoS3GJf0V_t14Qb7k',
    authDomain: 'hamaraservice-s009.firebaseapp.com',
    projectId: 'hamaraservice-s009',
    storageBucket: 'hamaraservice-s009.firebasestorage.app',
    messagingSenderId: '1064274729048'
  };
  var st = { role: 'customer', save: null, cfg: {}, listening: false };
  var HIDE_KEY = 'hs_push_banner_hidden_until';

  function device() { return typeof HS_device === 'function' ? HS_device() : 'desktop'; }
  function standalone() {
    return window.navigator.standalone === true ||
      !!(window.matchMedia && window.matchMedia('(display-mode: standalone)').matches);
  }
  function supported() { return 'serviceWorker' in navigator && 'Notification' in window && 'PushManager' in window; }
  function hidden() { try { return +localStorage.getItem(HIDE_KEY) > Date.now(); } catch (e) { return false; } }
  function hideFor(days) { try { localStorage.setItem(HIDE_KEY, String(Date.now() + days * 864e5)); } catch (e) {} }

  function swUrl() { return '/app/firebase-messaging-sw.js?appId=' + encodeURIComponent(st.cfg.firebase_web_app_id || ''); }
  function registerSW() { return navigator.serviceWorker.register(swUrl(), { scope: '/app/' }); }

  function getToken() {
    if (!window.firebase || !firebase.messaging || !st.cfg.firebase_web_app_id) return;
    try {
      if (!firebase.apps.length) firebase.initializeApp(Object.assign({ appId: st.cfg.firebase_web_app_id }, FB));
      var m = firebase.messaging();
      registerSW().then(function (reg) {
        return m.getToken({ vapidKey: st.cfg.firebase_vapid_key, serviceWorkerRegistration: reg });
      }).then(function (t) { if (t && st.save) st.save(t); })
        .catch(function (e) { console.warn('[push] token', e); });
      if (!st.listening) {
        st.listening = true;
        // Page open: show it in the page (the page's own polling also updates the screen)
        m.onMessage(function (p) {
          var d = p.notification || p.data || {};
          if (window.popNotify) popNotify('🔔', d.title || 'HamaraService', d.body || '', 'var(--teal)');
        });
      }
    } catch (e) { console.warn('[push] init', e); }
  }

  function banner(kind) {
    if (hidden() || document.getElementById('hsPushBanner')) return;
    var who = st.role === 'provider'
      ? 'new job requests, even when this page is closed'
      : 'quotes, the Start OTP when your provider arrives and the completion OTP';
    var html = kind === 'ios'
      ? '<strong class="t">📲 Get alerts on your iPhone</strong><span class="m">Tap <b>Share</b> <i class="ic">⬆︎</i> in Safari, then <b>Add to Home Screen</b>. Open HamaraService from your Home Screen and turn on notifications for ' + who + '.</span>'
      : '<strong class="t">🔔 Turn on notifications</strong><span class="m">Get ' + who + '.</span><button type="button" id="hsPushOn">Turn on</button>';
    var el = document.createElement('div');
    el.id = 'hsPushBanner';
    el.setAttribute('role', 'dialog');
    el.innerHTML = '<style>#hsPushBanner{position:fixed;left:12px;right:12px;bottom:calc(84px + env(safe-area-inset-bottom));z-index:9998;max-width:460px;margin:0 auto;background:#0f4c5c;color:#fff;border-radius:16px;padding:14px 40px 14px 16px;box-shadow:0 10px 30px rgba(0,0,0,.3);font:14px/1.45 system-ui,-apple-system,"Segoe UI",sans-serif}'
      + '#hsPushBanner .t{display:block;font-size:15px;margin-bottom:3px}#hsPushBanner .m{color:#d7ebef}#hsPushBanner .m b{color:#fff}#hsPushBanner .ic{display:inline-block;font-style:normal;background:#fff;color:#0f4c5c;border-radius:6px;padding:0 5px;font-weight:700}'
      + '#hsPushBanner button#hsPushOn{display:block;margin-top:10px;background:#E8651A;color:#fff;border:0;border-radius:10px;padding:10px 16px;font-weight:800;font-size:14px;cursor:pointer}'
      + '#hsPushBanner .x{position:absolute;top:6px;right:8px;background:none;border:0;color:#fff;font-size:22px;line-height:1;cursor:pointer;padding:6px}</style>'
      + html + '<button type="button" class="x" aria-label="Close">×</button>';
    document.body.appendChild(el);
    el.querySelector('.x').onclick = function () { hideFor(3); el.remove(); };
    var on = el.querySelector('#hsPushOn');
    if (on) on.onclick = function () {
      Notification.requestPermission().then(function (p) {
        el.remove();
        if (p === 'granted') getToken(); else hideFor(7);
      });
    };
  }

  function start() {
    if (!st.cfg.firebase_web_app_id) return;            // push not configured on the server
    if (device() === 'ios' && !standalone()) { banner('ios'); return; }
    if (!supported()) return;
    var check = (firebase.messaging && firebase.messaging.isSupported) ? firebase.messaging.isSupported() : true;
    Promise.resolve(check).then(function (ok) {
      if (!ok) return;
      if (Notification.permission === 'granted') getToken();
      else if (Notification.permission === 'default') banner('enable');
    });
  }

  window.HS_PUSH = {
    init: function (role, save) {
      st.role = role === 'provider' ? 'provider' : 'customer';
      st.save = save;
      var go = function (c) { st.cfg = c || {}; if (window.firebase) start(); };
      if (typeof HS_site === 'function') HS_site(go); else go({});
    },
    // the one service worker for /app/ (also when push is off)
    registerWorker: function () {
      if (!('serviceWorker' in navigator)) return;
      var reg = function () { registerSW().catch(function () {}); };
      if (typeof HS_site === 'function') HS_site(function (c) { st.cfg = c || st.cfg; reg(); }); else reg();
    }
  };
})();
