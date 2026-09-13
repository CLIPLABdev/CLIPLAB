import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import { resolve } from 'node:path';
import vm from 'node:vm';

const modulePath = process.argv[2];
if (!modulePath) throw new Error('Usage: node reframe-worker.test.mjs <worker-module>');
const source = await readFile(resolve(modulePath), 'utf8');
const context = { clearTimeout, console, setTimeout, URL };
context.globalThis = context;
vm.runInNewContext(`${source}\n;globalThis.testFactory = createReframeWorkerRuntime;`, context);
const createReframeWorkerRuntime = context.testFactory;

class FakeBitmap {
  constructor(width = 320, height = 180) {
    this.width = width;
    this.height = height;
    this.closed = false;
    this.closeCalls = 0;
  }
  close() { this.closed = true; this.closeCalls += 1; }
}

const detection = (x, y, width, height, score = 0.9) => ({
  boundingBox: { originX: x, originY: y, width, height },
  categories: [{ score }],
  keypoints: [{ x: 0.1, y: 0.2 }],
});

function harness({ detectForVideo, tracker = () => [], maxInferenceMs = 50 } = {}) {
  const imports = [];
  const messages = [];
  const detector = {
    closeCalls: 0,
    detectForVideo: detectForVideo ?? (() => ({ detections: [] })),
    close() { this.closeCalls += 1; },
  };
  let createOptions;
  const importModule = async specifier => {
    imports.push(specifier);
    if (specifier.endsWith('reframe-tracker.js')) return { buildReframeKeyframes: tracker };
    return {
      FilesetResolver: {
        async forVisionTasks(path) {
          assert.equal(path, '/assets/vendor/mediapipe-tasks-vision-1.0.1/wasm');
          return { wasm: true };
        },
      },
      FaceDetector: {
        async createFromOptions(vision, options) {
          assert.deepEqual(vision, { wasm: true });
          createOptions = options;
          return detector;
        },
      },
    };
  };
  const runtime = createReframeWorkerRuntime({
    postMessage: message => messages.push(structuredClone(message)),
    importModule,
    ImageBitmapClass: FakeBitmap,
    maxInferenceMs,
  });
  return { runtime, imports, messages, detector, get createOptions() { return structuredClone(createOptions); } };
}

function proxyHarness() {
  const messages = [];
  const timers = new Map();
  const workers = [];
  let nextTimerId = 1;

  class FakeClassicWorker {
    constructor(url) {
      this.url = url;
      this.messages = [];
      this.terminateCalls = 0;
      workers.push(this);
    }
    postMessage(message, transfer = []) {
      this.messages.push({ message, transfer });
    }
    respond(data) {
      this.onmessage?.({ data });
    }
    terminate() {
      this.terminateCalls += 1;
    }
  }

  const workerSelf = {
    ImageBitmap: FakeBitmap,
    location: { href: 'http://127.0.0.1:8094/assets/js/reframe-worker.js' },
    postMessage: message => messages.push(structuredClone(message)),
  };
  const proxyContext = {
    URL,
    Worker: FakeClassicWorker,
    ImageBitmap: FakeBitmap,
    clearTimeout: id => timers.delete(id),
    setTimeout: (callback, milliseconds) => {
      const id = nextTimerId;
      nextTimerId += 1;
      timers.set(id, { callback, milliseconds });
      return id;
    },
    self: workerSelf,
  };
  proxyContext.globalThis = proxyContext;
  vm.runInNewContext(source, proxyContext);

  return {
    messages,
    timers,
    workers,
    send: data => workerSelf.onmessage({ data }),
    fireTimers() {
      const pending = [...timers.values()];
      timers.clear();
      for (const timer of pending) timer.callback();
    },
  };
}

const tests = [];
const test = (name, fn) => tests.push([name, fn]);
const last = value => value.at(-1);

