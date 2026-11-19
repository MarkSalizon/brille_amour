const PASSWORD_EYE_ICON = `
  <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
    <path d="M1.5 12s3.9-6.5 10.5-6.5S22.5 12 22.5 12 18.6 18.5 12 18.5 1.5 12 1.5 12Z" />
    <circle cx="12" cy="12" r="3.25" />
  </svg>
`;
const PASSWORD_EYE_OFF_ICON = `
  <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
    <path d="M3 3l18 18" />
    <path d="M10.73 5.18A10.94 10.94 0 0 1 12 5.1c6.6 0 10.5 6.4 10.5 6.4a18.78 18.78 0 0 1-4.07 4.78" />
    <path d="M6.61 6.62A18.74 18.74 0 0 0 1.5 11.5S5.4 17.9 12 17.9a10.9 10.9 0 0 0 4.28-.83" />
    <path d="M9.88 9.88A3 3 0 0 0 14.12 14.12" />
  </svg>
`;

function renderPasswordToggle(button, showing) {
  button.innerHTML = `${showing ? PASSWORD_EYE_OFF_ICON : PASSWORD_EYE_ICON}<span class="sr-only">${showing ? 'Hide password' : 'Show password'}</span>`;
  button.setAttribute('aria-label', showing ? 'Hide password' : 'Show password');
  button.setAttribute('title', showing ? 'Hide password' : 'Show password');
  button.setAttribute('aria-pressed', showing ? 'true' : 'false');
}

document.querySelectorAll('[data-password-toggle]').forEach((button) => {
  const input = document.getElementById(button.dataset.passwordToggle);
  if (!input) {
    return;
  }

  renderPasswordToggle(button, input.type !== 'password');

  button.addEventListener('click', () => {
    const show = input.type === 'password';
    input.type = show ? 'text' : 'password';
    renderPasswordToggle(button, show);
  });
});

document.querySelectorAll('[data-auth-form]').forEach((form) => {
  const submit = form.querySelector('[data-submit]');
  const fields = Array.from(form.querySelectorAll('[data-required]'));
  const terms = form.querySelector('[data-terms]');
  const confirm = form.querySelector('[data-confirm-password]');
  const password = form.querySelector('[data-password]');

  function setError(field, message) {
    const error = form.querySelector(`[data-error-for="${field.id}"]`);
    if (error) {
      error.textContent = message;
    }
    field.setAttribute('aria-invalid', message ? 'true' : 'false');
  }

  function validateField(field) {
    let message = '';
    const value = field.value.trim();

    if (field.hasAttribute('data-required') && !value) {
      message = 'This field is required.';
    } else if (field.type === 'email' && value && !field.validity.valid) {
      message = 'Enter a valid email address.';
    } else if (field.hasAttribute('data-password') && field.value.length > 0 && field.value.length < 6) {
      message = 'Use at least 6 characters.';
    } else if (field.hasAttribute('data-confirm-password') && password && field.value !== password.value) {
      message = 'Passwords must match.';
    }

    setError(field, message);
    return !message;
  }

  function updateSubmitState() {
    const fieldsValid = fields.every((field) => validateField(field));
    const termsValid = !terms || terms.checked;
    if (submit) {
      submit.disabled = !fieldsValid || !termsValid;
    }
  }

  fields.forEach((field) => {
    field.addEventListener('input', updateSubmitState);
    field.addEventListener('change', updateSubmitState);
    field.addEventListener('blur', () => validateField(field));
  });

  terms?.addEventListener('change', updateSubmitState);
  password?.addEventListener('input', () => {
    if (confirm?.value) {
      validateField(confirm);
    }
  });

  form.addEventListener('submit', (event) => {
    updateSubmitState();
    if (submit?.disabled) {
      event.preventDefault();
      return;
    }

    submit.dataset.originalText = submit.textContent;
    submit.textContent = 'Please wait...';
    submit.disabled = true;
  });

  updateSubmitState();
});
