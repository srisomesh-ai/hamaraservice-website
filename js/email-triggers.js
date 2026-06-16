// ============================================================
//  js/email-triggers.js
//  Call this from provider-portal.js, user-panel.js, admin.js
//  Include AFTER Firebase scripts in each HTML file:
//  <script src="js/email-triggers.js"></script>
// ============================================================

var EMAIL_API = '/api/email.php';

// Send any email type — internal helper
function sendEmail(payload, onSuccess, onError) {
  var xhr = new XMLHttpRequest();
  xhr.open('POST', EMAIL_API, true);
  xhr.setRequestHeader('Content-Type', 'application/json');
  xhr.timeout = 10000;
  xhr.onload = function() {
    try {
      var res = JSON.parse(xhr.responseText);
      if (res.success) { if (onSuccess) onSuccess(res); }
      else { console.warn('Email not sent:', res.error); if (onError) onError(res.error); }
    } catch(e) { console.warn('Email parse error:', e); }
  };
  xhr.onerror = xhr.ontimeout = function() {
    console.warn('Email API unreachable');
  };
  xhr.send(JSON.stringify(payload));
}

// ── 1. Customer welcome (call after Firebase user created) ────────────────────
// Usage: emailCustomerWelcome(fbUser.displayName, fbUser.email)
function emailCustomerWelcome(name, email) {
  sendEmail({ type: 'customer_welcome', name: name, email: email });
}

// ── 2. Provider welcome / pending (call after submitRegistration) ─────────────
// Usage: emailProviderWelcome(name, email, providerId, service)
function emailProviderWelcome(name, email, id, service) {
  sendEmail({ type: 'provider_welcome', name: name, email: email, id: id, service: service });
}

// ── 3. Admin notification (call same time as #2) ──────────────────────────────
// Usage: emailAdminProviderPending(provider)
function emailAdminProviderPending(prov) {
  sendEmail({
    type:    'admin_provider_pending',
    name:    prov.name    || '',
    email:   prov.email   || '',
    id:      prov.id      || '',
    phone:   prov.phone   || '',
    service: Array.isArray(prov.services) ? prov.services.map(function(s){ return s.name||s; }).join(', ') : (prov.service||''),
    city:    prov.city    || prov.address || ''
  });
}

// ── 4. Provider approved (call from admin panel when clicking Approve) ─────────
// Usage: emailProviderApproved(name, email, id)
function emailProviderApproved(name, email, id) {
  sendEmail({ type: 'provider_approved', name: name, email: email, id: id });
}
