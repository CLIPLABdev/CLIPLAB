import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import { resolve } from 'node:path';

const modulePath = process.argv[2];
if (!modulePath) throw new Error('Usage: node reframe-editor.test.mjs <editor-module>');
const source = await readFile(resolve(modulePath), 'utf8');
const editor = await import(`data:text/javascript;base64,${Buffer.from(source).toString('base64')}`);
const { buildSampleTimes, parseStrictIntegerLimit, validateAutoKeyframes, enhanceReframeEditor } = editor;

class FakeNode extends EventTarget {
  constructor(value = '') {
    super();
    this.value = value;
    this.textContent = value;
    this.hidden = false;
    this.disabled = false;
    this.focused = false;
    this.dataset = {};
    this.attributes = new Map();
  }
  click() { if (!this.disabled) this.dispatchEvent(new Event('click')); }
  focus() { this.focused = true; }
  blur() { this.focused = false; }
  setAttribute(name, value) { this.attributes.set(name, String(value)); }
  removeAttribute(name) { this.attributes.delete(name); if (name === 'src') this.src = ''; }
  hasAttribute(name) { return this.attributes.has(name); }
}

class FakeVideo extends FakeNode {
  constructor(log, {
    loadFailure = false, seekFailure = false, videoWidth = 1920, videoHeight = 1080,
    boxWidth = 320, boxHeight = 180,
  } = {}) {
    super();
    this.log = log;
    this.loadFailure = loadFailure;
    this.seekFailure = seekFailure;
    this.readyState = 0;
    this.videoWidth = videoWidth;
    this.videoHeight = videoHeight;
    this.box = { left: 0, top: 0, width: boxWidth, height: boxHeight };
    this._src = '';
    this._currentTime = 0;
  }
  set src(value) { this._src = value; if (value) this.log.requests.push(value); }
  get src() { return this._src; }
  load() {
    this.log.loads += 1;
    queueMicrotask(() => {
      if (!this.src) return;
      if (this.loadFailure) this.dispatchEvent(new Event('error'));
      else { this.readyState = 1; this.dispatchEvent(new Event('loadedmetadata')); }
    });
  }
  set currentTime(value) {
    this._currentTime = value;
    this.log.seeks.push(value);
    queueMicrotask(() => this.dispatchEvent(new Event(this.seekFailure ? 'error' : 'seeked')));
  }
  get currentTime() { return this._currentTime; }
  getBoundingClientRect() { return { ...this.box }; }
}

class FakeOverlay extends FakeNode {
  constructor(width = 320, height = Math.round(width * 9 / 16)) {
    super();
    this.width = width;
    this.height = height;
    this.box = { left: 0, top: 0, width, height };
    this.draws = 0;
    this.strokes = [];
  }
  getBoundingClientRect() { return { ...this.box }; }
  getContext() {
    return {
      clearRect: () => { this.draws += 1; },
      fillRect: () => { this.draws += 1; },
      strokeRect: (...args) => { this.draws += 1; this.strokes.push(args); },
    };
  }
  setPointerCapture() {}
}

class FakeBitmap {
  constructor(log) { this.log = log; this.closed = false; }
  close() { this.closed = true; this.log.closedBitmaps += 1; }
}

class FakeOffscreenCanvas {
  constructor(width, height) {
    this.width = width;
    this.height = height;
    FakeOffscreenCanvas.created.push([width, height]);
  }
  getContext(kind) {
    assert.equal(kind, '2d');
    return { drawImage: (...args) => FakeOffscreenCanvas.draws.push(args) };
  }
  transferToImageBitmap() { return new FakeBitmap(FakeOffscreenCanvas.log); }
  static reset(log) { this.created = []; this.draws = []; this.log = log; }
}
FakeOffscreenCanvas.reset({ closedBitmaps: 0 });

class FakeWorker extends EventTarget {
  constructor(url, options) {
    super();
    this.url = url;
    this.options = options;
    this.messages = [];
    this.inflight = 0;
    this.maxInflight = 0;
    this.terminated = false;
    FakeWorker.active += 1;
    FakeWorker.peakActive = Math.max(FakeWorker.peakActive, FakeWorker.active);
    FakeWorker.instances.push(this);
  }
  postMessage(message, transfer = []) {
    this.messages.push({ message, transfer });
    if (message.type === 'cancel') return;
    this.inflight += 1;
    this.maxInflight = Math.max(this.maxInflight, this.inflight);
    const respond = () => {
      if (this.terminated) return;
      this.inflight -= 1;
      let response;
      if (FakeWorker.failType === message.type) {
        response = { type: 'error', request_id: message.request_id, code: 'inference_failed' };
      } else if (message.type === 'initialize') {
        response = { type: 'initialized', request_id: message.request_id };
      } else if (message.type === 'start') {
        response = { type: 'started', request_id: message.request_id };
      } else if (message.type === 'frame') {
        response = { type: 'frame_ack', request_id: message.request_id };
      } else if (message.type === 'finish') {
        response = { type: 'finished', request_id: message.request_id, keyframes: FakeWorker.result };
      }
      if (response) this.dispatchEvent(new MessageEvent('message', { data: response }));
    };
    if (FakeWorker.responseDelayMs > 0) setTimeout(respond, FakeWorker.responseDelayMs);
    else queueMicrotask(respond);
  }
  terminate() {
    if (this.terminated) return;
    this.terminated = true;
    FakeWorker.active -= 1;
  }
  static reset() {
    this.instances = [];
    this.failType = null;
    this.responseDelayMs = 0;
    this.active = 0;
    this.peakActive = 0;
    this.result = [
      { at_ms: 0, center_x: 0.25, center_y: 0.5 },
      { at_ms: 2000, center_x: 0.75, center_y: 0.5 },
    ];
  }
}
FakeWorker.reset();

