/* Brillé Amour — confirmed pre-order payment, within the pre-order detail page. */
(() => {
  'use strict';
  const modal = document.getElementById('prePaymentModal');
  const openButton = document.getElementById('preOpenPayment');
  const form = document.getElementById('prePay');
  if (!modal || !openButton || !form) return;

  const closeButton = document.getElementById('prePaymentClose');
  const method = document.getElementById('preMethod');
  const date = document.getElementById('prePaymentDate');
  const address = document.getElementById('prePaymentAddress');
  const submit = document.getElementById('prePayButton');
  const error = document.getElementById('prePayError');
  const quotedTotal = Math.max(0, Number(modal.dataset.quotedTotal || 0));
  const orderTotal = document.getElementById('preOrderTotal');
  const paymentDueSummaryLabel = document.getElementById('prePaymentDueSummaryLabel');
  const paymentDueSummary = document.getElementById('prePaymentDueSummary');
  const payTodayLabel = document.getElementById('prePayTodayLabel');
  const payTodayNote = document.getElementById('prePayTodayNote');
  const payTodayAmount = document.getElementById('prePayTodayAmount');
  const financialTotal = document.getElementById('preFinancialTotal');
  const financialBalanceRow = document.getElementById('preFinancialBalanceRow');
  const financialBalance = document.getElementById('preFinancialBalance');
  let submitting = false;

  function show() {
    if (modal.open || typeof modal.showModal !== 'function') return;
    modal.showModal();
    document.body.classList.add('pre-payment-open');
    method.focus();
  }
  function hide() {
    if (submitting) return;
    if (modal.open) modal.close();
    document.body.classList.remove('pre-payment-open');
    openButton.focus();
  }
  function money(value) {
    return `PHP ${Number(value || 0).toLocaleString('en-PH', {minimumFractionDigits:2, maximumFractionDigits:2})}`;
  }
  function syncMethod() {
    const isDelivery = method.value === 'delivery';
    const label = date.closest('label');
    if (label) label.childNodes.forEach(node => {
      if (node.nodeType === Node.TEXT_NODE && /date/i.test(node.textContent)) {
        node.textContent = isDelivery ? 'Delivery date' : 'Pickup date';
      }
    });
    address.hidden = !isDelivery;

    const total = quotedTotal;
    const paymentDue = isDelivery ? total : Math.round(quotedTotal * 0.25 * 100) / 100;
    const balance = Math.max(0, total - paymentDue);
    if (orderTotal) orderTotal.textContent = money(total);
    if (paymentDueSummaryLabel) paymentDueSummaryLabel.childNodes[0].textContent = isDelivery ? 'Full payment ' : '25% downpayment ';
    if (paymentDueSummary) paymentDueSummary.textContent = money(paymentDue);
    if (payTodayLabel) payTodayLabel.childNodes[0].textContent = isDelivery ? 'Full payment due today ' : 'Downpayment due today ';
    if (payTodayNote) payTodayNote.textContent = isDelivery ? '100% of order total' : '25% of agreed total';
    if (payTodayAmount) payTodayAmount.textContent = money(paymentDue);
    if (financialTotal) financialTotal.textContent = money(total);
    if (financialBalanceRow) financialBalanceRow.hidden = isDelivery;
    if (financialBalance) financialBalance.textContent = money(balance);
  }
  function showError(message) {
    error.textContent = message;
    error.hidden = false;
    error.scrollIntoView({behavior:'auto', block:'nearest'});
  }

  openButton.addEventListener('click', show);
  closeButton.addEventListener('click', hide);
  // Keep the checkout open on backdrop click and Escape; only explicit buttons close it.
  modal.addEventListener('cancel', event => event.preventDefault());
  modal.addEventListener('close', () => document.body.classList.remove('pre-payment-open'));
  method.addEventListener('change', syncMethod);
  syncMethod();
  if (modal.dataset.openOnLoad === '1') show();

  form.addEventListener('submit', async event => {
    event.preventDefault();
    if (submitting) return;
    if (!form.reportValidity()) return;
    submitting = true;
    submit.disabled = true;
    submit.textContent = 'Preparing secure payment…';
    error.hidden = true;
    try {
      const body = new FormData(form);
      const response = await fetch('checkout_api.php', {
        method:'POST', body, credentials:'same-origin', headers:{Accept:'application/json'}
      });
      let data;
      try { data = await response.json(); }
      catch (_) { throw new Error('The payment response could not be read. Please check My Orders before trying again.'); }
      if (!response.ok || !data.ok) {
        if (response.status >= 500) {
          throw new Error((data.error || 'Payment could not be started.') + ' Please check My Orders before trying again.');
        }
        throw new Error(data.error || 'Unable to start payment. Please check your details.');
      }
      if (!data.checkout_url) throw new Error('Order created, but payment link is unavailable. Please check My Orders before trying again.');
      // The booking and payment session now exist: never submit this form twice.
      window.location.assign(data.checkout_url);
      return;
    } catch (err) {
      showError(err.message || 'Could not start payment. Please check My Orders before retrying.');
      submitting = false;
      submit.disabled = false;
      submit.textContent = 'Continue to Payment';
    }
  });
})();