test('imports only fixed same-origin modules lazily and initializes FaceDetector in VIDEO mode', async () => {
  const h = harness();
  assert.deepEqual(h.imports, []);
  await h.runtime.handle({ type: 'initialize', request_id: 'init_1' });
  assert.deepEqual(h.imports, [
    '/assets/vendor/mediapipe-tasks-vision-1.0.1/vision_bundle.mjs',
    './reframe-tracker.js',
  ]);
  assert.deepEqual(h.createOptions, {
    baseOptions: { modelAssetPath: '/assets/vendor/mediapipe-tasks-vision-1.0.1/models/blaze_face_short_range_float16.tflite' },
    runningMode: 'VIDEO',
  });
  assert.deepEqual(last(h.messages), { type: 'initialized', request_id: 'init_1' });
});

test('preserves legacy detect, accepts timestamp zero first and closes every bitmap', async () => {
  const h = harness({ detectForVideo: () => ({ detections: [detection(1, 2, 3, 4)] }) });
  await h.runtime.handle({ type: 'initialize', request_id: 'init' });
  const first = new FakeBitmap();
  await h.runtime.handle({ type: 'detect', request_id: 'detect_0', bitmap: first, timestamp_ms: 0 });
  assert.equal(first.closed, true);
  assert.deepEqual(last(h.messages), { type: 'detected', request_id: 'detect_0', count: 1 });

  const stale = new FakeBitmap();
  await h.runtime.handle({ type: 'detect', request_id: 'detect_stale', bitmap: stale, timestamp_ms: 0 });
  assert.equal(stale.closed, true);
  assert.deepEqual(last(h.messages), { type: 'error', request_id: 'detect_stale', code: 'invalid_message' });
});

test('keeps the detector clock monotonic when start resets the logical frame clock', async () => {
  const detectorTimes = [];
  const h = harness({
    detectForVideo: (_bitmap, timestamp) => {
      if (timestamp <= (detectorTimes.at(-1) ?? -1)) throw new Error('detector timestamp');
      detectorTimes.push(timestamp);
      return { detections: [] };
    },
  });
  await h.runtime.handle({ type: 'initialize', request_id: 'init' });
  await h.runtime.handle({ type: 'detect', request_id: 'legacy', bitmap: new FakeBitmap(), timestamp_ms: 0 });
  await h.runtime.handle({ type: 'start', request_id: 'start', duration_ms: 1000 });
  await h.runtime.handle({ type: 'frame', request_id: 'frame', bitmap: new FakeBitmap(), timestamp_ms: 0 });

  assert.deepEqual(detectorTimes, [0, 1]);
  assert.deepEqual(last(h.messages), { type: 'frame_ack', request_id: 'frame' });
});

test('validates start duration and resets timestamps and samples for a fresh run', async () => {
  const tracked = [];
  const h = harness({ tracker: (samples, duration) => { tracked.push({ samples, duration }); return []; } });
  await h.runtime.handle({ type: 'initialize', request_id: 'init' });
  for (const duration_ms of [999, 180001, 1000.5, '1000']) {
    await h.runtime.handle({ type: 'start', request_id: `bad_${String(duration_ms)}`, duration_ms });
    assert.equal(last(h.messages).code, 'invalid_message');
  }
  await h.runtime.handle({ type: 'start', request_id: 'start_1', duration_ms: 1000 });
  assert.deepEqual(last(h.messages), { type: 'started', request_id: 'start_1' });
  await h.runtime.handle({ type: 'finish', request_id: 'finish_1' });
  assert.deepEqual(structuredClone(tracked), [{ samples: [], duration: 1000 }]);
  assert.deepEqual(last(h.messages), { type: 'finished', request_id: 'finish_1', keyframes: [] });
});