function makeEditor({
  consent = '0', viewport = 768, limits = {}, loadFailure = false, seekFailure = false,
  WorkerClass = FakeWorker, OffscreenCanvasClass = FakeOffscreenCanvas, requestTimeoutMs = 50,
  videoWidth = 1920, videoHeight = 1080, documentTarget: suppliedDocument = null,
  windowTarget: suppliedWindow = null,
} = {}) {
  const log = { requests: [], loads: 0, seeks: [], closedBitmaps: 0 };
  FakeOffscreenCanvas.reset(log);
  const nodes = {
    ratio: new FakeNode('9:16'),
    mode: new FakeNode('manual'),
    focusX: new FakeNode('0.500000'),
    focusY: new FakeNode('0.500000'),
    hidden: new FakeNode(''),
    option: new FakeNode(''),
    previewButton: new FakeNode(''),
    focusButton: new FakeNode('Editar foco na prévia'),
    autoButton: new FakeNode(''),
    video: new FakeVideo(log, {
      loadFailure, seekFailure, videoWidth, videoHeight,
      boxWidth: viewport, boxHeight: Math.round(viewport * 9 / 16),
    }),
    overlay: new FakeOverlay(viewport),
    status: new FakeNode('Prévia ainda não carregada.'),
    start: new FakeNode('10.000'),
    end: new FakeNode('12.000'),
  };
  nodes.previewButton.hidden = true;
  nodes.focusButton.hidden = true;
  nodes.focusButton.disabled = true;
  nodes.autoButton.hidden = true;
  nodes.option.hidden = true;
  nodes.option.disabled = true;
  const selectors = new Map([
    ['[data-reframe-ratio]', nodes.ratio], ['[data-reframe-mode]', nodes.mode],
    ['[name="focus_x"]', nodes.focusX], ['[name="focus_y"]', nodes.focusY],
    ['[data-reframe-keyframes]', nodes.hidden], ['[data-reframe-auto-option]', nodes.option],
    ['[data-reframe-preview-open]', nodes.previewButton], ['[data-reframe-auto]', nodes.autoButton],
    ['[data-reframe-focus-edit]', nodes.focusButton],
    ['[data-reframe-preview]', nodes.video], ['[data-reframe-overlay]', nodes.overlay],
    ['[data-reframe-status]', nodes.status], ['[name="start_time"]', nodes.start],
    ['[name="end_time"]', nodes.end],
  ]);
  const form = new FakeNode();
  form.querySelector = selector => selectors.get(selector) ?? null;
  const root = new FakeNode();
  root.dataset = {
    sourcePreviewUrl: '/clips/41/source-preview',
    consentActive: consent,
    reframeMaxDurationMs: limits.duration ?? '90000',
    reframeMaxFrames: limits.frames ?? '180',
    reframeMaxEdge: limits.edge ?? '320',
    clipId: '41',
  };
  root.querySelector = selector => selectors.get(selector) ?? null;
  root.closest = selector => selector === 'form' ? form : null;

  const documentTarget = suppliedDocument ?? new EventTarget();
  documentTarget.visibilityState = 'visible';
  const windowTarget = suppliedWindow ?? new EventTarget();
  const controller = enhanceReframeEditor(root, {
    WorkerClass,
    OffscreenCanvasClass,
    document: documentTarget,
    window: windowTarget,
    requestTimeoutMs,
    seekTimeoutMs: 50,
  });
  nodes.form = form;
  return { controller, root, nodes, log, documentTarget, windowTarget };
}

async function waitUntil(predicate, timeoutMs = 250) {
  const deadline = Date.now() + timeoutMs;
  while (!predicate()) {
    if (Date.now() >= deadline) throw new Error('Condition was not reached in time.');
    await new Promise(resolveWait => setTimeout(resolveWait, 1));
  }
}

const tests = [];
const test = (name, fn) => tests.push([name, fn]);

test('parses strict integer limits with safe defaults, floors and ceilings', () => {
  assert.equal(parseStrictIntegerLimit('120000', 90000, 1000, 180000), 120000);
  assert.equal(parseStrictIntegerLimit('999999', 90000, 1000, 180000), 180000);
  assert.equal(parseStrictIntegerLimit('1', 90000, 1000, 180000), 1000);
  for (const invalid of ['', ' 180', '+180', '180.0', '1e2', '-1', 'abc']) {
    assert.equal(parseStrictIntegerLimit(invalid, 180, 2, 180), 180);
  }
});

