// ── Live Pricing Listener ─────────────────────────────────────────────────────
// Include this in every service page (or add inline) to receive admin price changes.
// When admin saves a price, hs_pricing_ts changes → this fires → UI updates.
// Usage: add <script src="pricing-listener.js"></script> in each svc-*.html
// OR just paste the window.addEventListener block inline.

(function() {
  var HS_PRICING_KEY = 'hs_pricing_overrides';

  // Read a saved price override (returns null if not overridden)
  window.hsGetPrice = function(svcId, groupKey, itemIdx, defaultPrice) {
    try {
      var saved = JSON.parse(localStorage.getItem(HS_PRICING_KEY) || '{}');
      var k = svcId + '.' + groupKey + '.' + itemIdx;
      return saved[k] !== undefined ? saved[k] : defaultPrice;
    } catch(e) { return defaultPrice; }
  };

  window.hsGetBase = function(svcId, defaultBase) {
    try {
      var saved = JSON.parse(localStorage.getItem(HS_PRICING_KEY) || '{}');
      var k = svcId + '.base';
      return saved[k] !== undefined ? saved[k] : defaultBase;
    } catch(e) { return defaultBase; }
  };

  // Listen for admin price broadcasts
  window.addEventListener('storage', function(e) {
    if (e.key === 'hs_pricing_ts') {
      // Admin saved changes — reload prices if page has a refreshPricing function
      if (typeof window.refreshPricing === 'function') {
        window.refreshPricing();
      }
    }
  });
})();