test('classic runtime rejects unexpected bitmaps before mutating initialize, start, finish or cancel state', async () => {
  const h = harness();
  const invalidInitialize = new FakeBitmap();
  await h.runtime.handle({ type: 'initialize', request_id: 'init_owned', bitmap: invalidInitialize });
  assert.equal(invalidInitialize.closeCalls, 1);
  assert.deepEqual(h.imports, []);
  assert.deepEqual(last(h.messages), { type: 'error', request_id: 'init_owned', code: 'invalid_message' });
  await h.runtime.handle({ type: 'initialize', request_id: 'init_owned' });
  assert.deepEqual(last(h.messages), { type: 'initialized', request_id: 'init_owned' });

  const invalidStart = new FakeBitmap();
  await h.runtime.handle({ type: 'start', request_id: 'start_owned', duration_ms: 1000, bitmap: invalidStart });
  assert.equal(invalidStart.closeCalls, 1);
  assert.deepEqual(last(h.messages), { type: 'error', request_id: 'start_owned', code: 'invalid_message' });
  await h.runtime.handle({ type: 'start', request_id: 'start_owned', duration_ms: 1000 });
  assert.deepEqual(last(h.messages), { type: 'started', request_id: 'start_owned' });

  const invalidFinish = new FakeBitmap();
  await h.runtime.handle({ type: 'finish', request_id: 'finish_owned', bitmap: invalidFinish });
  assert.equal(invalidFinish.closeCalls, 1);
  assert.deepEqual(last(h.messages), { type: 'error', request_id: 'finish_owned', code: 'invalid_message' });
  await h.runtime.handle({ type: 'finish', request_id: 'finish_owned' });
  assert.deepEqual(last(h.messages), { type: 'finished', request_id: 'finish_owned', keyframes: [] });

  await h.runtime.handle({ type: 'start', request_id: 'restart', duration_ms: 1000 });
  const invalidCancel = new FakeBitmap();
  await h.runtime.handle({ type: 'cancel', request_id: 'cancel_owned', bitmap: invalidCancel });
  assert.equal(invalidCancel.closeCalls, 1);
  assert.equal(h.detector.closeCalls, 0);
  assert.deepEqual(last(h.messages), { type: 'error', request_id: 'cancel_owned', code: 'invalid_message' });
  await h.runtime.handle({ type: 'cancel', request_id: 'cancel_owned' });
  assert.equal(h.detector.closeCalls, 1);
  assert.deepEqual(last(h.messages), { type: 'cancelled', request_id: 'cancel_owned' });

  const invalidType = new FakeBitmap();
  await h.runtime.handle({ type: 'bogus', request_id: 'bogus_owned', bitmap: invalidType });
  assert.equal(invalidType.closeCalls, 1);
  assert.deepEqual(last(h.messages), { type: 'error', request_id: 'bogus_owned', code: 'invalid_message' });
});

test('normalizes detections, processes frames serially and returns only tracker keyframes', async () => {
  const tracked = [];
  const expected = [
    { at_ms: 0, center_x: 0.25, center_y: 0.5 },
    { at_ms: 1000, center_x: 0.75, center_y: 0.5 },
  ];
  const h = harness({
    detectForVideo: (_bitmap, timestamp) => ({ detections: [detection(timestamp === 0 ? 32 : 160, 18, 64, 36, 0.75)] }),
    tracker: (samples, duration, options) => {
      tracked.push({ samples, duration, options });
      return expected;
    },
  });
  await h.runtime.handle({ type: 'initialize', request_id: 'init' });
  await h.runtime.handle({ type: 'start', request_id: 'start', duration_ms: 1000 });
  const first = new FakeBitmap(320, 180);
  const second = new FakeBitmap(320, 180);
  await h.runtime.handle({ type: 'frame', request_id: 'frame_0', bitmap: first, timestamp_ms: 0 });
  await h.runtime.handle({ type: 'frame', request_id: 'frame_1', bitmap: second, timestamp_ms: 1000 });
  assert.equal(first.closed, true);
  assert.equal(second.closed, true);
  assert.deepEqual(h.messages.slice(-2), [
    { type: 'frame_ack', request_id: 'frame_0' },
    { type: 'frame_ack', request_id: 'frame_1' },
  ]);
  await h.runtime.handle({ type: 'finish', request_id: 'finish' });
  assert.deepEqual(last(h.messages), { type: 'finished', request_id: 'finish', keyframes: expected });
  assert.deepEqual(structuredClone(tracked), [{
    samples: [
      { at_ms: 0, width: 1, height: 1, boxes: [{ x: 0.1, y: 0.1, width: 0.2, height: 0.2, confidence: 0.75 }] },
      { at_ms: 1000, width: 1, height: 1, boxes: [{ x: 0.5, y: 0.1, width: 0.2, height: 0.2, confidence: 0.75 }] },
    ],
    duration: 1000,
    options: undefined,
  }]);
  assert.equal(JSON.stringify(h.messages).includes('keypoints'), false);
  assert.equal(JSON.stringify(h.messages).includes('boundingBox'), false);
});

