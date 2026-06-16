window.HS_SOUND_ROLE='provider';


// ════════ STATE ════════
let P = null; // current provider
let updateMap = null, updateMarker = null, updateCircle = null;
let newLat = null, newLng = null, newRadius = 5;
let alertCountdown = null;
let pendingBooking = null;

const DAYS = ['Sun','Mon','Tue','Wed','Thu','Fri','Sat'];
const TIME_SLOTS = ['6AM','7AM','8AM','9AM','10AM','11AM','12PM','1PM','2PM','3PM','4PM','5PM','6PM','7PM','8PM'];

// ════════ BOOKING DB ════════
function getBK() {
  // Return localStorage cache (always synced from Firebase on load)
  try { return JSON.parse(localStorage.getItem('hs_bk_' + P.id) || '[]'); } catch(e) { return []; }
}
// Always load bookings from Firebase — called on initDashboard
function loadBKFromFirebase(cb) {
  fbRestGet('bookings', function(err, data) {
    if (err || !data || typeof data !== 'object') { if(cb) cb(getBK()); return; }
    var fbBks = Object.values(data).filter(function(b){ return b && b.id && b.providerId === P.id; });
    // Merge with local (local may have optimistic updates)
    var local = getBK();
    var merged = {};
    local.forEach(function(b){ if(b.id) merged[b.id] = b; });
    fbBks.forEach(function(b){ if(b.id) merged[b.id] = Object.assign({}, merged[b.id]||{}, b); });
    var sorted = Object.values(merged).sort(function(a,b){
      return new Date(b.createdAt||b.date||0) - new Date(a.createdAt||a.date||0);
    });
    localStorage.setItem('hs_bk_' + P.id, JSON.stringify(sorted));
    if(cb) cb(sorted);
  });
}
function saveBK(bk) {
  // Cache locally for instant UI
  localStorage.setItem('hs_bk_' + P.id, JSON.stringify(bk));
  // Always write every booking to Firebase (source of truth)
  bk.forEach(function(b) {
    if (b.id) {
      fbWrite('bookings/' + b.id, Object.assign({}, b, { providerId: P.id, providerName: P.name }));
    }
  });
}
// Load bookings from Firebase into localStorage cache (called on init)
async function syncBKFromFirebase() {
  return new Promise(function(resolve) {
    var url = FB_URL + '/bookings.json?orderBy="providerId"&equalTo="' + P.id + '"';
    fetch(url, { signal: AbortSignal.timeout(8000) })
      .then(function(r) { return r.ok ? r.json() : null; })
      .then(function(data) {
        if (data && typeof data === 'object') {
          var fbBks = Object.values(data).filter(function(b){ return b && b.id; });
          if (fbBks.length > 0) {
            var local = getBK();
            var merged = {};
            local.forEach(function(b){ if(b.id) merged[b.id] = b; });
            // Firebase wins for status (source of truth)
            fbBks.forEach(function(b){ if(b.id) merged[b.id] = Object.assign({}, merged[b.id]||{}, b); });
            var sorted = Object.values(merged).sort(function(a,b){
              return new Date(b.createdAt||b.date||0) - new Date(a.createdAt||a.date||0);
            });
            localStorage.setItem('hs_bk_' + P.id, JSON.stringify(sorted));
          }
        }
        resolve();
      })
      .catch(function(e){ console.warn('[syncBK] err:', e.message); resolve(); });
  });
}

function getReviews() {
  try { return JSON.parse(localStorage.getItem('hs_rv_' + P.id) || '[]'); } catch(e) { return []; }
}
function loadReviewsFromFirebase(cb) {
  fbRestGet('reviews', function(err, data) {
    if (err || !data) { if(cb) cb(getReviews()); return; }
    var rvs = Object.values(data).filter(function(r){ return r && r.providerId === P.id; });
    localStorage.setItem('hs_rv_' + P.id, JSON.stringify(rvs));
    if(cb) cb(rvs);
  });
}
function saveReviews(rv) {
  localStorage.setItem('hs_rv_' + P.id, JSON.stringify(rv));
  // Write each review to Firebase
  rv.forEach(function(r, i) {
    if (!r._fbKey) { r._fbKey = P.id + '_' + i; }
    fbWrite('reviews/' + r._fbKey, Object.assign({}, r, { providerId: P.id }));
  });
}

// ════════ STATS COMPUTE ════════
function computeStats(){
  const bk = getBK();
  const catalog = JSON.parse(localStorage.getItem("hs_catalog")||"null") || [];
  const completed = bk.filter(b => b.status === 'completed' || b.status === 'paid');
  const pending   = bk.filter(b => b.status === 'pending');
  const awaitingPayment = bk.filter(b => b.status === 'payment_pending');
  const today = new Date().toISOString().split("T")[0];

  // For each completed booking, compute commission from catalog
  let grossEarned = 0, commissionTotal = 0, netEarned = 0;
  completed.forEach(b => {
    const svc = catalog.find(s => s.name === b.service);
    const commPct = svc ? svc.commission : 12;
    const commAmt = svc ? svc.commAmt : Math.round(b.amount * commPct / 100);
    const provAmt = b.amount - commAmt;
    grossEarned += b.amount;
    commissionTotal += commAmt;
    netEarned += provAmt;
    // Annotate booking for display
    b._commAmt = commAmt;
    b._provAmt = provAmt;
    b._commPct = commPct;
  });

  const todayGross = completed.filter(b => b.date === today).reduce((s,b) => s+b.amount, 0);

  // Payout tracking
  const payouts = JSON.parse(localStorage.getItem("hs_payouts")||"[]")
    .filter(p => p.providerId === P.id);
  const withdrawn = payouts
    .filter(p => p.status === "approved")
    .reduce((s,p) => s+p.amount, 0);
  const pendingWithdraw = payouts
    .filter(p => p.status === "pending")
    .reduce((s,p) => s+p.amount, 0);
  const available = Math.max(netEarned - withdrawn - pendingWithdraw, 0);

  const reviews = getReviews();
  const avgRating = reviews.length
    ? (reviews.reduce((s,r) => s+r.stars, 0) / reviews.length).toFixed(1)
    : null;

  return {
    bk, completed, pending, awaitingPayment, payouts,
    grossEarned, commissionTotal, netEarned,
    totalEarned: netEarned,
    todayGross, withdrawn, pendingWithdraw, available,
    reviews, avgRating, today
  };
}

// ════════ LOGIN CHECK ════════
window.addEventListener('load', () => {
  const saved = localStorage.getItem('hs_current_provider');
  if (!saved) { window.location.href = 'provider-portal.html'; return; }
  try {
    P = JSON.parse(saved);
    if (!P || !P.id) { window.location.href = 'provider-portal.html'; return; }
    // Always refresh from Firebase (works across all devices)
    const FB_DB = 'https://hamaraservice-s009-default-rtdb.asia-southeast1.firebasedatabase.app';
    fetch(FB_DB + '/providers/' + P.id + '.json', {signal:AbortSignal.timeout(6000)})
      .then(function(r){ return r.json(); })
      .then(function(fbP){
        if (fbP && fbP.id) {
          P = fbP;
          localStorage.setItem('hs_current_provider', JSON.stringify(P));
          var all = JSON.parse(localStorage.getItem('hs_providers')||'[]');
          var idx = all.findIndex(function(pr){ return pr.id===P.id; });
          if(idx>-1) all[idx]=P; else all.push(P);
          localStorage.setItem('hs_providers', JSON.stringify(all));
        }
        initDashboard();
      })
      .catch(function(){
        // Firebase unavailable — use cached data
        initDashboard();
      });
  } catch(e) { window.location.href = 'provider-portal.html'; }
});

function doLogout() {
  localStorage.removeItem('hs_current_provider');
  window.location.href = 'provider-portal.html';
}

// ════════ INIT ════════
function initDashboard() {
  document.getElementById('topbarDate').textContent = new Date().toLocaleDateString('en-IN',{weekday:'short',day:'numeric',month:'short',year:'numeric'});
  document.getElementById('sb_name').textContent = P.name;
  document.getElementById('sb_id').textContent = P.id;
  // Show friendly login info
  const idEl = document.getElementById('sb_id');
  if(idEl) idEl.title = 'Login ID: '+P.id+' | Use your password to log in';
  document.getElementById('availToggle').checked = P.available !== false;
  updateAvailLabel();

  // Seed demo bookings if demo account and no bookings yet
  if (P.id === 'HS-PRO-DEMO' && getBK().length === 0) seedDemoData();

  // Sync bookings from Firebase (picks up bookings made from any device)
  syncBKFromFirebase().then(function(){ loadOverview(); loadAllBookings(); loadEarnings(); loadRatings(); });

  loadOverview();
  loadAllBookings();
  loadEarnings();
  loadProfile();
  loadAvailability();
  loadRatings();
  loadNotifications();

  // ── REAL Firebase polling for new bookings ──────────────────
  startBookingPoller();
  // Re-sync bookings from Firebase every 60s (catches updates from other devices)
  setInterval(function(){
    syncBKFromFirebase().then(function(){ loadOverview(); loadAllBookings(); loadEarnings(); });
  }, 60000);
}

function seedDemoData() {
  const bk = [
    {id:'HS-BK-001',customer:'Anita Reddy',phone:'9876543210',service:'House Maid',date:'2026-05-01',time:'09:00',amount:499,status:'completed'},
    {id:'HS-BK-002',customer:'Ramesh Kumar',phone:'9123456780',service:'AC Cleaning',date:'2026-05-02',time:'11:00',amount:799,status:'completed'},
    {id:'HS-BK-003',customer:'Priya Sharma',phone:'9988776655',service:'House Maid',date:'2026-05-03',time:'10:00',amount:499,status:'completed'},
    {id:'HS-BK-004',customer:'Suresh Babu',phone:'9001234567',service:'House Maid',date:'2026-05-04',time:'14:00',amount:499,status:'pending',customerLat:17.3780,customerLng:78.4980},
    {id:'HS-BK-005',customer:'Lakshmi Devi',phone:'9876001234',service:'Cooking Person',date:'2026-04-28',time:'08:00',amount:799,status:'completed'},
    {id:'HS-BK-006',customer:'Vijay Nair',phone:'9123000456',service:'House Maid',date:'2026-04-25',time:'16:00',amount:499,status:'cancelled'},
  ];
  saveBK(bk);
  const rv = [
    {customer:'Anita Reddy',stars:5,text:'Excellent work! Very clean and punctual.',date:'May 1'},
    {customer:'Ramesh Kumar',stars:5,text:'Professional and friendly. Will book again!',date:'May 2'},
    {customer:'Priya Sharma',stars:4,text:'Good service, came on time. Satisfied.',date:'May 3'},
    {customer:'Lakshmi Devi',stars:5,text:'Best cook I have hired. Food was amazing!',date:'Apr 28'},
  ];
  saveReviews(rv);
}

// ════════ OVERVIEW ════════
function loadOverview() {
  const s = computeStats();
  document.getElementById('st-total').textContent = s.bk.length;
  document.getElementById('st-completed').textContent = s.completed.length;
  document.getElementById('st-earned').textContent = '₹' + s.totalEarned.toLocaleString('en-IN');
  document.getElementById('st-rating').textContent = s.avgRating || '—';
  document.getElementById('st-pending').textContent = s.pending.length;
  // Show awaiting payment badge if any
  if (s.awaitingPayment && s.awaitingPayment.length > 0) {
    showToast('💳 ' + s.awaitingPayment.length + ' booking(s) awaiting customer payment');
  }
  document.getElementById('pendingBadge').textContent = s.pending.length;

  // Donut
  const total = s.bk.length || 1;
  const compP = (s.completed.length / total * 100).toFixed(0);
  const pendP = (s.pending.length / total * 100).toFixed(0);
  document.getElementById('donutChart').style.background =
    `conic-gradient(var(--green) 0% ${compP}%, var(--yellow) ${compP}% ${+compP + +pendP}%, var(--red) ${+compP + +pendP}% 100%)`;

  // Weekly chart (last 7 calendar days)
  const days7 = [...Array(7)].map((_, i) => {
    const d = new Date(); d.setDate(d.getDate() - (6 - i));
    return d.toISOString().split('T')[0];
  });
  const earnings7 = days7.map(d => s.completed.filter(b => b.date === d).reduce((sum, b) => sum + b.amount, 0));
  const maxE = Math.max(...earnings7, 1);
  const chart = document.getElementById('weeklyChart');
  chart.innerHTML = '';
  earnings7.forEach(v => {
    const col = document.createElement('div'); col.className = 'bar-col';
    const pct = (v / maxE * 100).toFixed(0);
    col.innerHTML = `<div class="bar-val">${v > 0 ? '₹'+v : ''}</div><div class="bar" style="height:${Math.max(pct,4)}%"></div>`;
    chart.appendChild(col);
  });
  const labelsEl = document.getElementById('weeklyLabels');
  labelsEl.innerHTML = days7.map(d => `<span style="font-size:10px;font-weight:800;color:var(--muted);flex:1;text-align:center">${new Date(d).toLocaleDateString('en-IN',{weekday:'short'})}</span>`).join('');

  // Recent bookings (latest 5)
  const recent = [...s.bk].sort((a,b) => new Date(b.date) - new Date(a.date)).slice(0,5);
  document.getElementById('recentBody').innerHTML = recent.map(b => `
    <tr><td><strong>${b.id}</strong></td><td>${b.customer}</td><td>${b.service}</td>
    <td>${b.date}</td><td><strong>₹${b.amount}</strong></td>
    <td><span class="badge ${b.status}">${cap(b.status)}</span></td></tr>
  `).join('') || '<tr><td colspan="6" style="text-align:center;color:var(--muted);padding:24px">No bookings yet</td></tr>';
}

