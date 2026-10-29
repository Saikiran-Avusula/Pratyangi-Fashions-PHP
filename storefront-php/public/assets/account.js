/*
 * The account page (PHP-only additions, see docs/API_CONTRACT.md 11.3), in plain JavaScript: no libraries, no inline scripts.
 *  - "Verify your email": Send code -> POST /api/v1/auth/email/resend, then the 6-digit code -> POST /api/v1/auth/email/verify {code}.
 *  - "Complete your account": a name (POST /auth/profile/name), Add mobile number (POST /auth/mobile/request and /verify) and Add email
 *    (POST /auth/email/link/request and /verify). Each add is: send a code to the typed value, then enter the code.
 * Every call is same-origin with the cookie and a JSON body. Text is set with textContent only; nothing is written into the page as HTML.
 * After a verification the visitor goes to the landing page "/", as after every sign-in.
 */
(function (win) {
  'use strict';
  var doc = win.document;
  var GENERIC = 'Something went wrong. Please try again.';
  var UNREACHABLE = 'We could not reach the server. Check your connection and try again.';
  var RESEND_SECONDS = 45;

  function qs(sel, scope) { return (scope || doc).querySelector(sel); }
  function qsa(sel, scope) { return Array.prototype.slice.call((scope || doc).querySelectorAll(sel)); }

  /** POST JSON to /api/v1 with the cookie. Resolves { ok, status, data } (never rejects on an HTTP error). */
  function post(path, body) {
    return win.fetch('/api/v1' + path, {
      method: 'POST',
      credentials: 'include',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(body)
    }).then(function (response) {
      return response.json().catch(function () { return {}; }).then(function (data) {
        return { ok: response.ok, status: response.status, data: data || {} };
      });
    }, function () {
      return { ok: false, status: 0, data: { message: UNREACHABLE } };
    });
  }

  function say(el, text, bad) {
    if (!el) return;
    el.textContent = text;
    el.hidden = !text;
    if (bad) el.setAttribute('data-bad', '1'); else el.removeAttribute('data-bad');
  }
  function messageOf(result) {
    return typeof result.data.message === 'string' && result.data.message ? result.data.message : GENERIC;
  }
  function goHome(el, text) {
    say(el, text + ' Taking you to the home page…', false);
    win.setTimeout(function () { win.location.assign('/'); }, 1200);
  }
  function normaliseMobile(value) {
    var match = /^(?:\+?91)?([6-9]\d{9})$/.exec(String(value).replace(/ /g, '').trim());
    return match ? '+91' + match[1] : '';
  }
  function validEmail(value) {
    var email = String(value).trim();
    return email.length <= 254 && /^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/.test(email);
  }
  /** A button that counts down before it can be pressed again. */
  function cooldown(button, idleText, seconds) {
    var left = seconds;
    (function tick() {
      button.disabled = left > 0;
      button.textContent = left > 0 ? (idleText === 'Resend code' ? 'Resend' : idleText) + ' in ' + Math.floor(left / 60) + ':' + (left % 60 < 10 ? '0' : '') + (left % 60) : idleText;
      if (left > 0) { left -= 1; win.setTimeout(tick, 1000); }
    })();
  }

  /* ------------------------------------------------------------------ verify the email already on the account */

  (function () {
    var send = qs('[data-pf="email-send"]');
    var form = qs('[data-pf="email-code-form"]');
    var input = qs('[data-pf="email-code"]');
    var result = qs('[data-pf="email-result"]');
    if (!send || !form || !input || !result) return;
    var busy = false;

    send.addEventListener('click', function () {
      if (busy || send.disabled) return;
      busy = true;
      send.disabled = true;
      send.textContent = 'Sending…';
      say(result, '', false);
      post('/auth/email/resend', {}).then(function (r) {
        busy = false;
        if (r.ok) {
          say(result, messageOf(r), false);
          form.hidden = false;
          input.focus();
          cooldown(send, 'Resend code', r.data.resendAfter || RESEND_SECONDS);
        } else {
          say(result, messageOf(r), true);
          if (r.status === 429 && r.data.retryAfter) cooldown(send, 'Send code', r.data.retryAfter);
          else { send.disabled = false; send.textContent = 'Send code'; }
        }
      });
    });
    form.addEventListener('submit', function (event) {
      event.preventDefault();
      if (busy) return;
      var code = input.value.trim();
      if (!/^\d{6}$/.test(code)) { say(result, 'Enter the 6-digit code.', true); return; }
      busy = true;
      var vb = qs('button[type="submit"]', form); vb.disabled = true;
      post('/auth/email/verify', { code: code }).then(function (r) {
        busy = false; vb.disabled = false;
        if (r.ok) goHome(result, 'Your email is verified.');
        else say(result, messageOf(r), true);
      });
    });
  })();

  /* ------------------------------------------------------------------ add a name */

  (function () {
    var form = qs('[data-pf="name-form"]');
    if (!form) return;
    var input = qs('[data-pf="name-input"]', form);
    var result = qs('[data-pf="name-result"]', form);
    var busy = false;
    form.addEventListener('submit', function (event) {
      event.preventDefault();
      if (busy) return;
      var name = input.value.trim();
      if (name.length < 2) { say(result, 'Enter your full name (at least 2 characters).', true); return; }
      if (name.length > 80) { say(result, 'That name is too long (80 characters at most).', true); return; }
      busy = true;
      var nb = qs('button[type="submit"]', form); nb.disabled = true;
      post('/auth/profile/name', { name: name }).then(function (r) {
        busy = false; nb.disabled = false;
        if (r.ok) win.location.reload();
        else say(result, messageOf(r), true);
      });
    });
  })();

  /* ------------------------------------------------------------------ add a mobile number or an email */

  var KINDS = {
    mobile: {
      request: '/auth/mobile/request', verify: '/auth/mobile/verify', key: 'mobile',
      clean: function (value) { return normaliseMobile(value); },
      problem: function (value) { return normaliseMobile(value) ? '' : 'Enter a valid 10-digit mobile number.'; },
      done: 'Your mobile number is added.'
    },
    email: {
      request: '/auth/email/link/request', verify: '/auth/email/link/verify', key: 'email',
      clean: function (value) { return String(value).trim().toLowerCase(); },
      problem: function (value) { return validEmail(value) ? '' : 'Enter a valid email address, like name@example.com.'; },
      done: 'Your email is added and verified.'
    }
  };

  qsa('[data-pf-link]').forEach(function (block) {
    var kind = KINDS[block.getAttribute('data-pf-link')];
    if (!kind) return;
    var open = qs('[data-pf="link-open"]', block);
    var form = qs('[data-pf="link-form"]', block);
    var target = qs('[data-pf="link-target"]', block);
    var send = qs('[data-pf="link-send"]', block);
    var codeWrap = qs('[data-pf="link-code-wrap"]', block);
    var code = qs('[data-pf="link-code"]', block);
    var verify = qs('[data-pf="link-verify"]', block);
    var resend = qs('[data-pf="link-resend"]', block);
    var result = qs('[data-pf="link-result"]', block);
    var step = 'target';
    var typed = '';
    var busy = false;

    function ask(again) {
      var value = again ? typed : kind.clean(target.value);
      var body = {};
      body[kind.key] = value;
      busy = true;
      send.disabled = true;
      resend.disabled = true;
      say(result, '', false);
      return post(kind.request, body).then(function (r) {
        busy = false;
        send.disabled = false;
        if (!r.ok) {
          say(result, messageOf(r), true);
          var wait = r.status === 429 && r.data.retryAfter ? r.data.retryAfter : 0;
          if (step === 'code') cooldown(resend, 'Resend code', wait);
          else if (wait) cooldown(send, 'Send code', wait);
          return;
        }
        typed = value;
        step = 'code';
        target.readOnly = true;
        send.hidden = true;
        codeWrap.hidden = false;
        say(result, 'We sent a 6-digit code. Enter it below.', false);
        cooldown(resend, 'Resend code', r.data.resendAfter || RESEND_SECONDS);
        code.focus();
      });
    }

    open.addEventListener('click', function () {
      open.hidden = true;
      form.hidden = false;
      target.focus();
    });
    resend.addEventListener('click', function () {
      if (!busy && !resend.disabled) ask(true);
    });
    form.addEventListener('submit', function (event) {
      event.preventDefault();
      if (busy) return;
      if (step === 'target') {
        var problem = kind.problem(target.value);
        if (problem) { say(result, problem, true); return; }
        ask(false);
        return;
      }
      var digits = code.value.trim();
      if (!/^\d{6}$/.test(digits)) { say(result, 'Enter the 6-digit code.', true); return; }
      var body = { code: digits };
      body[kind.key] = typed;
      busy = true;
      verify.disabled = true;
      post(kind.verify, body).then(function (r) {
        busy = false;
        verify.disabled = false;
        if (r.ok) goHome(result, kind.done);
        else say(result, messageOf(r), true);
      });
    });
  });
  /* ------------------------------------------------------------------ Notifications: offers and new arrivals, per channel (offers.js) */

  (function () {
    var panel = qs('[data-pf="consent-panel"]');
    if (!panel || !win.PratyangiOffers) return;
    var boxes = qsa('[data-pf-consent]', panel);
    var result = qs('[data-pf="consent-result"]', panel);
    win.PratyangiOffers.get().then(function (state) {
      if (!state) { say(result, GENERIC, true); return; }
      boxes.forEach(function (box) { box.checked = !!state[box.getAttribute('data-pf-consent')]; box.disabled = false; });
    });
    boxes.forEach(function (box) {
      box.addEventListener('change', function () {
        var change = {};
        change[box.getAttribute('data-pf-consent')] = box.checked;
        box.disabled = true;
        win.PratyangiOffers.set(change, 'account').then(function (state) {
          box.disabled = false;
          if (!state) { box.checked = !box.checked; say(result, GENERIC, true); return; }
          say(result, 'Saved.', false);
        });
      });
    });
  })();
})(window);