/*
 * The bag page (/bag) and the order summary of the checkout page (/checkout), in plain JavaScript. It does what the web's BagView, CheckoutView,
 * lib/bag.ts, lib/useCatalog.ts (useBagItems), lib/catalogApi.ts and lib/pricing.ts do for the real store:
 *   - the lines are the saved bag (window.PratyangiBag, bag.js: key "pratyangi-demo-bag", event "pratyangi-bag-change", the "storage" event for other tabs);
 *   - each line is checked against GET /api/v1/catalog/products?limit=100[&cursor=...] (a public read: no cookies, 10 s timeout, at most 20 pages,
 *     one request shared for 30 s) and is ok / limited / soldOut / gone / checking / unverified; a line is never dropped from the saved bag;
 *   - prices are rupees; tax is added on top line by line in whole paise (rounded half up), the same rule as the order will use.
 * Every value from the catalogue or the bag is written with textContent / setAttribute, never as HTML. No libraries, no inline script.
 */
(function (win) {
  'use strict';

  var doc = win.document;
  var Bag = win.PratyangiBag;
  var root = doc.querySelector('[data-pf-bag-page]');
  if (!root || !Bag) return;
  var checkoutMode = root.getAttribute('data-pf-bag-page') === 'checkout';
  var P = checkoutMode ? 'FVHMJa_' : 'BpPmcW_';
  var PLACEHOLDER = '/assets/images/reference/product-1.webp';
  var IMAGE_BASE = root.getAttribute('data-pf-image-base') || ''; // the ERP serves its pictures at its own address: base + "/" + image_path
  var FIGMA = '/assets/images/figma/';

  /* ---------------------------------------------------------------- formatting and pricing (lib/catalog.ts, lib/pricing.ts) */

  function formatInr(value) { return '₹' + Math.round(value).toLocaleString('en-IN'); }
  function formatRs(value) { return 'Rs. ' + value.toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); }
  function discountPercent(product) { return Math.round((1 - product.priceInr / product.compareAtInr) * 100); }
  function productHref(sku) { return '/product?sku=' + encodeURIComponent(sku); }
  function lineTaxPaise(unitPaise, quantity, taxBp) { return Math.floor((unitPaise * quantity * taxBp + 5000) / 10000); }

  /* ---------------------------------------------------------------- the catalogue (lib/catalogApi.ts, lib/useCatalog.ts) */

  var PAGE_LIMIT = 100;
  var MAX_PAGES = 20;
  var TIMEOUT_MS = 10000;
  var REUSE_MS = 30000;
  var shared = null;

  function CatalogError(message, canRetry) {
    this.message = message;
    this.canRetry = canRetry !== false;
  }

  function isApiProduct(item) {
    return !!item && typeof item === 'object'
      && typeof item.sku === 'string' && typeof item.name === 'string'
      && typeof item.price === 'number' && typeof item.mrp === 'number'
      && typeof item.inStock === 'boolean' && typeof item.availableQuantity === 'number'
      && (item.taxPercent === undefined || (typeof item.taxPercent === 'number' && item.taxPercent >= 0 && item.taxPercent <= 100));
  }

  function mapProduct(item) {
    return {
      id: item.sku,
      name: item.name,
      subtitle: [item.color, item.size].filter(Boolean).join(' · '),
      priceInr: item.price,
      compareAtInr: item.mrp,
      inStock: item.inStock,
      availableQuantity: item.availableQuantity,
      taxPercent: item.taxPercent || 0,
      imagePath: typeof item.imagePath === 'string' ? item.imagePath : ''
    };
  }

  function fetchPage(url) {
    var controller = typeof win.AbortController === 'function' ? new win.AbortController() : null;
    var timer = win.setTimeout(function () { if (controller) controller.abort(); }, TIMEOUT_MS);
    var options = { credentials: 'omit' };
    if (controller) options.signal = controller.signal;
    return win.fetch(url, options).then(function (response) {
      if (!response.ok) {
        if (response.status === 503) throw new CatalogError('The catalogue is not available right now. Please try again in a moment.');
        if (response.status === 429) throw new CatalogError('Too many requests. Please wait a minute and try again.');
        throw new CatalogError('We could not load the catalogue. Please try again.');
      }
      return response.json().then(function (body) {
        if (!body || !Array.isArray(body.products)) throw new CatalogError('The store sent an answer we could not read. Please try again.');
        return { products: body.products.filter(isApiProduct), nextCursor: typeof body.nextCursor === 'string' ? body.nextCursor : null };
      }, function () {
        throw new CatalogError('The store sent an answer we could not read. Please try again.');
      });
    }, function () {
      throw new CatalogError('We could not reach the store. Check your connection and try again.');
    }).then(function (page) {
      win.clearTimeout(timer);
      return page;
    }, function (error) {
      win.clearTimeout(timer);
      throw error;
    });
  }

  function fetchCatalog() {
    var products = [];
    function next(cursor, pageNumber) {
      if (pageNumber >= MAX_PAGES) throw new CatalogError('The catalogue is too large to load in one go. Please try again later.');
      var url = '/api/v1/catalog/products?limit=' + PAGE_LIMIT + (cursor ? '&cursor=' + encodeURIComponent(cursor) : '');
      return fetchPage(url).then(function (page) {
        page.products.forEach(function (item) { products.push(mapProduct(item)); });
        return page.nextCursor ? next(page.nextCursor, pageNumber + 1) : products;
      });
    }
    return next(null, 0);
  }

  function loadCatalog(forceFresh) {
    if (!forceFresh && shared && Date.now() - shared.at < REUSE_MS) return shared.promise;
    var entry = { at: Date.now(), promise: fetchCatalog() };
    shared = entry;
    entry.promise.then(null, function () { if (shared === entry) shared = null; });
    return entry.promise;
  }

  /* ---------------------------------------------------------------- state */

  var state = {
    lines: [],
    ready: false,
    message: '',
    flash: '',
    catalog: { status: 'loading' }
  };

  function loadBag() {
    var saved = Bag.read();
    state.lines = saved.lines;
    state.message = saved.message;
    state.ready = true;
  }

  function loadProducts(forceFresh) {
    state.catalog = { status: 'loading' };
    var attempt = loadCatalog(forceFresh);
    attempt.then(function (products) {
      state.catalog = { status: 'ready', products: products };
      render();
    }, function (error) {
      state.catalog = {
        status: 'error',
        message: error instanceof CatalogError ? error.message : 'We could not load the catalogue. Please try again.',
        canRetry: error instanceof CatalogError ? error.canRetry : true
      };
      render();
    });
  }

  /** The bag checked against the catalogue (useBagItems). */
  function bagItems() {
    var items = state.lines.map(function (line) {
      var product;
      if (state.catalog.status === 'ready') {
        for (var i = 0; i < state.catalog.products.length; i++) {
          if (state.catalog.products[i].id === line.id) { product = state.catalog.products[i]; break; }
        }
      }
      var item = { id: line.id, quantity: line.quantity };
      if (state.catalog.status === 'loading') { item.state = 'checking'; return item; }
      if (state.catalog.status === 'error') { item.state = 'unverified'; return item; }
      if (!product) { item.state = 'gone'; return item; }
      item.product = product;
      if (!product.inStock) { item.state = 'soldOut'; return item; }
      var available = product.availableQuantity;
      if (available !== undefined && available < line.quantity) { item.state = 'limited'; item.available = available; return item; }
      item.state = 'ok';
      if (available !== undefined) item.available = available;
      return item;
    });
    function counted(item) { return item.state === 'ok' ? item.quantity : item.state === 'limited' ? (item.available || 0) : 0; }
    var netPaise = 0;
    var taxPaise = 0;
    var finalTotals = true;
    var problem = null;
    items.forEach(function (item) {
      if (item.state !== 'ok') finalTotals = false;
      if (!item.product) return;
      var unit = Math.round(item.product.priceInr * 100);
      var quantity = counted(item);
      netPaise += unit * quantity;
      taxPaise += lineTaxPaise(unit, quantity, Math.round(item.product.taxPercent * 100));
    });
    function any(names) { return items.some(function (item) { return names.indexOf(item.state) !== -1; }); }
    if (any(['unverified'])) problem = 'unverified';
    else if (any(['checking'])) problem = 'checking';
    else if (any(['soldOut', 'gone', 'limited'])) problem = 'unavailable';
    return { items: items, counted: counted, netPaise: netPaise, taxPaise: taxPaise, totalPaise: netPaise + taxPaise, totalsFinal: finalTotals, problem: problem };
  }

  /* ---------------------------------------------------------------- small DOM helpers */

  function h(tag, className, text) {
    var node = doc.createElement(tag);
    if (className) node.className = className;
    if (text !== undefined && text !== null) node.textContent = text;
    return node;
  }

  function figmaImg(file, size) {
    var image = doc.createElement('img');
    image.alt = '';
    image.setAttribute('loading', 'lazy');
    image.width = size;
    image.height = size;
    image.setAttribute('decoding', 'async');
    image.setAttribute('data-nimg', '1');
    image.style.color = 'transparent';
    image.src = FIGMA + file;
    return image;
  }

  function fillImg(name, imagePath) {
    var image = doc.createElement('img');
    image.alt = name;
    image.setAttribute('loading', 'lazy');
    image.setAttribute('decoding', 'async');
    image.setAttribute('data-nimg', 'fill');
    image.setAttribute('style', 'position:absolute;height:100%;width:100%;left:0;top:0;right:0;bottom:0;object-position:center top;color:transparent');
    image.src = IMAGE_BASE && imagePath ? IMAGE_BASE + '/' + imagePath.replace(/^\/+/, '') : PLACEHOLDER;
    image.onerror = function () { image.onerror = null; image.src = PLACEHOLDER; }; // a picture the ERP cannot give
    return image;
  }

  function button(label, className, onClick) {
    var node = h('button', className, label);
    node.type = 'button';
    if (onClick) node.addEventListener('click', onClick);
    return node;
  }

  function qs(name) { return root.querySelector('[data-pf="' + name + '"]'); }
  function qsa(name) { return Array.prototype.slice.call(root.querySelectorAll('[data-pf="' + name + '"]')); }
  function clear(node) { while (node.firstChild) node.removeChild(node.firstChild); }

  /* ---------------------------------------------------------------- actions (lib/bag.ts commit) */

  function commit(update) {
    var next = update(state.lines);
    var result = Bag.write(next);
    state.lines = next;
    if (!result.ok) state.message = result.message;
    render();
  }

  function removeLine(id) {
    commit(function (current) { return current.filter(function (line) { return line.id !== id; }); });
  }

  function setQuantity(id, quantity) {
    var value = Math.min(Math.max(1, quantity), Bag.MAX);
    commit(function (current) {
      return current.map(function (line) { return line.id === id ? { id: line.id, quantity: value } : line; });
    });
  }

  /* ---------------------------------------------------------------- rendering */

  var UNKNOWN_NOTE = {
    checking: 'Checking availability…',
    unverified: 'We could not check this item right now, so its price and stock are unknown.',
    gone: 'This item is no longer available.'
  };
  var BAG_PROBLEM = {
    unverified: 'We could not check stock and prices right now. Your bag is saved, but the totals below are not final.',
    checking: 'Checking stock and prices…',
    unavailable: 'Some items are no longer available as listed. Remove them or change the quantity to continue.'
  };
  var CHECKOUT_PROBLEM = {
    unverified: 'We could not check stock and prices right now, so these totals are not final.',
    checking: 'Checking stock and prices…',
    unavailable: 'Some items are unavailable as listed. Remove them or change the quantity in your bag to continue.'
  };

  function bagLine(item, view) {
    var product = item.product;
    var article = h('article', P + 'item');
    if (!product) {
      article.appendChild(h('div', P + 'photo'));
      var info = h('div', P + 'info');
      var details = h('div', P + 'details');
      details.appendChild(h('h2', null, 'Item ' + item.id));
      var note = h('p', null, UNKNOWN_NOTE[item.state]);
      if (item.state === 'checking') note.setAttribute('role', 'status');
      details.appendChild(note);
      info.appendChild(details);
      var actions = h('div', P + 'actions');
      var secondary = h('div', P + 'secondary');
      var remove = button('Remove', null, function () { removeLine(item.id); });
      remove.insertBefore(figmaImg('trash.svg', 19), remove.firstChild);
      secondary.appendChild(remove);
      actions.appendChild(secondary);
      info.appendChild(actions);
      article.appendChild(info);
      return article;
    }
    var photo = h('div', P + 'photo');
    photo.appendChild(fillImg(product.name, product.imagePath));
    article.appendChild(photo);

    var body = h('div', P + 'info');
    var text = h('div', P + 'details');
    var title = h('h2');
    var link = h('a', null, product.name);
    link.setAttribute('href', productHref(item.id));
    title.appendChild(link);
    text.appendChild(title);
    text.appendChild(h('p', null, product.subtitle));
    body.appendChild(text);

    if (item.state === 'soldOut') {
      var out = h('p', P + 'status', 'Out of stock. It is not included in your total; remove it to continue.');
      out.setAttribute('role', 'alert');
      body.appendChild(out);
    }
    if (item.state === 'limited') {
      var limited = h('p', P + 'status', 'Only ' + item.available + ' left, but you have ' + item.quantity + ' in your bag. ');
      limited.setAttribute('role', 'alert');
      limited.appendChild(button('Change to ' + item.available, null, function () { setQuantity(item.id, Math.max(1, item.available || 1)); }));
      body.appendChild(limited);
    }

    var row = h('div', P + 'actions');
    var group = h('div', P + 'quantity');
    group.setAttribute('role', 'group');
    group.setAttribute('aria-label', 'Quantity for ' + product.name);
    var less = button('−', null, function () { setQuantity(item.id, item.quantity - 1); });
    less.setAttribute('aria-label', 'Decrease quantity');
    less.disabled = item.quantity <= 1;
    var shown = h('output', null, String(item.quantity));
    shown.setAttribute('aria-live', 'polite');
    var more = button('+', null, function () { setQuantity(item.id, item.quantity + 1); });
    more.setAttribute('aria-label', 'Increase quantity');
    more.disabled = item.state === 'soldOut' || item.quantity >= Math.min(Bag.MAX, item.available !== undefined ? item.available : Bag.MAX);
    group.appendChild(less);
    group.appendChild(shown);
    group.appendChild(more);
    row.appendChild(group);

    var secondaryRow = h('div', P + 'secondary');
    var wish = button(' Move to Wishlist (coming soon)', null);
    wish.disabled = true;
    wish.title = 'Wishlist coming soon';
    wish.insertBefore(figmaImg('wishlist-heart.svg', 22), wish.firstChild);
    var drop = button(' Remove', null, function () { removeLine(item.id); });
    drop.insertBefore(figmaImg('trash.svg', 19), drop.firstChild);
    secondaryRow.appendChild(wish);
    secondaryRow.appendChild(drop);
    row.appendChild(secondaryRow);
    body.appendChild(row);
    article.appendChild(body);

    var price = h('div', P + 'price');
    if (item.state === 'soldOut') {
      price.appendChild(h('strong', null, 'Unavailable'));
    } else {
      var quantity = view.counted(item);
      price.appendChild(h('strong', null, formatInr(product.priceInr * quantity)));
      var percent = discountPercent(product);
      if (percent > 0) {
        price.appendChild(h('del', null, formatInr(product.compareAtInr * quantity)));
        price.appendChild(h('span', null, percent + '% OFF'));
      }
    }
    article.appendChild(price);
    return article;
  }

  function checkoutLine(item, view) {
    var product = item.product;
    var li = h('li', P + 'line');
    if (!product) {
      li.appendChild(h('div', P + 'thumb'));
      var plain = h('div', P + 'lineBody');
      plain.appendChild(h('p', P + 'lineName', 'Item ' + item.id));
      plain.appendChild(h('p', P + 'lineSub', item.state === 'checking' ? 'Checking availability…' : item.state === 'gone' ? 'No longer available' : 'Stock and price could not be checked'));
      li.appendChild(plain);
      var removeUnknown = button('', P + 'remove', function () { removeLine(item.id); });
      removeUnknown.setAttribute('aria-label', 'Remove item ' + item.id);
      removeUnknown.appendChild(trashIcon());
      li.appendChild(removeUnknown);
      return li;
    }
    var thumb = h('div', P + 'thumb');
    thumb.appendChild(fillImg(product.name, product.imagePath));
    li.appendChild(thumb);
    var body = h('div', P + 'lineBody');
    body.appendChild(h('p', P + 'lineName', product.name));
    body.appendChild(h('p', P + 'lineSub', product.subtitle));
    if (item.state === 'soldOut') {
      var out = h('p', P + 'lineSub', 'Out of stock: not included in the total');
      out.setAttribute('role', 'alert');
      body.appendChild(out);
    }
    if (item.state === 'limited') {
      var limited = h('p', P + 'lineSub', 'Only ' + item.available + ' available: change the quantity to continue');
      limited.setAttribute('role', 'alert');
      body.appendChild(limited);
    }
    var row = h('div', P + 'priceRow');
    row.appendChild(h('strong', null, item.state === 'soldOut' ? 'Unavailable' : formatInr(product.priceInr * view.counted(item))));
    var label = h('label', P + 'qty');
    label.appendChild(h('span', null, 'Qty:'));
    var select = doc.createElement('select');
    select.setAttribute('aria-label', 'Quantity for ' + product.name);
    for (var q = 1; q <= 10; q++) {
      var option = h('option', null, String(q));
      option.value = String(q);
      select.appendChild(option);
    }
    select.value = String(item.quantity);
    select.addEventListener('change', function () { setQuantity(item.id, Number(select.value)); });
    label.appendChild(select);
    label.appendChild(chevronDown());
    row.appendChild(label);
    body.appendChild(row);
    li.appendChild(body);
    var drop = button('', P + 'remove', function () { removeLine(item.id); });
    drop.setAttribute('aria-label', 'Remove ' + product.name);
    drop.appendChild(trashIcon());
    li.appendChild(drop);
    return li;
  }

  function svgIcon(size, paths) {
    var ns = 'http://www.w3.org/2000/svg';
    var svg = doc.createElementNS(ns, 'svg');
    svg.setAttribute('width', String(size));
    svg.setAttribute('height', String(size));
    svg.setAttribute('viewBox', '0 0 24 24');
    svg.setAttribute('fill', 'none');
    svg.setAttribute('stroke', 'currentColor');
    svg.setAttribute('stroke-width', '2');
    svg.setAttribute('stroke-linecap', 'round');
    svg.setAttribute('stroke-linejoin', 'round');
    svg.setAttribute('aria-hidden', 'true');
    paths.forEach(function (d) {
      var path = doc.createElementNS(ns, 'path');
      path.setAttribute('d', d);
      svg.appendChild(path);
    });
    return svg;
  }
  function trashIcon() { return svgIcon(18, ['M3 6h18', 'M19 6v14c0 1-1 2-2 2H7c-1 0-2-1-2-2V6', 'M8 6V4c0-1 1-2 2-2h4c1 0 2 1 2 2v2', 'M10 11v6', 'M14 11v6']); }
  function chevronDown() { return svgIcon(15, ['m6 9 6 6 6-6']); }

  function problemBox(text, view) {
    var box = h('div', P + 'status');
    box.setAttribute('role', view.problem === 'checking' ? 'status' : 'alert');
    box.appendChild(h('p', null, text));
    if (view.problem === 'unverified' && state.catalog.status === 'error' && state.catalog.canRetry) {
      box.appendChild(button('Try again', checkoutMode ? P + 'backLink' : null, function () { loadProducts(true); render(); }));
    }
    return box;
  }

  var emptyNode = null;

  function render() {
    var view = bagItems();
    var items = view.items;
    var bagCount = state.lines.reduce(function (total, line) { return total + line.quantity; }, 0);
    var itemLabel = bagCount + ' ' + (bagCount === 1 ? 'item' : 'items');

    [['title-count', '(' + itemLabel + ')'], ['subtotal-label', 'Subtotal (' + itemLabel + ')'], ['bag-size', itemLabel + ' in your bag']].forEach(function (pair) {
      var node = qs(pair[0]);
      if (node) node.textContent = pair[1];
    });
    var lead = qs('bag-lead');
    if (lead) lead.textContent = items.length ? 'Great choice! These beautiful pieces are waiting for you.' : 'Your bag is empty — discover something beautiful.';

    // notices
    var notices = qs('bag-notices');
    if (notices) {
      clear(notices);
      if (state.message) { var saved = h('p', P + 'status', state.message); saved.setAttribute('role', 'status'); notices.appendChild(saved); }
      if (state.flash) { var flash = h('p', P + 'status', state.flash); flash.setAttribute('role', 'status'); notices.appendChild(flash); }
      if (!checkoutMode && items.length > 0 && view.problem) notices.appendChild(problemBox(BAG_PROBLEM[view.problem], view));
    }

    // the lines
    var list = qs('bag-items');
    if (list) {
      clear(list);
      // the empty state: inside the items column on the bag page; before the list on checkout (a list cannot hold a div)
      if (emptyNode && emptyNode.parentNode) emptyNode.parentNode.removeChild(emptyNode);
      emptyNode = null;
      list.hidden = false;
      if (state.ready && items.length === 0) {
        emptyNode = window.PratyangiEmptyState('shopping-bag', checkoutMode ? 'Your bag is empty.' : 'There are no sarees in your bag yet.', 'Add a saree you love and it will wait for you here.');
        if (checkoutMode) {
          list.parentNode.insertBefore(emptyNode, list);
          list.hidden = true;
        } else {
          list.appendChild(emptyNode);
        }
      }
      items.forEach(function (item) { list.appendChild(checkoutMode ? checkoutLine(item, view) : bagLine(item, view)); });
    }

    // an empty bag has nothing to total: only the empty state shows
    var summary = root.querySelector(checkoutMode ? '.FVHMJa_summary' : '.BpPmcW_summary');
    if (summary) summary.style.display = state.ready && items.length > 0 ? '' : 'none'; // the page starts with it hidden

    // the totals
    var subtotal = qs('subtotal');
    if (subtotal) subtotal.textContent = formatRs(view.netPaise / 100);
    var taxRow = qs('tax-row');
    if (taxRow) taxRow.hidden = !(view.taxPaise > 0);
    var tax = qs('tax');
    if (tax) tax.textContent = formatRs(view.taxPaise / 100);
    var totalLabel = qs('total-label');
    if (totalLabel) totalLabel.textContent = !view.totalsFinal ? 'Total (not final)' : checkoutMode ? 'Total' : 'Total Amount';
    var total = qs('total');
    if (total) total.textContent = formatRs(view.totalPaise / 100);

    var note = qs('total-note');
    if (note) {
      clear(note);
      if (checkoutMode) {
        if (items.length > 0 && view.problem) note.appendChild(problemBox(CHECKOUT_PROBLEM[view.problem], view));
      } else if (items.length > 0 && !view.totalsFinal) {
        var notFinal = h('p', P + 'status', 'Not final: this total leaves out items that are unavailable or could not be checked.');
        notFinal.setAttribute('role', 'status');
        note.appendChild(notFinal);
      }
    }

    // "Proceed to Checkout": a link when every line is checked and fine, otherwise a disabled button (the bag page only)
    var slot = qs('checkout-slot');
    if (slot && !checkoutMode) {
      var ready = items.length > 0 && view.totalsFinal;
      var next;
      if (ready) {
        next = h('a', P + 'checkout', 'Proceed to Checkout');
        next.setAttribute('href', root.getAttribute('data-checkout-href') || '/checkout');
      } else {
        next = button('Proceed to Checkout', P + 'checkout');
        next.disabled = true;
      }
      next.setAttribute('data-pf', 'checkout-slot');
      next.appendChild(figmaImg('checkout-arrow.svg', 27));
      slot.parentNode.replaceChild(next, slot);
    }
  }

  /* ---------------------------------------------------------------- start */

  // a card's "Add to cart" under "You May Also Like": bag.js adds it, this page says so (the web does the same)
  doc.addEventListener('pf:bag-added', function () {
    state.flash = 'Added to your bag.';
    loadBag();
    render();
  });

  function reload(event) {
    if (event && event.type === 'storage' && event.key !== null && event.key !== Bag.KEY) return;
    loadBag();
    render();
  }
  win.addEventListener('storage', reload);
  win.addEventListener(Bag.EVENT, reload);

  // delivery addresses: public/assets/address-book.js

  loadBag();
  render();
  loadProducts(false);
})(window);