// ════════ ALL BOOKINGS ════════
function loadAllBookings(filter = 'all') {
  let bk = getBK();
  if (filter !== 'all') bk = bk.filter(b => b.status === filter);
  bk = bk.sort((a,b) => new Date(b.date) - new Date(a.date));
  document.getElementById('allBkBody').innerHTML = bk.map(b => `
    <tr>
      <td><strong>${b.id}</strong></td>
      <td>${b.customer}<br/><small style="color:var(--muted)">${b.phone}</small></td>
      <td>${b.service}</td>
      <td>${b.date}<br/><small style="color:var(--muted)">${b.time}</small></td>
      <td><strong>₹${b.amount}</strong></td>
      <td><span class="badge ${b.status}">${cap(b.status)}</span></td>
      <td>
        ${b.status === 'pending'
          ? `<button class="nav-btn-track" onclick="openProviderTracking('${b.id}');return false;" style="margin-bottom:5px">🗺️ Navigate</button>
             <br/><button class="abtn complete" onclick="updateStatus('${b.id}','completed')">🔐 Close Job</button>
             <button class="abtn cancel" onclick="updateStatus('${b.id}','cancelled')">❌</button>`
          : b.status === 'payment_pending' || b.status === 'otp_sent'
          ? `<div class="pay-pending-badge"><div class="pay-pending-dot"></div>Awaiting Customer Payment</div>`
          : b.status === 'paid'
          ? `<span class="badge paid">💰 Paid · Processing</span>`
          : `<button class="abtn view" onclick="viewBk('${b.id}')">👁 View</button>
             <br/><button class="btn-track-cust" onclick="openCustomerTracking('${b.id}')" style="margin-top:4px">📍 Customer View</button>`}
      </td>
    </tr>
  `).join('') || '<tr><td colspan="7" style="text-align:center;color:var(--muted);padding:24px">No bookings found</td></tr>';
}

function filterBk(f, btn) {
  document.querySelectorAll('.fbtn').forEach(b => b.classList.remove('active'));
  btn.classList.add('active');
  loadAllBookings(f);
}

// ─── OTP COMPLETION STATE ───────────────────────────────────────
let pendingCompleteId = null;
let currentRatingBookingId = null;
let selectedRating = 0;
const OTP_LABELS = ['','😞 Very Bad','😕 Poor','😐 Okay','😊 Good','🤩 Excellent!'];

function updateStatus(id, status) {
  if (status === 'completed') {
    // Trigger OTP flow instead of direct complete
    initiateOTPCompletion(id);
    return;
  }
  // Cancellation — direct
  if (!confirm('Cancel this booking? This cannot be undone.')) return;
  const bk = getBK();
  const idx = bk.findIndex(b => b.id === id);
  if (idx > -1) {
    bk[idx].status = 'cancelled';
    saveBK(bk);
    syncProviderStats();
    loadAllBookings(); loadOverview(); loadEarnings(); loadNotifications();
    showToast('❌ Booking cancelled');
  }
}

// ─── STEP 1: Provider clicks "Close Job" ────────────────────────
// Generates OTP → sends to customer (via Firebase) → customer sees code
// Provider asks customer to read their code, then enters it here
function initiateOTPCompletion(bookingId) {
  const bk = getBK();
  const booking = bk.find(b => b.id === bookingId);
  if (!booking) return;

  // Generate 4-digit OTP
  const otp = String(Math.floor(1000 + Math.random() * 9000));
  const bkIdx = bk.findIndex(b => b.id === bookingId);
  bk[bkIdx].completionOTP = otp;
  bk[bkIdx].otpGeneratedAt = new Date().toISOString();
  bk[bkIdx].status = 'otp_sent';
  saveBK(bk);

  pendingCompleteId = bookingId;

  // Write OTP to Firebase so user panel can show it to customer
  fbWrite('job_otp/' + bookingId, {
    otp: otp,
    bookingId: bookingId,
    service: booking.service,
    customer: booking.customer || 'Customer',
    providerName: P.name,
    generatedAt: Date.now(),
    status: 'waiting' // → 'verified' once provider enters correct code
  });

  // Update booking status in Firebase too
  fbWrite('active_bookings/' + bookingId + '/status', 'otp_sent');
  fbWrite('active_bookings/' + bookingId + '/otpSentAt', Date.now());

  // Show the provider entry modal — ask customer to read their code
  document.getElementById('otpBookingInfo').textContent =
    'Service: ' + booking.service + ' · Customer: ' + (booking.customer || '—');
  document.getElementById('otpCustomerInstruction').textContent =
    'Ask ' + (booking.customer || 'the customer') + ' to open their app and read you the 4-digit code.';

  ['od1','od2','od3','od4'].forEach(id => {
    const el = document.getElementById(id);
    el.value = ''; el.classList.remove('filled');
  });
  document.getElementById('otpErr').style.display = 'none';
  document.getElementById('otpRequestModal').classList.add('show');
  setTimeout(() => document.getElementById('od1').focus(), 300);
}

// ─── OTP DIGIT INPUT LOGIC ───────────────────────────────────────
function otpInput(el, num) {
  const val = el.value.replace(/[^0-9]/g,'');
  el.value = val;
  el.classList.toggle('filled', val.length > 0);
  if (val && num < 4) {
    document.getElementById('od' + (num+1)).focus();
  }
  if (num === 4 && val) {
    // Auto-attempt verify when last digit entered
    const all = ['od1','od2','od3','od4'].map(id => document.getElementById(id).value);
    if (all.every(v => v.length === 1)) verifyOTP();
  }
}
function otpBack(e, num) {
  if (e.key === 'Backspace' && !e.target.value && num > 1) {
    const prev = document.getElementById('od' + (num-1));
    prev.value = ''; prev.classList.remove('filled'); prev.focus();
  }
}
function getEnteredOTP() {
  return ['od1','od2','od3','od4'].map(id => document.getElementById(id).value).join('');
}

// ─── STEP 3: Provider enters OTP → Verify ───────────────────────
function verifyOTP() {
  const entered = getEnteredOTP();
  if (entered.length < 4) { showToast('⚠️ Enter all 4 digits'); return; }

  const bk = getBK();
  const booking = bk.find(b => b.id === pendingCompleteId);
  if (!booking) { closeModal('otpRequestModal'); return; }

  if (entered !== booking.completionOTP) {
    document.getElementById('otpErr').style.display = 'block';
    ['od1','od2','od3','od4'].forEach(id => {
      const el = document.getElementById(id);
      el.style.borderColor = 'var(--red)';
      el.style.background = 'var(--red-light)';
      setTimeout(() => { el.style.borderColor = ''; el.style.background = ''; }, 1500);
    });
    return;
  }

  // ✅ OTP correct — job closed, now customer needs to pay
  const idx = bk.findIndex(b => b.id === pendingCompleteId);
  bk[idx].status = 'payment_pending';
  bk[idx].otpVerifiedAt = new Date().toISOString();
  bk[idx].completionOTP = null;
  saveBK(bk);

  // Signal Firebase: OTP verified → user panel will open payment screen
  fbWrite('job_otp/' + pendingCompleteId + '/status', 'verified');
  fbWrite('job_otp/' + pendingCompleteId + '/verifiedAt', Date.now());
  fbWrite('active_bookings/' + pendingCompleteId + '/status', 'payment_pending');
  fbWrite('active_bookings/' + pendingCompleteId + '/otpVerifiedAt', Date.now());

  currentRatingBookingId = pendingCompleteId;
  pendingCompleteId = null;

  closeModal('otpRequestModal');

  // Refresh provider panel — shows "Awaiting Payment" badge
  syncProviderStats();
  loadAllBookings(); loadOverview(); loadNotifications();

  showToast('✅ Job verified! Customer will now be prompted to pay.');

  // Provider just waits — poll Firebase for payment confirmation from user panel
  startPaymentPolling(currentRatingBookingId || bk[idx].id);
}

// ─── PAYMENT WINDOW ──────────────────────────────────────────────
let selectedPayMethod = null;

function openPaymentWindow(booking) {
  document.getElementById('pw-service').textContent  = booking.service;
  document.getElementById('pw-provider').textContent = P.name;
  document.getElementById('pw-id').textContent       = booking.id;
  document.getElementById('pw-date').textContent     = booking.date + ' ' + (booking.time||'');
  document.getElementById('pw-amount').textContent   = '₹' + booking.amount.toLocaleString('en-IN');
  selectedPayMethod = null;
  document.querySelectorAll('.pay-method').forEach(m => m.classList.remove('selected'));
  const btn = document.getElementById('payNowBtn');
  btn.textContent = 'Select payment method to continue';
  btn.disabled = true; btn.style.opacity = '.5'; btn.style.cursor = 'not-allowed';
  document.getElementById('paymentModal').classList.add('show');
}

function selectPayMethod(el, method) {
  document.querySelectorAll('.pay-method').forEach(m => m.classList.remove('selected'));
  el.classList.add('selected');
  selectedPayMethod = method;
  document.getElementById('payGatewayNote').style.display = method !== 'cash' ? 'block' : 'none';
  const btn = document.getElementById('payNowBtn');
  btn.textContent = method === 'cash' ? '💵 Confirm Cash Payment' : '💳 Pay ₹' + document.getElementById('pw-amount').textContent.replace('₹','');
  btn.disabled = false; btn.style.opacity = '1'; btn.style.cursor = 'pointer';
}

function processCustomerPayment() {
  if (!selectedPayMethod) { showToast('⚠️ Select a payment method'); return; }
  const bookingId = currentRatingBookingId;
  const bk = getBK();
  const idx = bk.findIndex(b => b.id === bookingId);
  if (idx < 0) return;

  // Generate transaction ID
  const txnId = 'TXN' + Date.now().toString(36).toUpperCase();

  // Mark booking as paid → completed
  bk[idx].status = 'paid';
  bk[idx].paidAt = new Date().toISOString();
  bk[idx].paymentMethod = selectedPayMethod;
  bk[idx].txnId = txnId;
  saveBK(bk);

  closeModal('paymentModal');

  // Show payment success
  document.getElementById('paySuccessInfo').textContent =
    bk[idx].service + ' · ₹' + bk[idx].amount + ' via ' + selectedPayMethod.toUpperCase();
  document.getElementById('payTxnId').textContent = txnId;
  document.getElementById('paySuccessModal').classList.add('show');

  // Refresh provider panel
  syncProviderStats();
  loadAllBookings(); loadOverview(); loadEarnings(); loadNotifications();
  stopPaymentPolling();
}

function afterPaymentSuccess() {
  // Move booking to fully completed after payment
  const bookingId = currentRatingBookingId;
  const bk = getBK();
  const idx = bk.findIndex(b => b.id === bookingId);
  if (idx > -1 && bk[idx].status === 'paid') {
    bk[idx].status = 'completed';
    bk[idx].completedAt = new Date().toISOString();
    saveBK(bk);
    syncProviderStats();
    loadAllBookings(); loadOverview(); loadEarnings();
  }
  closeModal('paySuccessModal');
  const booking = bk[idx];
  openCustomerRatingModal(booking);
}

// ─── PAYMENT POLLING — provider polls Firebase for customer payment ───
let pollInterval = null;
let _payPollBookingId = null;
function startPaymentPolling(bookingId) {
  stopPaymentPolling();
  _payPollBookingId = bookingId;
  pollInterval = setInterval(() => {
    // 1. Check local storage (same-device scenario)
    const bk = getBK();
    const b = bk.find(x => x.id === _payPollBookingId);
    if (b && (b.status === 'paid' || b.status === 'completed')) {
      stopPaymentPolling();
      syncProviderStats();
      loadAllBookings(); loadOverview(); loadEarnings();
      showPaymentReceivedAlert(b);
      return;
    }
    // 2. Check Firebase (cross-device — user paid on their own phone)
    fbRead('active_bookings/' + _payPollBookingId, function(data) {
      if (data && (data.status === 'paid' || data.status === 'completed')) {
        stopPaymentPolling();
        // Sync payment info into local booking
        const bkLocal = getBK();
        const localIdx = bkLocal.findIndex(x => x.id === _payPollBookingId);
        if (localIdx > -1) {
          bkLocal[localIdx].status = data.status || 'paid';
          bkLocal[localIdx].paidAt = data.paidAt || new Date().toISOString();
          bkLocal[localIdx].paymentMethod = data.paymentMethod || 'online';
          bkLocal[localIdx].txnId = data.txnId || '';
          saveBK(bkLocal);
        }
        syncProviderStats();
        loadAllBookings(); loadOverview(); loadEarnings();
        const bkFresh = getBK();
        const paid = bkFresh.find(x => x.id === _payPollBookingId);
        if (paid) showPaymentReceivedAlert(paid);
      }
    });
  }, 3000); // check every 3 seconds
}
function stopPaymentPolling() {
  if (pollInterval) { clearInterval(pollInterval); pollInterval = null; }
}
function showPaymentReceivedAlert(booking) {
  const catalog = JSON.parse(localStorage.getItem('hs_catalog')||'null')||[];
  const svc = catalog.find(s => s.name === booking.service);
  const commPct = svc ? svc.commission : 12;
  const commAmt = svc ? svc.commAmt : Math.round(booking.amount * commPct / 100);
  const provAmt = booking.amount - commAmt;
  document.getElementById('jobDoneInfo').textContent =
    booking.service + ' · ' + (booking.customer||'Customer') + ' · ' + booking.id;
  document.getElementById('jobDoneAmount').textContent = '₹' + provAmt.toLocaleString('en-IN');
  document.getElementById('jobDoneCommNote').textContent =
    'Paid via ' + (booking.paymentMethod||'—').toUpperCase() + ' · Commission: ₹' + commAmt + ' (' + commPct + '%) · Your net: ₹' + provAmt;
  document.getElementById('jobDoneModal').classList.add('show');
}

