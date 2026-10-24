/* HamaraService — service pages (svc-*.html).
 * Prices and options stay on the page as information; every "Book Now" button
 * becomes "Book in the app" (Android → Play Store).
 * iPhone/iPad (no app yet) and computers: "Book online" runs the page's own
 * web booking (/app/customer.html?book=1&svc=…) when WEB_ACCESS allows it —
 * the web app opens these pages for its services, so sending computers to
 * the get-app page from here would loop.
 * When WEB_LOGIN_MODE is 'web' the original web booking flow is left untouched.
 * Load after js/hs-site.js, with `defer`, at the end of the page.
 */
(function () {
  var appMode = true;   // default until site config says 'web'
  var SEL = '[onclick*="ookNow"],[onclick*="confirmBook"]';
  var LABEL = 'Book in the app →';
  var webBook = false;  // iPhone/iPad or computer with web access → keep the web booking flow
  var PLAY = 'https://play.google.com/store/apps/details?id=com.hamaraservice.customer';

  // Works even if an old cached hs-site.js (without HS_getApp) is served.
  function getApp() {
    if (typeof window.HS_getApp === 'function') return window.HS_getApp('customer');
    location.href = /iphone|ipad|ipod/i.test(navigator.userAgent || '') ? '/get-app.html?for=customer' : PLAY;
  }

  function fix(btn) {
    if (!appMode) return;
    if (!btn.hasAttribute('data-hs-orig')) btn.setAttribute('data-hs-orig', btn.textContent);
    if (/book/i.test(btn.textContent) && btn.textContent !== LABEL) btn.textContent = LABEL;
    if (btn.disabled) btn.disabled = false;
    if (btn.style.opacity && btn.style.opacity !== '1') btn.style.opacity = '1';
    if (btn.style.cursor === 'not-allowed') btn.style.cursor = 'pointer';
  }
  function fixAll() { if (appMode) Array.prototype.forEach.call(document.querySelectorAll(SEL), fix); }

  // Capture phase: runs before the inline onclick and stops it.
  document.addEventListener('click', function (e) {
    if (!appMode) return;
    var b = e.target && e.target.closest && e.target.closest(SEL);
    if (!b) return;
    e.preventDefault(); e.stopPropagation();
    if (webBook) { bookOnWeb(); return; }
    getApp();
  }, true);

  // iPhone/iPad and computers: the page's own web booking (keeps the selected tasks) once its
  // price has loaded, else straight to the web app with this service.
  function bookOnWeb() {
    if (window.VISIT_PRICE > 0 && typeof window.bookNow === 'function') {
      try { window.bookNow(); return; } catch (x) {}
    }
    var m = typeof window.bookNow === 'function' && String(window.bookNow).match(/svcId\s*:\s*'([^']+)'/);
    location.href = '/app/customer.html?book=1' + (m ? '&svc=' + encodeURIComponent(m[1]) : '');
  }

  var pending = false;
  var obs = new MutationObserver(function () {
    if (pending) return;
    pending = true;
    setTimeout(function () { pending = false; fixAll(); }, 30);
  });
  function start() {
    // Pages rendered by service-base.js replace <body>; put the SEO link block back.
    if (window.__hsSeoLinks && !document.getElementById('hsSeoLinks')) {
      document.body.insertAdjacentHTML('beforeend', window.__hsSeoLinks);
    }
    fixAll();
    obs.observe(document.body, { subtree: true, childList: true, attributes: true, attributeFilter: ['disabled', 'style'] });
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start); else start();

  if (typeof window.HS_site !== 'function' || typeof window.HS_appMode !== 'function') return;
  HS_site(function (c) {
    var dev = typeof window.HS_device === 'function' ? HS_device() : '';
    // Came from a partner's status card (/p/<id>): book on the web on any
    // device, so the partner's discount stays with the booking.
    var ref = false;
    try { var r = JSON.parse(localStorage.getItem('hs_ref') || 'null'); ref = !!(r && r.id && Date.now() - r.t < 7 * 864e5); } catch (x) {}
    if (ref || (typeof window.HS_webAllowed === 'function' && HS_webAllowed(c) &&
        (dev === 'desktop' || (dev === 'ios' && !HS_iosAppUrl('customer', c))))) {
      webBook = true; LABEL = 'Book online →';
      Array.prototype.forEach.call(document.querySelectorAll('[data-hs-orig]'), function (b) {
        if (/book/i.test(b.textContent)) b.textContent = LABEL;
      });
    }
    if (HS_appMode(c)) return;
    appMode = false;
    obs.disconnect();
    Array.prototype.forEach.call(document.querySelectorAll('[data-hs-orig]'), function (b) {
      b.textContent = b.getAttribute('data-hs-orig'); b.removeAttribute('data-hs-orig');
    });
    // Let the page's own price logic decide enabled/disabled again
    ['updatePrice', 'updateTotal', 'updateAll', 'updatePriceDisplays'].forEach(function (f) {
      if (typeof window[f] === 'function') try { window[f](); } catch (e) {}
    });
  });
})();
