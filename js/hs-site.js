/* HamaraService — public site settings + shared support / app-install helpers.
 *   HS_site(function (cfg) { ... })     cfg from /api/site.php?action=config
 *   HS_support('customer'|'provider', 'optional context')   opens WhatsApp (email fallback)
 *   HS_getApp('customer'|'provider')    install the right Android app:
 *                                       Play Store link when set in site config, else /get-app.html?for=…
 *   HS_login('customer'|'provider')     WEB_LOGIN_MODE 'app' (default) → HS_getApp;
 *                                       'web' → the old web app (/app/customer.html, /app/provider.html)
 *   <a data-hs-getapp="customer" href="/get-app.html?for=customer">  → href is switched to the
 *                                       Play Store link automatically once one is configured.
 */
(function () {
  var cfg = null, waiting = [];
  var h = location.hostname;
  var API = (h === 'hamaraservice.com' || h === 'www.hamaraservice.com' || h === 'localhost' || h === '127.0.0.1')
    ? '/api' : 'https://hamaraservice.com/api';
  function load() {
    fetch(API + '/site.php?action=config').then(function (r) { return r.json(); })
      .then(function (res) { cfg = (res && res.success && res.data) || {}; })
      .catch(function () { cfg = {}; })
      .then(function () { var w = waiting; waiting = []; w.forEach(function (cb) { try { cb(cfg); } catch (e) {} }); });
  }
  window.HS_site = function (cb) { if (cfg) cb(cfg); else { waiting.push(cb); if (waiting.length === 1) load(); } };

  // 'app' unless the server explicitly says 'web' (also when the API is unreachable)
  window.HS_appMode = function (c) { return !(c && c.web_login_mode === 'web'); };

  window.HS_appUrl = function (who, c) {
    who = who === 'provider' ? 'provider' : 'customer';
    var app = c ? (who === 'provider' ? c.provider_app_url : c.customer_app_url) : '';
    return app || ('/get-app.html?for=' + who);
  };

  window.HS_support = function (who, context) {
    HS_site(function (c) {
      var msg = 'Hi HamaraService support, I am a ' + (who || 'customer') + '.' + (context ? ' ' + context : '') + ' I need help with: ';
      if (c.support_whatsapp) {
        window.open('https://wa.me/' + c.support_whatsapp + '?text=' + encodeURIComponent(msg), '_blank');
      } else {
        location.href = 'mailto:' + (c.support_email || 'support@hamaraservice.com') + '?subject=' + encodeURIComponent('Support request') + '&body=' + encodeURIComponent(msg);
      }
    });
  };

  // The one place that decides where "install the app" goes.
  window.HS_getApp = function (who) {
    HS_site(function (c) { location.href = HS_appUrl(who, c); });
  };

  // Website login / register / book buttons.
  //  - WEB_LOGIN_MODE 'app' (default): no web login → install the app (HS_getApp)
  //  - WEB_LOGIN_MODE 'web': app link set → /get-app.html (app or continue on web); else the web app
  window.HS_login = function (who, extra) {
    who = who === 'provider' ? 'provider' : 'customer';
    var web = (who === 'provider' ? '/app/provider.html' : '/app/customer.html') + (extra || '');
    HS_site(function (c) {
      if (HS_appMode(c)) { location.href = HS_appUrl(who, c); return; }
      var app = who === 'provider' ? c.provider_app_url : c.customer_app_url;
      location.href = app ? '/get-app.html?for=' + who : web;
    });
  };

  // Upgrade plain install links to the Play Store link once configured.
  function upgradeLinks() {
    var links = document.querySelectorAll('a[data-hs-getapp]');
    if (!links.length) return;
    HS_site(function (c) {
      for (var i = 0; i < links.length; i++) {
        var who = links[i].getAttribute('data-hs-getapp');
        var app = who === 'provider' ? c.provider_app_url : c.customer_app_url;
        if (app) { links[i].href = app; links[i].rel = 'noopener'; }
      }
    });
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', upgradeLinks);
  else upgradeLinks();
})();