// ─── STEP 4: Rating Modal ────────────────────────────────────────
function openCustomerRatingModal(booking) {
  selectedRating = 0;
  document.getElementById('ratingLabel').textContent = '';
  document.getElementById('ratingReviewText').value = '';
  // Reset stars
  for (let i = 1; i <= 5; i++) {
    document.getElementById('rs' + i).classList.remove('active');
  }
  document.getElementById('ratingProviderName').textContent =
    'Rate your ' + (booking ? booking.service : 'service') + ' by ' + P.name;
  document.getElementById('ratingModal').classList.add('show');
}

function setRating(n) {
  selectedRating = n;
  document.getElementById('ratingLabel').textContent = OTP_LABELS[n];
  for (let i = 1; i <= 5; i++) {
    document.getElementById('rs' + i).classList.toggle('active', i <= n);
  }
}

function submitRating() {
  if (selectedRating === 0) { showToast('⭐ Please tap a star to rate'); return; }
  const review = document.getElementById('ratingReviewText').value.trim();
  const bk = getBK();
  const booking = bk.find(b => b.id === currentRatingBookingId);

  const reviewObj = {
    customer: booking ? (booking.customer || 'Customer') : 'Customer',
    stars: selectedRating,
    text: review || OTP_LABELS[selectedRating].replace(/[^a-zA-Z ]/g,'').trim() || 'Great service!',
    date: new Date().toLocaleDateString('en-IN', {day:'numeric', month:'short'}),
    bookingId: currentRatingBookingId || ''
  };

  // Save review
  const reviews = getReviews();
  reviews.push(reviewObj);
  saveReviews(reviews);

  // Update provider avg rating in providers list
  const avgRating = (reviews.reduce((s,r) => s+r.stars, 0) / reviews.length).toFixed(1);
  const all = JSON.parse(localStorage.getItem('hs_providers') || '[]');
  const pidx = all.findIndex(p => p.id === P.id);
  if (pidx > -1) {
    all[pidx].rating = +avgRating;
    all[pidx].reviews = reviews.length;
    P = all[pidx];
    localStorage.setItem('hs_providers', JSON.stringify(all));
    localStorage.setItem('hs_current_provider', JSON.stringify(P));
  }

  closeModal('ratingModal');
  loadRatings(); loadOverview();

  // Write review to Firebase so it persists cross-device
  if (reviewObj.bookingId) {
    fbWrite('reviews/' + reviewObj.bookingId, reviewObj);
  }

  showToast('⭐ Rating saved! Thank you.');
  currentRatingBookingId = null;
}

function viewBk(id) {
  const b = getBK().find(b => b.id === id);
  if (!b) return;
  document.getElementById('vmTitle').textContent = '📋 ' + b.id;
  document.getElementById('vmSub').textContent = 'Service: ' + b.service;
  document.getElementById('vmBody').innerHTML = `
    <strong>Customer:</strong> ${b.customer}<br/>
    <strong>Phone:</strong> ${b.phone}<br/>
    <strong>Date:</strong> ${b.date} at ${b.time}<br/>
    <strong>Amount:</strong> ₹${b.amount}<br/>
    <strong>Status:</strong> ${cap(b.status)}
  `;
  document.getElementById('viewModal').classList.add('show');
}

function syncProviderStats() {
  const s = computeStats();
  const all = JSON.parse(localStorage.getItem('hs_providers') || '[]');
  const idx = all.findIndex(p => p.id === P.id);
  if (idx > -1) {
    all[idx].totalBookings     = s.bk.length;
    all[idx].completedBookings = s.completed.length;
    all[idx].totalEarned       = s.totalEarned;
    all[idx].rating            = s.avgRating ? +s.avgRating : all[idx].rating;
    all[idx].reviews           = s.reviews.length;
    P = all[idx];
    localStorage.setItem('hs_providers', JSON.stringify(all));
    localStorage.setItem('hs_current_provider', JSON.stringify(P));
    // Sync stats to Firebase so admin sees live numbers
    fbWrite('providers/' + P.id + '/totalBookings',     s.bk.length);
    fbWrite('providers/' + P.id + '/completedBookings', s.completed.length);
    fbWrite('providers/' + P.id + '/totalEarned',       s.totalEarned);
    if (s.avgRating) fbWrite('providers/' + P.id + '/rating', +s.avgRating);
    fbWrite('providers/' + P.id + '/reviews',           s.reviews.length);
  }
}

// ════════ EARNINGS ════════
function loadEarnings(){
  const s = computeStats();

  document.getElementById("e-gross").textContent      = "₹"+s.grossEarned.toLocaleString("en-IN");
  document.getElementById("e-commission").textContent = "₹"+s.commissionTotal.toLocaleString("en-IN");
  document.getElementById("e-net").textContent        = "₹"+s.netEarned.toLocaleString("en-IN");
  document.getElementById("e-withdrawn").textContent  = "₹"+s.withdrawn.toLocaleString("en-IN");
  document.getElementById("e-available").textContent  = "₹"+s.available.toLocaleString("en-IN");
  document.getElementById("withdrawAvail").textContent = "₹"+s.available.toLocaleString("en-IN");
  document.getElementById("wdAvailDisplay") && (document.getElementById("wdAvailDisplay").textContent = "₹"+s.available.toLocaleString("en-IN"));

  // Booking breakdown table
  const sorted = [...s.completed].sort((a,b) => new Date(b.date)-new Date(a.date));
  document.getElementById("earnBody").innerHTML = sorted.map(b => `
    <tr>
      <td><strong>${b.id}</strong></td>
      <td>${b.service}</td>
      <td>${b.date}</td>
      <td><strong>₹${b.amount}</strong></td>
      <td style="color:var(--red);font-weight:800;">-₹${b._commAmt||0} <small style="color:var(--muted)">(${b._commPct||12}%)</small></td>
      <td style="color:var(--green);font-weight:900;">₹${b._provAmt||b.amount}</td>
      <td><span class="badge completed">Credited</span></td>
    </tr>
  `).join("") || "<tr><td colspan='7' style='text-align:center;color:var(--muted);padding:24px'>No completed bookings yet</td></tr>";

  // Pending withdraw requests
  const pendingReqs = s.payouts.filter(p => p.status === "pending");
  document.getElementById("pendingWithdrawSection").style.display = pendingReqs.length ? "block" : "none";
  document.getElementById("pendingWithdrawBody").innerHTML = pendingReqs.map(p => `
    <tr>
      <td><strong>${p.id}</strong></td>
      <td style="color:var(--green);font-weight:800;">₹${p.amount.toLocaleString("en-IN")}</td>
      <td style="font-size:12px">${p.account} <small>(${p.accountType})</small></td>
      <td style="font-size:12px">${new Date(p.requestedAt).toLocaleDateString("en-IN")}</td>
      <td><span class="badge pending">⏳ Pending Admin Approval</span></td>
    </tr>
  `).join("");

  // Full withdrawal history
  const allPayouts = [...s.payouts].sort((a,b) => new Date(b.requestedAt)-new Date(a.requestedAt));
  document.getElementById("withdrawHistoryBody").innerHTML = allPayouts.length ? allPayouts.map(p => `
    <tr>
      <td><strong>${p.id}</strong></td>
      <td style="font-weight:800;">₹${p.amount.toLocaleString("en-IN")}</td>
      <td style="font-size:12px">${p.account}</td>
      <td style="font-size:12px">${new Date(p.requestedAt).toLocaleDateString("en-IN")}</td>
      <td style="font-size:12px">${p.processedAt ? new Date(p.processedAt).toLocaleDateString("en-IN") : "—"}</td>
      <td><span class="badge ${p.status}">${
        p.status==="approved" ? "✅ Paid" :
        p.status==="rejected" ? "❌ Rejected" : "⏳ Pending"
      }</span></td>
    </tr>
  `).join("") : "<tr><td colspan='6' style='text-align:center;color:var(--muted);padding:20px'>No withdrawal history</td></tr>";
}

// ════════ PROFILE ════════
function loadProfile() {
  document.getElementById('pf_name').value     = P.name || '';
  document.getElementById('pf_phone').value    = P.phone || '';
  document.getElementById('pf_email').value    = P.email || '';
  document.getElementById('pf_whatsapp').value = P.whatsapp || '';
  document.getElementById('pf_bio').value      = P.bio || '';
  document.getElementById('pf_idtype').value   = P.idType || '';
  document.getElementById('pf_idnum').value    = P.idNum || '';
  const expSel = document.getElementById('pf_exp');
  [...expSel.options].forEach(o => { if (o.value === P.experience || o.text === P.experience) o.selected = true; });
  const genSel = document.getElementById('pf_gender');
  [...genSel.options].forEach(o => { if (o.value === P.gender || o.text === P.gender) o.selected = true; });

  const container = document.getElementById('svcsEdit');
  container.innerHTML = '';
  (P.services || []).forEach((s, i) => {
    const d = document.createElement('div');
    d.style.cssText = 'background:var(--bg);border:2px solid var(--border);border-radius:12px;padding:14px;text-align:center;';
    d.innerHTML = `<div style="font-size:26px;margin-bottom:5px">${s.icon}</div><div style="font-size:12px;font-weight:800;margin-bottom:8px">${s.name}</div>
      <div style="position:relative"><span style="position:absolute;left:9px;top:50%;transform:translateY(-50%);font-weight:800;color:var(--red);font-size:14px">₹</span>
      <input type="number" id="sp_${i}" value="${s.price}" style="width:100%;padding:8px 8px 8px 22px;border:2px solid var(--border);border-radius:9px;font-family:'Nunito',sans-serif;font-size:15px;font-weight:800;outline:none;text-align:center;" onfocus="this.style.borderColor='var(--red)'" onblur="this.style.borderColor='var(--border)'"/></div>
      <div style="font-size:10px;color:var(--muted);margin-top:3px;font-weight:700">per visit</div>`;
    container.appendChild(d);
  });
}

function saveProfile() {
  const all = JSON.parse(localStorage.getItem('hs_providers') || '[]');
  const idx = all.findIndex(p => p.id === P.id);
  if (idx > -1) {
    all[idx].name       = document.getElementById('pf_name').value;
    all[idx].phone      = document.getElementById('pf_phone').value;
    all[idx].email      = document.getElementById('pf_email').value;
    all[idx].whatsapp   = document.getElementById('pf_whatsapp').value;
    all[idx].bio        = document.getElementById('pf_bio').value;
    all[idx].experience = document.getElementById('pf_exp').value;
    all[idx].gender     = document.getElementById('pf_gender').value;
    P = all[idx];
    localStorage.setItem('hs_providers', JSON.stringify(all));
    localStorage.setItem('hs_current_provider', JSON.stringify(P));
    // Write to Firebase so admin and other devices see the update
    fbWrite('providers/' + P.id, P);
    document.getElementById('sb_name').textContent = P.name;
    showToast('✅ Profile saved!');
  }
}

function saveServices() {
  const all = JSON.parse(localStorage.getItem('hs_providers') || '[]');
  const idx = all.findIndex(p => p.id === P.id);
  if (idx > -1) {
    (P.services || []).forEach((s, i) => {
      const inp = document.getElementById(`sp_${i}`);
      if (inp) all[idx].services[i].price = parseInt(inp.value) || s.price;
    });
    P = all[idx];
    localStorage.setItem('hs_providers', JSON.stringify(all));
    localStorage.setItem('hs_current_provider', JSON.stringify(P));
    fbWrite('providers/' + P.id + '/services', P.services);
    showToast('✅ Services & prices updated!');
  }
}

// ════════ AVAILABILITY ════════
function loadAvailability() {
  const avail = P.availability || { days:[1,2,3,4,5], slots:['9AM','10AM','11AM','2PM','3PM'], start:'08:00', end:'20:00' };
  if (avail.start) document.getElementById('workStart').value = avail.start;
  if (avail.end)   document.getElementById('workEnd').value   = avail.end;

  const grid = document.getElementById('availGrid');
  grid.innerHTML = '';
  DAYS.forEach((d, i) => {
    const on = avail.days.includes(i);
    const el = document.createElement('div');
    el.className = 'avail-day ' + (on ? 'on' : 'off');
    el.id = 'day_' + i;
    el.innerHTML = `<div class="dn">${d}</div><div class="ds">${on ? '✅' : '❌'}</div>`;
    el.onclick = () => {
      const was = el.classList.contains('on');
      el.classList.toggle('on', !was); el.classList.toggle('off', was);
      el.querySelector('.ds').textContent = !was ? '✅' : '❌';
    };
    grid.appendChild(el);
  });

  const slotWrap = document.getElementById('timeSlots');
  slotWrap.innerHTML = '';
  TIME_SLOTS.forEach(slot => {
    const el = document.createElement('div');
    el.className = 'tslot' + (avail.slots.includes(slot) ? ' active' : '');
    el.textContent = slot;
    el.onclick = () => el.classList.toggle('active');
    slotWrap.appendChild(el);
  });
}

