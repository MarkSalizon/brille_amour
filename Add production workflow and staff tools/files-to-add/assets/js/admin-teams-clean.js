(() => {
  const page = document.querySelector('.team-page');
  if (!page) return;
  const input = document.getElementById('teamSearch');
  const group = document.getElementById('teamGroup');
  const noResults = document.getElementById('teamNoResults');
  const sections = Array.from(page.querySelectorAll('[data-team-section]'));
  const filter = () => {
    const query = (input?.value || '').trim().toLocaleLowerCase();
    const selected = group?.value || 'all';
    let matched = 0;
    sections.forEach((section) => {
      const allowed = selected === 'all' || section.dataset.teamSection === selected;
      let visible = 0;
      section.querySelectorAll('[data-team-row]').forEach((row) => {
        const show = allowed && (!query || row.dataset.teamSearch?.includes(query));
        row.hidden = !show;
        if (show) visible++;
      });
      section.hidden = !allowed || (Boolean(query) && visible === 0);
      matched += visible;
    });
    if (noResults) noResults.hidden = matched > 0;
  };
  input?.addEventListener('input', filter);
  group?.addEventListener('change', filter);
  filter();
})();
