/*
 * Page behaviour for the landing page, in plain JavaScript (no libraries, no inline scripts, no network).
 * It reproduces what the Node web app does with React + framer-motion + Lenis: the same classes, timings, easings and
 * breakpoints. See docs/UI_PARITY.md for the table of behaviours and what is only approximated.
 *
 * With JavaScript off, nothing is hidden: the page is fully visible and the links work (the menu and search dialog need JS).
 * With prefers-reduced-motion: reduce, nothing is hidden or animated either (like the web: instant, no smooth scroll,
 * no marquee, no parallax, the hero stays still).
 */
(function () {
  'use strict';

  var doc = document;
  var win = window;
  var reduce = !!(win.matchMedia && win.matchMedia('(prefers-reduced-motion: reduce)').matches);
  var canAnimate = typeof Element !== 'undefined' && typeof Element.prototype.animate === 'function';
  var hasIO = typeof win.IntersectionObserver === 'function';

  // easeOutExpo from the web's motion presets
  var EXPO = 'cubic-bezier(0.22, 1, 0.36, 1)';

  function qs(sel, root) { return (root || doc).querySelector(sel); }
  function qsa(sel, root) { return Array.prototype.slice.call((root || doc).querySelectorAll(sel)); }
  function clamp(v, lo, hi) { return v < lo ? lo : v > hi ? hi : v; }

  /** cubic-bezier(x1, y1, x2, y2) as a function of progress (what framer-motion's cubicBezier returns). */
  function bezier(x1, y1, x2, y2) {
    function at(t, a, b) { var u = 1 - t; return 3 * a * t * u * u + 3 * b * t * t * u + t * t * t; }
    return function (x) {
      if (x <= 0) return 0;
      if (x >= 1) return 1;
      var lo = 0, hi = 1, t = x;
      for (var i = 0; i < 24; i++) {
        if (at(t, x1, x2) < x) lo = t; else hi = t;
        t = (lo + hi) / 2;
      }
      return at(t, y1, y2);
    };
  }
  var soft = bezier(0.45, 0, 0.2, 1);        // Hero.tsx
  var headingEase = bezier(0.25, 0.1, 0.25, 1); // page.tsx heading fade

  /** framer-motion's useTransform for one segment: input range -> output range, clamped, optional easing. */
  function mapRange(v, inLo, inHi, outLo, outHi, ease) {
    var t = clamp((v - inLo) / (inHi - inLo), 0, 1);
    if (ease) t = ease(t);
    return outLo + (outHi - outLo) * t;
  }

  /* ------------------------------------------------------------------ small animation helper */

  /** Plays a Web Animation between two keyframes; ends on the element's normal style. fill:backwards shows `from` during the delay. */
  function play(el, from, to, duration, delay, easing) {
    if (!canAnimate || reduce || !el) return null;
    return el.animate([from, to], { duration: duration, delay: delay || 0, easing: easing || EXPO, fill: 'backwards' });
  }

  /* ------------------------------------------------------------------ 1. page-load entrances (header, hero) */

  function entrances() {
    if (!canAnimate || reduce) return;
    // header: y -24 -> 0, opacity 0 -> 1, 0.45s easeOutExpo
    play(qs('[data-pf="header"]'), { opacity: 0, transform: 'translateY(-24px)' }, { opacity: 1, transform: 'none' }, 450, 0);
    // hero stage settles in: scale 1.08 -> 1, 1s
    play(qs('.RSeura_plateIntro'), { opacity: 0, transform: 'scale(1.08)' }, { opacity: 1, transform: 'none' }, 1000, 0);
    // models rise (y 56 -> 0, 1s): centre first at 0.25s, then 0.4 / 0.55 / 0.7 outwards
    qsa('.RSeura_model').forEach(function (model) {
      var id = model.getAttribute('data-hero-model') || '';
      var delay = id === 'center' ? 250 : /-1$/.test(id) ? 400 : /-2$/.test(id) ? 550 : 700;
      play(qs('.RSeura_modelIntro', model), { opacity: 0, transform: 'translateY(56px)' }, { opacity: 1, transform: 'none' }, 1000, delay);
    });
  }

  /* ------------------------------------------------------------------ 2. scroll-linked hero and category heading */

  // Per model: how far it drifts sideways (artboard px), its width, and whether it fades (Hero heroLayers.ts)
  var HERO = {
    'left-3': { w: 72, travel: -490 },
    'left-2': { w: 189, travel: -374 },
    'left-1': { w: 229, travel: -288 },
    'center': { w: 267, travel: 0, fade: [0.08, 0.7] },
    'right-1': { w: 214, travel: 288 },
    'right-2': { w: 197, travel: 374 },
    'right-3': { w: 79, travel: 490 }
  };

  function scrollLinked() {
    var category = qs('#categories');
    var stage = qs('.RSeura_stage');
    var heading = qs('.YnyroG_categoryHeading');
    var models = qsa('.RSeura_model').map(function (el) {
      return { el: el, cfg: HERO[el.getAttribute('data-hero-model') || ''] || null, lastT: '', lastO: '' };
    });
    if (!category || !stage) return;
    var queued = false;

    function update() {
      queued = false;
      var height = category.offsetTop; // the hero's height inside the layered wrapper
      var p = height > 0 ? clamp(win.scrollY / height, 0, 1) : 0;
      // the whole scene dims as it is covered (reduced motion: flat)
      stage.style.opacity = String(mapRange(p, 0.25, 1, 1, reduce ? 1 : 0.6, soft));
      models.forEach(function (m) {
        if (!m.cfg) return;
        var x = reduce ? 0 : (m.cfg.travel / m.cfg.w) * 100 * soft(p);
        var t = 'translateX(' + x + '%)';
        if (t !== m.lastT) { m.el.style.transform = t; m.lastT = t; }
        var o = '1';
        if (m.cfg.fade && !reduce) o = String(mapRange(p, m.cfg.fade[0], m.cfg.fade[1], 1, 0, soft));
        if (o !== m.lastO) { m.el.style.opacity = o; m.lastO = o; }
      });
      if (heading) {
        // "Shop by Category" appears only as the page is scrolled; a soft blur only while it overlaps the hero
        heading.style.opacity = String(mapRange(p, 0.05, 0.45, reduce ? 1 : 0, 1, headingEase));
        var blur = 0;
        if (!reduce) blur = p < 0.35 ? mapRange(p, 0.1, 0.35, 0, 2.5) : mapRange(p, 0.35, 0.8, 2.5, 0);
        heading.style.filter = blur > 0.05 ? 'blur(' + blur + 'px)' : 'none';
      }
    }
    function schedule() { if (!queued) { queued = true; win.requestAnimationFrame(update); } }

    win.addEventListener('scroll', schedule, { passive: true });
    win.addEventListener('resize', schedule);
    update();
  }

  /* ------------------------------------------------------------------ 3. scroll-reveal entrances */

  var shown = { opacity: '1', transform: 'none', filter: 'none' };

  /** The hidden look of a part, written inline (no transition, so nothing slides before the reveal). */
  function hide(part) {
    var s = part.el.style;
    s.opacity = String(part.op);
    if (part.tf) s.transform = part.tf;
    if (part.fl) s.filter = part.fl;
    s.transition = 'none';
  }
  /** Back to the stylesheet's own look (so CSS hover effects work again). */
  function unhide(part) {
    var s = part.el.style;
    s.removeProperty('opacity');
    if (part.tf) s.removeProperty('transform');
    if (part.fl) s.removeProperty('filter');
    s.removeProperty('transition');
  }
  function frame(part, hidden, from) {
    var f = { opacity: hidden ? String(part.op) : shown.opacity };
    if (part.tf) f.transform = hidden ? part.tf : shown.transform;
    if (part.fl) f.filter = hidden ? part.fl : shown.filter;
    if (from) { // start where the element is right now
      var cs = win.getComputedStyle(part.el);
      f.opacity = cs.opacity;
      if (part.tf) f.transform = cs.transform;
      if (part.fl) f.filter = cs.filter;
    }
    return f;
  }
  /** Moves one part to its shown or hidden look with its own duration, delay and easing. */
  function transitionPart(part, toShown) {
    var from = frame(part, false, true);
    var to = frame(part, !toShown, false);
    if (part.anim) part.anim.cancel();
    var timing = toShown ? part.inT : part.outT;
    var a = part.el.animate([from, to], { duration: timing.dur, delay: timing.delay, easing: timing.ease, fill: 'both' });
    part.anim = a;
    a.onfinish = function () {
      if (toShown) unhide(part); else hide(part);
      a.cancel();
      if (part.anim === a) part.anim = null;
    };
  }

  var observers = {};
  function observerFor(amount, margin) {
    var key = amount + '|' + margin;
    if (observers[key]) return observers[key];
    var io = new win.IntersectionObserver(function (entries) {
      entries.forEach(function (entry) {
        var item = entry.target.__pfReveal;
        if (!item) return;
        var inView = entry.isIntersecting && entry.intersectionRatio >= amount - 0.005;
        if (inView === item.inView) return;
        item.inView = inView;
        item.parts.forEach(function (part) { transitionPart(part, inView); });
      });
    }, { threshold: [0, amount], rootMargin: margin });
    observers[key] = io;
    return io;
  }

  var T_UP = function (delaySec) { return { dur: 550, delay: delaySec * 1000, ease: EXPO }; };

  /** One watched element and the parts that animate with it. Reveals repeat in both directions (viewport once:false). */
  function reveal(watch, parts, amount, margin) {
    if (!watch) return;
    var list = parts.filter(function (p) { return p.el; });
    list.forEach(function (p) { p.anim = null; hide(p); });
    watch.__pfReveal = { parts: list, inView: false };
    observerFor(amount, margin || '0px').observe(watch);
  }
  /** fadeUp / fadeIn presets (lib/motion/presets.ts): the exit uses the same timing. */
  function fadeUp(el, delaySec) {
    var t = T_UP(delaySec);
    reveal(el, [{ el: el, op: 0, tf: 'translateY(28px)', inT: t, outT: t }], 0.2);
  }
  function fadeIn(el, delaySec) {
    var t = { dur: 450, delay: delaySec * 1000, ease: EXPO };
    reveal(el, [{ el: el, op: 0, inT: t, outT: t }], 0.15);
  }

  function scrollReveals() {
    if (!canAnimate || !hasIO || reduce) return;

    // section headings: the title rises and sharpens from a blur, then "See all" follows (RevealHeading)
    ['#new-arrivals-row > .YnyroG_sectionHeading', '#reviews > .YnyroG_sectionHeading'].forEach(function (sel) {
      var box = qs(sel);
      if (!box) return;
      var h2 = qs('h2', box);
      var link = qs('.YnyroG_seeAll', box);
      var out = { dur: 400, delay: 0, ease: 'ease-out' }; // framer's default for a variant without its own timing
      reveal(box, [
        { el: h2, op: 0, tf: 'translateY(26px)', fl: 'blur(6px)', inT: { dur: 800, delay: 0, ease: EXPO }, outT: out },
        { el: link, op: 0, tf: 'translateX(-10px)', inT: { dur: 800, delay: 250, ease: EXPO }, outT: out }
      ], 0.6);
    });

    // product cards: fade up one after another (index % 4 * 0.09s), amount 0.15, bottom margin -6%
    qsa('.JEjkwq_card').forEach(function (card, i) {
      reveal(card, [{ el: card, op: 0, tf: 'translateY(36px)',
        inT: { dur: 700, delay: (i % 4) * 90, ease: EXPO }, outT: { dur: 220, delay: 0, ease: 'ease-out' } }], 0.15, '0px 0px -6% 0px');
    });

    // reviews frame
    var frameEl = qs('.YnyroG_reviewFrame');
    if (frameEl) {
      var t = { dur: 800, delay: 200, ease: EXPO };
      reveal(frameEl, [{ el: frameEl, op: 0, tf: 'translateY(32px)', inT: t, outT: t }], 0.2);
    }

    // try-on: three blocks
    qsa('#try-on > div:not([data-pf])').forEach(function (el) { fadeUp(el, 0); });

    // exclusive offers
    fadeUp(qs('.YnyroG_exclusiveOffers'), 0);
    fadeUp(qs('.YnyroG_exclusiveOffers > h2'), 0);
    qsa('.YnyroG_offerCard').forEach(function (el, i) { fadeUp(el, 0.1 + i * 0.12); });

    // join our world
    var copy = '.YnyroG_socialCopy > ';
    fadeUp(qs(copy + '.YnyroG_eyebrow'), 0);
    fadeUp(qs(copy + 'h2'), 0.08);
    fadeUp(qs(copy + 'h2 + p'), 0.16);
    fadeUp(qs(copy + '.YnyroG_socialLinks'), 0.24);
    fadeUp(qs(copy + '.YnyroG_whatsapp'), 0.32);
    fadeUp(qs(copy + '.YnyroG_joinStrip'), 0.4);
    fadeIn(qs('.YnyroG_portrait'), 0.1);

    // latest from our socials
    fadeUp(qs('._4EXm3W_inner'), 0);
    qsa('._4EXm3W_card').forEach(function (el, i) { fadeUp(el, 0.08 + i * 0.1); });

    // why Pratyangi: heading, cards (amount 0.3) and the icon that pops in a moment after its card
    fadeUp(qs('.OL4btW_section > h2'), 0);
    qsa('.OL4btW_card').forEach(function (card, i) {
      var cardT = { dur: 700, delay: 100 + i * 90, ease: EXPO };
      reveal(card, [{ el: card, op: 0, tf: 'translateY(36px)', inT: cardT, outT: cardT }], 0.3);
      var icon = qs(':scope > span', card);
      var iconT = { dur: 600, delay: 300 + i * 90, ease: EXPO };
      if (icon) reveal(icon, [{ el: icon, op: 0, tf: 'scale(0.6)', inT: iconT, outT: iconT }], 0.3);
    });

    // newsletter
    var news = doc.getElementById('newsletter');
    if (news) fadeUp(news.parentElement, 0);
    fadeUp(qs('.YnyroG_newsletterContent > h2'), 0);
    fadeUp(qs('.YnyroG_newsletterForm'), 0.12);
    fadeUp(qs('.YnyroG_newsletterNote'), 0.24);

    // footer
    fadeUp(qs('.f_viWW_footerBrand'), 0);
    qsa('.f_viWW_footerColumn').forEach(function (el, i) { fadeUp(el, 0.08 + i * 0.08); });
    fadeUp(qs('.f_viWW_paymentRow'), 0.1);
  }

  /* ------------------------------------------------------------------ 4. parallax (portrait and social photos) */

  function parallax() {
    if (reduce) return;
    var layers = [];
    qsa('.YnyroG_portraitLayer').forEach(function (el) { layers.push({ el: el, range: 3, y: 0 }); });
    qsa('._4EXm3W_photoLayer').forEach(function (el) { layers.push({ el: el, range: 2.5, y: 0 }); });
    if (!layers.length || !win.matchMedia) return;
    var wide = win.matchMedia('(min-width: 701px)'); // minWidth 701 like ParallaxLayer
    var queued = false;

    function update() {
      queued = false;
      var vh = win.innerHeight;
      layers.forEach(function (layer) {
        if (!wide.matches) {
          if (layer.y !== null) { layer.el.style.removeProperty('transform'); layer.y = null; }
          return;
        }
        var rect = layer.el.getBoundingClientRect();
        var h = rect.height;
        if (!h) return;
        var top = rect.top - (layer.y ? (layer.y / 100) * h : 0); // position without our own shift
        // offset ["start end", "end start"]: 0 when the layer's top meets the viewport bottom, 1 when its bottom meets the top
        var p = clamp((vh - top) / (vh + h), 0, 1);
        layer.y = -layer.range + 2 * layer.range * p;
        layer.el.style.transform = 'translateY(' + layer.y + '%)';
      });
    }
    function schedule() { if (!queued) { queued = true; win.requestAnimationFrame(update); } }
    win.addEventListener('scroll', schedule, { passive: true });
    win.addEventListener('resize', schedule);
    if (wide.addEventListener) wide.addEventListener('change', schedule);
    update();
  }

  /* ------------------------------------------------------------------ 5. smooth wheel scroll (Lenis: lerp 0.085, wheel x0.9, anchors offset -80) */

  var smooth = { go: null };

  function smoothScroll() {
    if (reduce) return;
    var target = win.scrollY;
    var current = target;
    var raf = 0;
    var last = 0;
    var animating = false;
    var lambda = 0.085 * 60; // Lenis damps with lerp * 60 per second

    function maxScroll() { return Math.max(0, doc.documentElement.scrollHeight - win.innerHeight); }

    function tick(time) {
      var dt = last ? Math.min((time - last) / 1000, 0.1) : 1 / 60;
      last = time;
      current += (target - current) * (1 - Math.exp(-lambda * dt));
      if (Math.abs(target - current) < 0.5) current = target;
      win.scrollTo(0, current);
      if (current === target) { stop(); return; }
      raf = win.requestAnimationFrame(tick);
    }
    function start() { if (!animating) { animating = true; last = 0; raf = win.requestAnimationFrame(tick); } }
    function stop() { win.cancelAnimationFrame(raf); animating = false; raf = 0; last = 0; }

    // does something under the pointer scroll by itself in this direction (menus, dialogs, text areas)?
    function scrollsInside(node, dy) {
      for (var el = node; el && el !== doc.body && el !== doc.documentElement; el = el.parentElement) {
        var cs = win.getComputedStyle(el);
        if ((cs.overflowY === 'auto' || cs.overflowY === 'scroll') && el.scrollHeight > el.clientHeight + 1) {
          if (dy < 0 ? el.scrollTop > 0 : el.scrollTop + el.clientHeight < el.scrollHeight - 1) return true;
        }
      }
      return false;
    }

    win.addEventListener('wheel', function (event) {
      if (event.defaultPrevented || event.ctrlKey || event.metaKey) return; // ctrl+wheel is zoom
      if (Math.abs(event.deltaX) > Math.abs(event.deltaY)) return;
      if (event.deltaY === 0 || scrollsInside(event.target, event.deltaY)) return;
      var dy = event.deltaY;
      if (event.deltaMode === 1) dy *= 16; else if (event.deltaMode === 2) dy *= win.innerHeight;
      if (!animating) { current = target = win.scrollY; }
      target = clamp(target + dy * 0.9, 0, maxScroll());
      event.preventDefault();
      start();
    }, { passive: false });

    // anything else that moves the page (keys, scrollbar, touch) wins: follow it
    win.addEventListener('scroll', function () {
      if (animating) {
        if (Math.abs(win.scrollY - current) > 2) { stop(); current = target = win.scrollY; }
      } else {
        current = target = win.scrollY;
      }
    }, { passive: true });

    smooth.go = function (y) { // used by in-page links
      if (!animating) current = win.scrollY;
      target = clamp(y, 0, maxScroll());
      start();
    };

    // in-page links (#arrivals, /#arrivals): glide there, 80px above the target
    doc.addEventListener('click', function (event) {
      if (event.defaultPrevented || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return;
      var a = event.target && event.target.closest ? event.target.closest('a[href]') : null;
      if (!a || a.target === '_blank') return;
      var url;
      try { url = new URL(a.href, win.location.href); } catch (e) { return; }
      if (url.origin !== win.location.origin || url.pathname !== win.location.pathname || !url.hash || url.hash.length < 2) return;
      var el = doc.getElementById(decodeURIComponent(url.hash.slice(1)));
      if (!el) return;
      event.preventDefault();
      smooth.go(el.getBoundingClientRect().top + win.scrollY - 80);
    });
  }

  /* ------------------------------------------------------------------ 6. header: shop menu, search dialog, account */

  var header = {};

  function initHeader() {
    var toggle = qs('[data-pf="shop-toggle"]');
    var panel = qs('[data-pf="menu-panel"]');
    var backdrop = qs('[data-pf="menu-backdrop"]');
    var icon = qs('.pSCCwa_menuIcon');
    var searchOpen = qs('[data-pf="search-open"]');
    var scrim = qs('[data-pf="search-scrim"]');
    var dialog = qs('[data-pf="search-dialog"]');
    var closeBtn = qs('[data-pf="search-close"]');
    var input = qs('#catalog-search');
    var results = qs('[data-pf="search-results"]');
    var empty = qs('[data-pf="search-empty"]');
    if (!toggle || !panel) return;

    var menuOpen = false;
    var searchIsOpen = false;
    var menuExit = null;
    var scrimExit = null;

    function setMenu(open) {
      if (open === menuOpen) return;
      menuOpen = open;
      toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
      if (icon) icon.setAttribute('data-open', open ? 'true' : 'false');
      if (backdrop) backdrop.hidden = !open;
      if (menuExit) { menuExit.cancel(); menuExit = null; }
      if (open) {
        panel.hidden = false;
        // the panel unrolls from the top; its columns rise in one after another (0.12s + 0.07s each)
        play(panel, { opacity: 0, clipPath: 'inset(0 0 100% 0)' }, { opacity: 1, clipPath: 'inset(0 0 0% 0)' }, 420, 0);
        qsa('.pSCCwa_menuGroup', panel).forEach(function (group, i) {
          play(group, { opacity: 0, transform: 'translateY(14px)' }, { opacity: 1, transform: 'none' }, 450, 120 + i * 70);
        });
      } else if (canAnimate && !reduce) {
        var a = panel.animate([{ opacity: 1, clipPath: 'inset(0 0 0% 0)' }, { opacity: 0, clipPath: 'inset(0 0 100% 0)' }], { duration: 420, easing: EXPO, fill: 'forwards' });
        menuExit = a;
        a.onfinish = function () { panel.hidden = true; a.cancel(); if (menuExit === a) menuExit = null; };
      } else {
        panel.hidden = true;
      }
    }

    function openSearch() {
      if (!scrim || !dialog || searchIsOpen) return;
      searchIsOpen = true;
      setMenu(false);
      if (scrimExit) { scrimExit.forEach(function (a) { a.cancel(); }); scrimExit = null; }
      scrim.hidden = false;
      play(scrim, { opacity: 0 }, { opacity: 1 }, 300, 0, 'ease');
      play(dialog, { opacity: 0, transform: 'translateY(16px) scale(0.98)' }, { opacity: 1, transform: 'none' }, 240, 0);
      if (input) input.focus();
    }
    function closeSearch() {
      if (!searchIsOpen) return;
      searchIsOpen = false;
      if (searchOpen) searchOpen.focus();
      if (canAnimate && !reduce) {
        var a1 = scrim.animate([{ opacity: 1 }, { opacity: 0 }], { duration: 300, easing: 'ease', fill: 'forwards' });
        var a2 = dialog.animate([{ opacity: 1, transform: 'none' }, { opacity: 0, transform: 'translateY(12px) scale(0.98)' }], { duration: 240, easing: EXPO, fill: 'forwards' });
        scrimExit = [a1, a2];
        a1.onfinish = function () { scrim.hidden = true; a1.cancel(); a2.cancel(); scrimExit = null; };
      } else {
        scrim.hidden = true;
      }
    }

    toggle.addEventListener('click', function () { setMenu(!menuOpen); });
    if (backdrop) backdrop.addEventListener('mousedown', function () { setMenu(false); });
    qsa('.pSCCwa_menuGroup a', panel).forEach(function (a) { a.addEventListener('click', function () { setMenu(false); }); });
    if (searchOpen) searchOpen.addEventListener('click', openSearch);
    if (closeBtn) closeBtn.addEventListener('click', closeSearch);
    if (scrim) scrim.addEventListener('mousedown', function (event) { if (event.target === scrim) closeSearch(); });

    // under 2 characters: the quick links filter locally. From 2 characters: the real catalogue (/api/v1/catalog/products?q=), 300 ms after typing stops
    var productsBox = qs('[data-pf="search-products"]');
    var imgBase = dialog ? dialog.getAttribute('data-pf-img') || '' : '';
    var typing = 0;
    var inflight = null;
    function showLinks(term) {
      var any = false;
      qsa('a[data-pf-text]', results).forEach(function (a) {
        var words = (a.getAttribute('data-pf-text') || '').split('|');
        var hit = !term || words.some(function (w) { return w.indexOf(term) !== -1; });
        a.style.display = hit ? '' : 'none';
        if (hit) any = true;
      });
      if (empty) { empty.textContent = 'No matching items.'; empty.hidden = any; }
    }
    function showProducts(products) {
      productsBox.textContent = '';
      products.forEach(function (p) {
        var a = doc.createElement('a');
        a.href = '/product?sku=' + encodeURIComponent(p.sku);
        a.className = 'pf_searchHit';
        var img = doc.createElement('img');
        img.alt = '';
        img.width = 48; img.height = 48;
        img.src = imgBase && p.imagePath ? imgBase + String(p.imagePath).replace(/^\/+/, '') : '/assets/images/reference/product-1.webp';
        var name = doc.createElement('span');
        name.textContent = p.name;
        var price = doc.createElement('strong');
        price.textContent = '₹' + Number(p.price).toLocaleString('en-IN');
        a.appendChild(img); a.appendChild(name); a.appendChild(price);
        a.addEventListener('click', closeSearch);
        productsBox.appendChild(a);
      });
      productsBox.hidden = !products.length;
      if (empty) { empty.textContent = 'No products found'; empty.hidden = products.length > 0; }
    }
    function searchCatalog(term) {
      if (inflight) inflight.abort();
      inflight = new win.AbortController();
      var mine = inflight;
      win.fetch('/api/v1/catalog/products?limit=8&q=' + encodeURIComponent(term), { signal: mine.signal, credentials: 'same-origin' })
        .then(function (response) { if (!response.ok) throw new Error('search'); return response.json(); })
        .then(function (data) { showProducts(Array.isArray(data.products) ? data.products : []); })
        .catch(function (error) {
          if (error && error.name === 'AbortError') return; // a newer search replaced this one
          productsBox.hidden = true;
          if (empty) { empty.textContent = 'Search is not available right now.'; empty.hidden = false; }
        });
    }
    function filter() {
      var term = input ? input.value.trim().toLowerCase() : '';
      win.clearTimeout(typing);
      if (inflight) { inflight.abort(); inflight = null; }
      var long = term.length >= 2;
      results.classList.toggle('pf_searchLive', long);
      if (!long) { if (productsBox) productsBox.hidden = true; showLinks(term); return; }
      if (empty) empty.hidden = true;
      typing = win.setTimeout(function () { searchCatalog(term); }, 300);
    }
    if (input) {
      input.addEventListener('input', filter);
      input.addEventListener('keydown', function (event) {
        var term = input.value.trim();
        if (event.key === 'Enter' && term.length >= 2) win.location.assign('/search?q=' + encodeURIComponent(term));
      });
    }
    if (results) qsa('a', results).forEach(function (a) { a.addEventListener('click', closeSearch); });

    // Esc closes the search dialog first, otherwise the menu; Tab stays inside the open dialog
    win.addEventListener('keydown', function (event) {
      if (!searchIsOpen && !menuOpen) return;
      if (event.key === 'Escape') {
        if (searchIsOpen) closeSearch(); else setMenu(false);
        return;
      }
      if (event.key !== 'Tab' || !searchIsOpen || !dialog) return;
      var focusable = qsa('a[href], button:not([disabled]), input', dialog).filter(function (el) { return el.style.display !== 'none'; });
      if (!focusable.length) return;
      var first = focusable[0];
      var lastEl = focusable[focusable.length - 1];
      if (event.shiftKey && doc.activeElement === first) { event.preventDefault(); lastEl.focus(); }
      else if (!event.shiftKey && doc.activeElement === lastEl) { event.preventDefault(); first.focus(); }
    });

    header.setMenu = setMenu;
  }

  /* ------------------------------------------------------------------ 7. category carousel */

  function categories() {
    var track = qs('.YnyroG_categoryTrack');
    var left = qs('.YnyroG_leftArrow');
    var right = qs('.YnyroG_rightArrow');
    if (!track || !left || !right) return;
    function sync() {
      var max = track.scrollWidth - track.clientWidth;
      left.disabled = track.scrollLeft <= 2;
      right.disabled = max <= 2 || track.scrollLeft >= max - 2;
    }
    function move(direction) {
      var card = qs('a', track);
      var step = card ? card.getBoundingClientRect().width : 248;
      track.scrollTo({ left: track.scrollLeft + direction * step, behavior: reduce ? 'auto' : 'smooth' });
    }
    left.addEventListener('click', function () { move(-1); });
    right.addEventListener('click', function () { move(1); });
    track.addEventListener('scroll', sync, { passive: true });
    if (typeof win.ResizeObserver === 'function') new win.ResizeObserver(sync).observe(track);
    sync();
  }

  /* ------------------------------------------------------------------ 8. customer reviews marquee (useMarquee: 36 px/s, resume after 2.2 s) */

  function marquee() {
    var row = qs('.YnyroG_reviewViewport');
    if (!row || reduce || !hasIO) return;
    var speed = 36;
    var resumeDelay = 2200;
    function group() { return row.scrollWidth / 3; }
    var position = group();
    row.scrollLeft = position;
    var frameId = 0, last = 0, hovering = false, touching = false, focused = false, dragging = false, resumeAt = 0, lastSet = row.scrollLeft;
    function holdFor(ms) { resumeAt = performance.now() + (ms === undefined ? resumeDelay : ms); }
    function wrap() {
      var width = group();
      if (!width) return;
      if (row.scrollLeft >= width * 2) row.scrollLeft -= width;
      else if (row.scrollLeft <= 0) row.scrollLeft += width;
    }
    function tick(time) {
      var dt = last ? Math.min(time - last, 64) : 0;
      last = time;
      if (!hovering && !touching && !focused && !dragging && time >= resumeAt && !doc.hidden) {
        position += (speed * dt) / 1000;
        if (position >= group() * 2) position -= group();
        row.scrollLeft = position;
        lastSet = row.scrollLeft;
      } else {
        position = row.scrollLeft; // follow wherever the visitor has taken it
      }
      frameId = win.requestAnimationFrame(tick);
    }
    function start() { if (!frameId) { last = 0; frameId = win.requestAnimationFrame(tick); } }
    function stop() { win.cancelAnimationFrame(frameId); frameId = 0; }
    new win.IntersectionObserver(function (entries) {
      if (entries[0].isIntersecting) start(); else stop(); // only runs while on screen
    }).observe(row);

    row.addEventListener('scroll', function () {
      if (Math.abs(row.scrollLeft - lastSet) > 1.5) holdFor();
      wrap();
      lastSet = row.scrollLeft;
    }, { passive: true });

    var dragStartX = 0, dragStartLeft = 0;
    row.addEventListener('pointerdown', function (event) {
      if (event.pointerType !== 'mouse' || event.button !== 0) return;
      dragging = true;
      dragStartX = event.clientX;
      dragStartLeft = row.scrollLeft;
      row.setPointerCapture(event.pointerId);
      row.dataset.dragging = 'true';
    });
    row.addEventListener('pointermove', function (event) {
      if (dragging) row.scrollLeft = dragStartLeft - (event.clientX - dragStartX);
    });
    function endDrag(event) {
      if (!dragging) return;
      dragging = false;
      delete row.dataset.dragging;
      holdFor();
      if (row.hasPointerCapture(event.pointerId)) row.releasePointerCapture(event.pointerId);
    }
    row.addEventListener('pointerup', endDrag);
    row.addEventListener('pointercancel', endDrag);
    row.addEventListener('pointerenter', function (event) { if (event.pointerType === 'mouse') hovering = true; });
    row.addEventListener('pointerleave', function (event) { if (event.pointerType === 'mouse') { hovering = false; holdFor(600); } });
    row.addEventListener('touchstart', function () { touching = true; }, { passive: true });
    function touchEnd() { touching = false; holdFor(); }
    row.addEventListener('touchend', touchEnd, { passive: true });
    row.addEventListener('touchcancel', touchEnd, { passive: true });
    row.addEventListener('wheel', function () { holdFor(); }, { passive: true });
    row.addEventListener('focusin', function () { focused = true; });
    row.addEventListener('focusout', function () { focused = false; holdFor(); });
    if (typeof win.ResizeObserver === 'function') {
      new win.ResizeObserver(function () { position = row.scrollLeft; wrap(); }).observe(row);
    }
  }

  /* ------------------------------------------------------------------ 9. try-on: compare slider and the access dialog */

  function tryOn() {
    qsa('.mH2uza_compare').forEach(function (box) {
      var range = qs('.mH2uza_range', box);
      if (range) range.addEventListener('input', function () { box.style.setProperty('--split', Number(range.value) + '%'); });
    });

    var scrim = qs('[data-pf="tryon-scrim"]');
    var dialog = qs('[data-pf="tryon-dialog"]');
    if (!scrim || !dialog) return;
    var title = qs('[data-pf="tryon-title"]', dialog);
    var text = qs('[data-pf="tryon-text"]', dialog);
    var silverBtn = qs('[data-pf="tryon-silver"]', dialog);
    var isOpen = false;
    var exitAnims = null;

    var views = {
      silver: { title: 'AI Try-On', text: 'AI Try-On is not part of this version of the store yet. It arrives in a later build phase.', silver: false },
      platinum: { title: 'Unlock Platinum Try-On', text: 'Platinum Try-On unlocks once a Silver saree is in your bag. Add one now to try on Platinum sarees straight away.', silver: true },
      gold: { title: 'Gold Collection', text: 'Our Gold sarees are available for direct purchase only, so AI Try-On isn’t offered for this collection.', silver: true }
    };
    function show(tier) {
      var v = views[tier] || views.silver;
      title.textContent = v.title;
      text.textContent = v.text;
      silverBtn.hidden = !v.silver;
    }
    function open(tier) {
      show(tier);
      if (isOpen) return;
      isOpen = true;
      if (exitAnims) { exitAnims.forEach(function (a) { a.cancel(); }); exitAnims = null; }
      scrim.hidden = false;
      play(scrim, { opacity: 0 }, { opacity: 1 }, 300, 0, 'ease');
      play(dialog, { opacity: 0, transform: 'translateY(16px) scale(0.98)' }, { opacity: 1, transform: 'none' }, 240, 0);
    }
    function close() {
      if (!isOpen) return;
      isOpen = false;
      if (canAnimate && !reduce) {
        var a1 = scrim.animate([{ opacity: 1 }, { opacity: 0 }], { duration: 300, easing: 'ease', fill: 'forwards' });
        var a2 = dialog.animate([{ opacity: 1, transform: 'none' }, { opacity: 0, transform: 'translateY(12px) scale(0.98)' }], { duration: 240, easing: EXPO, fill: 'forwards' });
        exitAnims = [a1, a2];
        a1.onfinish = function () { scrim.hidden = true; a1.cancel(); a2.cancel(); exitAnims = null; };
      } else {
        scrim.hidden = true;
      }
    }

    qsa('._3CJUpq_primary, ._3CJUpq_uploadButton').forEach(function (b) {
      if (!dialog.contains(b)) b.addEventListener('click', function () { open('silver'); });
    });
    qsa('._3CJUpq_tierButton').forEach(function (b) {
      var card = b.closest('article');
      var tier = card && /_3CJUpq_gold\b/.test(card.className) ? 'gold' : card && /_3CJUpq_platinum\b/.test(card.className) ? 'platinum' : 'silver';
      b.addEventListener('click', function () { open(tier); });
    });
    silverBtn.addEventListener('click', function () { show('silver'); });
    qs('[data-pf="tryon-close"]', dialog).addEventListener('click', close);
    scrim.addEventListener('mousedown', function (event) { if (event.target === scrim) close(); });
    win.addEventListener('keydown', function (event) { if (isOpen && event.key === 'Escape') close(); });
  }

  /* ------------------------------------------------------------------ 10. offers and newsletter */

  function forms() {
    var offers = qs('.YnyroG_exclusiveOffers');
    var message = null;
    qsa('.YnyroG_offerCard button').forEach(function (button, index) {
      button.addEventListener('click', function () {
        if (!message) {
          message = doc.createElement('p');
          message.className = 'YnyroG_offerMessage';
          message.setAttribute('role', 'status');
          offers.appendChild(message);
        }
        message.textContent = index === 0
          ? 'Local demo only — code FIRST20 is displayed but cannot be redeemed.'
          : 'Local demo only — this offer is not redeemable.';
      });
    });

    var form = qs('.YnyroG_newsletterForm');
    var note = qs('.YnyroG_newsletterNote');
    if (form && note) {
      form.addEventListener('submit', function (event) {
        event.preventDefault();
        var email = qs('input', form);
        if (!email.value.trim()) {
          note.textContent = 'Please enter your email address.';
          return;
        }
        note.textContent = 'Thanks for joining us — this is a local demo, no subscription was sent.';
        email.value = '';
      });
    }
  }

  /* ------------------------------------------------------------------ 11. Logout button (AccountShell) */

  // The web's Logout: POST /auth/logout (cookie sent, no body, no Content-Type), then the home page. A failed call changes nothing.
  function logout() {
    qsa('[data-pf="logout"]').forEach(function (button) {
      button.addEventListener('click', function () {
        if (button.disabled) return;
        button.disabled = true;
        win.fetch('/api/v1/auth/logout', { method: 'POST', credentials: 'include' }).then(function (response) {
          if (response.ok) win.location.assign('/'); else button.disabled = false;
        }).catch(function () { button.disabled = false; });
      });
    });
  }

  /* ------------------------------------------------------------------ 11b. Header bag count */

  // The web's header shows the sum of the bag quantities (lib/bag.ts) and says it in the link's label. It follows the bag live: this tab
  // (window event "pratyangi-bag-change") and other tabs (the browser "storage" event). An unreadable bag counts as empty.
  var BAG_KEY = 'pratyangi-demo-bag';
  var BAG_EVENT = 'pratyangi-bag-change';

  function bagCountNow() {
    try {
      var parsed = JSON.parse(win.localStorage.getItem(BAG_KEY));
      if (!Array.isArray(parsed)) return 0;
      var total = 0;
      for (var i = 0; i < parsed.length; i++) {
        var entry = parsed[i];
        var line = typeof entry === 'string' ? { id: entry, quantity: 1 } : entry;
        if (!line || typeof line.id !== 'string' || typeof line.quantity !== 'number') return 0;
        total += Math.min(Math.max(1, Math.round(line.quantity)), 10);
      }
      return total;
    } catch (error) {
      return 0;
    }
  }

  function bagCount() {
    function show() {
      var n = bagCountNow();
      qsa('[data-pf="bag-count"]').forEach(function (span) { span.textContent = String(n); });
      qsa('[data-pf="bag-link"]').forEach(function (link) { link.setAttribute('aria-label', 'Open your bag, ' + n + ' items'); });
    }
    show();
    win.addEventListener('storage', function (event) { if (event.key === null || event.key === BAG_KEY) show(); });
    win.addEventListener(BAG_EVENT, show);
  }

  /* ------------------------------------------------------------------ 11b2. The shared empty state, for lists drawn in the browser */

  // The same box as partials/empty_state.php. actions: [{label, href}]; the first is the filled button.
  var EMPTY_ICONS = {
    'shopping-bag': '<path d="M6 2 3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4Z"></path><path d="M3 6h18"></path><path d="M16 10a4 4 0 0 1-8 0"></path>',
    heart: '<path d="M19 14c1.49-1.46 3-3.21 3-5.5A5.5 5.5 0 0 0 16.5 3c-1.76 0-3 .5-4.5 2-1.5-1.5-2.74-2-4.5-2A5.5 5.5 0 0 0 2 8.5c0 2.3 1.5 4.05 3 5.5l7 7Z"></path>'
  };
  win.PratyangiEmptyState = function (icon, title, line) {
    var box = doc.createElement('div');
    box.className = 'pf_emptyState';
    var svg = '<svg xmlns="http://www.w3.org/2000/svg" width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' + EMPTY_ICONS[icon] + '</svg>';
    box.insertAdjacentHTML('afterbegin', svg);
    [['h2', title], ['p', line]].forEach(function (pair) { if (!pair[1]) return; var n = doc.createElement(pair[0]); n.textContent = pair[1]; box.appendChild(n); });
    var actions = doc.createElement('div');
    actions.className = 'pf_emptyActions';
    var shop = doc.createElement('a');
    shop.className = 'pf_emptyBtn';
    shop.href = '/new-arrivals';
    shop.textContent = 'Start shopping';
    actions.appendChild(shop);
    box.appendChild(actions);
    return box;
  };

  /* ------------------------------------------------------------------ 11c. Wishlist (browser only), quick view, picture fallback */

  // shortcut: the wishlist lives in this browser only (a JSON array of SKUs, like the bag); move it to the database with accounts later.
  var WISH_KEY = 'pratyangi-demo-wishlist';
  var WISH_EVENT = 'pratyangi-wishlist-change';
  var PLACEHOLDER = '/assets/images/reference/product-1.webp';

  function wishRead() {
    try {
      var parsed = JSON.parse(win.localStorage.getItem(WISH_KEY));
      return Array.isArray(parsed) ? parsed.filter(function (s) { return typeof s === 'string'; }) : [];
    } catch (error) {
      return [];
    }
  }

  function wishWrite(list) {
    try { win.localStorage.setItem(WISH_KEY, JSON.stringify(list)); } catch (error) { return; }
    win.dispatchEvent(new win.Event(WISH_EVENT));
  }

  win.PratyangiWishlist = {
    KEY: WISH_KEY, EVENT: WISH_EVENT, read: wishRead,
    add: function (sku) { var list = wishRead(); if (list.indexOf(sku) === -1) wishWrite(list.concat(sku)); },
    remove: function (sku) { wishWrite(wishRead().filter(function (s) { return s !== sku; })); }
  };

  function wishlist() {
    function paint() {
      var list = wishRead();
      qsa('[data-pf="wish-count"]').forEach(function (span) { span.textContent = String(list.length); });
      qsa('[data-pf="wish-link"]').forEach(function (link) { link.setAttribute('aria-label', 'Open your wishlist, ' + list.length + ' items'); });
      qsa('[data-pf="wish"]').forEach(function (button) {
        var on = list.indexOf(button.getAttribute('data-sku')) !== -1;
        button.setAttribute('aria-pressed', on ? 'true' : 'false');
        button.classList.toggle('pf_wished', on);
        if (button.classList.contains('pf_wishText')) button.textContent = on ? 'Remove from wishlist' : 'Add to wishlist';
        else button.setAttribute('aria-label', on ? 'Remove from wishlist' : 'Add to wishlist');
      });
    }
    doc.addEventListener('click', function (event) {
      var button = event.target.closest ? event.target.closest('[data-pf="wish"]') : null;
      var sku = button ? button.getAttribute('data-sku') : '';
      if (!sku) return;
      var list = wishRead();
      wishWrite(list.indexOf(sku) === -1 ? list.concat(sku) : list.filter(function (s) { return s !== sku; }));
    });
    paint();
    win.addEventListener(WISH_EVENT, paint);
    win.addEventListener('storage', function (event) { if (event.key === null || event.key === WISH_KEY) paint(); });
    return paint;
  }

  // A picture the ERP cannot serve shows the placeholder, on every page (the picture paths all sit under /uploads/products/).
  function pictureFallback() {
    function swap(img) {
      if (img.src.indexOf('/uploads/products/') !== -1 && !img.hasAttribute('data-pf-fallback')) {
        img.setAttribute('data-pf-fallback', '');
        img.src = PLACEHOLDER;
      }
    }
    doc.addEventListener('error', function (event) { if (event.target && event.target.tagName === 'IMG') swap(event.target); }, true);
    qsa('img').forEach(function (img) { if (img.complete && img.naturalWidth === 0) swap(img); });
  }

  // The eye on a product card: a dialog built from the card's data-qv-* values. Add to bag and the heart use the same delegated buttons as the cards.
  function quickView(paint) {
    var open = null;
    function el(tag, className, text) {
      var node = doc.createElement(tag);
      if (className) node.className = className;
      if (text) node.textContent = text;
      return node;
    }
    function close() {
      if (!open) return;
      open.scrim.remove();
      doc.body.style.overflow = open.overflow;
      var back = open.opener;
      open = null;
      if (back) back.focus();
    }
    function show(card, opener) {
      close();
      var d = function (name) { return card.getAttribute('data-qv-' + name) || ''; };
      var scrim = el('div', 'pf_qv_scrim');
      var box = el('section', 'pf_qv');
      box.setAttribute('role', 'dialog');
      box.setAttribute('aria-modal', 'true');
      box.setAttribute('aria-label', d('name'));
      var x = el('button', 'pf_qv_close', '×');
      x.type = 'button';
      x.setAttribute('aria-label', 'Close quick view');
      var img = el('img', 'pf_qv_img');
      img.alt = d('name');
      img.src = d('img');
      var info = el('div', 'pf_qv_info');
      info.appendChild(el('h2', '', d('name')));
      if (d('sub')) info.appendChild(el('p', 'pf_qv_sub', d('sub')));
      var price = el('p', 'pf_qv_price');
      price.appendChild(el('strong', '', d('price')));
      if (d('mrp')) price.appendChild(el('del', '', d('mrp')));
      info.appendChild(price);
      if (d('tax')) info.appendChild(el('p', 'pf_qv_line', d('tax')));
      if (d('size')) info.appendChild(el('p', 'pf_qv_line', 'Size: ' + d('size')));
      info.appendChild(el('p', d('instock') === '1' ? 'pf_qv_line' : 'pf_qv_line pf_qv_out', d('stock')));
      var add = el('button', 'pf_qv_add', d('instock') === '1' ? 'Add to bag' : 'Out of stock');
      add.type = 'button';
      add.setAttribute('data-pf', 'add-to-bag');
      add.setAttribute('data-sku', d('sku'));
      if (d('instock') !== '1') add.disabled = true;
      var wish = el('button', 'pf_qv_wish pf_wishText', 'Add to wishlist');
      wish.type = 'button';
      wish.setAttribute('data-pf', 'wish');
      wish.setAttribute('data-sku', d('sku'));
      var link = el('a', 'pf_qv_link', 'View full details');
      link.href = d('href');
      [add, wish, link].forEach(function (n) { info.appendChild(n); });
      box.appendChild(x); box.appendChild(img); box.appendChild(info);
      scrim.appendChild(box);
      doc.body.appendChild(scrim);
      open = { scrim: scrim, box: box, opener: opener, overflow: doc.body.style.overflow };
      doc.body.style.overflow = 'hidden';
      x.addEventListener('click', close);
      scrim.addEventListener('mousedown', function (event) { if (event.target === scrim) close(); });
      add.addEventListener('click', function () { add.textContent = 'Added'; win.setTimeout(function () { add.textContent = 'Add to bag'; }, 1200); });
      paint();
      x.focus();
    }
    doc.addEventListener('click', function (event) {
      var button = event.target.closest ? event.target.closest('[data-pf="quick-view"]') : null;
      var card = button ? button.closest('[data-qv-sku]') : null;
      if (card) show(card, button);
    });
    doc.addEventListener('keydown', function (event) {
      if (!open) return;
      if (event.key === 'Escape') { event.stopPropagation(); close(); return; }
      if (event.key !== 'Tab') return;
      var items = qsa('button:not([disabled]), a[href]', open.box);
      var first = items[0];
      var last = items[items.length - 1];
      if (event.shiftKey && doc.activeElement === first) { event.preventDefault(); last.focus(); }
      else if (!event.shiftKey && doc.activeElement === last) { event.preventDefault(); first.focus(); }
    }, true);
  }

  /* ------------------------------------------------------------------ 12. "Try again" of the catalogue message */

  // The web's CatalogState retry asks for the catalogue again; the PHP page is built on the server, so it loads the page again.
  function retry() {
    qsa('[data-pf="retry"]').forEach(function (button) {
      button.addEventListener('click', function () { win.location.reload(); });
    });
  }

  /* ------------------------------------------------------------------ start */

  function init() {
    logout();
    bagCount();
    quickView(wishlist());
    // the account tabs scroll sideways on a phone: bring the open one into view
    var tab = qs('.pnvDaq_navActive');
    if (tab && tab.scrollIntoView) tab.scrollIntoView({ inline: 'center', block: 'nearest' });
    pictureFallback();
    retry();
    initHeader();
    categories();
    tryOn();
    forms();
    marquee();
    smoothScroll();
    scrollLinked();
    scrollReveals();
    parallax();
    entrances();
  }

  if (doc.readyState === 'loading') doc.addEventListener('DOMContentLoaded', init); else init();
})();