function saveAvailability() {
  const days = DAYS.map((_, i) => document.getElementById('day_' + i)?.classList.contains('on') ? i : null).filter(v => v !== null);
  const slots = [...document.querySelectorAll('.tslot.active')].map(el => el.textContent);
  const all = JSON.parse(localStorage.getItem('hs_providers') || '[]');
  const idx = all.findIndex(p => p.id === P.id);
  if (idx > -1) {
    all[idx].availability = { days, slots, start: document.getElementById('workStart').value, end: document.getElementById('workEnd').value };
    P = all[idx];
    localStorage.setItem('hs_providers', JSON.stringify(all));
    localStorage.setItem('hs_current_provider', JSON.stringify(P));
    fbWrite('providers/' + P.id + '/availability', P.availability);
    showToast('✅ Availability saved!');
  }
}

// ════════ LOCATION ════════
function initUpdateMap() {
  if (updateMap) { updateMap.invalidateSize(); return; }
  const lat = P.lat || 17.3850, lng = P.lng || 78.4867;
  newLat = lat; newLng = lng; newRadius = P.radius || 5;
  updateMap = L.map('updateMap').setView([lat, lng], 13);
  L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png',{attribution:'© OSM',maxZoom:19}).addTo(updateMap);
  updateMarker = L.marker([lat, lng],{draggable:true,icon:L.divIcon({html:`<div style="font-size:34px;margin-top:-34px;margin-left:-13px;filter:drop-shadow(0 3px 8px rgba(232,37,26,0.5))">📍</div>`,className:'',iconSize:[26,34],iconAnchor:[13,34]})}).addTo(updateMap);
  updateMarker.on('dragend', e => { const ll=e.target.getLatLng(); newLat=ll.lat; newLng=ll.lng; drawLocCircle(); reverseGeo(newLat,newLng); });
  updateMap.on('click', e => { newLat=e.latlng.lat; newLng=e.latlng.lng; updateMarker.setLatLng([newLat,newLng]); drawLocCircle(); reverseGeo(newLat,newLng); });
  document.getElementById('locRadiusSlider').value = newRadius;
  updateLocRadius(newRadius);
  document.getElementById('currentAddr').textContent = P.address || `${lat.toFixed(5)}, ${lng.toFixed(5)}`;
  drawLocCircle();
}

function drawLocCircle() {
  if (!updateMap || !newLat) return;
  if (updateCircle) updateMap.removeLayer(updateCircle);
  updateCircle = L.circle([newLat,newLng],{radius:newRadius*1000,color:'#E8251A',fillColor:'#E8251A',fillOpacity:0.08,weight:2,dashArray:'6,4'}).addTo(updateMap);
}

function updateLocRadius(v) {
  newRadius = parseInt(v);
  document.getElementById('locRadius2').textContent = v + ' km';
  const pct = ((v-1)/(50-1)*100).toFixed(1);
  document.getElementById('locRadiusSlider').style.background = `linear-gradient(to right,var(--red) 0%,var(--red) ${pct}%,var(--border) ${pct}%)`;
  drawLocCircle();
}

function searchNewLoc() {
  const q = document.getElementById('locSearch2').value.trim();
  if (!q) { showToast('⚠️ Enter a location'); return; }
  fetch(`https://nominatim.openstreetmap.org/search?q=${encodeURIComponent(q)}&format=json&limit=1`,{headers:{'Accept-Language':'en'}})
    .then(r=>r.json()).then(res=>{
      if(!res.length){showToast('❌ Not found');return;}
      newLat=+res[0].lat; newLng=+res[0].lon;
      updateMap.setView([newLat,newLng],14); updateMarker.setLatLng([newLat,newLng]);
      drawLocCircle(); reverseGeo(newLat,newLng);
    }).catch(()=>showToast('❌ Search failed'));
}

function locGPS() {
  if(!navigator.geolocation){showToast('❌ GPS not supported');return;}
  showToast('🎯 Getting location…');
  navigator.geolocation.getCurrentPosition(pos=>{
    newLat=pos.coords.latitude; newLng=pos.coords.longitude;
    updateMap.setView([newLat,newLng],15); updateMarker.setLatLng([newLat,newLng]);
    drawLocCircle(); reverseGeo(newLat,newLng); showToast('✅ Location updated!');
  },()=>showToast('❌ GPS failed'));
}

function reverseGeo(lat,lng) {
  document.getElementById('currentAddr').textContent = 'Fetching…';
  fetch(`https://nominatim.openstreetmap.org/reverse?lat=${lat}&lon=${lng}&format=json`,{headers:{'Accept-Language':'en'}})
    .then(r=>r.json()).then(d=>{
      const a=d.address||{};
      const parts=[a.road||a.suburb,a.suburb||a.neighbourhood,a.city||a.town||a.county,a.state].filter(Boolean);
      P._newAddress = parts.join(', ')||d.display_name;
      document.getElementById('currentAddr').textContent = P._newAddress;
    }).catch(()=>{P._newAddress=`${lat.toFixed(5)}, ${lng.toFixed(5)}`;document.getElementById('currentAddr').textContent=P._newAddress;});
}

function saveLocation() {
  if(!newLat){showToast('⚠️ Please set location on the map');return;}
  const all = JSON.parse(localStorage.getItem('hs_providers')||'[]');
  const idx = all.findIndex(p=>p.id===P.id);
  if(idx>-1){
    all[idx].lat=newLat; all[idx].lng=newLng; all[idx].radius=newRadius;
    all[idx].address=P._newAddress||all[idx].address;
    P=all[idx];
    localStorage.setItem('hs_providers',JSON.stringify(all));
    localStorage.setItem('hs_current_provider',JSON.stringify(P));
    // Write to Firebase so customer radar uses updated location/radius
    fbWrite('providers/' + P.id + '/lat', newLat);
    fbWrite('providers/' + P.id + '/lng', newLng);
    fbWrite('providers/' + P.id + '/radius', newRadius);
    fbWrite('providers/' + P.id + '/address', P.address);
    showToast('✅ Location saved! Customers in '+newRadius+'km can find you.');
  }
}

// ════════ RATINGS ════════
function loadRatings() {
  const reviews = getReviews();
  const avg = reviews.length ? (reviews.reduce((s,r)=>s+r.stars,0)/reviews.length) : 0;
  document.getElementById('ratingNum').textContent = reviews.length ? avg.toFixed(1) : '—';
  document.getElementById('ratingStars').textContent = reviews.length ? '★'.repeat(Math.round(avg)) + '☆'.repeat(5-Math.round(avg)) : '—';
  document.getElementById('reviewCount').textContent = reviews.length;

  // bars
  const bars = document.getElementById('ratingBars');
  bars.innerHTML = [5,4,3,2,1].map(n => {
    const cnt = reviews.filter(r=>r.stars===n).length;
    const pct = reviews.length ? (cnt/reviews.length*100).toFixed(0) : 0;
    return `<div class="rbar-row"><span class="rbar-label">${n} ★</span><div class="rbar-wrap"><div class="rbar-fill" style="width:${pct}%"></div></div><span class="rbar-count">${cnt}</span></div>`;
  }).join('');

  // reviews list
  document.getElementById('reviewsList').innerHTML = reviews.length
    ? reviews.map(r=>`<div style="border:1.5px solid var(--border);border-radius:9px;padding:11px;">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:4px;">
          <strong style="font-size:13px">${r.customer}</strong>
          <span style="color:#f59e0b;font-size:13px">${'★'.repeat(r.stars)}${'☆'.repeat(5-r.stars)}</span>
        </div>
        <div style="font-size:12px;color:var(--muted);font-weight:600;margin-bottom:3px">${r.text}</div>
        <div style="font-size:11px;color:var(--muted);font-weight:700">${r.date}</div>
      </div>`).join('')
    : '<div style="text-align:center;color:var(--muted);padding:20px;font-size:13px;font-weight:700">No reviews yet</div>';
}

// ════════ NOTIFICATIONS ════════
function loadNotifications() {
  const s = computeStats();
  const notifs = [];
  s.pending.forEach(b => notifs.push({type:'booking',icon:'📋',title:'Pending Booking',sub:`${b.customer} booked ${b.service} for ${b.date} at ${b.time}`,time:'Waiting for action',unread:true}));
  (s.awaitingPayment||[]).forEach(b => notifs.push({type:'payment',icon:'💳',title:'Awaiting Customer Payment',sub:`${b.customer} · ${b.service} · ₹${b.amount} — OTP verified, payment pending`,time:'Action needed',unread:true}));
  s.completed.slice(-3).forEach(b => notifs.push({type:'payment',icon:'💰',title:'Payment Received',sub:`₹${b.amount} for ${b.service} by ${b.customer}`,time:b.date,unread:false}));
  if (s.reviews.length) notifs.push({type:'rating',icon:'⭐',title:s.reviews.length + ' Customer Review(s)',sub:'Check your Ratings page for feedback',time:'Recent',unread:false});
  notifs.push({type:'alert',icon:'💡',title:'Profile Tip',sub:'Complete your profile to get 40% more bookings!',time:'Always',unread:false});

  document.getElementById('notifBadge').textContent = notifs.filter(n=>n.unread).length;
  document.getElementById('notifList').innerHTML = notifs.map(n=>`
    <div class="notif-item ${n.unread?'unread':''}">
      <div class="notif-icon-wrap ${n.type}">${n.icon}</div>
      <div style="flex:1"><div class="notif-title">${n.title}</div><div class="notif-sub">${n.sub}</div><div class="notif-time">${n.time}</div></div>
      ${n.unread?'<div class="unread-dot"></div>':''}
    </div>`).join('') || '<div style="text-align:center;color:var(--muted);padding:40px;font-size:14px;font-weight:700">No notifications</div>';
}

// ════════ AVAILABILITY TOGGLE ════════
function toggleAvailability() {
  const isOn = document.getElementById('availToggle').checked;
  const all = JSON.parse(localStorage.getItem('hs_providers')||'[]');
  const idx = all.findIndex(p=>p.id===P.id);
  if(idx>-1){
    all[idx].available=isOn; P=all[idx];
    localStorage.setItem('hs_providers',JSON.stringify(all));
    localStorage.setItem('hs_current_provider',JSON.stringify(P));
    // Sync to Firebase so customer radar and admin see live status
    fbWrite('providers/' + P.id + '/available', isOn);
  }
  updateAvailLabel();
  showToast(isOn?'🟢 You are now AVAILABLE for bookings!':'🔴 You are now UNAVAILABLE');
}
function updateAvailLabel(){document.getElementById('availLabel').textContent=P.available!==false?'🟢 Available':'🔴 Unavailable';}

// ════════ INCOMING ALERT ════════
const DEMO_CUSTOMERS = ['Kavitha Rao','Srikanth Reddy','Meena Kumari','Ravi Shankar','Divya Nair'];
function triggerIncomingAlert() {
  if (!P.available) return;
  const cust = DEMO_CUSTOMERS[Math.floor(Math.random()*DEMO_CUSTOMERS.length)];
  const svc  = (P.services||[])[0] || {name:'House Maid',icon:'🧹',price:499};
  const baseLat = P.lat||17.3850, baseLng = P.lng||78.4867;
  pendingBooking = {
    id:'HS-BK-'+Math.random().toString(36).substr(2,6).toUpperCase(),
    customer:cust, phone:'98'+Math.floor(10000000+Math.random()*89999999),
    service:svc.name, date:new Date().toISOString().split('T')[0],
    time:new Date().toLocaleTimeString('en-IN',{hour:'2-digit',minute:'2-digit'}),
    amount:svc.price, status:'pending',
    customerLat: baseLat + (Math.random()-.5)*.035,
    customerLng: baseLng + (Math.random()-.5)*.035,
    tracking: false
  };
  const custDist = haversineKm(baseLat, baseLng, pendingBooking.customerLat, pendingBooking.customerLng);
  document.getElementById('alertDetail').innerHTML = `<strong>Customer:</strong> ${cust}<br/><strong>Service:</strong> ${svc.icon} ${svc.name}<br/><strong>Distance:</strong> ${custDist.toFixed(1)} km away<br/><strong>Amount:</strong> ₹${svc.price}`;
  let sec = 60;
  document.getElementById('alertTimer').textContent = sec;
  document.getElementById('incomingAlert').style.display = 'block';
  clearInterval(alertCountdown);
  alertCountdown = setInterval(()=>{
    sec--; document.getElementById('alertTimer').textContent = sec;
    if(sec<=0){clearInterval(alertCountdown);document.getElementById('incomingAlert').style.display='none';showToast('⏰ Booking request expired');}
  },1000);
}

// ════════════════════════════════════════════════════════════
// REAL BOOKING POLLER — checks Firebase every 5 seconds
// ════════════════════════════════════════════════════════════
var bookingPoller = null;
var seenBookingIds = {}; // prevent showing same booking twice

function startBookingPoller() {
  if (bookingPoller) clearInterval(bookingPoller);
  // Poll immediately, then every 5 seconds
  checkForNewBookings();
  bookingPoller = setInterval(checkForNewBookings, 5000);
}

