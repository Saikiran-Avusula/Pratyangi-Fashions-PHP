/*
 * Mobile one-time-code sign-in and sign-up on /login and /signup (a PHP-only addition, see docs/API_CONTRACT.md "PHP-only additions"), in
 * plain JavaScript: no libraries, no inline scripts, no external requests. It runs after auth.js and uses what auth.js shares as
 * window.pfAuth (field helpers, the six-box code input, the welcome and the redirect). Markup comes from the server (AuthHtml::modeTabs
 * and otpForm).
 *
 * One flow for both pages: number -> Send OTP -> code -> Verify. A number that has an account signs in; a new number gets an account when
 * the code is right. No name or email is asked. Then the welcome, and the landing page "/" (or a safe ?next=).
 * Calls: POST /api/v1/auth/otp/request {mobile} and /verify {mobile, code} on /login; /auth/otp/signup/request and /verify on /signup.
 */
(function () {
  'use strict';

  var A = window.pfAuth;
  if (!A) return;
  var win = window;
  var card = A.card;
  var form = A.qs('[data-pf="otp-form"]', card);
  var tabs = A.qsa('[data-pf-mode]', card);
  var emailForm = A.qs('[data-pf-panel="email"]', card);
  if (!form || !emailForm || tabs.length !== 2) return;

  var signup = form.getAttribute('data-pf-signup') === '1';
  var GENERIC = 'Something went wrong. Please try again.';
  var UNREACHABLE = 'We could not reach the server. Check your connection and try again.';
  var RESEND_SECONDS = 45;

  var mobile = A.fieldIn(form, 'otp-mobile');
  var code = A.fieldIn(form, 'otp-code');
  var codeBox = A.initOtp(code);
  var codeWrap = A.qs('[data-pf="otp-code-wrap"]', form);
  var sent = A.qs('[data-pf="otp-sent"]', form);
  var terms = A.qs('#otp-terms', form); // /signup only
  var termsWrap = A.qs('[data-pf="otp-terms"]', form);
  var submit = A.qs('[data-pf="otp-submit"]', form);
  var resend = A.qs('[data-pf="otp-resend"]', form);
  var change = A.qs('[data-pf="otp-change"]', form);

  var state = { step: 'number', busy: false, errors: {}, formError: '', notice: '', resendIn: 0, number: '' };
  var countdown = 0;

  /* ------------------------------------------------------------------ the API */

  /** POST to /api/v1: cookie included, JSON body. Resolves {status, data}; rejects with an AuthError that also carries status and retryAfter. */
  function post(path, body) {
    return win.fetch('/api/v1' + path, {
      method: 'POST',
      credentials: 'include',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(body)
    }).then(function (response) {
      var retry = parseInt(response.headers.get('Retry-After') || '', 10);
      return response.json().catch(function () { return {}; }).then(function (data) {
        if (!response.ok) {
          var error = new A.AuthError(data.message != null ? data.message : GENERIC, data.field, data.errors);
          error.status = response.status;
          error.retryAfter = isNaN(retry) ? 0 : retry;
          throw error;
        }
        return { status: response.status, data: data };
      });
    }, function () {
      throw new A.AuthError(UNREACHABLE);
    });
  }

  /* ------------------------------------------------------------------ painting */

  function paintResend() {
    resend.disabled = state.resendIn > 0 || state.busy;
    resend.textContent = state.resendIn > 0 ? 'Resend code in ' + state.resendIn + 's' : 'Resend code';
  }
  function startCountdown(seconds) {
    win.clearTimeout(countdown);
    state.resendIn = seconds;
    (function tick() {
      paintResend();
      if (state.resendIn > 0) {
        countdown = win.setTimeout(function () { state.resendIn -= 1; tick(); }, 1000);
      }
    })();
  }
  function buttonText() {
    if (state.step === 'number') return 'Send OTP';
    return signup ? 'Verify & Create Account' : 'Verify & Sign In';
  }
  function paintTerms(message) {
    if (!terms) return;
    var old = A.qs('p.neurva_error', termsWrap);
    if (old) termsWrap.removeChild(old);
    A.qs('.neurva_check', termsWrap).classList.toggle('neurva_checkInvalid', !!message);
    if (message) {
      var p = win.document.createElement('p');
      p.className = 'neurva_error';
      p.id = 'otp-terms-error';
      p.setAttribute('role', 'alert');
      p.textContent = message;
      termsWrap.appendChild(p);
      terms.setAttribute('aria-invalid', 'true');
      terms.setAttribute('aria-describedby', 'otp-terms-error');
    } else {
      terms.removeAttribute('aria-invalid');
      terms.removeAttribute('aria-describedby');
    }
  }
  function paintAll() {
    A.showField(mobile, state.errors.mobile || '', '');
    A.showField(code, state.errors.code || '', '');
    A.showTop(form, state.formError, state.notice);
  }
  function setBusy(busy) {
    state.busy = busy;
    A.paintButton(submit, busy, buttonText(), state.step === 'number' ? 'Sending OTP…' : 'Verifying…');
    paintResend();
  }
  function showStep(step) {
    state.step = step;
    var onCode = step !== 'number';
    codeWrap.hidden = !onCode;
    mobile.input.readOnly = onCode;
    A.paintButton(submit, false, buttonText(), '');
  }
  function run(task) {
    state.formError = '';
    state.notice = '';
    setBusy(true);
    A.showTop(form, '', '');
    return task().catch(function (error) {
      var d = A.describeError(error);
      state.errors = { mobile: d.errors.mobile, code: d.errors.code };
      if (error && error.status === 429 && error.retryAfter && state.step !== 'number') startCountdown(error.retryAfter);
      // a message that belongs to an input is shown at that input; everything else at the top of the form
      if (!d.errors.mobile && !d.errors.code) {
        if (state.step !== 'number' && error && error.status === 400) state.errors.code = d.message;
        else state.formError = d.message;
      }
      paintAll();
      // no account to sign in to (404) or already registered (409): point at the other page
      if (state.step === 'number' && error && (error.status === 404 || error.status === 409)) {
        var alertBox = A.qs('p.neurva_alert', form);
        var link = win.document.createElement('a');
        link.className = 'neurva_inlineLink';
        link.href = signup ? '/login' : '/signup';
        link.textContent = signup ? ' Sign in' : ' Create account';
        if (alertBox) alertBox.appendChild(link);
      }
    }).then(function () { setBusy(false); });
  }

  /* ------------------------------------------------------------------ the steps */

  function sendCode(again) {
    var number = again ? state.number : A.normaliseMobile(mobile.input.value);
    return post(signup ? '/auth/otp/signup/request' : '/auth/otp/request', { mobile: number }).then(function (result) {
      state.number = number;
      sent.textContent = 'We sent a 6-digit code to ' + A.masked(number) + '.';
      codeBox.clear();
      var coming = state.step === 'number';
      if (coming) showStep('code');
      state.errors = {};
      startCountdown(result.data && result.data.resendAfter ? result.data.resendAfter : RESEND_SECONDS);
      code.input.focus();
    });
  }

  function verifyCode() {
    return post(signup ? '/auth/otp/signup/verify' : '/auth/otp/verify', { mobile: state.number, code: code.input.value }).then(function (result) {
      var offers = A.qs('#otp-offers', form);
      if (result.status === 201 && offers && offers.checked && win.PratyangiOffers) win.PratyangiOffers.save('signup', true);
      A.finish(result.data.user, result.status === 201);
    });
  }

  form.addEventListener('submit', function (event) {
    event.preventDefault();
    if (state.busy) return;
    if (state.step === 'number') {
      var found = { mobile: A.validateMobile(mobile.input.value) || undefined };
      if (signup) found.terms = terms.checked ? undefined : 'Please accept the terms to continue.';
      state.errors = found;
      paintAll();
      paintTerms(found.terms);
      if (found.mobile || found.terms) { A.focusFirstInvalid(form); return; }
      run(function () { return sendCode(false); });
      return;
    }
    var problem = A.validateCode(code.input.value);
    state.errors = { code: problem || undefined };
    paintAll();
    if (problem) { A.focusFirstInvalid(form); return; }
    run(verifyCode);
  });

  resend.addEventListener('click', function () {
    if (state.busy || state.resendIn > 0) return;
    run(function () { return sendCode(true); });
  });
  change.addEventListener('click', function () {
    win.clearTimeout(countdown);
    state.resendIn = 0;
    state.errors = {};
    state.formError = '';
    state.notice = '';
    codeBox.clear();
    showStep('number');
    paintAll();
    mobile.input.focus();
  });

  mobile.input.addEventListener('input', function () { state.errors.mobile = undefined; A.showField(mobile, '', ''); });
  code.input.addEventListener('input', function () { state.errors.code = undefined; A.showField(code, '', ''); });
  if (terms) terms.addEventListener('change', function () { paintTerms(''); });

  /* ------------------------------------------------------------------ the Email | Mobile OTP switch */

  function select(mode, focus) {
    var otp = mode === 'otp';
    tabs.forEach(function (tab) {
      var on = tab.getAttribute('data-pf-mode') === mode;
      tab.setAttribute('aria-selected', on ? 'true' : 'false');
      tab.tabIndex = on ? 0 : -1;
      tab.className = on ? 'pf_mode pf_modeOn' : 'pf_mode';
      if (on && focus) tab.focus();
    });
    emailForm.hidden = otp;
    form.hidden = !otp;
  }
  tabs.forEach(function (tab) {
    tab.addEventListener('click', function () { select(tab.getAttribute('data-pf-mode'), false); });
    tab.addEventListener('keydown', function (event) {
      if (event.key !== 'ArrowLeft' && event.key !== 'ArrowRight') return;
      event.preventDefault();
      select(tab.getAttribute('data-pf-mode') === 'email' ? 'otp' : 'email', true);
    });
  });
  select('email', false);
  showStep('number');
})();
