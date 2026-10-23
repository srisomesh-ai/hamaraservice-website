/* HamaraService — public site settings + shared support / app-install helpers.
 *   HS_site(function (cfg) { ... })     cfg from /api/site.php?action=config
 *   HS_support('customer'|'provider', 'optional context')   opens WhatsApp (email fallback)
 *   HS_getApp / HS_login('customer'|'provider', '?extra')  — where "Book / Login / Join" goes:
 *       Android                  → Play Store
 *       iPhone / iPad            → App Store once IOS_*_APP_URL is set, else the web app
 *                                  (/app/customer.html, /app/provider.html) when WEB_ACCESS allows
 *       computer                 → /get-app.html (QR for the Android app + "Continue on the web")
 *   HS_webAllowed(cfg)           → may this device use the web apps?
 *   <a data-hs-getapp="customer" href="/get-app.html?for=customer">  → href follows the same rules.
 */
(function () {
  var cfg = null, waiting = [];
  var h = location.hostname;
  var API = (h === 'hamaraservice.com' || h === 'www.hamaraservice.com' || h === 'localhost' || h === '127.0.0.1')
    ? '/api' : 'https://hamaraservice.com/api';
  function load() {
    var done = false;
    function finish(c) {
      if (done) return; done = true; cfg = c || {};
      var w = waiting; waiting = []; w.forEach(function (cb) { try { cb(cfg); } catch (e) {} });
    }
    // Never leave a button waiting: fall back to defaults after 3 s.
    setTimeout(function () { finish({}); }, 3000);
    fetch(API + '/site.php?action=config', { cache: 'no-store' }).then(function (r) { return r.json(); })
      .then(function (res) { finish((res && res.success && res.data) || {}); })
      .catch(function () { finish({}); });
  }
  window.HS_site = function (cb) { if (cfg) cb(cfg); else { waiting.push(cb); if (waiting.length === 1) load(); } };

  // 'app' unless the server explicitly says 'web' (also when the API is unreachable)
  window.HS_appMode = function (c) { return !(c && c.web_login_mode === 'web'); };

  // Web apps are for devices without our app: iPhone/iPad and computers (WEB_ACCESS).
  // Defaults to allowed when the config can't be loaded, so nobody is stuck.
  window.HS_webAllowed = function (c) {
    if (c && c.web_login_mode === 'web') return true;
    var d = HS_device();
    if (d === 'android') return false;
    var list = (c && Array.isArray(c.web_access)) ? c.web_access : ['ios', 'desktop'];
    return list.indexOf(d) >= 0;
  };
  window.HS_iosAppUrl = function (who, c) {
    return (c && (who === 'provider' ? c.ios_provider_app_url : c.ios_customer_app_url)) || '';
  };

  // Known Play Store links (used when the server config is unreachable).
  var PLAY = { customer: 'https://play.google.com/store/apps/details?id=com.hamaraservice.customer', provider: 'https://play.google.com/store/apps/details?id=com.hamaraservice.provider' };
  window.HS_playUrl = function (who, c) {
    who = who === 'provider' ? 'provider' : 'customer';
    var app = c ? (who === 'provider' ? c.provider_app_url : c.customer_app_url) : '';
    return app || PLAY[who] || '';
  };

  window.HS_appUrl = function (who, c) {
    who = who === 'provider' ? 'provider' : 'customer';
    var app = HS_playUrl(who, c);
    return app || ('/get-app.html?for=' + who);
  };

  window.HS_support = function (who, context) {
    HS_site(function (c) {
      var msg = 'Hi HamaraService support, I am a ' + (who || 'customer') + '.' + (context ? ' ' + context : '') + ' I need help with: ';
      if (c.support_whatsapp) {
        window.open('https://wa.me/' + c.support_whatsapp + '?text=' + encodeURIComponent(msg), '_blank');
      } else {
        location.href = 'mailto:' + (c.support_email || 'info@hamaraservice.com') + '?subject=' + encodeURIComponent('Support request') + '&body=' + encodeURIComponent(msg);
      }
    });
  };

  // 'android' | 'ios' | 'desktop' — the Play Store only helps Android visitors.
  window.HS_device = function () {
    var ua = navigator.userAgent || '';
    if (/android/i.test(ua)) return 'android';
    if (/iphone|ipad|ipod/i.test(ua) || (/Macintosh/.test(ua) && navigator.maxTouchPoints > 1)) return 'ios';
    return 'desktop';
  };

  // The one place that decides where "Book / Login / Join / Get the app" goes.
  function destination(who, c, extra) {
    var d = HS_device(), play = HS_playUrl(who, c);
    var web = (who === 'provider' ? '/app/provider.html' : '/app/customer.html') + (extra || '');
    if (d === 'android') return play || '/get-app.html?for=' + who;
    if (d === 'ios') {
      var store = HS_iosAppUrl(who, c);
      if (store) return store;
      return HS_webAllowed(c) ? web : '/get-app.html?for=' + who;
    }
    // computer: QR code for the phone app, plus "Continue on the web" when allowed
    return HS_webAllowed(c) ? '/get-app.html?for=' + who : (play || '/get-app.html?for=' + who);
  }
  window.HS_destination = function (who, extra, cb) {
    who = who === 'provider' ? 'provider' : 'customer';
    HS_site(function (c) { cb(destination(who, c, extra)); });
  };
  window.HS_getApp = function (who, extra) {
    HS_destination(who, extra, function (url) { location.href = url; });
  };
  window.HS_login = window.HS_getApp;

  // Point plain install links (data-hs-getapp) at the right place for this device.
  function upgradeLinks() {
    var links = document.querySelectorAll('a[data-hs-getapp]');
    if (!links.length) return;
    HS_site(function (c) {
      for (var i = 0; i < links.length; i++) {
        var who = links[i].getAttribute('data-hs-getapp') === 'provider' ? 'provider' : 'customer';
        // App download links: on a computer straight to Google Play (its page can
        // install on the visitor's phone); iPhone → App Store / web app.
        var url = HS_device() === 'desktop' ? (HS_playUrl(who, c) || destination(who, c, '')) : destination(who, c, '');
        links[i].href = url;
        if (/^https?:/.test(url)) links[i].rel = 'noopener';
      }
    });
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', upgradeLinks);
  else upgradeLinks();
})();
