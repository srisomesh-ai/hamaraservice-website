/* HamaraService — public site settings + shared support / login helpers.
 *   HS_site(function (cfg) { ... })     cfg from /api/site.php?action=config
 *   HS_support('customer'|'provider', 'optional context')   opens WhatsApp (email fallback)
 *   HS_login('customer'|'provider')     web login or app link, per WEB_LOGIN_MODE
 */
(function () {
  var cfg = null, waiting = [];
  var API = (location.hostname === 'hamaraservice.com' || location.hostname === 'www.hamaraservice.com')
    ? '/api' : 'https://hamaraservice.com/api';
  function load() {
    fetch(API + '/site.php?action=config').then(function (r) { return r.json(); })
      .then(function (res) { cfg = (res && res.success && res.data) || {}; })
      .catch(function () { cfg = {}; })
      .then(function () { var w = waiting; waiting = []; w.forEach(function (cb) { try { cb(cfg); } catch (e) {} }); });
  }
  window.HS_site = function (cb) { if (cfg) cb(cfg); else { waiting.push(cb); if (waiting.length === 1) load(); } };

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

  // Website login buttons:
  //  - WEB_LOGIN_MODE 'app' + app link set → straight to the app (Play Store)
  //  - app link set (mode 'web')           → /get-app.html: download the app or continue on web
  //  - no app link yet                     → the web app
  window.HS_login = function (who, extra) {
    who = who === 'provider' ? 'provider' : 'customer';
    var web = (who === 'provider' ? '/app/provider.html' : '/app/customer.html') + (extra || '');
    HS_site(function (c) {
      var app = who === 'provider' ? c.provider_app_url : c.customer_app_url;
      if (app && c.web_login_mode === 'app') location.href = app;
      else if (app) location.href = '/get-app.html?for=' + who;
      else location.href = web;
    });
  };
})();