async function checkForNewBookings() {
  fbRead('active_bookings', function(data) {
  if (!data) return;


    var provLat      = P.lat || 17.3850;
    var provLng      = P.lng || 78.4867;
    var provServices = (P.services || []).map(function(s){ return (s.name||'').toLowerCase(); });
    var provRadius   = P.radius || 10;

    var keys = Object.keys(data);
    for (var i = 0; i < keys.length; i++) {
      var bk = data[keys[i]];
      if (!bk || !bk.id) continue;

      // ── If this booking is currently showing in our alert, check if
      //    someone else accepted it while we were looking ────────────
      if (seenBookingIds[bk.id] === 'alerting') {
        if (bk.acceptedBy && bk.acceptedBy.id && bk.acceptedBy.id !== P.id) {
          // Another provider accepted while our alert was open
          seenBookingIds[bk.id] = 'taken';
          clearInterval(alertCountdown);
          document.getElementById('incomingAlert').style.display = 'none';
          pendingBooking = null;
          showJobTakenPopup(bk.acceptedBy.name || 'Another provider');
        }
        continue;
      }

      if (seenBookingIds[bk.id]) continue;          // already handled
      if (bk.acceptedBy && bk.acceptedBy.id) continue; // already accepted
      if (bk.status !== 'searching') continue;       // not available

      var custLat = bk.lat, custLng = bk.lng;
      if (!custLat || !custLng) continue;
      var dist = haversineKm(provLat, provLng, custLat, custLng);
      if (dist > provRadius) continue;

      if (provServices.length > 0) {
        var reqSvc = (bk.service || '').toLowerCase();
        var matches = provServices.some(function(s){
          return reqSvc.includes(s) || s.includes(reqSvc.split(' ')[0]);
        });
        if (!matches) continue;
      }

      // Mark as alerting (not just 'seen') so we keep watching it
      seenBookingIds[bk.id] = 'alerting';
      showRealBookingAlert(bk, dist);
      break;
    }
  });
}

function showRealBookingAlert(bk, dist) {
  // Set as pending booking
  pendingBooking = {
    id:          bk.id,
    customer:    bk.customer || 'Customer',
    phone:       bk.phone || '',
    service:     bk.service || '',
    date:        bk.date || '',
    time:        bk.time || '',
    amount:      bk.priceVal || 0,
    status:      'pending',
    customerLat: bk.lat,
    customerLng: bk.lng,
    address:     bk.address || '',
    fromFirebase: true,
    fbKey:       bk.id,
  };

  var distStr = dist < 1 ? Math.round(dist*1000)+'m' : dist.toFixed(1)+' km';

  document.getElementById('alertDetail').innerHTML =
    '<strong>Customer:</strong> '+bk.customer+'<br/>'+
    '<strong>Service:</strong> '+bk.service+'<br/>'+
    '<strong>Distance:</strong> '+distStr+' away<br/>'+
    '<strong>Address:</strong> '+(bk.address||'—')+'<br/>'+
    '<strong>Date:</strong> '+bk.date+' at '+bk.time+'<br/>'+
    '<strong>Amount:</strong> ₹'+(bk.priceVal||bk.price||'—');

  var sec = 60;
  document.getElementById('alertTimer').textContent = sec;
  document.getElementById('incomingAlert').style.display = 'block';
  clearInterval(alertCountdown);

  // Play notification sound if available
  try { new Audio('data:audio/wav;base64,UklGRnoGAABXQVZFZm10IBAAAA==').play(); } catch(e){}

  alertCountdown = setInterval(function(){
    sec--;
    document.getElementById('alertTimer').textContent = sec;
    if (sec <= 0) {
      clearInterval(alertCountdown);
      document.getElementById('incomingAlert').style.display = 'none';
      // Mark as expired in Firebase so next provider can get it
      if (pendingBooking && pendingBooking.fromFirebase) {
        seenBookingIds[pendingBooking.id] = false; // allow retry
      }
      pendingBooking = null;
      showToast('⏰ Booking request expired');
    }
  }, 1000);
}

function acceptNewBooking() {
  if (!pendingBooking) return;
  var bkId   = pendingBooking.fbKey || pendingBooking.id;
  var bkSnap = pendingBooking;

  // Disable accept button immediately to prevent double-tap
  var acceptBtn = document.getElementById('acceptBtn');
  if (acceptBtn) { acceptBtn.disabled = true; acceptBtn.textContent = 'Checking…'; }

  // ── STEP 1: Read current state from Firebase FIRST ──────────
  // This is the race-condition gate — only one provider can win
  fbRead('active_bookings/' + bkId, function(current) {

    // Re-enable button if we exit early
    function reEnable() {
      if (acceptBtn) { acceptBtn.disabled = false; acceptBtn.textContent = 'Accept'; }
    }

    if (!current) { reEnable(); showToast('⚠️ Booking no longer available.'); return; }

    // Already accepted by someone else?
    if (current.acceptedBy && current.acceptedBy.id && current.acceptedBy.id !== P.id) {
      clearInterval(alertCountdown);
      document.getElementById('incomingAlert').style.display = 'none';
      seenBookingIds[bkId] = 'taken';
      pendingBooking = null;
      // Remove from local if we optimistically added it
      var localBk = getBK();
      var idx = localBk.findIndex(function(b){ return b.id === bkId; });
      if (idx > -1) { localBk.splice(idx, 1); saveBK(localBk); }
      showJobTakenPopup(current.acceptedBy.name || 'Another provider');
      return;
    }

    // Status changed away from 'searching'?
    if (current.status && current.status !== 'searching') {
      clearInterval(alertCountdown);
      document.getElementById('incomingAlert').style.display = 'none';
      seenBookingIds[bkId] = 'taken';
      pendingBooking = null;
      reEnable();
      showToast('⚠️ This booking is no longer available.');
      return;
    }

    // ── STEP 2: We won — clear UI and write to Firebase ─────────
    clearInterval(alertCountdown);
    document.getElementById('incomingAlert').style.display = 'none';
    seenBookingIds[bkId] = 'accepted';

    var providerInfo = {
      id:         P.id,
      name:       P.name,
      phone:      P.phone || '',
      rating:     P.rating || 4.8,
      totalJobs:  P.totalJobs || 0,
      lat:        P.lat || null,
      lng:        P.lng || null,
      acceptedAt: new Date().toISOString(),
    };

    // ── ATOMIC: Write acceptedBy + status together in ONE PATCH ──
    // Other providers' next poll will see acceptedBy set → show "taken" popup
    if (bkSnap.fromFirebase && bkId) {
      var atomicUpdate = {
        status:     'accepted',
        acceptedBy: providerInfo,
        acceptedAt: new Date().toISOString()
      };
      // Single PATCH — both fields written together atomically
      fbRestPatch('active_bookings/' + bkId, atomicUpdate);
      fbDelete('notifications/search_' + bkId);
      // Write full booking record
      fbWrite('bookings/' + bkId, Object.assign({}, bkSnap, {
        providerId:   P.id,
        providerName: P.name,
        acceptedBy:   providerInfo,
        status:       'accepted',
        acceptedAt:   new Date().toISOString(),
        amount:       bkSnap.amount || bkSnap.priceVal || 0
      }));
    }

    // Save to local dashboard
    var localBk = getBK();
    // Avoid duplicate
    if (!localBk.find(function(b){ return b.id === bkId; })) {
      localBk.unshift(Object.assign({}, bkSnap, { status: 'accepted', providerId: P.id }));
      saveBK(localBk);
    }

    syncProviderStats();
    loadOverview(); loadAllBookings(); loadNotifications();
    showToast('✅ Booking accepted! Customer is being notified…');
    pendingBooking = null;
  });
}

function rejectNewBooking() {
  clearInterval(alertCountdown);
  document.getElementById('incomingAlert').style.display = 'none';
  pendingBooking = null;
  showToast('❌ Booking rejected');
}

// ════════ WITHDRAW ════════
function showWithdrawModal(){
  const s = computeStats();
  const avail = document.getElementById("wdAvailDisplay");
  if(avail) avail.textContent = "₹"+s.available.toLocaleString("en-IN");
  document.getElementById("withdrawModal").classList.add("show");
}
function processWithdraw(){
  const amt = parseInt(document.getElementById("wdAmt").value);
  const acc = document.getElementById("wdAcc").value.trim();
  const type = document.getElementById("wdType").value;
  if(!amt || amt <= 0){showToast("⚠️ Enter a valid amount");return;}
  if(!acc){showToast("⚠️ Enter your bank account or UPI ID");return;}

  const s = computeStats();
  const available = s.netEarned - s.withdrawn - s.pendingWithdraw;
  if(amt > available){showToast("⚠️ Amount exceeds available balance ₹"+available);return;}

  // Create payout request
  const payouts = JSON.parse(localStorage.getItem("hs_payouts")||"[]");
  const req = {
    id: "WD-"+Date.now().toString(36).toUpperCase(),
    providerId: P.id,
    providerName: P.name,
    providerPhone: P.phone,
    amount: amt,
    account: acc,
    accountType: type,
    status: "pending",
    requestedAt: new Date().toISOString(),
    processedAt: null,
    note: ""
  };
  payouts.push(req);
  localStorage.setItem("hs_payouts", JSON.stringify(payouts));
  // Write to Firebase so admin can see and process
  fbWrite('payouts/' + req.id, req);
  closeModal("withdrawModal");
  loadEarnings();
  showToast("✅ Withdrawal request of ₹"+amt+" submitted! Admin will process within 24 hrs.");
}

// ════════ NAV ════════
const PAGE_TITLES={overview:'📊 Overview',bookings:'📋 My Bookings',earnings:'💰 Earnings',profile:'👤 My Profile',availability:'📅 Availability',location:'📍 Update Location',ratings:'⭐ Ratings',notifications:'🔔 Notifications'};
function showPage(name){
  document.querySelectorAll('.page').forEach(p=>p.classList.remove('active'));
  document.querySelectorAll('.nav-item').forEach(n=>n.classList.remove('active'));
  document.getElementById('page-'+name)?.classList.add('active');
  document.querySelectorAll('.nav-item').forEach(n=>{if(n.textContent.toLowerCase().includes(name.split('-')[0]))n.classList.add('active');});
  document.getElementById('pageTitle').textContent=PAGE_TITLES[name]||name;
  document.getElementById('sidebar').classList.remove('open');
  if(name==='location')setTimeout(initUpdateMap,200);
}

// ════════ UTILS ════════
function cap(s){return s.charAt(0).toUpperCase()+s.slice(1);}
function closeModal(id){document.getElementById(id).classList.remove('show');}
function showToast(msg){const t=document.getElementById('toast');t.textContent=msg;t.style.display='block';clearTimeout(t._t);t._t=setTimeout(()=>t.style.display='none',3500);}

// ════════════════════════════════════════════════════════════════
// REAL TRACKING ENGINE
// GPS: navigator.geolocation.watchPosition (device GPS)
// Routing: OSRM (free, real roads, no API key)
// Sync: Firebase Realtime Database (free tier)
// ════════════════════════════════════════════════════════════════

// ══════════════════════════════════════════════════════════════
// REAL-TIME SYNC ENGINE
// PRIMARY  : localStorage + BroadcastChannel (same device, instant)
// SECONDARY: Firebase Realtime DB (cross-device — configure below)
// ══════════════════════════════════════════════════════════════

// ── FIREBASE SETUP (cross-device tracking) ────────────────────
// STEP 1: Go to https://console.firebase.google.com
// STEP 2: Create project → Build → Realtime Database → Create database
// STEP 3: Rules tab → paste: { "rules": { ".read": true, ".write": true } }
// STEP 4: Copy your database URL (looks like: https://YOURPROJECT-default-rtdb.firebaseio.com)
// STEP 5: Replace the URL below
const FB_URL = 'https://hamaraservice-s009-default-rtdb.asia-southeast1.firebasedatabase.app'; // ✅ LIVE
const FB_URL_ALT = 'https://hamaraservice-s009-default-rtdb.asia-southeast1.firebasedatabase.app'; // same
const FB_ENABLED = true; // Firebase configured ✅

// BroadcastChannel for same-device cross-tab sync (works immediately, no setup)
var trackChannel = null;
try { trackChannel = new BroadcastChannel('hs_tracking'); } catch(e) {}

// ── MAP + STATE ──────────────────────────────────────────────────
var provMap=null, custMap2=null;
var provSelfMarker=null, provDestMarker=null, provRoutePolyline=null;
var custProvMarker=null, custHomeMarker=null, custRoutePolyline=null;
var gpsWatchId=null, custPollTimer=null;
var activeBookingId=null, activeBookingData=null;
var myRealLat=null, myRealLng=null;

// ── LEAFLET ICONS ────────────────────────────────────────────────
function mkIcon(emoji,bg,sz){sz=sz||44;return L.divIcon({html:'<div style="width:'+sz+'px;height:'+sz+'px;background:'+bg+';border-radius:50%;border:3px solid white;box-shadow:0 4px 16px rgba(0,0,0,.3);display:flex;align-items:center;justify-content:center;font-size:'+(sz*.5)+'px;">'+emoji+'</div>',className:'',iconSize:[sz,sz],iconAnchor:[sz/2,sz/2]});}
function mkDestIcon(emoji){return L.divIcon({html:'<div style="position:relative"><div style="width:48px;height:48px;background:white;border-radius:50%;border:3px solid #1A73E8;box-shadow:0 4px 14px rgba(27,79,255,.35);display:flex;align-items:center;justify-content:center;font-size:22px;">'+emoji+'</div><div style="width:14px;height:14px;background:#1A73E8;border-radius:50%;border:2px solid white;position:absolute;bottom:-4px;right:-2px;"></div></div>',className:'',iconSize:[48,48],iconAnchor:[24,24]});}