test('rejects invalid dimensions, timestamps, duplicate IDs and concurrent inference while closing bitmaps', async () => {
  let release;
  const pending = new Promise(resolve => { release = resolve; });
  const h = harness({ detectForVideo: () => pending });
  await h.runtime.handle({ type: 'initialize', request_id: 'init' });
  await h.runtime.handle({ type: 'start', request_id: 'start', duration_ms: 1000 });

  for (const [request_id, bitmap, timestamp_ms] of [
    ['too_wide', new FakeBitmap(321, 180), 0],
    ['too_tall', new FakeBitmap(180, 321), 0],
    ['zero_edge', new FakeBitmap(0, 180), 0],
    ['fractional', new FakeBitmap(), 0.5],
  ]) {
    await h.runtime.handle({ type: 'frame', request_id, bitmap, timestamp_ms });
    assert.equal(bitmap.closed, true);
    assert.equal(last(h.messages).code, 'invalid_message');
  }

  const active = new FakeBitmap();
  const activePromise = h.runtime.handle({ type: 'frame', request_id: 'active', bitmap: active, timestamp_ms: 0 });
  const concurrent = new FakeBitmap();
  await h.runtime.handle({ type: 'frame', request_id: 'concurrent', bitmap: concurrent, timestamp_ms: 1 });
  assert.equal(concurrent.closed, true);
  assert.deepEqual(last(h.messages), { type: 'error', request_id: 'concurrent', code: 'busy' });
  release({ detections: [] });
  await activePromise;
  assert.equal(active.closed, true);

  const duplicate = new FakeBitmap();
  await h.runtime.handle({ type: 'frame', request_id: 'active', bitmap: duplicate, timestamp_ms: 1 });
  assert.equal(duplicate.closed, true);
  assert.deepEqual(last(h.messages), { type: 'error', request_id: 'active', code: 'stale_request' });
});

test('times out inference, sanitizes errors and never leaks raw exception data', async () => {
  const h = harness({ detectForVideo: () => new Promise(() => {}), maxInferenceMs: 5 });
  await h.runtime.handle({ type: 'initialize', request_id: 'init' });
  await h.runtime.handle({ type: 'start', request_id: 'start', duration_ms: 1000 });
  const bitmap = new FakeBitmap();
  await h.runtime.handle({ type: 'frame', request_id: 'timeout', bitmap, timestamp_ms: 0 });
  assert.equal(bitmap.closed, true);
  assert.deepEqual(last(h.messages), { type: 'error', request_id: 'timeout', code: 'inference_failed' });
  const serialized = JSON.stringify(h.messages);
  for (const secret of ['http', 'cookie', 'csrf', 'object', 'path', 'stack', 'detection']) {
    assert.equal(serialized.toLowerCase().includes(secret), false);
  }
});

