/*
 * New Arrivals filters, sort and pagination, in plain JavaScript (no libraries, no inline scripts, no network).
 * The web's NewArrivalsView keeps the whole catalogue in the browser and filters it there; the PHP page prints the whole catalogue
 * as product cards (with data-price, data-category, data-fabric, data-color, data-instock) and this file shows, hides and orders them
 * with the same rules: category, price range, fabrics, colours, "In Stock" only; sort Featured (the catalogue's order) / price;
 * 9 cards a page; any change goes back to page 1; "Clear all filters"; the ?fabric= link already ticked on the server.
 * Without JavaScript the page still shows the (server-filtered) first page of cards.
 */
(function () {
  'use strict';

  var doc = document;
  var win = window;
  var root = doc.querySelector('[data-pf="listing"]');
  if (!root) return;

  function qs(sel, scope) { return (scope || root).querySelector(sel); }
  function qsa(sel, scope) { return Array.prototype.slice.call((scope || root).querySelectorAll(sel)); }

  var pageSize = Number(root.getAttribute('data-page-size')) || 9;
  var min = Number(root.getAttribute('data-min'));
  var max = Number(root.getAttribute('data-max'));
  var step = Number(root.getAttribute('data-step'));

  var grid = qs('[data-pf="grid"]');
  var empty = qs('[data-pf="empty"]');
  var pagination = qs('[data-pf="pagination"]');
  var countText = qs('[data-pf="count"]');
  var sortSelect = qs('[data-pf="sort"]');
  var priceValue = qs('[data-pf="price-value"]');
  var slider = qs('[data-pf="price-slider"]');
  var lowInput = qs('[data-pf="price-low"]');
  var highInput = qs('[data-pf="price-high"]');
  var resetPrice = qs('[data-pf="price-reset"]');
  var presets = qsa('[data-pf="price-preset"]');
  var categoryButtons = qsa('[data-pf="categories"] button');
  var fabricBoxes = qsa('[data-pf="fabric"]');
  var fabricChoices = qsa('[data-pf="fabric-choice"]');
  var fabricMore = qs('[data-pf="fabric-more"]');
  var colorChips = qsa('[data-pf="color"]');
  var inStockBox = qs('[data-pf="in-stock"]');
  var clearButtons = qsa('[data-pf="clear"]');
  if (!grid || !lowInput || !highInput) return;

  var cards = qsa('article.JEjkwq_card', grid).map(function (el, index) {
    return {
      el: el,
      index: index,
      price: parseFloat(el.getAttribute('data-price')),
      category: el.getAttribute('data-category') || '',
      fabric: el.getAttribute('data-fabric') || '',
      color: el.getAttribute('data-color') || '',
      inStock: el.getAttribute('data-instock') === '1'
    };
  });

  /** Everything the visitor can change. Read from the page at the start (the server has already ticked ?fabric= links). */
  var state = {
    category: null,
    low: min,
    high: max,
    fabrics: fabricBoxes.filter(function (box) { return box.checked; }).map(function (box) { return box.value; }),
    colors: [],
    inStock: false,
    sort: 'featured',
    page: 1,
    showAllFabrics: !fabricMore || fabricMore.textContent.indexOf('Less') !== -1
  };
  if (inStockBox) inStockBox.checked = false;
  if (sortSelect) sortSelect.value = 'featured';

  var lastOrder = '';
  var statusLine = null;

  /** 100000 -> "₹1,00,000" (Indian grouping, like toLocaleString("en-IN")). */
  function inr(value) {
    var digits = String(Math.abs(Math.round(value)));
    if (digits.length > 3) digits = digits.slice(0, -3).replace(/\B(?=(\d{2})+$)/g, ',') + ',' + digits.slice(-3);
    return '₹' + digits;
  }

  function toggle(list, value) {
    var at = list.indexOf(value);
    return at === -1 ? list.concat([value]) : list.filter(function (item) { return item !== value; });
  }

  function pageList(current, total) {
    var out = [];
    var i;
    if (total <= 7) { for (i = 1; i <= total; i++) out.push(i); return out; }
    if (current <= 4) return [1, 2, 3, 4, 5, 'gap', total];
    if (current >= total - 3) return [1, 'gap', total - 4, total - 3, total - 2, total - 1, total];
    return [1, 'gap', current - 1, current, current + 1, 'gap', total];
  }

  function matches(card) {
    return (!state.category || card.category === state.category)
      && card.price >= state.low && card.price <= state.high
      && (!state.fabrics.length || state.fabrics.indexOf(card.fabric) !== -1)
      && (!state.colors.length || state.colors.indexOf(card.color) !== -1)
      && (!state.inStock || card.inStock);
  }

  function hasFilters() {
    return !!(state.category || state.low !== min || state.high !== max || state.fabrics.length || state.colors.length || state.inStock);
  }

  function buildPagination(current, total) {
    pagination.textContent = '';
    function button(label, className, disabled, ariaLabel) {
      var b = doc.createElement('button');
      b.type = 'button';
      if (className) b.className = className;
      if (disabled) b.disabled = true;
      if (ariaLabel) b.setAttribute('aria-label', ariaLabel);
      b.textContent = label;
      return b;
    }
    var prev = button('‹', '_4HCqHa_pageArrow', current === 1, 'Previous page');
    prev.setAttribute('data-page', String(current - 1));
    pagination.appendChild(prev);
    pageList(current, total).forEach(function (entry) {
      if (entry === 'gap') {
        var gap = doc.createElement('span');
        gap.className = '_4HCqHa_pageGap';
        gap.textContent = '…';
        pagination.appendChild(gap);
        return;
      }
      var b = button(String(entry), '', false, '');
      if (entry === current) b.setAttribute('aria-current', 'page');
      b.setAttribute('data-page', String(entry));
      pagination.appendChild(b);
    });
    var next = button('›', '_4HCqHa_pageArrow', current === total, 'Next page');
    next.setAttribute('data-page', String(current + 1));
    pagination.appendChild(next);
  }

  /** Brings the page in line with the state: which cards show and in what order, counts, controls. */
  function apply() {
    var filtered = cards.filter(matches);
    if (state.sort === 'price-asc') filtered.sort(function (a, b) { return a.price - b.price || a.index - b.index; });
    else if (state.sort === 'price-desc') filtered.sort(function (a, b) { return b.price - a.price || a.index - b.index; });

    var totalPages = Math.max(1, Math.ceil(filtered.length / pageSize));
    var current = Math.min(state.page, totalPages);
    state.page = current;
    var visible = filtered.slice((current - 1) * pageSize, current * pageSize);

    // order in the page: the matching cards in sort order, then the rest
    var rest = cards.filter(function (card) { return filtered.indexOf(card) === -1; });
    var ordered = filtered.concat(rest);
    var signature = ordered.map(function (card) { return card.index; }).join(',');
    if (signature !== lastOrder) {
      lastOrder = signature;
      ordered.forEach(function (card) { grid.appendChild(card.el); });
    }
    cards.forEach(function (card) { card.el.hidden = visible.indexOf(card) === -1; });
    grid.hidden = visible.length === 0;
    if (empty) empty.hidden = visible.length > 0;

    if (countText) countText.textContent = filtered.length + ' ' + (filtered.length === 1 ? 'Product' : 'Products');

    if (pagination) {
      pagination.hidden = totalPages <= 1;
      if (totalPages > 1) buildPagination(current, totalPages);
    }

    // controls
    categoryButtons.forEach(function (b) {
      b.setAttribute('aria-pressed', (b.getAttribute('data-value') || '') === (state.category || '') ? 'true' : 'false');
    });
    fabricBoxes.forEach(function (box) { box.checked = state.fabrics.indexOf(box.value) !== -1; });
    colorChips.forEach(function (chip) {
      chip.setAttribute('aria-pressed', state.colors.indexOf(chip.getAttribute('data-value') || '') !== -1 ? 'true' : 'false');
    });
    if (inStockBox) inStockBox.checked = state.inStock;
    if (sortSelect) sortSelect.value = state.sort;

    if (priceValue) priceValue.textContent = inr(state.low) + ' – ' + inr(state.high) + (state.high === max ? '+' : '');
    var from = ((state.low - min) / (max - min)) * 100;
    var to = ((state.high - min) / (max - min)) * 100;
    if (slider) {
      slider.style.setProperty('--from', from + '%');
      slider.style.setProperty('--to', to + '%');
    }
    lowInput.value = String(state.low);
    highInput.value = String(state.high);
    lowInput.setAttribute('aria-valuetext', inr(state.low));
    highInput.setAttribute('aria-valuetext', inr(state.high));
    lowInput.style.zIndex = state.low >= max - step * 2 ? '2' : '1';
    if (resetPrice) resetPrice.disabled = !(state.low !== min || state.high !== max);
    presets.forEach(function (b) {
      var active = state.low === Number(b.getAttribute('data-from')) && state.high === Number(b.getAttribute('data-to'));
      b.setAttribute('aria-pressed', active ? 'true' : 'false');
      b.className = active ? 'ZxDzjq_presetActive' : '';
    });

    var filtersClear = qs('[data-pf="filters"] > [data-pf="clear"]');
    if (filtersClear) filtersClear.hidden = !hasFilters();
  }

  /** A change of any filter or the sort goes back to page 1 (the web's `update`). */
  function change(fn) {
    fn();
    state.page = 1;
    apply();
  }

  function clearFilters() {
    change(function () {
      state.category = null;
      state.low = min;
      state.high = max;
      state.fabrics = [];
      state.colors = [];
      state.inStock = false;
    });
  }

  // ---- events
  categoryButtons.forEach(function (b) {
    b.addEventListener('click', function () {
      change(function () { state.category = b.getAttribute('data-value') || null; });
    });
  });
  fabricBoxes.forEach(function (box) {
    box.addEventListener('change', function () { change(function () { state.fabrics = toggle(state.fabrics, box.value); }); });
  });
  colorChips.forEach(function (chip) {
    chip.addEventListener('click', function () { change(function () { state.colors = toggle(state.colors, chip.getAttribute('data-value') || ''); }); });
  });
  if (inStockBox) inStockBox.addEventListener('change', function () { change(function () { state.inStock = !state.inStock; }); });
  if (sortSelect) sortSelect.addEventListener('change', function () { change(function () { state.sort = sortSelect.value; }); });
  lowInput.addEventListener('input', function () {
    change(function () { state.low = Math.min(Number(lowInput.value), state.high - step); });
  });
  highInput.addEventListener('input', function () {
    change(function () { state.high = Math.max(Number(highInput.value), state.low + step); });
  });
  if (resetPrice) resetPrice.addEventListener('click', function () { change(function () { state.low = min; state.high = max; }); });
  presets.forEach(function (b) {
    b.addEventListener('click', function () {
      change(function () { state.low = Number(b.getAttribute('data-from')); state.high = Number(b.getAttribute('data-to')); });
    });
  });
  clearButtons.forEach(function (b) { b.addEventListener('click', clearFilters); });

  if (fabricMore) {
    fabricMore.addEventListener('click', function () {
      state.showAllFabrics = !state.showAllFabrics;
      fabricChoices.forEach(function (label, i) { label.hidden = i >= 5 && !state.showAllFabrics; });
      fabricMore.textContent = state.showAllFabrics ? '− Show Less' : '+ Show More';
    });
  }

  if (pagination) {
    pagination.addEventListener('click', function (event) {
      var b = event.target && event.target.closest ? event.target.closest('button[data-page]') : null;
      if (!b || b.disabled) return;
      state.page = Number(b.getAttribute('data-page'));
      apply();
      var top = doc.getElementById('catalog-top');
      var calm = win.matchMedia && win.matchMedia('(prefers-reduced-motion: reduce)').matches;
      if (top && top.scrollIntoView) top.scrollIntoView({ behavior: calm ? 'auto' : 'smooth', block: 'start' });
    });
  }

  // the two fold-away parts of the sidebar
  function foldable(toggleSel, panelSel, open) {
    var button = qs(toggleSel);
    var panel = qs(panelSel);
    if (!button || !panel) return;
    function set(next) {
      button.setAttribute('aria-expanded', next ? 'true' : 'false');
      panel.hidden = !next;
    }
    set(open);
    button.addEventListener('click', function () { set(button.getAttribute('aria-expanded') !== 'true'); });
  }
  // on a phone the filters start folded so the sarees come first, unless the address has a query
  var phone = !!(win.matchMedia && win.matchMedia('(max-width: 700px)').matches) && win.location.search === '';
  foldable('[data-pf="categories-toggle"]', '[data-pf="categories"]', false);
  foldable('[data-pf="filters-toggle"]', '[data-pf="filters"]', !phone);

  // "Added to your bag." under the toolbar after a card's Add to cart (bag.js does the saving)
  doc.addEventListener('pf:bag-added', function (event) {
    var detail = event.detail || {};
    if (!statusLine) {
      statusLine = doc.createElement('p');
      statusLine.className = '_4HCqHa_status';
      statusLine.setAttribute('role', 'status');
      grid.parentNode.insertBefore(statusLine, grid);
    }
    statusLine.textContent = detail.ok ? 'Added to your bag.' : (detail.message || '');
  });

  apply();
})();
