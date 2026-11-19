/**
 * orders-live.js
 * ─────────────────────────────────────────────────────────
 * Makes the staff Orders page live in two shapes:
 *
 *   Detail view (#orderDetailLive) — the status form posts over AJAX, and the
 *   whole detail block re-renders itself afterwards. Re-fetching the page and
 *   swapping one element is deliberate: the badge, the role-allowed target list
 *   and the Production Log all have to agree, and this way that logic stays in
 *   PHP instead of being duplicated in JavaScript.
 *
 *   List view (#ordersListLive) — the list is filtered by URL, so instead of
 *   redrawing rows we watch the revision and offer a Refresh button when
 *   somebody else changes an order.
 *
 * Progressive enhancement: the status form is a real POST and still works with
 * this file missing or JavaScript off.
 */
(function () {
  'use strict';

  if (!window.BARealtime) return;

  var detail = document.getElementById('orderDetailLive');
  var list = document.getElementById('ordersListLive');
  var root = detail || list;
  if (!root) return;

  var API = root.dataset.api;
  var CSRF = root.dataset.csrf;

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

  // Re-queried every time: the pill lives inside the block we replace.
  function setPill(state) {
    var pill = document.getElementById('orderLivePill');
    if (!pill) return;

    pill.dataset.state = state;
    var label = pill.querySelector('.live-pill-text');
    if (label) label.textContent = PILL_TEXT[state] || 'Live';
  }

  /* ── detail view ────────────────────────────────────── */

  var busy = false;
  var reloading = false;
  var deferredRefresh = false;

  /** Don't yank the form out from under someone mid-edit. */
  function isEditing() {
    if (busy) return true;

    var active = document.activeElement;
    if (!active || !detail.contains(active)) return false;

    return /^(INPUT|SELECT|TEXTAREA)$/.test(active.tagName);
  }

  /** Pulls this same URL and swaps in its freshly rendered detail block. */
  function refreshDetail() {
    if (!detail || reloading) return Promise.resolve(false);

    reloading = true;
    deferredRefresh = false;
    setPill('syncing');

    return fetch(window.location.href, {
      credentials: 'same-origin',
      headers: { 'X-Requested-With': 'fetch' }
    })
      .then(function (response) { return response.text(); })
      .then(function (html) {
        var fresh = new DOMParser()
          .parseFromString(html, 'text/html')
          .getElementById('orderDetailLive');

        if (!fresh) return false;

        detail.innerHTML = fresh.innerHTML;
        detail.dataset.revision = fresh.dataset.revision || detail.dataset.revision;
        detail.dataset.logId = fresh.dataset.logId || detail.dataset.logId;

        // Keep the poller aligned with what we just drew.
        channel.sync({
          revision: detail.dataset.revision,
          last_log_id: parseInt(detail.dataset.logId || '0', 10)
        });

        if (window.BAResponsive) window.BAResponsive.refresh(detail);
        setPill('live');
        return true;
      })
      .catch(function () {
        setPill('offline');
        return false;
      })
      .then(function (done) {
        reloading = false;
        return done;
      });
  }

  function submitStatus(form) {
    if (busy) return;

    var statusField = form.querySelector('[name="new_status"]');
    var notesField = form.querySelector('[name="log_notes"]');
    var idField = form.querySelector('[name="id"]');
    var button = form.querySelector('button[type="submit"], button:not([type])');

    if (!statusField || !idField) return;

    busy = true;
    if (button) {
      button.disabled = true;
      button.dataset.idleLabel = button.textContent;
      button.textContent = 'Updating…';
    }
    setPill('syncing');

    channel.post('order_status', {
      order_id: idField.value,
      new_status: statusField.value,
      notes: notesField ? notesField.value : '',
      workflow_csrf_token: CSRF
    }).then(function (data) {
      busy = false;

      if (!data || !data.ok) {
        if (button) {
          button.disabled = false;
          button.textContent = button.dataset.idleLabel || 'Update Status';
        }
        toast((data && data.error) || 'Could not update that order.', 'error');
        setPill('live');
        // The server may have refused because the order moved under us.
        refreshDetail();
        return;
      }

      toast(data.message || 'Status updated.', 'success');
      refreshDetail();
    });
  }

  if (detail) {
    detail.addEventListener('submit', function (event) {
      var form = event.target.closest('form[data-order-status]');
      if (!form) return;

      event.preventDefault();
      submitStatus(form);
    });

    detail.addEventListener('click', function (event) {
      if (!event.target.closest('#orderLivePill')) return;

      setPill('syncing');
      channel.refresh();
    });

    // A redraw that arrived mid-edit lands as soon as the field is released.
    detail.addEventListener('focusout', function () {
      if (!deferredRefresh) return;

      setTimeout(function () {
        if (!deferredRefresh || isEditing()) return;
        deferredRefresh = false;
        refreshDetail();
      }, 200);
    });
  }

  /* ── list view ──────────────────────────────────────── */

  var pendingUpdates = 0;

  function announceListChange() {
    var button = document.getElementById('ordersRefresh');
    if (!button) return;

    pendingUpdates++;
    var text = document.getElementById('ordersRefreshText');
    if (text) {
      text.textContent = pendingUpdates === 1
        ? 'Orders updated — Refresh'
        : pendingUpdates + ' updates — Refresh';
    }
    button.hidden = false;
  }

  var refreshButton = document.getElementById('ordersRefresh');
  if (refreshButton) {
    refreshButton.addEventListener('click', function () {
      window.location.reload();
    });
  }

  /* ── live channel ───────────────────────────────────── */

  var channel = BARealtime.create({
    url: API,
    action: 'revision',
    revision: root.dataset.revision || '',
    lastLogId: parseInt(root.dataset.logId || '0', 10),
    interval: 8000,
    idleInterval: 25000,
    onUpdate: function () {
      if (!detail) {
        announceListChange();
        return;
      }

      // Somebody else touched an order. Redraw unless we are mid-edit, in which
      // case wait for the field to be released.
      if (isEditing()) {
        deferredRefresh = true;
        return;
      }

      refreshDetail();
    },
    onState: setPill,
    onEvents: function (events) {
      events.forEach(function (item) { toast(item.text, 'success'); });
    }
  });

  channel.start();
})();
