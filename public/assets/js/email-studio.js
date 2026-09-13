(() => {
  const studio = document.querySelector('[data-email-studio]');
  if (!studio) return;
  const cards = [...studio.querySelectorAll('[data-email-card]')];
  const search = studio.querySelector('[data-email-search]');
  const category = studio.querySelector('[data-email-category]');
  const status = studio.querySelector('[data-email-status]');
  const normalize = value => value.normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase().trim();
  const filter = () => {
    const query = normalize(search.value);
    let count = 0;
    cards.forEach(card => {
      const visible = normalize(card.dataset.search).includes(query) && (!category.value || category.value === card.dataset.category) && (!status.value || status.value === card.dataset.status);
      card.hidden = !visible;
      if (visible) count++;
    });
    studio.querySelector('[data-email-count]').textContent = `${count} de ${cards.length} versões`;
    studio.querySelector('[data-email-empty]').hidden = count !== 0;
  };
  if (search && category && status) {
    studio.querySelector('[data-email-filters]').hidden = false;
    search.addEventListener('input', filter);
    category.addEventListener('change', filter);
    status.addEventListener('change', filter);
    studio.querySelector('[data-email-clear]').addEventListener('click', () => {
      search.value = ''; category.value = ''; status.value = ''; filter(); search.focus();
    });
  }
  cards.forEach(card => {
    const detail = card.querySelector('details');
    detail.addEventListener('toggle', () => card.classList.toggle('email-card-expanded', detail.open));
    card.querySelector('[data-email-controls]').hidden = false;
    card.querySelectorAll('[data-email-size]').forEach(button => button.addEventListener('click', () => {
      card.querySelector('[data-email-preview]').dataset.size = button.dataset.emailSize;
      card.querySelectorAll('[data-email-size]').forEach(item => item.setAttribute('aria-pressed', String(item === button)));
    }));
  });
  const event = studio.querySelector('[data-email-event]');
  if (event) {
    const updateVariables = () => studio.querySelectorAll('[data-email-variables]').forEach(group => { group.hidden = group.dataset.emailVariables !== event.value; });
    event.addEventListener('change', updateVariables);
    updateVariables();
  }
})();
