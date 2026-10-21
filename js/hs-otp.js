/* HamaraService — email verification code (OTP) step for web registration
 *
 *   HS_EmailOtp.start({
 *     el: containerElement,          // the OTP panel is rendered into this
 *     api: function (ep, action, body) → Promise<{success, data, error}>,
 *     email: 'you@example.com',
 *     purpose: 'customer_register' | 'provider_register',
 *     verifyText: 'Verify & Create Account',
 *     onSendError: function (msg) {},        // first code could not be sent (stay on the form)
 *     onShown: function () {},               // code sent, panel visible (hide the form)
 *     onVerified: function (otpToken, ui) {},// ui.busy(bool), ui.error(msg)
 *     onChangeEmail: function () {}          // "Change email" — show the form again
 *   });
 *   HS_EmailOtp.token(email, purpose) → a still-valid otp_token for this email (or '')
 *   HS_EmailOtp.forget(email, purpose) / HS_EmailOtp.close(el)
 *
 * Server: api/otp.php (send / verify). The code is only ever e-mailed.
 */
(function () {
  var cache = {};          // purpose|email → {token, exp}
  var timer = null;

  function esc(v) { return String(v == null ? '' : v).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
  function injectCss() {
    if (document.getElementById('hsOtpCss')) return;
    var s = document.createElement('style'); s.id = 'hsOtpCss';
    s.textContent =
      '.hs-otp{text-align:center}' +
      '.hs-otp-ico{width:56px;height:56px;border-radius:50%;background:#eef5f6;display:flex;align-items:center;justify-content:center;font-size:26px;margin:4px auto 10px}' +
      '.hs-otp-title{font-size:19px;font-weight:900;margin-bottom:4px}' +
      '.hs-otp-sub{font-size:13.5px;color:var(--muted,#6b7f87);margin-bottom:14px;line-height:1.5;word-break:break-word}' +
      '.hs-otp-err{color:var(--red,#dc2626);font-size:13px;min-height:16px;margin-bottom:8px}' +
      '.hs-otp-code{text-align:center;font-size:26px !important;font-weight:900;letter-spacing:12px;padding-left:24px !important}' +
      '.hs-otp-links{display:flex;justify-content:space-between;gap:10px;margin-top:14px;font-size:13.5px}' +
      '.hs-otp-links button{background:none;border:none;padding:4px 0;font:inherit;font-weight:800;color:var(--teal,#0f4c5c);cursor:pointer}' +
      '.hs-otp-links button:disabled{color:var(--muted,#6b7f87);font-weight:600;cursor:default}' +
      '.hs-otp-note{font-size:12px;color:var(--muted,#6b7f87);margin-top:12px}';
    document.head.appendChild(s);
  }
  function key(email, purpose) { return purpose + '|' + String(email || '').toLowerCase(); }

  function token(email, purpose) {
    var c = cache[key(email, purpose)];
    return c && c.exp > Date.now() + 60000 ? c.token : '';
  }

  function start(o) {
    injectCss();
    var email = String(o.email || '').trim().toLowerCase();
    o.api('otp.php', 'send', { email: email, purpose: o.purpose }).then(function (res) {
      if (!res || !res.success) { o.onSendError((res && res.error) || 'Could not send email, please try again'); return; }
      render(o, email, (res.data && res.data.resend_in) || 60);
      if (o.onShown) o.onShown();
    }).catch(function () { o.onSendError('Network error'); });
  }

  function render(o, email, wait) {
    var el = o.el;
    el.innerHTML =
      '<div class="hs-otp">' +
      '<div class="hs-otp-ico">✉️</div>' +
      '<div class="hs-otp-title">Verify your email</div>' +
      '<div class="hs-otp-sub">Enter the 6-digit code we sent to<br><b>' + esc(email) + '</b></div>' +
      '<div class="hs-otp-err" id="hsOtpErr"></div>' +
      '<input class="inp hs-otp-code" id="hsOtpCode" inputmode="numeric" pattern="[0-9]*" autocomplete="one-time-code" maxlength="6" placeholder="------" aria-label="6-digit code">' +
      '<button class="btn" id="hsOtpVerify" type="button">' + esc(o.verifyText || 'Verify') + '</button>' +
      '<div class="hs-otp-links"><button type="button" id="hsOtpResend" disabled></button>' +
      '<button type="button" id="hsOtpChange">Change email</button></div>' +
      '<div class="hs-otp-note">Valid for 10 minutes. Not in your inbox? Check the spam folder.</div>' +
      '</div>';
    el.classList.remove('hide'); el.style.display = '';
    var $ = function (id) { return document.getElementById(id); };
    var inp = $('hsOtpCode'), btn = $('hsOtpVerify'), rs = $('hsOtpResend');
    var label = btn.textContent;
    var ui = {
      busy: function (b) { btn.disabled = !!b; btn.textContent = b ? 'Please wait…' : label; },
      error: function (m) { $('hsOtpErr').textContent = m || ''; }
    };
    function countdown(sec) {
      clearInterval(timer);
      var left = Math.max(0, Math.ceil(sec));
      function tick() {
        if (!document.body.contains(rs)) { clearInterval(timer); return; }
        if (left <= 0) { clearInterval(timer); rs.disabled = false; rs.textContent = 'Resend code'; return; }
        rs.disabled = true; rs.textContent = 'Resend code in ' + left + 's'; left--;
      }
      tick(); timer = setInterval(tick, 1000);
    }
    function verify() {
      var code = inp.value.replace(/\D/g, '');
      if (code.length !== 6) { ui.error('Enter the 6-digit code from the email'); inp.focus(); return; }
      ui.error(''); ui.busy(true);
      o.api('otp.php', 'verify', { email: email, purpose: o.purpose, code: code }).then(function (res) {
        if (res && res.success && res.data && res.data.otp_token) {
          cache[key(email, o.purpose)] = { token: res.data.otp_token, exp: Date.now() + ((res.data.expires_in || 1800) * 1000) };
          clearInterval(timer);
          o.onVerified(res.data.otp_token, ui);
        } else { ui.busy(false); ui.error((res && res.error) || 'Verification failed'); inp.select(); }
      }).catch(function () { ui.busy(false); ui.error('Network error'); });
    }
    inp.addEventListener('input', function () {
      var v = inp.value.replace(/\D/g, '').slice(0, 6);
      if (v !== inp.value) inp.value = v;
      if (v.length === 6 && !btn.disabled) verify();
    });
    inp.addEventListener('keydown', function (e) { if (e.key === 'Enter') verify(); });
    btn.addEventListener('click', verify);
    rs.addEventListener('click', function () {
      rs.disabled = true; ui.error('');
      o.api('otp.php', 'send', { email: email, purpose: o.purpose }).then(function (res) {
        if (res && res.success) { countdown((res.data && res.data.resend_in) || 60); inp.value = ''; inp.focus(); flash('New code sent'); }
        else { ui.error((res && res.error) || 'Could not send email, please try again'); countdown((res && res.retry_after) || 0); }
      }).catch(function () { rs.disabled = false; ui.error('Network error'); });
    });
    $('hsOtpChange').addEventListener('click', function () {
      clearInterval(timer); el.innerHTML = ''; el.classList.add('hide');
      if (o.onChangeEmail) o.onChangeEmail();
    });
    function flash(m) { var s = el.querySelector('.hs-otp-note'); if (s) { var t = s.textContent; s.textContent = '✓ ' + m; setTimeout(function () { s.textContent = t; }, 2500); } }
    countdown(wait);
    setTimeout(function () { inp.focus(); }, 50);
  }

  function close(el) { clearInterval(timer); if (el) { el.innerHTML = ''; el.classList.add('hide'); } }

  function forget(email, purpose) { delete cache[key(email, purpose)]; }

  window.HS_EmailOtp = { start: start, token: token, forget: forget, close: close };
})();
