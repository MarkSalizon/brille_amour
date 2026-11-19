/* Explicit, one-time review of signup terms. Never checks the consent box automatically. */
(() => {
  'use strict';
  const modal = document.querySelector('[data-terms-modal]');
  if (!modal || modal.dataset.initialized === 'yes') return;
  modal.dataset.initialized = 'yes';
  const scroll = modal.querySelector('[data-terms-scroll]');
  const content = modal.querySelector('[data-terms-content]');
  const hint = modal.querySelector('[data-terms-hint]');
  const next = modal.querySelector('[data-terms-next]');
  const tabs = Array.from(modal.querySelectorAll('[data-terms-tab]'));
  const boxes = Array.from(document.querySelectorAll('input[name="accept_terms"][type="checkbox"][data-terms-consent]'));
  const reviewed = {terms: false, privacy: false};
  const urls = {terms: modal.dataset.termsUrl, privacy: modal.dataset.privacyUrl};
  const docs = {terms: '', privacy: ''};
  let current = 'terms';
  let previousFocus = null;
  let isLoading = false;

  // Keep the checkbox clickable so its very first tap opens the review modal.
  // Consent is NEVER automatic: until both documents are reviewed, block the
  // browser's checkbox toggle rather than disabling the input itself.
  boxes.forEach(box => {
    box.checked = false;
    box.disabled = false;
    box.setAttribute('aria-describedby', 'baTermsReviewHelp');
    box.setAttribute('aria-controls', 'baTermsReviewModal');
    box.addEventListener('click', event => {
      if (reviewed.terms && reviewed.privacy) return;
      event.preventDefault();
      box.checked = false;
      open();
    });
  });

  function updateButtons() {
    tabs.forEach(tab => {
      const active = tab.dataset.termsTab === current;
      tab.classList.toggle('is-active', active);
      tab.setAttribute('aria-selected', String(active));
      const check = tab.querySelector('[data-terms-done]');
      if (check) check.textContent = reviewed[tab.dataset.termsTab] ? '✓' : '';
    });
    if (reviewed.terms && reviewed.privacy) {
      hint.textContent = 'Both documents reviewed. Return to the form, then tap the checkbox again if you agree.';
      next.textContent = 'Back to signup';
      next.disabled = isLoading;
    } else if (current === 'terms') {
      hint.textContent = reviewed.terms ? 'Terms reviewed. Continue to the Privacy Policy.' : 'Scroll to the end of the Terms & Conditions to continue.';
      next.textContent = 'Continue to Privacy Policy';
      next.disabled = isLoading || !reviewed.terms;
    } else {
      hint.textContent = reviewed.privacy ? 'Privacy Policy reviewed. Return to Terms if needed.' : 'Scroll to the end of the Privacy Policy to continue.';
      next.textContent = 'Return to Terms & Conditions';
      next.disabled = isLoading || !reviewed.privacy;
    }
  }

  function markScrollComplete() {
    if (isLoading || !docs[current]) return;
    if (scroll.scrollTop + scroll.clientHeight >= scroll.scrollHeight - 20) {
      reviewed[current] = true;
      updateButtons();
    }
  }

  async function view(type) {
    current = type;
    scroll.scrollTop = 0;
    isLoading = true;
    content.textContent = 'Opening document…';
    updateButtons();
    if (!docs[type]) {
      try {
        const response = await fetch(urls[type], {credentials:'same-origin',cache:'no-store'});
        if (!response.ok) throw new Error('Unavailable');
        const html = new DOMParser().parseFromString(await response.text(),'text/html');
        const article = html.querySelector('.privacy-card');
        if (!article) throw new Error('Document content unavailable');
        // The HTML is authored by our own public policy page, not by the customer.
        docs[type] = article.innerHTML;
      } catch (_) {
        content.textContent = 'Could not open the agreement document. Check your connection and try again.';
        const a = document.createElement('a');
        a.href = urls[type]; a.target = '_blank'; a.rel = 'noopener noreferrer';
        a.textContent = 'Open the document in a new tab';
        content.append(document.createElement('br'),a);
        isLoading = false;
        updateButtons();
        return;
      }
    }
    // Do not overwrite another document if the user clicked tabs during loading.
    if (current !== type) return;
    content.innerHTML = docs[type];
    content.querySelectorAll('a[href]').forEach(a => {
      const dest = new URL(a.getAttribute('href'),new URL(urls[type],document.baseURI));
      a.href = dest.href;
      a.target = '_blank'; a.rel = 'noopener noreferrer';
    });
    isLoading = false;
    scroll.scrollTop = 0;
    updateButtons();
    markScrollComplete();
  }

  function open() {
    previousFocus = document.activeElement;
    modal.hidden = false;
    modal.setAttribute('aria-hidden','false');
    document.body.classList.add('ba-terms-modal-open');
    view('terms');
    modal.querySelector('[data-terms-close]')?.focus();
  }
  function close() {
    modal.hidden = true;
    modal.setAttribute('aria-hidden','true');
    document.body.classList.remove('ba-terms-modal-open');
    if (previousFocus && typeof previousFocus.focus === 'function') previousFocus.focus();
  }
  document.querySelectorAll('[data-terms-open]').forEach(button => button.addEventListener('click',open));
  modal.querySelectorAll('[data-terms-close]').forEach(button=>button.addEventListener('click',close));
  tabs.forEach(tab=>tab.addEventListener('click',()=>view(tab.dataset.termsTab)));
  scroll.addEventListener('scroll',markScrollComplete,{passive:true});
  next.addEventListener('click',()=>{
    if (reviewed.terms && reviewed.privacy) {
      // Return focus to the checkbox; the user must tap it again to accept.
      // Never set checked=true on behalf of the customer.
      close();
      return;
    }
    if (current==='terms' && reviewed.terms) view('privacy');
    else if (current==='privacy' && reviewed.privacy) view('terms');
  });
  document.addEventListener('keydown',e=>{
    if (modal.hidden) return;
    if (e.key==='Escape') {e.preventDefault();e.stopImmediatePropagation();close();}
    if (e.key==='Tab') {
      const available=Array.from(modal.querySelectorAll('button:not([disabled]), [tabindex="0"], a[href]')).filter(el=>el.getClientRects().length);
      if (!available.length) return;
      const first=available[0],last=available[available.length-1];
      if (e.shiftKey && document.activeElement===first) {e.preventDefault();last.focus();}
      else if(!e.shiftKey && document.activeElement===last) {e.preventDefault();first.focus();}
    }
  },true);
  document.querySelectorAll('form').forEach(form=>{
    if (!form.querySelector('[data-terms-consent]')) return;
    form.addEventListener('submit',e=>{
      const box=form.querySelector('[data-terms-consent]');
      if (!box.checked || !reviewed.terms || !reviewed.privacy) {
        e.preventDefault();
        if (!reviewed.terms || !reviewed.privacy) open();
        else box.focus();
      }
    });
  });
})();
