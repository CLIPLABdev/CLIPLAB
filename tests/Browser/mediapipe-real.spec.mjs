import { test, expect } from '@playwright/test';
import { readFile } from 'node:fs/promises';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { startLocalApacheServer } from './support/local-apache-server.mjs';
import { startLocalPhpServer } from './support/local-php-server.mjs';

const here = dirname(fileURLToPath(import.meta.url));
const projectRoot = resolve(here, '../..');
const assetRoot = '/assets/vendor/mediapipe-tasks-vision-1.0.1';
const platformEnv = Object.fromEntries(
  ['PATH', 'SystemRoot', 'TEMP', 'TMP'].flatMap(name => (
    typeof process.env[name] === 'string' ? [[name, process.env[name]]] : []
  )),
);
const appEnv = {
  ...platformEnv,
  APP_ENV: 'testing',
  APP_DEBUG: 'false',
  APP_ENV_FILE: '',
  APP_URL: 'http://127.0.0.1:8094',
  APP_TIMEZONE: 'UTC',
  GEMINI_API_KEY: '',
  GEMINI_MODEL: '',
  MAIL_TRANSPORT: 'log',
};
let apache;
let apacheFallback;
let server;

test.beforeAll(async () => {
  server = await startLocalPhpServer({ phpBin: process.env.TEST_PHP_BIN, port: 8094, root: resolve(projectRoot, 'public'), env: appEnv });
  apache = await startLocalApacheServer({ port: 8096, root: resolve(projectRoot, 'public') });
  apacheFallback = await startLocalApacheServer({ port: 8097, root: projectRoot });
});

test.afterAll(async () => {
  await apacheFallback?.stop();
  await apache?.stop();
  await server?.stop();
});

test.use({ channel: 'chrome' });

test('self-hosted MediaPipe worker detects the synthetic face under production CSP with deploy MIME types', async ({ page }) => {
  const browserMessages = [];
  page.on('console', message => browserMessages.push(message.text()));
  const response = await page.goto(server.baseUrl);
  const csp = response?.headers()['content-security-policy'] ?? '';
  expect(csp).toContain("worker-src 'self'");
  expect(csp).not.toContain("'wasm-unsafe-eval'");
  expect(csp).not.toContain("'unsafe-eval'");
  expect(csp).not.toContain('blob:');

  const workerCsp = "default-src 'none'; script-src 'self' 'wasm-unsafe-eval'; connect-src 'self'";
  for (const apacheBaseUrl of [apache.baseUrl, apacheFallback.baseUrl]) {
    const workerResponse = await fetch(`${apacheBaseUrl}/assets/js/reframe-worker.js`);
    expect(workerResponse.status).toBe(200);
    expect(workerResponse.headers.get('content-security-policy')).toBe(workerCsp);
  }

  const requiredTypes = new Map([
    [`${assetRoot}/vision_bundle.mjs`, 'application/javascript'],
    [`${assetRoot}/wasm/vision_wasm_internal.wasm`, 'application/wasm'],
    [`${assetRoot}/models/blaze_face_short_range_float16.tflite`, 'application/octet-stream'],
  ]);
  for (const [path, contentType] of requiredTypes) {
    const assetResponse = await fetch(`${apache.baseUrl}${path}`);
    expect(assetResponse.status).toBe(200);
    expect(assetResponse.headers.get('content-type')).toBe(contentType);
  }

  await page.goto(`${apache.baseUrl}${assetRoot}/manifest.json`);
  const external = [];
  page.on('request', request => {
    if (new URL(request.url()).origin !== apache.baseUrl) external.push(request.url());
  });
  const result = await page.evaluate(async ({ baseUrl, imageBytes }) => {
    const worker = new Worker(`${baseUrl}/assets/js/reframe-worker.js`, { type: 'module' });
    const request = (message, transfer = []) => new Promise((resolve, reject) => {
      const timeout = setTimeout(() => reject(new Error(`worker timeout: ${message.type}`)), 15000);
      worker.addEventListener('error', event => { clearTimeout(timeout); reject(new Error(event.message)); }, { once: true });
      worker.addEventListener('message', event => {
        if (event.data?.request_id === message.request_id) {
          clearTimeout(timeout);
          resolve(event.data);
        }
      }, { once: true });
      worker.postMessage(message, transfer);
    });
    try {
      const initialized = await request({ type: 'initialize', request_id: 'init' });
      const bitmap = await createImageBitmap(new Blob([new Uint8Array(imageBytes)], { type: 'image/png' }));
      const detected = await request({ type: 'detect', request_id: 'detect', bitmap, timestamp_ms: 1 }, [bitmap]);
      return { initialized, detected };
    } finally {
      worker.terminate();
    }
  }, {
    baseUrl: apache.baseUrl,
    imageBytes: Array.from(await readFile(resolve(projectRoot, 'tests/Fixtures/mediapipe-synthetic-face.png'))),
  });

  expect(result.initialized, browserMessages.join('\n')).toEqual({ type: 'initialized', request_id: 'init' });
  expect(result.detected).toMatchObject({ type: 'detected', request_id: 'detect' });
  expect(result.detected.count).toBeGreaterThanOrEqual(1);
  expect(external).toEqual([]);
});

