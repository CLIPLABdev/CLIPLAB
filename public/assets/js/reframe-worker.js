const ASSET_ROOT = '/assets/vendor/mediapipe-tasks-vision-1.0.1';
const VISION_MODULE = `${ASSET_ROOT}/vision_bundle.mjs`;
const TRACKER_MODULE = './reframe-tracker.js';
const WASM_ROOT = `${ASSET_ROOT}/wasm`;
const MODEL_PATH = `${ASSET_ROOT}/models/blaze_face_short_range_float16.tflite`;
const REQUEST_ID = /^[A-Za-z0-9_-]{1,64}$/;
const PROXY_INFERENCE_TIMEOUT_MS = 15000;
const safeRequestId = value => REQUEST_ID.test(value ?? '') ? value : null;

class WorkerProtocolError extends Error {
  constructor(code) {
    super(code);
    this.code = code;
  }
}

const protocolError = code => new WorkerProtocolError(code);
const defaultImportModule = specifier => import(specifier);
const finite = value => typeof value === 'number' && Number.isFinite(value);
const normalizedNumber = value => Math.round(value * 1000000) / 1000000;

function closeBitmap(bitmap, ImageBitmapClass) {
  if (ImageBitmapClass && bitmap instanceof ImageBitmapClass) {
    try { bitmap.close(); } catch {}
  }
}

function confidenceOf(detection) {
  const score = detection?.categories?.[0]?.score;
  return finite(score) && score >= 0 && score <= 1 ? score : 0;
}

function normalizeDetections(result, width, height) {
  const detections = Array.isArray(result?.detections) ? result.detections : [];
  const boxes = [];
  for (const detection of detections) {
    const box = detection?.boundingBox;
    if (!box || ![box.originX, box.originY, box.width, box.height].every(finite)
        || box.width <= 0 || box.height <= 0) continue;
    const left = Math.max(0, Math.min(1, box.originX / width));
    const top = Math.max(0, Math.min(1, box.originY / height));
    const right = Math.max(0, Math.min(1, (box.originX + box.width) / width));
    const bottom = Math.max(0, Math.min(1, (box.originY + box.height) / height));
    if (right <= left || bottom <= top) continue;
    boxes.push({
      x: normalizedNumber(left),
      y: normalizedNumber(top),
      width: normalizedNumber(right - left),
      height: normalizedNumber(bottom - top),
      confidence: confidenceOf(detection),
    });
  }
  return boxes;
}

function timeout(promise, milliseconds) {
  let timer;
  return Promise.race([
    promise,
    new Promise((_, reject) => {
      timer = setTimeout(() => reject(protocolError('inference_failed')), milliseconds);
    }),
  ]).finally(() => clearTimeout(timer));
}