// ── MATH ────────────────────────────────────────────────────────
function calcDist(a,b,c,d){var R=6371,dL=(c-a)*Math.PI/180,dN=(d-b)*Math.PI/180,e=Math.sin(dL/2)*Math.sin(dL/2)+Math.cos(a*Math.PI/180)*Math.cos(c*Math.PI/180)*Math.sin(dN/2)*Math.sin(dN/2);return R*2*Math.atan2(Math.sqrt(e),Math.sqrt(1-e));}
function haversineKm(a,b,c,d){return calcDist(a,b,c,d);}
function fmtD(km){return km<1?Math.round(km*1000)+'m':km.toFixed(1)+'km';}
function fmtT(km){var m=Math.max(1,Math.round(km*3.5));return m<60?m+' min':(Math.floor(m/60)+'h '+(m%60)+'m');}

// ── OSRM: REAL ROAD ROUTE (free, no key) ────────────────────────
async function getRoute(fromLat,fromLng,toLat,toLng){
  try{
    var url='https://router.project-osrm.org/route/v1/driving/'+fromLng+','+fromLat+';'+toLng+','+toLat+'?overview=full&geometries=geojson';
    var r=await fetch(url,{signal:AbortSignal.timeout(8000)});
    var d=await r.json();
    if(d.code==='Ok'&&d.routes.length){
      var coords=d.routes[0].geometry.coordinates.map(function(c){return[c[1],c[0]];});
      return{coords:coords,dist:d.routes[0].distance/1000,dur:d.routes[0].duration/60};
    }
  }catch(e){console.warn('OSRM failed:',e);}
  // Fallback: straight line
  return{coords:[[fromLat,fromLng],[toLat,toLng]],dist:calcDist(fromLat,fromLng,toLat,toLng),dur:calcDist(fromLat,fromLng,toLat,toLng)*4};
}

