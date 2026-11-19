/* Poll the authenticated pre-order endpoints: no page refresh and no message HTML injection. */
(() => {
  'use strict';
  const page = document.querySelector('[data-pre-live-role]');
  if (!page) return;
  const role = page.dataset.preLiveRole;
  const id = Number(page.dataset.preThreadId || 0);
  let inboxAfter = Number(page.dataset.preLastInboxMessage || 0);
  let requestsAfter = Number(page.dataset.preLastRequest || 0);
  let threadAfter = Number(page.dataset.preLastThreadMessage || 0);
  let status = page.dataset.preStatus || '';
  let updatedAt = page.dataset.preUpdated || '';
  let busy = false, stopped = false;
  const chat = page.querySelector('.pre-messages');
  const count = page.querySelector('.pre-message-count');
  const banner = document.getElementById('preLiveNotice');
  const messageText = document.getElementById('preLiveNoticeText');
  const bannerLink = document.getElementById('preLiveNoticeLink');
  document.getElementById('preLiveNoticeClose')?.addEventListener('click', () => { banner.hidden = true; });

  function announce(text, href, label = 'View request') {
    if (!banner) return;
    messageText.textContent = text;
    bannerLink.textContent = label;
    bannerLink.href = href;
    banner.hidden = false;
  }

  function appendMessage(m) {
    if (!chat || !m.id || chat.querySelector('[data-pre-message-id="' + Number(m.id) + '"]')) return;
    const own = m.sender_role === role;
    const atBottom = chat.scrollHeight - chat.scrollTop - chat.clientHeight < 100;
    chat.querySelector('.pre-chat-empty')?.remove();
    const article = document.createElement('article');
    article.className = 'pre-msg ' + (own ? 'is-mine' : 'is-other');
    article.dataset.preMessageId = String(m.id);
    const meta = document.createElement('div');
    meta.className = 'pre-msg-meta';
    const sender = document.createElement('strong');
    sender.textContent = own ? (role === 'admin' ? 'You (Admin)' : 'You') :
      (role === 'admin' ? (page.dataset.preCustomerName || 'Customer') : 'Brillé Amour Admin');
    const time = document.createElement('time');
    const date = new Date(String(m.created_at).replace(' ', 'T'));
    time.textContent = Number.isNaN(date.getTime()) ? '' : date.toLocaleString('en-PH', {month:'short',day:'numeric',hour:'numeric',minute:'2-digit'});
    meta.append(sender,time);
    const body = document.createElement('p');
    body.textContent = m.message || '';
    body.style.whiteSpace = 'pre-wrap';
    article.append(meta, body);
    chat.append(article);
    if (count) {
      const n = chat.querySelectorAll('.pre-msg').length;
      count.textContent = n + (n === 1 ? ' message' : ' messages');
    }
    if (atBottom || own) chat.scrollTop = chat.scrollHeight;
    if (!own) announce(role === 'admin' ? 'New message from customer.' : 'New message from Admin.',
      role === 'admin' ? '#preAdminDiscussion' : '#preCustomerDiscussion', 'Read message');
  }

  function addRequest(r) {
    const list = page.querySelector('.pre-admin-requests');
    if (!list || list.querySelector('[data-pre-request-id="' + Number(r.id) + '"]')) return;
    page.querySelector('.pre-admin-list .pre-empty')?.remove();
    const link = document.createElement('a');
    link.className = 'pre-admin-request pre-live-new';
    link.dataset.preRequestId = String(r.id);
    link.href = 'preorders.php?view=' + Number(r.id);
    const icon = document.createElement('div');
    icon.className = 'pre-request-icon';
    icon.textContent = String(r.product_name || 'P').charAt(0).toUpperCase();
    const copy = document.createElement('div');
    copy.className = 'pre-request-copy';
    const label = document.createElement('span');
    label.className = 'pre-request-id';
    label.textContent = 'REQUEST #' + Number(r.id) + ' · NEW';
    const title = document.createElement('strong');
    title.textContent = r.product_name || 'Custom product';
    const details = document.createElement('span');
    details.className = 'pre-request-customer';
    details.textContent = (r.customer_name || 'Customer') + ' · Qty ' + Number(r.quantity || 0);
    copy.append(label, title, details);
    const tag = document.createElement('span');
    tag.className = 'pre-tag pre-status-requested';
    tag.textContent = 'New request';
    link.append(icon,copy,tag);
    list.prepend(link);
  }

  async function poll() {
    if (busy || stopped || document.hidden || !navigator.onLine || (role === 'customer' && !id)) return;
    busy = true;
    try {
      const args = new URLSearchParams();
      if (id) { args.set('request_id',String(id)); args.set('since_thread',String(threadAfter)); }
      if (role === 'admin') { args.set('since_message',String(inboxAfter)); args.set('since_request',String(requestsAfter)); }
      const response = await fetch('preorder_live.php?' + args, {credentials:'same-origin', cache:'no-store', headers:{Accept:'application/json'}});
      if (response.status === 401 || response.status === 403) {
        stopped = true;
        announce('Your session has expired. Sign in again to receive updates.', 'login.php', 'Sign in');
        return;
      }
      if (!response.ok) return;
      const data = await response.json();
      if (role === 'admin') {
        for (const r of data.requests || []) {
          requestsAfter = Math.max(requestsAfter, Number(r.id));
          addRequest(r);
          announce('New pre-order request from ' + (r.customer_name || 'a customer') + '.', 'preorders.php?view=' + Number(r.id));
        }
        for (const m of data.inbox || []) {
          inboxAfter = Math.max(inboxAfter, Number(m.id));
          if (Number(m.request_id) === id) continue; // This thread is updated below.
          announce('New customer message about ' + (m.product_name || 'a pre-order') + '.',
            'preorders.php?view=' + Number(m.request_id) + '#preAdminDiscussion', 'Read message');
          page.querySelector('[data-pre-request-id="' + Number(m.request_id) + '"]')?.classList.add('pre-live-new');
        }
      }
      for (const m of data.thread || []) {
        threadAfter = Math.max(threadAfter, Number(m.id));
        appendMessage(m);
      }
      if (id && status && data.status && data.status !== status) {
        status = data.status;
        announce('Pre-order status has changed. Review the updated quotation or next step.',
          'preorders.php?view=' + id, 'Review update');
      }
      if (id && role === 'customer' && updatedAt && data.updated_at && updatedAt !== data.updated_at) {
        updatedAt = data.updated_at;
        if (data.status === 'quoted' || data.status === 'confirmed')
          announce('Your quotation or payment step has been updated.', 'preorders.php?view=' + id, 'Review quotation');
      }
    } catch (_error) { /* Recover on the next poll without blocking order forms. */ }
    finally { busy = false; }
  }
  window.setInterval(poll, 3000);
  document.addEventListener('visibilitychange', () => { if (!document.hidden) poll(); });
  window.addEventListener('online', poll);
  poll();
})();
