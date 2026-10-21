/* HamaraService — first-party website analytics (admin → Visitors).
 * <script src="/js/hs-track.js" defer></script> on every public page
 * (not on admin.html, hs-prices.html or /app/*). No cookies for ads, no third parties:
 * data goes only to /api/track.php on our own server.
 *
 * Sends: a page view (path without personal data, title, referrer, utm_* values,
 * gclid/fbclid presence only, screen, language, time zone), a heartbeat every 15 s
 * while the page is visible (active seconds), a final beacon when the page is hidden
 * or closed, and clicks on app-install / WhatsApp / call buttons.
 * Visitor id: random, localStorage 'hs_vid' (cookie fallback), 1 year.
 * Visit (session) id: random, renewed after 30 min without activity or when the
 * visitor arrives again from another site / campaign. Shared by tabs (localStorage,
 * sessionStorage fallback) so opening a second tab does not count as a new visit.
 * Respects Do-Not-Track (navigator.doNotTrack === '1') and Global Privacy Control:
 * then nothing is sent. Runs only on hamaraservice.com / www / localhost / 127.0.0.1.
 * Never throws, never blocks the page.
 */
(function () {
  'use strict';
  try {
    var host = location.hostname;
    if (!/^(www\.)?hamaraservice\.com$/.test(host) && host !== 'localhost' && host !== '127.0.0.1') return;
    if (navigator.doNotTrack === '1' || window.doNotTrack === '1' || navigator.msDoNotTrack === '1' || navigator.globalPrivacyControl === true) return;
    if (window.__hsTrack) return;
    window.__hsTrack = true;

    var END = '/api/track.php', IDLE = 30 * 60 * 1000, YEAR = 31536000;

    function rid() {
      var a = '', c = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789', b;
      try { b = new Uint8Array(20); (window.crypto || window.msCrypto).getRandomValues(b); } catch (e) { b = null; }
      for (var i = 0; i < 20; i++) a += c.charAt((b ? b[i] : Math.floor(Math.random() * 256)) % 62);
      return a;
    }
    function store(kind) { try { var s = window[kind]; var k = '__hs_t'; s.setItem(k, '1'); s.removeItem(k); return s; } catch (e) { return null; } }
    var LS = store('localStorage'), SS = store('sessionStorage');
    function cookie(name, val) {
      try {
        if (val === undefined) { var m = document.cookie.match(new RegExp('(?:^|; )' + name + '=([A-Za-z0-9_-]+)')); return m ? m[1] : ''; }
        document.cookie = name + '=' + val + '; max-age=' + YEAR + '; path=/; SameSite=Lax' + (location.protocol === 'https:' ? '; Secure' : '');
      } catch (e) {}
      return '';
    }
    var ID_RE = /^[A-Za-z0-9_-]{8,32}$/;

    // ── visitor id (1 year) ──
    var vid = '';
    try { vid = (LS && LS.getItem('hs_vid')) || cookie('hs_vid'); } catch (e) {}
    if (!ID_RE.test(vid || '')) vid = rid();
    try { if (LS) LS.setItem('hs_vid', vid); else cookie('hs_vid', vid); } catch (e) {}

    // ── page context ──
    function qp(name) { try { return new URLSearchParams(location.search).get(name) || ''; } catch (e) { return ''; } }
    var utm = { source: qp('utm_source'), medium: qp('utm_medium'), campaign: qp('utm_campaign'), term: qp('utm_term'), content: qp('utm_content') };
    var gclid = !!(qp('gclid') || qp('gbraid') || qp('wbraid')), fbclid = !!qp('fbclid');
    var ref = document.referrer || '';
    var refHost = ''; try { refHost = ref ? new URL(ref).hostname.replace(/^www\./, '') : ''; } catch (e) {}
    var external = !!refHost && refHost !== host.replace(/^www\./, '') && !/(^|\.)hamaraservice\.(com|in)$/.test(refHost);
    var campaign = !!(utm.source || utm.medium || utm.campaign || gclid || fbclid);

    // ── visit (session) id ──
    var SKEY = 'hs_sess', mem = null;
    function readSess() {
      var raw = null;
      try { raw = (LS && LS.getItem(SKEY)) || (SS && SS.getItem(SKEY)); } catch (e) {}
      try { var o = raw ? JSON.parse(raw) : mem; if (o && ID_RE.test(o.id) && typeof o.t === 'number') return o; } catch (e) {}
      return null;
    }
    function writeSess(o) { mem = o; var s = JSON.stringify(o); try { if (LS) LS.setItem(SKEY, s); else if (SS) SS.setItem(SKEY, s); } catch (e) {} }
    function session(forceNew) {
      var o = readSess(), now = Date.now();
      if (forceNew || !o || now - o.t > IDLE) o = { id: rid(), t: now }; else o.t = now;
      writeSess(o);
      return o.id;
    }
    function touch() { var o = readSess(); if (o && o.id === sid) { o.t = Date.now(); writeSess(o); } }

    // Arriving from another site or from a campaign link starts a new visit
    var sid = session(external || campaign);

    // Path + query without personal data (ids, contact details, tokens, coordinates)
    function cleanPath() {
      var p = location.pathname || '/', out = [];
      try {
        new URLSearchParams(location.search).forEach(function (v, k) {
          var kl = k.toLowerCase();
          if (/^(utm_|gclid|fbclid|gbraid|wbraid|msclkid|_ga|_gl)/.test(kl)) return;
          if (/mail|phone|mobile|tel|token|otp|pass|pwd|code|key|auth|secret|session|sid|name|address|addr|upi|account|card|pin|aadhaar|pan|lat|lng|lon/.test(kl)) return;
          if (/@|\d{7,}/.test(v)) return;
          out.push(encodeURIComponent(k) + '=' + encodeURIComponent(v.slice(0, 60)));
        });
      } catch (e) {}
      return (p + (out.length ? '?' + out.join('&') : '')).slice(0, 255);
    }

    // ── transport ──
    function send(data, beacon) {
      try {
        data.vid = vid; data.sid = sid; data.pid = pid;
        var body = JSON.stringify(data);
        if (beacon && navigator.sendBeacon) {
          if (navigator.sendBeacon(END, new Blob([body], { type: 'text/plain;charset=UTF-8' }))) return;
        }
        if (window.fetch) {
          fetch(END, { method: 'POST', body: body, keepalive: true, credentials: 'same-origin', headers: { 'Content-Type': 'text/plain;charset=UTF-8' } })
            .catch(function () {});
        }
      } catch (e) {}
    }

    // ── active (visible) time on this page ──
    var pid = rid(), active = 0, since = document.visibilityState === 'visible' ? Date.now() : 0, lastSent = -1, hiddenAt = 0;
    function secs() { return Math.round((active + (since ? Date.now() - since : 0)) / 1000); }
    function pageview() {
      send({ a: 'pageview', path: cleanPath(), title: (document.title || '').slice(0, 150), ref: ref.slice(0, 500), utm: utm,
             gclid: gclid ? 1 : 0, fbclid: fbclid ? 1 : 0,
             screen: (screen && screen.width ? screen.width + 'x' + screen.height : ''),
             lang: (navigator.language || '').slice(0, 20),
             tz: (function () { try { return Intl.DateTimeFormat().resolvedOptions().timeZone || ''; } catch (e) { return ''; } })() });
    }
    function beat(final) {
      var s = secs();
      if (final && s === lastSent) return;
      lastSent = s; touch();
      send({ a: 'heartbeat', secs: s, final: final ? 1 : 0 }, !!final);
    }

    pageview();
    setInterval(function () { if (document.visibilityState === 'visible') beat(false); }, 15000);
    document.addEventListener('visibilitychange', function () {
      try {
        if (document.visibilityState === 'hidden') {
          if (since) { active += Date.now() - since; since = 0; }
          hiddenAt = Date.now();
          beat(true);
        } else {
          // back after more than 30 min → a new visit with a fresh page view
          if (hiddenAt && Date.now() - hiddenAt > IDLE) {
            sid = session(true); pid = rid(); active = 0; lastSent = -1; ref = ''; external = false;
            since = Date.now(); pageview();
          } else since = Date.now();
          hiddenAt = 0;
        }
      } catch (e) {}
    });
    window.addEventListener('pagehide', function () {
      try { if (since) { active += Date.now() - since; since = Date.now(); } beat(true); } catch (e) {}
    });

    // ── calls to action ──
    var lastEv = {};
    function ev(type, who, target) {
      try {
        who = who === 'provider' ? 'provider' : (who === 'customer' ? 'customer' : '');
        var k = type + '|' + who, now = Date.now();
        if (lastEv[k] && now - lastEv[k] < 1500) return;   // link + JS helper for the same click
        lastEv[k] = now; touch();
        send({ a: 'event', type: type, who: who, target: String(target || '').slice(0, 300), path: cleanPath() }, true);
      } catch (e) {}
    }
    function pageWho() { return /job|partner|provider|join/i.test(location.pathname + ' ' + location.search) ? 'provider' : 'customer'; }
    function whoFrom(el, href) {
      var n = el.closest && el.closest('[data-hs-getapp],[data-hs-who]');
      if (n) return n.getAttribute('data-hs-getapp') || n.getAttribute('data-hs-who');
      var m = /[?&]for=(customer|provider)/.exec(href || '');
      if (m) return m[1];
      if (/provider|partner/i.test(href || '')) return 'provider';
      var t = (el.textContent || '').toLowerCase();
      if (/partner|join|provider|earn/.test(t)) return 'provider';
      return pageWho();
    }
    document.addEventListener('click', function (e) {
      try {
        var el = e.target && e.target.closest && e.target.closest('a[href],[data-hs-getapp],button,[onclick]');
        if (!el) return;
        var oc = el.getAttribute('onclick') || '';
        if (/HS_(getApp|login|support)\s*\(/.test(oc)) return;   // counted by the wrapped helpers below
        var href = el.getAttribute('href') || '';
        var abs = el.href || href;
        if (el.hasAttribute('data-hs-getapp')) return ev('install_click', el.getAttribute('data-hs-getapp'), abs);
        if (/^tel:/i.test(href)) return ev('call_click', whoFrom(el, href), href);
        if (/(^|\/\/)(wa\.me|api\.whatsapp\.com|chat\.whatsapp\.com)\b|^whatsapp:/i.test(href)) return ev('whatsapp_click', whoFrom(el, href), abs);
        if (/play\.google\.com\/store|market:\/\//i.test(href)) return ev('install_click', whoFrom(el, href), abs);
        if (/(^|\/)get-app\.html/i.test(href)) return ev('install_click', whoFrom(el, href), abs);
      } catch (x) {}
    }, true);

    // HS_getApp / HS_login ("Book in App", login, install buttons) and HS_support (WhatsApp)
    function wrap(name, type) {
      try {
        var f = window[name];
        if (typeof f !== 'function' || f.__hsT) return;
        var w = function (who) {
          ev(type, who === 'provider' ? 'provider' : (who === 'customer' ? 'customer' : pageWho()), name);
          return f.apply(this, arguments);
        };
        w.__hsT = true;
        window[name] = w;
      } catch (e) {}
    }
    function wrapAll() { wrap('HS_getApp', 'install_click'); wrap('HS_login', 'install_click'); wrap('HS_support', 'whatsapp_click'); }
    wrapAll();
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', wrapAll);
    window.addEventListener('load', wrapAll);
  } catch (e) { /* analytics must never break the page */ }
})();