test('builds exact sampling with endpoints, at most 180 frames and steps at least 500ms', () => {
  assert.deepEqual(buildSampleTimes(1000, 180), [0, 500, 1000]);
  for (const [duration, maxFrames] of [[2000, 3], [90000, 180], [180000, 180], [12345, 17]]) {
    const times = buildSampleTimes(duration, maxFrames);
    assert.equal(times[0], 0);
    assert.equal(times.at(-1), duration);
    assert.ok(times.length <= maxFrames);
    for (let index = 1; index < times.length - 1; index += 1) {
      assert.ok(times[index] - times[index - 1] >= 500);
    }
  }
});

test('uses safe DOM defaults and ceilings when projected limits are malformed or extreme', () => {
  const invalid = makeEditor({ limits: { duration: '90.0', frames: ' 9', edge: '+320' } });
  assert.deepEqual(invalid.controller.limits, { duration: 90000, frames: 180, edge: 320 });
  const extreme = makeEditor({ limits: { duration: '999999', frames: '999', edge: '999' } });
  assert.deepEqual(extreme.controller.limits, { duration: 180000, frames: 180, edge: 320 });
});

test('does no network, Worker, vendor or model work on load at 320, 768 and 1440 widths', () => {
  FakeWorker.reset();
  for (const viewport of [320, 768, 1440]) {
    const { nodes, log } = makeEditor({ viewport });
    assert.deepEqual(log.requests, []);
    assert.equal(FakeWorker.instances.length, 0);
    assert.equal(nodes.previewButton.hidden, false);
    assert.equal(nodes.focusButton.hidden, false);
    assert.equal(nodes.focusButton.disabled, true);
    assert.equal(nodes.autoButton.hidden, false);
    assert.equal(nodes.overlay.width, viewport);
  }
});

test('manual preview without consent requests only data-source-preview-url and no worker assets', async () => {
  FakeWorker.reset();
  const { controller, nodes, log } = makeEditor({ consent: '0' });
  nodes.previewButton.click();
  await controller.whenIdle();
  assert.deepEqual(log.requests, ['/clips/41/source-preview']);
  assert.equal(nodes.video.src, '/clips/41/source-preview');
  assert.equal(nodes.overlay.hidden, false);
  assert.equal(FakeWorker.instances.length, 0);
  assert.equal(log.requests.some(url => /worker|vendor|model|wasm/i.test(url)), false);
});

test('auto without exact active consent creates no Worker and preserves numeric manual fallback', async () => {
  for (const consent of ['0', 'true', ' 1', '']) {
    FakeWorker.reset();
    const { controller, nodes, log } = makeEditor({ consent });
    nodes.autoButton.click();
    await controller.whenIdle();
    assert.equal(FakeWorker.instances.length, 0);
    assert.deepEqual(log.requests, []);
    assert.equal(nodes.hidden.value, '');
    assert.equal(nodes.option.disabled, true);
    assert.equal(nodes.option.hidden, true);
    assert.equal(nodes.mode.value, 'manual');
    assert.equal(nodes.focusX.value, '0.500000');
    assert.equal(nodes.focusY.value, '0.500000');
  }
});

test('automatic detection publishes busy state until all sampling finishes', async () => {
  FakeWorker.reset();
  const current=makeEditor({consent:'1'});
  const states=[];
  current.root.addEventListener('reframe:busy',event=>states.push(event.detail.busy));
  current.nodes.autoButton.click();
  await current.controller.whenIdle();
  assert.deepEqual(states,[true,false]);
  assert.equal(current.root.dataset.reframeBusy,'0');
});

test('consented auto samples serially at source-relative times, scales to edge and emits backend-safe JSON', async () => {
  FakeWorker.reset();
  const { controller, nodes, log } = makeEditor({ consent: '1', limits: { frames: '5', edge: '320' } });
  nodes.autoButton.click();
  await controller.whenIdle();

  assert.deepEqual(log.requests, ['/clips/41/source-preview']);
  assert.deepEqual(log.seeks, [10, 10.5, 11, 11.5, 12]);
  assert.deepEqual(FakeOffscreenCanvas.created, Array(5).fill([320, 180]));
  assert.equal(FakeWorker.instances.length, 1);
  const worker = FakeWorker.instances[0];
  assert.equal(worker.url, '/assets/js/reframe-worker.js');
  assert.deepEqual(worker.options, { type: 'module' });
  assert.equal(worker.maxInflight, 1);
  assert.deepEqual(worker.messages.filter(entry => entry.message.type === 'frame').map(entry => entry.message.timestamp_ms), [0, 500, 1000, 1500, 2000]);
  assert.ok(worker.messages.filter(entry => entry.message.type === 'frame').every(entry => entry.transfer.length === 1));
  assert.equal(nodes.hidden.value, '[{"at_ms":0,"center_x":0.25,"center_y":0.5},{"at_ms":2000,"center_x":0.75,"center_y":0.5}]');
  assert.deepEqual(JSON.parse(nodes.hidden.value), FakeWorker.result);
  assert.equal(nodes.option.disabled, false);
  assert.equal(nodes.option.hidden, false);
  assert.equal(nodes.mode.value, 'auto');
  assert.equal(nodes.focusX.value, '');
  assert.equal(nodes.focusY.value, '');
  assert.equal(worker.terminated, true);
});

