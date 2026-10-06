(function () {
  'use strict';

  const config = window.BookzyraFront || {};
  const strings = config.text || {};
  const currency = config.currency || 'EUR';
  const apiUrl = String(config.apiUrl || '').replace(/\/?$/, '/');
  const locale = document.documentElement.lang || navigator.language || 'en';

  function element(tag, className, text) {
    const node = document.createElement(tag);
    if (className) node.className = className;
    if (typeof text === 'string') node.textContent = text;
    return node;
  }

  function priceLabel(amount) {
    if (Number(amount) <= 0) return strings.free || 'Free';
    try {
      return new Intl.NumberFormat(locale, { style: 'currency', currency }).format(Number(amount));
    } catch (error) {
      return Number(amount).toFixed(2) + ' ' + currency;
    }
  }

  function dateLabel(value) {
    if (!value) return strings.notSelected || 'Not selected';
    const parts = value.split('-').map(Number);
    const date = new Date(Date.UTC(parts[0], parts[1] - 1, parts[2], 12));
    return new Intl.DateTimeFormat(locale, {
      weekday: 'short', month: 'short', day: 'numeric', year: 'numeric', timeZone: 'UTC'
    }).format(date);
  }

  function timeLabel(value) {
    if (!value) return strings.notSelected || 'Not selected';
    const pieces = value.split(':').map(Number);
    const date = new Date(Date.UTC(2000, 0, 1, pieces[0], pieces[1] || 0));
    return new Intl.DateTimeFormat(locale, {
      hour: 'numeric', minute: '2-digit', timeZone: 'UTC'
    }).format(date);
  }

  function request(url, options) {
    return fetch(url, options || {}).then(function (response) {
      return response.json().catch(function () { return {}; }).then(function (data) {
        if (!response.ok) {
          const message = data && data.message ? data.message : (strings.bookingFailed || 'Something went wrong.');
          throw new Error(message);
        }
        return data;
      });
    });
  }

  function mount(root) {
    const state = { services: [], service: null, date: '', time: '', method: '', busy: false };
    let slotRequestId = 0;
    const serviceList = root.querySelector('[data-service-list]');
    const slotList = root.querySelector('[data-slot-list]');
    const dateInput = root.querySelector('[data-date-input]');
    const form = root.querySelector('[data-booking-form]');
    const alertBox = root.querySelector('[data-booking-alert]');
    const nextService = root.querySelector('[data-next-step="2"]');
    const nextTime = root.querySelector('[data-next-step="3"]');
    const submitButton = root.querySelector('[data-submit-booking]');
    const submitLabel = root.querySelector('[data-submit-label]');
    const methodList = root.querySelector('[data-payment-list]');
    const panels = Array.prototype.slice.call(root.querySelectorAll('[data-step]'));
    const indicators = Array.prototype.slice.call(root.querySelectorAll('[data-step-indicator]'));
    const selectedServiceSummary = root.querySelector('[data-selected-service-summary]');
    const finalServiceSummary = root.querySelector('[data-final-service-summary]');

    dateInput.min = config.today || '';
    dateInput.max = config.maxDate || '';
    dateInput.value = config.today || '';

    function showAlert(message, type) {
      alertBox.textContent = message;
      alertBox.className = 'bz-alert' + (type ? ' is-' + type : '');
      alertBox.hidden = false;
    }

    function clearAlert() {
      alertBox.textContent = '';
      alertBox.hidden = true;
      alertBox.className = 'bz-alert';
    }

    function setStep(step) {
      clearAlert();
      panels.forEach(function (panel) {
        const active = panel.getAttribute('data-step') === String(step);
        panel.hidden = !active;
        panel.classList.toggle('is-active', active);
      });
      indicators.forEach(function (indicator) {
        const number = Number(indicator.getAttribute('data-step-indicator'));
        indicator.classList.toggle('is-current', step !== 'success' && number === Number(step));
        indicator.classList.toggle('is-complete', step === 'success' || (typeof step === 'number' && number < step));
        if (number === Number(step)) indicator.setAttribute('aria-current', 'step');
        else indicator.removeAttribute('aria-current');
      });
      if (window.matchMedia && window.matchMedia('(max-width: 720px)').matches) {
        root.scrollIntoView({ behavior: 'smooth', block: 'start' });
      }
    }

    function updateSummary() {
      const empty = root.querySelector('[data-summary-empty]');
      const details = root.querySelector('[data-summary-details]');
      if (!state.service) {
        empty.hidden = false;
        details.hidden = true;
        return;
      }
      empty.hidden = true;
      details.hidden = false;
      root.querySelector('[data-summary-service]').textContent = state.service.name;
      root.querySelector('[data-summary-duration]').textContent = state.service.duration + ' ' + (strings.minute || 'min');
      root.querySelector('[data-summary-date]').textContent = dateLabel(state.date);
      root.querySelector('[data-summary-time]').textContent = timeLabel(state.time);
      root.querySelector('[data-summary-price]').textContent = priceLabel(state.service.price);
      root.querySelector('[data-summary-dot]').style.backgroundColor = state.service.color || '#6257e8';
      const serviceSummary = buildSelectedService();
      selectedServiceSummary.replaceChildren(serviceSummary.cloneNode(true));
      finalServiceSummary.replaceChildren(serviceSummary);
    }

    function buildSelectedService() {
      const wrapper = element('div', 'bz-selected-service-inner');
      const dot = element('span', 'bz-service-dot');
      dot.style.backgroundColor = state.service.color || '#6257e8';
      const copy = element('span', 'bz-selected-service-copy');
      const name = element('strong', '', state.service.name);
      const meta = element('small', '', state.service.duration + ' ' + (strings.minute || 'min') + ' · ' + priceLabel(state.service.price));
      copy.append(name, meta);
      wrapper.append(dot, copy);
      const change = element('button', 'bz-change-service', 'Change');
      change.type = 'button';
      change.addEventListener('click', function () { setStep(1); });
      wrapper.append(change);
      return wrapper;
    }

    function renderServices(services) {
      serviceList.replaceChildren();
      if (!services.length) {
        const empty = element('div', 'bz-empty-services');
        empty.append(element('span', 'bz-empty-services-icon', '✦'));
        empty.append(element('strong', '', strings.noServices || 'There are no services available right now.'));
        serviceList.append(empty);
        return;
      }

      services.forEach(function (service) {
        const card = element('button', 'bz-service-card');
        card.type = 'button';
        card.setAttribute('aria-pressed', 'false');
        card.style.setProperty('--service-color', service.color || '#6257e8');
        const icon = element('span', 'bz-service-card-icon', '✦');
        const copy = element('span', 'bz-service-card-copy');
        copy.append(element('strong', 'bz-service-title', service.name));
        copy.append(element('span', 'bz-service-meta', service.duration + ' ' + (strings.minute || 'min')));
        if (service.description) copy.append(element('span', 'bz-service-description', service.description));
        const price = element('span', 'bz-service-price', priceLabel(service.price));
        const check = element('span', 'bz-service-check', '✓');
        check.setAttribute('aria-hidden', 'true');
        card.append(icon, copy, price, check);
        card.addEventListener('click', function () {
          state.service = service;
          state.time = '';
          state.method = '';
          nextService.disabled = false;
          serviceList.querySelectorAll('.bz-service-card').forEach(function (button) {
            const selected = button === card;
            button.classList.toggle('is-selected', selected);
            button.setAttribute('aria-pressed', selected ? 'true' : 'false');
          });
          updateSummary();
        });
        serviceList.append(card);
      });

      const preferred = Number(root.dataset.preselectedService || config.preselectedService || 0);
      if (preferred) {
        const cardIndex = services.findIndex(function (service) { return Number(service.id) === preferred; });
        if (cardIndex > -1) serviceList.querySelectorAll('.bz-service-card')[cardIndex].click();
      }
    }

    function renderPaymentMethods() {
      methodList.replaceChildren();
      const methods = (config.methods || []).filter(function (method) {
        if (method.id === 'vpayments') return Boolean(state.service && Number(state.service.price) > 0);
        if (method.id === 'free') return Boolean(state.service && Number(state.service.price) <= 0);
        return true;
      });
      if (!methods.length) {
        const empty = element('p', 'bz-payment-empty', 'No payment methods are available. Please contact the site owner.');
        methodList.append(empty);
        return;
      }
      if (!methods.some(function (method) { return method.id === state.method; })) {
        const freeMethod = methods.find(function (method) { return method.id === 'free'; });
        state.method = freeMethod ? freeMethod.id : methods[0].id;
      }
      const selectedMethod = methods.find(function (method) { return method.id === state.method; });
      if (submitLabel && selectedMethod) {
        submitLabel.textContent = selectedMethod.id === 'vpayments' ? (strings.payNow || 'Continue to secure payment') : (strings.requestBooking || 'Request appointment');
      }

      methods.forEach(function (method, index) {
        const label = element('label', 'bz-payment-option');
        const radio = element('input');
        radio.type = 'radio';
        radio.name = 'bookzyra-payment-' + root.id;
        radio.value = method.id;
        radio.checked = method.id === state.method;
        label.classList.toggle('is-selected', radio.checked);
        const radioMark = element('span', 'bz-radio-mark');
        const copy = element('span', 'bz-payment-copy');
        copy.append(element('strong', '', method.label));
        if (method.description) copy.append(element('small', '', method.description));
        const icon = element('span', 'bz-payment-option-icon', method.id === 'vpayments' ? '⌑' : (method.id === 'offline' ? '◷' : '↗'));
        label.append(radio, radioMark, icon, copy);
        radio.addEventListener('change', function () {
          state.method = method.id;
          if (submitLabel) submitLabel.textContent = method.id === 'vpayments' ? (strings.payNow || 'Continue to secure payment') : (strings.requestBooking || 'Request appointment');
          methodList.querySelectorAll('.bz-payment-option').forEach(function (option) {
            const selected = option.querySelector('input[type="radio"]').checked;
            option.classList.toggle('is-selected', selected);
          });
        });
        methodList.append(label);
      });
    }

    function loadSlots() {
      const requestId = ++slotRequestId;
      state.date = dateInput.value;
      state.time = '';
      nextTime.disabled = true;
      root.querySelector('[data-slot-date-label]').textContent = state.date ? dateLabel(state.date) : '';
      updateSummary();
      slotList.replaceChildren();
      const loading = element('div', 'bz-slots-loading');
      loading.append(element('span', 'bz-spinner'));
      loading.append(document.createTextNode('Loading available times…'));
      slotList.append(loading);
      if (!state.service || !state.date) return;

      const url = apiUrl + 'slots?service_id=' + encodeURIComponent(state.service.id) + '&date=' + encodeURIComponent(state.date);
      request(url).then(function (result) {
        if (requestId !== slotRequestId) return;
        slotList.replaceChildren();
        if (!Array.isArray(result.slots) || result.slots.length === 0) {
          slotList.append(element('p', 'bz-placeholder-text', strings.noSlots || 'No times are available on this date. Try another day.'));
          return;
        }
        result.slots.forEach(function (time) {
          const button = element('button', 'bz-slot-button', timeLabel(time));
          button.type = 'button';
          button.setAttribute('aria-pressed', 'false');
          button.addEventListener('click', function () {
            state.time = time;
            nextTime.disabled = false;
            slotList.querySelectorAll('.bz-slot-button').forEach(function (slot) {
              slot.classList.toggle('is-selected', slot === button);
              slot.setAttribute('aria-pressed', slot === button ? 'true' : 'false');
            });
            updateSummary();
          });
          slotList.append(button);
        });
      }).catch(function (error) {
        if (requestId !== slotRequestId) return;
        slotList.replaceChildren(element('p', 'bz-placeholder-text is-error', error.message || strings.slotsFailed || 'Could not load times.'));
      });
    }

    function renderSuccess(result, paymentMethod) {
      const success = root.querySelector('[data-step="success"]');
      success.replaceChildren();
      const icon = element('div', 'bz-success-icon', '✓');
      const eyebrow = element('p', 'bz-overline', 'BOOKZYRA · ' + (strings.bookingReceived || 'BOOKING RECEIVED'));
      const title = element('h3', '', strings.bookingReceived || 'Appointment request received');
      const message = element('p', 'bz-success-message', result.message || strings.booked || 'Your appointment request has been received.');
      const reference = element('div', 'bz-success-reference');
      reference.append(element('span', '', strings.bookingReference || 'Booking reference'));
      reference.append(element('strong', '', result.reference || ''));
      success.append(icon, eyebrow, title, message, reference);
      if (paymentMethod && paymentMethod.instructions) {
        const instructions = element('div', 'bz-success-instructions');
        instructions.append(element('strong', '', paymentMethod.label));
        instructions.append(element('p', '', paymentMethod.instructions));
        success.append(instructions);
      }
      const again = element('button', 'bz-button bz-button-quiet bz-again-button', strings.chooseAnother || 'Make another booking');
      again.type = 'button';
      again.addEventListener('click', function () { window.location.reload(); });
      success.append(again);
      setStep('success');
    }

    nextService.addEventListener('click', function () {
      if (!state.service) {
        showAlert(strings.selectService || 'Please choose a service first.', 'error');
        return;
      }
      state.date = dateInput.value || config.today || '';
      dateInput.value = state.date;
      state.time = '';
      nextTime.disabled = true;
      setStep(2);
      loadSlots();
    });

    nextTime.addEventListener('click', function () {
      if (!state.time) {
        showAlert(strings.selectTime || 'Please choose an available appointment time.', 'error');
        return;
      }
      setStep(3);
      renderPaymentMethods();
      updateSummary();
    });

    root.querySelectorAll('[data-prev-step]').forEach(function (button) {
      button.addEventListener('click', function () { setStep(Number(button.getAttribute('data-prev-step'))); });
    });

    dateInput.addEventListener('change', function () {
      if (dateInput.value) {
        loadSlots();
        return;
      }
      slotRequestId += 1;
      state.date = '';
      state.time = '';
      nextTime.disabled = true;
      slotList.replaceChildren(element('p', 'bz-placeholder-text', 'Choose a date to see available appointments.'));
      root.querySelector('[data-slot-date-label]').textContent = '';
      updateSummary();
    });

    form.addEventListener('submit', function (event) {
      event.preventDefault();
      clearAlert();
      if (!state.service) {
        showAlert(strings.selectService || 'Please choose a service first.', 'error');
        setStep(1);
        return;
      }
      if (!state.time) {
        showAlert(strings.selectTime || 'Please choose an available appointment time.', 'error');
        setStep(2);
        return;
      }
      if (!form.reportValidity()) {
        showAlert(strings.formRequired || 'Please complete the required fields.', 'error');
        return;
      }
      const payment = form.querySelector('input[type="radio"]:checked');
      if (!payment) {
        showAlert(strings.selectPayment || 'Please choose a payment method.', 'error');
        return;
      }

      const formData = new FormData(form);
      const payload = {
        service_id: state.service.id,
        date: state.date,
        time: state.time,
        name: formData.get('name'),
        email: formData.get('email'),
        phone: formData.get('phone'),
        note: formData.get('note'),
        payment_method: payment.value,
        consent: formData.get('consent') ? true : false,
        website: formData.get('website')
      };
      const selectedMethod = (config.methods || []).find(function (method) { return method.id === payment.value; });
      state.busy = true;
      submitButton.disabled = true;
      submitButton.classList.add('is-loading');
      const originalButtonText = submitLabel ? submitLabel.textContent.trim() : submitButton.textContent.trim();
      if (submitLabel) submitLabel.textContent = payment.value === 'vpayments' ? (strings.processing || 'Processing…') : (strings.sending || 'Sending your request…');

      request(apiUrl + 'bookings', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': config.nonce || '' },
        body: JSON.stringify(payload)
      }).then(function (result) {
        if (result.redirect_url) {
          if (submitLabel) submitLabel.textContent = strings.processing || 'Processing…';
          window.location.assign(result.redirect_url);
          return;
        }
        renderSuccess(result, selectedMethod);
      }).catch(function (error) {
        showAlert(error.message || strings.bookingFailed || 'We couldn’t complete your booking. Please try again.', 'error');
        submitButton.disabled = false;
        submitButton.classList.remove('is-loading');
        if (submitLabel) submitLabel.textContent = originalButtonText;
        state.busy = false;
      });
    });

    request(apiUrl + 'services').then(function (result) {
      state.services = Array.isArray(result.services) ? result.services : [];
      renderServices(state.services);
    }).catch(function () {
      serviceList.replaceChildren(element('div', 'bz-empty-services is-error', strings.loadFailed || 'We couldn’t load services just now. Please refresh and try again.'));
    });
  }

  document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('[data-bookzyra-widget]').forEach(mount);
  });
}());
