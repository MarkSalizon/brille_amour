(() => {
  'use strict';

  const dialog = document.getElementById('orderFeedbackModal');
  const form = document.getElementById('orderFeedbackForm');
  if (!dialog || !form) return;

  const title = document.getElementById('orderFeedbackTitle');
  const orderNumberNode = dialog.querySelector('[data-feedback-order-number]');
  const ratingInput = form.querySelector('input[name="rating"]');
  const orderIdInput = form.querySelector('input[name="order_id"]');
  const feedbackInput = form.querySelector('textarea[name="feedback"]');
  const stars = Array.from(dialog.querySelectorAll('[data-feedback-star]'));
  const submitButton = form.querySelector('[type="submit"]');
  const message = dialog.querySelector('[data-feedback-message]');
  let currentTrigger = null;
  let selectedRating = 0;

  function paintStars(rating) {
    selectedRating = Math.max(0, Math.min(5, Number(rating) || 0));
    if (ratingInput) ratingInput.value = selectedRating ? String(selectedRating) : '';
    stars.forEach((button) => {
      const value = Number(button.dataset.feedbackStar || 0);
      const active = value <= selectedRating;
      button.classList.toggle('is-selected', active);
      button.setAttribute('aria-checked', value === selectedRating ? 'true' : 'false');
    });
  }

  function setMessage(text, type) {
    if (!message) return;
    message.textContent = text || '';
    message.classList.toggle('is-error', type === 'error');
    message.classList.toggle('is-success', type === 'success');
    message.hidden = !text;
  }

  function openFeedback(trigger) {
    currentTrigger = trigger;
    const card = trigger.closest('[data-order-id]');
    if (!card) return;
    const orderId = String(card.dataset.orderId || '');
    const orderNumber = String(card.dataset.orderNumber || '');
    const currentRating = Number(trigger.dataset.feedbackRating || 0);
    const currentText = String(trigger.dataset.feedbackText || '');

    orderIdInput.value = orderId;
    feedbackInput.value = currentText;
    paintStars(currentRating);
    setMessage('', '');
    if (orderNumberNode) orderNumberNode.textContent = orderNumber ? `Order #${orderNumber}` : 'Completed order';
    if (title) title.textContent = currentRating ? 'Edit your rating' : 'Rate your order';

    if (typeof dialog.showModal === 'function') dialog.showModal();
    else dialog.setAttribute('open', '');
  }

  function closeFeedback() {
    if (typeof dialog.close === 'function' && dialog.open) dialog.close();
    else dialog.removeAttribute('open');
    currentTrigger = null;
  }

  document.addEventListener('click', (event) => {
    const trigger = event.target.closest('[data-open-order-feedback]');
    if (trigger) {
      event.preventDefault();
      openFeedback(trigger);
      return;
    }
    if (event.target.closest('[data-close-order-feedback]')) {
      event.preventDefault();
      closeFeedback();
    }
  });

  stars.forEach((button) => {
    button.addEventListener('click', () => paintStars(Number(button.dataset.feedbackStar || 0)));
    button.addEventListener('mouseenter', () => {
      const hover = Number(button.dataset.feedbackStar || 0);
      stars.forEach((star) => star.classList.toggle('is-hovered', Number(star.dataset.feedbackStar || 0) <= hover));
    });
    button.addEventListener('mouseleave', () => stars.forEach((star) => star.classList.remove('is-hovered')));
  });

  dialog.addEventListener('cancel', (event) => {
    event.preventDefault();
    closeFeedback();
  });
  dialog.addEventListener('click', (event) => {
    if (event.target === dialog) closeFeedback();
  });

  form.addEventListener('submit', async (event) => {
    event.preventDefault();
    if (!selectedRating) {
      setMessage('Choose a star rating first.', 'error');
      return;
    }

    const previousText = submitButton ? submitButton.textContent : '';
    if (submitButton) {
      submitButton.disabled = true;
      submitButton.textContent = 'Saving…';
    }
    setMessage('', '');

    try {
      const response = await fetch('order_feedback_api.php', {
        method: 'POST',
        credentials: 'same-origin',
        headers: { Accept: 'application/json' },
        body: new FormData(form)
      });
      const data = await response.json().catch(() => ({}));
      if (!response.ok || !data.ok) throw new Error(data.error || 'Could not save your feedback.');

      const rating = Number(data.rating || selectedRating);
      const feedback = String(data.feedback || '');
      const starsText = String(data.stars || '');
      const card = document.querySelector('[data-order-id="' + CSS.escape(String(data.order_id || orderIdInput.value)) + '"]');
      const trigger = card?.querySelector('[data-open-order-feedback]') || currentTrigger;
      if (trigger) {
        trigger.dataset.feedbackRating = String(rating);
        trigger.dataset.feedbackText = feedback;
        trigger.textContent = 'Edit Rating';
      }
      const summary = card?.querySelector('[data-feedback-summary]');
      if (summary) {
        summary.hidden = false;
        const starsNode = summary.querySelector('[data-feedback-stars-text]');
        const labelNode = summary.querySelector('[data-feedback-rating-label]');
        if (starsNode) starsNode.textContent = starsText;
        if (labelNode) labelNode.textContent = `${rating}/5`;
      }

      setMessage(data.message || 'Thank you for your feedback!', 'success');
      window.setTimeout(closeFeedback, 850);
    } catch (error) {
      setMessage(error && error.message ? error.message : 'Could not save your feedback.', 'error');
    } finally {
      if (submitButton) {
        submitButton.disabled = false;
        submitButton.textContent = previousText || 'Submit Feedback';
      }
    }
  });
})();
