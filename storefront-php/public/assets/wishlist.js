/*
 * The /wishlist page: the SKUs saved by site.js (window.PratyangiWishlist) looked up in the catalogue (/api/v1/catalog/products, same cache as the
 * pages), each with Move to bag (bag.js, then removed from the wishlist) and Remove. A SKU the catalogue no longer has can only be removed.
 */
(function () {
  'use strict';

  var doc = document;
  var root = doc.querySelector('[data-pf="wishlist"]');
  var wish = window.PratyangiWishlist;
  var bag = window.PratyangiBag;
  if (!root || !wish || !bag) return;
  var imgBase = '';

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
    var info = el('div', 'pf_wishlist_info');
    var title = el('a', 'pf_wishlist_name', p ? p.name : 'No longer available');
    if (p) title.href = '/product?sku=' + encodeURIComponent(sku);
    info.appendChild(title);
    if (p) info.appendChild(el('p', 'pf_wishlist_price', '\u20B9' + Number(p.price).toLocaleString('en-IN') + (p.inStock ? '' : ' \u00B7 Out of stock')));
    var actions = el('div', 'pf_wishlist_actions');
    if (p && p.inStock) {
      var move = el('button', 'pf_acct_btn', 'Move to bag');
      move.type = 'button';
      move.addEventListener('click', function () {
        if (bag.add(sku).ok) wish.remove(sku);
      });
      actions.appendChild(move);
    }
    var remove = el('button', 'pf_acct_link', 'Remove');
    remove.type = 'button';
    remove.addEventListener('click', function () { wish.remove(sku); });
    actions.appendChild(remove);
    li.appendChild(img); li.appendChild(info); li.appendChild(actions);
    return li;
  }

  var token = 0;
  function draw() {
    var skus = wish.read();
    var mine = ++token;
    if (!skus.length) {
      root.textContent = '';
      root.appendChild(window.PratyangiEmptyState('heart', 'Your wishlist is empty.', 'Tap the heart on any saree to save it here.'));
      return;
    }
    load(skus, '', {}).then(function (found) {
      if (mine !== token) return; // a newer draw replaced this one
      root.textContent = '';
      skus.forEach(function (sku) { root.appendChild(row(sku, found[sku] || null)); });
    }, function () { if (mine === token) note('We could not load your wishlist. Please try again.'); });
  }

  imgBase = root.getAttribute('data-pf-img') || '';
  draw();
  window.addEventListener(wish.EVENT, draw);
})();