test('rejects noncanonical worker results, zero tracks and worker failures with one accessible fallback message', async () => {
  const badResults = [
    [],
    [{ at_ms: 0, center_x: 0.5, center_y: 0.5 }],
    [{ at_ms: 0, center_x: -0, center_y: 0.5 }, { at_ms: 2000, center_x: 0.5, center_y: 0.5 }],
    [{ at_ms: 0, center_x: 0.1234567, center_y: 0.5 }, { at_ms: 2000, center_x: 0.5, center_y: 0.5 }],
    [{ at_ms: 0, center_x: 0.5, center_y: 0.5, source: 'private' }, { at_ms: 2000, center_x: 0.5, center_y: 0.5 }],
    [{ at_ms: 0, center_x: 0.5, center_y: 0.5 }, { at_ms: 1999, center_x: 0.5, center_y: 0.5 }],
  ];
  for (const result of badResults) {
    FakeWorker.reset();
    FakeWorker.result = result;
    const { controller, nodes } = makeEditor({ consent: '1' });
    nodes.autoButton.click();
    await controller.whenIdle();
    assert.equal(nodes.hidden.value, '');
    assert.equal(nodes.option.disabled, true);
    assert.equal(nodes.option.hidden, true);
    assert.equal(nodes.mode.value, 'manual');
    assert.match(nodes.status.textContent, /indisponível/i);
  }
});

test('codec, load, seek, capability and worker timeout failures retain manual mode', async () => {
  const scenarios = [
    { loadFailure: true },
    { seekFailure: true },
    { WorkerClass: null },
    { OffscreenCanvasClass: null },
  ];
  for (const scenario of scenarios) {
    FakeWorker.reset();
    const { controller, nodes } = makeEditor({ consent: '1', ...scenario });
    nodes.autoButton.click();
    await controller.whenIdle();
    assert.equal(nodes.mode.value, 'manual');
    assert.equal(nodes.hidden.value, '');
    assert.equal(nodes.option.disabled, true);
  }

  class SilentWorker extends FakeWorker { postMessage(message, transfer = []) { this.messages.push({ message, transfer }); } }
  const timed = makeEditor({ consent: '1', WorkerClass: SilentWorker, requestTimeoutMs: 5 });
  timed.nodes.autoButton.click();
  await timed.controller.whenIdle();
  assert.equal(timed.nodes.mode.value, 'manual');
  assert.equal(timed.nodes.hidden.value, '');
});

test('closes a bitmap still owned by the editor when frame transfer fails', async () => {
  class ThrowingWorker extends FakeWorker {
    postMessage(message, transfer = []) {
      if (message.type === 'frame') throw new Error('transfer failed');
      super.postMessage(message, transfer);
    }
  }
  FakeWorker.reset();
  const current = makeEditor({ consent: '1', WorkerClass: ThrowingWorker });
  current.nodes.autoButton.click();
  await current.controller.whenIdle();
  assert.equal(current.log.closedBitmaps, 1);
  assert.equal(current.nodes.mode.value, 'manual');
  assert.equal(current.nodes.hidden.value, '');
});

