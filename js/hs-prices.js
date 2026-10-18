/* HamaraService — admin reference prices for the public service pages.
 * Source: MySQL API (api/services.php?action=price_ranges → data[SVCxxx].admin_prices),
 * which is the same object the admin saves in hs-prices.html. Replaces the old
 * Firebase Realtime Database reads of hs_service_prices/SVCxxx.
 *
 *   HS_adminPrices('SVC011', function (prices) { ... })   // prices or null
 *   HS_priceRanges(function (all) { ... })                 // whole map or {}
 */
(function () {
  var URL = '/api/services.php?action=price_ranges';
  var cache = null, cacheAt = 0, pending = null;

  function fetchRanges() {
    if (cache && Date.now() - cacheAt < 25000) return Promise.resolve(cache);
    if (pending) return pending;
    pending = fetch(URL, { headers: { 'Accept': 'application/json' } })
      .then(function (r) { return r.json(); })
      .then(function (res) {
        pending = null;
        if (res && res.success && res.data && typeof res.data === 'object') {
          cache = res.data; cacheAt = Date.now();
        }
        return cache || {};
      })
      .catch(function () { pending = null; return cache || {}; });
    return pending;
  }

  window.HS_priceRanges = function (cb) {
    fetchRanges().then(function (all) { try { cb(all || {}); } catch (e) { console.warn(e); } });
  };

  window.HS_adminPrices = function (pathOrId, cb) {
    var m = String(pathOrId || '').match(/SVC\d+/);
    if (!m) { cb(null); return; }
    fetchRanges().then(function (all) {
      var row = all && all[m[0]];
      var d = row && row.admin_prices;
      try { cb(d && typeof d === 'object' ? d : null); } catch (e) { console.warn(e); }
    });
  };
})();
