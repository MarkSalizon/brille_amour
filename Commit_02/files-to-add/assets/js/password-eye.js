/* Shared eye-only password controls for customer and legacy auth pages. */
(() => {
  const eye = '<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M2 12s3.6-6 10-6 10 6 10 6-3.6 6-10 6-10-6-10-6Z"/><circle cx="12" cy="12" r="3"/></svg>';
  const eyeOff = '<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M3 3l18 18M10.6 5.1c.5-.1.9-.1 1.4-.1 6.4 0 10 7 10 7a16.4 16.4 0 0 1-3.6 4.1M6.7 6.7C3.7 8.5 2 12 2 12s3.6 7 10 7c1.4 0 2.8-.3 4-.9M9.9 9.9a3 3 0 0 0 4.2 4.2"/></svg>';
  document.querySelectorAll('[data-password-toggle], [data-modal-password-toggle]').forEach((button) => {
    const id = button.dataset.passwordToggle || button.dataset.modalPasswordToggle;
    const field = document.getElementById(id || '');
    if (!field || button.dataset.eyeBound === '1') return;
    button.dataset.eyeBound = '1';
    const render = () => {
      const visible = field.type !== 'password';
      const hasValue = String(field.value || '').length > 0;
      const description = visible ? 'Hide password' : 'Show password';
      button.innerHTML = visible ? eyeOff : eye;
      button.setAttribute('aria-label', description);
      button.setAttribute('title', description);
      button.setAttribute('aria-pressed', String(visible));
      button.classList.toggle('is-visible', hasValue);
      button.tabIndex = hasValue ? 0 : -1;
    };
    render();
    button.addEventListener('click', () => {
      field.type = field.type === 'password' ? 'text' : 'password';
      render();
    });
    field.addEventListener('input', render);
    field.addEventListener('change', render);
    field.addEventListener('blur', render);
  });
})();
