const DEFAULT_DURATION_MS = 90000;
const DEFAULT_MAX_FRAMES = 180;
const DEFAULT_MAX_EDGE = 320;
const WORKER_URL = '/assets/js/reframe-worker.js';
const DECIMAL_TIME = /^(?:0|[1-9][0-9]*)(?:\.[0-9]+)?$/;
const PAGE_COORDINATORS = new WeakMap();
const FALLBACK_COORDINATOR = { owner: null, tail: Promise.resolve() };

const clamp = value => Math.max(0, Math.min(1, value));

export function parseStrictIntegerLimit(raw, defaultValue, minimum, maximum) {
  if (typeof raw !== 'string' || !/^(?:0|[1-9][0-9]*)$/.test(raw)) return defaultValue;
  const value = Number(raw);
  if (!Number.isSafeInteger(value)) return defaultValue;
  return Math.max(minimum, Math.min(maximum, value));
}

export function buildSampleTimes(durationMs, maxFrames) {
  if (!Number.isInteger(durationMs) || durationMs < 1000 || durationMs > 180000
      || !Number.isInteger(maxFrames) || maxFrames < 2 || maxFrames > 180) return [];
  const stepMs = Math.max(500, Math.ceil(durationMs / (maxFrames - 1)));
  const times = [0];
  for (let at = stepMs; at < durationMs; at += stepMs) times.push(at);
  if (times.at(-1) !== durationMs) times.push(durationMs);
  return times;
}

function canonicalCoordinate(value) {
  if (typeof value !== 'number' || !Number.isFinite(value) || value < 0 || value > 1
      || Object.is(value, -0) || String(value).toLowerCase().includes('e')) return false;
  return Math.abs(value * 1000000 - Math.round(value * 1000000)) < 1e-8;
}

export function validateAutoKeyframes(value, durationMs) {
  if (!Array.isArray(value) || value.length < 2 || value.length > 32
      || !Number.isInteger(durationMs) || durationMs < 1000 || durationMs > 180000) return false;
  let previous = -1;
  for (let index = 0; index < value.length; index += 1) {
    const point = value[index];
    if (!point || typeof point !== 'object' || Array.isArray(point)
        || Object.keys(point).join(',') !== 'at_ms,center_x,center_y'
        || !Number.isInteger(point.at_ms) || point.at_ms < 0 || point.at_ms > durationMs
        || point.at_ms <= previous || !canonicalCoordinate(point.center_x)
        || !canonicalCoordinate(point.center_y)) return false;
    previous = point.at_ms;
  }
  return value[0].at_ms === 0 && value.at(-1).at_ms === durationMs;
}

function waitForEvent(target, successType, failureTypes, milliseconds, signal) {
  return new Promise((resolve, reject) => {
    let timer;
    const cleanup = () => {
      clearTimeout(timer);
      target.removeEventListener(successType, success);
      for (const type of failureTypes) target.removeEventListener(type, failure);
      signal?.removeEventListener('abort', aborted);
    };
    const success = () => { cleanup(); resolve(); };
    const failure = () => { cleanup(); reject(new Error('media_unavailable')); };
    const aborted = () => { cleanup(); reject(new Error('aborted')); };
    target.addEventListener(successType, success, { once: true });
    for (const type of failureTypes) target.addEventListener(type, failure, { once: true });
    signal?.addEventListener('abort', aborted, { once: true });
    timer = setTimeout(failure, milliseconds);
    if (signal?.aborted) aborted();
  });
}

function strictTime(value) {
  if (typeof value !== 'string' || !DECIMAL_TIME.test(value)) return null;
  const parsed = Number(value);
  return Number.isFinite(parsed) && parsed >= 0 ? parsed : null;
}

function closeOwned(bitmap) {
  if (!bitmap) return;
  try { bitmap.close(); } catch {}
}

function setStatus(node, message) {
  if (node) node.textContent = message;
}

function pageCoordinator(target) {
  if ((typeof target !== 'object' && typeof target !== 'function') || target === null) {
    return FALLBACK_COORDINATOR;
  }
  let coordinator = PAGE_COORDINATORS.get(target);
  if (!coordinator) {
    coordinator = { owner: null, tail: Promise.resolve() };
    PAGE_COORDINATORS.set(target, coordinator);
  }
  return coordinator;
}