test('manual overlay only captures pointer and keyboard while its accessible edit mode is active', async () => {
  for (const viewport of [320, 768, 1440]) {
    const { controller, nodes } = makeEditor({ viewport });
    nodes.previewButton.click();
    await controller.whenIdle();
    assert.ok(nodes.overlay.draws > 0);
    assert.ok(nodes.overlay.strokes.at(-1)[0] > viewport * 0.3);
    assert.equal(nodes.focusButton.disabled, false);
    assert.equal(nodes.focusButton.attributes.get('aria-pressed'), 'false');
    assert.equal(nodes.overlay.attributes.get('aria-hidden'), 'true');
    assert.equal(nodes.overlay.attributes.get('tabindex'), '-1');

    const pointer = new Event('pointerdown');
    Object.defineProperties(pointer, { clientX: { value: viewport * 0.25 }, clientY: { value: nodes.overlay.height * 0.75 }, pointerId: { value: 1 } });
    nodes.overlay.dispatchEvent(pointer);
    assert.equal(nodes.focusX.value, '0.500000');
    assert.equal(nodes.focusY.value, '0.500000');

    nodes.focusButton.click();
    assert.equal(nodes.focusButton.attributes.get('aria-pressed'), 'true');
    assert.equal(nodes.overlay.dataset.focusEditing, 'true');
    assert.equal(nodes.overlay.attributes.get('aria-hidden'), 'false');
    assert.equal(nodes.overlay.attributes.get('tabindex'), '0');
    assert.equal(nodes.overlay.focused, true);

    nodes.overlay.dispatchEvent(pointer);
    assert.equal(nodes.focusX.value, '0.250000');
    assert.equal(nodes.focusY.value, '0.750000');
    assert.ok(nodes.overlay.strokes.at(-1)[0] > viewport * 0.08);
    assert.ok(nodes.overlay.strokes.at(-1)[0] < viewport * 0.11);
    const right = new Event('keydown');
    Object.defineProperties(right, { key: { value: 'ArrowRight' }, shiftKey: { value: true } });
    nodes.overlay.dispatchEvent(right);
    assert.equal(nodes.focusX.value, '0.300000');
    const left = new Event('keydown');
    Object.defineProperties(left, { key: { value: 'ArrowLeft' }, shiftKey: { value: false } });
    nodes.overlay.dispatchEvent(left);
    assert.equal(nodes.focusX.value, '0.290000');
    for (let index = 0; index < 100; index += 1) nodes.overlay.dispatchEvent(right);
    assert.equal(nodes.focusX.value, '1.000000');

    const escape = new Event('keydown');
    Object.defineProperty(escape, 'key', { value: 'Escape' });
    nodes.overlay.dispatchEvent(escape);
    assert.equal(nodes.focusButton.attributes.get('aria-pressed'), 'false');
    assert.equal(nodes.overlay.dataset.focusEditing, 'false');
    assert.equal(nodes.overlay.attributes.get('aria-hidden'), 'true');
    assert.equal(nodes.overlay.attributes.get('tabindex'), '-1');
    assert.equal(nodes.overlay.focused, false);

    nodes.overlay.dispatchEvent(pointer);
    assert.equal(nodes.focusX.value, '1.000000');
    assert.equal(nodes.focusY.value, '0.750000');
  }
});

test('maps pointer focus and crop overlay to the rendered video content instead of letterbox bars', async () => {
  const { controller, nodes } = makeEditor({
    viewport: 320,
    videoWidth: 1080,
    videoHeight: 1920,
  });
  nodes.previewButton.click();
  await controller.whenIdle();
  nodes.focusButton.click();

  const renderedWidth = 180 * (1080 / 1920);
  const renderedLeft = (320 - renderedWidth) / 2;
  const pointer = new Event('pointerdown');
  Object.defineProperties(pointer, {
    clientX: { value: renderedLeft + renderedWidth * 0.25 },
    clientY: { value: 180 * 0.75 },
    pointerId: { value: 2 },
  });
  nodes.overlay.dispatchEvent(pointer);

  assert.equal(nodes.focusX.value, '0.250000');
  assert.equal(nodes.focusY.value, '0.750000');
  const [cropX, cropY, cropWidth, cropHeight] = nodes.overlay.strokes.at(-1);
  assert.ok(Math.abs(cropX - renderedLeft) < 0.01);
  assert.ok(Math.abs(cropY) < 0.01);
  assert.ok(Math.abs(cropWidth - renderedWidth) < 0.01);
  assert.ok(Math.abs(cropHeight - 180) < 0.01);
});

test('center and original selections clear incompatible focus and automatic keyframes', () => {
  const { nodes } = makeEditor();
  nodes.hidden.value = '[{"at_ms":0,"center_x":0.25,"center_y":0.5},{"at_ms":2000,"center_x":0.75,"center_y":0.5}]';
  nodes.option.disabled = false;
  nodes.option.hidden = false;
  nodes.focusX.value = '0.250000';
  nodes.focusY.value = '0.750000';

  nodes.mode.value = 'center';
  nodes.mode.dispatchEvent(new Event('change'));
  assert.equal(nodes.ratio.value, '9:16');
  assert.equal(nodes.mode.value, 'center');
  assert.equal(nodes.focusX.value, '');
  assert.equal(nodes.focusY.value, '');
  assert.equal(nodes.hidden.value, '');

  nodes.focusX.value = '0.125000';
  nodes.focusY.value = '0.875000';
  nodes.mode.value = 'original';
  nodes.mode.dispatchEvent(new Event('change'));
  assert.equal(nodes.ratio.value, 'original');
  assert.equal(nodes.mode.value, 'original');
  assert.equal(nodes.focusX.value, '');
  assert.equal(nodes.focusY.value, '');
  assert.equal(nodes.hidden.value, '');
});

