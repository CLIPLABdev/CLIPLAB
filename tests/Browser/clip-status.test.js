'use strict';

const fs = require('fs');
const vm = require('vm');

const scriptPath = process.argv[2];
if (!scriptPath) throw new Error('Clip status script path is required.');
const source = fs.readFileSync(scriptPath, 'utf8');
const flush = () => new Promise((resolve) => setImmediate(resolve));

function textElement(initial) {
  let value = initial;
  let writes = 0;
  return {
    get textContent() { return value; },
    set textContent(next) { value = String(next); writes += 1; },
    writes: () => writes,
    className: '',
  };
}

function assetElement() {
  return {
    hidden: true,
    href: '',
    src: '',
    removeAttribute(name) {
      if (name === 'href') this.href = '';
      if (name === 'src') this.src = '';
    },
  };
}

function harness(fetchImplementation, initialStage = 'Na fila para renderização') {
  const status = textElement(initialStage);
  const message = textElement('Aguardando.');
  const thumbnail = assetElement();
  const download = assetElement();
  const elements = new Map([
    ['[data-clip-status]', status],
    ['[data-clip-message]', message],
    ['[data-clip-thumbnail]', thumbnail],
    ['[data-clip-download]', download],
    ['h4', { textContent: 'Corte teste' }],
  ]);
  let removed = false;
  const card = {
    dataset: { clipCard: '44', clipStatusUrl: '/api/clips/44/status' },
    querySelector: (selector) => elements.get(selector) || null,
    removeAttribute(name) {
      if (name === 'data-clip-status-url') removed = true;
    },
  };
  let visibilityChange = null;
  const document = {
    hidden: false,
    querySelectorAll: () => [card],
    addEventListener(event, listener) {
      if (event === 'visibilitychange') visibilityChange = listener;
    },
  };
  let pageHide = null;
  let timerId = 0;
  const timers = new Map();
  const scheduled = [];
  const window = {
    setTimeout(callback, delay) {
      const id = ++timerId;
      timers.set(id, { callback, delay });
      scheduled.push(delay);
      return id;
    },
    clearTimeout(id) { timers.delete(id); },
    addEventListener(event, listener) {
      if (event === 'pagehide') pageHide = listener;
    },
  };
  let fetchCalls = 0;
  let activeFetches = 0;
  let maxInflight = 0;
  const fetch = (...args) => {
    fetchCalls += 1;
    activeFetches += 1;
    maxInflight = Math.max(maxInflight, activeFetches);
    return Promise.resolve(fetchImplementation(...args)).finally(() => { activeFetches -= 1; });
  };
  let aborts = 0;
  class AbortController {
    constructor() {
      const listeners = [];
      this.signal = {
        addEventListener(event, listener) {
          if (event === 'abort') listeners.push(listener);
        },
      };
      this.abort = () => {
        aborts += 1;
        listeners.forEach((listener) => listener());
      };
    }
  }

  vm.runInNewContext(source, {
    AbortController, Array, Error, Map, Math, Number, Promise, RegExp, Set, String,
    console, document, fetch, window,
  });

  return {
    document,
    status,
    message,
    thumbnail,
    download,
    fetchCalls: () => fetchCalls,
    maxInflight: () => maxInflight,
    aborts: () => aborts,
    removed: () => removed,
    scheduled: () => scheduled.slice(),
    visibilityChange: () => visibilityChange,
    pageHide: () => pageHide,
    async runTimer(delay) {
      const entry = [...timers.entries()].find(([, timer]) => timer.delay === delay);
      if (!entry) throw new Error('Expected a scheduled timer at ' + delay + 'ms.');
      timers.delete(entry[0]);
      entry[1].callback();
      await flush();
      await flush();
    },
    async runNextPoll() {
      const entry = [...timers.entries()]
        .filter(([, timer]) => timer.delay <= 15000)
        .sort((left, right) => left[1].delay - right[1].delay)[0];
      if (!entry) throw new Error('Expected a scheduled polling timer.');
      timers.delete(entry[0]);
      entry[1].callback();
      await flush();
      await flush();
    },
  };
}

function response(payload) {
  return { ok: true, status: 200, json: async () => payload };
}

