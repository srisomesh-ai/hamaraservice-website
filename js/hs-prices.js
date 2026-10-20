/* HamaraService — admin reference prices for the public service pages.
 * Source: MySQL API (api/services.php?action=price_ranges → data[SVCxxx].admin_prices),
 * which is the same object the admin saves in hs-prices.html. Replaces the old
 * Firebase Realtime Database reads of hs_service_prices/SVCxxx.
 *
 *   HS_adminPrices('SVC011', function (prices) { ... })   // prices or null
 *   HS_priceRanges(function (all) { ... })                 // whole map or {}
 */
(function () {
  // Default prices from the admin price sheet (hs-prices.html), same shape as
  // the saved admin_prices. Used where admin hasn't saved a price yet, so the
  // service pages never wait forever on "Price loading…".
  var DEFAULTS = {"SVC001":{"base":129,"task":{},"sweep":{"studio":79,"1bhk":129,"2bhk":199,"3bhk":279,"4bhk":369,"villa":499},"dust":{"studio":99,"1bhk":149,"2bhk":249,"3bhk":349,"4bhk":449,"villa":599},"dishes":{"daily":99,"people":199,"event":399,"marriage":799}},"SVC002":{"base":799,"bhk":{"studio":599,"1bhk":799,"2bhk":1199,"3bhk":1599,"4bhk":1999,"rooms":399},"extras":{"mattress":249,"carpet":299,"sofashampoo":399,"balcony":149,"watertank":499,"pest":699},"condition":{"regular":0,"moderate":0,"dirty":150,"renovation":399}},"SVC003":{"base":299,"with":{"perBath":299},"addon":{"bathtub":149,"shower":99},"condition":{"normal":0,"moderate":0,"dirty":79,"mold":119}},"SVC004":{"base":149,"with":{"tiles":149,"cabinets":199,"chimney":149,"sink":99,"stove":199,"floor":99},"grease":{"light":0,"heavy":99}},"SVC005":{"base":299,"sofa":{"2seater":299,"3seater":399,"5seater":599,"recliner":199},"carpet":{"small":249,"medium":349,"large":499}},"SVC006":{"base":149,"wash":{"basePrice":149,"extraPer":12},"iron":{"basePrice":99,"extraPer":10},"dry":{"perVisit":79},"fold":{"perVisit":79},"handwash":{"perVisit":99}},"SVC007":{"base":299,"clean":{"split":299,"window":249,"cassette":399,"deepclean":499},"repair":{"visit":199,"gas":799,"compressor":299,"pcb":499}},"SVC009":{"base":199,"visit":{"visit":199},"appliance":{"washingmachine":0,"fridge":0,"microwave":0,"geyser":0,"dishwasher":99}},"SVC010":{"base":199,"visit":{"service":199,"filter":299,"install":499,"repair":249}},"SVC011":{"base":149,"visit":{"visit":149},"work":{"leakfix":0,"tapfix":0,"blockage":99,"toiletfix":0,"newfit":149,"motor":199}},"SVC012":{"base":149,"visit":{"visit":149},"work":{"switch":0,"fan":99,"light":0,"mcb":149,"wiring":199,"inverter":299}},"SVC013":{"base":199,"visit":{"visit":199},"work":{"doorfix":0,"furnifix":0,"lock":99,"new":299,"wardrobefix":149}},"SVC014":{"base":499,"rooms":{"pricePerRoom":499,"emulsion":699,"texture":999,"exterior":12},"condition":{"good":0,"moderate":99,"bad":199}},"SVC015":{"base":399,"visit":{"visit":399,"percam":199,"dvr":299,"repair":249}},"SVC016":{"base":199,"visit":{"upto4":199,"upto8":299,"upto12":399,"above12":499}},"SVC017":{"base":149,"car":{"exterior":149,"interior":199,"full":299,"premium":499,"engine":249},"bike":{"exterior":79,"full":129,"chain":79,"premium":249},"cartype":{"hatchback":0,"sedan":49,"suv":99,"largsuv":149,"luxury":249}},"SVC019":{"base":199,"visit":{"car":199,"bike":149},"service":{"oilchange":149,"brake":99,"tyrefit":79,"battery":99,"general":299}},"SVC021":{"base":599,"size":{"studio":399,"1bhk":599,"2bhk":799,"3bhk":999,"4bhk":1299,"villa":1599},"pest":{"cockroach":0,"mosquito":0,"termite":199,"rats":149,"bedbugs":299,"all":299}},"SVC022":{"base":249,"svc":{"breakfast":249,"lunch":299,"dinner":279,"twotime":499,"fullday":699,"party":1299,"tiffin":2499,"monthly":6999},"people":{"p2":0,"p4":79,"p6":149,"p7":249},"style":{"regular":0,"restaurant":99}},"SVC034":{"base":799,"type":{"daytime":799,"nighttime":899,"fullday":1499,"event":1099,"office":799,"bouncer_event":1099,"bouncer_pub":1299,"bouncer_vip":1599}},"SVC032":{"base":299,"visit":{"visit":299,"trimming":199,"planting":249,"lawn":349,"monthly":1499}},"SVC033":{"base":249,"car":{"local":249,"fullday":599,"outstation":899,"monthly":7999,"event":699},"auto":{"local":149,"fullday":399,"monthly":4999},"tempo":{"shifting":599,"local":399,"outstation":1099,"fullday":799},"truck":{"shifting":1099,"goods":899,"outstation":1599,"fullday":1299},"bus":{"event":1099,"staff":899,"outstation":1799,"fullday":1499},"tractor":{"farm":699,"goods":599,"fullday":899}},"SVC026":{"base":399,"session":{"single":399,"monthly":3999,"threemonth":9999,"yoga":299,"diet":499}},"SVC023":{"base":149,"svc":{"haircut":149,"fade":199,"layer":249,"kids":99,"shave":99,"beardtrim":79,"beardline":149,"bearddes":249,"colblack":299,"colfash":499,"highlights":699,"massage":149,"cleanup":199,"combo":599,"visit":49}},"SVC024":{"base":249,"cut":{"trim":249,"layer":349,"uvcut":399,"designer":499,"visit":49},"hair":{"blowdry":299,"globalcol":499,"highlights":699,"balayage":899,"roottouchup":349},"thread":{"eyebrow":49,"upperlip":29,"fullface":149},"wax":{"armswax":149,"legswax":199,"fullbodywax":599,"underarms":79},"facial":{"basic":349,"dtan":449,"fruit":599,"gold":799,"antiage":999},"nail":{"mani":299,"pedi":349,"combo":599},"makeup":{"party":1199,"bridal":1999,"hd":2999}},"SVC025":{"base":799,"types":{"swedish":799,"deeptissue":999,"ayurvedic":1099,"sports":999,"reflexology":799,"aromatherapy":899,"headneck":599,"backpain":899}},"SVC027":{"base":399,"visit":{"gp":399,"specialist":699,"pediatric":499,"senior":449}},"SVC028":{"base":299,"visit":{"basic":299,"injection":199,"dressing":199,"monitoring":399,"monthly":4999}},"SVC029":{"base":49,"visit":{"visit":49,"urgent":99}},"SVC030":{"base":399,"care":{"half":399,"fullday":699,"overnight":999,"event":499,"nanny":899,"monthly":14999},"extra":{"extrachild":199}},"SVC008":{"base":299,"visit":{"visit":199,"gas":799,"compressor":499,"pcb":399,"motor":299,"install":999}},"SVC018":{"base":79,"wash":{"exterior":79,"full":129,"premium":249,"chain":79},"type":{"scooter":0,"commuter":0,"sports":49}},"SVC020":{"base":149,"visit":{"visit":149},"service":{"oilchange":99,"brake":79,"puncture":49,"battery":79,"general":199,"tuning":149}},"SVC031":{"base":599,"care":{"companion":599,"personal":799,"fullday":1199,"nightcare":999,"hospital":899,"monthly":17999},"needs":{"dementia":149,"bedridden":249,"physio":249,"diabetes":79,"catheter":149}}};

  function withDefaults(id, saved) {
    var base = DEFAULTS[id] ? JSON.parse(JSON.stringify(DEFAULTS[id])) : null;
    if (!saved || typeof saved !== 'object') return base;
    if (!base) return saved;
    Object.keys(saved).forEach(function (k) {
      var v = saved[k];
      if (v && typeof v === 'object' && !Array.isArray(v) && base[k] && typeof base[k] === 'object') {
        Object.keys(v).forEach(function (k2) { base[k][k2] = v[k2]; });
      } else base[k] = v;
    });
    return base;
  }

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

  // Adds "👷 N providers · ₹min – ₹max" (approved providers' own price
  // ranges) to the service page's hero pills, once per page.
  function showProviderRange(row) {
    if (!row || !(row.provider_count > 0) || !(row.min > 0)) return;
    if (document.getElementById('hsProvRange')) return;
    var pills = document.querySelector('.hero-pills');
    if (!pills) return;
    var el = document.createElement('div');
    el.className = 'pill green';
    el.id = 'hsProvRange';
    el.textContent = '👷 ' + row.provider_count + ' provider' + (row.provider_count > 1 ? 's' : '') +
      ' · ₹' + row.min + (row.max > row.min ? ' – ₹' + row.max : '');
    pills.insertBefore(el, pills.firstChild);
  }

  window.HS_adminPrices = function (pathOrId, cb) {
    var m = String(pathOrId || '').match(/SVC\d+/);
    if (!m) { cb(null); return; }
    fetchRanges().then(function (all) {
      var row = all && all[m[0]];
      try { showProviderRange(row); } catch (e) {}
      var d = withDefaults(m[0], row && row.admin_prices);
      try { cb(d && typeof d === 'object' ? d : null); } catch (e) { console.warn(e); }
    });
  };
})();
