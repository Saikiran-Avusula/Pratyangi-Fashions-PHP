/*
 * The cookie notice and the one gate for optional scripts.
 *   The choice is the first-party cookie pf_consent = "v1.a<0|1>.m<0|1>" (analytics, marketing; necessary is always on), kept 12 months.
 *   window.PratyangiConsent.allowed('analytics' | 'marketing') says whether a category was accepted;
 *   window.PratyangiConsent.loadScript(category, src) loads a script ONLY after that category is accepted (now, or when the visitor accepts it later).
 *   Any script that is not essential must be added through loadScript, never with a <script> tag. None exist today.
 *   The bar shows on the first visit; "Cookie settings" in the footer ([data-pf=cookie-settings]) opens it again on the Manage panel.
 */
(function (win) {
  'use strict';

  var doc = win.document;
  var NAME = 'pf_consent';
  var MAX_AGE = 60 * 60 * 24 * 365; // 12 months
  var waiting = [];

  function read() {
    var m = /(?:^|;\s*)pf_consent=v1\.a([01])\.m([01])(?:;|$)/.exec(doc.cookie || '');
    return m ? { analytics: m[1] === '1', marketing: m[2] === '1' } : null;
  }

  function write(choice) {
    var secure = win.location.protocol === 'https:' ? '; Secure' : '';
    doc.cookie = NAME + '=v1.a' + (choice.analytics ? 1 : 0) + '.m' + (choice.marketing ? 1 : 0) + '; Max-Age=' + MAX_AGE + '; Path=/; SameSite=Lax' + secure;
  }

  function allowed(category) {
    var choice = read();
    return !!choice && choice[category] === true;
  }

  function load(src) {
    var s = doc.createElement('script');
    s.src = src;
    s.async = true;
    doc.head.appendChild(s);
  }

  function flush() {
    var rest = [];
    for (var i = 0; i < waiting.length; i++) {
      if (allowed(waiting[i][0])) load(waiting[i][1]); else rest.push(waiting[i]);
    }
    waiting = rest;
  }

  win.PratyangiConsent = {
    allowed: allowed,
    loadScript: function (category, src) {
      if (category !== 'analytics' && category !== 'marketing') return;
      if (allowed(category)) load(src); else waiting.push([category, src]);
    }
  };

  function init() {
    var bar = doc.querySelector('[data-pf="cookie-bar"]');
    if (!bar) return;
    var panel = bar.querySelector('[data-pf="cookie-panel"]');
    var manage = bar.querySelector('[data-pf="cookie-manage"]');
    var analytics = bar.querySelector('[data-pf="cookie-analytics"]');
    var marketing = bar.querySelector('[data-pf="cookie-marketing"]');
    var root = doc.documentElement;
    var opener = null;

    // the floating WhatsApp button and the sticky buy buttons sit above the bar (CSS reads --pf-cookie-h)
    function measure() {
      root.style.setProperty('--pf-cookie-h', bar.hidden ? '0px' : bar.offsetHeight + 'px');
    }

    // the open Manage panel is tall: the page behind it is pinned while it is open, and only the bar scrolls
    var locked = false;
    function lock(on) {
      if (!win.PratyangiScroll || on === locked) { return; }
      locked = on;
      if (on) { win.PratyangiScroll.lock(); } else { win.PratyangiScroll.unlock(); }
    }

    function showPanel(on) {
      panel.hidden = !on;
      lock(on);
      manage.setAttribute('aria-expanded', on ? 'true' : 'false');
      measure();
    }

    function open(withPanel) {
      var choice = read() || { analytics: false, marketing: false };
      analytics.checked = choice.analytics;
      marketing.checked = choice.marketing;
      bar.hidden = false;
      showPanel(!!withPanel);
      measure();
    }

    function close() {
      lock(false);
      bar.hidden = true;
      measure();
      if (opener && opener.focus) { opener.focus(); }
      opener = null;
    }

    function save(choice) {
      write(choice);
      close();
      flush();
    }

    bar.querySelector('[data-pf="cookie-accept"]').addEventListener('click', function () { save({ analytics: false, marketing: false }); }); // accepts what the notice says: essential cookies only
    manage.addEventListener('click', function () { showPanel(panel.hidden); });
    bar.querySelector('[data-pf="cookie-save"]').addEventListener('click', function () { save({ analytics: analytics.checked, marketing: marketing.checked }); });
    bar.addEventListener('keydown', function (e) { if (e.key === 'Escape' && read()) close(); });
    win.addEventListener('resize', measure);
    doc.addEventListener('click', function (e) {
      var link = e.target.closest ? e.target.closest('[data-pf="cookie-settings"]') : null;
      if (!link) return;
      e.preventDefault();
      opener = link;
      open(true);
      manage.focus();
    });

    if (!read()) open(false);
  }

  if (doc.readyState === 'loading') doc.addEventListener('DOMContentLoaded', init); else init();
})(window);
