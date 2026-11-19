const form = document.getElementById('customerOrderForm');

if (form) {
  const gcashDialog = document.getElementById('gcashPaymentDialog');
  const paymentExpectedAmount = document.getElementById('paymentExpectedAmount');
  const paymentConfirmed = document.getElementById('paymentConfirmed');
  const paymentReference = document.getElementById('paymentReference');
  const gcashAutoReference = document.getElementById('gcashAutoReference');
  const paymentProof = document.getElementById('paymentProof');
  const gcashChangeWarning = document.getElementById('gcashChangeWarning');
  const gcashProofPreview = document.getElementById('gcashProofPreview');
  const gcashProofPreviewImage = document.getElementById('gcashProofPreviewImage');
  const paymentMethod = document.getElementById('paymentMethod');
  const otherPaymentWrap = document.getElementById('otherPaymentWrap');
  const otherPaymentMethod = document.getElementById('otherPaymentMethod');
  const pickupDate = document.getElementById('pickupDate');
  const pickupDateDisplay = document.getElementById('pickupDateDisplay');
  const pickupDateStatus = document.getElementById('pickupDateStatus');
  const pickupCalendar = document.getElementById('pickupCalendar');
  const pickupRefresh = document.getElementById('pickupRefresh');
  const pickupLastUpdated = document.getElementById('pickupLastUpdated');
  const pickupDialog = document.getElementById('pickupScheduleDialog');
  const pickupTime = document.getElementById('pickupTime');
  const pickupScheduleSummary = document.getElementById('pickupScheduleSummary');
  const pickupScheduleButton = document.getElementById('openPickupSchedule');
  const fulfillmentInputs = Array.from(document.querySelectorAll('input[name="fulfillment_method"]'));
  const fulfillmentStepLabel = document.getElementById('fulfillmentStepLabel');
  const fulfillmentStageHeading = document.getElementById('fulfillmentStageHeading');
  const pickupFulfillmentPanel = document.getElementById('pickupFulfillmentPanel');
  const deliveryFulfillmentPanel = document.getElementById('deliveryFulfillmentPanel');
  const fulfillmentAddressField = document.getElementById('fulfillmentAddressField');
  const fulfillmentAddress = document.getElementById('fulfillmentAddress');
  const deliveryAddressPreview = document.getElementById('deliveryAddressPreview');
  let pickupDraftBeforeOpen = null;
  let pickupDefaultCapacity = Number(pickupDate?.dataset?.defaultCapacity || 20) || 20;
  let pickupCapacity = new Map((window.pickupCapacity || []).map((row) => [String(row.date || ''), row]));
  const pickupCalendarDays = Math.max(14, Math.min(365, Number(pickupDate?.dataset?.days || 120) || 120));
  const pickupCapacityApi = window.pickupCapacityApi || 'pickup_dates_api.php';

  const updatePaymentMethod = () => {
    const isOther = paymentMethod?.value === 'Other';
    if (otherPaymentWrap) otherPaymentWrap.hidden = !isOther;
    if (otherPaymentMethod) otherPaymentMethod.required = Boolean(isOther);
  };

  paymentMethod?.addEventListener('change', updatePaymentMethod);
  updatePaymentMethod();

  const selectedFulfillmentMethod = () => {
    return document.querySelector('input[name="fulfillment_method"]:checked')?.value === 'delivery' ? 'delivery' : 'pickup';
  };

  const syncDeliveryPreview = () => {
    if (!deliveryAddressPreview) return;
    const value = String(fulfillmentAddress?.value || '').trim();
    deliveryAddressPreview.textContent = value || 'No address entered yet.';
  };

  const syncFulfillmentMethod = ({ refreshPickup = false } = {}) => {
    const method = selectedFulfillmentMethod();
    const isDelivery = method === 'delivery';

    if (fulfillmentStepLabel) fulfillmentStepLabel.textContent = isDelivery ? 'Delivery' : 'Pickup';
    if (fulfillmentStageHeading) fulfillmentStageHeading.textContent = isDelivery ? 'Delivery Details' : 'Pickup Schedule';
    if (pickupFulfillmentPanel) pickupFulfillmentPanel.hidden = isDelivery;
    if (deliveryFulfillmentPanel) deliveryFulfillmentPanel.hidden = !isDelivery;

    if (fulfillmentAddressField) fulfillmentAddressField.hidden = !isDelivery;
    if (fulfillmentAddress) {
      fulfillmentAddress.disabled = !isDelivery;
      fulfillmentAddress.required = isDelivery;
      if (isDelivery && !fulfillmentAddress.value.trim()) {
        fulfillmentAddress.value = fulfillmentAddress.dataset.savedAddress || '';
      }
    }

    [pickupDate, pickupTime].forEach((control) => {
      if (!control) return;
      control.disabled = isDelivery;
      control.required = !isDelivery && control === pickupDate;
      if (isDelivery) control.setCustomValidity('');
    });

    if (isDelivery && pickupDialog?.open) pickupDialog.close();
    syncDeliveryPreview();

    if (typeof checkoutStage !== 'undefined' && checkoutStage === 2 && overlayTitle) {
      overlayTitle.textContent = isDelivery ? 'Confirm delivery details' : 'Choose pickup schedule';
    }
    if (!isDelivery && refreshPickup) refreshPickupAvailability(true);
  };

  fulfillmentInputs.forEach((input) => input.addEventListener('change', () => syncFulfillmentMethod({ refreshPickup: true })));
  fulfillmentAddress?.addEventListener('input', syncDeliveryPreview);

  const localDateKey = (date) => {
    const yyyy = date.getFullYear();
    const mm = String(date.getMonth() + 1).padStart(2, '0');
    const dd = String(date.getDate()).padStart(2, '0');
    return `${yyyy}-${mm}-${dd}`;
  };

  const parseDateKey = (dateKey) => {
    const [year, month, day] = String(dateKey || '').split('-').map(Number);
    if (!year || !month || !day) return null;
    return new Date(year, month - 1, day);
  };

  const formatPickupDate = (dateKey) => {
    const date = parseDateKey(dateKey);
    if (!date) return dateKey || '';
    return date.toLocaleDateString('en-PH', { weekday: 'short', month: 'short', day: 'numeric', year: 'numeric' });
  };

  const formatPickupButtonDate = (dateKey) => {
    const date = parseDateKey(dateKey);
    if (!date) return dateKey || '';
    return date.toLocaleDateString('en-PH', { month: 'short', day: 'numeric' });
  };

  const updatePickupSummary = () => {
    if (!pickupScheduleSummary) return;
    const date = pickupDate?.value;
    let time = 'Time not selected';
    if (pickupTime?.value) {
      time = formatPickupTime(pickupTime.value) || pickupTime.value;
    }
    pickupScheduleSummary.textContent = date ? `${formatPickupDate(date)} · ${time}` : 'No pickup schedule selected.';
    if (pickupScheduleButton) pickupScheduleButton.textContent = date ? 'Change pickup schedule' : 'Choose pickup schedule';
  };

  const formatPickupTime = (value) => {
    const match = value.trim().match(/^(\d{1,2}):([0-5][0-9])\s*(AM|PM)?$/i);
    if (!match) return null;
    let hour = Number(match[1]);
    const period = match[3]?.toUpperCase();
    if (period ? hour < 1 || hour > 12 : hour > 23) return null;
    if (period) hour = hour % 12 + (period === 'PM' ? 12 : 0);
    return `${String(hour % 12 || 12).padStart(2, '0')}:${match[2]} ${hour < 12 ? 'AM' : 'PM'}`;
  };
  const validatePickupTime = () => {
    const value = pickupTime.value.trim();
    const formatted = formatPickupTime(value);
    pickupTime.setCustomValidity(value && !formatted ? 'Enter a valid time, such as 09:31 AM or 14:33.' : '');
    return !value || Boolean(formatted);
  };
  pickupTime?.addEventListener('input', validatePickupTime);
  pickupTime?.addEventListener('change', () => {
    if (validatePickupTime() && pickupTime.value.trim()) pickupTime.value = formatPickupTime(pickupTime.value);
  });

  const openPickupSchedule = () => {
    if (selectedFulfillmentMethod() !== 'pickup') return;
    if (!pickupDialog || pickupDialog.open) return;
    pickupDraftBeforeOpen = { date: pickupDate.value, time: pickupTime.value };
    pickupDialog.showModal();
    refreshPickupAvailability(true);
  };

  pickupScheduleButton?.addEventListener('click', openPickupSchedule);
  [pickupDate, pickupTime].forEach((control) => control?.addEventListener('invalid', (event) => {
    if (!pickupDialog.open) {
      event.preventDefault();
      showCheckoutStage(2, { focus: false });
      openPickupSchedule();
      control.focus();
    }
  }));
  document.getElementById('closePickupSchedule')?.addEventListener('click', () => pickupDialog.close());
  pickupDialog?.addEventListener('keydown', (event) => {
    if (event.key === 'Escape') event.stopPropagation();
    if (event.key === 'Enter' && event.target.tagName === 'INPUT') event.preventDefault();
  });
  pickupDialog?.addEventListener('close', () => {
    if (pickupDraftBeforeOpen) {
      pickupDate.value = pickupDraftBeforeOpen.date;
      pickupTime.value = pickupDraftBeforeOpen.time;
      validatePickupTime();
      pickupDraftBeforeOpen = null;
      updatePickupDateStatus();
      renderPickupCalendar();
    }
    updatePickupSummary();
    pickupScheduleButton?.focus();
  });
  document.getElementById('savePickupSchedule')?.addEventListener('click', () => {
    if (!updatePickupDateStatus()) {
      pickupDateStatus.setAttribute('role', 'alert');
      pickupCalendar.querySelector('button:not(:disabled)')?.focus();
      return;
    }
    validatePickupTime();
    if (!pickupTime.reportValidity()) return;
    if (pickupTime.value.trim()) pickupTime.value = formatPickupTime(pickupTime.value);
    pickupDraftBeforeOpen = null;
    updatePickupSummary();
    pickupDialog.close();
  });
  updatePickupSummary();

  const pickupInfo = (date) => {
    if (!date) return null;
    return pickupCapacity.get(date) || {
      date,
      max_orders: pickupDefaultCapacity,
      current_orders: 0,
      remaining: pickupDefaultCapacity,
      is_closed: false,
      fully_booked: false,
      status: 'available',
      note: '',
    };
  };

  const updatePickupDateStatus = () => {
    if (!pickupDate || !pickupDateStatus) return true;
    const value = pickupDate.value;
    pickupDate.setCustomValidity('');
    if (pickupDateDisplay) {
      pickupDateDisplay.value = value ? formatPickupDate(value) : '';
    }
    if (!value) {
      pickupDate.setCustomValidity('Please choose an available pickup date.');
      pickupDateStatus.textContent = 'Choose a pickup date. Fully booked or closed dates are disabled below.';
      pickupDateStatus.className = '';
      return false;
    }
    if (pickupDate.validity.rangeUnderflow || pickupDate.validity.rangeOverflow || pickupDate.validity.badInput) {
      pickupDate.setCustomValidity('Choose a date within the available calendar range.');
      pickupDateStatus.textContent = 'Choose a date within the available calendar range.';
      pickupDateStatus.className = 'pickup-full';
      return false;
    }
    const info = pickupInfo(value);
    if (info?.fully_booked || info?.is_closed) {
      const reason = info.is_closed ? 'Closed for pickup' : 'Fully booked';
      pickupDate.setCustomValidity(reason);
      pickupDateStatus.textContent = `${formatPickupDate(value)} is ${reason.toLowerCase()}. Please choose another pickup date.`;
      pickupDateStatus.className = 'pickup-full';
      return false;
    }
    const remaining = Number(info?.remaining ?? pickupDefaultCapacity);
    pickupDate.setCustomValidity('');
    pickupDateStatus.textContent = remaining <= 3
      ? `${formatPickupDate(value)} is almost full — ${remaining} slot(s) left.`
      : `${formatPickupDate(value)} has ${remaining} pickup slot(s) available.`;
    pickupDateStatus.className = remaining <= 3 ? 'pickup-warning' : 'pickup-available';
    return true;
  };

  const renderPickupCalendar = () => {
    if (!pickupCalendar) return;
    const selectedDate = pickupDate?.value || '';
    pickupCalendar.innerHTML = '';

    const today = new Date();
    today.setHours(0, 0, 0, 0);

    for (let offset = 0; offset < pickupCalendarDays; offset += 1) {
      const date = new Date(today);
      date.setDate(today.getDate() + offset);
      const dateKey = localDateKey(date);
      const info = pickupInfo(dateKey);
      const remaining = Number(info?.remaining ?? pickupDefaultCapacity);
      const isClosed = Boolean(info?.is_closed);
      const isFull = Boolean(info?.fully_booked);
      const isAlmostFull = !isFull && remaining <= 3;
      const button = document.createElement('button');
      const title = document.createElement('strong');
      const meta = document.createElement('small');

      button.type = 'button';
      button.className = 'pickup-day';
      if (dateKey === selectedDate) button.classList.add('selected');
      if (isFull) button.classList.add('disabled');
      if (isAlmostFull) button.classList.add('almost-full');
      if (!isFull && !isAlmostFull) button.classList.add('available');
      button.dataset.date = dateKey;
      button.disabled = isFull;
      button.setAttribute('aria-pressed', dateKey === selectedDate ? 'true' : 'false');

      title.textContent = formatPickupButtonDate(dateKey);
      meta.textContent = isClosed ? 'Closed' : (isFull ? 'Fully booked' : (isAlmostFull ? `${remaining} left` : `${remaining} slots`));
      if (info?.note) button.title = String(info.note);

      button.append(title, meta);
      button.addEventListener('click', () => {
        if (isFull || !pickupDate) return;
        pickupDate.value = dateKey;
        updatePickupDateStatus();
        renderPickupCalendar();
      });
      pickupCalendar.appendChild(button);
    }
  };

  const setPickupAvailability = (rows, defaultCapacity) => {
    pickupDefaultCapacity = Number(defaultCapacity || pickupDefaultCapacity) || pickupDefaultCapacity;
    pickupCapacity = new Map((rows || []).map((row) => [String(row.date || ''), row]));
    if (pickupDate) pickupDate.dataset.defaultCapacity = String(pickupDefaultCapacity);
    renderPickupCalendar();
    updatePickupDateStatus();
  };

  const refreshPickupAvailability = async (silent = false) => {
    if (!pickupCapacityApi) return;
    try {
      if (pickupRefresh) pickupRefresh.disabled = true;
      const response = await fetch(`${pickupCapacityApi}?days=${pickupCalendarDays}&_=${Date.now()}`, {
        headers: { Accept: 'application/json' },
        cache: 'no-store',
      });
      const payload = await response.json();
      if (!response.ok || !payload.ok) {
        throw new Error(payload.error || 'Pickup availability could not be loaded.');
      }
      setPickupAvailability(payload.dates || [], payload.default_capacity);
      if (pickupLastUpdated) {
        const loaded = payload.generated_at ? new Date(payload.generated_at) : new Date();
        pickupLastUpdated.textContent = `Availability updated ${loaded.toLocaleTimeString('en-PH', { hour: '2-digit', minute: '2-digit' })}.`;
      }
    } catch (error) {
      if (!silent && pickupDateStatus) {
        pickupDateStatus.textContent = error.message || 'Pickup availability could not be refreshed.';
        pickupDateStatus.className = 'pickup-full';
      }
    } finally {
      if (pickupRefresh) pickupRefresh.disabled = false;
    }
  };

  pickupDate?.addEventListener('input', () => { updatePickupDateStatus(); renderPickupCalendar(); });
  pickupDate?.addEventListener('change', () => { updatePickupDateStatus(); renderPickupCalendar(); });
  pickupRefresh?.addEventListener('click', () => refreshPickupAvailability(false));
  window.addEventListener('focus', () => refreshPickupAvailability(true));
  document.addEventListener('visibilitychange', () => {
    if (!document.hidden) refreshPickupAvailability(true);
  });
  renderPickupCalendar();
  updatePickupDateStatus();
  refreshPickupAvailability(true);
  setInterval(() => refreshPickupAvailability(true), 30000);

  document.getElementById('openGcashPayment')?.addEventListener('click', () => {
    document.getElementById('gcashDialogAmount').textContent = document.getElementById('checkoutDeposit').textContent;
    gcashDialog.showModal();
  });
  document.getElementById('closeGcashPayment')?.addEventListener('click', () => gcashDialog.close());
  document.getElementById('gcashReturnToCheckout')?.addEventListener('click', () => {
    const depositText = document.getElementById('checkoutDeposit')?.textContent || 'PHP 0.00';
    const numericDeposit = Number(depositText.replace(/[^0-9.]/g, '')) || 0;
    if (paymentExpectedAmount) paymentExpectedAmount.value = numericDeposit.toFixed(2);
    const confirmationAmount = document.getElementById('gcashConfirmedAmount');
    if (confirmationAmount) confirmationAmount.textContent = depositText;
    if (paymentConfirmed) paymentConfirmed.checked = false;
    if (gcashChangeWarning) gcashChangeWarning.hidden = true;
    gcashDialog.close();
    paymentProof?.focus();
  });
  paymentProof?.addEventListener('change', () => {
    const file = paymentProof.files?.[0];
    if (!file || !gcashProofPreview || !gcashProofPreviewImage) {
      if (gcashProofPreview) gcashProofPreview.hidden = true;
      if (gcashAutoReference) gcashAutoReference.hidden = true;
      return;
    }
    if (gcashAutoReference) gcashAutoReference.hidden = false;
    const url = URL.createObjectURL(file);
    gcashProofPreviewImage.onload = () => URL.revokeObjectURL(url);
    gcashProofPreviewImage.src = url;
    gcashProofPreview.hidden = false;
  });
  gcashDialog?.addEventListener('keydown', event => {
    if (event.key === 'Escape') event.stopPropagation();
  });
  let products = window.productCatalog || {};
  let categories = [];
  let activeCategory = 'all';
  let searchQuery = '';

  const cartPayload = document.getElementById('cartPayload');
  const categoryList = document.getElementById('categoryList');
  const productGrid = document.getElementById('productGrid');
  const catalogStatus = document.getElementById('catalogStatus');
  const cartList = document.getElementById('cartList');
  const cartTotal = document.getElementById('cartTotal');
  const cartDeposit = document.getElementById('cartDeposit');
  const cartBalance = document.getElementById('cartBalance');
  const cartPillCount = document.getElementById('cartPillCount');
  const bottomCheckoutBar = document.getElementById('bottomCheckoutBar');
  const bottomCartCount = document.getElementById('bottomCartCount');
  const bottomCartTotal = document.getElementById('bottomCartTotal');
  const overlay = document.getElementById('orderOverlay');
  const checkoutPanel = document.getElementById('checkoutPanel');
  const overlayTitle = document.getElementById('overlayTitle');
  const activeCategoryLabel = document.getElementById('activeCategoryLabel');
  const visibleProductCount = document.getElementById('visibleProductCount');
  const emptyProducts = document.getElementById('emptyProducts');
  const cartNoteFields = document.getElementById('cartNoteFields');
  const catalogSearchToggle = document.getElementById('catalogSearchToggle');
  const catalogSearchPanel = document.getElementById('catalogSearchPanel');
  const catalogSearch = document.getElementById('catalogSearch');
  const catalogSearchClear = document.getElementById('catalogSearchClear');
  const catalogSearchSuggestions = document.getElementById('catalogSearchSuggestions');
  const proceedCheckoutButton = document.querySelector('[data-proceed-checkout]');
  const checkoutStagePanels = Array.from(document.querySelectorAll('[data-checkout-stage]'));
  const checkoutStepperItems = Array.from(document.querySelectorAll('#checkoutStepper > span'));
  let checkoutStage = 1;
  const cart = new Map();
  const noteCache = new Map();
  const expandedDescriptions = new Set();
  const cartStorageKey = window.customerId ? `brille_customer_cart_${window.customerId}` : '';
  let cartStorageHydrated = false;

  const hydrateCartFromStorage = () => {
    if (!cartStorageKey || cartStorageHydrated) return;
    cartStorageHydrated = true;
    try {
      const stored = JSON.parse(localStorage.getItem(cartStorageKey) || '{}');
      if (!stored || Array.isArray(stored) || typeof stored !== 'object') return;
      Object.entries(stored).forEach(([id, qty]) => {
        if (!products[id]) return;
        const availability = productAvailability(products[id]);
        if (!availability.available) return;
        const max = Math.max(0, Math.floor(availability.max || 999));
        const next = Math.max(0, Math.min(max, Math.floor(Number(qty) || 0)));
        if (next > 0) cart.set(String(id), next);
      });
    } catch (_) {
      // A malformed browser cart should never block checkout.
    }
  };

  const persistCartToStorage = () => {
    if (!cartStorageKey || !cartStorageHydrated) return;
    try {
      const stored = {};
      cart.forEach((qty, id) => {
        if (Number(qty) > 0) stored[String(id)] = Math.floor(Number(qty));
      });
      localStorage.setItem(cartStorageKey, JSON.stringify(stored));
      window.dispatchEvent(new CustomEvent('brille-commerce-change'));
    } catch (_) {
      // Checkout remains usable even if browser storage is unavailable.
    }
  };

  const money = (amount) => `PHP ${Number(amount || 0).toLocaleString('en-PH', {
    minimumFractionDigits: 2,
    maximumFractionDigits: 2,
  })}`;

  const escapeHtml = (value) => String(value ?? '').replace(/[&<>"']/g, (char) => ({
    '&': '&amp;',
    '<': '&lt;',
    '>': '&gt;',
    '"': '&quot;',
    "'": '&#039;',
  }[char]));

  const productArray = () => Object.values(products);

  const normalizeSearch = (value) => String(value || '').toLowerCase().trim();

  const searchTerms = (value = searchQuery) => normalizeSearch(value).split(/\s+/).filter(Boolean);

  const productSearchText = (product, availability) => [
    product.name,
    product.description,
    product.category_name,
    product.unit,
    product.inventory_unit,
    product.unit_price,
    availability.label,
  ].map((value) => String(value || '').toLowerCase()).join(' ');

  const productMatchesSearch = (product, availability, query = searchQuery) => {
    const terms = searchTerms(query);
    if (!terms.length) return true;

    const haystack = productSearchText(product, availability);

    return terms.every((term) => haystack.includes(term));
  };

  const productSearchScore = (product, availability) => {
    if (!searchQuery) return 0;

    const terms = searchTerms();
    const name = String(product.name || '').toLowerCase();
    const category = String(product.category_name || '').toLowerCase();
    const description = String(product.description || '').toLowerCase();
    const haystack = productSearchText(product, availability);

    return terms.reduce((score, term) => {
      if (name.startsWith(term)) return score + 12;
      if (name.includes(term)) return score + 8;
      if (category.includes(term)) return score + 5;
      if (description.includes(term)) return score + 3;
      return haystack.includes(term) ? score + 1 : score;
    }, 0);
  };

  const productAvailability = (product) => {
    if (!product) return { available: false, label: 'Unavailable', className: 'unavailable', max: 0 };
    if (Number(product.unit_price || 0) <= 0) return { available: false, label: 'Price on request', className: 'unavailable', max: 0 };
    if (typeof product.available === 'boolean') {
      const max = Math.max(0, Math.min(999, Math.floor(Number(product.max_order_qty) || 0)));
      const available = product.available && max > 0;
      return { available, label: available ? 'Made to order' : 'Unavailable', className: available ? 'available' : 'unavailable', max: available ? max : 0 };
    }
    return { available: false, label: 'Checking availability', className: 'unavailable', max: 0 };
  };

  const showCheckoutStage = (stage, { focus = true } = {}) => {
    const nextStage = Math.max(1, Math.min(3, Number(stage) || 1));
    checkoutStage = nextStage;

    checkoutStagePanels.forEach((panel) => {
      panel.hidden = Number(panel.dataset.checkoutStage || 0) !== nextStage;
    });

    checkoutStepperItems.forEach((item, index) => {
      const step = index + 1;
      item.classList.toggle('is-active', step === nextStage);
      item.classList.toggle('is-complete', step < nextStage);
      item.setAttribute('aria-current', step === nextStage ? 'step' : 'false');
    });

    overlayTitle.textContent = nextStage === 1
      ? 'Fill checkout details'
      : nextStage === 2
        ? (selectedFulfillmentMethod() === 'delivery' ? 'Confirm delivery details' : 'Choose pickup schedule')
        : 'Complete payment';

    if (nextStage === 2 && selectedFulfillmentMethod() === 'pickup') refreshPickupAvailability(true);

    if (focus) {
      const activePanel = checkoutStagePanels.find((panel) => Number(panel.dataset.checkoutStage || 0) === nextStage);
      activePanel?.scrollIntoView({ behavior: 'smooth', block: 'start' });
      window.setTimeout(() => {
        const target = activePanel?.querySelector('input:not([type="hidden"]), select, textarea, button');
        target?.focus({ preventScroll: true });
      }, 180);
    }
  };

  const validateCheckoutPanel = (stage) => {
    const panel = checkoutStagePanels.find((item) => Number(item.dataset.checkoutStage || 0) === Number(stage));
    if (!panel) return true;

    const controls = Array.from(panel.querySelectorAll('input, select, textarea'));
    for (const control of controls) {
      if (control.disabled || control.type === 'hidden' || !control.willValidate) continue;
      if (!control.checkValidity()) {
        control.reportValidity();
        control.focus();
        return false;
      }
    }
    return true;
  };

  const showCartStep = () => {
    checkoutPanel.hidden = true;
    overlay.classList.remove('checkout-active');
    showCheckoutStage(1, { focus: false });
    overlayTitle.textContent = 'Review your cart';
  };

  const showCheckoutStep = () => {
    checkoutPanel.hidden = false;
    overlay.classList.add('checkout-active');
    showCheckoutStage(1, { focus: false });
    checkoutPanel.scrollIntoView({ behavior: 'smooth', block: 'start' });
  };

  const openOverlay = () => {
    showCartStep();
    overlay.hidden = false;
    document.body.classList.add('overlay-open');
  };

  const closeOverlay = () => {
    overlay.hidden = true;
    document.body.classList.remove('overlay-open');
  };

  const syncNoteCache = () => {
    document.querySelectorAll('[data-item-note]').forEach((field) => {
      noteCache.set(field.dataset.itemNote, field.value);
    });
  };

  // The cart and the menu each carry a note field for the same item, so keep both
  // and the submitted hidden input in step with whichever one is being typed into.
  const updateNote = (productId, value, originScope) => {
    const id = String(productId);
    noteCache.set(id, value);

    document.querySelectorAll(`[data-item-note="${id}"]`).forEach((field) => {
      if (field.dataset.noteScope !== originScope && field.value !== value) {
        field.value = value;
      }
    });

    const hiddenField = cartNoteFields?.querySelector(`[data-note-field="${id}"]`);
    if (hiddenField) {
      hiddenField.value = value;
    }
  };

  // The catalog poll re-renders the cart every few seconds; without this a customer
  // typing item instructions would lose the caret mid-word.
  const captureNoteFocus = () => {
    const active = document.activeElement;
    if (!active || typeof active.matches !== 'function' || !active.matches('[data-item-note]')) {
      return null;
    }

    return {
      id: active.dataset.itemNote,
      scope: active.dataset.noteScope || '',
      start: active.selectionStart,
      end: active.selectionEnd,
    };
  };

  const restoreNoteFocus = (snapshot) => {
    if (!snapshot) return;

    const field = document.querySelector(`[data-item-note="${snapshot.id}"][data-note-scope="${snapshot.scope}"]`);
    if (!field) return;

    field.focus({ preventScroll: true });
    if (snapshot.start !== null && snapshot.start !== undefined) {
      field.setSelectionRange(snapshot.start, snapshot.end);
    }
  };

  const setQuantity = (productId, quantity) => {
    const id = String(productId);
    const availability = productAvailability(products[id]);
    const max = availability.available ? Math.max(0, Math.floor(availability.max || 999)) : 0;
    const nextQuantity = Math.max(0, Math.min(max, Number(quantity) || 0));

    if (nextQuantity === 0) {
      cart.delete(id);
    } else {
      cart.set(id, nextQuantity);
    }

    renderCart();
    renderProducts();
  };

  const cartSummary = () => {
    let count = 0;
    let total = 0;
    let deposit = 0;

    cart.forEach((qty, id) => {
      const product = products[id];
      if (!product) return;
      count += qty;
      total += qty * Number(product.unit_price || 0);
      deposit += Math.round(Math.round(qty * Number(product.unit_price || 0) * 100) / 4) / 100;
    });

    deposit = Math.round(deposit * 100) / 100;
    const balance = Math.max(0, total - deposit);

    return { count, total, deposit, balance };
  };

  function renderCategories() {
    const buttons = [
      '<span>Categories</span>',
      `<button type="button" class="${activeCategory === 'all' ? 'active' : ''}" data-category="all">All Products</button>`,
      ...categories.map((category) => `
        <button type="button" class="${String(category.id) === activeCategory ? 'active' : ''}" data-category="${category.id}">
          ${escapeHtml(category.name)}
        </button>
      `),
    ];

    categoryList.innerHTML = buttons.join('');
  }

  function matchingSearchProducts() {
    return productArray()
      .filter((product) => {
        const availability = productAvailability(product);
        return availability.available && productMatchesSearch(product, availability);
      })
      .sort((a, b) => {
        if (!searchQuery) return 0;
        const scoreB = productSearchScore(b, productAvailability(b));
        const scoreA = productSearchScore(a, productAvailability(a));
        return scoreB - scoreA || String(a.name || '').localeCompare(String(b.name || ''));
      });
  }

  function renderSearchSuggestions(matches) {
    if (!catalogSearchSuggestions) return;

    catalogSearchSuggestions.textContent = '';
    if (!searchQuery || !matches.length) {
      catalogSearchSuggestions.hidden = true;
      return;
    }

    matches.slice(0, 5).forEach((product) => {
      const button = document.createElement('button');
      button.type = 'button';
      button.dataset.searchProduct = String(product.id);
      button.innerHTML = `
        <strong>${escapeHtml(product.name)}</strong>
        <span>${escapeHtml(product.category_name || 'Custom Product')} - ${money(product.unit_price)}</span>
      `;
      catalogSearchSuggestions.appendChild(button);
    });

    catalogSearchSuggestions.hidden = false;
  }

  function syncSearchControls() {
    if (catalogSearchClear) {
      catalogSearchClear.hidden = !searchQuery;
    }

    if (catalogSearchToggle) {
      catalogSearchToggle.classList.toggle('active', !!searchQuery);
    }
  }

  function scrollToProduct(productId) {
    const card = productGrid.querySelector(`[data-product-id="${productId}"]`);
    if (!card) return;

    card.scrollIntoView({ behavior: 'smooth', block: 'center' });
    card.classList.add('is-search-target');
    window.setTimeout(() => card.classList.remove('is-search-target'), 1200);
  }

  function openSearchPanel() {
    if (!catalogSearchPanel || !catalogSearchToggle) return;

    catalogSearchPanel.hidden = false;
    catalogSearchToggle.setAttribute('aria-expanded', 'true');
    catalogSearch.focus();
  }

  function closeSearchPanel() {
    if (!catalogSearchPanel || !catalogSearchToggle) return;

    catalogSearchPanel.hidden = true;
    catalogSearchToggle.setAttribute('aria-expanded', 'false');
  }

  function toggleSearchPanel() {
    if (!catalogSearchPanel || catalogSearchPanel.hidden) {
      openSearchPanel();
      return;
    }

    closeSearchPanel();
  }

  function updateDescriptionToggles() {
    productGrid.querySelectorAll('[data-description-toggle]').forEach((button) => {
      const description = document.getElementById(button.getAttribute('aria-controls'));
      const expanded = expandedDescriptions.has(button.dataset.descriptionToggle);
      description.classList.remove('is-expanded');
      const overflowing = description.scrollHeight > description.clientHeight + 1;
      description.classList.toggle('is-expanded', expanded);
      button.hidden = !overflowing && !expanded;
      button.setAttribute('aria-expanded', String(expanded));
      button.textContent = expanded ? 'See less' : 'See more';
    });
  }

  window.addEventListener('resize', updateDescriptionToggles);
  document.fonts?.ready.then(updateDescriptionToggles);

  function renderProducts() {
    const focusSnapshot = captureNoteFocus();
    syncNoteCache();

    const searchMatches = matchingSearchProducts();
    const sourceProducts = searchQuery ? searchMatches : productArray();
    const visibleProducts = sourceProducts.filter((product) =>
      activeCategory === 'all' || String(product.category_id) === activeCategory
    );

    productGrid.innerHTML = visibleProducts.map((product) => {
      const id = String(product.id);
      const qty = cart.get(id) || 0;
      const availability = productAvailability(product);
      const max = Math.floor(availability.max || 0);
      const initial = escapeHtml((product.name || '?').slice(0, 1).toUpperCase());
      const noteValue = escapeHtml(noteCache.get(id) || '');
      const imageIndex = product.sample_image_index;
      const hasImage = Number.isInteger(imageIndex) && imageIndex >= 0 && imageIndex < 19;
      const preview = String(product.image_url || '');
      const artwork = /^assets\/img\/products\/[a-z-]+\.png$/.test(preview)
        ? `<div class="product-sample"><img class="catalog-product-preview" src="../${preview}" alt="${escapeHtml(product.name)} product sample" loading="lazy"><small>Sample design</small></div>`
        : hasImage
        ? `<div class="product-sample"><div class="product-sample-image" role="img" aria-label="${escapeHtml(product.name)} sample design" style="background-position: ${(imageIndex % 5) * 25}% ${Math.floor(imageIndex / 5) * 100 / 3}%"></div><small>Sample design</small></div>`
        : `<div class="product-art" aria-hidden="true">${initial}</div>`;

      return `
        <article class="product-card" data-product-card data-product-id="${id}" data-category-id="${product.category_id}">
          ${artwork}
          <div class="product-copy">
            <span>${escapeHtml(product.category_name || 'Custom Product')}</span>
            <h2>${escapeHtml(product.name)}</h2>
            <p id="product-description-${id}" class="${expandedDescriptions.has(id) ? 'is-expanded' : ''}">${escapeHtml(product.description || 'Customized to your preferred design, color, text, and occasion.')}</p>
            <button type="button" class="description-toggle" data-description-toggle="${id}" aria-controls="product-description-${id}" aria-expanded="${expandedDescriptions.has(id)}" hidden>See more</button>
            <div class="product-meta">
              <strong>${Number(product.unit_price || 0) > 0 ? money(product.unit_price) : 'Price on request'}</strong>
              <em class="stock-badge ${availability.className}">${escapeHtml(availability.label)}</em>
            </div>
          </div>
          <div class="product-actions">
            <button type="button" class="qty-btn" data-decrease="${id}" ${qty <= 0 ? 'disabled' : ''}>-</button>
            <input class="qty-input" id="qty-${id}" type="number" min="0" max="${max}" step="1" inputmode="numeric" value="${qty}" data-quantity-input="${id}" ${max < 1 ? 'disabled' : ''} aria-label="Quantity for ${escapeHtml(product.name)}">
            <button type="button" class="qty-btn" data-increase="${id}" ${qty >= max ? 'disabled' : ''}>+</button>
          </div>
          <label class="item-note" ${qty > 0 ? '' : 'hidden'}>
            <span>Item instructions</span>
            <textarea data-item-note="${id}" data-note-scope="grid" rows="2" placeholder="Text, size, color, theme, or design request">${noteValue}</textarea>
          </label>
        </article>
      `;
    }).join('');

    updateDescriptionToggles();
    activeCategoryLabel.textContent = activeCategory === 'all'
      ? 'All Products'
      : categories.find((category) => String(category.id) === activeCategory)?.name || 'Selected Category';
    visibleProductCount.textContent = searchQuery
      ? `${visibleProducts.length} result${visibleProducts.length === 1 ? '' : 's'}`
      : `${visibleProducts.length} product${visibleProducts.length === 1 ? '' : 's'}`;
    emptyProducts.hidden = visibleProducts.length !== 0;
    renderSearchSuggestions(searchMatches);
    syncSearchControls();
    restoreNoteFocus(focusSnapshot);
  }

  // Notes travel in hidden inputs so they survive a category filter that removes the
  // matching product card from the menu.
  function renderNoteFields() {
    if (!cartNoteFields) return;

    cartNoteFields.innerHTML = '';
    Array.from(cart.keys())
      .filter((id) => products[id])
      .forEach((id) => {
        const field = document.createElement('input');
        field.type = 'hidden';
        field.name = `description_${id}`;
        field.dataset.noteField = id;
        field.value = noteCache.get(id) || '';
        cartNoteFields.appendChild(field);
      });
  }

  function renderCart() {
    const focusSnapshot = captureNoteFocus();
    const items = Array.from(cart.entries()).filter(([id]) => products[id]);
    cartPayload.value = JSON.stringify(items.map(([id, qty]) => ({ id: Number(id), qty })));
    persistCartToStorage();

    if (items.length === 0) {
      cartList.innerHTML = '<p class="empty-cart">Add products from the menu to begin.</p>';
    } else {
      cartList.innerHTML = items.map(([id, qty]) => {
        const product = products[id];
        const availability = productAvailability(product);
        const max = Math.floor(availability.max || 0);
        const noteValue = escapeHtml(noteCache.get(id) || '');

        return `
          <article class="cart-item" data-cart-item="${id}">
            <div class="cart-item-head">
              <div class="cart-item-info">
                <strong>${escapeHtml(product.name)}</strong>
                <span>${money(product.unit_price)} each</span>
                <small>${escapeHtml(availability.label)}</small>
              </div>
              <div class="cart-item-side">
                <b>${money(qty * Number(product.unit_price || 0))}</b>
                <button type="button" class="cart-remove" data-remove-item="${id}">Remove</button>
              </div>
            </div>
            <div class="cart-item-controls">
              <button type="button" class="qty-btn" data-cart-decrease="${id}" aria-label="Decrease quantity">-</button>
              <input class="qty-input" type="number" min="0" max="${max}" step="1" inputmode="numeric" value="${qty}" data-cart-quantity-input="${id}" aria-label="Quantity for ${escapeHtml(product.name)}">
              <button type="button" class="qty-btn" data-cart-increase="${id}" aria-label="Increase quantity" ${qty >= max ? 'disabled' : ''}>+</button>
            </div>
            <label class="item-note cart-item-note">
              <span>Item instructions</span>
              <textarea data-item-note="${id}" data-note-scope="cart" rows="2" placeholder="Text, size, color, theme, or design request">${noteValue}</textarea>
            </label>
          </article>
        `;
      }).join('');
    }

    renderNoteFields();

    const summary = cartSummary();
    cartTotal.textContent = money(summary.total);
    cartDeposit.textContent = money(summary.deposit);
    cartBalance.textContent = money(summary.balance);
    if (gcashDialog?.open) document.getElementById('gcashDialogAmount').textContent = money(summary.deposit);
    if (paymentExpectedAmount && Number(paymentExpectedAmount.value || 0) > 0) {
      const paidForAmount = Number(paymentExpectedAmount.value || 0);
      const changedAfterPayment = Math.abs(paidForAmount - Number(summary.deposit || 0)) > 0.009;
      if (changedAfterPayment) {
        if (paymentConfirmed) paymentConfirmed.checked = false;
        if (gcashChangeWarning) gcashChangeWarning.hidden = false;
      } else if (gcashChangeWarning) {
        gcashChangeWarning.hidden = true;
      }
    }
    ['Total', 'Deposit', 'Balance'].forEach(key => {
      const output = document.getElementById('checkout' + key);
      if (output) output.textContent = money(summary[key.toLowerCase()]);
    });
    cartPillCount.textContent = String(summary.count);
    cartPillCount.hidden = summary.count === 0;
    cartPillCount.closest('[data-open-cart]').setAttribute('aria-label', `Open cart, ${summary.count} item${summary.count === 1 ? '' : 's'}`);
    bottomCartCount.textContent = `${summary.count} item${summary.count === 1 ? '' : 's'}`;
    bottomCartTotal.textContent = money(summary.total);
    bottomCheckoutBar.hidden = summary.count === 0;
    document.body.classList.toggle('cart-bar-visible', summary.count > 0);

    if (proceedCheckoutButton) {
      proceedCheckoutButton.disabled = summary.count === 0;
    }

    // Emptying the cart while the form is open sends the customer back to the cart step.
    if (summary.count === 0 && !checkoutPanel.hidden) {
      showCartStep();
    }

    restoreNoteFocus(focusSnapshot);
  }

  function reconcileCartWithInventory() {
    Array.from(cart.entries()).forEach(([id, qty]) => {
      const availability = productAvailability(products[id]);
      if (!availability.available) {
        cart.delete(id);
        return;
      }

      if (availability.max < qty) {
        cart.set(id, Math.max(1, Math.floor(availability.max)));
      }
    });
  }

  let catalogSnapshot = '';
  let catalogRefreshing = false;
  let initialStoredCartLaunchDone = false;
  const shouldOpenStoredCheckout = new URLSearchParams(window.location.search).get('from') === 'cart';
  async function refreshCatalog() {
    if (catalogRefreshing || !productGrid.isConnected) return;
    catalogRefreshing = true;
    try {
      const response = await fetch(window.customerCatalogApi || 'catalog_api.php', {
        cache: 'no-store',
        headers: { 'Accept': 'application/json' },
      });
      const data = await response.json();

      if (!data.ok) {
        throw new Error('Catalog request failed.');
      }

      const nextSnapshot = JSON.stringify([data.categories || [], data.products || []]);
      catalogStatus.textContent = 'Catalog is live';
      catalogStatus.classList.remove('error');
      // A heartbeat must not rebuild controls or disturb typing and focus.
      if (nextSnapshot === catalogSnapshot) return;

      categories = data.categories || [];
      products = {};
      (data.products || []).forEach((product) => {
        if (product.available) {
          products[String(product.id)] = product;
        }
      });
      window.productCatalog = products;

      hydrateCartFromStorage();
      reconcileCartWithInventory();
      renderCategories();
      renderCart();
      renderProducts();
      if (!initialStoredCartLaunchDone && shouldOpenStoredCheckout && cart.size > 0) {
        initialStoredCartLaunchDone = true;
        openOverlay();
        showCheckoutStep();
      }
      catalogSnapshot = nextSnapshot;
      catalogStatus.textContent = 'Catalog is live';
      catalogStatus.classList.remove('error');
    } catch (error) {
      catalogStatus.textContent = 'Live catalog unavailable. Showing last loaded products.';
      catalogStatus.classList.add('error');
    } finally {
      catalogRefreshing = false;
    }
  }

  categoryList.addEventListener('click', (event) => {
    const button = event.target.closest('[data-category]');
    if (!button) return;

    activeCategory = button.dataset.category;
    renderCategories();
    renderProducts();
  });

  catalogSearch?.addEventListener('input', () => {
    searchQuery = normalizeSearch(catalogSearch.value);
    renderProducts();
  });

  catalogSearchToggle?.addEventListener('click', toggleSearchPanel);

  catalogSearchClear?.addEventListener('click', () => {
    searchQuery = '';
    catalogSearch.value = '';
    renderProducts();
    catalogSearch.focus();
  });

  catalogSearchSuggestions?.addEventListener('click', (event) => {
    const button = event.target.closest('[data-search-product]');
    if (!button) return;

    activeCategory = 'all';
    renderCategories();
    renderProducts();
    closeSearchPanel();
    window.setTimeout(() => scrollToProduct(button.dataset.searchProduct), 0);
  });

  productGrid.addEventListener('input', (event) => {
    if (event.target.matches('[data-item-note]')) {
      updateNote(event.target.dataset.itemNote, event.target.value, 'grid');
      return;
    }

    if (event.target.matches('[data-quantity-input]')) {
      const id = String(event.target.dataset.quantityInput);
      const availability = productAvailability(products[id]);
      const max = availability.available ? Math.max(0, Math.floor(availability.max || 999)) : 0;
      if (event.target.value === '') return;

      const typed = Math.max(0, Math.min(max, Math.floor(Number(event.target.value) || 0)));
      if (typed === 0) cart.delete(id);
      else cart.set(id, typed);
    }
  });

  productGrid.addEventListener('change', (event) => {
    if (!event.target.matches('[data-quantity-input]')) return;
    const id = String(event.target.dataset.quantityInput);
    setQuantity(id, event.target.value === '' ? 0 : event.target.value);
  });

  productGrid.addEventListener('click', (event) => {
    const descriptionToggle = event.target.closest('[data-description-toggle]');
    if (descriptionToggle) {
      const id = descriptionToggle.dataset.descriptionToggle;
      if (expandedDescriptions.has(id)) {
        expandedDescriptions.delete(id);
      } else {
        expandedDescriptions.add(id);
      }
      updateDescriptionToggles();
      return;
    }
    const increase = event.target.closest('[data-increase]');
    const decrease = event.target.closest('[data-decrease]');

    if (increase) {
      const id = String(increase.dataset.increase);
      setQuantity(id, (cart.get(id) || 0) + 1);
    }

    if (decrease) {
      const id = String(decrease.dataset.decrease);
      setQuantity(id, (cart.get(id) || 0) - 1);
    }
  });

  cartList.addEventListener('input', (event) => {
    if (event.target.matches('[data-item-note]')) {
      updateNote(event.target.dataset.itemNote, event.target.value, 'cart');
      return;
    }

    if (event.target.matches('[data-cart-quantity-input]')) {
      const id = String(event.target.dataset.cartQuantityInput);
      const availability = productAvailability(products[id]);
      const max = availability.available ? Math.max(0, Math.floor(availability.max || 999)) : 0;
      if (event.target.value === '') return;

      const typed = Math.max(0, Math.min(max, Math.floor(Number(event.target.value) || 0)));
      if (typed === 0) cart.delete(id);
      else cart.set(id, typed);
    }
  });

  cartList.addEventListener('change', (event) => {
    if (!event.target.matches('[data-cart-quantity-input]')) return;
    const id = String(event.target.dataset.cartQuantityInput);
    setQuantity(id, event.target.value === '' ? 0 : event.target.value);
  });

  cartList.addEventListener('click', (event) => {
    const increase = event.target.closest('[data-cart-increase]');
    const decrease = event.target.closest('[data-cart-decrease]');
    const remove = event.target.closest('[data-remove-item]');

    if (increase) {
      const id = String(increase.dataset.cartIncrease);
      setQuantity(id, (cart.get(id) || 0) + 1);
    }

    if (decrease) {
      const id = String(decrease.dataset.cartDecrease);
      setQuantity(id, (cart.get(id) || 0) - 1);
    }

    if (remove) {
      setQuantity(String(remove.dataset.removeItem), 0);
    }
  });

  document.querySelector('[data-clear-cart]')?.addEventListener('click', () => {
    if (cart.size === 0) return;
    if (!confirm('Remove every item from your cart?')) return;

    cart.clear();
    renderCart();
    renderProducts();
  });

  document.querySelector('[data-open-cart]')?.addEventListener('click', openOverlay);
  // Only the X button dismisses the order/checkout overlay, not its backdrop.
  document.querySelectorAll('button[data-close-overlay]').forEach((button) => {
    button.addEventListener('click', closeOverlay);
  });

  document.querySelector('[data-proceed-checkout]')?.addEventListener('click', () => {
    if (cart.size === 0) {
      alert('Please add at least one product to your cart.');
      return;
    }

    showCheckoutStep();
  });

  document.querySelector('[data-bottom-checkout]')?.addEventListener('click', () => {
    if (cart.size === 0) {
      alert('Please add at least one product to your cart.');
      return;
    }

    // Land on the editable cart first — the checkout form is one step further in.
    openOverlay();
    cartList.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
  });

  document.querySelector('[data-back-cart]')?.addEventListener('click', showCartStep);

  document.querySelectorAll('[data-checkout-next]').forEach((button) => {
    button.addEventListener('click', () => {
      const targetStage = Number(button.dataset.checkoutNext || 1);

      if (checkoutStage === 1 && !validateCheckoutPanel(1)) return;

      if (checkoutStage === 2) {
        if (selectedFulfillmentMethod() === 'pickup' && !updatePickupDateStatus()) {
          alert('Please choose an available pickup date before continuing.');
          openPickupSchedule();
          return;
        }
        if (!validateCheckoutPanel(2)) return;
      }

      showCheckoutStage(targetStage);
    });
  });

  document.querySelectorAll('[data-checkout-back]').forEach((button) => {
    button.addEventListener('click', () => {
      showCheckoutStage(Number(button.dataset.checkoutBack || 1));
    });
  });

  syncFulfillmentMethod();
  showCheckoutStage(1, { focus: false });

  document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape' && catalogSearchPanel && !catalogSearchPanel.hidden) {
      closeSearchPanel();
      catalogSearchToggle?.focus();
      return;
    }

    // Escape is intentionally not a checkout close control; the customer uses X.
    if (event.key === 'Escape' && !overlay.hidden) {
      event.preventDefault();
    }
  });

  document.addEventListener('click', (event) => {
    if (!catalogSearchPanel || catalogSearchPanel.hidden) return;
    if (catalogSearchPanel.contains(event.target) || catalogSearchToggle?.contains(event.target)) return;

    closeSearchPanel();
  });

  form.addEventListener('submit', (event) => {
    if (cart.size === 0) {
      event.preventDefault();
      alert('Please add at least one product to your cart.');
      return;
    }

    if (selectedFulfillmentMethod() === 'pickup' && !updatePickupDateStatus()) {
      event.preventDefault();
      showCheckoutStage(2, { focus: false });
      alert('That pickup date is fully booked or closed. Please choose another date.');
      openPickupSchedule();
      return;
    }

    // Manual payment must be locked to the amount the customer actually
    // saw before the order is allowed to reach the server. The server repeats
    // this validation, so a disabled or modified browser cannot bypass it.
    if (paymentExpectedAmount) {
      const summary = cartSummary();
      const expected = Number(paymentExpectedAmount.value || 0);
      if (expected <= 0) {
        event.preventDefault();
        alert('Open the payment instructions first, complete the payment, then upload the successful receipt.');
        document.getElementById('openGcashPayment')?.focus();
        return;
      }
      if (Math.abs(expected - Number(summary.deposit || 0)) > 0.009) {
        event.preventDefault();
        if (paymentConfirmed) paymentConfirmed.checked = false;
        if (gcashChangeWarning) gcashChangeWarning.hidden = false;
        alert('The order amount changed after the payment step. Reopen the payment instructions and use the updated downpayment amount.');
        return;
      }
    }

    syncNoteCache();
    renderNoteFields();
  });

  refreshCatalog();
  setInterval(refreshCatalog, 5000);
}