export function enhanceReframeEditor(root, environment = {}) {
  const form = root?.closest?.('form');
  const find = selector => root?.querySelector?.(selector) ?? form?.querySelector?.(selector) ?? null;
  const ratio = find('[data-reframe-ratio]');
  const mode = find('[data-reframe-mode]');
  const focusX = find('[name="focus_x"]');
  const focusY = find('[name="focus_y"]');
  const keyframes = find('[data-reframe-keyframes]');
  const autoOption = find('[data-reframe-auto-option]');
  const previewButton = find('[data-reframe-preview-open]');
  const focusEditButton = find('[data-reframe-focus-edit]');
  const autoButton = find('[data-reframe-auto]');
  const video = find('[data-reframe-preview]');
  const overlay = find('[data-reframe-overlay]');
  const status = find('[data-reframe-status]');
  const startInput = find('[name="start_time"]');
  const endInput = find('[name="end_time"]');
  const documentTarget = environment.document ?? globalThis.document;
  const windowTarget = environment.window ?? globalThis.window;
  const WorkerClass = Object.hasOwn(environment, 'WorkerClass') ? environment.WorkerClass : globalThis.Worker;
  const OffscreenCanvasClass = Object.hasOwn(environment, 'OffscreenCanvasClass')
    ? environment.OffscreenCanvasClass : globalThis.OffscreenCanvas;
  const requestTimeoutMs = environment.requestTimeoutMs ?? 15000;
  const seekTimeoutMs = environment.seekTimeoutMs ?? 10000;
  const limits = {
    duration: parseStrictIntegerLimit(root?.dataset?.reframeMaxDurationMs, DEFAULT_DURATION_MS, 1000, 180000),
    frames: parseStrictIntegerLimit(root?.dataset?.reframeMaxFrames, DEFAULT_MAX_FRAMES, 2, 180),
    edge: parseStrictIntegerLimit(root?.dataset?.reframeMaxEdge, DEFAULT_MAX_EDGE, 64, 320),
  };

  const coordinator = pageCoordinator(documentTarget);
  const control = {};
  let pending = Promise.resolve();
  let currentOperation = null;
  let generation = 0;
  let requestSequence = 0;
  let focusEditing = false;
  root.dataset.reframeBusy = '0';
  const publishBusy = busy => {
    root.dataset.reframeBusy = busy ? '1' : '0';
    root.dispatchEvent(new CustomEvent('reframe:busy', { bubbles: true, detail: { busy } }));
  };

  const normalizeManualFocus = () => {
    if (!focusX || !focusY) return false;
    const normalize = node => {
      const raw = typeof node.value === 'string' ? node.value.trim() : '';
      const value = raw === '' ? NaN : Number(raw);
      node.value = (Number.isFinite(value) ? clamp(value) : 0.5).toFixed(6);
    };
    normalize(focusX);
    normalize(focusY);
    return true;
  };

  const clearFocus = () => {
    if (focusX) focusX.value = '';
    if (focusY) focusY.value = '';
  };
  const clearAutomatic = () => {
    if (keyframes) keyframes.value = '';
    if (autoOption) {
      autoOption.disabled = true;
      autoOption.hidden = true;
    }
  };
  const ensureCropAspect = () => {
    if (ratio && ratio.value === 'original') ratio.value = '9:16';
  };
  const selectOriginal = () => {
    clearAutomatic();
    clearFocus();
    if (ratio) ratio.value = 'original';
    if (mode) mode.value = 'original';
  };
  const selectCenter = () => {
    clearAutomatic();
    clearFocus();
    ensureCropAspect();
    if (mode) mode.value = 'center';
  };
  const selectManual = () => {
    clearAutomatic();
    ensureCropAspect();
    if (normalizeManualFocus() && mode) mode.value = 'manual';
    else if (mode) mode.value = 'center';
  };
  const ensureManualFallback = () => {
    clearAutomatic();
    if (ratio?.value === 'original') selectOriginal();
    else selectManual();
  };

  const renderedVideoRect = () => {
    const videoBounds = video?.getBoundingClientRect?.();
    if (!videoBounds || videoBounds.width <= 0 || videoBounds.height <= 0
        || !Number.isFinite(video?.videoWidth) || !Number.isFinite(video?.videoHeight)
        || video.videoWidth <= 0 || video.videoHeight <= 0) return null;
    const scale = Math.min(videoBounds.width / video.videoWidth, videoBounds.height / video.videoHeight);
    const width = video.videoWidth * scale;
    const height = video.videoHeight * scale;
    return {
      left: videoBounds.left + (videoBounds.width - width) / 2,
      top: videoBounds.top + (videoBounds.height - height) / 2,
      width,
      height,
    };
  };

  const drawOverlay = () => {
    if (!overlay || overlay.hidden) return;
    const context = overlay.getContext?.('2d');
    const bounds = overlay.getBoundingClientRect?.();
    if (!context || !bounds || bounds.width <= 0 || bounds.height <= 0) return;

    const width = Math.max(1, Math.round(bounds.width));
    const height = Math.max(1, Math.round(bounds.height));
    if (overlay.width !== width) overlay.width = width;
    if (overlay.height !== height) overlay.height = height;

    const rendered = renderedVideoRect();
    if (!rendered) return;
    const scaleX = width / bounds.width;
    const scaleY = height / bounds.height;
    const contentLeft = (rendered.left - bounds.left) * scaleX;
    const contentTop = (rendered.top - bounds.top) * scaleY;
    const contentWidth = rendered.width * scaleX;
    const contentHeight = rendered.height * scaleY;

    const targetRatios = { '9:16': 9 / 16, '1:1': 1, '16:9': 16 / 9, '4:5': 4 / 5 };
    const targetRatio = targetRatios[ratio?.value];
    let cropWidth = contentWidth;
    let cropHeight = contentHeight;
    if (targetRatio) {
      if (contentWidth / contentHeight > targetRatio) cropWidth = contentHeight * targetRatio;
      else cropHeight = contentWidth / targetRatio;
    }

    const xFocus = clamp(Number.isFinite(Number(focusX?.value)) ? Number(focusX.value) : 0.5);
    const yFocus = clamp(Number.isFinite(Number(focusY?.value)) ? Number(focusY.value) : 0.5);
    const cropX = contentLeft + Math.max(
      0,
      Math.min(contentWidth - cropWidth, xFocus * contentWidth - cropWidth / 2)
    );
    const cropY = contentTop + Math.max(
      0,
      Math.min(contentHeight - cropHeight, yFocus * contentHeight - cropHeight / 2)
    );
    context.clearRect(0, 0, width, height);
    context.fillStyle = 'rgba(0, 0, 0, 0.42)';
    context.fillRect(contentLeft, contentTop, contentWidth, contentHeight);
    context.clearRect(cropX, cropY, cropWidth, cropHeight);
    context.strokeStyle = '#ffffff';
    context.lineWidth = 2;
    context.strokeRect(cropX, cropY, cropWidth, cropHeight);
  };

  const automaticPlanReady = () => (
    mode?.value === 'auto'
    && Boolean(keyframes?.value)
    && autoOption?.disabled === false
  );
  const statusAfterPreview = () => (
    automaticPlanReady() ? 'Enquadramento inteligente pronto.' : 'Prévia pronta.'
  );
  const setFocusEditing = (active, { announce = true } = {}) => {
    const enabled = Boolean(
      active
      && overlay
      && overlay.hidden === false
      && focusEditButton
      && focusEditButton.disabled === false
    );
    focusEditing = enabled;
    if (focusEditButton) {
      focusEditButton.setAttribute?.('aria-pressed', enabled ? 'true' : 'false');
      focusEditButton.textContent = enabled ? 'Concluir edição de foco' : 'Editar foco na prévia';
    }
    if (overlay) {
      overlay.dataset.focusEditing = enabled ? 'true' : 'false';
      overlay.setAttribute?.('aria-hidden', enabled ? 'false' : 'true');
      overlay.setAttribute?.('tabindex', enabled ? '0' : '-1');
      if ('tabIndex' in overlay) overlay.tabIndex = enabled ? 0 : -1;
      if (enabled) overlay.focus?.();
      else overlay.blur?.();
    }
    if (announce) {
      setStatus(
        status,
        enabled
          ? 'Edição de foco ativa. Use clique, toque ou as setas do teclado.'
          : statusAfterPreview()
      );
    }
  };

  const nextRequestId = prefix => `${prefix}_${++requestSequence}`;
  const stopWorker = (context, sendCancel) => {
    if (!context?.worker) return;
    if (sendCancel) {
      try { context.worker.postMessage({ type: 'cancel', request_id: nextRequestId('cancel') }); } catch {}
    }
    try { context.worker.terminate(); } catch {}
    context.worker = null;
  };
  const cancellationMessage = (reason, operationKind) => {
    if (reason === 'takeover') return operationKind === 'auto'
      ? 'Análise cancelada porque outra prévia foi aberta. Use o foco manual.'
      : 'Prévia cancelada porque outro corte foi aberto.';
    if (reason === 'visibility') return operationKind === 'auto'
      ? 'Análise cancelada porque a página deixou de estar visível. Use o foco manual.'
      : 'Prévia cancelada porque a página deixou de estar visível.';
    if (reason === 'pagehide') return operationKind === 'auto'
      ? 'Análise cancelada porque a página foi fechada. Use o foco manual.'
      : 'Prévia cancelada porque a página foi fechada.';
    if (reason === 'clip') return operationKind === 'auto'
      ? 'Análise cancelada após a troca de corte. Use o foco manual.'
      : 'Prévia cancelada após a troca de corte.';
    if (reason === 'user') return 'Análise cancelada. A alteração de enquadramento foi mantida.';
    return operationKind === 'auto'
      ? 'Análise cancelada. Use o foco manual.'
      : 'Operação de prévia cancelada.';
  };
  const cancelActive = ({ reason = 'cancelled', announce = true } = {}) => {
    const context = currentOperation;
    if (!context) return null;
    generation += 1;
    currentOperation = null;
    context?.controller.abort();
    closeOwned(context?.ownedBitmap);
    if (context) context.ownedBitmap = null;
    stopWorker(context, true);
    if (context.kind === 'auto') ensureManualFallback();
    if (announce) setStatus(status, cancellationMessage(reason, context.kind));
    return context;
  };
  const unloadPreview = () => {
    setFocusEditing(false, { announce: false });
    if (video) {
      try { video.removeAttribute('src'); } catch { video.src = ''; }
      try { video.load(); } catch {}
    }
    if (overlay) overlay.hidden = true;
    if (focusEditButton) focusEditButton.disabled = true;
  };
  const releaseOwnership = () => {
    if (coordinator.owner === control) coordinator.owner = null;
  };
  const cancelAndUnload = reason => {
    const cancelled = cancelActive({ reason, announce: false });
    unloadPreview();
    if (cancelled) setStatus(status, cancellationMessage(reason, cancelled.kind));
    else if (automaticPlanReady()) {
      setStatus(status, 'Enquadramento inteligente pronto. Prévia fechada.');
    }
    else setStatus(status, 'Prévia fechada.');
    releaseOwnership();
    return cancelled;
  };
  control.takeover = () => cancelAndUnload('takeover');

  const revealOverlay = () => {
    if (!overlay) return;
    overlay.hidden = false;
    if (focusEditButton) focusEditButton.disabled = false;
    setFocusEditing(false, { announce: false });
    drawOverlay();
  };

  const loadPreview = async signal => {
    const source = root?.dataset?.sourcePreviewUrl;
    if (!video || typeof source !== 'string' || source === '') throw new Error('media_unavailable');
    if (video.src === source && video.readyState >= 1) {
      revealOverlay();
      setStatus(status, statusAfterPreview());
      return;
    }
    setStatus(status, 'Carregando prévia.');
    video.src = source;
    const loaded = waitForEvent(video, 'loadedmetadata', ['error', 'abort'], seekTimeoutMs, signal);
    video.load();
    await loaded;
    if (!Number.isFinite(video.videoWidth) || !Number.isFinite(video.videoHeight)
        || video.videoWidth <= 0 || video.videoHeight <= 0) throw new Error('media_unavailable');
    revealOverlay();
    setStatus(status, statusAfterPreview());
  };

  const workerRequest = (context, message, transfer, signal, transferred) => new Promise((resolve, reject) => {
    const activeWorker = context.worker;
    if (!activeWorker) {
      reject(new Error('worker_failed'));
      return;
    }
    let timer;
    const cleanup = () => {
      clearTimeout(timer);
      activeWorker.removeEventListener('message', onMessage);
      activeWorker.removeEventListener('error', onError);
      signal?.removeEventListener('abort', onAbort);
    };
    const fail = () => { cleanup(); reject(new Error('worker_failed')); };
    const onAbort = () => { cleanup(); reject(new Error('aborted')); };
    const onError = () => fail();
    const onMessage = event => {
      if (event.data?.request_id !== message.request_id) return;
      if (event.data?.type === 'error') { fail(); return; }
      cleanup();
      resolve(event.data);
    };
    activeWorker.addEventListener('message', onMessage);
    activeWorker.addEventListener('error', onError, { once: true });
    signal?.addEventListener('abort', onAbort, { once: true });
    timer = setTimeout(fail, requestTimeoutMs);
    try {
      activeWorker.postMessage(message, transfer);
      transferred?.();
    } catch {
      fail();
    }
    if (signal?.aborted) onAbort();
  });

  const seek = async (target, signal) => {
    if (Math.abs(video.currentTime - target) < 0.0005 && video.readyState >= 2) return;
    const sought = waitForEvent(video, 'seeked', ['error', 'abort', 'emptied'], seekTimeoutMs, signal);
    video.currentTime = target;
    await sought;
  };

  const sampleBitmap = () => {
    const scale = Math.min(1, limits.edge / Math.max(video.videoWidth, video.videoHeight));
    const width = Math.max(1, Math.round(video.videoWidth * scale));
    const height = Math.max(1, Math.round(video.videoHeight * scale));
    const canvas = new OffscreenCanvasClass(width, height);
    const context = canvas.getContext('2d', { alpha: false });
    if (!context || typeof context.drawImage !== 'function'
        || typeof canvas.transferToImageBitmap !== 'function') throw new Error('capability_missing');
    context.drawImage(video, 0, 0, width, height);
    return canvas.transferToImageBitmap();
  };

  const activateAutomatic = async context => {
    if (root?.dataset?.consentActive !== '1') {
      ensureManualFallback();
      setStatus(status, 'Enquadramento inteligente indisponível sem consentimento ativo.');
      return;
    }
    if (typeof WorkerClass !== 'function' || typeof OffscreenCanvasClass !== 'function') {
      ensureManualFallback();
      setStatus(status, 'Enquadramento inteligente indisponível neste navegador.');
      return;
    }
    const start = strictTime(startInput?.value);
    const end = strictTime(endInput?.value);
    if (start === null || end === null || end <= start) {
      ensureManualFallback();
      setStatus(status, 'Enquadramento inteligente indisponível para este intervalo.');
      return;
    }
    const durationMs = Math.round((end - start) * 1000);
    if (durationMs < 1000 || durationMs > limits.duration) {
      ensureManualFallback();
      setStatus(status, 'Enquadramento inteligente indisponível para este intervalo.');
      return;
    }

    setFocusEditing(false, { announce: false });
    if (ratio?.value === 'original' || mode?.value === 'auto') selectCenter();
    else clearAutomatic();
    const signal = context.controller.signal;
    await loadPreview(signal);
    setStatus(status, 'Analisando enquadramento.');
    context.worker = new WorkerClass(WORKER_URL, { type: 'module' });
    await workerRequest(context, { type: 'initialize', request_id: nextRequestId('initialize') }, [], signal);
    await workerRequest(context, { type: 'start', request_id: nextRequestId('start'), duration_ms: durationMs }, [], signal);
    const times = buildSampleTimes(durationMs, limits.frames);
    for (const atMs of times) {
      await seek(start + atMs / 1000, signal);
      if (signal.aborted) throw new Error('aborted');
      context.ownedBitmap = sampleBitmap();
      await workerRequest(
        context,
        { type: 'frame', request_id: nextRequestId('frame'), bitmap: context.ownedBitmap, timestamp_ms: atMs },
        [context.ownedBitmap],
        signal,
        () => { context.ownedBitmap = null; }
      );
    }
    const finished = await workerRequest(
      context,
      { type: 'finish', request_id: nextRequestId('finish') },
      [],
      signal
    );
    if (finished?.type !== 'finished' || !validateAutoKeyframes(finished.keyframes, durationMs)) {
      throw new Error('invalid_result');
    }
    const serialized = JSON.stringify(finished.keyframes);
    if (serialized.length > 16384 || /\s/.test(serialized)) throw new Error('invalid_result');
    if (currentOperation !== context || generation !== context.generation
        || context.controller.signal.aborted || coordinator.owner !== control) {
      throw new Error('aborted');
    }
    keyframes.value = serialized;
    autoOption.disabled = false;
    autoOption.hidden = false;
    mode.value = 'auto';
    clearFocus();
    setStatus(status, 'Enquadramento inteligente pronto.');
  };

  const queue = (kind, action, onFailure) => {
    const previousOwner = coordinator.owner;
    if (previousOwner && previousOwner !== control) previousOwner.takeover();
    else if (previousOwner === control) cancelActive({ reason: 'replaced' });
    coordinator.owner = control;
    const context = {
      generation: ++generation,
      kind,
      controller: new AbortController(),
      worker: null,
      ownedBitmap: null,
    };
    currentOperation = context;
    const run = coordinator.tail.catch(() => {}).then(async () => {
      if (currentOperation !== context || generation !== context.generation
          || context.controller.signal.aborted || coordinator.owner !== control) return;
      let succeeded = false;
      if (kind === 'auto') publishBusy(true);
      try {
        await action(context);
        succeeded = true;
      } catch {
        if (currentOperation === context && generation === context.generation) onFailure();
      } finally {
        closeOwned(context.ownedBitmap);
        context.ownedBitmap = null;
        stopWorker(context, !succeeded);
        if (kind === 'auto') publishBusy(false);
        if (currentOperation === context) currentOperation = null;
      }
    });
    pending = run.catch(() => {});
    coordinator.tail = pending;
  };

  const applyFocus = (x, y) => {
    if (!focusX || !focusY) return;
    const cancelled = cancelActive({ reason: 'user', announce: false });
    focusX.value = clamp(x).toFixed(6);
    focusY.value = clamp(y).toFixed(6);
    selectManual();
    drawOverlay();
    setStatus(
      status,
      cancelled
        ? 'Análise cancelada. O foco manual foi mantido.'
        : 'Foco manual atualizado.'
    );
  };

  previewButton?.addEventListener('click', () => {
    queue(
      'preview',
      context => loadPreview(context.controller.signal),
      () => {
        ensureManualFallback();
        setStatus(status, 'Prévia indisponível. Use o foco manual.');
      }
    );
  });
  autoButton?.addEventListener('click', () => queue(
    'auto',
    activateAutomatic,
    () => {
      ensureManualFallback();
      setStatus(status, 'Enquadramento inteligente indisponível. Use o foco manual.');
    }
  ));
  focusEditButton?.addEventListener('click', () => {
    if (focusEditButton.disabled || overlay?.hidden) return;
    setFocusEditing(!focusEditing);
  });
  overlay?.addEventListener('pointerdown', event => {
    if (!focusEditing) return;
    const bounds = renderedVideoRect();
    if (!bounds || bounds.width <= 0 || bounds.height <= 0) return;
    overlay.setPointerCapture?.(event.pointerId);
    applyFocus((event.clientX - bounds.left) / bounds.width, (event.clientY - bounds.top) / bounds.height);
  });
  overlay?.addEventListener('keydown', event => {
    if (!focusEditing) return;
    if (event.key === 'Escape') {
      event.preventDefault?.();
      setFocusEditing(false);
      focusEditButton?.focus?.();
      return;
    }
    const movement = event.shiftKey ? 0.05 : 0.01;
    const deltas = {
      ArrowLeft: [-movement, 0], ArrowRight: [movement, 0],
      ArrowUp: [0, -movement], ArrowDown: [0, movement],
    };
    const delta = deltas[event.key];
    if (!delta) return;
    event.preventDefault?.();
    const currentX = Number(focusX?.value);
    const currentY = Number(focusY?.value);
    applyFocus((Number.isFinite(currentX) ? currentX : 0.5) + delta[0],
      (Number.isFinite(currentY) ? currentY : 0.5) + delta[1]);
  });
  mode?.addEventListener('change', () => {
    const requestedMode = mode.value;
    const cancelled = cancelActive({ reason: 'user', announce: false });
    setFocusEditing(false, { announce: false });
    mode.value = requestedMode;
    if (requestedMode === 'original') selectOriginal();
    else if (requestedMode === 'center') selectCenter();
    else if (requestedMode === 'manual') selectManual();
    else if (requestedMode === 'auto' && !autoOption?.disabled && keyframes?.value) clearFocus();
    else selectCenter();
    drawOverlay();
    setStatus(
      status,
      cancelled
        ? 'Análise cancelada. A alteração de enquadramento foi mantida.'
        : 'Modo de enquadramento atualizado.'
    );
  });
  ratio?.addEventListener('change', () => {
    const requestedRatio = ratio.value;
    const modeBeforeCancellation = mode?.value;
    const cancelled = cancelActive({ reason: 'user', announce: false });
    setFocusEditing(false, { announce: false });
    ratio.value = requestedRatio;
    if (requestedRatio === 'original') selectOriginal();
    else if (modeBeforeCancellation === 'manual') selectManual();
    else selectCenter();
    drawOverlay();
    setStatus(
      status,
      cancelled
        ? 'Análise cancelada. A alteração de enquadramento foi mantida.'
        : 'Proporção de saída atualizada.'
    );
  });
  const handleFocusEdit = () => {
    const requestedX = focusX?.value ?? '';
    const requestedY = focusY?.value ?? '';
    const cancelled = cancelActive({ reason: 'user', announce: false });
    if (focusX) focusX.value = requestedX;
    if (focusY) focusY.value = requestedY;
    selectManual();
    drawOverlay();
    setStatus(
      status,
      cancelled
        ? 'Análise cancelada. O foco manual foi mantido.'
        : 'Foco manual atualizado.'
    );
  };
  focusX?.addEventListener('input', handleFocusEdit);
  focusX?.addEventListener('change', handleFocusEdit);
  focusY?.addEventListener('input', handleFocusEdit);
  focusY?.addEventListener('change', handleFocusEdit);

  const normalizeFormState = () => {
    if (ratio?.value === 'original' || mode?.value === 'original') selectOriginal();
    else if (mode?.value === 'center') selectCenter();
    else if (mode?.value === 'manual') selectManual();
    else if (mode?.value === 'auto' && !autoOption?.disabled && keyframes?.value) clearFocus();
    else selectCenter();
  };
  form?.addEventListener?.('submit', () => {
    cancelActive({ reason: 'submit', announce: false });
    normalizeFormState();
  });

  const invalidateInterval = () => {
    const hadCompletedPlan = automaticPlanReady();
    const cancelled = cancelActive({ reason: 'interval', announce: false });
    if (hadCompletedPlan && cancelled?.kind !== 'auto') ensureManualFallback();
    unloadPreview();
    releaseOwnership();
    setStatus(
      status,
      hadCompletedPlan || cancelled?.kind === 'auto'
        ? 'Enquadramento inteligente removido após alteração do intervalo.'
        : 'Intervalo atualizado. Abra a prévia novamente.'
    );
  };
  startInput?.addEventListener('change', invalidateInterval);
  endInput?.addEventListener('change', invalidateInterval);
  root?.addEventListener?.('reframe:clip-change', () => cancelAndUnload('clip'));
  documentTarget?.addEventListener?.('visibilitychange', () => {
    if (documentTarget.visibilityState === 'hidden') cancelAndUnload('visibility');
  });
  windowTarget?.addEventListener?.('pagehide', () => cancelAndUnload('pagehide'));

  normalizeFormState();
  if (previewButton) previewButton.hidden = false;
  if (focusEditButton) {
    focusEditButton.hidden = false;
    focusEditButton.disabled = true;
    focusEditButton.setAttribute?.('aria-pressed', 'false');
  }
  setFocusEditing(false, { announce: false });
  if (autoButton) autoButton.hidden = false;

  return {
    limits,
    whenIdle: () => pending,
    destroy: () => cancelAndUnload('pagehide'),
  };
}

if (typeof document !== 'undefined') {
  for (const root of document.querySelectorAll('[data-reframe-editor]')) {
    enhanceReframeEditor(root);
  }
}
