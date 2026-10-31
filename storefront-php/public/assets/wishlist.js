/*
 * The /wishlist page: the SKUs saved by site.js (window.PratyangiWishlist) looked up in the catalogue (/api/v1/catalog/products, same cache as the
 * pages), each with Move to bag (bag.js, then removed from the wishlist) and Remove. A SKU the catalogue no longer has can only be removed.
 */
(function () {
  'use strict';
  if (window.__pfWishlistPage) return; window.__pfWishlistPage = true; // REQ1: loaded twice = set up once

  var doc = document;
  var root = doc.querySelector('[data-pf="wishlist"]');
  var wish = window.PratyangiWishlist;
  var bag = window.PratyangiBag;
  if (!root || !wish || !bag) return;
  var imgBase = '';
  var sizes = {}; // sku -> "S:1,M:1,..." from the page (Variants)

  function el(tag, className, text) {
    var node = doc.createElement(tag);
    if (className) node.className = className;
    if (text) node.textContent = text;
    return node;
  }

  function note(text) {
    root.textContent = '';
    root.appendChild(el('p', 'pf_wishlist_note', text));
  }

  /** Every catalogue page until all wanted SKUs are found (the API pages 100 at a time). */
  function load(wanted, cursor, found) {
    var url = '/api/v1/catalog/products?limit=100' + (cursor ? '&cursor=' + encodeURIComponent(cursor) : '');
    return window.fetch(url, { credentials: 'same-origin' }).then(function (r) {
      if (!r.ok) throw new Error('catalogue');
      return r.json();
    }).then(function (data) {
      (data.products || []).forEach(function (p) { if (wanted.indexOf(p.sku) !== -1) found[p.sku] = p; });
      return data.nextCursor && Object.keys(found).length < wanted.length ? load(wanted, data.nextCursor, found) : found;
    });
  }

  function row(sku, p) {
    var li = el('article', 'pf_wishlist_row');
    var img = el('img', 'pf_wishlist_img');
    img.alt = '';
    img.src = p && p.imagePath && imgBase ? imgBase + String(p.imagePath).replace(/^\/+/, '') : '/assets/images/reference/product-1.webp';
    img.loading = 'lazy';
    img.decoding = 'async';
    // UI10: the picture sits in a 3:4 box with the eye (site.js Quick View reads the data-qv-* values below)
    var photo = el('div', 'pf_wishlist_photo');
    photo.appendChild(img);
    if (p) {
      var qv = { sku: sku, name: p.name, price: window.PratyangiMoney(p.price), mrp: p.mrp > p.price ? window.PratyangiMoney(p.mrp) : '', sub: [p.color, p.size].filter(Boolean).join(' · '),
        stock: p.inStock ? 'In stock' : 'Out of stock', instock: p.inStock ? '1' : '0', img: img.src, href: '/product?sku=' + encodeURIComponent(sku) };
      Object.keys(qv).forEach(function (k) { li.setAttribute('data-qv-' + k, qv[k] || ''); });
      var eye = el('button', 'pf_eye', '');
      eye.type = 'button';
      eye.setAttribute('data-pf', 'quick-view');
      eye.setAttribute('aria-haspopup', 'dialog');
      eye.setAttribute('aria-label', 'Quick view ' + p.name);
      eye.innerHTML = '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M2.062 12.348a1 1 0 0 1 0-.696 10.75 10.75 0 0 1 19.876 0 1 1 0 0 1 0 .696 10.75 10.75 0 0 1-19.876 0"/><circle cx="12" cy="12" r="3"/></svg>';
      photo.appendChild(eye);
    }
    var info = el('div', 'pf_wishlist_info');
    var title = el('a', 'pf_wishlist_name', p ? p.name : 'No longer available');
    if (p) title.href = '/product?sku=' + encodeURIComponent(sku);
    info.appendChild(title);
    if (p) info.appendChild(el('p', 'pf_wishlist_price', window.PratyangiMoney(p.price) + (p.inStock ? '' : ' \u00B7 Out of stock')));
    var actions = el('div', 'pf_wishlist_actions');
    if (p && p.inStock) {
      var move = el('button', 'pf_acct_btn', 'Move to bag');
      move.type = 'button';
      if (sizes[sku]) {
        // a sized product: site.js opens the size picker (never added without a size); it leaves the wishlist once the size is in the bag
        move.setAttribute('data-pf', 'add-to-bag');
        move.setAttribute('data-sku', sku);
        move.setAttribute('data-sizes', sizes[sku]);
      } else {
        move.addEventListener('click', function () {
          if (bag.add(sku).ok) wish.remove(sku);
        });
      }
      actions.appendChild(move);
    }
    var remove = el('button', 'pf_acct_link', 'Remove');
    remove.type = 'button';
    remove.addEventListener('click', function () { wish.remove(sku); });
    actions.appendChild(remove);
    li.appendChild(photo); li.appendChild(info); li.appendChild(actions);
    return li;
  }

  var tools = doc.querySelector('[data-pf="wish-tools"]');
  var total = doc.querySelector('[data-pf="wish-total"]');
  var sortBox = doc.querySelector('[data-pf="wish-sort"]');
  function showTools(n) {
    if (!tools) return;
    tools.hidden = n < 1;
    if (total) total.textContent = n + (n === 1 ? ' Item' : ' Items');
  }

  var token = 0;
  function draw() {
    var skus = wish.read();
    var mine = ++token;
    showTools(skus.length);
    if (!skus.length) {
      root.textContent = '';
      root.appendChild(window.PratyangiEmptyState('heart', 'Your wishlist is empty.', 'Tap the heart on any saree to save it here.'));
      return;
    }
    load(skus, '', {}).then(function (found) {
      if (mine !== token) return; // a newer draw replaced this one
      root.textContent = '';
      var order = skus.slice().reverse(); // newest first
      var by = sortBox ? sortBox.value : 'new';
      if (by !== 'new') order.sort(function (a, b) { var pa = found[a] ? found[a].price : 0, pb = found[b] ? found[b].price : 0; return by === 'low' ? pa - pb : pb - pa; });
      order.forEach(function (sku) { root.appendChild(row(sku, found[sku] || null)); });
    }, function () { if (mine === token) note('We could not load your wishlist. Please try again.'); });
  }

  imgBase = root.getAttribute('data-pf-img') || '';
  try { sizes = JSON.parse(root.getAttribute('data-pf-sizes') || '{}'); } catch (e) { sizes = {}; }
  document.addEventListener('pf:bag-added', function (event) {
    var d = event.detail || {};
    if (d.ok && d.sku && d.opener && d.opener.closest && d.opener.closest('.pf_wishlist_actions')) wish.remove(d.sku.split('~')[0]); // only this page's "Move to bag"
  });
  if (sortBox) sortBox.addEventListener('change', draw);
  draw();
  window.addEventListener(wish.EVENT, draw);
})();
