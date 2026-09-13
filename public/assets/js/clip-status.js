(() => {
  const cards = Array.from(document.querySelectorAll('[data-clip-status-url]'));
  if (!cards.length) return;

  const statuses = new Set(['suggested', 'approved', 'queued', 'rendering', 'completed', 'failed']);
  const terminal = new Set(['suggested', 'completed', 'failed']);
  const delays = [3000, 5000, 8000, 15000];

  cards.forEach((card) => {
    const rawClipId = card.dataset.clipCard;
    const url = card.dataset.clipStatusUrl;
    if (typeof rawClipId !== 'string' || !/^[1-9][0-9]*$/.test(rawClipId)) return;
    if (url !== '/api/clips/' + rawClipId + '/status') return;

    const clipId = Number(rawClipId);
    const statusText = card.querySelector('[data-clip-status]');
    const messageText = card.querySelector('[data-clip-message]');
    const thumbnail = card.querySelector('[data-clip-thumbnail]');
    const download = card.querySelector('[data-clip-download]');
    if (!Number.isSafeInteger(clipId) || !statusText || !messageText || !thumbnail || !download) return;

    const thumbnailPath = new RegExp('^/clips/' + rawClipId + '/thumbnail$');
    const downloadPath = new RegExp('^/clips/' + rawClipId + '/download$');
    let previousStage = statusText.textContent.trim();
    let failures = 0;
    let timer = null;
    let inFlight = false;
    let controller = null;
    let stopped = false;
    let resumeRequested = false;

    const hideAssets = () => {
      thumbnail.hidden = true;
      thumbnail.removeAttribute('src');
      download.hidden = true;
      download.removeAttribute('href');
    };

    const stop = (feedback = '') => {
      stopped = true;
      card.removeAttribute('data-clip-status-url');
      if (timer !== null) window.clearTimeout(timer);
      timer = null;
      if (feedback) messageText.textContent = feedback;
    };

    const schedule = () => {
      if (stopped || document.hidden || timer !== null) return;
      const delayIndex = failures > 0 ? Math.min(failures - 1, delays.length - 1) : 0;
      timer = window.setTimeout(poll, delays[delayIndex]);
    };

    const poll = async () => {
      timer = null;
      if (stopped || document.hidden || inFlight) return;
      inFlight = true;
      controller = new AbortController();
      const currentController = controller;
      let timedOut = false;
      const timeout = window.setTimeout(() => {
        timedOut = true;
        currentController.abort();
      }, 10000);
      try {
        const response = await fetch(url, {
          credentials: 'same-origin',
          headers: { Accept: 'application/json' },
          cache: 'no-store',
          signal: currentController.signal,
        });
        if (stopped || document.hidden) return;
        if (response.status === 401 || response.status === 404) {
          stop(response.status === 401
            ? 'Sua sessão expirou. Entre novamente para atualizar este corte.'
            : 'Este corte não está mais disponível.');
          return;
        }
        if (!response.ok) throw new Error('clip_status_unavailable');

        const payload = await response.json();
        if (!payload || payload.id !== clipId || typeof payload.status !== 'string' || !statuses.has(payload.status)) {
          throw new Error('clip_status_invalid');
        }
        const nextStage = typeof payload.stage === 'string' && payload.stage.trim() !== ''
          ? payload.stage
          : previousStage;
        const nextMessage = typeof payload.message === 'string' ? payload.message : '';

        if (nextStage !== previousStage) {
          statusText.textContent = nextStage;
          previousStage = nextStage;
        }
        messageText.textContent = nextMessage;
        hideAssets();
        if (payload.status === 'completed'
          && typeof payload.thumbnail_url === 'string'
          && typeof payload.download_url === 'string'
          && thumbnailPath.test(payload.thumbnail_url)
          && downloadPath.test(payload.download_url)
        ) {
          thumbnail.src = payload.thumbnail_url;
          thumbnail.hidden = false;
          download.href = payload.download_url;
          download.hidden = false;
        }

        failures = 0;
        if (terminal.has(payload.status)) {
          stop();
          return;
        }
        schedule();
      } catch (error) {
        const aborted = error && error.name === 'AbortError';
        if (stopped || document.hidden || (aborted && !timedOut)) return;
        failures += 1;
        if (failures >= 5) {
          stop('Não foi possível atualizar automaticamente. Atualize esta página para tentar novamente.');
          return;
        }
        schedule();
      } finally {
        window.clearTimeout(timeout);
        if (controller === currentController) controller = null;
        inFlight = false;
        if (resumeRequested && !stopped && !document.hidden) {
          resumeRequested = false;
          poll();
        }
      }
    };

    document.addEventListener('visibilitychange', () => {
      if (document.hidden) {
        if (timer !== null) window.clearTimeout(timer);
        timer = null;
        if (controller !== null) controller.abort();
        return;
      }
      if (stopped) return;
      if (inFlight) {
        resumeRequested = true;
      } else {
        poll();
      }
    });

    window.addEventListener('pagehide', () => {
      stopped = true;
      if (timer !== null) window.clearTimeout(timer);
      timer = null;
      if (controller !== null) controller.abort();
    });

    poll();
  });
})();
