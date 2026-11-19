/* Pickup Capacity page enhancements. No changes to server-side capacity rules. */
(() => {
  'use strict';
  const root = document.getElementById('pickupCapacityPage');
  if (!root) return;

  const date = root.querySelector('#capacityDate');
  const limit = root.querySelector('#capacityMax');
  const note = root.querySelector('#capacityNote');
  const closed = root.querySelector('#capacityClosed');
  const editor = root.querySelector('#pcPanelSettings');
  const search = root.querySelector('#capacitySearch');
  const filter = root.querySelector('#capacityFilter');
  const reset = root.querySelector('#capacityResetFilters');
  const count = root.querySelector('#capacityResultCount');
  const empty = root.querySelector('#capacityNoResults');
  const rows = Array.from(root.querySelectorAll('[data-capacity-row]'));

  const tabs = Array.from(root.querySelectorAll('[data-pc-tab]'));
  const panels = Array.from(root.querySelectorAll('[data-pc-panel]'));
  const availableTabs = new Set(tabs.map(button => button.dataset.pcTab));

  function showSection(name, focusTab = false) {
    if (!availableTabs.has(name)) return;
    tabs.forEach(button => {
      const active = button.dataset.pcTab === name;
      button.classList.toggle('is-active', active);
      button.setAttribute('aria-selected', String(active));
      button.tabIndex = active ? 0 : -1;
      if (active && focusTab) button.focus();
    });
    panels.forEach(panel => {
      const active = panel.dataset.pcPanel === name;
      panel.hidden = !active;
      panel.classList.toggle('is-active', active);
    });
  }

  tabs.forEach((button, index) => {
    button.addEventListener('click', () => showSection(button.dataset.pcTab));
    button.addEventListener('keydown', event => {
      let nextIndex = index;
      if (event.key === 'ArrowRight') nextIndex = (index + 1) % tabs.length;
      else if (event.key === 'ArrowLeft') nextIndex = (index - 1 + tabs.length) % tabs.length;
      else if (event.key === 'Home') nextIndex = 0;
      else if (event.key === 'End') nextIndex = tabs.length - 1;
      else return;
      event.preventDefault();
      showSection(tabs[nextIndex].dataset.pcTab, true);
    });
  });
  root.classList.add('is-tabs-ready');
  showSection(root.dataset.pcInitial || 'orders');

  const filterRows = () => {
    const query = (search?.value || '').trim().toLowerCase();
    const selected = filter?.value || 'all';
    let shown = 0;
    rows.forEach(row => {
      const matchesStatus = selected === 'all' || row.dataset.status === selected;
      const searchable = `${row.dataset.date || ''} ${row.querySelector('.pc-date-main')?.textContent || ''} ${row.dataset.note || ''}`.toLowerCase();
      const matchesQuery = !query || searchable.includes(query);
      const visible = matchesStatus && matchesQuery;
      row.hidden = !visible;
      if (visible) shown++;
    });
    if (count) count.textContent = `${shown} ${shown === 1 ? 'date' : 'dates'}`;
    if (empty) empty.hidden = shown !== 0;
    if (reset) reset.hidden = !query && selected === 'all';
  };

  search?.addEventListener('input', filterRows);
  filter?.addEventListener('change', filterRows);
  reset?.addEventListener('click', () => {
    if (search) search.value = '';
    if (filter) filter.value = 'all';
    filterRows();
    search?.focus();
  });

  root.querySelector('#capacityRows')?.addEventListener('click', event => {
    const button = event.target.closest('[data-edit-capacity]');
    if (!button) return;
    const row = button.closest('[data-capacity-row]');
    if (!row) return;
    if (date) date.value = row.dataset.date || '';
    if (limit) limit.value = row.dataset.limit || '';
    if (note) note.value = row.dataset.note || '';
    if (closed) closed.checked = row.dataset.closed === '1';
    showSection('settings');
    editor?.scrollIntoView({behavior: window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth', block:'start'});
    window.setTimeout(() => date?.focus({preventScroll:true}), 160);
  });
  filterRows();
})();

// Independent filters for the upcoming pickup/delivery appointment list.
(() => {
  'use strict';
  const root = document.getElementById('pickupCapacityPage');
  if (!root) return;
  const search = root.querySelector('#appointmentSearch');
  const method = root.querySelector('#appointmentMethod');
  const date = root.querySelector('#appointmentDate');
  const count = root.querySelector('#appointmentResultCount');
  const empty = root.querySelector('#appointmentNoResults');
  const rows = Array.from(root.querySelectorAll('[data-appointment-row]'));

  const filterAppointments = () => {
    const query = (search?.value || '').trim().toLowerCase();
    const selectedMethod = method?.value || 'all';
    const selectedDate = date?.value || '';
    let visible = 0;
    rows.forEach(row => {
      const matches = (selectedMethod === 'all' || row.dataset.method === selectedMethod)
        && (!selectedDate || row.dataset.date === selectedDate)
        && (!query || String(row.dataset.search || '').toLowerCase().includes(query));
      row.hidden = !matches;
      if (matches) visible++;
    });
    if (count) count.textContent = `${visible} ${visible === 1 ? 'order' : 'orders'}`;
    if (empty) empty.hidden = visible !== 0;
  };

  search?.addEventListener('input', filterAppointments);
  method?.addEventListener('change', filterAppointments);
  date?.addEventListener('change', filterAppointments);
  filterAppointments();
})();
