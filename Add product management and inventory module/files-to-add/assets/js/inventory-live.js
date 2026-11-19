/* Live read-only AJAX snapshots. Existing server-side transaction and stock guards remain authoritative. */
(() => {
  const onInventory = /\/inventory\.php$/.test(location.pathname);
  const onProducts = /\/products\.php$/.test(location.pathname);
  if (!onInventory && !onProducts) return;
  const selectors = onInventory
    ? ['.inv-summary', '.inv-table-card', '#inventoryLowStockAlerts', '.tx-card']
    : ['#apProductsGrid', '#apNoProducts', '.ap-hero-meta'];
  let busy = false;
  async function refresh() {
    if (busy || document.hidden || document.querySelector('.modal-backdrop.open, .modal-backdrop[style*="display: block"]')) return;
    // Avoid overriding typed filters or forms while somebody is editing.
    if (document.activeElement && document.activeElement.closest('form') &&
        ['INPUT','TEXTAREA','SELECT'].includes(document.activeElement.tagName)) return;
    busy = true;
    try {
      const url = new URL(location.href);
      url.searchParams.set('inventory_live_snapshot', '1');
      const response = await fetch(url.toString(), {
        credentials:'same-origin', cache:'no-store', headers:{Accept:'text/html','X-Requested-With':'XMLHttpRequest'}
      });
      if (!response.ok || !(response.headers.get('content-type') || '').includes('text/html')) return;
      const page = new DOMParser().parseFromString(await response.text(), 'text/html');
      // Login redirects may be HTTP 200; never swap a login page into an authenticated view.
      if (!page.querySelector(onInventory ? '.inv-table-card' : '#apProductsGrid')) return;
      for (const selector of selectors) {
        const existing = document.querySelector(selector);
        const incoming = page.querySelector(selector);
        if (existing && incoming && existing.innerHTML !== incoming.innerHTML) {
          existing.innerHTML = incoming.innerHTML;
        }
      }
      if (onProducts && window.brilleFilterAdminProducts) window.brilleFilterAdminProducts();
    } catch (_) { /* Preserve current page; retry after connectivity recovers. */ }
    finally { busy = false; }
  }
  window.setInterval(refresh, 5000);
  window.addEventListener('focus', refresh);
  document.addEventListener('visibilitychange', () => { if (!document.hidden) refresh(); });
  // Refresh immediately after a stock/product AJAX mutation instead of waiting for the next poll.
  document.addEventListener('ba:ajax-complete', () => window.setTimeout(refresh, 180));
})();