test('module proxy rejects unexpected non-inference bitmaps before runtime, forwarding or pending state', () => {
  const initializing = proxyHarness();
  const initializeBitmap = new FakeBitmap();
  initializing.send({ type: 'initialize', request_id: 'owned_initialize', bitmap: initializeBitmap });
  assert.equal(initializeBitmap.closeCalls, 1);
  assert.equal(initializing.workers.length, 0);
  assert.equal(initializing.timers.size, 0);
  assert.deepEqual(last(initializing.messages), {
    type: 'error', request_id: 'owned_initialize', code: 'invalid_message',
  });
  initializing.send({ type: 'initialize', request_id: 'owned_initialize' });
  assert.equal(initializing.workers.length, 1);
  assert.equal(initializing.workers[0].messages.length, 1);
  assert.equal(initializing.workers[0].messages[0].message.request_id, 'owned_initialize');

  for (const [type, response] of [
    ['start', { type: 'started', request_id: 'owned_start' }],
    ['finish', { type: 'finished', request_id: 'owned_finish', keyframes: [] }],
    ['cancel', { type: 'cancelled', request_id: 'owned_cancel' }],
  ]) {
    const h = proxyHarness();
    h.send({ type: 'initialize', request_id: 'seed' });
    const runtime = h.workers[0];
    runtime.respond({ type: 'initialized', request_id: 'seed' });
    const forwardedBefore = runtime.messages.length;
    const messagesBefore = h.messages.length;
    const bitmap = new FakeBitmap();

    h.send({ type, request_id: `owned_${type}`, duration_ms: 1000, bitmap });

    assert.equal(bitmap.closeCalls, 1);
    assert.equal(h.workers.length, 1);
    assert.equal(runtime.messages.length, forwardedBefore);
    assert.equal(h.timers.size, 0);
    assert.equal(h.messages.length, messagesBefore + 1);
    assert.deepEqual(last(h.messages), {
      type: 'error', request_id: `owned_${type}`, code: 'invalid_message',
    });

    h.send({ type, request_id: `owned_${type}`, duration_ms: 1000 });
    assert.equal(runtime.messages.length, forwardedBefore + 1);
    assert.equal(runtime.messages.at(-1).message.request_id, `owned_${type}`);
    runtime.respond(response);
    assert.equal(h.timers.size, 0);
  }

  const invalid = proxyHarness();
  const invalidBitmap = new FakeBitmap();
  invalid.send({ type: 'bogus', request_id: 'owned_bogus', bitmap: invalidBitmap });
  assert.equal(invalidBitmap.closeCalls, 1);
  assert.equal(invalid.workers.length, 0);
  assert.equal(invalid.timers.size, 0);
  assert.deepEqual(last(invalid.messages), {
    type: 'error', request_id: 'owned_bogus', code: 'invalid_message',
  });
  invalid.send({ type: 'initialize', request_id: 'owned_bogus' });
  assert.equal(invalid.workers.length, 1);
  assert.equal(invalid.workers[0].messages[0].message.request_id, 'owned_bogus');
});