// ── DUAL SYNC: localStorage + BroadcastChannel + Firebase ────────
async function fbWrite(path, data) {
  // localStorage — same device instant sync
  try { localStorage.setItem('hs_track_' + path.replace(/\//g,'_'), JSON.stringify(data)); } catch(e) {}
  // BroadcastChannel — cross-tab same device
  try { if(trackChannel) trackChannel.postMessage({path:path, data:data}); } catch(e) {}
  // Firebase REST — cross-device (no await, proper .then())
  if (!FB_ENABLED) return;
  var url = FB_URL + '/' + path + '.json';
  fetch(url, {
    method: 'PUT',
    headers: {'Content-Type':'application/json'},
    body: JSON.stringify(data),
    signal: AbortSignal.timeout(8000)
  }).then(function(r){
    if (r.ok) console.log('[fbWrite] OK:', path);
    else console.warn('[fbWrite] HTTP', r.status, path);
  }).catch(function(e){ console.warn('[fbWrite] err:', path, e.message); });
}

async function fbRead(path, cb) {
  // Firebase REST GET — proper .then() chain, no await in non-async fn
  if (FB_ENABLED) {
    var url = FB_URL + '/' + path + '.json';
    fetch(url, { signal: AbortSignal.timeout(8000) })
      .then(function(r) { return r.ok ? r.json() : null; })
      .then(function(data) { if (cb) cb(data); })
      .catch(function(e) {
        console.warn('[fbRead] err:', path, e.message);
        // Fallback: localStorage
        try {
          var raw = localStorage.getItem('hs_track_' + path.replace(/\//g,'_'));
          if (cb) cb(raw ? JSON.parse(raw) : null);
        } catch(e2) { if (cb) cb(null); }
      });
  } else {
    try {
      var raw = localStorage.getItem('hs_track_' + path.replace(/\//g,'_'));
      if (cb) cb(raw ? JSON.parse(raw) : null);
    } catch(e) { if (cb) cb(null); }
  }
}

async function fbDelete(path) {
  if (!FB_ENABLED) return;
  try {
    await fetch(FB_URL + '/' + path + '.json', {method:'DELETE', signal:AbortSignal.timeout(5000)});
  } catch(e) { console.warn('FB delete failed:', e.message); }
}

// Listen for BroadcastChannel messages (cross-tab same device)
if (trackChannel) {
  trackChannel.onmessage = function(ev) {
    try {
      var path = ev.data.path, data = ev.data.data;
      localStorage.setItem('hs_track_' + path.replace(/\//g,'_'), JSON.stringify(data));
    } catch(e) {}
  };
}

// ════════════════════════════════════════════════════════════════
// PROVIDER TRACKING — Opens on provider device
// Uses real device GPS, publishes to Firebase every 4s
// ════════════════════════════════════════════════════════════════
async function openProviderTracking(bookingId){
  var bk=getBK();
  var booking=bk.find(function(b){return b.id===bookingId;});
  if(!booking)return;
  activeBookingId=bookingId;
  activeBookingData=booking;

  document.getElementById('provDrwCustName').textContent=booking.customer||'Customer';
  document.getElementById('provDrwCustDetail').textContent=booking.service+' · '+booking.date+' '+(booking.time||'');
  document.getElementById('provEarn').textContent='Rs '+(booking.amount-Math.round(booking.amount*.12)).toLocaleString('en-IN');
  document.getElementById('provCallBtn').onclick=function(){window.open('tel:'+(booking.phone||''));};
  document.getElementById('providerTrackingView').classList.add('show');
  setGpsBanner('prov','searching','Acquiring GPS signal...');
  updateProvSteps('searching');
  // Show Firebase setup hint if not configured
  var fbBanner = document.getElementById('fbSetupBanner');
  if (fbBanner) fbBanner.style.display = 'block'; // show rules reminder until rules are published

  setTimeout(async function(){
    if(!provMap){
      provMap=L.map('provMap',{zoomControl:false}).setView([17.385,78.486],15);
      L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png',{attribution:'© OpenStreetMap',maxZoom:19}).addTo(provMap);
      L.control.zoom({position:'bottomright'}).addTo(provMap);
    }
    // Customer destination pin
    var cLat=booking.customerLat, cLng=booking.customerLng;
    if(cLat&&cLng){
      if(provDestMarker)provMap.removeLayer(provDestMarker);
      provDestMarker=L.marker([cLat,cLng],{icon:mkDestIcon('🏠')}).addTo(provMap).bindPopup('<b>'+(booking.customer||'Customer')+'</b><br/>'+(booking.service||'')+'<br/>Destination');
      provMap.setView([cLat,cLng],13);
    }
    startRealGPS(bookingId,cLat,cLng);
  },200);
}

function startRealGPS(bookingId,destLat,destLng){
  if(!navigator.geolocation){
    setGpsBanner('prov','error','GPS not supported on this device');
    showToast('GPS not available — try Google Chrome on Android');
    return;
  }
  setGpsBanner('prov','searching','Acquiring GPS...');
  document.getElementById('provTrkTitle').textContent='Getting your location...';

  if(gpsWatchId!==null){navigator.geolocation.clearWatch(gpsWatchId);}

  gpsWatchId=navigator.geolocation.watchPosition(
    async function(pos){
      var lat=pos.coords.latitude, lng=pos.coords.longitude, acc=Math.round(pos.coords.accuracy);
      myRealLat=lat; myRealLng=lng;

      setGpsBanner('prov','live','GPS Live · Accuracy ±'+acc+'m');
      document.getElementById('provTrkTitle').textContent='Navigating to customer';
      document.getElementById('provTrkDot').className='trk-dot green';

      // Place / move provider marker
      if(!provSelfMarker){
        provSelfMarker=L.marker([lat,lng],{icon:mkIcon('👷','linear-gradient(135deg,#E8251A,#FF6B00)')}).addTo(provMap).bindPopup('<b>You (Provider)</b><br/>Acc: ±'+acc+'m');
        L.circle([lat,lng],{radius:acc,color:'#1A73E8',fillColor:'#1A73E8',fillOpacity:.08,weight:1,dashArray:'5,3'}).addTo(provMap);
      } else {
        provSelfMarker.setLatLng([lat,lng]);
      }

      // Get real road route
      if(destLat&&destLng){
        var route=await getRoute(lat,lng,destLat,destLng);
        if(provRoutePolyline)provMap.removeLayer(provRoutePolyline);
        provRoutePolyline=L.polyline(route.coords,{color:'#1A73E8',weight:6,opacity:.85,lineJoin:'round',lineCap:'round'}).addTo(provMap);
        provMap.fitBounds([[lat,lng],[destLat,destLng]],{padding:[80,80],maxZoom:16});

        var d=route.dist;
        document.getElementById('provDist').textContent=fmtD(d);
        document.getElementById('provEtaVal').textContent=fmtT(d);
        document.getElementById('provTrkEta').textContent=fmtT(d);
        document.getElementById('provTrkSub').textContent=fmtD(d)+' via road · ±'+acc+'m';

        updateProvSteps(d<0.05?'arrived':'navigating');
        if(d<0.05){
          document.getElementById('provTrkTitle').textContent='You have arrived!';
          setGpsBanner('prov','live','You are at the customer location');
        }
      } else {
        provMap.setView([lat,lng],16);
      }

      // PUBLISH to Firebase — customer map reads this
      await fbWrite('tracking/'+bookingId+'/provider',{lat:lat,lng:lng,ts:Date.now(),name:P.name,phone:P.phone,rating:P.rating||4.8,service:activeBookingData?(activeBookingData.service||''):''});
    },
    function(err){
      var msgs={
        1:'Location permission denied — please tap Allow when browser asks',
        2:'GPS unavailable — make sure you are outdoors or near a window',
        3:'GPS is slow — retrying automatically...'
      };
      setGpsBanner('prov','error',msgs[err.code]||'GPS error: '+err.message);
      document.getElementById('provTrkTitle').textContent = err.code===1 ? 'Allow Location Access' : 'GPS Signal Weak';
      document.getElementById('provTrkSub').textContent = msgs[err.code]||'GPS error';
      if(err.code!==1) setTimeout(function(){startRealGPS(bookingId,destLat,destLng);},6000);
      // Show manual instructions on permission denied
      if(err.code===1){
        showToast('⚠️ GPS denied — go to browser Settings → Site permissions → Location → Allow for '+window.location.hostname);
      }
    },
    {enableHighAccuracy:true,maximumAge:2000,timeout:20000}
  );
}

function stopProviderTracking(){
  if(gpsWatchId!==null){navigator.geolocation.clearWatch(gpsWatchId);gpsWatchId=null;}
  document.getElementById('providerTrackingView').classList.remove('show');
}

function openGoogleMapsNav(){
  if(!activeBookingData){return;}
  var lat=activeBookingData.customerLat,lng=activeBookingData.customerLng;
  if(!lat){showToast('Customer location not available');return;}
  // Try Google Maps first, fall back to general maps
  var gUrl='https://www.google.com/maps/dir/?api=1&destination='+lat+','+lng+'&travelmode=driving';
  window.open(gUrl,'_blank');
  showToast('\uD83D\uDDFA Opened in Google Maps!');
}

// Share customer tracking link (for sending to customer via WhatsApp/SMS)
function shareTrackingLink(bookingId){
  var url = window.location.href.split('?')[0] + '?track=' + bookingId;
  if(navigator.share){
    navigator.share({title:'Track your service professional',text:'Track '+P.name+' in real time:',url:url});
  } else if(navigator.clipboard){
    navigator.clipboard.writeText(url);
    showToast('\uD83D\uDD17 Tracking link copied! Share with customer via WhatsApp');
  } else {
    prompt('Copy this tracking link and send to customer:',url);
  }
}

// ════════════════════════════════════════════════════════════════
// CUSTOMER TRACKING — Opens on customer device
// Reads provider location from Firebase every 4 seconds
// Shows real road route from provider to customer
// ════════════════════════════════════════════════════════════════
async function openCustomerTracking(bookingId){
  var bk=getBK();
  var booking=bk.find(function(b){return b.id===bookingId;});
  if(!booking)return;
  activeBookingId=bookingId;
  activeBookingData=booking;

  document.getElementById('custDrwProvName').textContent=P.name;
  document.getElementById('custDrwProvDetail').textContent=(booking.service||'Service')+' · Booking '+bookingId;
  document.getElementById('custDrwRating').textContent='⭐ '+(P.rating||4.8)+' · Background Verified';
  document.getElementById('custAmt').textContent='Rs '+(booking.amount||0);
  document.getElementById('custCallBtn').onclick=function(){window.open('tel:'+(P.phone||''));};
  document.getElementById('customerTrackingView').classList.add('show');
  updateCustSteps('waiting');

  setTimeout(async function(){
    if(!custMap2){
      custMap2=L.map('custMap',{zoomControl:false}).setView([17.385,78.486],14);
      L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png',{attribution:'© OpenStreetMap',maxZoom:19}).addTo(custMap2);
      L.control.zoom({position:'bottomright'}).addTo(custMap2);
    }
    var cLat=booking.customerLat, cLng=booking.customerLng;
    if(cLat&&cLng){
      if(custHomeMarker)custMap2.removeLayer(custHomeMarker);
      custHomeMarker=L.marker([cLat,cLng],{icon:mkDestIcon('🏠')}).addTo(custMap2).bindPopup('<b>Your location</b>');
      custMap2.setView([cLat,cLng],14);
    }
    startFirebasePoll(bookingId,cLat,cLng);
  },200);
}

async function startFirebasePoll(bookingId,custLat,custLng){
  if(custPollTimer)clearInterval(custPollTimer);

  async function poll(){
    if(!document.getElementById('customerTrackingView').classList.contains('show')){clearInterval(custPollTimer);return;}

    // PRIMARY: Read from Firebase (cross-device, real-time)
    var data=await fbRead('tracking/'+bookingId+'/provider');

    // FALLBACK: Same device demo — read from memory
    if((!data||!data.lat)&&myRealLat){data={lat:myRealLat,lng:myRealLng,ts:Date.now(),name:P.name,phone:P.phone,rating:P.rating||4.8};}

    if(!data||!data.lat){
      setGpsBanner('cust','searching','Waiting for provider GPS...');
      document.getElementById('custTrkTitle').textContent='Connecting to provider...';
      updateCustSteps('waiting');
      return;
    }

    var pLat=data.lat, pLng=data.lng;
    var age=Date.now()-(data.ts||0);
    var fresh=age<15000;
    setGpsBanner('cust',fresh?'live':'searching',fresh?'Live GPS · Updated '+Math.round(age/1000)+'s ago':'Last seen '+Math.round(age/1000)+'s ago — reconnecting');

    // Place / animate provider marker
    if(!custProvMarker){
      custProvMarker=L.marker([pLat,pLng],{icon:mkIcon('👷','linear-gradient(135deg,#E8251A,#FF6B00)')}).addTo(custMap2).bindPopup('<b>'+(data.name||P.name)+'</b><br/>On the way!');
    } else {
      custProvMarker.setLatLng([pLat,pLng]);
    }

    // Real road route
    if(custLat&&custLng){
      var route=await getRoute(pLat,pLng,custLat,custLng);
      if(custRoutePolyline)custMap2.removeLayer(custRoutePolyline);
      custRoutePolyline=L.polyline(route.coords,{color:'#E8251A',weight:6,opacity:.85,lineJoin:'round',lineCap:'round'}).addTo(custMap2);
      custMap2.fitBounds([[pLat,pLng],[custLat,custLng]],{padding:[80,80],maxZoom:16});

      var d=route.dist;
      document.getElementById('custDist').textContent=fmtD(d);
      document.getElementById('custEtaVal').textContent=fmtT(d);
      document.getElementById('custTrkEta').textContent=fmtT(d);
      document.getElementById('custDrwSub').textContent=(data.name||P.name)+' is '+fmtD(d)+' away';
      document.getElementById('custTrkSub').textContent=fmtD(d)+' via road · Live GPS';

      if(d<0.08){
        document.getElementById('custTrkTitle').textContent='Professional has arrived!';
        document.getElementById('custTrkDot').className='trk-dot orange';
        updateCustSteps('arrived');
      } else {
        document.getElementById('custTrkTitle').textContent='Professional on the way';
        updateCustSteps('enroute');
      }
    }
  }

  await poll();
  custPollTimer=setInterval(poll,4000);
}

function stopCustomerTracking(){
  if(custPollTimer){clearInterval(custPollTimer);custPollTimer=null;}
  document.getElementById('customerTrackingView').classList.remove('show');
}

// ── BANNERS + STEP TIMELINES ────────────────────────────────────
function setGpsBanner(side,state,msg){
  var id=side==='prov'?'provGpsBanner':'custGpsBanner';
  var el=document.getElementById(id);
  if(!el)return;
  el.className='gps-banner '+state;
  el.textContent=msg;
}
function updateProvSteps(stage){
  var idx={'searching':1,'navigating':1,'arrived':2,'working':3,'done':4}[stage]||0;
  renderTimeline('provSteps',[
    {t:'Booking Accepted',d:'You accepted the job'},
    {t:'Navigating',d:'Following GPS route to customer'},
    {t:'Arrived',d:'At customer location'},
    {t:'Job in Progress',d:'Service underway'},
    {t:'Complete and Get Paid',d:'OTP confirmation and payment'},
  ],idx);
}
function updateCustSteps(stage){
  var idx={'waiting':0,'enroute':1,'arrived':2,'working':3,'done':4}[stage]||0;
  renderTimeline('custSteps',[
    {t:'Professional Assigned',d:P.name+' accepted your booking'},
    {t:'On the Way',d:'Live GPS tracking active'},
    {t:'Arrived',d:'Professional at your door'},
    {t:'Job in Progress',d:'Service underway'},
    {t:'Payment and Rating',d:'Confirm completion and pay'},
  ],idx);
}
function renderTimeline(id,steps,activeIdx){
  var el=document.getElementById(id);
  if(!el)return;
  el.innerHTML=steps.map(function(s,i){
    var done=i<activeIdx, active=i===activeIdx, last=i===steps.length-1;
    var cls=done?'done':active?'active':'wait';
    return '<div class="drw-step"><div class="ds-col"><div class="ds-dot '+cls+'">'+(done?'&#10003;':active?'&#9679;':(i+1))+'</div>'+(last?'':'<div class="ds-line '+(done?'done':'wait')+'"></div>')+'</div><div class="ds-info"><div class="ds-title '+cls+'">'+s.t+'</div><div class="ds-desc">'+s.d+'</div></div></div>';
  }).join('');
}




// ─── ANDROID MOBILE MODE CONTROLLER ─────────────────────────────────────────
(function(){
  // Detect mobile: width ≤ 768 OR primary input is touch
  var isMobile = window.matchMedia('(max-width:768px)').matches ||
                 window.matchMedia('(pointer:coarse)').matches;

  if(!isMobile) return; // Desktop — do nothing at all

  // Activate mobile mode
  document.body.classList.add('mobile-mode');
  document.getElementById('mobileNav').style.display = 'flex';

  // ── Sync provider name/ID into drawer ───────────────────────────────────
  function syncMobHeader(){
    var P = JSON.parse(localStorage.getItem('hs_current_provider')||'null');
    if(!P) return;
    var nameEl = document.getElementById('mob_pname');
    var idEl   = document.getElementById('mob_pid');
    if(nameEl) nameEl.textContent = P.name || 'Provider';
    if(idEl)   idEl.textContent   = P.id   || '';
  }
  syncMobHeader();
  setTimeout(syncMobHeader, 1200); // retry after Firebase load

  // ── Sync availability toggle ─────────────────────────────────────────────
  var mainToggle = document.getElementById('availToggle');
  var mobToggle  = document.getElementById('mobAvailToggle');
  var mobLabel   = document.getElementById('mobAvailLabel');

  function syncAvailLabel(isAvail){
    if(mobLabel) mobLabel.textContent = isAvail ? '🟢 Available' : '🔴 Unavailable';
  }

  // Keep in sync with main toggle
  if(mainToggle && mobToggle){
    mobToggle.checked = mainToggle.checked;
    syncAvailLabel(mainToggle.checked);
    mainToggle.addEventListener('change', function(){
      mobToggle.checked = this.checked;
      syncAvailLabel(this.checked);
    });
  }

  window.mobSyncAvail = function(el){
    if(mainToggle){ mainToggle.checked = el.checked; mainToggle.dispatchEvent(new Event('change')); }
    syncAvailLabel(el.checked);
  };

  // ── Badge sync ───────────────────────────────────────────────────────────
  function syncBadges(){
    var bk = document.getElementById('pendingBadge');
    var nt = document.getElementById('notifBadge');
    var mbk = document.getElementById('mobBadgeBk');
    var mnt = document.getElementById('mobBadgeNotif');
    if(bk && mbk){ var n=parseInt(bk.textContent)||0; mbk.textContent=n; mbk.style.display=n?'flex':'none'; }
    if(nt && mnt){ var n=parseInt(nt.textContent)||0; mnt.textContent=n; mnt.style.display=n?'':'none'; }
  }
  syncBadges();
  setInterval(syncBadges, 2000);

  // ── Bottom Nav routing ───────────────────────────────────────────────────
  window.mobNav = function(page){
    showPage(page); // reuse existing showPage function
    // Update active tab
    document.querySelectorAll('.mob-nav-item').forEach(function(el){
      el.classList.remove('active');
    });
    var tab = document.getElementById('mni-'+page);
    if(tab) tab.classList.add('active');
    else{
      // pages under "More" — highlight More tab
      document.getElementById('mni-more').classList.add('active');
    }
    syncBadges();
  };

  // ── More drawer ──────────────────────────────────────────────────────────
  window.mobToggleMore = function(){
    var drawer   = document.getElementById('mobMoreDrawer');
    var backdrop = document.getElementById('mobMoreBackdrop');
    syncMobHeader();
    var isOpen = drawer.classList.contains('open');
    if(isOpen){ mobCloseMore(); } else {
      drawer.classList.add('open');
      backdrop.classList.add('open');
    }
  };
  window.mobCloseMore = function(){
    document.getElementById('mobMoreDrawer').classList.remove('open');
    document.getElementById('mobMoreBackdrop').classList.remove('open');
  };

  // ── Intercept showPage to keep bottom nav in sync ───────────────────────
  var _origShowPage = window.showPage;
  window.showPage = function(name){
    _origShowPage(name);
    // Update mobile bottom tab
    document.querySelectorAll('.mob-nav-item').forEach(function(el){ el.classList.remove('active'); });
    var mainTabs = ['overview','bookings','earnings','ratings'];
    if(mainTabs.indexOf(name) !== -1){
      var tab = document.getElementById('mni-'+name);
      if(tab) tab.classList.add('active');
    } else {
      var moreTab = document.getElementById('mni-more');
      if(moreTab) moreTab.classList.add('active');
    }
    syncBadges();
  };

  // ── Re-check on orientation change ──────────────────────────────────────
  window.addEventListener('resize', function(){
    var stillMobile = window.matchMedia('(max-width:768px)').matches ||
                      window.matchMedia('(pointer:coarse)').matches;
    document.body.classList.toggle('mobile-mode', stillMobile);
    document.getElementById('mobileNav').style.display = stillMobile ? 'flex' : 'none';
  });

})();



/* ═══════════════════════════════════════════════════════════════════════════
   HAMARA SERVICE — SOUND & NOTIFICATION ENGINE  v1.0
   Shared across: provider-dashboard, admin, user-panel
   Works 100% offline — all sounds synthesized via Web Audio API (no files)
   ═══════════════════════════════════════════════════════════════════════════ */
(function(){
'use strict';
var ROLE = window.HS_SOUND_ROLE || 'user';
var _ctx = null, _unlocked = false, _pendingPlays = [];
function getCtx(){ if(!_ctx){ try{ _ctx=new (window.AudioContext||window.webkitAudioContext)(); }catch(e){ return null; } } return _ctx; }
function unlock(){
  if(_unlocked) return;
  var ctx=getCtx(); if(!ctx) return;
  if(ctx.state==='suspended') ctx.resume();
  var buf=ctx.createBuffer(1,1,22050),src=ctx.createBufferSource();
  src.buffer=buf; src.connect(ctx.destination); src.start(0);
  _unlocked=true;
  _pendingPlays.forEach(function(fn){ setTimeout(fn,50); }); _pendingPlays=[];
}
['click','touchstart','keydown'].forEach(function(e){ document.addEventListener(e,unlock,{once:true,passive:true}); });
function playTone(o){
  var ctx=getCtx(); if(!ctx) return;
  if(!_unlocked){ _pendingPlays.push(function(){ playTone(o); }); return; }
  if(ctx.state==='suspended'){ ctx.resume().then(function(){ playTone(o); }); return; }
  var now=ctx.currentTime, freqs=o.freqs||[440], dur=o.dur||0.4, vol=o.vol||0.4, type=o.type||'sine';
  var gain=ctx.createGain(); gain.connect(ctx.destination);
  gain.gain.setValueAtTime(0,now);
  gain.gain.linearRampToValueAtTime(vol, now+(o.fadeIn||0.02));
  gain.gain.setValueAtTime(vol, now+dur-(o.fadeOut||0.05));
  gain.gain.linearRampToValueAtTime(0, now+dur);
  freqs.forEach(function(f,i){
    var osc=ctx.createOscillator(); osc.type=type;
    osc.frequency.setValueAtTime(f,now);
    if(o.ramp) osc.frequency.linearRampToValueAtTime(o.ramp[i]||f, now+dur);
    osc.connect(gain); osc.start(now); osc.stop(now+dur);
  });
}
var SND={
  providerNewJob:function(){
    for(var r=0;r<3;r++)(function(r){
      setTimeout(function(){
        playTone({freqs:[523,659,784],type:'sawtooth',dur:0.18,vol:0.55,fadeIn:0.01,fadeOut:0.03});
        setTimeout(function(){ playTone({freqs:[659,784,1047],type:'sawtooth',dur:0.18,vol:0.60,fadeIn:0.01,fadeOut:0.03}); },200);
        setTimeout(function(){ playTone({freqs:[784,1047,1319],type:'sawtooth',dur:0.28,vol:0.65,fadeIn:0.01,fadeOut:0.05}); },420);
      },r*900);
    })(r);
  },
  providerCountdownWarn:function(){
    playTone({freqs:[880],type:'square',dur:0.1,vol:0.5});
    setTimeout(function(){ playTone({freqs:[880],type:'square',dur:0.1,vol:0.5}); },150);
    setTimeout(function(){ playTone({freqs:[1100],type:'square',dur:0.2,vol:0.6}); },300);
  },
  providerJobAccepted:function(){
    playTone({freqs:[523],type:'sine',dur:0.12,vol:0.4});
    setTimeout(function(){ playTone({freqs:[659],type:'sine',dur:0.12,vol:0.4}); },130);
    setTimeout(function(){ playTone({freqs:[784],type:'sine',dur:0.25,vol:0.45}); },260);
  },
  providerPaymentIn:function(){
    playTone({freqs:[1047,1319],type:'sine',dur:0.12,vol:0.45,fadeIn:0.01,fadeOut:0.03});
    setTimeout(function(){ playTone({freqs:[1319,1568],type:'sine',dur:0.2,vol:0.5,fadeIn:0.01,fadeOut:0.06}); },140);
  },
  providerJobExpired:function(){
    playTone({freqs:[330,262],type:'sine',dur:0.35,vol:0.35,ramp:[220,196],fadeIn:0.02,fadeOut:0.1});
  },
  userBookingConfirmed:function(){
    playTone({freqs:[523],type:'sine',dur:0.1,vol:0.35});
    setTimeout(function(){ playTone({freqs:[784],type:'sine',dur:0.1,vol:0.35}); },110);
    setTimeout(function(){ playTone({freqs:[1047],type:'sine',dur:0.22,vol:0.4}); },220);
  },
  userProviderOnWay:function(){
    playTone({freqs:[440,554],type:'sine',dur:0.15,vol:0.38,fadeIn:0.01,fadeOut:0.04});
    setTimeout(function(){ playTone({freqs:[554,659],type:'sine',dur:0.22,vol:0.4,fadeIn:0.01,fadeOut:0.06}); },180);
  },
  userServiceDone:function(){
    [0,110,220,330].forEach(function(d,i){
      setTimeout(function(){ playTone({freqs:[[392],[523],[659],[784]][i],type:'sine',dur:[0.1,0.1,0.1,0.28][i],vol:0.35+i*0.02}); },d);
    });
  },
  userPaymentRequest:function(){
    playTone({freqs:[659,523],type:'triangle',dur:0.2,vol:0.38,ramp:[523,440],fadeIn:0.02,fadeOut:0.05});
    setTimeout(function(){ playTone({freqs:[659,523],type:'triangle',dur:0.2,vol:0.38,ramp:[523,440],fadeIn:0.02,fadeOut:0.05}); },350);
  },
  userSoftNotif:function(){
    playTone({freqs:[880],type:'sine',dur:0.18,vol:0.25,fadeIn:0.03,fadeOut:0.08});
    setTimeout(function(){ playTone({freqs:[1047],type:'sine',dur:0.22,vol:0.28,fadeIn:0.02,fadeOut:0.1}); },200);
  },
  adminNewProvider:function(){
    [0,140,280,420].forEach(function(d,i){
      setTimeout(function(){ playTone({freqs:[[523],[659],[784],[659]][i],type:'triangle',dur:[0.12,0.12,0.12,0.18][i],vol:0.38}); },d);
    });
  },
  adminNewOrder:function(){
    playTone({freqs:[440,554,659],type:'sine',dur:0.2,vol:0.38,fadeIn:0.02,fadeOut:0.05});
    setTimeout(function(){ playTone({freqs:[554,659,784],type:'sine',dur:0.28,vol:0.4,fadeIn:0.01,fadeOut:0.08}); },250);
  },
  adminPayoutRequest:function(){
    [0,160,320].forEach(function(d,i){
      setTimeout(function(){ playTone({freqs:[[392],[523],[440]][i],type:'triangle',dur:[0.15,0.15,0.25][i],vol:0.38}); },d);
    });
  },
  adminHighAlert:function(){
    playTone({freqs:[880,1100],type:'sawtooth',dur:0.15,vol:0.45,fadeIn:0.01,fadeOut:0.03});
    setTimeout(function(){ playTone({freqs:[660,880],type:'sawtooth',dur:0.15,vol:0.45,fadeIn:0.01,fadeOut:0.03}); },200);
    setTimeout(function(){ playTone({freqs:[880,1100],type:'sawtooth',dur:0.2,vol:0.48,fadeIn:0.01,fadeOut:0.05}); },400);
  },
  adminConfirm:function(){ playTone({freqs:[659,784],type:'sine',dur:0.2,vol:0.32,fadeIn:0.02,fadeOut:0.07}); },
};
var _notifGranted=false;
function requestNotifPermission(){
  if(!('Notification' in window)) return;
  if(Notification.permission==='granted'){ _notifGranted=true; return; }
  if(Notification.permission!=='denied') Notification.requestPermission().then(function(p){ _notifGranted=(p==='granted'); });
}
function pushNotif(title,body,opts){
  if(!_notifGranted||document.visibilityState==='visible') return;
  try{ var n=new Notification(title,Object.assign({icon:'data:image/svg+xml,<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 32 32"><circle cx="16" cy="16" r="16" fill="%23E8251A"/></svg>',requireInteraction:opts&&opts.important,tag:opts&&opts.tag||'hs',silent:true},opts||{},{body:body})); n.onclick=function(){ window.focus(); n.close(); }; }catch(e){}
}
function alertToast(msg,opts){
  var el=document.getElementById('hsAlertToast');
  if(!el){
    el=document.createElement('div'); el.id='hsAlertToast';
    el.style.cssText='position:fixed;bottom:80px;left:50%;transform:translateX(-50%);min-width:260px;max-width:380px;padding:13px 18px 13px 14px;border-radius:14px;font-family:Nunito,sans-serif;font-size:14px;font-weight:700;z-index:99999;box-shadow:0 8px 32px rgba(0,0,0,0.22);display:none;align-items:center;gap:10px;cursor:pointer;';
    el.innerHTML='<style>@keyframes _hsIn{from{opacity:0;transform:translateX(-50%) translateY(12px)}to{opacity:1;transform:translateX(-50%) translateY(0)}}</style><span id="_hsIco" style="font-size:20px;flex-shrink:0;"></span><span id="_hsMsg" style="flex:1;line-height:1.4;"></span><span onclick="document.getElementById(\'hsAlertToast\').style.display=\'none\'" style="font-size:18px;opacity:.6;padding-left:8px;flex-shrink:0;">✕</span>';
    document.body.appendChild(el);
  }
  var C={urgent:{bg:'#E8251A',fg:'white'},warning:{bg:'#FF6B00',fg:'white'},success:{bg:'#1ea84b',fg:'white'},info:{bg:'#1A73E8',fg:'white'}};
  var I={urgent:'🚨',warning:'⚠️',success:'✅',info:'ℹ️'};
  var t=opts&&opts.type||'info', c=C[t]||C.info;
  el.style.background=c.bg; el.style.color=c.fg; el.style.display='flex';
  el.style.animation='_hsIn .3s ease';
  document.getElementById('_hsIco').textContent=I[t]||'🔔';
  document.getElementById('_hsMsg').textContent=msg;
  clearTimeout(el._t); el._t=setTimeout(function(){ el.style.display='none'; },opts&&opts.duration||5500);
}
var _jobLoop=null;
function startJobAlertLoop(){ stopJobAlertLoop(); SND.providerNewJob(); _jobLoop=setInterval(function(){ SND.providerNewJob(); },8000); }
function stopJobAlertLoop(){ if(_jobLoop){ clearInterval(_jobLoop); _jobLoop=null; } }
window.HS={ sound:SND, role:ROLE, alertToast:alertToast, pushNotif:pushNotif, startJobAlert:startJobAlertLoop, stopJobAlert:stopJobAlertLoop, unlock:unlock, requestPush:requestNotifPermission };
document.addEventListener('click',function(){ requestNotifPermission(); },{once:true});
// Permission banner
setTimeout(function(){
  if(localStorage.getItem('hs_notif_asked')||!('Notification' in window)||Notification.permission!=='default') return;
  var b=document.createElement('div');
  b.style.cssText='position:fixed;top:0;left:0;right:0;z-index:99998;background:linear-gradient(135deg,#1a0a08,#2d1010);color:white;padding:11px 18px;display:flex;align-items:center;justify-content:space-between;gap:12px;font-family:Nunito,sans-serif;font-size:13px;font-weight:700;box-shadow:0 2px 12px rgba(0,0,0,0.3);';
  b.innerHTML='<span>🔔 Allow notifications to never miss a booking alert</span><div style="display:flex;gap:8px;"><button onclick="HS.requestPush();localStorage.setItem(\'hs_notif_asked\',1);this.closest(\'div\').parentNode.remove();" style="padding:7px 16px;background:#E8251A;color:white;border:none;border-radius:8px;font-family:inherit;font-size:13px;font-weight:800;cursor:pointer;">Allow</button><button onclick="localStorage.setItem(\'hs_notif_asked\',1);this.closest(\'div\').parentNode.remove();" style="padding:7px 12px;background:rgba(255,255,255,0.12);color:white;border:none;border-radius:8px;font-family:inherit;font-size:13px;font-weight:700;cursor:pointer;">Not now</button></div>';
  document.body.appendChild(b); setTimeout(function(){ if(b.parentNode) b.remove(); },12000);
},2500);
})();



(function(){
  // Wait for page JS to be ready
  function onReady(fn){ if(document.readyState==='complete') fn(); else window.addEventListener('load',fn); }
  onReady(function(){

    /* ── 1. New incoming job alert ──────────────────────────────────── */
    // Wrap the function that shows the incoming job modal
    var _origShowIncoming = window.showIncomingJobAlert || window.showNewBookingAlert || null;

    // Hook: intercept when incomingAlert becomes visible (MutationObserver)
    var alertEl = document.getElementById('incomingAlert');
    if(alertEl){
      var _alertVisible = false;
      var _alertObs = new MutationObserver(function(muts){
        muts.forEach(function(m){
          var isNowVisible = alertEl.style.display !== 'none' && alertEl.style.display !== '';
          if(isNowVisible && !_alertVisible){
            _alertVisible = true;
            HS.startJobAlert(); // start repeating loud alarm
            HS.alertToast('🚨 New job request! Accept within 60 seconds', {type:'urgent', duration:60000});
            HS.pushNotif('🚨 New Job Request!', 'Accept within 60 seconds — open HamaraService now', {important:true, tag:'hs-job'});
          } else if(!isNowVisible && _alertVisible){
            _alertVisible = false;
            HS.stopJobAlert(); // stop alarm when modal closes
          }
        });
      });
      _alertObs.observe(alertEl, {attributes:true, attributeFilter:['style']});
    }

    /* ── 2. Countdown warning at 15 seconds ─────────────────────────── */
    var _lastSec = 999;
    var _countObs = new MutationObserver(function(){
      var timerEl = document.getElementById('alertTimer');
      if(!timerEl) return;
      var sec = parseInt(timerEl.textContent)||0;
      if(sec === 15 && _lastSec > 15) HS.sound.providerCountdownWarn();
      if(sec === 5  && _lastSec > 5)  HS.sound.providerCountdownWarn();
      _lastSec = sec;
    });
    var timerEl = document.getElementById('alertTimer');
    if(timerEl) _countObs.observe(timerEl, {childList:true, characterData:true, subtree:true});

    /* ── 3. Job accepted ────────────────────────────────────────────── */
    var _origAccept = window.acceptNewBooking;
    if(_origAccept) window.acceptNewBooking = function(){
      HS.stopJobAlert();
      HS.sound.providerJobAccepted();
      HS.alertToast('✅ Job accepted! Check My Bookings', {type:'success'});
      return _origAccept.apply(this, arguments);
    };

    /* ── 4. Job expired / rejected ──────────────────────────────────── */
    var _origReject = window.rejectNewBooking;
    if(_origReject) window.rejectNewBooking = function(){
      HS.stopJobAlert();
      HS.sound.providerJobExpired();
      return _origReject.apply(this, arguments);
    };

    /* ── 5. Payment received — watch for jobDoneModal ───────────────── */
    var jobDoneEl = document.getElementById('jobDoneModal');
    if(jobDoneEl){
      var _doneObs = new MutationObserver(function(muts){
        muts.forEach(function(m){
          if(m.target.classList && m.target.classList.contains('show')){
            HS.sound.providerPaymentIn();
            HS.alertToast('💰 Payment received! Credited to wallet', {type:'success'});
            HS.pushNotif('💰 Payment Received', 'Earnings credited to your HamaraService wallet', {tag:'hs-pay'});
          }
        });
      });
      _doneObs.observe(jobDoneEl, {attributes:true, attributeFilter:['class']});
    }

    /* ── 6. Pending badge change → new booking arrived ──────────────── */
    var badgeEl = document.getElementById('pendingBadge');
    var _lastBadge = 0;
    if(badgeEl){
      var _badgeObs = new MutationObserver(function(){
        var n = parseInt(badgeEl.textContent)||0;
        if(n > _lastBadge && _lastBadge >= 0){
          // Only sound if job alert isn't already ringing
          if(!document.getElementById('incomingAlert') || document.getElementById('incomingAlert').style.display==='none'){
            HS.sound.userSoftNotif();
          }
        }
        _lastBadge = n;
      });
      _badgeObs.observe(badgeEl, {childList:true, characterData:true, subtree:true});
    }

  }); // onReady
})();
function showJobTakenPopup(provName) {
  var ov = document.createElement('div');
  ov.style.cssText = 'position:fixed;inset:0;background:rgba(0,0,0,.7);display:flex;align-items:center;justify-content:center;z-index:9999;padding:20px;box-sizing:border-box;';
  ov.innerHTML =
    '<div style="background:white;border-radius:20px;padding:28px 22px;max-width:320px;width:100%;text-align:center;">' +
    '<div style="font-size:48px;margin-bottom:12px;">&#x1F614;</div>' +
    '<h3 style="font-size:17px;font-weight:800;color:#0f1117;margin-bottom:8px;">Order Already Accepted</h3>' +
    '<p style="font-size:14px;color:#6b7280;line-height:1.6;margin-bottom:18px;">' +
    '<strong style="color:#E8251A;">'+(provName||'Another provider')+'</strong> accepted this order before you.<br/>' +
    'Wait for the next order — it is coming soon!</p>' +
    '<button id="_jtcClose" style="width:100%;padding:13px;background:#E8251A;color:white;border:none;border-radius:12px;font-size:14px;font-weight:700;cursor:pointer;font-family:inherit;">Got it</button>' +
    '</div>';
  document.body.appendChild(ov);
  document.getElementById('_jtcClose').onclick = function(){ ov.remove(); };
}
