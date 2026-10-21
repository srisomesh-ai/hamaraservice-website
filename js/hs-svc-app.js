/* HamaraService — service pages (svc-*.html) in app-only mode.
 * Prices and options stay on the page as information; every "Book Now" button
 * becomes "Book in the app" and opens the customer app install (HS_getApp).
 * When WEB_LOGIN_MODE is 'web' the original web booking flow is left untouched.
 * Load after js/hs-site.js, with `defer`, at the end of the page.
 */
(function () {
  var appMode = true;   // default until site config says 'web'
  var SEL = '[onclick*="ookNow"],[onclick*="confirmBook"]';
  var LABEL = 'Book in the app →';
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
    getApp();
  }, true);

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
