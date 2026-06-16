// ═══════════════════════════════════════════════════════════════════
//  HAMARA SERVICE — service-base.js
//  Shared engine for all service detail pages.
//  Each service page defines window.SVC_CONFIG then loads this file.
// ═══════════════════════════════════════════════════════════════════

(function() {

// ── Inject CSS ───────────────────────────────────────────────────────────────
var css = `
:root{
  --brand:#E8651A;--brand2:#c9510f;--brand-soft:#fff3eb;
  --teal:#1B6B7A;--teal2:#134f5c;--teal-soft:#e6f4f6;
  --ink:#1a1a2e;--ink2:#2d3748;--muted:#718096;
  --line:#e2e8f0;--bg:#f0f7f9;--white:#fff;
  --green:#2ecc71;--green-soft:#e8f8f0;--yellow:#f59e0b;
  --r:12px;--r2:20px;--sh:0 2px 16px rgba(0,0,0,.07);--sh2:0 8px 32px rgba(0,0,0,.10);
}
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0;}
html{scroll-behavior:smooth;-webkit-font-smoothing:antialiased;}
body{font-family:'DM Sans',sans-serif;background:var(--bg);color:var(--ink);font-size:16px;line-height:1.6;overflow-x:hidden;}

/* HEADER */
header{position:sticky;top:0;z-index:900;background:var(--teal);height:60px;padding:0 24px;display:flex;align-items:center;justify-content:space-between;box-shadow:0 2px 12px rgba(0,0,0,.2);}
.hdr-logo{display:flex;align-items:center;gap:10px;text-decoration:none;}
.hdr-logo-box{width:34px;height:34px;background:rgba(255,255,255,.18);border-radius:9px;display:flex;align-items:center;justify-content:center;}
.hdr-logo-box svg{width:18px;height:18px;fill:white;}
.hdr-logo-name{font-family:'Sora',sans-serif;font-size:18px;font-weight:800;color:white;}
.hdr-logo-name span{color:#ffb07a;}
.hdr-back{display:flex;align-items:center;gap:6px;color:rgba(255,255,255,.85);font-size:14px;font-weight:600;text-decoration:none;padding:8px 14px;border:1.5px solid rgba(255,255,255,.25);border-radius:9px;transition:all .2s;}
.hdr-back:hover{background:rgba(255,255,255,.15);color:white;}

/* HERO */
.svc-hero{background:linear-gradient(135deg,#0d3d47 0%,var(--teal) 100%);padding:36px 24px 56px;position:relative;overflow:hidden;}
.svc-hero::after{content:'';position:absolute;bottom:-1px;left:0;right:0;height:40px;background:linear-gradient(to top,var(--bg),transparent);pointer-events:none;}
.svc-hero-inner{max-width:900px;margin:0 auto;display:flex;align-items:center;gap:20px;}
.svc-hero-icon{font-size:64px;flex-shrink:0;filter:drop-shadow(0 6px 16px rgba(0,0,0,.3));}
.svc-hero-cat{font-size:11px;font-weight:700;color:rgba(255,255,255,.5);text-transform:uppercase;letter-spacing:1.2px;margin-bottom:6px;}
.svc-hero-name{font-family:'Sora',sans-serif;font-size:clamp(22px,4vw,38px);font-weight:800;color:white;letter-spacing:-.4px;margin-bottom:8px;}
.svc-hero-tagline{font-size:15px;color:rgba(255,255,255,.6);margin-bottom:16px;}
.svc-hero-pills{display:flex;flex-wrap:wrap;gap:7px;}
.svc-pill{padding:5px 13px;background:rgba(255,255,255,.1);border:1px solid rgba(255,255,255,.2);border-radius:100px;font-size:12px;font-weight:600;color:rgba(255,255,255,.85);}
.svc-pill.green{background:rgba(46,204,113,.15);border-color:rgba(46,204,113,.3);color:#7fffc4;}
@media(max-width:560px){.svc-hero-inner{flex-direction:column;text-align:center;align-items:center;}.svc-hero-pills{justify-content:center;}}

/* LAYOUT */
.svc-main{max-width:900px;margin:0 auto;padding:28px 20px 120px;display:grid;grid-template-columns:1fr 300px;gap:22px;align-items:start;}
@media(max-width:768px){.svc-main{grid-template-columns:1fr;padding:16px 14px 120px;}}

/* CARDS */
.card{background:white;border-radius:var(--r2);border:1px solid var(--line);box-shadow:var(--sh);margin-bottom:18px;overflow:hidden;}
.card-head{padding:18px 22px 14px;border-bottom:1px solid var(--line);display:flex;align-items:center;gap:10px;}
.card-icon{width:38px;height:38px;border-radius:10px;background:var(--teal-soft);display:flex;align-items:center;justify-content:center;font-size:18px;flex-shrink:0;}
.card-title{font-family:'Sora',sans-serif;font-size:15px;font-weight:700;color:var(--ink);}
.card-body{padding:18px 22px;}
@media(max-width:768px){.card-body{padding:14px 16px;}.card-head{padding:14px 16px;}}

/* INCLUDES */
.inc-list{display:flex;flex-direction:column;gap:9px;}
.inc-item{display:flex;align-items:flex-start;gap:10px;padding:10px 12px;background:var(--bg);border-radius:10px;}
.inc-check{width:20px;height:20px;border-radius:50%;background:var(--green-soft);color:var(--green);display:flex;align-items:center;justify-content:center;font-size:11px;font-weight:800;flex-shrink:0;margin-top:1px;}
.inc-text{font-size:14px;font-weight:500;color:var(--ink2);line-height:1.5;}
.excl-list{display:flex;flex-direction:column;gap:7px;margin-top:14px;}
.excl-item{display:flex;align-items:flex-start;gap:9px;font-size:13px;color:var(--muted);}
.excl-x{color:#fc8181;font-weight:800;flex-shrink:0;}

/* STEPS */
.steps-list{display:flex;flex-direction:column;gap:0;}
.step-item{display:flex;gap:14px;padding:14px 0;position:relative;}
.step-item:not(:last-child)::before{content:'';position:absolute;left:17px;top:50px;bottom:-8px;width:2px;background:var(--line);}
.step-num{width:34px;height:34px;border-radius:50%;background:var(--teal);color:white;font-family:'Sora',sans-serif;font-weight:800;font-size:13px;display:flex;align-items:center;justify-content:center;flex-shrink:0;}
.step-title{font-size:14px;font-weight:700;color:var(--ink);margin-bottom:2px;}
.step-desc{font-size:13px;color:var(--muted);line-height:1.55;}

/* BEFORE/AFTER */
.ba-grid{display:grid;grid-template-columns:1fr 1fr;gap:12px;}
.ba-card{border-radius:var(--r);overflow:hidden;border:1.5px solid var(--line);}
.ba-label{padding:7px 12px;font-size:11px;font-weight:800;text-transform:uppercase;letter-spacing:.7px;}
.ba-label.before{background:#fee2e2;color:#c53030;}
.ba-label.after{background:var(--green-soft);color:#27ae60;}
.ba-img{width:100%;height:130px;display:flex;align-items:center;justify-content:center;font-size:52px;background:var(--bg);}

/* FAQ */
.faq-item{border-bottom:1px solid var(--line);padding:14px 0;}
.faq-item:last-child{border:none;}
.faq-q{font-size:14px;font-weight:700;color:var(--ink);cursor:pointer;display:flex;justify-content:space-between;align-items:center;gap:10px;}
.faq-arr{flex-shrink:0;transition:transform .2s;color:var(--muted);font-size:18px;}
.faq-item.open .faq-arr{transform:rotate(180deg);}
.faq-a{font-size:13px;color:var(--muted);line-height:1.65;max-height:0;overflow:hidden;transition:max-height .3s ease,padding .3s;}
.faq-item.open .faq-a{max-height:200px;padding-top:8px;}

/* SIMILAR */
.sim-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:10px;}
.sim-card{background:white;border:1.5px solid var(--line);border-radius:var(--r);padding:14px 10px;text-align:center;cursor:pointer;transition:all .2s;text-decoration:none;display:block;}
.sim-card:hover{border-color:var(--brand);transform:translateY(-2px);box-shadow:var(--sh);}
.sim-icon{font-size:28px;margin-bottom:6px;}
.sim-name{font-size:11px;font-weight:700;color:var(--ink2);}
.sim-price{font-size:11px;color:var(--green);font-weight:600;margin-top:1px;}

/* RATING */
.rating-row{display:flex;align-items:center;gap:16px;padding:14px 18px;background:var(--bg);border-radius:var(--r);}
.rating-num{font-family:'Sora',sans-serif;font-size:36px;font-weight:800;color:var(--ink);flex-shrink:0;}
.rating-stars{color:var(--yellow);font-size:16px;}
.rating-count{font-size:12px;color:var(--muted);margin-top:2px;}
.rbar-row{display:flex;align-items:center;gap:7px;font-size:11px;color:var(--muted);margin-bottom:3px;}
.rbar-track{flex:1;height:5px;background:var(--line);border-radius:3px;overflow:hidden;}
.rbar-fill{height:100%;border-radius:3px;background:var(--yellow);}

/* BOOKING CARD */
.book-card{position:sticky;top:80px;background:white;border-radius:var(--r2);border:1px solid var(--line);box-shadow:var(--sh2);overflow:hidden;}
.book-top{background:linear-gradient(135deg,#0d3d47,var(--teal));padding:22px;}
.book-price{font-family:'Sora',sans-serif;font-size:32px;font-weight:800;color:white;line-height:1;}
.book-price sub{font-size:14px;font-weight:500;color:rgba(255,255,255,.6);}
.book-note{font-size:11px;color:rgba(255,255,255,.5);margin-top:4px;}
.book-meta{display:flex;flex-direction:column;gap:9px;padding:18px 22px;border-bottom:1px solid var(--line);}
.book-meta-row{display:flex;justify-content:space-between;align-items:center;font-size:13px;}
.book-meta-label{color:var(--muted);font-weight:500;}
.book-meta-val{font-weight:700;color:var(--ink);}
.book-btn{width:100%;padding:16px;background:var(--brand);color:white;border:none;font-family:'Sora',sans-serif;font-size:16px;font-weight:700;cursor:pointer;transition:background .2s;letter-spacing:-.2px;}
.book-btn:hover{background:var(--brand2);}
.book-trust{padding:14px 22px;display:flex;flex-direction:column;gap:7px;}
.book-trust-item{display:flex;align-items:center;gap:7px;font-size:12px;color:var(--muted);font-weight:500;}

/* MOBILE BOOK BAR */
.mob-book-bar{display:none;}
@media(max-width:768px){
  .mob-book-bar{display:flex;align-items:center;justify-content:space-between;position:fixed;bottom:0;left:0;right:0;background:white;border-top:1px solid var(--line);padding:12px 16px calc(12px + env(safe-area-inset-bottom));box-shadow:0 -4px 20px rgba(0,0,0,.10);z-index:9999;}
  .mob-book-price{font-family:'Sora',sans-serif;font-size:20px;font-weight:800;color:var(--teal);}
  .mob-book-price sub{font-size:12px;color:var(--muted);}
  .mob-book-btn{padding:12px 24px;background:var(--brand);color:white;border:none;border-radius:100px;font-family:'Sora',sans-serif;font-size:15px;font-weight:700;cursor:pointer;}
  .book-card{display:none;}
}

/* SUBCATEGORY MODAL */
.sub-overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,.55);z-index:99998;align-items:flex-end;justify-content:center;}
.sub-overlay.open{display:flex;}
.sub-modal{background:white;width:100%;max-width:560px;border-radius:24px 24px 0 0;max-height:85vh;overflow-y:auto;animation:slideUp .25s ease;}
@keyframes slideUp{from{transform:translateY(100%)}to{transform:translateY(0)}}
.sub-modal-head{padding:18px 22px 14px;border-bottom:1px solid var(--line);position:sticky;top:0;background:white;z-index:1;display:flex;align-items:center;justify-content:space-between;}
.sub-modal-title{font-family:'Sora',sans-serif;font-size:17px;font-weight:800;color:var(--ink);}
.sub-modal-close{width:30px;height:30px;border-radius:50%;background:var(--bg);border:none;cursor:pointer;font-size:16px;color:var(--muted);}
.sub-modal-body{padding:18px 22px 28px;}
.sub-section{margin-bottom:20px;}
.sub-label{font-size:11px;font-weight:800;color:var(--muted);text-transform:uppercase;letter-spacing:.8px;margin-bottom:9px;display:flex;align-items:center;gap:4px;}
.sub-req{color:var(--brand);}
.sub-chips{display:flex;flex-wrap:wrap;gap:7px;}
.sub-chip{padding:9px 16px;border:2px solid var(--line);border-radius:14px;font-family:'DM Sans',sans-serif;font-size:13px;font-weight:600;cursor:pointer;transition:all .15s;color:var(--ink2);background:var(--bg);display:flex;flex-direction:column;align-items:center;gap:2px;text-align:center;}
.sub-chip:hover{border-color:var(--teal);color:var(--teal);background:var(--teal-soft);}
.sub-chip.sel{background:var(--teal);border-color:var(--teal);color:white;}
.sub-chip.sel .chip-price{color:rgba(255,255,255,.8);}
.chip-label{font-size:13px;font-weight:600;line-height:1.3;}
.chip-price{font-size:11px;font-weight:700;color:var(--teal);margin-top:1px;}
.sub-stepper{display:flex;align-items:center;border:2px solid var(--line);border-radius:12px;overflow:hidden;width:fit-content;}
.sub-step-btn{width:42px;height:42px;border:none;background:var(--bg);font-size:20px;font-weight:700;cursor:pointer;color:var(--teal);}
.sub-step-btn:hover{background:var(--teal-soft);}
.sub-step-val{min-width:46px;text-align:center;font-family:'Sora',sans-serif;font-size:17px;font-weight:800;color:var(--ink);}
.sub-input{width:100%;padding:11px 13px;border:2px solid var(--line);border-radius:11px;font-family:'DM Sans',sans-serif;font-size:14px;color:var(--ink);background:var(--bg);outline:none;box-sizing:border-box;}
.sub-input:focus{border-color:var(--teal);}
.sub-select{width:100%;padding:11px 36px 11px 13px;border:2px solid var(--line);border-radius:11px;font-family:'DM Sans',sans-serif;font-size:14px;color:var(--ink);background:var(--bg);outline:none;cursor:pointer;appearance:none;background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='14' height='14' viewBox='0 0 24 24' fill='none' stroke='%23718096' stroke-width='2'%3E%3Cpath d='M6 9l6 6 6-6'/%3E%3C/svg%3E");background-repeat:no-repeat;background-position:right 12px center;box-sizing:border-box;}
.sub-select:focus{border-color:var(--teal);}
.sub-summary{background:var(--teal-soft);border:1.5px solid rgba(27,107,122,.2);border-radius:11px;padding:11px 14px;font-size:12px;font-weight:600;color:var(--teal);margin-bottom:14px;display:none;line-height:1.5;}
.sub-confirm{width:100%;padding:15px;background:var(--brand);color:white;border:none;border-radius:13px;font-family:'Sora',sans-serif;font-size:15px;font-weight:700;cursor:pointer;margin-top:8px;}
.sub-confirm:hover{background:var(--brand2);}

/* TOAST */
.toast{position:fixed;bottom:80px;left:50%;transform:translateX(-50%);background:var(--ink);color:white;padding:11px 20px;border-radius:100px;font-size:13px;font-weight:600;z-index:99999;display:none;box-shadow:0 8px 32px rgba(0,0,0,.2);white-space:nowrap;max-width:90vw;}

/* ─── INLINE PRICE CALCULATOR ─── */
.calc-section{margin-bottom:22px;}
.calc-section-label{font-size:11px;font-weight:800;color:var(--muted);text-transform:uppercase;letter-spacing:.8px;margin-bottom:10px;}
.calc-req{color:var(--brand);}
.calc-chips{display:flex;flex-wrap:wrap;gap:8px;}
.calc-chip{display:flex;flex-direction:column;align-items:center;padding:10px 16px;min-width:80px;border:2px solid var(--line);border-radius:14px;cursor:pointer;transition:all .18s;background:white;text-align:center;}
.calc-chip:hover{border-color:var(--teal);background:var(--teal-soft);}
.calc-chip.sel{border-color:var(--brand);background:var(--brand-soft);}
.cc-name{font-size:13px;font-weight:600;color:var(--ink2);line-height:1.3;}
.cc-price{font-size:12px;font-weight:700;color:var(--teal);margin-top:3px;}
.calc-chip.sel .cc-name{color:var(--brand);font-weight:700;}
.calc-chip.sel .cc-price{color:var(--brand);}
.calc-summary{background:linear-gradient(135deg,#0d3d47,var(--teal));border-radius:16px;padding:18px 20px;display:flex;align-items:center;justify-content:space-between;gap:16px;margin-top:4px;}
.calc-sum-label{font-size:11px;font-weight:700;color:rgba(255,255,255,.6);text-transform:uppercase;letter-spacing:.6px;margin-bottom:4px;}
.calc-sum-price{font-family:'Sora',sans-serif;font-size:28px;font-weight:800;color:white;line-height:1;}
.calc-sum-note{font-size:11px;color:rgba(255,255,255,.5);margin-top:3px;}
.calc-book-btn{padding:13px 24px;background:var(--brand);color:white;border:none;border-radius:12px;font-family:'Sora',sans-serif;font-size:15px;font-weight:700;cursor:pointer;white-space:nowrap;flex-shrink:0;transition:background .2s;}
.calc-book-btn:hover{background:var(--brand2);}
@media(max-width:480px){.calc-summary{flex-direction:column;align-items:flex-start;gap:12px;}.calc-book-btn{width:100%;}.calc-sum-price{font-size:24px;}}
`;

var styleEl = document.createElement('style');
styleEl.textContent = css;
document.head.appendChild(styleEl);

// ── Render page from SVC_CONFIG ──────────────────────────────────────────────
var C = window.SVC_CONFIG;
if (!C) { console.error('SVC_CONFIG not defined'); return; }

// ── SYNC PRICE FROM FIREBASE (admin source of truth) ─────────────────────
var CATALOG_URL = 'catalog.json';

function applyAdminData(catalog) {
  if (!catalog) return;
  // Normalise array or object
  var arr = Array.isArray(catalog) ? catalog.filter(Boolean) : Object.values(catalog).filter(Boolean);
  if (!arr.length) return;
  var svc = arr.find(function(s) { return s.name === C.name; });
  if (!svc) return;

  // If admin marked inactive — show unavailable screen
  if (svc.status === 'inactive') {
    document.body.innerHTML = '<div style="display:flex;flex-direction:column;align-items:center;justify-content:center;min-height:100vh;font-family:DM Sans,sans-serif;background:#f0f7f9;gap:16px;text-align:center;padding:24px;">'
      + '<div style="font-size:48px;">🚫</div>'
      + '<div style="font-size:22px;font-weight:800;color:#1a1a2e;">Service Temporarily Unavailable</div>'
      + '<div style="font-size:15px;color:#718096;">This service is currently paused. Please check back later.</div>'
      + '<a href="index.html" style="padding:12px 28px;background:#1B6B7A;color:white;border-radius:100px;text-decoration:none;font-weight:700;margin-top:8px;">← Back to Home</a>'
      + '</div>';
    return;
  }

  // Override with admin values
  if (svc.userPrice) C.price = svc.userPrice;
  if (svc.icon)      C.icon  = svc.icon;
  C.providerEarns = svc.providerEarns || null;
}

// Step 1: Apply from localStorage immediately (fast, no delay)
(function() {
  try {
    var cached = JSON.parse(localStorage.getItem('hs_catalog') || '[]');
    if (cached.length) applyAdminData(cached);
  } catch(e) {}
})();

document.title = C.name + ' – Hamara Service';

// Step 2a: Fetch hs_service_prices from Firebase (set by hs-prices.html admin tool)
// This is the PRIMARY price source — overrides everything else
(function fetchHSPrices() {
  var FB_PRICES = 'https://hamaraservice-s009-default-rtdb.asia-southeast1.firebasedatabase.app/hs_service_prices';
  var API_KEY   = 'AIzaSyDpMpewyKVlfsfSeKfoS3GJf0V_t14Qb7k';

  function applyPrice(data) {
    if (!data || data === 'null') return false;
    var newPrice = data.base != null ? parseInt(data.base) : null;
    if (newPrice && newPrice > 0) { C.price = newPrice; updatePriceDisplays(); return true; }
    return false;
  }

  function fetchPrices(token) {
    var auth  = token ? '?auth=' + token : '';
    var idUrl = FB_PRICES + '/' + C.id + '.json' + auth + (token ? '&' : '?') + '_=' + Date.now();
    var allUrl= FB_PRICES + '.json' + auth + (token ? '&' : '?') + '_=' + Date.now();

    // Step 1: try by ID
    if (C.id) {
      var x1 = new XMLHttpRequest();
      x1.open('GET', idUrl, true); x1.timeout = 6000;
      x1.onload = function() {
        try {
          var d = JSON.parse(x1.responseText);
          if (d && d !== 'null' && applyPrice(d)) return;
        } catch(e) {}
        fetchByName(allUrl); // fallback to name scan
      };
      x1.onerror = x1.ontimeout = function() { fetchByName(allUrl); };
      x1.send();
    } else {
      fetchByName(allUrl);
    }
  }

  function fetchByName(url) {
    var x2 = new XMLHttpRequest();
    x2.open('GET', url, true); x2.timeout = 8000;
    x2.onload = function() {
      try {
        var all = JSON.parse(x2.responseText);
        if (!all || typeof all !== 'object') return;
        var cname = (C.name || '').toLowerCase().trim();
        var keys = Object.keys(all);
        for (var i = 0; i < keys.length; i++) {
          var e = all[keys[i]]; if (!e) continue;
          var ename = (e.name || e.service || '').toLowerCase().trim();
          if ((ename && ename === cname) || keys[i] === C.id) {
            if (applyPrice(e)) return;
          }
        }
      } catch(e2) {}
    };
    x2.send();
  }

  // Get anonymous auth token first, then fetch
  var xa = new XMLHttpRequest();
  xa.open('POST', 'https://identitytoolkit.googleapis.com/v1/accounts:signInAnonymously?key=' + API_KEY, true);
  xa.setRequestHeader('Content-Type', 'application/json');
  xa.timeout = 5000;
  xa.onload = function() {
    try { var d = JSON.parse(xa.responseText); fetchPrices(d.idToken || ''); }
    catch(e) { fetchPrices(''); }
  };
  xa.onerror = xa.ontimeout = function() { fetchPrices(''); };
  xa.send(JSON.stringify({returnSecureToken: true}));
})();
// Step 2b: Fetch catalog.json for service status and metadata
(function fetchFromFirebase() {
  var xhr = new XMLHttpRequest();
  xhr.open('GET', CATALOG_URL + '?_=' + Date.now(), true);
  xhr.timeout = 6000;
  xhr.onload = function() {
    try {
      var raw = JSON.parse(xhr.responseText);
      if (!raw) return;
      var data = Array.isArray(raw) ? raw.filter(Boolean) : Object.values(raw).filter(Boolean);
      if (!data.length) return;
      try { localStorage.setItem('hs_catalog', JSON.stringify(data)); } catch(e){}
      var svc = data.find(function(s) { return s.name === C.name; });
      if (!svc) return;
      if (svc.status === 'inactive') { applyAdminData(data); return; }
      // Only update price from catalog if hs_service_prices didn't override it
      if (svc.userPrice && svc.userPrice !== C.price && !_hsPriceLoaded) {
        C.price = svc.userPrice;
        updatePriceDisplays();
      }
    } catch(e) {}
  };
  xhr.send();
})();

var _hsPriceLoaded = false;
function updatePriceDisplays() {
  _hsPriceLoaded = true;
  var bp = document.querySelector('.book-price');
  var mp = document.querySelector('.mob-book-price');
  if (bp) bp.innerHTML = '₹' + C.price + ' <sub>' + C.unit + '</sub>';
  if (mp) mp.innerHTML = '₹' + C.price + ' <sub>' + C.unit + '</sub>';
  // Also update the price calculator base price
  var calcNote = document.querySelector('.calc-sum-label');
  if (calcNote) {
    var priceEl = document.getElementById('calcTotalPrice');
    if (priceEl && priceEl.textContent.includes('499')) {
      priceEl.textContent = '₹' + C.price;
    }
  }
}


// Header
document.body.innerHTML = `
<header>
  <a class="hdr-logo" href="index.html">
    <div class="hdr-logo-box"><svg viewBox="0 0 24 24"><path d="M10 20v-6h4v6h5v-8h3L12 3 2 12h3v8z"/></svg></div>
    <span class="hdr-logo-name">Hamara<span>Service</span></span>
  </a>
  <a class="hdr-back" href="index.html">← Home</a>
</header>

<div class="svc-hero">
  <div class="svc-hero-inner">
    <div class="svc-hero-icon">${C.icon}</div>
    <div>
      <div class="svc-hero-cat">${C.cat}</div>
      <div class="svc-hero-name">${C.name}</div>
      <div class="svc-hero-tagline">${C.tagline}</div>
      <div class="svc-hero-pills">
        <div class="svc-pill">⏱ Min ${C.minHours} ${C.minHours===0.5?'hour':'hours'}</div>
        <div class="svc-pill">⏳ ${C.duration}</div>
        <div class="svc-pill green">✅ Verified Pro</div>
        <div class="svc-pill">🛡 30-day guarantee</div>
      </div>
    </div>
  </div>
</div>

<div class="svc-main">
  <div class="svc-left">

    <div class="card">
      <div class="card-head"><div class="card-icon">📋</div><div class="card-title">About This Service</div></div>
      <div class="card-body"><p style="font-size:15px;color:var(--ink2);line-height:1.75;">${C.desc}</p></div>
    </div>

    <div class="card">
      <div class="card-head"><div class="card-icon">✅</div><div class="card-title">What's Included</div></div>
      <div class="card-body">
        <div class="inc-list">${C.includes.map(i=>`<div class="inc-item"><div class="inc-check">✓</div><div class="inc-text">${i}</div></div>`).join('')}</div>
        ${C.excludes&&C.excludes.length?`<div class="excl-list"><div style="font-size:11px;font-weight:800;color:var(--muted);text-transform:uppercase;letter-spacing:.6px;margin-bottom:7px;">Not included</div>${C.excludes.map(e=>`<div class="excl-item"><span class="excl-x">✗</span>${e}</div>`).join('')}</div>`:''}
      </div>
    </div>

    <div class="card">
      <div class="card-head"><div class="card-icon">⚡</div><div class="card-title">How It Works</div></div>
      <div class="card-body">
        <div class="steps-list">${C.steps.map((s,i)=>`<div class="step-item"><div class="step-num">${i+1}</div><div><div class="step-title">${s.t}</div><div class="step-desc">${s.d}</div></div></div>`).join('')}</div>
      </div>
    </div>

    <div class="card">
      <div class="card-head"><div class="card-icon">📸</div><div class="card-title">Before & After</div></div>
      <div class="card-body">
        <div class="ba-grid">
          <div class="ba-card"><div class="ba-label before">Before</div><div class="ba-img">${C.before}</div></div>
          <div class="ba-card"><div class="ba-label after">After</div><div class="ba-img">${C.after}</div></div>
        </div>
        <p style="font-size:11px;color:var(--muted);margin-top:10px;text-align:center;">Results from verified Hamara Service professionals</p>
      </div>
    </div>

    <div class="card">
      <div class="card-head"><div class="card-icon">⭐</div><div class="card-title">Customer Ratings</div></div>
      <div class="card-body">
        <div class="rating-row">
          <div style="text-align:center;padding-right:16px;">
            <div class="rating-num">${C.rating}</div>
            <div class="rating-stars">★★★★★</div>
            <div class="rating-count">${C.ratingCount.toLocaleString()} reviews</div>
          </div>
          <div style="flex:1;">
            ${['5','4','3','2','1'].map((n,i)=>{var w=['72','18','6','2','2'][i];return`<div class="rbar-row">${n}★<div class="rbar-track"><div class="rbar-fill" style="width:${w}%"></div></div></div>`;}).join('')}
          </div>
        </div>
      </div>
    </div>

    <div class="card">
      <div class="card-head"><div class="card-icon">💬</div><div class="card-title">Frequently Asked Questions</div></div>
      <div class="card-body" style="padding-top:8px;">
        ${C.faqs.map((f,i)=>`<div class="faq-item" id="faq${i}"><div class="faq-q" onclick="toggleFaq(${i})">${f.q}<span class="faq-arr">›</span></div><div class="faq-a">${f.a}</div></div>`).join('')}
      </div>
    </div>

    ${C.subcategory&&C.subcategory.length?`
    <div class="card calc-card" id="priceCalcCard">
      <div class="card-head"><div class="card-icon">🧮</div><div class="card-title">Select Your Requirements & See Price</div></div>
      <div class="card-body" id="calcBody"></div>
    </div>`:''}

    ${C.similar&&C.similar.length?`
    <div class="card">
      <div class="card-head"><div class="card-icon">🔍</div><div class="card-title">Similar Services</div></div>
      <div class="card-body">
        <div class="sim-grid">
          ${C.similar.map(s=>`<a class="sim-card" href="${s.page}"><div class="sim-icon">${s.icon}</div><div class="sim-name">${s.name}</div><div class="sim-price">from ₹${s.price}</div></a>`).join('')}
        </div>
      </div>
    </div>`:''}

  </div>

  <div class="svc-right">
    <div class="book-card">
      <div class="book-top">
        <div class="book-price">₹${C.price} <sub>${C.unit}</sub></div>
        <div class="book-note">Inclusive of all charges</div>
      </div>
      <div class="book-meta">
        <div class="book-meta-row"><span class="book-meta-label">⏱ Duration</span><span class="book-meta-val">${C.duration}</span></div>
        <div class="book-meta-row"><span class="book-meta-label">👤 Team size</span><span class="book-meta-val">${C.teamSize||'1–2 professionals'}</span></div>
        <div class="book-meta-row"><span class="book-meta-label">⭐ Rating</span><span class="book-meta-val">${C.rating} (${C.ratingCount.toLocaleString()} reviews)</span></div>
        <div class="book-meta-row"><span class="book-meta-label">📦 Equipment</span><span class="book-meta-val">${C.equipment||'Provided by professional'}</span></div>
      </div>
      <button class="book-btn" id="mainBookBtn" onclick="mainBookNow()">Book Now →</button>
      <div class="book-trust">
        <div class="book-trust-item">✅ Background-verified professional</div>
        <div class="book-trust-item">🔒 Pay after service</div>
        <div class="book-trust-item">🔄 Free reschedule (2hrs notice)</div>
        <div class="book-trust-item">⭐ Satisfaction guaranteed</div>
      </div>
    </div>
  </div>
</div>

<div class="mob-book-bar">
  <div>
    <div class="mob-book-price">₹${C.price} <sub>${C.unit}</sub></div>
    <div style="font-size:11px;color:var(--muted);">⭐ ${C.rating} · ${C.ratingCount.toLocaleString()} reviews</div>
  </div>
  <button class="mob-book-btn" id="mobBookBtn" onclick="mainBookNow()">Book Now →</button>
</div>

<div class="sub-overlay" id="subOverlay" onclick="if(event.target===this)closeSubModal()">
  <div class="sub-modal">
    <div class="sub-modal-head">
      <div>
        <div style="font-size:11px;color:var(--muted);font-weight:600;">${C.cat}</div>
        <div class="sub-modal-title">${C.name}</div>
      </div>
      <button class="sub-modal-close" onclick="closeSubModal()">✕</button>
    </div>
    <div class="sub-modal-body" id="subModalBody"></div>
  </div>
</div>

<div class="toast" id="toast"></div>
`;

// ── Functions ────────────────────────────────────────────────────────────────

// ── INLINE PRICE CALCULATOR ─────────────────────────────────────────────────
var calcSel = {}; // {sectionId: label or [labels]}
var calcBasePrice = 0;
var calcExtraPrice = 0;

function renderPriceCalculator() {
  var body = document.getElementById('calcBody');
  if (!body || !C.subcategory || !C.subcategory.length) return;

  // Detect base price from first section (BHK size etc)
  calcBasePrice = C.price;
  calcExtraPrice = 0;
  calcSel = {};

  var html = '';
  C.subcategory.forEach(function(q) {
    html += '<div class="calc-section" id="calc-sec-' + q.id + '">';
    html += '<div class="calc-section-label">' + q.label.toUpperCase();
    if (q.req) html += ' <span class="calc-req">*</span>';
    html += '</div>';

    if (q.type === 'chips') {
      html += '<div class="calc-chips" id="calc-chips-' + q.id + '">';
      (q.opts || []).forEach(function(opt) {
        var label = typeof opt === 'object' ? opt.label : opt;
        var price = typeof opt === 'object' ? (opt.price || '') : '';
        html += '<div class="calc-chip" data-id="' + q.id + '" data-label="' + label.replace(/"/g,'&quot;') + '" data-price="' + price + '" data-single="' + (q.single?'1':'0') + '" onclick="calcChipClick(this)">'
          + '<span class="cc-name">' + label + '</span>'
          + (price ? '<span class="cc-price">' + price + '</span>' : '')
          + '</div>';
      });
      html += '</div>';
    }
    html += '</div>';
  });

  // Summary bar
  html += '<div class="calc-summary" id="calcSummary">'
    + '<div class="calc-sum-left">'
    + '<div class="calc-sum-label">Estimated Total</div>'
    + '<div class="calc-sum-price" id="calcTotalPrice">₹' + C.price + '</div>'
    + '<div class="calc-sum-note" id="calcPriceNote">Select home size to see price</div>'
    + '</div>'
    + '<button class="calc-book-btn" onclick="calcBookNow()">Book Now →</button>'
    + '</div>';

  body.innerHTML = html;

  // Hide the modal overlay since we use inline calc
  var overlay = document.getElementById('subOverlay');
  if (overlay) overlay.style.display = 'none';
}

function calcChipClick(el) {
  var id = el.dataset.id;
  var label = el.dataset.label;
  var single = el.dataset.single === '1';
  var container = document.getElementById('calc-chips-' + id);
  if (!container) return;

  if (single) {
    container.querySelectorAll('.calc-chip').forEach(function(c){ c.classList.remove('sel'); });
    el.classList.add('sel');
    calcSel[id] = label;
  } else {
    el.classList.toggle('sel');
    var sel = Array.from(container.querySelectorAll('.calc-chip.sel')).map(function(c){ return c.dataset.label; });
    calcSel[id] = sel;
  }
  calcUpdateTotal();
}

function calcUpdateTotal() {
  var total = 0;
  var notes = [];
  var baseSet = false;

  // First question = base price (BHK size)
  var firstQ = C.subcategory[0];
  if (firstQ && calcSel[firstQ.id]) {
    var selLabel = Array.isArray(calcSel[firstQ.id]) ? calcSel[firstQ.id][0] : calcSel[firstQ.id];
    var opt = (firstQ.opts || []).find(function(o){ return (typeof o==='object'?o.label:o) === selLabel; });
    if (opt && typeof opt === 'object' && opt.price) {
      // Parse price number from string like "₹999" or "₹1,499"
      var num = parseInt(opt.price.replace(/[^0-9]/g,''));
      if (!isNaN(num)) { total = num; baseSet = true; notes.push(selLabel); }
    }
  }

  if (!baseSet) total = C.price;

  // Remaining questions = add-ons / surcharges
  C.subcategory.slice(1).forEach(function(q) {
    var sel = calcSel[q.id];
    if (!sel || (Array.isArray(sel) && !sel.length)) return;
    var labels = Array.isArray(sel) ? sel : [sel];
    labels.forEach(function(label) {
      var opt = (q.opts || []).find(function(o){ return (typeof o==='object'?o.label:o) === label; });
      if (opt && typeof opt === 'object' && opt.price) {
        var p = opt.price;
        // Surcharge: starts with +
        if (p.startsWith('+')) {
          var num = parseInt(p.replace(/[^0-9]/g,''));
          if (!isNaN(num)) { total += num; notes.push(p + ' ' + label); }
        } else if (p !== 'Included' && p !== 'Standard rate') {
          // Fixed add-on price
          var num = parseInt(p.replace(/[^0-9]/g,''));
          if (!isNaN(num)) { total += num; notes.push(label + ' ' + p); }
        }
      }
    });
  });

  // Update display
  var priceEl = document.getElementById('calcTotalPrice');
  var noteEl  = document.getElementById('calcPriceNote');
  if (priceEl) priceEl.textContent = '₹' + total.toLocaleString('en-IN');
  if (noteEl)  noteEl.textContent  = notes.length ? notes.join(' + ') : 'Select home size to see price';

  // Sync with booking card price too
  var bp = document.querySelector('.book-price');
  var mp = document.querySelector('.mob-book-price');
  if (bp) bp.innerHTML = '₹' + total.toLocaleString('en-IN') + ' <sub>estimated</sub>';
  if (mp) mp.innerHTML = '₹' + total.toLocaleString('en-IN') + ' <sub>estimated</sub>';
}

function calcBookNow() {
  // Check required fields
  var missing = [];
  C.subcategory.forEach(function(q) {
    if (!q.req) return;
    var v = calcSel[q.id];
    if (!v || (Array.isArray(v) && !v.length)) missing.push(q.label);
  });
  if (missing.length) { showToast('⚠️ Please select: ' + missing[0]); return; }

  // Build summary lines for booking display
  var summary = [];
  Object.keys(calcSel).forEach(function(k){
    var val = calcSel[k];
    var q = C.subcategory.find(function(q){return q.id===k;});
    var label = q ? q.label : k;
    if(Array.isArray(val)){
      val.forEach(function(v){ summary.push(label+': '+v); });
    } else if(typeof val==='object'&&val!==null){
      summary.push(label+': '+(val.label||val.val||JSON.stringify(val)));
    } else {
      summary.push(label+': '+val);
    }
  });

  // Calculate current price from price bar
  var priceEl = document.getElementById('totalPrice') || document.querySelector('.pb-price');
  var totalPrice = priceEl ? priceEl.textContent.replace(/[^0-9]/g,'') : '';

  sessionStorage.setItem('hs_service_options', JSON.stringify({
    service:  C.name,
    icon:     C.icon   || '🔧',
    svcId:    C.id     || '',
    price:    totalPrice,
    summary:  summary,
    selections: calcSel,
    notes:    ''
  }));
  doBook();
}


// Single entry point for all Book Now buttons
window.mainBookNow = function() {
  if (C.subcategory && C.subcategory.length) {
    // Check required fields in inline calculator
    var missing = [];
    (C.subcategory || []).forEach(function(q) {
      if (!q.req) return;
      var v = calcSel[q.id];
      if (!v || (Array.isArray(v) && !v.length)) missing.push(q.label);
    });
    if (missing.length) {
      showToast('⚠️ Please select: ' + missing[0]);
      // Scroll to calculator
      var card = document.getElementById('priceCalcCard');
      if (card) card.scrollIntoView({behavior:'smooth', block:'center'});
      return;
    }
    var summary2 = [];
    Object.keys(calcSel).forEach(function(k){
      var val=calcSel[k]; var q=(C.subcategory||[]).find(function(q){return q.id===k;});
      var label=q?q.label:k;
      if(Array.isArray(val)){val.forEach(function(v){summary2.push(label+': '+v);});}
      else if(typeof val==='object'&&val!==null){summary2.push(label+': '+(val.label||val.val||JSON.stringify(val)));}
      else{summary2.push(label+': '+val);}
    });
    var pEl=document.getElementById('totalPrice')||document.querySelector('.pb-price');
    sessionStorage.setItem('hs_service_options', JSON.stringify({
      service: C.name, icon: C.icon||'🔧', svcId: C.id||'',
      price: pEl?pEl.textContent.replace(/[^0-9]/g,''):'',
      summary: summary2, selections: calcSel, notes: ''
    }));
    doBook();
  } else {
    openSubModal();
  }
};

window.toggleFaq = function(i) {
  var el = document.getElementById('faq'+i);
  if(el) el.classList.toggle('open');
};

var subSel = {};

window.openSubModal = function() {
  // If inline price calculator is shown, use that instead of modal
  if (C.subcategory && C.subcategory.length) {
    calcBookNow();
    return;
  }
  var qs = C.subcategory;
  subSel = {};
  var body = document.getElementById('subModalBody');
  if (!qs || !qs.length) { doBook(); return; }

  // Pre-fill stepper defaults
  qs.forEach(function(q){ if(q.type==='stepper') subSel[q.id]=q.default||q.min||1; });

  body.innerHTML = '';

  qs.forEach(function(q) {
    var sec = document.createElement('div');
    sec.className = 'sub-section';
    sec.id = 'qsec-'+q.id;
    if(q.showIf) sec.style.display='none';

    sec.innerHTML = '<div class="sub-label">'+q.label+(q.req?'<span class="sub-req">*</span>':'')+'</div>';

    if(q.type==='chips'){
      var div=document.createElement('div'); div.className='sub-chips';
      q.opts.forEach(function(opt){
        // opt can be a string or {label, price} object
        var label = typeof opt === 'object' ? opt.label : opt;
        var price = typeof opt === 'object' ? opt.price : null;

        var c = document.createElement('div');
        c.className = 'sub-chip';
        c.dataset.label = label;

        var labelEl = document.createElement('span');
        labelEl.className = 'chip-label';
        labelEl.textContent = label;
        c.appendChild(labelEl);

        if (price) {
          var priceEl = document.createElement('span');
          priceEl.className = 'chip-price';
          priceEl.textContent = price;
          c.appendChild(priceEl);
        }

        c.onclick = function(){
          if(q.single) div.querySelectorAll('.sub-chip').forEach(function(x){x.classList.remove('sel');});
          c.classList.toggle('sel');
          subSel[q.id] = q.single
            ? (div.querySelector('.sel') ? div.querySelector('.sel').dataset.label : null)
            : Array.from(div.querySelectorAll('.sel')).map(function(x){ return x.dataset.label; });
          refreshSummary(); checkConditionals();
        };
        div.appendChild(c);
      });
      sec.appendChild(div);

    } else if(q.type==='stepper'){
      var sd=document.createElement('div');
      sd.innerHTML='<div class="sub-stepper">'
        +'<button class="sub-step-btn" onclick="stepAdj(\''+q.id+'\','+(-(q.step||1))+','+q.min+','+q.max+')">−</button>'
        +'<div class="sub-step-val" id="sv-'+q.id+'">'+(q.default||q.min||1)+'</div>'
        +'<button class="sub-step-btn" onclick="stepAdj(\''+q.id+'\','+(q.step||1)+','+q.min+','+q.max+')">+</button>'
        +'</div><div style="font-size:11px;color:var(--muted);margin-top:5px;">'+(q.unit||'')+'</div>';
      sec.appendChild(sd);

    } else if(q.type==='select'){
      var sel=document.createElement('select'); sel.className='sub-select';
      q.opts.forEach(function(o){var op=document.createElement('option');op.value=o;op.textContent=o;sel.appendChild(op);});
      sel.onchange=function(){subSel[q.id]=sel.value;refreshSummary();};
      subSel[q.id]=q.opts[0];
      sec.appendChild(sel);

    } else if(q.type==='text'){
      var inp=document.createElement('input'); inp.className='sub-input'; inp.type='text'; inp.placeholder=q.placeholder||'';
      inp.oninput=function(){subSel[q.id]=inp.value;};
      sec.appendChild(inp);
    }

    body.appendChild(sec);
  });

  // Summary + Notes + Confirm
  body.insertAdjacentHTML('beforeend',
    '<div class="sub-summary" id="subSum"></div>'
    +'<div class="sub-section"><div class="sub-label">Additional notes (optional)</div>'
    +'<textarea class="sub-input" id="subNotes" rows="3" placeholder="Any specific instructions for the professional…" style="resize:none;"></textarea></div>'
    +'<button class="sub-confirm" onclick="confirmBook()">Confirm & Book Now →</button>'
  );

  document.getElementById('subOverlay').classList.add('open');
  document.body.style.overflow='hidden';
};

window.stepAdj = function(id,delta,min,max){
  subSel[id]=Math.max(min,Math.min(max,(subSel[id]||min)+delta));
  var el=document.getElementById('sv-'+id); if(el) el.textContent=subSel[id];
  refreshSummary();
};

function checkConditionals(){
  (C.subcategory||[]).forEach(function(q){
    if(!q.showIf) return;
    var sec=document.getElementById('qsec-'+q.id); if(!sec) return;
    var dep=subSel[q.showIf.id];
    var show=Array.isArray(dep)?dep.indexOf(q.showIf.val)>-1:dep===q.showIf.val;
    sec.style.display=show?'block':'none';
  });
}

function refreshSummary(){
  var s=document.getElementById('subSum'); if(!s) return;
  var parts=[];
  Object.keys(subSel).forEach(function(k){
    var v=subSel[k];
    if(!v||(Array.isArray(v)&&!v.length)) return;
    parts.push(Array.isArray(v)?v.join(', '):String(v));
  });
  if(parts.length){s.style.display='block';s.textContent='📋 '+parts.join(' · ');}
  else s.style.display='none';
}

window.closeSubModal = function(){
  document.getElementById('subOverlay').classList.remove('open');
  document.body.style.overflow='';
};

window.confirmBook = function(){
  var missing=[];
  (C.subcategory||[]).forEach(function(q){
    if(!q.req) return;
    var sec=document.getElementById('qsec-'+q.id);
    if(sec&&sec.style.display==='none') return;
    var v=subSel[q.id];
    if(!v||(Array.isArray(v)&&!v.length)) missing.push(q.label);
  });
  if(missing.length){ showToast('⚠️ Please fill: '+missing[0]); return; }
  var notes=document.getElementById('subNotes');
  // Build summary from subSel for booking display
  var subSummary = [];
  Object.keys(subSel).forEach(function(k) {
    var v = subSel[k];
    if (!v) return;
    var vStr = Array.isArray(v) ? v.join(', ') : String(v);
    if (vStr.trim()) subSummary.push(vStr);
  });
  var subPriceEl = document.getElementById('totalPrice') || document.querySelector('.pb-price');
  var subPrice = subPriceEl ? subPriceEl.textContent.replace(/[^0-9]/g,'') : '';
  sessionStorage.setItem('hs_service_options',JSON.stringify({
    service: C.name,
    icon: C.icon || '🔧',
    svcId: C.id || '',
    price: subPrice,
    summary: subSummary,
    selections: subSel,
    notes: notes ? notes.value : ''
  }));
  closeSubModal();
  doBook();
};

function doBook(){
  // Copy to localStorage so it survives login redirect
  localStorage.setItem('hs_service_options', sessionStorage.getItem('hs_service_options'));
  var user=JSON.parse(sessionStorage.getItem('hs_current_customer')||'null');
  var url='user-booking.html?service='+encodeURIComponent(C.name);
  if(user) window.location.href=url;
  else{ sessionStorage.setItem('hs_after_login',url); window.location.href='user-panel.html?tab=login'; }
}

window.showToast = function(msg){
  var t=document.getElementById('toast');
  t.textContent=msg; t.style.display='block';
  clearTimeout(t._t); t._t=setTimeout(function(){t.style.display='none';},3000);
};

// Render inline price calculator
renderPriceCalculator();

// Auth
try {
  firebase.auth().onAuthStateChanged(function(u){
    if(u) sessionStorage.setItem('hs_current_customer',JSON.stringify({uid:u.uid,name:u.displayName||'',email:u.email||''}));
    else sessionStorage.removeItem('hs_current_customer');
  });
} catch(e){}

})();