test('aspect and focus transitions always leave a backend-valid non-automatic state', () => {
  const { nodes } = makeEditor();
  nodes.ratio.value = 'original';
  nodes.mode.value = 'original';
  nodes.focusX.value = '';
  nodes.focusY.value = '';

  nodes.ratio.value = '1:1';
  nodes.ratio.dispatchEvent(new Event('change'));
  assert.equal(nodes.mode.value, 'center');
  assert.equal(nodes.focusX.value, '');
  assert.equal(nodes.focusY.value, '');

  nodes.mode.value = 'manual';
  nodes.mode.dispatchEvent(new Event('change'));
  assert.equal(nodes.mode.value, 'manual');
  assert.equal(nodes.focusX.value, '0.500000');
  assert.equal(nodes.focusY.value, '0.500000');

  nodes.ratio.value = 'original';
  nodes.ratio.dispatchEvent(new Event('change'));
  assert.equal(nodes.mode.value, 'original');
  assert.equal(nodes.focusX.value, '');
  assert.equal(nodes.focusY.value, '');

  nodes.focusX.value = '0.2';
  nodes.focusX.dispatchEvent(new Event('input'));
  assert.equal(nodes.ratio.value, '9:16');
  assert.equal(nodes.mode.value, 'manual');
  assert.equal(nodes.focusX.value, '0.200000');
  assert.equal(nodes.focusY.value, '0.500000');
  assert.equal(nodes.hidden.value, '');
});

test('rapid automatic activation is single-flight and stale cleanup cannot overwrite the latest result', async () => {
  FakeWorker.reset();
  FakeWorker.responseDelayMs = 8;
  const current = makeEditor({ consent: '1', requestTimeoutMs: 100 });

  current.nodes.autoButton.click();
  await waitUntil(() => FakeWorker.instances.length === 1);
  current.nodes.autoButton.click();
  await current.controller.whenIdle();

  assert.equal(current.nodes.mode.value, 'auto');
  assert.notEqual(current.nodes.hidden.value, '');
  assert.equal(FakeWorker.peakActive, 1);
  assert.equal(
    FakeWorker.instances.flatMap(instance => instance.messages).filter(entry => entry.message.type === 'finish').length,
    1
  );
  assert.ok(FakeWorker.instances.every(instance => instance.terminated));
});

test('double-clicking preview coalesces stale queued work into one private source load', async () => {
  const current = makeEditor();
  current.nodes.previewButton.click();
  current.nodes.previewButton.click();
  await current.controller.whenIdle();

  assert.deepEqual(current.log.requests, ['/clips/41/source-preview']);
  assert.equal(current.log.loads, 1);
  assert.equal(current.nodes.status.textContent, 'Prévia pronta.');
});

test('starting automatic analysis on another card cancels and awaits the prior card page-wide', async () => {
  FakeWorker.reset();
  FakeWorker.responseDelayMs = 8;
  const documentTarget = new EventTarget();
  documentTarget.visibilityState = 'visible';
  const windowTarget = new EventTarget();
  const first = makeEditor({ consent: '1', requestTimeoutMs: 100, documentTarget, windowTarget });
  const second = makeEditor({ consent: '1', requestTimeoutMs: 100, documentTarget, windowTarget });
  second.root.dataset.sourcePreviewUrl = '/clips/42/source-preview';

  first.nodes.autoButton.click();
  await waitUntil(() => FakeWorker.instances.length === 1);
  second.nodes.autoButton.click();
  await Promise.all([first.controller.whenIdle(), second.controller.whenIdle()]);

  const firstWorker = FakeWorker.instances[0];
  assert.equal(firstWorker.terminated, true);
  assert.ok(firstWorker.messages.some(entry => entry.message.type === 'cancel'));
  assert.equal(first.nodes.video.src, '');
  assert.equal(first.nodes.hidden.value, '');
  assert.match(first.nodes.status.textContent, /cancelada/i);
  assert.doesNotMatch(first.nodes.status.textContent, /analisando|pronto/i);
  assert.deepEqual(second.log.requests, ['/clips/42/source-preview']);
  assert.equal(second.nodes.video.src, '/clips/42/source-preview');
  assert.equal(second.nodes.mode.value, 'auto');
  assert.notEqual(second.nodes.hidden.value, '');
  assert.equal(second.nodes.status.textContent, 'Enquadramento inteligente pronto.');
  assert.equal(FakeWorker.peakActive, 1);
});

test('completed automatic plan survives preview and automatic work on another card', async () => {
  FakeWorker.reset();
  const documentTarget = new EventTarget();
  documentTarget.visibilityState = 'visible';
  const windowTarget = new EventTarget();
  const first = makeEditor({ consent: '1', documentTarget, windowTarget });
  const second = makeEditor({ consent: '1', documentTarget, windowTarget });
  second.root.dataset.sourcePreviewUrl = '/clips/42/source-preview';

  first.nodes.autoButton.click();
  await first.controller.whenIdle();
  const completedKeyframes = first.nodes.hidden.value;
  assert.equal(first.nodes.mode.value, 'auto');
  assert.notEqual(completedKeyframes, '');

  second.nodes.previewButton.click();
  await second.controller.whenIdle();
  assert.equal(first.nodes.video.src, '');
  assert.equal(first.nodes.mode.value, 'auto');
  assert.equal(first.nodes.hidden.value, completedKeyframes);
  assert.equal(first.nodes.option.disabled, false);
  assert.equal(first.nodes.option.hidden, false);
  assert.equal(first.nodes.status.textContent, 'Enquadramento inteligente pronto. Prévia fechada.');

  second.nodes.autoButton.click();
  await second.controller.whenIdle();
  assert.equal(first.nodes.mode.value, 'auto');
  assert.equal(first.nodes.hidden.value, completedKeyframes);
  assert.equal(second.nodes.mode.value, 'auto');
  assert.notEqual(second.nodes.hidden.value, '');
});

