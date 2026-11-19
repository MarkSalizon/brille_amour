(function () {
  const root = document.querySelector('[data-brille-bot]');
  if (!root) return;

  const launcher = document.getElementById('baBotLauncher');
  const panel = document.getElementById('baBotPanel');
  const closeBtn = document.getElementById('baBotClose');
  const log = document.getElementById('baBotLog');
  const form = document.getElementById('baBotComposer');
  const input = document.getElementById('baBotInput');
  const smartForm = document.getElementById('baBotSmartForm');
  const liveStatus = document.getElementById('baBotLiveStatus');
  const api = root.dataset.api || 'chatbot_api.php';
  let activeMode = '';
  let liveTimer = null;
  let lastRealtimePayload = null;
  let lastRealtimeBotMessage = null;

  function scrollBottom() {
    log.scrollTop = log.scrollHeight;
  }

  function addMessage(role, text) {
    const item = document.createElement('article');
    item.className = 'ba-bot-message ' + role;
    item.textContent = text;
    log.appendChild(item);
    scrollBottom();
    return item;
  }

  function setLiveStatus(text) {
    if (!liveStatus) return;
    liveStatus.textContent = text || 'Live admin data';
  }

  function isBotOpen() {
    return root.classList.contains('is-open') && !panel.hidden;
  }

  function syncClosedState() {
    root.classList.remove('is-open');
    panel.hidden = true;
    panel.classList.add('is-hidden');
    panel.setAttribute('aria-hidden', 'true');
    launcher.setAttribute('aria-expanded', 'false');
    stopLiveRefresh();
  }

  function openBot() {
    panel.hidden = false;
    panel.classList.remove('is-hidden');
    panel.setAttribute('aria-hidden', 'false');
    root.classList.add('is-open');
    launcher.setAttribute('aria-expanded', 'true');
    startLiveRefresh();
    window.setTimeout(() => input.focus(), 50);
  }

  function closeBot(options = {}) {
    syncClosedState();
    if (options.returnFocus !== false) launcher.focus();
  }

  function postBot(params) {
    const body = new URLSearchParams(Object.assign({ _: Date.now() }, params || {}));
    return fetch(api, {
      method: 'POST',
      credentials: 'same-origin',
      cache: 'no-store',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: body.toString()
    })
      .then(response => response.json().catch(() => ({ ok: false, reply: 'Invalid bot response. Please try again.' })))
      .catch(() => ({ ok: false, reply: 'Network error. Please try again.' }));
  }

  function realtimeEligible(payload) {
    const mode = String(payload?.mode || '');
    if (mode === 'pickup') return Boolean(payload.date);
    if (mode === 'order_status') return Boolean((payload.order_number || payload.customer_name) && payload.contact);
    return false;
  }

  function markRealtime(payload, botMessage) {
    if (!realtimeEligible(payload)) {
      lastRealtimePayload = null;
      lastRealtimeBotMessage = null;
      setLiveStatus('Live admin data');
      return;
    }
    lastRealtimePayload = Object.assign({}, payload);
    lastRealtimeBotMessage = botMessage;
    setLiveStatus('Live from admin');
  }

  function askBot(message, extra) {
    const clean = String(message || '').trim();
    if (clean) addMessage('user', clean);
    addMessage('bot loading', 'Checking live admin data...');
    const loading = log.querySelector('.ba-bot-message.loading:last-child');
    const payload = Object.assign({ message: clean, mode: activeMode }, extra || {});

    return postBot(payload).then(data => {
      if (loading) loading.remove();
      const botMessage = addMessage('bot', data.reply || 'I could not answer that. Please message staff.');
      markRealtime(payload, botMessage);
    });
  }

  function buildCurrentRealtimePayload() {
    if (activeMode === 'pickup') {
      const date = document.getElementById('baBotPickupDate')?.value || lastRealtimePayload?.date || '';
      if (!date) return null;
      return { message: 'Check pickup availability', mode: 'pickup', date };
    }
    if (activeMode === 'order_status') {
      const trackBy = document.querySelector('input[name="baBotTrackBy"]:checked')?.value || lastRealtimePayload?.track_by || 'order';
      const orderNumber = document.getElementById('baBotOrderNumber')?.value?.trim() || lastRealtimePayload?.order_number || '';
      const customerName = document.getElementById('baBotCustomerName')?.value?.trim() || lastRealtimePayload?.customer_name || '';
      const contact = document.getElementById('baBotContact')?.value?.trim() || lastRealtimePayload?.contact || '';
      if (!contact || (trackBy === 'order' && !orderNumber) || (trackBy === 'name' && !customerName)) return null;
      return {
        message: trackBy === 'name' ? 'Track order for ' + customerName : 'Check order ' + orderNumber,
        mode: 'order_status',
        track_by: trackBy,
        order_number: orderNumber,
        customer_name: customerName,
        contact
      };
    }
    return lastRealtimePayload;
  }

  function refreshLiveAnswer() {
    if (!isBotOpen()) return;
    const payload = buildCurrentRealtimePayload();
    if (!payload || !realtimeEligible(payload) || !lastRealtimeBotMessage) return;
    setLiveStatus('Updating...');
    postBot(Object.assign({}, payload, { silent: '1' })).then(data => {
      if (data && data.reply) {
        lastRealtimeBotMessage.textContent = data.reply + '\n\nUpdated live from admin: ' + new Date().toLocaleTimeString('en-PH', { hour: '2-digit', minute: '2-digit' });
        lastRealtimePayload = Object.assign({}, payload);
        setLiveStatus('Live from admin');
        scrollBottom();
      } else {
        setLiveStatus('Live admin data');
      }
    }).catch(() => setLiveStatus('Reconnect needed'));
  }

  function startLiveRefresh() {
    if (liveTimer) return;
    liveTimer = window.setInterval(refreshLiveAnswer, 15000);
  }

  function stopLiveRefresh() {
    if (!liveTimer) return;
    window.clearInterval(liveTimer);
    liveTimer = null;
  }

  function setSmartForm(mode) {
    activeMode = mode;
    lastRealtimePayload = null;
    lastRealtimeBotMessage = null;
    setLiveStatus('Live admin data');
    smartForm.textContent = '';
    smartForm.hidden = false;

    if (mode === 'order_status') {
      smartForm.innerHTML = '' +
        '<div class="ba-bot-track-methods">' +
          '<label><input type="radio" name="baBotTrackBy" value="order" checked> <span>Order number</span></label>' +
          '<label><input type="radio" name="baBotTrackBy" value="name"> <span>Name</span></label>' +
        '</div>' +
        '<label class="ba-bot-track-order"><span>Order number</span><input type="text" id="baBotOrderNumber" placeholder="BA-001"></label>' +
        '<label class="ba-bot-track-name" hidden><span>Full name</span><input type="text" id="baBotCustomerName" placeholder="Name used at checkout"></label>' +
        '<label><span>Contact used at checkout</span><input type="text" id="baBotContact" placeholder="Phone number or handle"></label>' +
        '<button type="button" class="ba-bot-smart-submit">Track order</button>';

      const orderWrap = smartForm.querySelector('.ba-bot-track-order');
      const nameWrap = smartForm.querySelector('.ba-bot-track-name');
      smartForm.querySelectorAll('input[name="baBotTrackBy"]').forEach(radio => {
        radio.addEventListener('change', () => {
          const byName = radio.value === 'name' && radio.checked;
          if (orderWrap) orderWrap.hidden = byName;
          if (nameWrap) nameWrap.hidden = !byName;
        });
      });

      smartForm.querySelector('.ba-bot-smart-submit').addEventListener('click', () => {
        const trackBy = smartForm.querySelector('input[name="baBotTrackBy"]:checked')?.value || 'order';
        const orderNumber = document.getElementById('baBotOrderNumber').value.trim();
        const customerName = document.getElementById('baBotCustomerName').value.trim();
        const contact = document.getElementById('baBotContact').value.trim();
        askBot(trackBy === 'name' ? 'Track order for ' + (customerName || '') : 'Check order ' + (orderNumber || ''), {
          mode: 'order_status',
          track_by: trackBy,
          order_number: orderNumber,
          customer_name: customerName,
          contact
        });
      });
      return;
    }

    if (mode === 'pickup') {
      smartForm.innerHTML = '' +
        '<label><span>Pickup date</span><input type="date" id="baBotPickupDate"></label>' +
        '<button type="button" class="ba-bot-smart-submit">Check date</button>';
      smartForm.querySelector('.ba-bot-smart-submit').addEventListener('click', () => {
        const date = document.getElementById('baBotPickupDate').value;
        askBot('Check pickup availability', { mode: 'pickup', date });
      });
      return;
    }

    smartForm.hidden = true;
    if (mode === 'payment') askBot('What payment methods are available?', { mode: 'payment' });
    if (mode === 'products') askBot('What products and prices are available?', { mode: 'products' });
  }

  syncClosedState();

  launcher.addEventListener('click', event => {
    event.preventDefault();
    event.stopPropagation();
    isBotOpen() ? closeBot({ returnFocus: false }) : openBot();
  });
  closeBtn.addEventListener('click', event => {
    event.preventDefault();
    event.stopPropagation();
    closeBot();
  });

  document.addEventListener('pointerdown', event => {
    if (!isBotOpen()) return;
    if (!root.contains(event.target)) closeBot({ returnFocus: false });
  }, true);

  root.addEventListener('click', event => {
    const btn = event.target.closest('[data-ba-bot-mode]');
    if (!btn) return;
    openBot();
    setSmartForm(btn.dataset.baBotMode || '');
  });

  form.addEventListener('submit', event => {
    event.preventDefault();
    const message = input.value.trim();
    if (!message) return;
    input.value = '';
    askBot(message);
  });

  input.addEventListener('keydown', event => {
    if (event.key === 'Enter' && !event.shiftKey) {
      event.preventDefault();
      form.requestSubmit ? form.requestSubmit() : form.dispatchEvent(new Event('submit'));
    }
  });

  document.addEventListener('keydown', event => {
    if (event.key === 'Escape' && isBotOpen()) closeBot();
  });

  document.addEventListener('visibilitychange', () => {
    if (document.hidden) {
      stopLiveRefresh();
    } else if (isBotOpen()) {
      startLiveRefresh();
      refreshLiveAnswer();
    }
  });

  window.addEventListener('focus', refreshLiveAnswer);
})();
