/**
 * production-board.js
 * ─────────────────────────────────────────────────────────
 * Makes the Production Board live: moves go over AJAX instead of reloading the
 * page, and the board redraws itself when somebody else moves a card.
 *
 * Progressive enhancement — every button is a real <form> that still posts
 * normally if this file never loads or JavaScript is off, and the customer
 * frames are native <details>, so they open either way.
 *
 * Expects #productionLive with data-api / data-csrf / data-revision / data-log-id,
 * and the markup produced by includes/production_board_view.php.
 */
(function () {
  'use strict';

  var root = document.getElementById('productionLive');
  if (!root) return;

  // Confirmation must still work even if the live/AJAX helper fails to load.
  // In that case we fall back to the normal server POST after the user confirms.
  var realtimeAvailable = !!window.BARealtime;
  var channel = null;

  var API = root.dataset.api;
  var CSRF = root.dataset.csrf;
  var completedWrap = document.getElementById('completedTodayWrap');
  var pill = document.getElementById('boardLivePill');

  var initialStageTab = root.querySelector('.stage-tab.is-active') || root.querySelector('.stage-tab');
  var activeStage = initialStageTab ? (initialStageTab.dataset.stageTab || 'all') : 'all';
  // Staff see separate waiting and accepted orders; keep the chosen tab after live updates.
  var activeTaskTab = 'waiting';
  var busy = false;          // a move is in flight
  var dragCard = null;
  var deferredUpdate = null; // live payload that arrived at a bad moment

  var PILL_TEXT = {
    live: 'Live',
    syncing: 'Syncing',
    offline: 'Reconnecting',
    paused: 'Paused',
    idle: 'Live'
  };

  /* ── helpers ────────────────────────────────────────── */

  function escapeHtml(value) {
    var span = document.createElement('span');
    span.textContent = value == null ? '' : String(value);
    return span.innerHTML;
  }

  // showToast() writes HTML, so hand it escaped text.
  function toast(message, type) {
    if (typeof showToast === 'function') showToast(escapeHtml(message), type || 'success');
  }

  function setPill(state) {
    if (!pill) return;
    pill.dataset.state = state;
    var label = pill.querySelector('.live-pill-text');
    if (label) label.textContent = PILL_TEXT[state] || 'Live';
  }

  function column(stage) {
    return stage ? root.querySelector('.kanban-col[data-stage="' + stage + '"]') : null;
  }

  function stageOfEveryCard() {
    var map = {};
    root.querySelectorAll('.order-card').forEach(function (card) {
      map[card.dataset.orderId] = card.dataset.stage;
    });
    return map;
  }

  // Which customer frames are open and how far into each one somebody has
  // scrolled, so a poll six seconds from now doesn't fold a frame shut or jump
  // it back to the first item while they are working inside it.
  function frameStates() {
    var states = {};
    root.querySelectorAll('.customer-frame[data-frame-key]').forEach(function (frame) {
      var body = frame.querySelector('.frame-body');
      states[frame.dataset.frameKey] = {
        open: frame.open,
        scroll: body ? body.scrollTop : 0
      };
    });
    return states;
  }

  function restoreFrames(states) {
    if (!states) return;

    root.querySelectorAll('.customer-frame[data-frame-key]').forEach(function (frame) {
      var was = states[frame.dataset.frameKey];
      // A frame we have not seen before keeps whatever the server chose for it.
      if (!was) return;

      frame.open = was.open;

      var body = frame.querySelector('.frame-body');
      if (body && was.scroll) body.scrollTop = was.scroll;   // after open, or it stays 0
    });
  }

  /* ── rendering ──────────────────────────────────────── */

  function flashMoved(previous) {
    if (!previous || !Object.keys(previous).length) return;

    root.querySelectorAll('.order-card').forEach(function (card) {
      var was = previous[card.dataset.orderId];
      if (was && was !== card.dataset.stage) {
        var frame = card.closest('.customer-frame');
        if (frame) frame.open = true;   // never flash a card that is folded away
        card.classList.add('just-moved');
        setTimeout(function () { card.classList.remove('just-moved'); }, 1800);
      }
    });
  }

  function updateTotals(data) {
    if (!data || !data.labels) return;

    document.querySelectorAll('[data-live]').forEach(function (el) {
      var key = el.dataset.live;
      if (!Object.prototype.hasOwnProperty.call(data.labels, key)) return;

      el.textContent = data.labels[key];

      if (el.hasAttribute('data-live-hide-zero')) {
        el.hidden = !(data.totals && data.totals[key]);
      }
    });
  }

  function applyStageFilter(stage) {
    activeStage = stage || 'all';

    root.querySelectorAll('.stage-tab').forEach(function (tab) {
      var on = tab.dataset.stageTab === activeStage;
      tab.classList.toggle('is-active', on);
      tab.setAttribute('aria-selected', on ? 'true' : 'false');
    });

    root.querySelectorAll('.kanban-col').forEach(function (col) {
      col.classList.toggle('is-filtered-out', activeStage !== 'all' && col.dataset.stage !== activeStage);
    });
  }

  function applyStaffTaskFilter(tab) {
    if (!root.querySelector('[data-staff-task-tab]')) return; // Admin uses stage tabs instead.
    activeTaskTab = tab === 'accepted' ? 'accepted' : 'waiting';
    root.querySelectorAll('[data-staff-task-tab]').forEach(function (button) {
      var selected = button.dataset.staffTaskTab === activeTaskTab;
      button.classList.toggle('is-active', selected);
      button.setAttribute('aria-selected', selected ? 'true' : 'false');
    });
    root.querySelectorAll('[data-staff-task-pane]').forEach(function (pane) {
      pane.hidden = pane.dataset.staffTaskPane !== activeTaskTab;
    });
  }

  function applyBoard(data) {
    if (!data || typeof data.html !== 'string') {
      updateTotals(data);
      return;
    }

    // Never swap the board out from under a move or a drag.
    if (busy || dragCard || root.querySelector('.production-hold-editor[open], .qc-return-editor[open]')) {
      deferredUpdate = data;
      return;
    }

    var previous = stageOfEveryCard();
    var frames = frameStates();
    var scrolled = {};
    root.querySelectorAll('.col-body').forEach(function (body) {
      scrolled[body.dataset.dropZone] = body.scrollTop;
    });

    root.innerHTML = data.html;

    restoreFrames(frames);

    root.querySelectorAll('.col-body').forEach(function (body) {
      var top = scrolled[body.dataset.dropZone];
      if (top) body.scrollTop = top;
    });

    applyStageFilter(activeStage);
    applyStaffTaskFilter(activeTaskTab);
    flashMoved(previous);
    updateTotals(data);

    if (completedWrap && typeof data.completed_html === 'string') {
      completedWrap.innerHTML = data.completed_html;
      if (window.BAResponsive) window.BAResponsive.refresh(completedWrap);
    }
  }

  root.addEventListener('toggle', function () {
    if (!busy && !dragCard && !root.querySelector('.production-hold-editor[open], .qc-return-editor[open]') && deferredUpdate) {
      var pending = deferredUpdate;
      deferredUpdate = null;
      applyBoard(pending);
    }
  }, true);

  function releaseBusy() {
    busy = false;
    if (deferredUpdate) {
      var pending = deferredUpdate;
      deferredUpdate = null;
      applyBoard(pending);
    }
  }

  /* ── stage confirmation ─────────────────────────────── */

  var confirmModal = null;
  var confirmResolve = null;

  function stageLabel(stage) {
    var labels = {
      in_production: 'In Production',
      quality_check: 'Quality Check',
      ready: 'Ready'
    };
    return labels[stage] || String(stage || '').replace(/_/g, ' ');
  }

  function isForwardStaffHandoff(from, to) {
    return (from === 'in_production' && to === 'quality_check') ||
           (from === 'quality_check' && to === 'ready');
  }

  function ensureConfirmModal() {
    if (confirmModal) return confirmModal;

    var wrap = document.createElement('div');
    wrap.className = 'ba-stage-confirm';
    wrap.hidden = true;
    wrap.innerHTML =
      '<div class="ba-stage-confirm__backdrop" data-stage-confirm-cancel></div>' +
      '<section class="ba-stage-confirm__dialog" role="dialog" aria-modal="true" aria-labelledby="baStageConfirmTitle">' +
        '<button type="button" class="ba-stage-confirm__close" aria-label="Close" data-stage-confirm-cancel>&times;</button>' +
        '<div class="ba-stage-confirm__icon" aria-hidden="true">✓</div>' +
        '<p class="ba-stage-confirm__eyebrow">CONFIRM STATUS UPDATE</p>' +
        '<h2 id="baStageConfirmTitle">Proceed to the next stage?</h2>' +
        '<p class="ba-stage-confirm__copy" data-stage-confirm-copy></p>' +
        '<div class="ba-stage-confirm__summary">' +
          '<div><span>Order</span><strong data-stage-confirm-order></strong></div>' +
          '<div><span>Current stage</span><strong data-stage-confirm-from></strong></div>' +
          '<div><span>Next stage</span><strong data-stage-confirm-to></strong></div>' +
        '</div>' +
        '<p class="ba-stage-confirm__notice" data-stage-confirm-notice></p>' +
        '<div class="ba-stage-confirm__actions">' +
          '<button type="button" class="btn btn-secondary" data-stage-confirm-cancel>Cancel</button>' +
          '<button type="button" class="btn btn-primary" data-stage-confirm-ok>Confirm & Continue</button>' +
        '</div>' +
      '</section>';

    document.body.appendChild(wrap);
    confirmModal = wrap;

    function finish(value) {
      if (!confirmModal || confirmModal.hidden) return;
      confirmModal.hidden = true;
      document.body.classList.remove('ba-stage-confirm-open');
      var resolve = confirmResolve;
      confirmResolve = null;
      if (resolve) resolve(value);
    }

    wrap.addEventListener('click', function (event) {
      if (event.target.closest('[data-stage-confirm-cancel]')) finish(false);
      if (event.target.closest('[data-stage-confirm-ok]')) finish(true);
    });

    document.addEventListener('keydown', function (event) {
      if (event.key === 'Escape' && confirmModal && !confirmModal.hidden) finish(false);
    });

    return wrap;
  }

  function confirmStageMove(card, from, to) {
    if (!isForwardStaffHandoff(from, to)) return Promise.resolve(true);

    var modal = ensureConfirmModal();
    var orderNumber = card && card.dataset.orderNumber ? card.dataset.orderNumber : 'This order';
    var copy = to === 'quality_check'
      ? 'Please confirm that production is complete and this order is ready to be reviewed by Quality Check.'
      : 'Please confirm that this order passed Quality Check and is ready for pickup or delivery preparation.';
    var notice = to === 'quality_check'
      ? 'After confirmation, the Quality Check staff will receive this order and the customer can see the updated progress.'
      : 'After confirmation, the order will move to Ready and the customer can see the updated progress.';
    var ok = modal.querySelector('[data-stage-confirm-ok]');

    modal.querySelector('[data-stage-confirm-order]').textContent = orderNumber;
    modal.querySelector('[data-stage-confirm-from]').textContent = stageLabel(from);
    modal.querySelector('[data-stage-confirm-to]').textContent = stageLabel(to);
    modal.querySelector('[data-stage-confirm-copy]').textContent = copy;
    modal.querySelector('[data-stage-confirm-notice]').textContent = notice;
    if (ok) ok.textContent = to === 'quality_check' ? 'Send to Quality Check' : 'Approve & Mark Ready';

    modal.hidden = false;
    document.body.classList.add('ba-stage-confirm-open');
    setTimeout(function () { if (ok) ok.focus(); }, 0);

    return new Promise(function (resolve) {
      if (confirmResolve) confirmResolve(false);
      confirmResolve = resolve;
    });
  }

  function nativeMove(card, newStatus, notes) {
    if (!card) return;

    var forms = Array.prototype.slice.call(card.querySelectorAll('form[data-board-move]'));
    var form = null;
    var button = null;

    forms.some(function (candidate) {
      var target = candidate.querySelector('button[name="new_status"][value="' + newStatus + '"]');
      if (!target) return false;
      form = candidate;
      button = target;
      return true;
    });

    if (!form || !button) return;

    var notesField = form.querySelector('[name="notes"]');
    if (notesField && typeof notes === 'string' && notes !== '') notesField.value = notes;

    // One-shot bypass so requestSubmit() does not reopen the same modal.
    form.dataset.stageConfirmed = '1';

    if (typeof form.requestSubmit === 'function') {
      form.requestSubmit(button);
      return;
    }

    var hidden = document.createElement('input');
    hidden.type = 'hidden';
    hidden.name = 'new_status';
    hidden.value = newStatus;
    form.appendChild(hidden);
    form.submit();
  }

  function requestMove(orderId, newStatus, card, notes) {
    var from = card ? card.dataset.stage : '';
    confirmStageMove(card, from, newStatus).then(function (approved) {
      if (!approved) return;
      if (channel) move(orderId, newStatus, card, notes);
      else nativeMove(card, newStatus, notes);
    });
  }

  /* ── moving a card ──────────────────────────────────── */

  function move(orderId, newStatus, card, notes) {
    if (!orderId || !newStatus || busy) return;
    if (!channel) {
      nativeMove(card, newStatus, notes);
      return;
    }

    busy = true;
    if (card) card.classList.add('is-busy');
    setPill('syncing');

    channel.post('move', {
      order_id: orderId,
      new_status: newStatus,
      workflow_csrf_token: CSRF,
      notes: notes || ''
    }).then(function (data) {
      if (card) card.classList.remove('is-busy');
      releaseBusy();

      if (!data || !data.ok) {
        toast((data && data.error) || 'Could not move that order.', 'error');
        if (data && data.html) applyBoard(data);   // resync to what the server really has
        setPill('live');
        return;
      }

      applyBoard(data);
      toast(data.message || 'Order moved.', 'success');
      setPill('live');
    }).catch(function () {
      if (card) card.classList.remove('is-busy');
      releaseBusy();
      setPill('offline');
      // Keep the workflow usable even when AJAX/realtime is unavailable.
      nativeMove(card, newStatus, notes);
    });
  }

  root.addEventListener('submit', function (event) {
    var holdForm = event.target.closest('form[data-board-hold]');
    if (holdForm) {
      // Without realtime/AJAX, allow the normal POST handler to save the hold.
      if (!channel) return;

      event.preventDefault();
      if (busy) return;
      var payload = {};
      new FormData(holdForm).forEach(function (value, key) { payload[key] = value; });
      var feedback = holdForm.querySelector('[data-hold-feedback]');
      busy = true;
      if (feedback) feedback.textContent = 'Saving...';
      channel.post('hold', payload).then(function (data) {
        busy = false;
        if (!data || !data.ok) {
          if (feedback) feedback.textContent = (data && data.error) || 'Unable to save. Try again.';
          return;
        }
        var editor = holdForm.closest('.production-hold-editor');
        if (editor) editor.open = false;
        deferredUpdate = null;
        applyBoard(data);
        toast(data.message);
      }).catch(function () {
        busy = false;
        if (feedback) feedback.textContent = 'Unable to save. Check your connection and try again.';
      });
      return;
    }

    var decisionForm = event.target.closest('form[data-board-decision]');
    if (decisionForm) {
      if (!channel) return; // Native POST fallback is handled by staff/production.php.
      event.preventDefault();
      if (busy) return;
      var payload = {};
      new FormData(decisionForm).forEach(function (value, key) { payload[key] = value; });
      busy = true;
      var submitBtn = decisionForm.querySelector('button[type="submit"]');
      if (submitBtn) submitBtn.disabled = true;
      channel.post('decide_order', payload).then(function (data) {
        busy = false;
        if (submitBtn) submitBtn.disabled = false;
        if (!data || !data.ok) {
          toast((data && data.error) || 'Unable to save your decision.', 'error');
          return;
        }
        // A prior declined/accepted card can now change its available actions.
        var declineEditor = decisionForm.closest('.staff-decline-editor');
        if (declineEditor) declineEditor.open = false;
        deferredUpdate = null;
        if (payload.decision === 'accept') activeTaskTab = 'accepted';
        applyBoard(data);
        toast(data.message || 'Decision saved.', 'success');
      }).catch(function () {
        busy = false;
        if (submitBtn) submitBtn.disabled = false;
        toast('Unable to save your decision. Please check your connection.', 'error');
      });
      return;
    }

    var form = event.target.closest('form[data-board-move]');
    if (!form) return;

    // nativeMove() sets this flag only after the confirmation was accepted.
    if (form.dataset.stageConfirmed === '1') {
      delete form.dataset.stageConfirmed;
      return;
    }

    var button = event.submitter || form.querySelector('button[name="new_status"]');
    var newStatus = button ? button.value : '';
    var card = form.closest('.order-card');
    var from = card ? card.dataset.stage : '';

    var orderId = form.querySelector('[name="order_id"]');
    var notesField = form.querySelector('[name="notes"]');

    // Only the two staff forward hand-offs require the confirmation modal.
    if (isForwardStaffHandoff(from, newStatus)) {
      event.preventDefault();
      requestMove(
        orderId ? orderId.value : '',
        newStatus,
        card,
        notesField ? notesField.value : ''
      );
      return;
    }

    // Preserve the existing AJAX behavior for admin moves and QC returns when
    // realtime is available; otherwise the normal server POST still works.
    if (channel) {
      event.preventDefault();
      move(
        orderId ? orderId.value : '',
        newStatus,
        card,
        notesField ? notesField.value : ''
      );
    }
  });

  // Admin delivery workflow uses the same live board endpoint so a Ready
  // delivery order moves immediately into the Out for Delivery container.
  root.addEventListener('submit', function (event) {
    var form = event.target.closest('form[data-board-delivery]');
    if (!form || !root.contains(form)) return;

    event.preventDefault();
    if (busy) return;

    var orderId = form.querySelector('[name="order_id"]');
    var target = form.querySelector('[name="delivery_status"]');
    var submitButton = form.querySelector('button[type="submit"]');
    var targetValue = target ? target.value : '';
    var confirmText = targetValue === 'delivered'
      ? 'Confirm that this order has been delivered? It will be marked Completed.'
      : 'Move this Ready order to Out for Delivery?';

    if (!window.confirm(confirmText)) return;

    busy = true;
    if (submitButton) submitButton.disabled = true;
    setPill('syncing');

    var body = new FormData(form);
    body.set('action', 'delivery_status');

    fetch(API + '?action=delivery_status', {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Accept': 'application/json' },
      body: body
    })
      .then(function (response) {
        return response.json().catch(function () { return {}; }).then(function (data) {
          if (!response.ok || !data.ok) throw new Error(data.error || 'Unable to update delivery status.');
          return data;
        });
      })
      .then(function (data) {
        applyBoard(data);
        toast(data.message || 'Delivery status updated.', 'success');
        setPill('live');
      })
      .catch(function (error) {
        toast(error && error.message ? error.message : 'Unable to update delivery status.', 'error');
        setPill('offline');
      })
      .finally(function () {
        if (submitButton) submitButton.disabled = false;
        releaseBusy();
      });
  });

  // Delegated so the buttons continue working after real-time board rerenders.
  root.addEventListener('click', function (event) {
    var taskTab = event.target.closest('[data-staff-task-tab]');
    if (taskTab && root.contains(taskTab)) applyStaffTaskFilter(taskTab.dataset.staffTaskTab);
  });

  /* ── stage tabs (the phone view of a 4-column board) ── */

  root.addEventListener('click', function (event) {
    var tab = event.target.closest('[data-stage-tab]');
    if (tab) applyStageFilter(tab.dataset.stageTab);
  });

  /* ── drag and drop (desktop shortcut for Back / Next) ─ */

  function isLegalDrop(from, to) {
    if (!from || !to || from === to) return false;

    var source = column(from);
    if (!source || source.dataset.canAct !== '1') return false;

    return to === source.dataset.prev || to === source.dataset.next;
  }

  function clearDropHints() {
    root.querySelectorAll('.kanban-col').forEach(function (col) {
      col.classList.remove('is-drop-target', 'is-drop-hover');
    });
  }

  function endDrag() {
    if (dragCard) dragCard.classList.remove('is-dragging');
    dragCard = null;
    clearDropHints();

    if (deferredUpdate && !busy) {
      var pending = deferredUpdate;
      deferredUpdate = null;
      applyBoard(pending);
    }
  }

  root.addEventListener('dragstart', function (event) {
    var card = event.target.closest('.order-card[draggable="true"]');
    if (!card) return;

    dragCard = card;
    card.classList.add('is-dragging');

    if (event.dataTransfer) {
      event.dataTransfer.effectAllowed = 'move';
      event.dataTransfer.setData('text/plain', card.dataset.orderId || '');
    }

    root.querySelectorAll('.kanban-col').forEach(function (col) {
      if (isLegalDrop(card.dataset.stage, col.dataset.stage)) col.classList.add('is-drop-target');
    });
  });

  root.addEventListener('dragend', endDrag);

  root.addEventListener('dragover', function (event) {
    if (!dragCard) return;

    var zone = event.target.closest('.col-body');
    if (!zone || !isLegalDrop(dragCard.dataset.stage, zone.dataset.dropZone)) return;

    event.preventDefault();
    if (event.dataTransfer) event.dataTransfer.dropEffect = 'move';
    zone.parentElement.classList.add('is-drop-hover');
  });

  root.addEventListener('dragleave', function (event) {
    var zone = event.target.closest('.col-body');
    if (zone) zone.parentElement.classList.remove('is-drop-hover');
  });

  root.addEventListener('drop', function (event) {
    if (!dragCard) return;

    var zone = event.target.closest('.col-body');
    if (!zone) return;

    event.preventDefault();

    var card = dragCard;
    var from = card.dataset.stage;
    var to = zone.dataset.dropZone;

    endDrag();

    if (isLegalDrop(from, to)) requestMove(card.dataset.orderId, to, card);
  });

  /* ── live channel ───────────────────────────────────── */

  if (realtimeAvailable) {
    channel = BARealtime.create({
      url: API,
      action: 'board',
      revision: root.dataset.revision || '',
      lastLogId: parseInt(root.dataset.logId || '0', 10),
      interval: 6000,
      idleInterval: 20000,
      onUpdate: applyBoard,
      onState: setPill,
      onEvents: function (events) {
        events.forEach(function (item) { toast(item.text, 'success'); });
      }
    });
  }

  if (pill && channel) {
    pill.addEventListener('click', function () {
      setPill('syncing');
      channel.refresh();
    });
  }

  applyStageFilter('all');
  applyStaffTaskFilter('waiting');
  if (channel) channel.start();
})();
