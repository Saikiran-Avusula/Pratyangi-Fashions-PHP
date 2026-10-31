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
  if (window.__pfListing) return; window.__pfListing = true; // REQ1: loaded twice = set up once

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
      mrp: parseFloat(el.getAttribute('data-qv-mrp')) || 0,
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

  /** The share of the MRP that is taken off (0 when there is no MRP above the price). */
  function off(card) { return card.mrp > card.price ? (card.mrp - card.price) / card.mrp : 0; }

  /** 100000 -> "₹1,00,000" (Indian grouping, like toLocaleString("en-IN")). */
  function inr(value) { return win.PratyangiMoney(value); } // the shared price format (site.js)

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
    else if (state.sort === 'discount') filtered.sort(function (a, b) { return off(b) - off(a) || a.index - b.index; });

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
    syncMobile();
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

  /* ---- phone controls (under 768px; the CSS hides them above): quick chips under the title, a bottom bar "Sort By | Filter", a sort sheet, and the
     filters of the sidebar shown as a bottom sheet. They drive the same state as the desktop controls, so nothing is filtered twice. ---- */
  var COLORS = { maroon: '#800020', red: '#c8102e', pink: '#ec6fa3', 'rose pink': '#e8a0b4', orange: '#f58a00', yellow: '#f2c230', mustard: '#d19a1a', gold: '#c9a227', green: '#2e8b57', 'emerald green': '#0f7a4f',
    olive: '#6b7a2a', teal: '#14807d', blue: '#2a5fc9', navy: '#1c2b5a', 'sky blue': '#6fb4e6', 'peacock blue': '#126b86', purple: '#7a3fa0', lavender: '#b79ad6', wine: '#5d1030', magenta: '#c2188f',
    white: '#ffffff', ivory: '#f6f0dc', cream: '#f3e9cf', beige: '#d9c7a3', grey: '#8a8a8a', black: '#1a1a1a', peach: '#f6b99a', indigo: '#3b3b98' };
  function colorOf(name) { return COLORS[String(name).toLowerCase()] || '#cfcabe'; }
  function node(tag, className, text) { var n = doc.createElement(tag); if (className) n.className = className; if (text) n.textContent = text; return n; }
  var SORTS = [['featured', 'Newest'], ['price-asc', 'Price: Low to High'], ['price-desc', 'Price: High to Low'], ['discount', 'Discount']];
  var sortOptions = [];
  var barSort = null, barFilter = null, filterCount = null, quickStock = null, quickColors = [];
  var openSheet = null;

  if (sortSelect && !qs('option[value="discount"]', sortSelect)) { var opt = node('option', '', 'Discount'); opt.value = 'discount'; sortSelect.appendChild(opt); }
  // colour chips become round swatches (the name stays as the accessible label)
  colorChips.forEach(function (chip) {
    var name = chip.getAttribute('data-value') || chip.textContent;
    chip.classList.add('pf_swatch');
    chip.setAttribute('aria-label', name);
    chip.title = name;
    chip.style.setProperty('--sw', colorOf(name));
  });

  function activeCount() {
    return state.fabrics.length + state.colors.length + (state.inStock ? 1 : 0) + (state.low !== min || state.high !== max ? 1 : 0) + (state.category ? 1 : 0);
  }
  function closeSheet() {
    if (!openSheet) return;
    openSheet.scrim.hidden = true;
    if (openSheet.sheet) openSheet.sheet.hidden = true;
    doc.body.classList.remove('pf_filterOpen');
    if (win.PratyangiScroll) win.PratyangiScroll.unlock();
    var back = openSheet.opener;
    openSheet = null;
    if (back) back.focus();
  }
  function show(which, opener) {
    closeSheet();
    openSheet = { scrim: scrim, sheet: which === 'sort' ? sortSheet : null, opener: opener };
    scrim.hidden = false;
    if (which === 'sort') sortSheet.hidden = false;
    else {
      var panel = qs('[data-pf="filters"]'), toggleBtn = qs('[data-pf="filters-toggle"]');
      if (panel) panel.hidden = false;
      if (toggleBtn) toggleBtn.setAttribute('aria-expanded', 'true');
      doc.body.classList.add('pf_filterOpen');
    }
    if (win.PratyangiScroll) win.PratyangiScroll.lock();
  }

  var scrim = node('div', 'pf_sheetScrim');
  scrim.hidden = true;
  scrim.addEventListener('mousedown', function (event) { if (event.target === scrim) closeSheet(); });
  var sortSheet = node('div', 'pf_sheet pf_sortSheet');
  sortSheet.hidden = true;
  sortSheet.setAttribute('role', 'dialog');
  sortSheet.setAttribute('aria-modal', 'true');
  sortSheet.setAttribute('aria-label', 'Sort by');
  var sortHead = node('div', 'pf_sheetHead');
  sortHead.appendChild(node('h2', '', 'Sort By'));
  var sortX = node('button', 'pf_sheetX', '×');
  sortX.type = 'button';
  sortX.setAttribute('aria-label', 'Close');
  sortX.addEventListener('click', closeSheet);
  sortHead.appendChild(sortX);
  sortSheet.appendChild(sortHead);
  SORTS.forEach(function (pair) {
    var b = node('button', 'pf_sortOpt', pair[1]);
    b.type = 'button';
    b.setAttribute('role', 'radio');
    b.addEventListener('click', function () { change(function () { state.sort = pair[0]; }); closeSheet(); });
    sortOptions.push([pair[0], b]);
    sortSheet.appendChild(b);
  });
  scrim.appendChild(sortSheet);

  var aside = qs('aside._4HCqHa_sidebar');
  if (aside) {
    var head = node('div', 'pf_filterHead');
    head.appendChild(node('h2', '', 'Filters'));
    var x = node('button', 'pf_sheetX', '×');
    x.type = 'button';
    x.setAttribute('aria-label', 'Close');
    x.addEventListener('click', closeSheet);
    head.appendChild(x);
    aside.insertBefore(head, aside.firstChild);
    var foot = node('div', 'pf_filterFoot');
    var clearBtn = node('button', 'pf_filterClear', 'Clear');
    clearBtn.type = 'button';
    clearBtn.addEventListener('click', clearFilters);
    var applyBtn = node('button', 'pf_filterApply', 'Apply');
    applyBtn.type = 'button';
    applyBtn.addEventListener('click', closeSheet);
    foot.appendChild(clearBtn);
    foot.appendChild(applyBtn);
    aside.appendChild(foot);
  }

  var bar = node('div', 'pf_lbar');
  barSort = node('button', '', 'Sort');
  barSort.type = 'button';
  barSort.setAttribute('aria-haspopup', 'dialog');
  barSort.addEventListener('click', function () { show('sort', barSort); });
  barFilter = node('button', '', 'Filter');
  barFilter.type = 'button';
  barFilter.setAttribute('aria-haspopup', 'dialog');
  filterCount = node('span', 'pf_lcount');
  filterCount.hidden = true;
  barFilter.appendChild(filterCount);
  barFilter.addEventListener('click', function () { show('filter', barFilter); });
  // UI9: a small icon before each label (sort arrows, filter sliders)
  var barIcon = function (btn, paths) {
    btn.insertAdjacentHTML('afterbegin', '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">' + paths + '</svg>');
  };
  barIcon(barSort, '<path d="m3 16 4 4 4-4"/><path d="M7 20V4"/><path d="m21 8-4-4-4 4"/><path d="M17 4v16"/>');
  barIcon(barFilter, '<path d="M4 6h10"/><path d="M18 6h2"/><circle cx="16" cy="6" r="2"/><path d="M4 18h4"/><path d="M12 18h8"/><circle cx="10" cy="18" r="2"/>');
  bar.appendChild(barSort);
  bar.appendChild(barFilter);
  doc.body.appendChild(scrim);
  doc.body.appendChild(bar);
  // UI9b: the pill and the WhatsApp button (html.pf_chromeHide) slide away on scroll down > 24px or while the footer is in view; back on scroll up
  var lastY = win.pageYOffset || 0, footSeen = false;
  var setHide = function (on) { doc.documentElement.classList.toggle('pf_chromeHide', on); };
  win.addEventListener('scroll', function () {
    var y = win.pageYOffset || 0;
    if (y - lastY > 24) { setHide(true); lastY = y; } else if (y < lastY) { setHide(footSeen); lastY = y; }
  }, { passive: true });
  var foot = doc.querySelector('footer'); // outside the listing root, so not qs()
  if (foot && 'IntersectionObserver' in win) new IntersectionObserver(function (es) { footSeen = es[0].isIntersecting; if (footSeen) setHide(true); }).observe(foot);

  var intro = qs('._4HCqHa_intro');
  if (intro) {
    var row = node('div', 'pf_qchips');
    row.setAttribute('role', 'group');
    row.setAttribute('aria-label', 'Quick filters');
    quickStock = node('button', 'pf_qchip', 'In stock');
    quickStock.type = 'button';
    quickStock.addEventListener('click', function () { change(function () { state.inStock = !state.inStock; }); });
    row.appendChild(quickStock);
    colorChips.slice(0, 8).forEach(function (chip) {
      var name = chip.getAttribute('data-value') || '';
      var q = node('button', 'pf_qchip');
      q.type = 'button';
      var dot = node('i', 'pf_qdot');
      dot.style.background = colorOf(name);
      q.appendChild(dot);
      q.appendChild(doc.createTextNode(name));
      q.addEventListener('click', function () { change(function () { state.colors = toggle(state.colors, name); }); });
      quickColors.push([name, q]);
      row.appendChild(q);
    });
    intro.parentNode.insertBefore(row, intro.nextSibling);
  }
  win.addEventListener('keydown', function (event) { if (event.key === 'Escape') closeSheet(); });

  /** Keeps the bar, the quick chips and the sort sheet in line with the state (called at the end of apply()). */
  function syncMobile() {
    if (!barFilter) return;
    var n = activeCount();
    filterCount.hidden = n === 0;
    filterCount.textContent = String(n);
    barFilter.setAttribute('aria-label', 'Filter' + (n ? ', ' + n + ' active' : ''));
    sortOptions.forEach(function (pair) { pair[1].setAttribute('aria-checked', pair[0] === state.sort ? 'true' : 'false'); });
    if (quickStock) quickStock.setAttribute('aria-pressed', state.inStock ? 'true' : 'false');
    quickColors.forEach(function (pair) { pair[1].setAttribute('aria-pressed', state.colors.indexOf(pair[0]) !== -1 ? 'true' : 'false'); });
  }
  apply();
})();
