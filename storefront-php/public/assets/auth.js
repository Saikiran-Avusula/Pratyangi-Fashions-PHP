/*
 * Behaviour of the sign-in pages (/login, /signup, /forgot-password, /verify-email), in plain JavaScript: no libraries, no inline
 * scripts, no external requests. It follows the web app's React code (components/auth/*, lib/auth/*, the four *View.tsx files):
 * the same checks and messages, the same /api/v1 calls (same-origin URLs, cookie included, JSON body), the same field errors and the
 * same redirect after success. Markup comes from the server (app/Views/auth); the later states are <template> elements cloned here.
 * See docs/PAGES_PARITY.md. PHP-only changes (docs/API_CONTRACT.md 11.3): sign-up ends in a 6-digit email code, forgot-password has an
 * Email code | Mobile code switch, sign-in names an unknown email, and every success goes to the landing page (or a safe ?next=).
 */
(function () {
  'use strict';

  var doc = document;
  var win = window;
  var root = doc.querySelector('[data-pf-auth]');
  if (!root) return;
  var page = root.getAttribute('data-pf-auth');
  var card = doc.querySelector('[data-pf="auth-card"]');
  if (!card) return;

  var reduce = !!(win.matchMedia && win.matchMedia('(prefers-reduced-motion: reduce)').matches);
  var canAnimate = typeof Element !== 'undefined' && typeof Element.prototype.animate === 'function';
  var EXPO = 'cubic-bezier(0.22, 1, 0.36, 1)'; // easeOutExpo of the web's motion presets

  function qs(sel, scope) { return (scope || doc).querySelector(sel); }
  function qsa(sel, scope) { return Array.prototype.slice.call((scope || doc).querySelectorAll(sel)); }
  function make(tag, className) {
    var el = doc.createElement(tag);
    if (className) el.className = className;
    return el;
  }
  function has(obj, key) { return Object.prototype.hasOwnProperty.call(obj, key); }
  function template(name) {
    var tpl = qs('template[data-pf-tpl="' + name + '"]', root);
    return tpl.content.cloneNode(true);
  }

  /* ------------------------------------------------------------------ checks (lib/auth/validation.ts) */

  var OTP_LENGTH = 6;
  var MOBILE_UNAVAILABLE = 'To sign in with a mobile number, use the Mobile OTP tab.';
  var STRENGTH_LABELS = ['', 'Weak', 'Fair', 'Good', 'Strong'];
  var RULES = [
    function (v) { return v.length >= 8; },
    function (v) { return /[A-Za-z]/.test(v); },
    function (v) { return /\d/.test(v); }
  ];

  function validateEmail(value) {
    var email = value.trim();
    if (!email) return 'Enter your email address.';
    if (email.length > 254) return 'That email address is too long.';
    if (!/^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/.test(email)) return 'Enter a valid email address, like name@example.com.';
    return '';
  }
  /** "empty", "email" or "mobile": a mobile number has no "@" and only phone characters (or is just "+"). */
  function detectIdentifierKind(value) {
    var text = value.trim();
    if (!text) return 'empty';
    if (text.indexOf('@') !== -1) return 'email';
    return /^\+?[\d\s\-()]*$/.test(text) && (text === '+' || /\d/.test(text)) ? 'mobile' : 'email';
  }
  function validateIdentifier(value) {
    var kind = detectIdentifierKind(value);
    if (kind === 'empty') return 'Enter your email address or mobile number.';
    if (kind === 'mobile') return document.querySelector('[data-pf-sms="0"]') ? 'Mobile sign-in is coming soon. Please use your email address.' : MOBILE_UNAVAILABLE;
    var message = validateEmail(value);
    return message.indexOf('Enter a valid email address') === 0 ? 'Enter a valid email, like name@example.com.' : message;
  }
  function validateName(value) {
    var name = value.trim();
    if (!name) return 'Enter your full name.';
    if (name.length < 2) return 'Enter your full name (at least 2 characters).';
    if (name.length > 80) return 'That name is too long (80 characters at most).';
    return '';
  }
  function validatePassword(value) {
    if (!value) return 'Create a password.';
    if (value.length < 8) return 'Password must be at least 8 characters.';
    if (value.length > 128) return 'Password is too long (128 characters at most).';
    if (!/[A-Za-z]/.test(value)) return 'Password needs at least one letter.';
    if (!/\d/.test(value)) return 'Password needs at least one number.';
    return '';
  }
  function validateConfirm(password, confirm) {
    if (!confirm) return 'Re-enter your password.';
    return confirm === password ? '' : 'The two passwords do not match.';
  }
  /** +91XXXXXXXXXX from 10 digits with an optional +91 or 91 and spaces, first digit 6-9; '' when it is not one (same rule as app/Auth/Mobile.php). */
  function normaliseMobile(value) {
    var match = /^(?:\+?91)?([6-9]\d{9})$/.exec(String(value).replace(/ /g, '').trim());
    return match ? '+91' + match[1] : '';
  }
  function validateMobile(value) {
    if (!String(value).trim()) return 'Enter your mobile number.';
    return normaliseMobile(value) ? '' : 'Enter a valid 10-digit mobile number.';
  }
  function masked(number) { return '+91 ******' + number.slice(-4); }
  function validateCode(value) {
    if (!value) return 'Enter the 6-digit code.';
    if (value.length !== OTP_LENGTH || !/^\d+$/.test(value)) return 'The code is 6 digits.';
    return '';
  }
  function passwordStrength(value) {
    if (!value) return 0;
    var score = 0;
    if (value.length >= 8) score++;
    if (/[a-z]/.test(value) && /[A-Z]/.test(value)) score++;
    if (/\d/.test(value)) score++;
    if (/[^A-Za-z0-9]/.test(value) || value.length >= 14) score++;
    return Math.max(1, score);
  }
  /**
   * Where to go after a sign-in: the landing page "/" unless the server found a safe same-site path in ?next= (app/Web/SafeNext.php:
   * one leading "/", not "//", no scheme, no backslash, no control character or space). The server prints its answer into
   * data-pf-next; this repeats the same checks so a changed page cannot send the visitor to another site.
   */
  function safeNext(value) {
    return value === '/checkout' ? value : '/';
  }

  /* ------------------------------------------------------------------ the API (lib/auth/apiAuth.ts) */

  var GENERIC = 'Something went wrong. Please try again.';

  /** An expected, customer-facing failure; `field` and `errors` say which inputs it belongs to. */
  function AuthError(message, field, errors, code) {
    this.name = 'AuthError';
    this.message = message;
    this.field = field;
    this.errors = errors;
    this.code = code;
  }
  AuthError.prototype = Object.create(Error.prototype);
  AuthError.prototype.constructor = AuthError;

  /** Same-origin call to /api/v1: cookie included, JSON body only when there is one. Resolves with the JSON (or undefined for 204). */
  function call(path, init) {
    init = init || {};
    var options = { method: init.method || 'GET', credentials: 'include' };
    if (init.body) {
      options.headers = { 'Content-Type': 'application/json' };
      options.body = JSON.stringify(init.body);
    }
    return win.fetch('/api/v1' + path, options).then(function (response) {
      if (response.status === 204) return undefined;
      return response.json().catch(function () { return {}; }).then(function (data) {
        if (!response.ok) {
          var failure = new AuthError(data.message != null ? data.message : GENERIC, data.field, data.errors, data.code);
          failure.status = response.status;
          failure.retryAfter = data.retryAfter || parseInt(response.headers.get('Retry-After') || '', 10) || 0;
          throw failure;
        }
        return data;
      });
    }, function () {
      throw new AuthError('We could not reach the server. Check your connection and try again.');
    });
  }

  /** What a failed call tells the form: a message for the whole form and one message per input when the server named them. */
  function describeError(error) {
    if (!(error instanceof AuthError)) return { message: GENERIC, errors: {} };
    var errors = {};
    if (error.errors && typeof error.errors === 'object') {
      Object.keys(error.errors).forEach(function (key) { errors[key] = error.errors[key]; });
    }
    if (error.field) {
      errors[error.field] = error.errors && error.errors[error.field] != null ? error.errors[error.field] : error.message;
    }
    return { message: error.message, errors: errors };
  }

  /* ------------------------------------------------------------------ small DOM helpers */

  /** A field made by AuthHtml: its wrapper, input and the element that gets the "invalid" class. */
  function fieldIn(scope, id) {
    var input = qs('#' + id, scope);
    if (!input) return null;
    var control = input.closest('.neurva_otp') || input.closest('.neurva_control');
    var wrap = input.closest('.neurva_field');
    return { input: input, wrap: wrap, control: control, slot: qs('.neurva_messageSlot', wrap), invalid: control.classList.contains('neurva_otp') ? 'neurva_otpInvalid' : 'neurva_invalid' };
  }

  /** Shows the error (or else the hint) under a field, like TextField/PasswordField/OtpField. Text is set with textContent only. */
  function showField(field, error, hint, hintRole) {
    if (!field) return;
    var host = field.slot || field.wrap;
    qsa('p.neurva_error, p.neurva_hint', host).forEach(function (p) { p.parentNode.removeChild(p); });
    var describedBy = '';
    if (hint && !error) {
      var h = make('p', 'neurva_hint');
      h.id = field.input.id + '-hint';
      if (hintRole) h.setAttribute('role', hintRole);
      h.textContent = hint;
      host.appendChild(h);
      describedBy = h.id;
    }
    if (error) {
      var e = make('p', 'neurva_error');
      e.id = field.input.id + '-error';
      e.setAttribute('role', 'alert');
      e.textContent = error;
      host.appendChild(e);
      describedBy = e.id;
    }
    field.control.classList.toggle(field.invalid, !!error);
    if (error) field.input.setAttribute('aria-invalid', 'true'); else field.input.removeAttribute('aria-invalid');
    if (describedBy) field.input.setAttribute('aria-describedby', describedBy); else field.input.removeAttribute('aria-describedby');
  }

  /** The red alert and the notice at the top of a form (alert first). */
  function showTop(form, alertText, noticeText) {
    qsa('p.neurva_alert:not(.pf_lock), p.neurva_notice:not(.pf_lock)', form).forEach(function (p) { if (p.parentNode === form) form.removeChild(p); });
    var anchor = form.firstChild;
    if (noticeText) {
      var n = make('p', 'neurva_notice');
      n.setAttribute('role', 'status');
      n.textContent = noticeText;
      form.insertBefore(n, anchor);
    }
    if (alertText) {
      var a = make('p', 'neurva_alert');
      a.setAttribute('role', 'alert');
      a.textContent = alertText;
      form.insertBefore(a, form.firstChild);
    }
  }

  /** A submit button in its idle or busy look (spinner + busy text, disabled). */
  function paintButton(button, busy, idleText, busyText) {
    button.disabled = busy || button.hasAttribute('data-pf-locked');
    button.textContent = '';
    if (busy) {
      var spinner = make('span', 'neurva_spinner');
      spinner.setAttribute('aria-hidden', 'true');
      button.appendChild(spinner);
      button.appendChild(doc.createTextNode(busyText));
    } else {
      button.textContent = idleText;
    }
  }

  /* ------------------------------------------------------------------ rate-limit wait (HTTP 429), shared by every form */

  /** Seconds as "2:45". */
  function clock(seconds) { return Math.floor(seconds / 60) + ':' + (seconds % 60 < 10 ? '0' : '') + (seconds % 60); }
  /** The end time of a wait, kept per page in sessionStorage so a reload carries on. Read with no value, clear with 0. */
  function waitEnd(key, value) {
    try {
      if (value === undefined) return Number(win.sessionStorage.getItem(key)) || 0;
      if (value) win.sessionStorage.setItem(key, String(value)); else win.sessionStorage.removeItem(key);
    } catch (e) { /* storage blocked: the wait still counts down, it just does not survive a reload */ }
    return 0;
  }
  /**
   * Holds the buttons off after a 429 and shows "Too many attempts. Try again in 2:45" counting down (aria-live polite), then "You can try
   * again now" and the buttons back. `host()` is the form the line goes into now; `buttons()` the submit buttons to hold. A wait still
   * running after a reload resumes at once. start(seconds) begins a wait.
   */
  function rateLock(key, host, buttons) {
    var timer = 0;
    var line = make('p', 'neurva_alert pf_lock');
    line.setAttribute('aria-live', 'polite');
    function hold(on) {
      buttons().forEach(function (b) {
        if (!b) return;
        if (on) b.setAttribute('data-pf-locked', ''); else b.removeAttribute('data-pf-locked');
        b.disabled = on;
      });
    }
    function tick() {
      var left = Math.ceil((waitEnd(key) - Date.now()) / 1000);
      var form = host();
      if (left > 0) {
        line.className = 'neurva_alert pf_lock';
        line.textContent = 'Too many attempts. Try again in ' + clock(left);
        if (form && line.parentNode !== form) form.insertBefore(line, form.firstChild);
        hold(true);
        timer = win.setTimeout(tick, 1000);
      } else if (line.textContent) {
        waitEnd(key, 0);
        hold(false);
        line.className = 'neurva_notice pf_lock';
        line.textContent = 'You can try again now';
        if (form && line.parentNode !== form) form.insertBefore(line, form.firstChild);
      }
    }
    tick();
    return { start: function (seconds) { win.clearTimeout(timer); waitEnd(key, Date.now() + seconds * 1000); tick(); } };
  }

  function focusFirstInvalid(form) {
    win.requestAnimationFrame(function () {
      var bad = qs('[aria-invalid="true"]', form);
      if (bad) bad.focus();
    });
  }

  /** The show/hide eye of every password input inside `scope`. */
  function initReveal(scope) {
    var eyeOff = template('icon-eye-off').firstChild;
    qsa('[data-pf="reveal"]', scope).forEach(function (button) {
      var input = button.parentNode.querySelector('input');
      var eye = button.firstChild.cloneNode(true);
      var label = button.getAttribute('data-pf-label') || 'password';
      var shown = false;
      button.addEventListener('click', function () {
        shown = !shown;
        input.type = shown ? 'text' : 'password';
        button.setAttribute('aria-pressed', shown ? 'true' : 'false');
        button.setAttribute('aria-label', (shown ? 'Hide ' : 'Show ') + label);
        button.textContent = '';
        button.appendChild((shown ? eyeOff : eye).cloneNode(true));
      });
    });
  }

  /** Four bars and three ticked rules under a new-password input (PasswordStrength). */
  function initStrength(box, input) {
    var bars = qsa('.neurva_bars span', box);
    var label = qs('.neurva_strengthLabel', box);
    var rules = qsa('.neurva_rules li', box);
    function update() {
      var value = input.value;
      var score = passwordStrength(value);
      box.setAttribute('data-score', String(score));
      bars.forEach(function (bar, index) { bar.className = index + 1 <= score ? 'neurva_barOn' : ''; });
      label.textContent = value ? 'Password strength: ' + STRENGTH_LABELS[score] : 'Use at least 8 characters with a letter and a number.';
      rules.forEach(function (li, index) {
        var met = RULES[index](value);
        li.className = met ? 'neurva_ruleMet' : '';
        li.firstChild.textContent = met ? '✓' : '○';
      });
    }
    input.addEventListener('input', update);
    update();
  }

  /** The six boxes over one real input (OtpField): digits only, at most six. */
  function initOtp(field) {
    var input = field.input;
    var boxes = qsa('.neurva_otpBox', field.control);
    var focused = false;
    function paint() {
      var value = input.value;
      boxes.forEach(function (box, index) {
        box.textContent = value.charAt(index);
        box.classList.toggle('neurva_otpBoxActive', focused && index === Math.min(value.length, OTP_LENGTH - 1));
      });
    }
    input.addEventListener('input', function () {
      var clean = input.value.replace(/\D/g, '').slice(0, OTP_LENGTH);
      if (clean !== input.value) input.value = clean;
      paint();
    });
    input.addEventListener('focus', function () { focused = true; paint(); });
    input.addEventListener('blur', function () { focused = false; paint(); });
    return { clear: function () { input.value = ''; paint(); } };
  }

  /* ------------------------------------------------------------------ page entrance (AuthShell: brand panel, card, perks) */

  function enter(el, delay, x) {
    if (!el || !canAnimate || reduce) return;
    el.animate([{ opacity: 0, transform: 'translate(' + x + 'px, 24px)' }, { opacity: 1, transform: 'none' }], { duration: 700, delay: delay * 1000, easing: EXPO, fill: 'backwards' });
  }
  function entrances() {
    enter(qs('[data-pf="auth-brand"]', root), 0.05, -24);
    qsa('[data-pf="auth-brand"] li', root).forEach(function (li, index) { enter(li, 0.35 + index * 0.1, 0); });
    enter(card, 0.15, 24);
  }

  /* ------------------------------------------------------------------ after a sign-in or sign-up (useAuthFlow) */

  var redirectTimer = 0;

  /** Shows the welcome in place of the form, then goes to the safe ?next= path or the landing page after 1.2 s. */
  function finish(user, created) {
    var frag = template('welcome');
    var name = String((user && user.name) || '').split(' ')[0];
    qs('[data-pf-slot="heading"]', frag).textContent = (created ? 'Welcome' : 'Welcome back') + (name ? ', ' + name : '') + '!';
    qs('[data-pf-slot="text"]', frag).textContent = created ? 'Your account is ready. Taking you there…' : 'Taking you there…';
    card.textContent = '';
    card.appendChild(frag);
    win.clearTimeout(redirectTimer);
    redirectTimer = win.setTimeout(function () {
      win.location.assign(safeNext(root.getAttribute('data-pf-next')));
    }, 1200);
  }

  /* ------------------------------------------------------------------ /login (LoginView.tsx) */

  function initLogin() {
    var form = qs('[data-pf="auth-form"]', card);
    var submitButton = qs('button[type="submit"]', form);
    var email = fieldIn(form, 'auth-identifier');
    var password = fieldIn(form, 'auth-password');
    var iconHolder = qs('[data-pf="field-icon"]', form);
    var emailLabel = qs('label', email.wrap);
    var mailIcon = iconHolder.firstChild.cloneNode(true);
    var mobileIcon = template('icon-mobile').firstChild;
    var state = { touched: false, busy: false, errors: {}, formError: '', notice: '', google: false };
    var shownKind = 'empty';
    var signupLink = qs('.neurva_switch a', card); // "Create an account", with ?next= kept

    function paintEmail() {
      var value = email.input.value;
      var kind = detectIdentifierKind(value);
      emailLabel.textContent = kind === 'mobile' ? 'Mobile number' : kind === 'email' ? 'Email address' : 'Email or mobile number';
      email.input.setAttribute('data-kind', kind);
      if ((kind === 'mobile') !== (shownKind === 'mobile')) {
        iconHolder.textContent = '';
        iconHolder.appendChild((kind === 'mobile' ? mobileIcon : mailIcon).cloneNode(true));
      }
      shownKind = kind;
      var isMobile = kind === 'mobile';
      // no email error while typing: it shows after leaving the box (or on submit); a mobile value only ever gets the notice
      var blurError = value.trim() && state.touched && !isMobile ? validateIdentifier(value) : '';
      showField(email, state.errors.email || blurError || '', isMobile ? MOBILE_UNAVAILABLE : '', 'status');
    }
    function paintAll() {
      paintEmail();
      showField(password, state.errors.password || '', '');
      showTop(form, state.formError, state.notice);
      var alertP = qs('p.neurva_alert', form);
      if (state.google && alertP) {
        // the Google button is above the form; the other way in is a password set by email code
        var set = make('a', 'neurva_inlineLink');
        set.href = '/forgot-password';
        set.textContent = 'Set a password';
        alertP.appendChild(doc.createTextNode('. Use the Google button, or '));
        alertP.appendChild(set);
      }
    }
    var lock = rateLock('pf_lock:login', function () { return form; }, function () { return [submitButton]; });
    function setBusy(busy) {
      state.busy = busy;
      paintButton(submitButton, busy, 'Sign In', 'Signing in…');
    }
    function run(task) {
      state.formError = '';
      state.notice = '';
      setBusy(true);
      showTop(form, '', '');
      return task().catch(function (error) {
        var d = describeError(error);
        state.errors = { email: d.errors.email, password: d.errors.password };
        state.google = !!(error && error.code === 'google_account');
        if (error && error.status === 429) { lock.start(error.retryAfter || 60); return; }
        // a message that belongs to an input is shown at that input; everything else at the top of the form
        if (!d.errors.email && !d.errors.password) state.formError = d.message;
        paintAll();
      }).then(function () { setBusy(false); });
    }

    email.input.addEventListener('input', function () {
      state.touched = false;
      state.google = false;
      state.errors.email = undefined;
      paintEmail();
    });
    email.input.addEventListener('blur', function () { state.touched = true; paintEmail(); });
    password.input.addEventListener('input', function () {
      state.errors.password = undefined;
      showField(password, '', '');
    });
    form.addEventListener('submit', function (event) {
      event.preventDefault();
      var idError = validateIdentifier(email.input.value);
      var found = { email: idError || undefined, password: password.input.value ? undefined : 'Enter your password.' };
      state.errors = found;
      paintAll();
      if (found.email || found.password) { focusFirstInvalid(form); return; }
      // the sign-in call: the address is trimmed and lower-cased, the password is sent as typed
      run(function () {
        return call('/auth/login', { method: 'POST', body: { email: email.input.value.trim().toLowerCase(), password: password.input.value } })
          .then(function (data) { finish(data.user, false); });
      });
    });
    initReveal(form);
    paintEmail();
  }

  /* ------------------------------------------------------------------ /signup (SignupView.tsx) */

  function initSignup() {
    var form = qs('[data-pf="auth-form"]', card);
    var submitButton = qs('button[type="submit"]', form);
    var fields = {
      name: fieldIn(form, 'auth-name'),
      email: fieldIn(form, 'auth-email'),
      password: fieldIn(form, 'auth-password'),
      confirm: fieldIn(form, 'auth-confirm')
    };
    var terms = qs('#auth-terms', form);
    var termsWrap = qs('[data-pf="terms"]', form);
    var termsLabel = qs('.neurva_check', termsWrap);
    var state = { errors: {}, formError: '', notice: '' };

    function paintTerms() {
      var message = state.errors.terms;
      var old = qs('p.neurva_error', termsWrap);
      if (old) termsWrap.removeChild(old);
      termsLabel.classList.toggle('neurva_checkInvalid', !!message);
      if (message) {
        var p = make('p', 'neurva_error');
        p.id = 'terms-error';
        p.setAttribute('role', 'alert');
        p.textContent = message;
        termsWrap.appendChild(p);
        terms.setAttribute('aria-invalid', 'true');
        terms.setAttribute('aria-describedby', 'terms-error');
      } else {
        terms.removeAttribute('aria-invalid');
        terms.removeAttribute('aria-describedby');
      }
    }
    function paintAll() {
      Object.keys(fields).forEach(function (key) { showField(fields[key], state.errors[key] || '', ''); });
      paintTerms();
      showTop(form, state.formError, state.notice);
    }
    var lock = rateLock('pf_lock:signup', function () { return form; }, function () { return [submitButton]; });
    function setBusy(busy) {
      paintButton(submitButton, busy, 'Create Account', 'Creating your account…');
    }
    function run(task) {
      state.formError = '';
      state.notice = '';
      setBusy(true);
      showTop(form, '', '');
      return task().catch(function (error) {
        var d = describeError(error);
        state.errors = { name: d.errors.name, email: d.errors.email, password: d.errors.password };
        if (error && error.status === 429) { lock.start(error.retryAfter || 60); return; }
        if (!d.errors.name && !d.errors.email && !d.errors.password) state.formError = d.message;
        paintAll();
      }).then(function () { setBusy(false); });
    }

    Object.keys(fields).forEach(function (key) {
      fields[key].input.addEventListener('input', function () {
        state.errors[key] = undefined;
        showField(fields[key], '', '');
      });
    });
    terms.addEventListener('change', function () {
      state.errors.terms = undefined;
      paintTerms();
    });
    form.addEventListener('submit', function (event) {
      event.preventDefault();
      var v = { name: fields.name.input.value, email: fields.email.input.value, password: fields.password.input.value, confirm: fields.confirm.input.value };
      var found = {
        name: validateName(v.name) || undefined,
        email: validateEmail(v.email) || undefined,
        password: validatePassword(v.password) || undefined,
        confirm: validateConfirm(v.password, v.confirm) || undefined,
        terms: terms.checked ? undefined : 'Please accept the terms to continue.'
      };
      state.errors = found;
      paintAll();
      if (Object.keys(found).some(function (key) { return found[key]; })) { focusFirstInvalid(form); return; }
      run(function () {
        return call('/auth/signup', { method: 'POST', body: { name: v.name.trim(), email: v.email.trim().toLowerCase(), password: v.password } })
          .then(function (data) {
            var offers = qs('#auth-offers', form);
            if (offers && offers.checked && win.PratyangiOffers) win.PratyangiOffers.save('signup', true); // signed in by now; a failure only means no consent is recorded
            finish(data.user, true);
          });
      });
    });
    initReveal(form);
    initStrength(qs('[data-pf="strength"]', form), fields.password.input);
  }

  /**
   * Signed in and a 6-digit code emailed: ask for it (POST /auth/email/verify {code}; POST /auth/email/resend for another). A correct code,
   * or Skip for now, goes on to the welcome and the landing page. When no code could be sent the step says so and Skip is the way on.
   */
  function askForEmailCode(user, emailAddress, sent) {
    var frag = template('verify-code');
    qs('[data-pf-slot="lead"]', frag).textContent = sent
      ? 'We sent a 6-digit code to ' + emailAddress + '. Enter it to confirm your email address.'
      : 'We could not send a code to ' + emailAddress + ' just now. You can verify later from your account page.';
    card.textContent = '';
    card.appendChild(frag);
    var form = qs('[data-pf="verify-form"]', card);
    var code = fieldIn(form, 'verify-code');
    var submit = qs('button[type="submit"]', form);
    var resend = qs('[data-pf="verify-resend"]', form);
    var skip = qs('[data-pf="verify-skip"]', card);
    var box = initOtp(code);
    var state = { busy: false, resendIn: 0 };
    var timer = 0;
    function paintResend() {
      resend.disabled = state.resendIn > 0 || state.busy || resend.hasAttribute('data-pf-locked');
      resend.textContent = state.resendIn > 0 ? 'Resend in ' + clock(state.resendIn) : 'Resend code';
    }
    function countdown(seconds) {
      win.clearTimeout(timer);
      state.resendIn = seconds;
      (function tick() {
        paintResend();
        if (state.resendIn > 0) timer = win.setTimeout(function () { state.resendIn -= 1; tick(); }, 1000);
      })();
    }
    var lock = rateLock('pf_lock:verify', function () { return form; }, function () { return [submit, resend]; });
    function fail(error, resending) {
      var d = describeError(error);
      if (error && error.status === 429) {
        // asking for a code too soon only holds the resend link; a limit on tries holds the whole form
        if (resending) { showTop(form, d.message, ''); countdown(error.retryAfter || 45); } else lock.start(error.retryAfter || 60);
        return;
      }
      if (d.errors.code || (error && error.status === 400)) showField(code, d.message, ''); else showTop(form, d.message, '');
    }
    code.input.addEventListener('input', function () { showField(code, '', ''); });
    form.addEventListener('submit', function (event) {
      event.preventDefault();
      if (state.busy) return;
      var problem = validateCode(code.input.value);
      showField(code, problem, '');
      showTop(form, '', '');
      if (problem) { focusFirstInvalid(form); return; }
      state.busy = true;
      paintButton(submit, true, 'Verify Email', 'Verifying…');
      call('/auth/email/verify', { method: 'POST', body: { code: code.input.value } })
        .then(function () { win.clearTimeout(timer); finish(user, true); }, function (error) { fail(error); })
        .then(function () { state.busy = false; if (qs('[data-pf="verify-form"]', card)) { paintButton(submit, false, 'Verify Email', ''); paintResend(); } });
    });
    resend.addEventListener('click', function () {
      if (state.busy || state.resendIn > 0) return;
      state.busy = true;
      paintResend();
      showTop(form, '', '');
      call('/auth/email/resend', { method: 'POST', body: {} })
        .then(function (data) { box.clear(); showTop(form, '', data.message); countdown(data.resendAfter || 45); }, function (error) { fail(error, true); })
        .then(function () { state.busy = false; paintResend(); });
    });
    skip.addEventListener('click', function () { win.clearTimeout(timer); finish(user, true); });
    if (sent) countdown(45); else paintResend();
    code.input.focus();
  }

  /* ------------------------------------------------------------------ /forgot-password (ForgotView.tsx) */

  /**
   * Step one asks where to send the code (the Email code | Mobile code switch); step two takes the code and the new password.
   * The code goes only to the verified email or mobile number stored on an account, and the page's answer is the same either way.
   * After the reset every session of the account has ended; the page then goes to the landing page (or a safe ?next=).
   */
  function initForgot() {
    var firstNodes = Array.prototype.slice.call(card.childNodes);
    var tabs = qsa('[data-pf-mode]', card);
    var emailForm = qs('[data-pf-panel="email"]', card);
    var mobileForm = qs('[data-pf-panel="mobile"]', card);
    var emailField = fieldIn(emailForm, 'auth-email');
    var mobileField = fieldIn(mobileForm, 'auth-mobile');
    var state = { step: 'first', channel: 'email', busy: false, errors: {}, formError: '', notice: '', resendIn: 0 };
    var resetNodes = null;
    var reset = null; // the second step, built the first time it is shown
    var countdown = 0;
    var lock = rateLock('pf_lock:forgot', function () { return state.step === 'reset' && reset ? reset.form : firstForm(); }, function () {
      return [firstSubmit(), reset && reset.submit, reset && reset.resend];
    });

    function firstForm() { return state.channel === 'mobile' ? mobileForm : emailForm; }
    function firstSubmit() { return qs('button[type="submit"]', firstForm()); }
    function normalisedEmail() { return emailField.input.value.trim().toLowerCase(); }
    /** What is sent and shown: the lower-cased address or the +91 number. */
    function typedTarget() { return state.channel === 'mobile' ? normaliseMobile(mobileField.input.value) : normalisedEmail(); }
    function body(extra) {
      var out = state.channel === 'mobile' ? { channel: 'mobile', mobile: typedTarget() } : { email: typedTarget() };
      Object.keys(extra || {}).forEach(function (key) { out[key] = extra[key]; });
      return out;
    }

    function paintResend() {
      if (!reset) return;
      reset.resend.disabled = state.resendIn > 0 || state.busy || reset.resend.hasAttribute('data-pf-locked');
      reset.resend.textContent = state.resendIn > 0 ? 'Resend in ' + clock(state.resendIn) : 'Resend code';
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
    function paintAll() {
      showField(emailField, state.channel === 'email' ? (state.errors.email || '') : '', '');
      showField(mobileField, state.channel === 'mobile' ? (state.errors.mobile || '') : '', '');
      if (reset) {
        showField(reset.code, state.errors.code || '', '');
        showField(reset.password, state.errors.password || '', '');
        showField(reset.confirm, state.errors.confirm || '', '');
      }
      showTop(firstForm(), state.step === 'first' ? state.formError : '', '');
      if (reset) showTop(reset.form, state.step === 'reset' ? state.formError : '', state.step === 'reset' ? state.notice : '');
    }
    function setBusy(busy) {
      state.busy = busy;
      paintButton(firstSubmit(), busy && state.step === 'first', 'Send code', 'Sending code…');
      if (reset) paintButton(reset.submit, busy && state.step === 'reset', 'Reset Password', 'Updating…');
      paintResend();
    }
    function run(task) {
      state.formError = '';
      setBusy(true);
      paintAll();
      return task().catch(function (error) {
        var d = describeError(error);
        state.errors = { email: d.errors.email, mobile: d.errors.mobile, code: d.errors.code, password: d.errors.password };
        if (error && error.status === 429) {
          // the resend link asking too soon only holds that link; every other 429 holds the form
          if (state.step === 'reset' && state.resending) { state.formError = d.message; startCountdown(error.retryAfter || 45); paintAll(); } else lock.start(error.retryAfter || 60);
          return;
        }
        if (!d.errors.email && !d.errors.mobile && !d.errors.code && !d.errors.password) state.formError = d.message;
        paintAll();
      }).then(function () { state.resending = false; setBusy(false); });
    }

    function showStep(step) {
      state.step = step;
      card.textContent = '';
      (step === 'first' ? firstNodes : resetNodes).forEach(function (node) { card.appendChild(node); });
    }

    function select(channel, focus) {
      state.channel = channel;
      tabs.forEach(function (tab) {
        var on = tab.getAttribute('data-pf-mode') === channel;
        tab.setAttribute('aria-selected', on ? 'true' : 'false');
        tab.tabIndex = on ? 0 : -1;
        tab.className = on ? 'pf_mode pf_modeOn' : 'pf_mode';
        if (on && focus) tab.focus();
      });
      emailForm.hidden = channel !== 'email';
      if (mobileForm) mobileForm.hidden = channel !== 'mobile';
      state.errors = {};
      state.formError = '';
      paintAll();
    }

    function buildReset() {
      var frag = template('reset');
      resetNodes = Array.prototype.slice.call(frag.childNodes);
      resetNodes.forEach(function (node) { card.appendChild(node); }); // in the page while the handlers are attached
      var form = qs('[data-pf="reset-form"]', card);
      reset = {
        form: form,
        code: fieldIn(form, 'auth-code'),
        password: fieldIn(form, 'auth-password'),
        confirm: fieldIn(form, 'auth-confirm'),
        submit: qs('button[type="submit"]', form),
        resend: qs('[data-pf="resend"]', form),
        targetSlot: qs('[data-pf-slot="target"]', card),
        change: qs('[data-pf="change"]', card)
      };
      reset.otp = initOtp(reset.code);
      reset.code.input.addEventListener('input', function () { state.errors.code = undefined; showField(reset.code, '', ''); });
      reset.password.input.addEventListener('input', function () { state.errors.password = undefined; showField(reset.password, '', ''); });
      reset.confirm.input.addEventListener('input', function () { state.errors.confirm = undefined; showField(reset.confirm, '', ''); });
      reset.change.addEventListener('click', function () {
        showStep('first');
        state.errors = {};
        state.notice = '';
        state.formError = '';
        paintAll();
        setBusy(state.busy);
      });
      reset.resend.addEventListener('click', function () { state.resending = true; sendCode(); });
      form.addEventListener('submit', function (event) {
        event.preventDefault();
        var code = reset.code.input.value;
        var found = {
          code: validateCode(code) || undefined,
          password: validatePassword(reset.password.input.value) || undefined,
          confirm: validateConfirm(reset.password.input.value, reset.confirm.input.value) || undefined
        };
        state.errors = found;
        paintAll();
        if (found.code || found.password || found.confirm) return;
        run(function () {
          return call('/auth/password/reset', { method: 'POST', body: body({ code: code, password: reset.password.input.value }) })
            .then(function () {
              win.clearTimeout(countdown);
              card.textContent = '';
              card.appendChild(template('done'));
              state.step = 'done';
              win.setTimeout(function () { win.location.assign(safeNext(root.getAttribute('data-pf-next'))); }, 1500);
            });
        });
      });
      initReveal(form);
      initStrength(qs('[data-pf="strength"]', form), reset.password.input);
    }

    function sendCode(event) {
      if (event) event.preventDefault();
      var problem = state.channel === 'mobile' ? validateMobile(mobileField.input.value) : validateEmail(emailField.input.value);
      state.errors = state.channel === 'mobile' ? { mobile: problem || undefined } : { email: problem || undefined };
      paintAll();
      if (problem) return;
      run(function () {
        return call('/auth/password/forgot', { method: 'POST', body: body() }).then(function () {
          var coming = state.step !== 'reset'; // a resend stays on the same step, so the code box keeps (not takes) focus
          if (!reset) buildReset();
          reset.otp.clear();
          reset.targetSlot.textContent = state.channel === 'mobile' ? masked(typedTarget()) : typedTarget();
          showStep('reset');
          startCountdown(45);
          state.notice = '';
          if (coming) reset.code.input.focus(); // OtpField autoFocus
        });
      });
    }

    tabs.forEach(function (tab) {
      tab.addEventListener('click', function () { select(tab.getAttribute('data-pf-mode'), false); });
      tab.addEventListener('keydown', function (event) {
        if (event.key !== 'ArrowLeft' && event.key !== 'ArrowRight') return;
        event.preventDefault();
        select(tab.getAttribute('data-pf-mode') === 'email' ? 'mobile' : 'email', true);
      });
    });
    emailField.input.addEventListener('input', function () { state.errors = {}; paintAll(); });
    if (mobileField) mobileField.input.addEventListener('input', function () { state.errors = {}; paintAll(); });
    emailForm.addEventListener('submit', sendCode);
    if (mobileForm) mobileForm.addEventListener('submit', sendCode);
    select('email', false);
  }

  /* ------------------------------------------------------------------ /verify-email (VerifyEmailView.tsx) */

  function initVerify() {
    var token = new win.URLSearchParams(win.location.search).get('token');
    if (!token) return; // the server already shows "Open the link from your email"

    function show(name) {
      var frag = template(name);
      card.textContent = '';
      card.appendChild(frag);
      return card;
    }

    function failed(error) {
      show('failed');
      qs('[data-pf-slot="message"]', card).textContent = error instanceof AuthError ? error.message : GENERIC;
    }

    call('/auth/email/verify', { method: 'POST', body: { token: token } }).then(function (data) {
      var first = data.customer.name.split(' ')[0] || '';
      show('verified');
      qs('[data-pf-slot="thanks"]', card).textContent = 'Thank you' + (first ? ', ' + first : '') + '. Your email address is confirmed.';
    }).catch(failed);
  }

  /* ------------------------------------------------------------------ start */

  // what auth-otp.js (the mobile one-time-code form on the sign-in and sign-up pages) shares with this file
  win.pfAuth = {
    qs: qs, qsa: qsa, call: call, AuthError: AuthError, describeError: describeError, fieldIn: fieldIn, showField: showField, showTop: showTop,
    paintButton: paintButton, focusFirstInvalid: focusFirstInvalid, initOtp: initOtp, finish: finish, template: template, card: card, page: page,
    normaliseMobile: normaliseMobile, validateMobile: validateMobile, validateCode: validateCode, masked: masked, clock: clock, rateLock: rateLock
  };

  /* ------------------------------------------------------------------ Continue with Google (login and signup; the block exists only when GOOGLE_CLIENT_ID is set) */

  function initGoogle() {
    var box = qs('[data-pf="google"]', card);
    if (!box) return;
    var holder = qs('[data-pf="google-button"]', box);
    var problem = qs('[data-pf="google-error"]', box);
    function say(text) { problem.textContent = text; problem.hidden = !text; }
    // from the Google answer until the page changes: a full-screen "Signing you in…" (it also stops a second tap); an error removes it and shows the message
    var signing = null;
    function showSigning() {
      signing = doc.createElement('div');
      signing.className = 'pf_signing';
      signing.setAttribute('role', 'status');
      var logo = qs('.pSCCwa_brandLogo img');
      if (logo) { var img = doc.createElement('img'); img.src = logo.getAttribute('src'); img.alt = ''; signing.appendChild(img); }
      var text = doc.createElement('p'); text.textContent = 'Signing you in…'; signing.appendChild(text);
      var spin = doc.createElement('span'); spin.className = 'pf_spin'; signing.appendChild(spin);
      doc.body.appendChild(signing);
      if (win.PratyangiScroll) win.PratyangiScroll.lock();
    }
    function hideSigning() { if (!signing) return; signing.remove(); signing = null; if (win.PratyangiScroll) win.PratyangiScroll.unlock(); }
    var script = doc.createElement('script');
    var testSrc = box.getAttribute('data-script-src') || ''; // set only by a test setup (GOOGLE_SCRIPT_PATH): a same-site path, never another host
    script.src = /^\/[A-Za-z0-9._-]+$/.test(testSrc) ? testSrc : 'https://accounts.google.com/gsi/client';
    script.async = true;
    script.onerror = function () { box.hidden = true; }; // Google cannot be reached: the email form is still there
    script.onload = function () {
      win.google.accounts.id.initialize({
        ux_mode: 'popup', // never the redirect mode (it posts to a login_uri we do not have)
        client_id: box.getAttribute('data-client-id'),
        callback: function (response) {
          if (signing) return;
          say('');
          showSigning();
          call('/auth/google', { method: 'POST', body: { credential: response.credential } })
            .then(function (data) { finish(data.user, false); }, function (error) { hideSigning(); say(describeError(error).message); });
        }
      });
      // Google draws a fixed-width button (200 to 400px): as wide as the card, drawn again when the card changes width
      var drawn = 0, timer = 0;
      function draw() {
        var width = Math.min(Math.max(Math.floor(box.clientWidth), 200), 400);
        if (width === drawn) return;
        drawn = width;
        holder.textContent = '';
        win.google.accounts.id.renderButton(holder, { theme: 'outline', size: 'large', shape: 'rectangular', text: page === 'signup' ? 'signup_with' : 'signin_with', width: width });
      }
      draw();
      win.addEventListener('resize', function () { win.clearTimeout(timer); timer = win.setTimeout(draw, 150); });
    };
    doc.head.appendChild(script);
  }

  /* ------------------------------------------------------------------ /account-help (the mobile form exists only while SMS_ENABLED=true) */

  function initHelp() {
    var form = qs('[data-pf="help-form"]', card);
    if (!form) return;
    var mobile = fieldIn(form, 'help-mobile');
    var submit = qs('button[type="submit"]', form);
    var result = qs('[data-pf="help-result"]', form);
    var busy = false;
    var lock = rateLock('pf_lock:help', function () { return form; }, function () { return [submit]; });
    mobile.input.addEventListener('input', function () { showField(mobile, '', ''); });
    form.addEventListener('submit', function (event) {
      event.preventDefault();
      if (busy) return;
      var problem = validateMobile(mobile.input.value);
      showField(mobile, problem, '');
      result.hidden = true;
      if (problem) { focusFirstInvalid(form); return; }
      busy = true;
      paintButton(submit, true, 'Text me a hint', 'Sending…');
      call('/auth/account-help/sms', { method: 'POST', body: { mobile: normaliseMobile(mobile.input.value) } })
        .then(function (data) { result.textContent = data.message; result.hidden = false; },
          function (error) {
            if (error && error.status === 429) lock.start(error.retryAfter || 60);
            else { var d = describeError(error); showField(mobile, d.errors.mobile || d.message, ''); }
          })
        .then(function () { busy = false; paintButton(submit, false, 'Text me a hint', ''); });
    });
  }

  entrances();
  if (page === 'login' || page === 'signup' || page === 'help') initGoogle();
  if (page === 'help') initHelp();
  if (page === 'login') initLogin();
  else if (page === 'signup') initSignup();
  else if (page === 'forgot') initForgot();
  else if (page === 'verify') initVerify();
})();
