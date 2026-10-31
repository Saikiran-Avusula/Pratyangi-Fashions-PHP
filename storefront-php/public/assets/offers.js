/*
 * "Send me offers and new arrivals by SMS, WhatsApp and email." (DPDP / TRAI consent). The box is never ticked for the visitor.
 *   PratyangiOffers.save(source, true)  after sign-up, address save or checkout when the visitor ticked the box: yes on all three channels
 *   PratyangiOffers.get() / .set({sms, whatsapp, email}, 'account')  the Notifications page, one channel at a time
 * Calls PUT/GET /api/v1/consent with the cookie. The server keeps the time, the page (source) and the IP of every change. Nothing here throws.
 */
(function (win) {
  'use strict';
  if (window.__pfOffers) return; window.__pfOffers = true; // REQ1: loaded twice = set up once

  function call(method, body) {
    var init = { method: method, credentials: 'include', headers: { 'Content-Type': 'application/json' }, keepalive: true };
    if (body) init.body = JSON.stringify(body);
    return win.fetch('/api/v1/consent', init).then(function (r) {
      return r.json().catch(function () { return {}; }).then(function (data) { return { ok: r.ok, data: data || {} }; });
    }, function () { return { ok: false, data: {} }; });
  }

  win.PratyangiOffers = {
    save: function (source, on) {
      if (!on) return win.Promise.resolve(true);
      return call('PUT', { sms: true, whatsapp: true, email: true, source: source }).then(function (r) { return r.ok; });
    },
    get: function () {
      return call('GET').then(function (r) { return r.ok && r.data.consent ? r.data.consent : null; });
    },
    set: function (changes, source) {
      changes.source = source;
      return call('PUT', changes).then(function (r) { return r.ok && r.data.consent ? r.data.consent : null; });
    }
  };
})(window);