async function main() {
  const completed = harness(async () => response({
    id: 44,
    status: 'completed',
    stage: 'Concluído',
    message: 'Pronto.',
    thumbnail_url: '/clips/44/thumbnail',
    download_url: '/clips/44/download',
  }));
  await flush();
  await flush();
  if (completed.download.hidden || completed.download.href !== '/clips/44/download') {
    throw new Error('A valid completed download URL was not revealed.');
  }
  if (completed.thumbnail.hidden || completed.thumbnail.src !== '/clips/44/thumbnail') {
    throw new Error('A valid completed thumbnail URL was not revealed.');
  }
  if (completed.maxInflight() !== 1 || !completed.removed()) {
    throw new Error('Completed polling did not remain single-flight and stop.');
  }

  const unsafeUrls = [
    ['https://evil.test/clips/44/thumbnail', '/clips/44/download'],
    ['//evil.test/clips/44/thumbnail', '/clips/44/download'],
    ['javascript:alert(1)', '/clips/44/download'],
    ['/clips/45/thumbnail', '/clips/44/download'],
    ['/clips/44/thumbnail', 'https://evil.test/clips/44/download'],
    ['/clips/44/thumbnail', '//evil.test/clips/44/download'],
    ['/clips/44/thumbnail', 'javascript:alert(1)'],
    ['/clips/44/thumbnail', '/clips/45/download'],
  ];
  for (const [thumbnailUrl, downloadUrl] of unsafeUrls) {
    const unsafe = harness(async () => response({
      id: 44, status: 'completed', stage: 'Concluído', message: '',
      thumbnail_url: thumbnailUrl, download_url: downloadUrl,
    }));
    await flush();
    await flush();
    if (!unsafe.thumbnail.hidden || !unsafe.download.hidden) {
      throw new Error('Unsafe clip asset URL was accepted: ' + thumbnailUrl + ' ' + downloadUrl);
    }
  }

  const premature = harness(async () => response({
    id: 44, status: 'rendering', stage: 'Renderizando vídeo', message: '',
    thumbnail_url: '/clips/44/thumbnail', download_url: '/clips/44/download',
  }));
  await flush();
  await flush();
  if (!premature.thumbnail.hidden || !premature.download.hidden) {
    throw new Error('Clip assets appeared before completed status.');
  }

  let releasePending;
  const pending = harness(() => new Promise((resolve) => { releasePending = resolve; }));
  if (pending.fetchCalls() !== 1 || typeof pending.pageHide() !== 'function') {
    throw new Error('Initial polling or pagehide handler is missing.');
  }
  pending.pageHide()();
  if (pending.aborts() !== 1 || pending.maxInflight() !== 1) {
    throw new Error('Page hide did not abort the single in-flight request.');
  }
  pending.document.hidden = false;
  if (typeof pending.visibilityChange() === 'function') pending.visibilityChange()();
  if (pending.fetchCalls() !== 1) throw new Error('Polling overlapped an in-flight request.');
  releasePending(response({ id: 44, status: 'completed', stage: 'Concluído', message: '', thumbnail_url: '/clips/44/thumbnail', download_url: '/clips/44/download' }));
  await flush();
  await flush();

  const stages = [
    { id: 44, status: 'rendering', stage: 'Na fila para renderização', message: 'Mensagem nova.', thumbnail_url: null, download_url: null },
    { id: 44, status: 'rendering', stage: 'Renderizando vídeo', message: 'Renderizando.', thumbnail_url: null, download_url: null },
  ];
  const stageChanges = harness(async () => response(stages.shift()));
  await flush();
  await flush();
  if (stageChanges.status.writes() !== 0) throw new Error('ARIA live status changed without a stage change.');
  await stageChanges.runNextPoll();
  if (stageChanges.status.writes() !== 1) throw new Error('A real stage change was not announced exactly once.');

  const failing = harness(async () => { throw new Error('offline'); });
  await flush();
  await flush();
  for (let attempt = 1; attempt < 5; attempt += 1) await failing.runNextPoll();
  const pollDelays = failing.scheduled().filter((delay) => delay !== 10000);
  if (pollDelays.join(',') !== '3000,5000,8000,15000') {
    throw new Error('Unexpected polling delays: ' + pollDelays.join(','));
  }
  if (!failing.message.textContent.includes('Atualize esta página') || !failing.removed()) {
    throw new Error('Five failures did not stop with recoverable visible feedback.');
  }
  if (failing.status.writes() !== 0) {
    throw new Error('Polling failures wrote to the ARIA live stage.');
  }

  const timingOut = harness((url, options) => new Promise((resolve, reject) => {
    options.signal.addEventListener('abort', () => {
      const error = new Error('request timeout');
      error.name = 'AbortError';
      reject(error);
    });
  }));
  for (let attempt = 0; attempt < 5; attempt += 1) {
    await timingOut.runTimer(10000);
    if (attempt < 4) await timingOut.runNextPoll();
  }
  const timeoutBackoff = timingOut.scheduled().filter((delay) => delay !== 10000);
  if (timingOut.fetchCalls() !== 5 || timeoutBackoff.join(',') !== '3000,5000,8000,15000') {
    throw new Error('Timed-out requests did not use the complete retry backoff.');
  }
  if (!timingOut.message.textContent.includes('Atualize esta página') || !timingOut.removed()) {
    throw new Error('Five timed-out requests did not stop with recovery feedback.');
  }

  process.stdout.write('ok\n');
}

main().catch((error) => {
  process.stderr.write(String(error && error.message ? error.message : error) + '\n');
  process.exitCode = 1;
});
