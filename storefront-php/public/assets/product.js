/*
 * The product page's "Add to bag" button, in plain JavaScript (needs bag.js first).
 * Like the web's ProductView: a sold-out button does nothing; otherwise the SKU goes into the bag (key pratyangi-demo-bag,
 * event pratyangi-bag-change) and "Added to your bag. View bag" appears under the button.
 * One difference: if the browser refuses to store the bag, the web still says "Added"; here the note says that bag changes will not persist.
 */
(function () {
  'use strict';

  var doc = document;
  var button = doc.querySelector('[data-pf="add-product"]');
  var bag = window.PratyangiBag;
  if (!button || !bag) return;

  var note = null;

  function show(result) {
    if (!note) {
      note = doc.createElement('p');
      note.className = 'udK_nG_note';
      note.setAttribute('role', 'status');
      button.parentNode.appendChild(note);
    }
    note.textContent = '';
    if (!result.ok) {
      note.textContent = result.message;
      return;
    }
    note.appendChild(doc.createTextNode('Added to your bag. '));
    var link = doc.createElement('a');
    link.setAttribute('href', button.getAttribute('data-bag-href') || '/soon');
    link.textContent = 'View bag';
    note.appendChild(link);
  }

  button.addEventListener('click', function () {
    if (button.disabled) return;
    var sku = button.getAttribute('data-sku') || '';
    if (!sku) return;
    show(bag.add(sku));
  });
})();
