(function () {
  'use strict';

  const root = document.getElementById('orders-list');
  if (!root) return;

  const selectedStatus = String(root.dataset.selectedStatus || 'all');
  const stageLabels = {
    pending: 'Pending',
    in_production: 'In Production',
    quality_check: 'Quality Check',
    ready: 'Ready',
    out_for_delivery: 'Out for Delivery',
    completed: 'Completed'
  };

  function purchaseFilterStatus(item) {
    const status = String(item.display_status || item.status || 'pending');
    if (status !== 'cancelled' && item.fulfillment_method === 'delivery') {
      if (item.delivery_status === 'out_for_delivery') return 'out_for_delivery';
      if (item.delivery_status === 'delivered') return 'completed';
    }
    return status;
  }

  function orderProgressModel(card) {
    const delivery = card.dataset.fulfillmentMethod === 'delivery';
    const status = card.dataset.orderStatus || 'pending';
    const steps = [['pending','Pending'], ['in_production','In Production'], ['quality_check','Quality Check'], ['ready', delivery ? 'Ready for Dispatch' : 'Ready for Pickup']];
    if (delivery) steps.push(['out_for_delivery','Out for Delivery'], ['delivered','Delivered']);
    else steps.push(['completed','Completed']);
    let current = status;
    if (delivery && status !== 'cancelled') {
      if (['out_for_delivery','delivered'].includes(card.dataset.deliveryStatus)) current = card.dataset.deliveryStatus;
      else if (status === 'completed') current = 'ready';
    }
    const index = steps.findIndex(step => step[0] === current);
    let label = index >= 0 ? steps[index][1] : 'Awaiting update';
    if (status === 'cancelled') label = 'Order cancelled';
    else if (card.dataset.waitingQualityCheck === '1') label = 'Waiting for Quality Check';
    else if (card.dataset.waitingProduction === '1') label = 'Waiting for Production';
    const descriptions = {
      pending: 'Your order has been received and is awaiting processing.',
      in_production: 'Your order is now being produced.',
      quality_check: 'Your order is being checked for print quality.',
      ready: delivery ? 'Your order is packed and ready for dispatch.' : 'Your order is ready for pickup.',
      out_for_delivery: 'Your order is on its way to your delivery address.',
      delivered: 'Your order has been delivered. Thank you for ordering!',
      completed: 'Your order has been completed. Thank you for ordering!',
      cancelled: 'This order has been cancelled.'
    };
    let description = descriptions[current] || 'Awaiting the next order update.';
    if (status !== 'cancelled' && card.dataset.waitingQualityCheck === '1') description = 'Production is complete. Waiting for an available Quality Check slot.';
    else if (status !== 'cancelled' && card.dataset.waitingProduction === '1') description = 'Your order is waiting for an available Production slot.';
    return { delivery, steps, index, label, description };
  }
  function renderCardProgress(card) {
    const panel = card.querySelector('[data-inline-progress]');
    if (!panel || panel.hidden) return;
    const model = orderProgressModel(card);
    panel.querySelector('[data-progress-heading]').textContent = (model.delivery ? 'Delivery' : 'Pickup') + ' Progress';
    panel.querySelector('[data-progress-status]').textContent = model.description;
    const list = panel.querySelector('[data-progress-steps]');
    list.replaceChildren();
    list.style.setProperty('--progress-count', String(model.steps.length));
    model.steps.forEach((step, i) => {
      const li = document.createElement('li');
      li.className = model.index < 0 ? 'upcoming' : i < model.index ? 'done' : i === model.index ? 'current' : 'upcoming';
      if (i === model.index) li.setAttribute('aria-current','step');
      const marker = document.createElement('span');
      marker.className = 'purchase-progress-marker';
      marker.setAttribute('aria-hidden','true');
      if (i < model.index || (i === model.index && i === model.steps.length - 1)) {
        marker.innerHTML = '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="m5 12 4 4L19 6"/></svg>';
      } else if (i === model.index) {
        marker.innerHTML = '<span class="purchase-progress-current-dot"></span>';
      } else marker.textContent = String(i + 1);
      const label = document.createElement('span'); label.textContent = step[1];
      li.append(marker,label); list.append(li);
    });
  }
  function renderOpenProgress() {
    root.querySelectorAll('[data-order-id]').forEach(renderCardProgress);
  }
  function toggleProgress(trigger) {
    const card = trigger.closest('[data-order-id]');
    const panel = card && card.querySelector('[data-inline-progress]');
    if (!panel) return;
    panel.hidden = !panel.hidden;
    trigger.setAttribute('aria-expanded', String(!panel.hidden));
    card.classList.toggle('is-progress-open', !panel.hidden);
    renderCardProgress(card);
  }
  root.addEventListener('click', event => {
    const trigger = event.target.closest('[data-open-order-progress]');
    if (trigger) toggleProgress(trigger);
  });
  root.addEventListener('keydown', event => {
    const trigger = event.target.closest('[data-open-order-progress]');
    if (trigger && (event.key === 'Enter' || event.key === ' ')) { event.preventDefault(); toggleProgress(trigger); }
  });

  function updateStageCounts(counts) {
    Object.keys(stageLabels).forEach(function (stage) {
      const badge = document.querySelector('[data-stage-count="' + stage + '"]');
      if (!badge) return;
      const total = Number((counts && counts[stage]) || 0);
      badge.textContent = String(total);
      badge.hidden = total <= 0;
    });
  }

  function updateCard(item) {
    const card = root.querySelector('[data-order-id="' + String(item.id) + '"]');
    if (!card) return false;

    const status = String(item.display_status || item.status || 'pending');
    card.dataset.fulfillmentMethod = item.fulfillment_method || card.dataset.fulfillmentMethod || 'pickup';
    card.dataset.deliveryStatus = item.delivery_status || '';
    const waiting = Boolean(item.waiting_for_production);
    const waitingQc = Boolean(item.waiting_for_quality_check);
    card.dataset.orderStatus = status;
    card.dataset.waitingProduction = waiting ? '1' : '0';
    card.dataset.waitingQualityCheck = waitingQc ? '1' : '0';

    const statusNode = card.querySelector('[data-order-live-status]');
    if (statusNode) {
      statusNode.className = 'order-status status-' + status.replace(/[^a-z0-9_-]/gi, '-');
      statusNode.textContent = item.label || stageLabels[status] || status;
    }

    const queueNote = card.querySelector('[data-order-queue-note]');
    if (queueNote) {
      queueNote.hidden = !(waiting || waitingQc);
      if (waitingQc) queueNote.textContent = 'Production is complete. Waiting for an automatic Quality Check slot (first come, first serve).';
      else if (waiting) queueNote.textContent = 'Payment verified. Waiting for an automatic Production slot (first come, first serve).';
    }

    const updated = card.querySelector('[data-order-live-updated]');
    if (updated) updated.textContent = 'Last updated ' + (item.updated_ago || 'just now');

    const stageLink = card.querySelector('[data-order-stage-link]');
    if (stageLink) {
      const normalLabel = stageLabels[status] || item.label || status;
      stageLink.href = 'orders.php?status=' + encodeURIComponent(status) + '#orders-list';
      stageLink.textContent = 'View ' + normalLabel + ' Orders';
      stageLink.hidden = !Object.prototype.hasOwnProperty.call(stageLabels, status);
    }

    const completed = purchaseFilterStatus(item) === 'completed';
    const reorder = card.querySelector('[data-order-reorder]');
    if (reorder) reorder.hidden = !completed;

    const feedbackWrap = card.querySelector('[data-order-feedback-wrap]');
    if (feedbackWrap) feedbackWrap.hidden = !completed;

    const bill = card.querySelector('[data-order-completed-bill]');
    if (bill) bill.hidden = status !== 'ready';

    card.hidden = selectedStatus !== 'all' && purchaseFilterStatus(item) !== selectedStatus;
    return true;
  }


  function escapeHtml(value) {
    return String(value == null ? '' : value)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#039;');
  }

  function artworkMarkup(product) {
    const img = String(product && product.image_url || '');
    if (img) {
      return '<img class="catalog-product-preview" src="../' + escapeHtml(img) + '" alt="' + escapeHtml(product.name || 'Product') + ' product sample" loading="lazy">';
    }
    const idx = Number(product && product.sample_image_index);
    const hasIndex = Number.isInteger(idx) && idx >= 0 && idx < 24;
    if (hasIndex) {
      const x = (idx % 5) * 25;
      const y = Math.floor(idx / 5) * 100 / 3;
      return '<div class="product-sample-image" role="img" aria-label="' + escapeHtml(product.name || 'Product') + ' sample design" style="background-position:' + x + '% ' + y + '%"></div>';
    }
    const letter = escapeHtml(String((product && product.name) || '?').slice(0, 1).toUpperCase());
    return '<div class="purchase-product-fallback" aria-hidden="true">' + letter + '</div>';
  }

  let catalogSignature = '';
  let catalogPolling = false;
  async function refreshOrderProductCards() {
    if (catalogPolling || document.hidden) return;
    catalogPolling = true;
    try {
      const response = await fetch('catalog_api.php', {cache: 'no-store', credentials: 'same-origin', headers: {Accept: 'application/json'}});
      if (!response.ok) return;
      const data = await response.json();
      if (!data.ok || !Array.isArray(data.products)) return;
      const products = data.products;
      const signature = JSON.stringify(products.map(function (p) {
        return [String(p.id), p.name || '', p.image_url || '', Number(p.unit_price || 0), p.order_mode || 'regular'];
      }));
      if (signature === catalogSignature) return;
      catalogSignature = signature;
      const byId = Object.create(null);
      products.forEach(function (product) { byId[String(product.id)] = product; });
      Array.prototype.forEach.call(root.querySelectorAll('[data-order-id]'), function (card) {
        const productId = String(card.dataset.productId || '0');
        const product = byId[productId];
        if (!product) return;
        const art = card.querySelector('.purchase-product-art');
        if (art) art.innerHTML = artworkMarkup(product);
        const name = card.querySelector('[data-order-product-name]');
        if (name && product.name) name.textContent = product.name;
      });
    } catch (_) {
      // Keep current order cards visible when polling fails.
    } finally {
      catalogPolling = false;
    }
  }

  function updateVisibleSummary() {
    const cards = Array.prototype.slice.call(root.querySelectorAll('[data-order-id]'));
    const visible = cards.filter(function (card) { return !card.hidden; }).length;
    const count = document.getElementById('ordersVisibleCount');
    const word = document.getElementById('ordersVisibleWord');
    const empty = document.getElementById('ordersFilterEmpty');
    if (count) count.textContent = String(visible);
    if (word) word.textContent = visible === 1 ? 'Order' : 'Orders';
    if (empty) empty.hidden = visible > 0;
  }

  window.setInterval(refreshOrderProductCards, 6000);
  window.addEventListener('focus', refreshOrderProductCards);
  document.addEventListener('visibilitychange', refreshOrderProductCards);
  refreshOrderProductCards();

  window.addEventListener('brille:order-progress', function (event) {
    const detail = event.detail || {};
    const orders = Array.isArray(detail.orders) ? detail.orders : [];
    const counts = Object.fromEntries(Object.keys(stageLabels).map(key => [key, 0]));
    orders.forEach(item => { const key = purchaseFilterStatus(item); if (key in counts) counts[key]++; });
    updateStageCounts(counts);
    orders.forEach(updateCard);
    updateVisibleSummary();
    renderOpenProgress();

    const note = document.getElementById('ordersLiveNote');
    if (note && !detail.initial && Array.isArray(detail.changedOrders) && detail.changedOrders.length) {
      note.textContent = 'Order progress updated just now.';
      window.setTimeout(function () {
        note.textContent = 'Live progress updates appear automatically.';
      }, 4500);
    }
  });
})();
