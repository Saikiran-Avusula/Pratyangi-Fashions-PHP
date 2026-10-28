/*
 * The shopper's bag in the browser, in plain JavaScript. It does what the web's lib/bag.ts does:
 *   - localStorage key "pratyangi-demo-bag" (the word "demo" is in the real key), a JSON array of {"id": "<sku>", "quantity": 1..10};
 *   - an old-style entry that is just a string is read as {id, quantity: 1};
 *   - quantities are rounded and clamped to 1..10 when read and written; adding a SKU that is already there raises it by one (max 10);
 *   - after every write the window event "pratyangi-bag-change" is dispatched (the browser's own "storage" event reaches other tabs);
 *   - text that is not a JSON array of such entries is read as an empty bag, with the message below;
 *   - a SKU is kept whatever the catalogue says (the bag and checkout mark lines the catalogue no longer has).
 * The product pages use window.PratyangiBag; the bag page (later step) reuses the same helpers.
 * It also wires every "Add to cart" button of a product card ([data-pf="add-to-bag"]) and tells the page with the document event
 * "pf:bag-added" ({detail: {sku, ok, message}}).
 * No network, no libraries, no inline script.
 */
(function (root) {
  'use strict';

  var KEY = 'pratyangi-demo-bag';
  var EVENT = 'pratyangi-bag-change';
  var MAX = 10;
  var UNREADABLE = 'The saved demo bag could not be read, so it started empty.';
  var NO_STORAGE = 'Browser storage is unavailable; bag changes will not persist.';

  function clamp(quantity) {
    return Math.min(Math.max(1, Math.round(quantity)), MAX);
  }

  /** The web's parseBag: null when the text is not a bag. A missing key (raw === null) is an empty bag. */
  function parse(raw) {
    if (raw === null) return [];
    var parsed = JSON.parse(raw);
    if (!Array.isArray(parsed)) return null;
    var lines = [];
    for (var i = 0; i < parsed.length; i++) {
      var entry = parsed[i];
      var line = typeof entry === 'string' ? { id: entry, quantity: 1 } : entry;
      if (!line || typeof line.id !== 'string' || typeof line.quantity !== 'number') return null;
      lines.push({ id: line.id, quantity: clamp(line.quantity) });
    }
    return lines;
  }

  /** {lines, message}: message is '' unless storage is unavailable or the saved text is unreadable (then lines is empty). */
  function read() {
    try {
      var parsed = parse(root.localStorage.getItem(KEY));
      if (parsed) return { lines: parsed, message: '' };
      return { lines: [], message: UNREADABLE };
    } catch (error) {
      return { lines: [], message: NO_STORAGE };
    }
  }

  /** Saves the lines and announces the change. Returns {ok, message}. */
  function write(lines) {
    try {
      root.localStorage.setItem(KEY, JSON.stringify(lines));
    } catch (error) {
      return { ok: false, message: NO_STORAGE };
    }
    var announce = function () { root.dispatchEvent(new root.Event(EVENT)); };
    if (typeof root.queueMicrotask === 'function') root.queueMicrotask(announce); else announce();
    return { ok: true, message: '' };
  }

  function commit(update) {
    return write(update(read().lines));
  }

  function add(id) {
    return commit(function (current) {
      var found = false;
      var next = current.map(function (line) {
        if (line.id !== id) return line;
        found = true;
        return { id: line.id, quantity: Math.min(line.quantity + 1, MAX) };
      });
      if (!found) next.push({ id: id, quantity: 1 });
      return next;
    });
  }

  function remove(id) {
    return commit(function (current) { return current.filter(function (line) { return line.id !== id; }); });
  }

  function setQuantity(id, quantity) {
    return commit(function (current) {
      return current.map(function (line) {
        return line.id === id ? { id: line.id, quantity: Math.min(Math.max(1, quantity), MAX) } : line;
      });
    });
  }

  function clear() {
    return write([]);
  }

  /** The header count: the sum of the quantities. */
  function count(lines) {
    return lines.reduce(function (total, line) { return total + line.quantity; }, 0);
  }

  root.PratyangiBag = {
    KEY: KEY, EVENT: EVENT, MAX: MAX, UNREADABLE: UNREADABLE, NO_STORAGE: NO_STORAGE,
    parse: parse, read: read, write: write, add: add, remove: remove, setQuantity: setQuantity, clear: clear, count: count
  };

  // the product cards' "Add to cart" buttons (home page and New Arrivals)
  var doc = root.document;
  if (doc && typeof doc.addEventListener === 'function') {
    doc.addEventListener('click', function (event) {
      var button = event.target && event.target.closest ? event.target.closest('[data-pf="add-to-bag"]') : null;
      if (!button || button.disabled) return;
      var sku = button.getAttribute('data-sku') || '';
      if (!sku) return;
      var result = add(sku);
      doc.dispatchEvent(new root.CustomEvent('pf:bag-added', { detail: { sku: sku, ok: result.ok, message: result.message } }));
    });
  }
})(typeof window !== 'undefined' ? window : globalThis);
