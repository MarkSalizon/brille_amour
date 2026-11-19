/* Real-time receipts. Polls a tiny "signature" endpoint and refreshes the receipt only when something changed.
   - Receipt page / verification page: <main data-receipt-live data-live-api="..." data-live-signature="..." data-live-swap="selector">
   - My Orders list: <div id="orders-list" data-receipt-live-list data-live-api="receipt_live_api.php?list=1">
   Polling pauses while the tab is hidden and resumes (with an immediate check) when it becomes visible. */
(function () {
  'use strict';
  var POLL_MS = 4000;

  function toast(text) {
    var el = document.getElementById('receiptLiveNote');
    if (!el) return;
    el.textContent = text;
    el.classList.add('is-flash');
    window.setTimeout(function () { el.textContent = 'Live'; el.classList.remove('is-flash'); }, 3500);
  }

  function getJson(url) {
    return fetch(url, { cache: 'no-store', credentials: 'same-origin', headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' } })
      .then(function (r) { return r.ok ? r.json() : null; })
      .catch(function () { return null; });
  }

  /* ---------- a single receipt (customer receipt page, admin/staff verification) ---------- */
  var root = document.querySelector('[data-receipt-live]');
  if (root) {
    var api = root.getAttribute('data-live-api');
    var swapSel = root.getAttribute('data-live-swap');
    var pageUrl = root.getAttribute('data-live-page') || location.href;
    var last = root.getAttribute('data-live-signature') || '';
    var busy = false;

    var userIsTyping = function () {
      var a = document.activeElement;
      return !!(a && root.contains(a) && /^(INPUT|TEXTAREA|SELECT)$/.test(a.tagName) && (a.value || '').length && a.closest(swapSel));
    };

    var reload = function (nextSig) {
      return fetch(pageUrl, { cache: 'no-store', credentials: 'same-origin', headers: { Accept: 'text/html', 'X-Requested-With': 'XMLHttpRequest' } })
        .then(function (r) { return r.ok ? r.text() : ''; })
        .then(function (html) {
          if (!html) return;
          var doc = new DOMParser().parseFromString(html, 'text/html');
          var incoming = doc.querySelector(swapSel);
          var current = document.querySelector(swapSel);
          if (!incoming || !current) return; // e.g. a login page came back: never swap it in
          if (current.innerHTML !== incoming.innerHTML) current.innerHTML = incoming.innerHTML;
          last = nextSig;
          toast('Updated just now');
        })
        .catch(function () {});
    };

    var tick = function () {
      if (busy || document.hidden) return;
      busy = true;
      getJson(api).then(function (data) {
        if (!data || !data.ok) return;
        if (data.paid === false || data.found === false) return;
        if (data.signature && data.signature !== last) {
          if (userIsTyping()) return; // do not wipe a note somebody is typing; try again next tick
          return reload(data.signature);
        }
      }).then(function () { busy = false; }, function () { busy = false; });
    };

    window.setInterval(tick, POLL_MS);
    window.addEventListener('focus', tick);
    document.addEventListener('visibilitychange', function () { if (!document.hidden) tick(); });
  }

  /* ---------- My Orders: show "View Payment Receipt" as soon as a payment is confirmed ---------- */
  var list = document.querySelector('[data-receipt-live-list]');
  if (list) {
    var listApi = list.getAttribute('data-live-api');
    var listLast = '';
    var listBusy = false;
    var applyList = function (orders) {
      orders.forEach(function (number) {
        var card = list.querySelector('[data-order-number="' + (window.CSS && CSS.escape ? CSS.escape(number) : number) + '"]');
        if (!card) return;
        var actions = card.querySelector('.purchase-footer-actions');
        if (!actions || actions.querySelector('.payment-receipt-link')) return;
        var a = document.createElement('a');
        a.className = 'payment-receipt-link';
        a.href = 'payment_receipt.php?order=' + encodeURIComponent(number);
        a.textContent = 'View Payment Receipt';
        actions.insertBefore(a, actions.firstChild);
      });
    };
    var listTick = function () {
      if (listBusy || document.hidden) return;
      listBusy = true;
      getJson(listApi).then(function (data) {
        if (data && data.ok && Array.isArray(data.orders) && data.signature !== listLast) {
          listLast = data.signature;
          applyList(data.orders);
        }
      }).then(function () { listBusy = false; }, function () { listBusy = false; });
    };
    window.setInterval(listTick, POLL_MS);
    window.addEventListener('focus', listTick);
    document.addEventListener('visibilitychange', function () { if (!document.hidden) listTick(); });
    listTick();
  }
})();