test('module proxy watchdog destroys a stalled classic runtime and requires a fresh initialize', () => {
  const h = proxyHarness();
  h.send({ type: 'initialize', request_id: 'init' });
  const stalled = h.workers[0];
  assert.equal(stalled.url, './reframe-worker.js?runtime=classic');
  stalled.respond({ type: 'initialized', request_id: 'init' });
  h.send({ type: 'start', request_id: 'start', duration_ms: 1000 });
  stalled.respond({ type: 'started', request_id: 'start' });

  const first = new FakeBitmap();
  h.send({ type: 'frame', request_id: 'frame_0', bitmap: first, timestamp_ms: 0 });
  assert.equal(h.timers.size, 1);
  assert.equal([...h.timers.values()][0].milliseconds, 15000);

  const concurrent = new FakeBitmap();
  h.send({ type: 'frame', request_id: 'frame_1', bitmap: concurrent, timestamp_ms: 1 });
  assert.equal(concurrent.closed, true);
  assert.equal(stalled.messages.filter(entry => entry.message.type === 'frame').length, 1);
  assert.deepEqual(last(h.messages), { type: 'error', request_id: 'frame_1', code: 'busy' });

  h.fireTimers();
  assert.equal(stalled.terminateCalls, 1);
  assert.equal(h.timers.size, 0);
  assert.deepEqual(last(h.messages), { type: 'error', request_id: 'frame_0', code: 'inference_failed' });
  const afterTimeout = h.messages.length;
  stalled.respond({ type: 'frame_ack', request_id: 'frame_0' });
  assert.equal(h.messages.length, afterTimeout);

  const rejected = new FakeBitmap();
  h.send({ type: 'frame', request_id: 'after_timeout', bitmap: rejected, timestamp_ms: 1 });
  assert.equal(rejected.closed, true);
  assert.equal(h.workers.length, 1);
  assert.deepEqual(last(h.messages), { type: 'error', request_id: 'after_timeout', code: 'not_ready' });

  h.send({ type: 'initialize', request_id: 'frame_0' });
  assert.equal(h.workers.length, 2);
  const recovered = h.workers[1];
  assert.equal(recovered.terminateCalls, 0);
  assert.equal(recovered.messages[0].message.request_id, 'frame_0');
  recovered.respond({ type: 'initialized', request_id: 'frame_0' });
  h.send({ type: 'start', request_id: 'new_start', duration_ms: 1000 });
  recovered.respond({ type: 'started', request_id: 'new_start' });
  h.send({ type: 'frame', request_id: 'new_frame', bitmap: new FakeBitmap(), timestamp_ms: 0 });
  assert.equal(recovered.messages.at(-1).message.request_id, 'new_frame');
  assert.equal(stalled.messages.filter(entry => entry.message.type === 'frame').length, 1);
  recovered.respond({ type: 'error', request_id: 'new_frame', code: 'inference_failed' });
  assert.equal(recovered.terminateCalls, 1);
  assert.equal(h.timers.size, 0);
  h.send({ type: 'initialize', request_id: 'after_classic_timeout' });
  assert.equal(h.workers.length, 3);
  assert.equal(h.workers[2].messages[0].message.request_id, 'after_classic_timeout');
});

test('cancel closes detector, clears state and suppresses stale completion', async () => {
  let release;
  const pending = new Promise(resolve => { release = resolve; });
  const h = harness({ detectForVideo: () => pending });
  await h.runtime.handle({ type: 'initialize', request_id: 'init' });
  await h.runtime.handle({ type: 'start', request_id: 'start', duration_ms: 1000 });
  const bitmap = new FakeBitmap();
  const frame = h.runtime.handle({ type: 'frame', request_id: 'frame', bitmap, timestamp_ms: 0 });
  await h.runtime.handle({ type: 'cancel', request_id: 'cancel' });
  assert.equal(h.detector.closeCalls, 1);
  assert.deepEqual(last(h.messages), { type: 'cancelled', request_id: 'cancel' });
  release({ detections: [detection(1, 1, 2, 2)] });
  await frame;
  assert.equal(bitmap.closed, true);
  assert.equal(h.messages.some(message => message.type === 'frame_ack' && message.request_id === 'frame'), false);

  await h.runtime.handle({ type: 'finish', request_id: 'finish_after_cancel' });
  assert.deepEqual(last(h.messages), { type: 'error', request_id: 'finish_after_cancel', code: 'not_ready' });
});

test('invalid messages are correlated when possible and finish with no track is empty', async () => {
  const h = harness();
  await h.runtime.handle({ type: 'bogus', request_id: 'bad' });
  assert.deepEqual(last(h.messages), { type: 'error', request_id: 'bad', code: 'not_ready' });
  await h.runtime.handle({ type: 'initialize', request_id: 'init' });
  await h.runtime.handle({ type: 'start', request_id: 'start', duration_ms: 1000 });
  await h.runtime.handle({ type: 'finish', request_id: 'finish' });
  assert.deepEqual(last(h.messages), { type: 'finished', request_id: 'finish', keyframes: [] });
  assert.deepEqual(Object.keys(last(h.messages)), ['type', 'request_id', 'keyframes']);
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
else console.log(`PASS ${tests.length} worker tests`);
