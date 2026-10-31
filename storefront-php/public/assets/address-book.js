/*
 * Delivery addresses: the add / edit dialog and the saved-address list, on /checkout and on /account. Talks to /api/v1/addresses (cookie, same origin).
 * The dialog opens by itself on /checkout when the customer has no address; on /account it only opens from "+ Add address" or Edit.
 * Moved here unchanged from bag-page.js; every value is written with textContent / value, never as HTML.
 */
(function (win) {
  'use strict';
  if (window.__pfAddressBook) return; window.__pfAddressBook = true; // REQ1: loaded twice = set up once

  var doc = win.document;
  // the tick box at checkout: a tick records the choice at once (it is never ticked for the visitor and an untick does not undo an earlier yes)
  (function initOffers() {
    var box = doc.querySelector('[data-pf-offers="checkout"]');
    if (box && win.PratyangiOffers) box.addEventListener('change', function () { win.PratyangiOffers.save('checkout', box.checked); });
  })();
  (function initAddressBook() {
    var checkoutPage = !!doc.querySelector('[data-pf-bag-page="checkout"]');
    function syncType() { doc.querySelectorAll('[data-pf-type-label]').forEach(function (l) { l.setAttribute('aria-checked', l.classList.contains('mdI9xa_typeActive') ? 'true' : 'false'); }); } // the chips announce which one is chosen
    var modal = doc.querySelector('[data-pf-address-modal]');
    var openBtn = doc.querySelector('[data-pf-open-address]');
    var form = doc.querySelector('[data-pf-modal-form]');
    var title = doc.querySelector('[data-pf-modal-title]');
    var genError = doc.querySelector('[data-pf-modal-general-error]');
    var list = doc.querySelector('[data-pf-address-list]');

    function clearErrors() {
      if (genError) { genError.textContent = ''; genError.hidden = true; }
      var errs = doc.querySelectorAll('[data-pf-error]');
      for (var i = 0; i < errs.length; i++) {
        errs[i].textContent = '';
        errs[i].hidden = true;
      }
    }

    function showFieldErrors(errors) {
      clearErrors();
      for (var f in errors) {
        if (Object.prototype.hasOwnProperty.call(errors, f)) {
          var el = doc.querySelector('[data-pf-error="' + f + '"]');
          if (el) {
            el.textContent = errors[f];
            el.hidden = false;
          }
        }
      }
    }

    function openModal(editingData) {
      if (!modal) return;
      clearErrors();
      if (form) form.reset();
      var idField = doc.querySelector('[data-pf-field="id"]');
      if (idField) idField.value = '';

      var typeLabels = doc.querySelectorAll('[data-pf-type-label]');
      typeLabels.forEach(function (l) { l.classList.remove('mdI9xa_typeActive'); });
      var homeLabel = doc.querySelector('[data-pf-type-label="Home"]');
      if (homeLabel) homeLabel.classList.add('mdI9xa_typeActive');
      syncType();

      if (editingData) {
        if (title) title.textContent = 'Edit Address';
        if (idField) idField.value = editingData.id || '';
        ['name', 'house', 'street', 'city', 'state', 'pincode', 'label'].forEach(function (k) {
          var input = doc.querySelector('[data-pf-field="' + k + '"]');
          if (input) input.value = editingData[k] || '';
        });
        var phoneInput = doc.querySelector('[data-pf-field="phone"]');
        if (phoneInput && editingData.phone) {
          phoneInput.value = editingData.phone.replace(/^\+91\s*/, '');
        }
        var altInput = doc.querySelector('[data-pf-field="alternatePhone"]');
        if (altInput && editingData.alternatePhone) {
          altInput.value = editingData.alternatePhone.replace(/^\+91\s*/, '');
        }
        var isDef = doc.querySelector('[data-pf-field="isDefault"]');
        if (isDef) isDef.checked = !!editingData.isDefault;
        var t = editingData.type || 'Home';
        var typeInput = doc.querySelector('[data-pf-field="type"][value="' + t + '"]');
        if (typeInput) {
          typeInput.checked = true;
          typeLabels.forEach(function (l) { l.classList.remove('mdI9xa_typeActive'); });
          var targetLabel = doc.querySelector('[data-pf-type-label="' + t + '"]');
          if (targetLabel) targetLabel.classList.add('mdI9xa_typeActive');
          syncType();
        }
      } else {
        if (title) title.textContent = 'Add New Address';
      }
      modal.hidden = false;
      if (win.PratyangiScroll) win.PratyangiScroll.lock(); // only the sheet scrolls
    }

    function closeModal() {
      if (!modal) return;
      modal.hidden = true;
      if (win.PratyangiScroll) win.PratyangiScroll.unlock();
      clearErrors();
    }

    if (openBtn) {
      openBtn.addEventListener('click', function () { openModal(null); });
    }

    // after a save the page reloads; pick the address that was just saved
    try {
      var picked = win.sessionStorage.getItem('pf-select-address');
      win.sessionStorage.removeItem('pf-select-address');
      var pickedCard = picked && list && list.querySelector('[data-pf-address-id="' + picked.replace(/[^0-9A-Za-z_-]/g, '') + '"]');
      var pickedRadio = pickedCard && pickedCard.querySelector('[data-pf-address-radio]');
      if (pickedRadio) { pickedRadio.checked = true; pickedRadio.dispatchEvent(new Event('change', { bubbles: true })); }
    } catch (x) { /* no storage: keep the default selection */ }

    // no saved address: the add-address form is the first thing the customer sees
    if (checkoutPage && list && !list.querySelector('[data-pf-address-id]')) openModal(null);

    var closeBtns = doc.querySelectorAll('[data-pf-modal-close]');
    closeBtns.forEach(function (b) {
      b.addEventListener('click', closeModal);
    });

    doc.addEventListener('keydown', function (e) {
      if (e.key === 'Escape' && modal && !modal.hidden) closeModal();
    });

    if (modal) {
      modal.addEventListener('click', function (e) {
        if (e.target === modal) closeModal();
      });
    }

    var typeRadios = doc.querySelectorAll('[data-pf-field="type"]');
    typeRadios.forEach(function (r) {
      r.addEventListener('change', function () {
        var typeLabels = doc.querySelectorAll('[data-pf-type-label]');
        typeLabels.forEach(function (l) { l.classList.remove('mdI9xa_typeActive'); });
        var l = doc.querySelector('[data-pf-type-label="' + r.value + '"]');
        if (l) l.classList.add('mdI9xa_typeActive');
        syncType();
      });
    });

    if (list) {
      list.addEventListener('change', function (e) {
        if (e.target && e.target.matches('[data-pf-address-radio]')) {
          var cards = list.querySelectorAll('[data-pf-address-id]');
          cards.forEach(function (c) { c.classList.remove('FVHMJa_selected'); });
          var parent = e.target.closest('[data-pf-address-id]');
          if (parent) parent.classList.add('FVHMJa_selected');
        }
      });

      list.addEventListener('click', function (e) {
        var editBtn = e.target.closest('[data-pf-edit-address]');
        if (editBtn) {
          var raw = editBtn.getAttribute('data-address');
          if (raw) {
            try { openModal(JSON.parse(raw)); } catch (err) {}
          }
          return;
        }

        var deleteBtn = e.target.closest('[data-pf-delete-address]');
        if (deleteBtn) {
          var addrId = deleteBtn.getAttribute('data-pf-delete-address');
          if (!addrId) return;
          deleteBtn.disabled = true;
          win.fetch('/api/v1/addresses/' + encodeURIComponent(addrId), {
            method: 'DELETE',
            credentials: 'include'
          }).then(function (res) {
            if (res.ok || res.status === 204) {
              win.location.reload();
            } else {
              deleteBtn.disabled = false;
              alert('We could not delete that address. Please try again.');
            }
          }, function () {
            deleteBtn.disabled = false;
            alert('Connection problem. Please try again.');
          });
        }
      });
    }

    if (form) {
      var saving = false; // REQ1: a double tap or Enter while saving sends ONE request
      form.addEventListener('submit', function (e) {
        e.preventDefault();
        if (saving) return;
        clearErrors();
        var idField = doc.querySelector('[data-pf-field="id"]');
        var addrId = idField ? idField.value.trim() : '';
        var isEdit = addrId !== '';

        var body = {
          type: (doc.querySelector('[data-pf-field="type"]:checked') || {}).value || 'Home',
          name: (doc.querySelector('[data-pf-field="name"]') || {}).value || '',
          phone: (doc.querySelector('[data-pf-field="phone"]') || {}).value || '',
          alternatePhone: (doc.querySelector('[data-pf-field="alternatePhone"]') || {}).value || '',
          label: (doc.querySelector('[data-pf-field="label"]') || {}).value || '',
          house: (doc.querySelector('[data-pf-field="house"]') || {}).value || '',
          street: (doc.querySelector('[data-pf-field="street"]') || {}).value || '',
          city: (doc.querySelector('[data-pf-field="city"]') || {}).value || '',
          state: (doc.querySelector('[data-pf-field="state"]') || {}).value || '',
          pincode: (doc.querySelector('[data-pf-field="pincode"]') || {}).value || '',
          isDefault: !!(doc.querySelector('[data-pf-field="isDefault"]') || {}).checked
        };

        var saveBtn = doc.querySelector('[data-pf-modal-save]');
        if (saveBtn) { saveBtn.disabled = true; saveBtn.textContent = 'Saving…'; }

        var url = isEdit ? '/api/v1/addresses/' + encodeURIComponent(addrId) : '/api/v1/addresses';
        var method = isEdit ? 'PUT' : 'POST';

        saving = true;
        win.fetch(url, {
          method: method,
          credentials: 'include',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify(body)
        }).then(function (res) {
          return res.json().catch(function () { return {}; }).then(function (data) {
            return { ok: res.ok, status: res.status, data: data };
          });
        }, function () {
          return { ok: false, status: 0, data: { message: 'We could not reach the server. Check your connection and try again.' } };
        }).then(function (res) {
          saving = false;
          if (saveBtn) { saveBtn.disabled = false; saveBtn.textContent = 'Save Address'; }
          if (res.ok) {
            closeModal();
            try { win.sessionStorage.setItem('pf-select-address', String((res.data.address || {}).id || addrId)); } catch (x) { /* no storage: the list just reloads */ }
            var offersBox = doc.querySelector('[data-pf-field="offers"]');
            var saved = offersBox && offersBox.checked && win.PratyangiOffers ? win.PratyangiOffers.save('address', true) : win.Promise.resolve();
            saved.then(function () { win.location.reload(); }); // the choice is stored before the page reloads
          } else if (res.status === 422 && res.data.errors) {
            showFieldErrors(res.data.errors);
          } else {
            if (genError) {
              genError.textContent = res.data.message || 'Something went wrong. Please try again.';
              genError.hidden = false;
            }
          }
        });
      });
    }
  })();
})(window);
