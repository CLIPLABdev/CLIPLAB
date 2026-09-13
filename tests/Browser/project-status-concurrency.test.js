'use strict';

const fs = require('fs');
const vm = require('vm');

const scriptPath = process.argv[2];
if (!scriptPath) throw new Error('Project status script path is required.');
const source = fs.readFileSync(scriptPath, 'utf8');

const flush = () => new Promise((resolve) => setImmediate(resolve));

function harness(fetchImplementation) {
  const statusText = { textContent: 'Na fila', className: '' };
  const messageText = { textContent: '' };
  const progressText = { textContent: '0%' };
  const progress = { value: 0, textContent: '0%' };
  const heading = { textContent: 'Projeto teste' };
  const suggestionsLink = { hidden: true, href: '' };
  const elements = new Map([
    ['[data-project-status-text]', statusText],
    ['[data-project-status-message]', messageText],
    ['[data-project-progress-value]', progressText],
    ['[data-project-progress]', progress],
    ['[data-project-suggestions-link]', suggestionsLink],
    ['h3', heading],
  ]);
  let removed = false;
  const card = {
    dataset: { projectStatusUrl: '/api/projects/1/status' },
    querySelector: (selector) => elements.get(selector) || null,
    removeAttribute: () => { removed = true; },
  };
  const liveRegion = { textContent: '' };
  let visibilityChange = null;
  const document = {
    hidden: false,
    querySelectorAll: () => [card],
    querySelector: (selector) => selector === '[data-project-status-live]' ? liveRegion : null,
    addEventListener: (event, listener) => {
      if (event === 'visibilitychange') visibilityChange = listener;
    },
  };
  let timerId = 0;
  const timers = new Map();
  const window = {
    setTimeout: (callback, delay) => {
      const id = ++timerId;
      timers.set(id, { callback, delay });
      return id;
    },
    clearTimeout: (id) => timers.delete(id),
  };
  let fetchCalls = 0;
  let activeFetches = 0;
  let maximumConcurrentFetches = 0;
  const fetch = (...args) => {
    fetchCalls += 1;
    activeFetches += 1;
    maximumConcurrentFetches = Math.max(maximumConcurrentFetches, activeFetches);
    return Promise.resolve(fetchImplementation(...args)).finally(() => { activeFetches -= 1; });
  };
  class AbortController {
    constructor() { this.signal = {}; }
    abort() {}
  }

  vm.runInNewContext(source, { AbortController, Array, Error, Map, Math, Number, Promise, Set, console, document, fetch, window });

  return {
    document,
    visibilityChange: () => visibilityChange,
    fetchCalls: () => fetchCalls,
    maximumConcurrentFetches: () => maximumConcurrentFetches,
    suggestionsLink,
    messageText,
    liveRegion,
    removed: () => removed,
    async runNextPollTimer() {
      const entry = [...timers.entries()].sort((left, right) => left[1].delay - right[1].delay)[0];
      if (!entry) throw new Error('Expected a scheduled polling timer.');
      timers.delete(entry[0]);
      entry[1].callback();
      await flush();
    },
  };
}

async function main() {
  const pending = harness(() => new Promise(() => {}));
  if (typeof pending.visibilityChange() !== 'function') throw new Error('Visibility handler was not registered.');
  if (pending.fetchCalls() !== 1) throw new Error(`Expected initial poll once, received ${pending.fetchCalls()}.`);
  pending.document.hidden = true;
  pending.visibilityChange()();
  pending.document.hidden = false;
  pending.visibilityChange()();
  if (pending.fetchCalls() !== 1 || pending.maximumConcurrentFetches() !== 1) {
    throw new Error(`Concurrent polling detected: calls=${pending.fetchCalls()}, max=${pending.maximumConcurrentFetches()}.`);
  }

  const ready = harness(async () => ({
    ok: true,
    status: 200,
    json: async () => ({ id: 999, status: 'suggestions_ready', stage: 'Sugestões prontas', message: 'Disponíveis.', progress: 100, suggestions_url: '/projetos/44' }),
  }));
  await flush();
  if (ready.suggestionsLink.hidden || ready.suggestionsLink.href !== '/projetos/44') {
    throw new Error('A valid server-projected suggestions URL was not revealed.');
  }
  if (!ready.removed()) throw new Error('Suggestions-ready polling did not stop.');

  for (const unsafe of ['https://evil.test/projetos/44', '//evil.test/projetos/44', '/projetos/44?next=evil', '/projetos/0', '/projetos/44/extra']) {
    const malicious = harness(async () => ({
      ok: true,
      status: 200,
      json: async () => ({ id: 44, status: 'suggestions_ready', stage: 'Sugestões prontas', message: '', progress: 100, suggestions_url: unsafe }),
    }));
    await flush();
    if (!malicious.suggestionsLink.hidden || malicious.suggestionsLink.href !== '') {
      throw new Error(`Unsafe suggestions URL was accepted: ${unsafe}`);
    }
  }

  const notReady = harness(async () => ({
    ok: true,
    status: 200,
    json: async () => ({ id: 44, status: 'analyzing', stage: 'Analisando', message: '', progress: 88, suggestions_url: '/projetos/44' }),
  }));
  await flush();
  if (!notReady.suggestionsLink.hidden) throw new Error('Suggestions link appeared before suggestions_ready.');

  const failing = harness(async () => { throw new Error('offline'); });
  await flush();
  for (let attempt = 1; attempt < 5; attempt += 1) {
    await failing.runNextPollTimer();
  }
  if (!failing.messageText.textContent.includes('Atualize')) {
    throw new Error('Five polling failures did not produce visible recoverable feedback.');
  }
  if (!failing.liveRegion.textContent.includes('atualizar')) {
    throw new Error('Polling failure feedback was not announced.');
  }

  process.stdout.write('ok\n');
}

main().catch((error) => {
  process.stderr.write(String(error && error.message ? error.message : error) + '\n');
  process.exitCode = 1;
});