function createReframeWorkerRuntime({
  postMessage,
  importModule = defaultImportModule,
  ImageBitmapClass = globalThis.ImageBitmap,
  maxInferenceMs = 15000,
} = {}) {
  if (typeof postMessage !== 'function' || typeof importModule !== 'function') {
    throw new TypeError('Invalid worker runtime.');
  }

  let detector = null;
  let tracker = null;
  let durationMs = null;
  let samples = [];
  let lastFrameTimestamp = -1;
  let lastLegacyTimestamp = -1;
  let lastDetectorTimestamp = -1;
  let inferenceInFlight = false;
  let generation = 0;
  const requestIds = new Set();

  const replyError = (requestId, code) => {
    postMessage({ type: 'error', request_id: safeRequestId(requestId), code });
  };

  const resetTracking = () => {
    durationMs = null;
    samples = [];
    lastFrameTimestamp = -1;
  };

  const initialize = async data => {
    if (detector) {
      postMessage({ type: 'initialized', request_id: data.request_id });
      return;
    }
    const [visionModule, trackerModule] = await Promise.all([
      importModule(VISION_MODULE),
      importModule(TRACKER_MODULE),
    ]);
    if (typeof visionModule?.FilesetResolver?.forVisionTasks !== 'function'
        || typeof visionModule?.FaceDetector?.createFromOptions !== 'function'
        || typeof trackerModule?.buildReframeKeyframes !== 'function') {
      throw protocolError('initialization_failed');
    }
    const vision = await visionModule.FilesetResolver.forVisionTasks(WASM_ROOT);
    detector = await visionModule.FaceDetector.createFromOptions(vision, {
      baseOptions: { modelAssetPath: MODEL_PATH },
      runningMode: 'VIDEO',
    });
    tracker = trackerModule.buildReframeKeyframes;
    lastLegacyTimestamp = -1;
    lastDetectorTimestamp = -1;
    resetTracking();
    postMessage({ type: 'initialized', request_id: data.request_id });
  };

  const detect = async (data, tracking) => {
    const bitmap = data?.bitmap;
    const runGeneration = generation;
    try {
      if (!ImageBitmapClass || !(bitmap instanceof ImageBitmapClass)
          || !Number.isInteger(data.timestamp_ms)) throw protocolError('invalid_message');
      if (tracking) {
        if (durationMs === null) throw protocolError('not_ready');
        if (!Number.isInteger(bitmap.width) || !Number.isInteger(bitmap.height)
            || bitmap.width <= 0 || bitmap.height <= 0 || bitmap.width > 320 || bitmap.height > 320
            || data.timestamp_ms < 0 || data.timestamp_ms > durationMs
            || data.timestamp_ms <= lastFrameTimestamp) throw protocolError('invalid_message');
      } else if (data.timestamp_ms < 0 || data.timestamp_ms <= lastLegacyTimestamp) {
        throw protocolError('invalid_message');
      }
      if (inferenceInFlight) throw protocolError('busy');
      if (tracking) lastFrameTimestamp = data.timestamp_ms;
      else lastLegacyTimestamp = data.timestamp_ms;
      inferenceInFlight = true;
      const detectorTimestamp = Math.max(data.timestamp_ms, lastDetectorTimestamp + 1);
      lastDetectorTimestamp = detectorTimestamp;
      const result = await timeout(
        Promise.resolve().then(() => detector.detectForVideo(bitmap, detectorTimestamp)),
        maxInferenceMs
      );
      if (runGeneration !== generation) return;
      if (tracking) {
        samples.push({
          at_ms: data.timestamp_ms,
          width: 1,
          height: 1,
          boxes: normalizeDetections(result, bitmap.width, bitmap.height),
        });
        postMessage({ type: 'frame_ack', request_id: data.request_id });
      } else {
        const count = Array.isArray(result?.detections) ? result.detections.length : 0;
        postMessage({ type: 'detected', request_id: data.request_id, count });
      }
    } catch (error) {
      if (runGeneration === generation) {
        const code = error instanceof WorkerProtocolError ? error.code : 'inference_failed';
        replyError(data?.request_id, code);
      }
    } finally {
      closeBitmap(bitmap, ImageBitmapClass);
      inferenceInFlight = false;
    }
  };

  const handle = async data => {
    const requestId = data?.request_id;
    if (!REQUEST_ID.test(requestId ?? '')) {
      closeBitmap(data?.bitmap, ImageBitmapClass);
      replyError(requestId, 'invalid_message');
      return;
    }
    if (Object.prototype.hasOwnProperty.call(data, 'bitmap')
        && data?.type !== 'detect' && data?.type !== 'frame') {
      closeBitmap(data.bitmap, ImageBitmapClass);
      replyError(requestId, 'invalid_message');
      return;
    }
    if (requestIds.has(requestId)) {
      closeBitmap(data?.bitmap, ImageBitmapClass);
      replyError(requestId, 'stale_request');
      return;
    }
    requestIds.add(requestId);

    try {
      if (data?.type === 'initialize') {
        await initialize(data);
        return;
      }
      if (!detector || !tracker) throw protocolError('not_ready');

      if (data?.type === 'detect') {
        await detect(data, false);
        return;
      }
      if (data?.type === 'start') {
        if (inferenceInFlight || !Number.isInteger(data.duration_ms)
            || data.duration_ms < 1000 || data.duration_ms > 180000) {
          throw protocolError(inferenceInFlight ? 'busy' : 'invalid_message');
        }
        generation += 1;
        resetTracking();
        durationMs = data.duration_ms;
        postMessage({ type: 'started', request_id: requestId });
        return;
      }
      if (data?.type === 'frame') {
        await detect(data, true);
        return;
      }
      if (data?.type === 'finish') {
        if (durationMs === null) throw protocolError('not_ready');
        if (inferenceInFlight) throw protocolError('busy');
        const currentSamples = samples;
        const currentDuration = durationMs;
        resetTracking();
        const keyframes = tracker(currentSamples, currentDuration, undefined);
        postMessage({ type: 'finished', request_id: requestId, keyframes: Array.isArray(keyframes) ? keyframes : [] });
        return;
      }
      if (data?.type === 'cancel') {
        generation += 1;
        resetTracking();
        try { detector.close(); } catch {}
        detector = null;
        tracker = null;
        postMessage({ type: 'cancelled', request_id: requestId });
        return;
      }
      throw protocolError('invalid_message');
    } catch (error) {
      closeBitmap(data?.bitmap, ImageBitmapClass);
      const code = error instanceof WorkerProtocolError ? error.code
        : data?.type === 'initialize' ? 'initialization_failed' : 'invalid_message';
      replyError(requestId, code);
    }
  };

  return { handle };
}

