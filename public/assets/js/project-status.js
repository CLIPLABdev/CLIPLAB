(() => {
  const cards = Array.from(document.querySelectorAll('[data-project-status-url]'));
  const liveRegion = document.querySelector('[data-project-status-live]');
  if (!cards.length || !liveRegion) return;

  const statuses = new Set([
    'receiving', 'queued', 'fetching', 'probing', 'ready',
    'ai_queued', 'uploading_ai', 'waiting_ai_file', 'analyzing',
    'identifying_clips', 'suggestions_ready', 'rendering', 'completed', 'awaiting_credits', 'failed',
  ]);
  const terminal = new Set(['ready', 'suggestions_ready', 'completed', 'awaiting_credits', 'failed']);
  const clipStatuses = new Set(['suggestions_ready', 'rendering', 'completed']);
  const safeSuggestionsPath = /^\/projetos\/[1-9][0-9]*$/;
  const delays = [3000, 5000, 8000, 15000];

  cards.forEach((card) => {
    const url = card.dataset.projectStatusUrl;
    const statusText = card.querySelector('[data-project-status-text]');
    const messageText = card.querySelector('[data-project-status-message]');
    const progressText = card.querySelector('[data-project-progress-value]');
    const progress = card.querySelector('[data-project-progress]');
    const suggestionsLink = card.querySelector('[data-project-suggestions-link]');
    const clipsLink = card.querySelector('[data-project-clips-link]');
    if (!url || !statusText || !messageText || !progressText || !progress) return;

    let timer = null;
    let backoff = 0;
    let errors = 0;
    let stopped = false;
    let inFlight = false;
    let successfulPolls = 0;
    let previousStage = statusText.textContent.trim();

    const stopWithFeedback = (message) => {
      stopped = true;
      card.removeAttribute('data-project-status-url');
      messageText.textContent = message;
      liveRegion.textContent = message;
    };

    const schedule = () => {
      if (stopped || document.hidden) return;
      timer = window.setTimeout(poll, delays[Math.min(backoff, delays.length - 1)]);
    };

    const poll = async () => {
      timer = null;
      if (stopped || document.hidden || inFlight) return;
      inFlight = true;
      const controller = new AbortController();
      const timeout = window.setTimeout(() => controller.abort(), 10000);
      try {
        const response = await fetch(url, {
          credentials: 'same-origin',
          headers: { Accept: 'application/json' },
          cache: 'no-store',
          signal: controller.signal,
        });
        if (response.status === 401 || response.status === 404) {
          stopWithFeedback(response.status === 401
            ? 'Sua sessão expirou. Entre novamente para atualizar o projeto.'
            : 'Este projeto não está mais disponível.');
          return;
        }
        if (!response.ok) throw new Error('status_unavailable');

        const payload = await response.json();
        const nextStage = typeof payload.stage === 'string' ? payload.stage : previousStage;
        const nextStatus = typeof payload.status === 'string' && statuses.has(payload.status) ? payload.status : '';
        const nextProgress = Number.isInteger(payload.progress) ? Math.max(0, Math.min(100, payload.progress)) : Number(progress.value);
        const changed = nextStage !== previousStage || nextProgress !== Number(progress.value);

        statusText.textContent = nextStage;
        messageText.textContent = typeof payload.message === 'string' ? payload.message : '';
        progress.value = nextProgress;
        progress.textContent = `${nextProgress}%`;
        progressText.textContent = `${nextProgress}%`;
        if (nextStatus) statusText.className = `status-pill status-${nextStatus}`;

        if (suggestionsLink
          && nextStatus === 'suggestions_ready'
          && typeof payload.suggestions_url === 'string'
          && safeSuggestionsPath.test(payload.suggestions_url)
        ) {
          suggestionsLink.href = payload.suggestions_url;
          suggestionsLink.hidden = false;
        }

        if (clipsLink && clipStatuses.has(nextStatus) && /^[1-9][0-9]*$/.test(card.dataset.projectId || '')) {
          clipsLink.href = `/clips?projeto=${card.dataset.projectId}`;
          clipsLink.hidden = false;
          const refresh = card.querySelector('.project-refresh-link');
          if (refresh && nextStatus === 'completed') refresh.hidden = true;
        }

        if (nextStage !== previousStage) {
          const heading = card.querySelector('h3');
          liveRegion.textContent = `${heading ? heading.textContent : 'Projeto'}: ${nextStage}.`;
          previousStage = nextStage;
        }
        errors = 0;
        backoff = successfulPolls === 0 || changed ? 0 : Math.min(backoff + 1, delays.length - 1);
        successfulPolls += 1;
        if (terminal.has(nextStatus)) {
          stopped = true;
          card.removeAttribute('data-project-status-url');
          return;
        }
        schedule();
      } catch (error) {
        errors += 1;
        backoff = Math.min(backoff + 1, delays.length - 1);
        if (errors >= 5) {
          stopWithFeedback('Não foi possível atualizar automaticamente. Atualize esta página para tentar novamente.');
          return;
        }
        schedule();
      } finally {
        window.clearTimeout(timeout);
        inFlight = false;
      }
    };

    document.addEventListener('visibilitychange', () => {
      if (document.hidden) {
        if (timer !== null) window.clearTimeout(timer);
        timer = null;
      } else if (!stopped && timer === null && !inFlight) {
        poll();
      }
    });

    poll();
  });
})();
