/**
 * Brille Amour Staff shell controls.
 * Delegated handlers keep the mobile drawer working after AJAX page swaps.
 */
(function () {
  'use strict';

  if (window.__BAStaffShellBound) return;
  window.__BAStaffShellBound = true;

  function body() {
    return document.body;
  }

  function toggleButton() {
    return document.getElementById('baNavToggle');
  }

  function setOpen(open) {
    var page = body();
    if (!page || !page.classList.contains('staff-portal-body')) return;

    page.classList.toggle('nav-open', !!open);

    var button = toggleButton();
    if (button) {
      button.setAttribute('aria-expanded', open ? 'true' : 'false');
      button.setAttribute('aria-label', open ? 'Close navigation' : 'Open navigation');
    }
  }

  function closeNav() {
    setOpen(false);
  }

  document.addEventListener('click', function (event) {
    var button = event.target.closest('#baNavToggle');
    if (button) {
      event.preventDefault();
      event.stopPropagation();
      setOpen(!body().classList.contains('nav-open'));
      return;
    }

    if (event.target.closest('#baScrim')) {
      event.preventDefault();
      closeNav();
      return;
    }

    if (event.target.closest('#baSidebar a, #baSidebar form button')) {
      closeNav();
    }
  });

  document.addEventListener('keydown', function (event) {
    if (event.key === 'Escape') closeNav();
  });

  /* AJAX navigation keeps the shell but replaces page content. Reset the drawer
     so the next tap always starts from a predictable closed state. */
  document.addEventListener('ba:page-loaded', closeNav);
  document.addEventListener('ba:ajax-complete', closeNav);
  window.addEventListener('pageshow', closeNav);

  var desktopQuery = window.matchMedia('(min-width: 921px)');
  if (desktopQuery.addEventListener) {
    desktopQuery.addEventListener('change', function (event) {
      if (event.matches) closeNav();
    });
  } else if (desktopQuery.addListener) {
    desktopQuery.addListener(function (event) {
      if (event.matches) closeNav();
    });
  }
  /* Live "new orders" badge on the sidebar Production link. */
  var incomingTimer = null;
  var incomingLast = null;

  function incomingBadge() {
    return document.querySelector('[data-incoming-orders-badge]');
  }

  function applyIncoming(count) {
    var badge = incomingBadge();
    if (!badge) return;
    count = Math.max(0, parseInt(count, 10) || 0);
    var previous = incomingLast === null ? parseInt(badge.textContent, 10) || 0 : incomingLast;
    badge.textContent = count > 99 ? '99+' : String(count);
    badge.hidden = count === 0;
    if (count > previous) {
      badge.classList.remove('is-new');
      void badge.offsetWidth; // restart the pulse animation
      badge.classList.add('is-new');
    }
    incomingLast = count;
  }

  function pollIncoming() {
    if (document.hidden || !incomingBadge() || !window.fetch) return;
    fetch('incoming_orders_api.php', { credentials: 'same-origin', cache: 'no-store' })
      .then(function (r) { return r.ok ? r.json() : null; })
      .then(function (d) { if (d && d.ok) applyIncoming(d.count); })
      .catch(function () {});
  }

  function startIncomingPolling() {
    if (incomingTimer || !incomingBadge()) return;
    incomingTimer = window.setInterval(pollIncoming, 15000);
  }

  document.addEventListener('visibilitychange', pollIncoming);
  document.addEventListener('ba:page-loaded', function () { incomingLast = null; startIncomingPolling(); pollIncoming(); });
  window.addEventListener('pageshow', pollIncoming);
  startIncomingPolling();
})();