if (typeof self !== 'undefined' && typeof self.postMessage === 'function') {
  const classicRuntime = new URL(self.location.href).searchParams.get('runtime') === 'classic';
  if (classicRuntime) {
    const runtime = createReframeWorkerRuntime({ postMessage: message => self.postMessage(message) });
    self.onmessage = event => { void runtime.handle(event.data); };
  } else {
    let runtimeWorker = null;
    let inferenceRequestId = null;
    const pending = new Map();
    const expectedResponses = {
      initialize: 'initialized',
      detect: 'detected',
      start: 'started',
      frame: 'frame_ack',
      finish: 'finished',
      cancel: 'cancelled',
    };
    const proxyError = (requestId, code) => {
      self.postMessage({ type: 'error', request_id: safeRequestId(requestId), code });
    };
    const clearPending = requestId => {
      const request = pending.get(requestId);
      if (!request) return null;
      if (request.timer !== null) clearTimeout(request.timer);
      pending.delete(requestId);
      if (inferenceRequestId === requestId) inferenceRequestId = null;
      return request;
    };
    const abandonRuntime = code => {
      const abandoned = runtimeWorker;
      runtimeWorker = null;
      if (abandoned) {
        abandoned.onmessage = null;
        abandoned.onerror = null;
        try { abandoned.terminate(); } catch {}
      }
      const requests = [...pending.keys()];
      for (const requestId of requests) {
        clearPending(requestId);
        proxyError(requestId, code);
      }
      if (requests.length === 0) proxyError(null, code);
    };
    const createRuntime = () => {
      const candidate = new Worker('./reframe-worker.js?runtime=classic');
      runtimeWorker = candidate;
      candidate.onmessage = event => {
        if (runtimeWorker !== candidate) return;
        const requestId = safeRequestId(event.data?.request_id);
        const request = requestId === null ? null : pending.get(requestId);
        if (!request) return;
        if (event.data?.type === 'error') {
          if (request.inference && event.data?.code === 'inference_failed') {
            abandonRuntime('inference_failed');
            return;
          }
          clearPending(requestId);
          const allowed = ['invalid_message', 'stale_request', 'busy', 'not_ready', 'inference_failed', 'initialization_failed'];
          proxyError(requestId, allowed.includes(event.data?.code) ? event.data.code : 'invalid_message');
          return;
        }
        if (event.data?.type !== request.expected) return;
        clearPending(requestId);
        self.postMessage(event.data);
      };
      candidate.onerror = () => {
        if (runtimeWorker === candidate) abandonRuntime('initialization_failed');
      };
      return candidate;
    };

    self.onmessage = ({ data }) => {
      const requestId = safeRequestId(data?.request_id);
      if (requestId === null) {
        closeBitmap(data?.bitmap, self.ImageBitmap);
        proxyError(null, 'invalid_message');
        return;
      }
      const inference = data?.type === 'detect' || data?.type === 'frame';
      if (Object.prototype.hasOwnProperty.call(data, 'bitmap') && !inference) {
        closeBitmap(data.bitmap, self.ImageBitmap);
        proxyError(requestId, 'invalid_message');
        return;
      }
      const expected = expectedResponses[data?.type];
      if (!expected) {
        closeBitmap(data?.bitmap, self.ImageBitmap);
        proxyError(requestId, 'invalid_message');
        return;
      }
      if (!runtimeWorker) {
        if (data?.type !== 'initialize') {
          proxyError(requestId, 'not_ready');
          closeBitmap(data?.bitmap, self.ImageBitmap);
          return;
        }
        try {
          createRuntime();
        } catch {
          proxyError(requestId, 'initialization_failed');
          return;
        }
      }
      if (pending.has(requestId)) {
        closeBitmap(data?.bitmap, self.ImageBitmap);
        proxyError(requestId, 'stale_request');
        return;
      }
      if (inferenceRequestId !== null) {
        closeBitmap(data?.bitmap, self.ImageBitmap);
        proxyError(requestId, 'busy');
        return;
      }
      const request = { expected, inference, timer: null };
      pending.set(requestId, request);
      if (inference) {
        inferenceRequestId = requestId;
        const candidate = runtimeWorker;
        request.timer = setTimeout(() => {
          if (runtimeWorker === candidate && pending.get(requestId) === request) {
            abandonRuntime('inference_failed');
          }
        }, PROXY_INFERENCE_TIMEOUT_MS);
      }
      const transferable = inference && data.bitmap ? [data.bitmap] : [];
      try {
        runtimeWorker.postMessage(data, transferable);
      } catch {
        clearPending(requestId);
        closeBitmap(data?.bitmap, self.ImageBitmap);
        proxyError(requestId, 'invalid_message');
      }
    };
  }
}
