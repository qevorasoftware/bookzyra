(function () {
  'use strict';

  const root = document.querySelector('.bookzyra-admin-wrap');
  if (!root) return;

  const methodsContainer = document.querySelector('[data-custom-methods]');
  const addMethodButton = document.querySelector('[data-add-payment-method]');
  const methodTemplate = document.querySelector('#bookzyra-custom-method-template');
  let nextIndex = methodsContainer ? methodsContainer.querySelectorAll('[data-custom-method-row]').length : 0;

  function refreshEmptyState() {
    if (!methodsContainer) return;
    const rows = methodsContainer.querySelectorAll('[data-custom-method-row]');
    const empty = methodsContainer.querySelector('[data-custom-empty]');
    if (!rows.length && !empty) {
      const message = document.createElement('div');
      message.className = 'bz-custom-empty';
      message.setAttribute('data-custom-empty', '');
      message.textContent = 'No custom methods yet. Add one to give customers another way to pay.';
      methodsContainer.appendChild(message);
    } else if (rows.length && empty) {
      empty.remove();
    }
  }

  if (addMethodButton && methodTemplate && methodsContainer) {
    addMethodButton.addEventListener('click', function () {
      const fragment = methodTemplate.content.cloneNode(true);
      const row = fragment.querySelector('[data-custom-method-row]');
      if (!row) return;
      row.querySelectorAll('[name]').forEach(function (field) {
        field.name = field.name.replace(/__index__/g, String(nextIndex));
      });
      row.querySelectorAll('[id], [for]').forEach(function (field) {
        if (field.id) field.id = field.id.replace(/__index__/g, String(nextIndex));
        if (field.htmlFor) field.htmlFor = field.htmlFor.replace(/__index__/g, String(nextIndex));
      });
      nextIndex += 1;
      methodsContainer.querySelectorAll('[data-custom-empty]').forEach(function (empty) { empty.remove(); });
      methodsContainer.appendChild(row);
      const label = row.querySelector('input[name$="[label]"]');
      if (label) label.focus();
    });
  }

  document.addEventListener('click', function (event) {
    const removeButton = event.target.closest('[data-remove-payment-method]');
    if (!removeButton) return;
    const row = removeButton.closest('[data-custom-method-row]');
    if (row) row.remove();
    refreshEmptyState();
  });

  document.addEventListener('input', function (event) {
    const labelInput = event.target.closest('.bz-custom-method-row input[name$="[label]"]');
    if (!labelInput) return;
    const row = labelInput.closest('[data-custom-method-row]');
    const heading = row && row.querySelector('.bz-custom-method-row-head strong');
    if (heading) heading.textContent = labelInput.value.trim() || 'New payment option';
  });

  document.addEventListener('change', function (event) {
    const checkbox = event.target.closest('.bz-day-toggle input[type="checkbox"]');
    if (!checkbox) return;
    const row = checkbox.closest('.bz-day-row');
    if (row) row.classList.toggle('is-closed', !checkbox.checked);
  });

  document.querySelectorAll('.bz-day-toggle input[type="checkbox"]').forEach(function (checkbox) {
    const row = checkbox.closest('.bz-day-row');
    if (row) row.classList.toggle('is-closed', !checkbox.checked);
  });
}());
