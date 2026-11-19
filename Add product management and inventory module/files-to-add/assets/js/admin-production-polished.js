/* Admin-only board presentation. All production changes still use production-board.js. */
(() => {
  'use strict';
  const boardRoot = document.getElementById('productionLive');
  const page = document.querySelector('.production-page.admin-production-polished');
  if (!boardRoot || !page) return;

  const search = document.getElementById('adminProductionSearch');
  const completedWrap = document.getElementById('completedTodayWrap');
  const methodButtons = Array.from(page.querySelectorAll('[data-production-method]'));
  let activeMethod = 'pickup';

  const valueNodes = {
    pending: page.querySelector('[data-production-metric="pending"]'),
    ready: page.querySelector('[data-production-metric="ready"]'),
    overdue: page.querySelector('[data-production-metric="overdue"]'),
    completed: page.querySelector('[data-production-metric="completed"]')
  };

  const countInStage = (name) => {
    const count = boardRoot.querySelector(`.kanban-col[data-stage="${name}"] .col-count`);
    return Number.parseInt(count?.textContent || '0', 10) || 0;
  };

  function activeStageTab() {
    return boardRoot.querySelector('[data-stage-tab].is-active');
  }

  function ensurePendingStageSelected() {
    const pendingTab = boardRoot.querySelector('[data-stage-tab="pending"]');
    if (pendingTab && !pendingTab.classList.contains('is-active')) pendingTab.click();
  }

  function updateMetrics() {
    if (valueNodes.pending) valueNodes.pending.textContent = String(countInStage('pending'));
    if (valueNodes.ready) valueNodes.ready.textContent = String(countInStage('ready'));
    const overdue = document.querySelector('[data-live="overdue"]');
    const overdueCount = Number.parseInt(overdue?.textContent || '0', 10) || 0;
    if (valueNodes.overdue) {
      valueNodes.overdue.textContent = String(overdueCount);
      valueNodes.overdue.classList.toggle('has-overdue', overdueCount > 0);
    }
    if (valueNodes.completed) valueNodes.completed.textContent = String(completedWrap?.querySelectorAll('tbody tr').length || 0);
  }

  function applyMethodButtonState() {
    methodButtons.forEach((button) => {
      const method = String(button.dataset.productionMethod || 'pickup');
      const isActive = method === activeMethod;
      button.classList.toggle('is-active', isActive);
      button.setAttribute('aria-pressed', isActive ? 'true' : 'false');
    });
  }

  function countFramesBy(method, stage) {
    return Array.from(boardRoot.querySelectorAll('.customer-frame')).filter((frame) => {
      return String(frame.dataset.fulfillment || 'pickup') === method && String(frame.dataset.stage || '') === stage;
    }).length;
  }

  function refreshStageTabCounts() {
    const pendingCount = countFramesBy(activeMethod, 'pending');
    const readyCount = countFramesBy(activeMethod, 'ready');
    const outCount = countFramesBy('delivery', 'out_for_delivery');

    boardRoot.querySelectorAll('[data-stage-tab]').forEach((button) => {
      const key = String(button.dataset.stageTab || 'pending');
      const countNode = button.querySelector('em');
      if (!countNode) return;
      if (key === 'pending') countNode.textContent = String(pendingCount);
      else if (key === 'ready') countNode.textContent = String(readyCount);
      else if (key === 'out_for_delivery') countNode.textContent = String(outCount);
    });
  }

  function updateWorkflowForMethod() {
    const isDeliveryMode = activeMethod === 'delivery';
    const outTab = boardRoot.querySelector('[data-stage-tab="out_for_delivery"]');
    const outColumn = boardRoot.querySelector('.kanban-col[data-stage="out_for_delivery"]');
    if (outTab) outTab.hidden = !isDeliveryMode;
    if (outColumn) outColumn.hidden = !isDeliveryMode;

    if (!isDeliveryMode && activeStageTab()?.dataset.stageTab === 'out_for_delivery') {
      ensurePendingStageSelected();
    }
  }

  function filterVisibleGroups() {
    const terms = String(search?.value || '').trim().toLocaleLowerCase().split(/\s+/).filter(Boolean);
    boardRoot.querySelectorAll('.kanban-col').forEach((column) => {
      if (column.hidden) return;
      let visible = 0;
      let total = 0;
      column.querySelectorAll('.production-date-group').forEach((dateGroup) => {
        let groupVisible = 0;
        dateGroup.querySelectorAll('.customer-frame').forEach((frame) => {
          const customer = String(frame.dataset.customer || '');
          const text = `${customer} ${frame.textContent || ''}`.toLocaleLowerCase();
          const fulfillment = String(frame.dataset.fulfillment || 'pickup');
          const methodMatch = fulfillment === activeMethod;
          const searchMatch = !terms.length || terms.every((term) => text.includes(term));
          const match = methodMatch && searchMatch;
          frame.hidden = !match;
          if (match) groupVisible++;
          total++;
        });
        dateGroup.hidden = groupVisible === 0;
        visible += groupVisible;
      });
      const body = column.querySelector('.col-body');
      if (!body) return;
      let empty = body.querySelector('[data-search-empty]');
      if (!empty && total > 0) {
        empty = document.createElement('p');
        empty.className = 'production-search-empty';
        empty.dataset.searchEmpty = '';
        empty.textContent = 'No matching orders in this stage.';
        empty.hidden = true;
        body.appendChild(empty);
      }
      if (empty) empty.hidden = visible > 0 || total === 0;
    });
  }

  function syncBoardUi() {
    applyMethodButtonState();
    updateWorkflowForMethod();
    refreshStageTabCounts();
    filterVisibleGroups();
    updateMetrics();
  }

  // Observe only direct board replacements from the live poller.
  // Watching the entire subtree caused our own count/filter DOM updates to
  // retrigger this observer continuously and could freeze the browser tab.
  const observer = new MutationObserver(syncBoardUi);
  observer.observe(boardRoot, { childList: true });
  if (completedWrap) new MutationObserver(updateMetrics).observe(completedWrap, { childList: true });
  const overduePill = document.querySelector('[data-live="overdue"]');
  if (overduePill) new MutationObserver(updateMetrics).observe(overduePill, { childList: true, characterData: true, subtree: true });

  search?.addEventListener('input', filterVisibleGroups);

  methodButtons.forEach((button) => {
    button.addEventListener('click', () => {
      activeMethod = String(button.dataset.productionMethod || 'pickup');
      ensurePendingStageSelected();
      syncBoardUi();
    });
  });

  boardRoot.addEventListener('click', (event) => {
    const stageButton = event.target.closest('[data-stage-tab]');
    if (stageButton && boardRoot.contains(stageButton)) {
      window.setTimeout(syncBoardUi, 0);
    }
  });

  syncBoardUi();
})();