test('mode ratio and focus edits abort active analysis and stale results cannot replace the user choice', async () => {
  const scenarios = [
    {
      name: 'center mode',
      change: nodes => {
        nodes.mode.value = 'center';
        nodes.mode.dispatchEvent(new Event('change'));
      },
      expected: { ratio: '9:16', mode: 'center', x: '', y: '' },
    },
    {
      name: 'original mode',
      change: nodes => {
        nodes.mode.value = 'original';
        nodes.mode.dispatchEvent(new Event('change'));
      },
      expected: { ratio: 'original', mode: 'original', x: '', y: '' },
    },
    {
      name: 'square ratio',
      prepare: nodes => {
        nodes.mode.value = 'center';
        nodes.mode.dispatchEvent(new Event('change'));
      },
      change: nodes => {
        nodes.ratio.value = '1:1';
        nodes.ratio.dispatchEvent(new Event('change'));
      },
      expected: { ratio: '1:1', mode: 'center', x: '', y: '' },
    },
    {
      name: 'manual focus',
      change: nodes => {
        nodes.focusX.value = '0.200000';
        nodes.focusX.dispatchEvent(new Event('input'));
      },
      expected: { ratio: '9:16', mode: 'manual', x: '0.200000', y: '0.500000' },
    },
  ];

  for (const scenario of scenarios) {
    FakeWorker.reset();
    FakeWorker.responseDelayMs = 20;
    const current = makeEditor({ consent: '1', requestTimeoutMs: 100 });
    scenario.prepare?.(current.nodes);
    current.nodes.autoButton.click();
    await waitUntil(() => FakeWorker.instances.length === 1);

    scenario.change(current.nodes);
    await current.controller.whenIdle();

    const worker = FakeWorker.instances[0];
    assert.equal(worker.terminated, true, scenario.name);
    assert.ok(worker.messages.some(entry => entry.message.type === 'cancel'), scenario.name);
    assert.equal(current.nodes.ratio.value, scenario.expected.ratio, scenario.name);
    assert.equal(current.nodes.mode.value, scenario.expected.mode, scenario.name);
    assert.equal(current.nodes.focusX.value, scenario.expected.x, scenario.name);
    assert.equal(current.nodes.focusY.value, scenario.expected.y, scenario.name);
    assert.equal(current.nodes.hidden.value, '', scenario.name);
    assert.match(current.nodes.status.textContent, /cancelada/i, scenario.name);
    assert.doesNotMatch(current.nodes.status.textContent, /analisando|pronto/i, scenario.name);
  }
});

test('clip/start/end changes, hidden visibility and pagehide abort work, cancel worker and unload preview', async () => {
  for (const eventName of ['clip', 'start', 'end', 'visibility', 'pagehide']) {
    FakeWorker.reset();
    class SlowWorker extends FakeWorker {
      postMessage(message, transfer = []) {
        this.messages.push({ message, transfer });
        if (message.type !== 'frame') return super.postMessage(message, transfer);
        this.lastBitmap = transfer[0];
      }
    }
    const current = makeEditor({ consent: '1', WorkerClass: SlowWorker, requestTimeoutMs: 10 });
    current.nodes.autoButton.click();
    await new Promise(resolve => setTimeout(resolve, 5));
    if (eventName === 'clip') current.root.dispatchEvent(new Event('reframe:clip-change'));
    if (eventName === 'start') current.nodes.start.dispatchEvent(new Event('change'));
    if (eventName === 'end') current.nodes.end.dispatchEvent(new Event('change'));
    if (eventName === 'visibility') {
      current.documentTarget.visibilityState = 'hidden';
      current.documentTarget.dispatchEvent(new Event('visibilitychange'));
    }
    if (eventName === 'pagehide') current.windowTarget.dispatchEvent(new Event('pagehide'));
    await current.controller.whenIdle();
    const worker = FakeWorker.instances.at(-1);
    assert.equal(worker.terminated, true);
    assert.ok(worker.messages.some(entry => entry.message.type === 'cancel'));
    assert.equal(current.nodes.video.src, '');
    assert.equal(current.nodes.hidden.value, '');
    if (eventName === 'start' || eventName === 'end') {
      assert.match(current.nodes.status.textContent, /intervalo/i);
    } else {
      assert.match(current.nodes.status.textContent, /cancelada/i);
    }
    assert.doesNotMatch(current.nodes.status.textContent, /analisando|pronto/i);
  }
});

