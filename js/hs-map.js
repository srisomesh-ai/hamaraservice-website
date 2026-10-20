/* HamaraService — map location picker (Leaflet + OpenStreetMap, no API key)
 *
 *   HS_MapPicker.open({
 *     lat, lng,                 // start position (0/undefined → device location or India centre)
 *     title: 'Pin your location',
 *     radiusKm: 5,              // optional: shows a range circle + slider (provider service range)
 *     minKm: 1, maxKm: 30,
 *     confirmText: 'Confirm location',
 *     onConfirm: function (r) { r.lat, r.lng, r.address, r.city, r.radiusKm }
 *   });
 *
 * The pin stays in the centre; the user pans/zooms the map under it.
 */
(function () {
  var LEAFLET_CSS = 'https://cdnjs.cloudflare.com/ajax/libs/leaflet/1.9.4/leaflet.min.css';
  var LEAFLET_JS  = 'https://cdnjs.cloudflare.com/ajax/libs/leaflet/1.9.4/leaflet.min.js';
  var loading = null;

  function loadLeaflet() {
    if (window.L && window.L.map) return Promise.resolve();
    if (loading) return loading;
    loading = new Promise(function (res, rej) {
      var css = document.createElement('link');
      css.rel = 'stylesheet'; css.href = LEAFLET_CSS;
      document.head.appendChild(css);
      var s = document.createElement('script');
      s.src = LEAFLET_JS; s.onload = function () { res(); };
      s.onerror = function () { loading = null; rej(new Error('Map could not load. Check your internet.')); };
      document.head.appendChild(s);
    });
    return loading;
  }

  function esc(v) {
    return String(v == null ? '' : v).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }

  var reverseTimer = null, reverseSeq = 0;
  function reverseGeocode(lat, lng, cb) {
    clearTimeout(reverseTimer);
    var seq = ++reverseSeq;
    reverseTimer = setTimeout(function () {
      fetch('https://nominatim.openstreetmap.org/reverse?format=json&zoom=18&addressdetails=1&lat=' + lat + '&lon=' + lng,
            { headers: { 'Accept-Language': 'en' } })
        .then(function (r) { return r.json(); })
        .then(function (d) {
          if (seq !== reverseSeq) return;
          var a = d.address || {};
          var city = a.city || a.town || a.village || a.suburb || a.county || '';
          var parts = [a.house_number, a.road, a.neighbourhood || a.suburb, city, a.state, a.postcode]
            .filter(function (x, i, arr) { return x && arr.indexOf(x) === i; });
          cb(parts.join(', ') || d.display_name || '', city);
        })
        .catch(function () { if (seq === reverseSeq) cb('', ''); });
    }, 450);
  }

  function open(opts) {
    opts = opts || {};
    var hasRange = typeof opts.radiusKm === 'number';
    var minKm = opts.minKm || 1, maxKm = opts.maxKm || 30;
    var radius = hasRange ? Math.min(maxKm, Math.max(minKm, opts.radiusKm || 5)) : 0;

    var ov = document.createElement('div');
    ov.id = 'hsMapPicker';
    ov.setAttribute('style', 'position:fixed;inset:0;z-index:9999;background:#fff;display:flex;flex-direction:column;font-family:inherit');
    ov.innerHTML =
      '<div style="display:flex;align-items:center;gap:10px;padding:12px 14px;background:#0f4c5c;color:#fff">' +
        '<span id="hsmClose" style="font-size:22px;cursor:pointer;padding:2px 6px">←</span>' +
        '<b style="flex:1;font-size:16px">' + esc(opts.title || 'Pin your location') + '</b></div>' +
      '<div style="position:relative;flex:1;min-height:200px">' +
        '<div id="hsmMap" style="position:absolute;inset:0;background:#e9eef0"></div>' +
        '<div style="position:absolute;left:50%;top:50%;transform:translate(-50%,-100%);z-index:500;font-size:38px;pointer-events:none;filter:drop-shadow(0 2px 2px rgba(0,0,0,.35))">📍</div>' +
        '<button id="hsmMe" type="button" style="position:absolute;right:12px;bottom:12px;z-index:500;border:0;border-radius:24px;padding:10px 14px;background:#fff;box-shadow:0 2px 8px rgba(0,0,0,.25);font-weight:700;cursor:pointer">📍 Use my current location</button>' +
        '<div id="hsmMsg" style="position:absolute;left:12px;right:12px;top:12px;z-index:500;display:none;background:#fff3cd;color:#7a5a00;padding:8px 10px;border-radius:10px;font-size:13px"></div>' +
      '</div>' +
      '<div style="padding:12px 14px 16px;box-shadow:0 -2px 10px rgba(0,0,0,.08)">' +
        '<div style="font-size:11px;font-weight:700;color:#6b7c80;text-transform:uppercase;letter-spacing:.4px">Selected location</div>' +
        '<div id="hsmAddr" style="font-size:14px;font-weight:600;margin:4px 0 10px;min-height:20px">Move the map to place the pin…</div>' +
        (hasRange
          ? '<div style="display:flex;justify-content:space-between;font-size:13px;font-weight:700;margin-bottom:4px"><span>Service range</span><span id="hsmKm">' + radius + ' km</span></div>' +
            '<input id="hsmRange" type="range" min="' + minKm + '" max="' + maxKm + '" step="1" value="' + radius + '" style="width:100%;margin-bottom:10px">'
          : '') +
        '<button id="hsmOk" type="button" style="width:100%;border:0;border-radius:12px;padding:14px;background:#f26b1d;color:#fff;font-size:15px;font-weight:800;cursor:pointer">' +
          esc(opts.confirmText || 'Confirm location') + '</button>' +
      '</div>';
    document.body.appendChild(ov);

    var state = { address: '', city: '' };
    var map = null, circle = null;
    function close() { if (map) map.remove(); ov.remove(); }
    function msg(t) { var m = ov.querySelector('#hsmMsg'); m.textContent = t || ''; m.style.display = t ? 'block' : 'none'; }
    ov.querySelector('#hsmClose').onclick = close;

    loadLeaflet().then(function () {
      var L = window.L;
      var start = (opts.lat && opts.lng) ? [opts.lat, opts.lng] : [20.5937, 78.9629];
      map = L.map(ov.querySelector('#hsmMap'), { zoomControl: true }).setView(start, (opts.lat && opts.lng) ? 16 : 5);
      L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        maxZoom: 19, attribution: '&copy; OpenStreetMap contributors'
      }).addTo(map);
      if (hasRange) {
        circle = L.circle(map.getCenter(), { radius: radius * 1000, color: '#0f4c5c', weight: 2, fillOpacity: 0.12 }).addTo(map);
      }
      function update() {
        var c = map.getCenter();
        if (circle) circle.setLatLng(c);
        ov.querySelector('#hsmAddr').textContent = 'Finding address…';
        reverseGeocode(c.lat, c.lng, function (addr, city) {
          state.address = addr; state.city = city;
          ov.querySelector('#hsmAddr').textContent = addr || (c.lat.toFixed(5) + ', ' + c.lng.toFixed(5));
        });
      }
      map.on('moveend', update);
      update();

      function fitRange() { if (circle) map.fitBounds(circle.getBounds(), { padding: [20, 20] }); }
      if (hasRange && opts.lat && opts.lng) setTimeout(fitRange, 50);
      var rg = ov.querySelector('#hsmRange');
      if (rg) rg.oninput = function () {
        radius = parseInt(rg.value, 10) || minKm;
        ov.querySelector('#hsmKm').textContent = radius + ' km';
        if (circle) circle.setRadius(radius * 1000);
      };
      if (rg) rg.onchange = fitRange;

      function locate() {
        if (!navigator.geolocation) { msg('Location not available on this device — move the map instead.'); return; }
        msg('Getting your location…');
        navigator.geolocation.getCurrentPosition(function (p) {
          msg('');
          map.setView([p.coords.latitude, p.coords.longitude], hasRange ? map.getZoom() : 17);
          if (hasRange) setTimeout(fitRange, 50);
        }, function () { msg('Location permission denied — move the map to your place.'); },
        { enableHighAccuracy: true, timeout: 10000 });
      }
      ov.querySelector('#hsmMe').onclick = locate;
      if (!(opts.lat && opts.lng)) locate();

      ov.querySelector('#hsmOk').onclick = function () {
        var c = map.getCenter();
        var r = { lat: +c.lat.toFixed(7), lng: +c.lng.toFixed(7), address: state.address, city: state.city };
        if (hasRange) r.radiusKm = radius;
        close();
        if (opts.onConfirm) opts.onConfirm(r);
      };
    }).catch(function (e) { msg(e.message); });
  }

  window.HS_MapPicker = { open: open };
})();
