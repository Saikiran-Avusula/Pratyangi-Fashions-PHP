/* Preview screens only (DEMO_UI=true; the pages that load this file exist only then). Nothing here saves, sends or charges anything.
 *  - data-demo-toast="text": click shows a small toast
 *  - payment radios enable the "Use this payment method" button (-> /checkout/review?pay=<choice>)
 *  - the add-card dialog (every field in it is disabled) opens and closes
 *  - review form: star rating, text counter, tags; reviews page: the two tabs; membership: Monthly / Yearly switch */
(function (win) {
  'use strict';
  var doc = win.document;
  function qsa(sel, root) { return Array.prototype.slice.call((root || doc).querySelectorAll(sel)); }

  var toast = null, toastTimer = 0;
  function say(text) {
    if (!toast) {
      toast = doc.createElement('div');
      toast.className = 'pf_dToast';
      toast.setAttribute('role', 'status');
      doc.body.appendChild(toast);
    }
    toast.textContent = text;
    toast.classList.add('pf_dToastOn');
    win.clearTimeout(toastTimer);
    toastTimer = win.setTimeout(function () { toast.classList.remove('pf_dToastOn'); }, 2600);
  }
  doc.addEventListener('click', function (event) {
    var t = event.target.closest ? event.target.closest('[data-demo-toast]') : null;
    if (t) { event.preventDefault(); say(t.getAttribute('data-demo-toast')); }
    var go = event.target.closest ? event.target.closest('[data-pf="demo-continue"]') : null;
    if (go && go.getAttribute('aria-disabled') === 'true') event.preventDefault();
  });

  // payment choice -> the Continue buttons
  var pays = qsa('input[name="pay"]');
  pays.forEach(function (radio) {
    radio.addEventListener('change', function () {
      qsa('[data-pf="demo-continue"]').forEach(function (a) {
        a.setAttribute('aria-disabled', 'false');
        a.setAttribute('href', '/checkout/review?pay=' + encodeURIComponent(radio.value));
      });
    });
  });

  // add-card dialog
  var modal = doc.querySelector('[data-demo-modal]');
  if (modal) {
    var opener = null;
    var close = function () { modal.hidden = true; if (win.PratyangiScroll) win.PratyangiScroll.unlock(); if (opener) opener.focus(); };
    qsa('[data-demo-open="card"]').forEach(function (b) {
      b.addEventListener('click', function () { opener = b; modal.hidden = false; if (win.PratyangiScroll) win.PratyangiScroll.lock(); var c = modal.querySelector('[data-demo-close]'); if (c) c.focus(); });
    });
    qsa('[data-demo-close]', modal).forEach(function (b) { b.addEventListener('click', close); });
    modal.addEventListener('click', function (e) { if (e.target === modal) close(); });
    doc.addEventListener('keydown', function (e) { if (e.key === 'Escape' && !modal.hidden) close(); });
  }

  // review form
  var stars = qsa('[data-star]');
  if (stars.length) {
    var rateText = doc.querySelector('[data-pf="rate-text"]');
    var setRate = function (n) {
      stars.forEach(function (s) { var on = Number(s.getAttribute('data-star')) <= n; s.classList.toggle('pf_dStarOn', on); s.setAttribute('aria-checked', String(Number(s.getAttribute('data-star')) === n)); s.tabIndex = (n === 0 ? s === stars[0] : Number(s.getAttribute('data-star')) === n) ? 0 : -1; });
      rateText.textContent = n + ' out of 5';
    };
    stars.forEach(function (s, i) {
      s.addEventListener('click', function () { setRate(i + 1); });
      s.addEventListener('keydown', function (e) {
        var n = Number(s.getAttribute('data-star'));
        if (e.key === 'ArrowRight' || e.key === 'ArrowUp') { e.preventDefault(); setRate(Math.min(5, n + 1)); stars[Math.min(4, n)].focus(); }
        if (e.key === 'ArrowLeft' || e.key === 'ArrowDown') { e.preventDefault(); setRate(Math.max(1, n - 1)); stars[Math.max(0, n - 2)].focus(); }
      });
    });
    setRate(0);
    var text = doc.querySelector('[data-pf="rv-text"]'), count = doc.querySelector('[data-pf="rv-count"]');
    if (text) text.addEventListener('input', function () { count.textContent = text.value.length + '/500'; });
    qsa('.pf_dTags button').forEach(function (b) { b.addEventListener('click', function () { b.setAttribute('aria-pressed', b.getAttribute('aria-pressed') === 'true' ? 'false' : 'true'); }); });
  }

  // reviews tabs
  var tabs = qsa('[role="tab"]');
  tabs.forEach(function (tab) {
    tab.addEventListener('click', function () {
      tabs.forEach(function (o) {
        var on = o === tab;
        o.setAttribute('aria-selected', String(on));
        o.tabIndex = on ? 0 : -1;
        doc.getElementById(o.getAttribute('aria-controls')).hidden = !on;
      });
    });
  });

  // membership Monthly / Yearly (no prices in the preview, only the state)
  qsa('.pf_dToggle button').forEach(function (b, i, all) {
    b.addEventListener('click', function () { all.forEach(function (o) { o.setAttribute('aria-pressed', String(o === b)); }); });
  });
})(window);