test('visibility pagehide and card takeover unload preview without erasing a completed automatic plan', async () => {
  for (const eventName of ['clip', 'visibility', 'pagehide']) {
    FakeWorker.reset();
    const current = makeEditor({ consent: '1' });
    current.nodes.autoButton.click();
    await current.controller.whenIdle();
    const completedKeyframes = current.nodes.hidden.value;

    if (eventName === 'clip') current.root.dispatchEvent(new Event('reframe:clip-change'));
    if (eventName === 'visibility') {
      current.documentTarget.visibilityState = 'hidden';
      current.documentTarget.dispatchEvent(new Event('visibilitychange'));
    }
    if (eventName === 'pagehide') current.windowTarget.dispatchEvent(new Event('pagehide'));

    assert.equal(current.nodes.video.src, '', eventName);
    assert.equal(current.nodes.mode.value, 'auto', eventName);
    assert.equal(current.nodes.hidden.value, completedKeyframes, eventName);
    assert.equal(current.nodes.option.disabled, false, eventName);
    assert.equal(current.nodes.option.hidden, false, eventName);
    assert.equal(
      current.nodes.status.textContent,
      'Enquadramento inteligente pronto. Prévia fechada.',
      eventName
    );
  }
});

test('timing and ratio changes clear a completed auto result to their safe fallback mode', async () => {
  for (const [trigger, expectedMode] of [['start', 'manual'], ['end', 'manual'], ['ratio', 'center']]) {
    FakeWorker.reset();
    const current = makeEditor({ consent: '1' });
    current.nodes.autoButton.click();
    await current.controller.whenIdle();
    assert.equal(current.nodes.mode.value, 'auto');
    assert.notEqual(current.nodes.hidden.value, '');

    if (trigger === 'start') current.nodes.start.dispatchEvent(new Event('change'));
    else if (trigger === 'end') current.nodes.end.dispatchEvent(new Event('change'));
    else {
      current.nodes.ratio.value = '1:1';
      current.nodes.ratio.dispatchEvent(new Event('change'));
    }
    assert.equal(current.nodes.mode.value, expectedMode);
    assert.equal(current.nodes.focusX.value, expectedMode === 'manual' ? '0.500000' : '');
    assert.equal(current.nodes.focusY.value, expectedMode === 'manual' ? '0.500000' : '');
    assert.equal(current.nodes.hidden.value, '');
    assert.equal(current.nodes.option.disabled, true);
    assert.equal(current.nodes.option.hidden, true);
    assert.doesNotMatch(current.nodes.status.textContent, /analisando|pronto/i);
    if (trigger === 'start' || trigger === 'end') {
      assert.match(current.nodes.status.textContent, /intervalo/i);
    }
  }
});

test('direct focus input and change edits invalidate completed auto and normalize both manual coordinates', async () => {
  const cases = [
    { field: 'focusX', event: 'input', value: '1.5', x: '1.000000', y: '0.500000' },
    { field: 'focusY', event: 'change', value: '-0.25', x: '0.500000', y: '0.000000' },
    { field: 'focusX', event: 'input', value: '', x: '0.500000', y: '0.500000' },
    { field: 'focusY', event: 'input', value: '0.625', x: '0.500000', y: '0.625000' },
  ];
  for (const scenario of cases) {
    FakeWorker.reset();
    const current = makeEditor({ consent: '1' });
    current.nodes.autoButton.click();
    await current.controller.whenIdle();
    assert.equal(current.nodes.mode.value, 'auto');
    assert.notEqual(current.nodes.hidden.value, '');
    const drawsBefore = current.nodes.overlay.draws;

    current.nodes[scenario.field].value = scenario.value;
    current.nodes[scenario.field].dispatchEvent(new Event(scenario.event));

    assert.equal(current.nodes.mode.value, 'manual');
    assert.equal(current.nodes.focusX.value, scenario.x);
    assert.equal(current.nodes.focusY.value, scenario.y);
    assert.equal(current.nodes.hidden.value, '');
    assert.equal(current.nodes.option.disabled, true);
    assert.equal(current.nodes.option.hidden, true);
    assert.ok(current.nodes.overlay.draws > drawsBefore);
  }
});

test('validates exact canonical result shape independently', () => {
  const valid = [
    { at_ms: 0, center_x: 0, center_y: 1 },
    { at_ms: 1000, center_x: 0.123456, center_y: 0.5 },
  ];
  assert.equal(validateAutoKeyframes(valid, 1000), true);
  assert.equal(validateAutoKeyframes([...valid].reverse(), 1000), false);
  assert.equal(validateAutoKeyframes(valid.map(point => ({ ...point, source: 'x' })), 1000), false);
  assert.equal(validateAutoKeyframes([{ ...valid[0], center_x: Infinity }, valid[1]], 1000), false);
});

let failed = 0;
for (const [name, fn] of tests) {
  try {
    await fn();
    console.log(`ok - ${name}`);
  } catch (error) {
    failed += 1;
    console.error(`not ok - ${name}`);
    console.error(error);
  }
}
if (failed > 0) process.exitCode = 1;
else console.log(`PASS ${tests.length} editor tests`);
