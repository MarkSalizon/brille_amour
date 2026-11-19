(() => {
  'use strict';

  const root = document.getElementById('productionLive');
  const select = document.querySelector('[data-production-sort]');
  if (!root || !select) return;

  const allowed = new Set([
    'queue', 'overdue', 'priority', 'due_asc', 'due_desc',
    'newest', 'oldest', 'customer_asc', 'qty_desc'
  ]);
  const storageKey = `brille-production-sort:${window.location.pathname}`;
  const collator = new Intl.Collator(undefined, { numeric: true, sensitivity: 'base' });
  let applying = false;
  let queuedFrame = 0;

  const stored = (() => {
    try { return window.localStorage.getItem(storageKey) || ''; }
    catch (_) { return ''; }
  })();
  if (allowed.has(stored)) select.value = stored;

  const numberValue = (frame, key) => {
    const value = Number(frame.dataset[key] || 0);
    return Number.isFinite(value) ? value : 0;
  };

  const textValue = (frame, key) => String(frame.dataset[key] || '').trim();

  const priorityWeight = (value) => {
    switch (String(value || '').toLowerCase()) {
      case 'urgent': return 5;
      case 'high': return 4;
      case 'medium':
      case 'normal': return 3;
      case 'low': return 2;
      default: return 1;
    }
  };

  const dueValue = (frame, fallback) => {
    const value = numberValue(frame, 'sortDue');
    return value > 0 ? value : fallback;
  };

  const tieBreak = (left, right) => {
    const createdDiff = numberValue(left, 'sortCreated') - numberValue(right, 'sortCreated');
    if (createdDiff) return createdDiff;
    return collator.compare(textValue(left, 'sortOrder'), textValue(right, 'sortOrder'));
  };

  const comparatorFor = (mode) => {
    switch (mode) {
      case 'overdue':
        return (a, b) =>
          (numberValue(b, 'sortOverdue') - numberValue(a, 'sortOverdue')) ||
          (dueValue(a, Number.MAX_SAFE_INTEGER) - dueValue(b, Number.MAX_SAFE_INTEGER)) ||
          tieBreak(a, b);
      case 'priority':
        return (a, b) =>
          (priorityWeight(textValue(b, 'sortPriority')) - priorityWeight(textValue(a, 'sortPriority'))) ||
          (numberValue(b, 'sortOverdue') - numberValue(a, 'sortOverdue')) ||
          (dueValue(a, Number.MAX_SAFE_INTEGER) - dueValue(b, Number.MAX_SAFE_INTEGER)) ||
          tieBreak(a, b);
      case 'due_asc':
        return (a, b) =>
          (dueValue(a, Number.MAX_SAFE_INTEGER) - dueValue(b, Number.MAX_SAFE_INTEGER)) ||
          tieBreak(a, b);
      case 'due_desc':
        return (a, b) =>
          (dueValue(b, Number.MIN_SAFE_INTEGER) - dueValue(a, Number.MIN_SAFE_INTEGER)) ||
          tieBreak(a, b);
      case 'newest':
        return (a, b) =>
          (numberValue(b, 'sortCreated') - numberValue(a, 'sortCreated')) ||
          collator.compare(textValue(b, 'sortOrder'), textValue(a, 'sortOrder'));
      case 'oldest':
        return (a, b) =>
          (numberValue(a, 'sortCreated') - numberValue(b, 'sortCreated')) ||
          collator.compare(textValue(a, 'sortOrder'), textValue(b, 'sortOrder'));
      case 'customer_asc':
        return (a, b) =>
          collator.compare(textValue(a, 'sortCustomer'), textValue(b, 'sortCustomer')) ||
          tieBreak(a, b);
      case 'qty_desc':
        return (a, b) =>
          (numberValue(b, 'sortQuantity') - numberValue(a, 'sortQuantity')) ||
          (dueValue(a, Number.MAX_SAFE_INTEGER) - dueValue(b, Number.MAX_SAFE_INTEGER)) ||
          tieBreak(a, b);
      default:
        return null;
    }
  };

  const getZones = () => {
    const staffPanes = Array.from(root.querySelectorAll('[data-staff-task-pane]'));
    if (staffPanes.length) return staffPanes;
    return Array.from(root.querySelectorAll('.kanban-col .col-body'));
  };

  const getFrames = (zone) => Array.from(zone.querySelectorAll(
    ':scope > .production-date-group > .production-date-orders > .customer-frame'
  ));

  const applySort = () => {
    applying = true;
    const mode = allowed.has(select.value) ? select.value : 'queue';
    const compare = comparatorFor(mode);

    getZones().forEach((zone) => {
      zone.classList.add('production-sort-zone');
      const frames = getFrames(zone);

      if (!compare) {
        zone.classList.remove('is-custom-sorted');
        frames.forEach((frame) => frame.style.removeProperty('order'));
        return;
      }

      zone.classList.add('is-custom-sorted');
      frames.sort(compare).forEach((frame, index) => {
        frame.style.order = String(index + 1);
      });
    });

    applying = false;
  };

  const scheduleApply = () => {
    if (queuedFrame) window.cancelAnimationFrame(queuedFrame);
    queuedFrame = window.requestAnimationFrame(() => {
      queuedFrame = 0;
      applySort();
    });
  };

  select.addEventListener('change', () => {
    const mode = allowed.has(select.value) ? select.value : 'queue';
    try { window.localStorage.setItem(storageKey, mode); } catch (_) {}
    applySort();
  });

  // The live production poller replaces the board markup. Reapply the user's
  // chosen sort immediately after that replacement without touching workflow data.
  new MutationObserver(() => {
    if (!applying) scheduleApply();
  }).observe(root, { childList: true });

  applySort();
})();