test('same-origin module worker preserves legacy detection and builds real tracked keyframes', async ({ page }) => {
  const response = await page.goto(server.baseUrl);
  const csp = response?.headers()['content-security-policy'] ?? '';
  expect(csp).toContain("worker-src 'self'");
  expect(csp).not.toContain("'wasm-unsafe-eval'");
  expect(csp).not.toContain("'unsafe-eval'");
  expect(csp).not.toContain('blob:');

  const requests = [];
  page.on('request', request => requests.push(request.url()));
  const result = await page.evaluate(async ({ baseUrl, imageBytes }) => {
    const worker = new Worker(`${baseUrl}/assets/js/reframe-worker.js`, { type: 'module' });
    const request = (message, transfer = []) => new Promise((resolve, reject) => {
      const timeout = setTimeout(() => reject(new Error(`worker timeout: ${message.type}`)), 15000);
      worker.addEventListener('error', event => { clearTimeout(timeout); reject(new Error(event.message)); }, { once: true });
      worker.addEventListener('message', event => {
        if (event.data?.request_id === message.request_id) {
          clearTimeout(timeout);
          resolve(event.data);
        }
      }, { once: true });
      worker.postMessage(message, transfer);
    });
    try {
      const initialized = await request({ type: 'initialize', request_id: 'init' });
      const image = new Blob([new Uint8Array(imageBytes)], { type: 'image/png' });
      const legacyBitmap = await createImageBitmap(image);
      const detected = await request({ type: 'detect', request_id: 'detect', bitmap: legacyBitmap, timestamp_ms: 0 }, [legacyBitmap]);
      const started = await request({ type: 'start', request_id: 'start', duration_ms: 1000 });
      const firstBitmap = await createImageBitmap(image, { resizeWidth: 320, resizeHeight: 320 });
      const first = await request({ type: 'frame', request_id: 'frame_0', bitmap: firstBitmap, timestamp_ms: 0 }, [firstBitmap]);
      const lastBitmap = await createImageBitmap(image, { resizeWidth: 320, resizeHeight: 320 });
      const last = await request({ type: 'frame', request_id: 'frame_1', bitmap: lastBitmap, timestamp_ms: 1000 }, [lastBitmap]);
      const finished = await request({ type: 'finish', request_id: 'finish' });
      return { initialized, detected, started, first, last, finished };
    } finally {
      worker.terminate();
    }
  }, {
    baseUrl: server.baseUrl,
    imageBytes: Array.from(await readFile(resolve(projectRoot, 'tests/Fixtures/mediapipe-synthetic-face.png'))),
  });

  expect(result.initialized).toEqual({ type: 'initialized', request_id: 'init' });
  expect(result.detected).toMatchObject({ type: 'detected', request_id: 'detect' });
  expect(result.detected.count).toBeGreaterThanOrEqual(1);
  expect(result.started).toEqual({ type: 'started', request_id: 'start' });
  expect(result.first).toEqual({ type: 'frame_ack', request_id: 'frame_0' });
  expect(result.last).toEqual({ type: 'frame_ack', request_id: 'frame_1' });
  expect(result.finished.type).toBe('finished');
  expect(result.finished.request_id).toBe('finish');
  expect(result.finished.keyframes.length).toBeGreaterThanOrEqual(2);
  expect(result.finished.keyframes.length).toBeLessThanOrEqual(32);
  expect(result.finished.keyframes[0].at_ms).toBe(0);
  expect(result.finished.keyframes.at(-1).at_ms).toBe(1000);
  expect(result.finished.keyframes.every(point => (
    Object.keys(point).join(',') === 'at_ms,center_x,center_y'
    && Number.isFinite(point.center_x) && point.center_x >= 0 && point.center_x <= 1
    && Number.isFinite(point.center_y) && point.center_y >= 0 && point.center_y <= 1
  ))).toBe(true);
  expect(requests.every(url => new URL(url).origin === server.baseUrl)).toBe(true);
  expect(requests.every(url => !new URL(url).pathname.includes('/clips/'))).toBe(true);
});
