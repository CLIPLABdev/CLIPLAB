import { test, expect } from '@playwright/test';
import { spawn } from 'node:child_process';
import { randomBytes, randomUUID } from 'node:crypto';
import { access } from 'node:fs/promises';
import { dirname, isAbsolute, join, relative, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { prepareIsolatedBaseRoot } from './support/isolated-media-root.mjs';
import { startLocalPhpServer } from './support/local-php-server.mjs';

const here = dirname(fileURLToPath(import.meta.url));
const projectRoot = resolve(here, '../..');
const fixtureScript = resolve(here, 'support/smart-reframe-e2e-fixture.php');
const lockScript = resolve(here, 'support/smart-reframe-e2e-lock.php');
const runId = `sr_${randomUUID().replaceAll('-', '')}`;
const fixtureStateKey = randomBytes(32).toString('hex');
if (!/^sr_[a-f0-9]{32}$/.test(runId)) throw new Error('Invalid isolated test identifier.');

const hasEnv = name => Object.prototype.hasOwnProperty.call(process.env, name);
const presentEnv = name => {
  if (!hasEnv(name)) throw new Error('Missing required test environment.');
  return process.env[name];
};
const requiredEnv = name => {
  const value = presentEnv(name);
  if (value.trim() === '') throw new Error('Missing required test environment.');
  return value;
};
const exists = async path => access(path).then(() => true, () => false);

let phpBin;
let baseRoot;
let runRoot;
let testEnv;
let fixtureEnv;
let appEnv;
let fixture;
let server;
let gateMutex;
let baseRootLease;
let fixtureSetupAttempted = false;
let lifecycleCleaned = false;

function validateTestDsn(dsn) {
  if (!/^mysql:[^\r\n\0\s]+$/.test(dsn)) throw new Error('Test database must be isolated.');
  const tokens = dsn.slice('mysql:'.length).split(';').filter(Boolean);
  const databases = tokens.filter(token => token.split('=', 1)[0] === 'dbname');
  if (databases.length !== 1 || databases[0] !== 'dbname=cliplab_phase5_test') {
    throw new Error('Test database must be isolated.');
  }
}

function buildExplicitEnvironment() {
  const dsn = requiredEnv('TEST_DB_DSN');
  validateTestDsn(dsn);
  phpBin = requiredEnv('TEST_PHP_BIN');
  const mediaBase = requiredEnv('TEST_MEDIA_PRIVATE_ROOT');
  baseRoot = resolve(mediaBase);
  runRoot = resolve(baseRoot, runId);
  const child = relative(baseRoot, runRoot);
  if (child !== runId || child.startsWith('..') || isAbsolute(child)) {
    throw new Error('Test media root must contain the isolated run.');
  }

  const platformEnv = Object.fromEntries(
    ['PATH', 'SystemRoot', 'TEMP', 'TMP']
      .filter(hasEnv)
      .map(name => [name, process.env[name]])
  );
  testEnv = {
    ...platformEnv,
    APP_ENV: 'testing',
    APP_DEBUG: 'false',
    APP_ENV_FILE: '',
    APP_TIMEZONE: 'UTC',
    DB_DSN: dsn,
    DB_USERNAME: requiredEnv('TEST_DB_USERNAME'),
    DB_PASSWORD: presentEnv('TEST_DB_PASSWORD'),
    MEDIA_DISK: 'local',
    MEDIA_PRIVATE_ROOT: runRoot,
    TEST_MEDIA_PRIVATE_ROOT: baseRoot,
    FFMPEG_BINARY: requiredEnv('TEST_FFMPEG_BIN'),
    FFPROBE_BINARY: requiredEnv('TEST_FFPROBE_BIN'),
    MAIL_TRANSPORT: 'log',
    MAIL_FROM_ADDRESS: 'e2e@cliplab.test',
    MAIL_LOG_FILE: join(runRoot, 'mail.log'),
    GEMINI_API_KEY: '',
    GEMINI_MODEL: '',
  };
  fixtureEnv = {
    ...testEnv,
    SMART_REFRAME_FIXTURE_STATE_KEY: fixtureStateKey,
  };
  appEnv = { ...testEnv, APP_URL: 'http://127.0.0.1:8095' };
}

function startGateMutex() {
  return new Promise((resolvePromise, reject) => {
    const child = spawn(phpBin, [lockScript], {
      cwd: projectRoot,
      shell: false,
      windowsHide: true,
      env: testEnv,
      stdio: ['pipe', 'pipe', 'pipe'],
    });
    let stdout = '';
    let outputBytes = 0;
    let ready = false;
    let readySettled = false;
    let stopped = false;
    let closeResolve;
    const closePromise = new Promise(resolveClose => { closeResolve = resolveClose; });
    const failReady = error => {
      if (readySettled) return;
      readySettled = true;
      clearTimeout(timer);
      if (child.exitCode === null && child.signalCode === null) child.kill();
      reject(error);
    };
    const collect = chunk => {
      outputBytes += chunk.length;
      if (outputBytes > 65536) {
        if (child.exitCode === null && child.signalCode === null) child.kill();
        failReady(new Error('Smart reframe test gate exceeded its output limit.'));
      }
    };
    child.stdout.on('data', chunk => {
      collect(chunk);
      stdout += chunk.toString();
      if (readySettled) return;
      const newline = stdout.indexOf('\n');
      if (newline < 0) return;
      const line = stdout.slice(0, newline).replace(/\r$/, '');
      let handshake;
      try { handshake = JSON.parse(line); } catch { failReady(new Error('Smart reframe test gate returned an invalid handshake.')); return; }
      if (!handshake || handshake.locked !== true || Object.keys(handshake).join(',') !== 'locked') {
        failReady(new Error('Smart reframe test gate returned an invalid handshake.'));
        return;
      }
      ready = true;
      readySettled = true;
      clearTimeout(timer);
      resolvePromise({
        stop: async () => {
          if (stopped) return;
          stopped = true;
          if (child.exitCode === null && child.signalCode === null) child.stdin.end('\n');
          let timeout;
          let result;
          try {
            result = await Promise.race([
              closePromise,
              new Promise((_, rejectStop) => {
                timeout = setTimeout(() => rejectStop(new Error('Smart reframe test gate did not stop.')), 5000);
              }),
            ]);
          } catch (error) {
            if (child.exitCode === null && child.signalCode === null) child.kill();
            await Promise.race([closePromise, new Promise(resolveWait => setTimeout(resolveWait, 1000))]);
            throw error;
          } finally {
            clearTimeout(timeout);
          }
          const lines = stdout.split(/\r?\n/).filter(Boolean);
          if (result.code !== 0 || lines.length !== 1 || lines[0] !== '{"locked":true}') {
            throw new Error('Smart reframe test gate stopped unexpectedly.');
          }
        },
      });
    });
    child.stderr.on('data', collect);
    child.stdin.on('error', () => {});
    child.once('error', () => failReady(new Error('Smart reframe test gate could not start.')));
    child.once('close', (code, signal) => {
      closeResolve({ code, signal });
      if (!ready) failReady(new Error('Smart reframe test gate stopped before its handshake.'));
    });
    const timer = setTimeout(
      () => failReady(new Error('Smart reframe test gate timed out.')),
      65000
    );
  });
}

function oneJsonLine(stdout) {
  const lines = stdout.split(/\r?\n/).filter(line => line !== '');
  if (lines.length !== 1 || lines[0].length > 65536) {
    throw new Error('Isolated PHP command returned invalid output.');
  }
  let value;
  try { value = JSON.parse(lines[0]); } catch { throw new Error('Isolated PHP command returned invalid output.'); }
  if (!value || typeof value !== 'object' || Array.isArray(value)) {
    throw new Error('Isolated PHP command returned invalid output.');
  }
  return value;
}

function runPhp(arguments_, env, timeoutMs = 90000) {
  return new Promise((resolvePromise, reject) => {
    const child = spawn(phpBin, arguments_, {
      cwd: projectRoot,
      shell: false,
      windowsHide: true,
      env,
      stdio: ['ignore', 'pipe', 'pipe'],
    });
    let stdout = '';
    let outputBytes = 0;
    let settled = false;
    const finish = (error, value) => {
      if (settled) return;
      settled = true;
      clearTimeout(timer);
      if (error) reject(error);
      else resolvePromise(value);
    };
    const collect = chunk => {
      outputBytes += chunk.length;
      if (outputBytes > 65536) {
        child.kill();
        finish(new Error('Isolated PHP command exceeded its output limit.'));
      }
    };
    child.stdout.on('data', chunk => { collect(chunk); stdout += chunk.toString(); });
    child.stderr.on('data', collect);
    child.once('error', () => finish(new Error('Isolated PHP command could not start.')));
    child.once('close', code => {
      if (code !== 0) finish(new Error('Isolated PHP command failed.'));
      else finish(null, oneJsonLine(stdout));
    });
    const timer = setTimeout(() => {
      child.kill();
      finish(new Error('Isolated PHP command timed out.'));
    }, timeoutMs);
  });
}

const runFixture = action => runPhp([fixtureScript, action, runId], fixtureEnv);
const runWorker = () => runPhp(
  [resolve(projectRoot, 'bin/process-jobs.php'), '--queue=media', '--limit=1', '--time-budget=50'],
  appEnv,
  90000
);

function validateSetup(value) {
  if (value.run_id !== runId
      || !Number.isSafeInteger(value.project_id) || value.project_id < 1
      || !Number.isSafeInteger(value.clip_id) || value.clip_id < 1
      || !Number.isSafeInteger(value.ledger_count) || value.ledger_count < 0
      || !value.owner || !Number.isSafeInteger(value.owner.id)
      || typeof value.owner.email !== 'string' || typeof value.owner.password !== 'string'
      || !value.foreign || !Number.isSafeInteger(value.foreign.id)
      || typeof value.foreign.email !== 'string' || typeof value.foreign.password !== 'string') {
    throw new Error('Fixture returned invalid setup data.');
  }
}

async function login(page, account) {
  await page.goto(`${server.baseUrl}/login`);
  await page.locator('#email').fill(account.email);
  await page.locator('#password').fill(account.password);
  await Promise.all([
    page.waitForURL(`${server.baseUrl}/dashboard`),
    page.getByRole('button', { name: 'Entrar' }).click(),
  ]);
}

async function cleanupLifecycle() {
  if (lifecycleCleaned) return;
  lifecycleCleaned = true;
  let failure;
  const attempt = async operation => {
    try { await operation(); } catch (error) { failure ??= error; }
  };
  await attempt(async () => server?.stop());
  await attempt(async () => {
    if (fixtureSetupAttempted && testEnv && baseRoot && await exists(baseRoot)) {
      await runFixture('cleanup');
      if (runRoot && await exists(runRoot)) throw new Error('Fixture cleanup left its isolated root behind.');
    }
  });
  await attempt(async () => baseRootLease?.cleanup());
  await attempt(async () => gateMutex?.stop());
  if (failure) throw failure;
}

test.beforeAll(async () => {
  buildExplicitEnvironment();
  gateMutex = await startGateMutex();
  try {
    baseRootLease = await prepareIsolatedBaseRoot(baseRoot, resolve(projectRoot, 'public'));
    fixtureSetupAttempted = true;
    fixture = await runFixture('setup');
    validateSetup(fixture);
    server = await startLocalPhpServer({
      phpBin,
      port: 8095,
      root: resolve(projectRoot, 'public'),
      env: appEnv,
    });
  } catch (error) {
    try {
      await cleanupLifecycle();
    } catch (cleanupError) {
      throw new AggregateError([error, cleanupError], 'Smart reframe E2E setup and cleanup failed.');
    }
    throw error;
  }
});

test.afterAll(async () => {
  await cleanupLifecycle();
});

test.use({ channel: 'chrome' });
test.setTimeout(120000);

test('authenticated owner completes automatic smart reframe without provider or shared state', async ({ page }) => {
  const requests = [];
  const external = [];
  const recordRequest = request => {
    const url = new URL(request.url());
    requests.push(url.pathname);
    if (url.origin !== server.baseUrl) external.push(request.url());
  };
  page.context().on('request', recordRequest);

  await page.setViewportSize({ width: 320, height: 800 });
  await login(page, fixture.owner);
  const projectUrl = `${server.baseUrl}/projetos/${fixture.project_id}`;
  await page.goto(projectUrl);

  let card = page.locator(`[data-clip-card="${fixture.clip_id}"]`);
  let editor = card.locator('[data-reframe-editor]');
  await expect(card).toBeVisible();
  await expect(editor).toHaveAttribute('data-consent-active', '0');
  expect(requests.some(path => path.includes('/assets/vendor/mediapipe-tasks-vision-1.0.1/'))).toBe(false);
  expect(requests.includes('/assets/js/reframe-worker.js')).toBe(false);

  await card.locator('[data-reframe-ratio]').selectOption('9:16');
  await card.locator('[data-reframe-mode]').selectOption('manual');
  await card.locator('[name="focus_x"]').fill('0.250000');
  await card.locator('[name="focus_y"]').fill('0.500000');
  const previewRequestStart = requests.length;
  const previewExternalStart = external.length;
  await card.locator('[data-reframe-preview-open]').click();
  await expect(card.locator('[data-reframe-status]')).toHaveText('Prévia pronta.', { timeout: 15000 });
  const previewRequests = requests.slice(previewRequestStart);
  expect(previewRequests.length).toBeGreaterThan(0);
  expect(previewRequests.every(path => path === `/clips/${fixture.clip_id}/source-preview`)).toBe(true);
  expect(external.slice(previewExternalStart)).toEqual([]);

  const previewStage = card.locator('.reframe-preview-stage');
  await expect(previewStage).toBeVisible();
  expect(await previewStage.locator(':scope > *').evaluateAll(children => children.map(child => child.tagName)))
    .toEqual(['VIDEO', 'CANVAS']);
  await previewStage.evaluate(stage => { stage.style.aspectRatio = '1 / 1'; });
  const video = card.locator('[data-reframe-preview]');
  const overlay = card.locator('[data-reframe-overlay]');
  const focusToggle = card.locator('[data-reframe-focus-edit]');
  await expect(focusToggle).toBeVisible();
  await expect(focusToggle).toBeEnabled();
  await expect(focusToggle).toHaveAttribute('aria-pressed', 'false');
  await expect(overlay).toHaveAttribute('aria-hidden', 'true');
  await expect(overlay).toHaveAttribute('tabindex', '-1');

  const hitTargetBeforeEditing = await previewStage.evaluate(stage => {
    const videoNode = stage.querySelector('video');
    if (!videoNode) throw new Error('Preview video unavailable.');
    const bounds = videoNode.getBoundingClientRect();
    return document.elementFromPoint(bounds.left + bounds.width / 2, bounds.top + bounds.height / 2)?.tagName;
  });
  expect(hitTargetBeforeEditing).toBe('VIDEO');
  await video.click({ position: { x: 10, y: 10 } });
  await expect.poll(() => video.evaluate(node => node.paused)).toBe(false);
  await video.evaluate(node => node.pause());

  const focusToggleBox = await focusToggle.boundingBox();
  expect(focusToggleBox).not.toBeNull();
  expect(focusToggleBox.x).toBeGreaterThanOrEqual(0);
  expect(focusToggleBox.x + focusToggleBox.width).toBeLessThanOrEqual(320);
  await focusToggle.click();
  await expect(focusToggle).toHaveAttribute('aria-pressed', 'true');
  await expect(overlay).toHaveAttribute('aria-hidden', 'false');
  await expect(overlay).toHaveAttribute('tabindex', '0');
  await expect(overlay).toBeFocused();
  const hitTargetWhileEditing = await previewStage.evaluate(stage => {
    const videoNode = stage.querySelector('video');
    if (!videoNode) throw new Error('Preview video unavailable.');
    const bounds = videoNode.getBoundingClientRect();
    return document.elementFromPoint(bounds.left + bounds.width / 2, bounds.top + bounds.height / 2)?.tagName;
  });
  expect(hitTargetWhileEditing).toBe('CANVAS');

  const pointer = await overlay.evaluate(canvas => {
    const video = canvas.parentElement?.querySelector('video');
    if (!video || video.videoWidth <= 0 || video.videoHeight <= 0) throw new Error('Preview geometry unavailable.');
    const canvasRect = canvas.getBoundingClientRect();
    const videoRect = video.getBoundingClientRect();
    const scale = Math.min(videoRect.width / video.videoWidth, videoRect.height / video.videoHeight);
    const width = video.videoWidth * scale;
    const height = video.videoHeight * scale;
    return {
      x: videoRect.left - canvasRect.left + (videoRect.width - width) / 2 + width * 0.25,
      y: videoRect.top - canvasRect.top + (videoRect.height - height) / 2 + height * 0.75,
    };
  });
  await overlay.click({ position: pointer });
  expect(Number(await card.locator('[name="focus_x"]').inputValue())).toBeCloseTo(0.25, 4);
  expect(Number(await card.locator('[name="focus_y"]').inputValue())).toBeCloseTo(0.75, 4);
  await overlay.press('Escape');
  await expect(focusToggle).toHaveAttribute('aria-pressed', 'false');
  await expect(overlay).toHaveAttribute('aria-hidden', 'true');
  await expect(overlay).toHaveAttribute('tabindex', '-1');
  const hitTargetAfterEditing = await previewStage.evaluate(stage => {
    const videoNode = stage.querySelector('video');
    if (!videoNode) throw new Error('Preview video unavailable.');
    const bounds = videoNode.getBoundingClientRect();
    return document.elementFromPoint(bounds.left + bounds.width / 2, bounds.top + bounds.height / 2)?.tagName;
  });
  expect(hitTargetAfterEditing).toBe('VIDEO');
  await previewStage.evaluate(stage => { stage.style.aspectRatio = ''; });

  await card.locator('[data-reframe-mode]').selectOption('center');
  await expect(card.locator('[name="focus_x"]')).toHaveValue('');
  await expect(card.locator('[name="focus_y"]')).toHaveValue('');
  await card.locator('[data-reframe-mode]').selectOption('original');
  await expect(card.locator('[data-reframe-ratio]')).toHaveValue('original');
  await card.locator('[data-reframe-ratio]').selectOption('1:1');
  await expect(card.locator('[data-reframe-mode]')).toHaveValue('center');
  await expect(card.locator('[name="focus_x"]')).toHaveValue('');
  await expect(card.locator('[name="focus_y"]')).toHaveValue('');

  const range = await page.evaluate(async clipId => {
    const response = await fetch(`/clips/${clipId}/source-preview`, { headers: { Range: 'bytes=0-31' } });
    return {
      status: response.status,
      contentRange: response.headers.get('content-range'),
      bytes: (await response.arrayBuffer()).byteLength,
    };
  }, fixture.clip_id);
  expect(range.status).toBe(206);
  expect(range.contentRange).toMatch(/^bytes 0-31\/[1-9][0-9]*$/);
  expect(range.bytes).toBe(32);

  const consentButton = page.getByRole('button', { name: 'Concordar e ativar enquadramento inteligente' });
  await Promise.all([page.waitForNavigation(), consentButton.click()]);
  await expect(page).toHaveURL(projectUrl);
  card = page.locator(`[data-clip-card="${fixture.clip_id}"]`);
  editor = card.locator('[data-reframe-editor]');
  await expect(editor).toHaveAttribute('data-consent-active', '1');
  expect(requests.some(path => path.includes('/assets/vendor/mediapipe-tasks-vision-1.0.1/'))).toBe(false);
  expect(requests.includes('/assets/js/reframe-worker.js')).toBe(false);

  const automaticRequestStart = requests.length;
  await card.locator('[data-reframe-ratio]').selectOption('4:5');
  await card.locator('[data-reframe-mode]').selectOption('manual');
  await card.locator('[data-reframe-auto]').click();
  await expect(card.locator('[data-reframe-status]')).toHaveText(
    'Enquadramento inteligente pronto.',
    { timeout: 45000 }
  );
  await expect(card.locator('[data-reframe-mode]')).toHaveValue('auto');
  const serialized = await card.locator('[data-reframe-keyframes]').inputValue();
  expect(serialized).not.toMatch(/\s/);
  const keyframes = JSON.parse(serialized);
  expect(keyframes.length).toBeGreaterThanOrEqual(2);
  expect(keyframes.length).toBeLessThanOrEqual(32);
  expect(keyframes[0].at_ms).toBe(0);
  expect(keyframes.at(-1).at_ms).toBe(2000);
  expect(keyframes.every(point => (
    Object.keys(point).join(',') === 'at_ms,center_x,center_y'
    && Number.isFinite(point.center_x) && point.center_x >= 0 && point.center_x <= 1
    && Number.isFinite(point.center_y) && point.center_y >= 0 && point.center_y <= 1
  ))).toBe(true);
  const automaticRequests = requests.slice(automaticRequestStart);
  expect(automaticRequests).toContain('/assets/js/reframe-worker.js');
  expect(automaticRequests.some(path => path.endsWith('/vision_bundle.mjs'))).toBe(true);
  expect(automaticRequests.some(path => path.endsWith('.wasm'))).toBe(true);
  expect(automaticRequests.some(path => path.endsWith('.tflite'))).toBe(true);

  const form = card.locator('form.clip-render-form');
  await Promise.all([
    page.waitForNavigation(),
    form.getByRole('button', { name: 'Aprovar e renderizar' }).click(),
  ]);
  await expect(page).toHaveURL(projectUrl);
  const libraryPage = await page.context().newPage();
  await libraryPage.goto(`${server.baseUrl}/clips`);
  const libraryCard = libraryPage.locator(`[data-clip-card="${fixture.clip_id}"]`);
  await expect(libraryCard).toBeVisible();
  await expect(libraryCard).toHaveClass(/\bclip-status-queued\b/);
  await expect(libraryCard.locator('[data-clip-status]')).toHaveText(/^Na fila(?: para renderização)?$/);
  await expect(libraryCard.locator('[data-clip-download]')).toBeHidden();
  await expect(libraryCard.locator('[data-clip-download]')).not.toHaveAttribute('href');
  await expect(libraryCard.locator('[data-clip-thumbnail]')).toBeHidden();
  await expect(libraryCard.locator('[data-clip-thumbnail]')).not.toHaveAttribute('src');
  const workerReport = await runWorker();
  expect(workerReport.claimed).toBe(1);
  expect(workerReport.completed).toBe(1);
  expect(workerReport.retried).toBe(0);
  expect(workerReport.deferred).toBe(0);
  expect(workerReport.failed).toBe(0);
  expect(workerReport.operational_errors).toBe(0);

  await expect(libraryCard.locator('[data-clip-status]')).toHaveText('Concluído', { timeout: 20000 });
  await expect(libraryCard.locator('[data-clip-thumbnail]')).toBeVisible();
  await expect(libraryCard.locator('[data-clip-download]')).toHaveAttribute('href', `/clips/${fixture.clip_id}/download`);
  await libraryPage.goto(`${server.baseUrl}/clips?filter=completed`);
  await expect(libraryCard).toBeVisible();
  for (const width of [320, 768, 1440]) {
    await libraryPage.setViewportSize({ width, height: 1000 });
    await expect(libraryCard).toBeVisible();
    expect(await libraryPage.evaluate(() => document.documentElement.scrollWidth <= document.documentElement.clientWidth)).toBe(true);
  }
  await libraryPage.screenshot({
    path: resolve(projectRoot, '.superpowers/sdd/2026-09-06-biblioteca-clipes/library-desktop.png'),
    fullPage: true,
  });
  const noJsContext = await page.context().browser().newContext({
    storageState: await page.context().storageState(),
    javaScriptEnabled: false,
    viewport: { width: 320, height: 900 },
  });
  noJsContext.on('request', recordRequest);
  try {
    const noJsPage = await noJsContext.newPage();
    await noJsPage.goto(`${server.baseUrl}/clips?filter=completed`);
    const noJsCard = noJsPage.locator(`[data-clip-card="${fixture.clip_id}"]`);
    await expect(noJsCard).toBeVisible();
    await expect(noJsCard.locator('[data-clip-download]')).toHaveAttribute('href', `/clips/${fixture.clip_id}/download`);
    expect(await noJsPage.evaluate(() => document.documentElement.scrollWidth <= document.documentElement.clientWidth)).toBe(true);
  } finally {
    await noJsContext.close();
    await libraryPage.close();
  }

  card = page.locator(`[data-clip-card="${fixture.clip_id}"]`);
  await expect(card.locator('[data-clip-status]')).toHaveText('Concluído', { timeout: 20000 });
  await expect(card.locator('.reframe-badge')).toContainText('4:5');
  await expect(card.locator('.reframe-badge')).toContainText('Automático');
  const thumbnail = card.locator('[data-clip-thumbnail]');
  await expect(thumbnail).toBeVisible();
  await expect.poll(() => thumbnail.evaluate(image => image.naturalWidth)).toBeGreaterThan(0);
  const thumbnailSize = await thumbnail.evaluate(image => [image.naturalWidth, image.naturalHeight]);
  expect(thumbnailSize[0] / thumbnailSize[1]).toBeCloseTo(4 / 5, 2);

  const contextRequest = page.context().request;
  const ownerThumbnail = await contextRequest.get(`${server.baseUrl}/clips/${fixture.clip_id}/thumbnail`);
  expect(ownerThumbnail.status()).toBe(200);
  expect(ownerThumbnail.headers()['content-type']).toBe('image/jpeg');
  expect((await ownerThumbnail.body()).length).toBeGreaterThan(0);
  const ownerDownload = await contextRequest.get(`${server.baseUrl}/clips/${fixture.clip_id}/download`);
  expect(ownerDownload.status()).toBe(200);
  expect(ownerDownload.headers()['content-type']).toBe('video/mp4');
  expect((await ownerDownload.body()).length).toBeGreaterThan(0);

  for (const width of [320, 768, 1440]) {
    await page.setViewportSize({ width, height: 900 });
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= document.documentElement.clientWidth)).toBe(true);
  }

  await page.context().clearCookies();
  await login(page, fixture.foreign);
  const foreignLibrary = await page.context().request.get(`${server.baseUrl}/clips?filter=completed`);
  expect(foreignLibrary.status()).toBe(200);
  expect(await foreignLibrary.text()).not.toContain(`data-clip-card="${fixture.clip_id}"`);
  for (const path of [
    `/api/clips/${fixture.clip_id}/status`,
    `/clips/${fixture.clip_id}/source-preview`,
    `/clips/${fixture.clip_id}/thumbnail`,
    `/clips/${fixture.clip_id}/download`,
  ]) {
    const response = await page.context().request.get(`${server.baseUrl}${path}`, { maxRedirects: 0 });
    expect(response.status()).toBe(404);
  }

  await page.context().clearCookies();
  const guestStatus = await page.context().request.get(
    `${server.baseUrl}/api/clips/${fixture.clip_id}/status`,
    { maxRedirects: 0 }
  );
  expect(guestStatus.status()).toBe(401);
  for (const path of [
    `/clips/${fixture.clip_id}/source-preview`,
    `/clips/${fixture.clip_id}/thumbnail`,
    `/clips/${fixture.clip_id}/download`,
  ]) {
    const response = await page.context().request.get(`${server.baseUrl}${path}`, { maxRedirects: 0 });
    expect(response.status()).toBe(302);
    expect(response.headers().location).toBe('/login');
  }

  const inspection = await runFixture('inspect');
  expect(inspection.run_id).toBe(runId);
  expect(inspection.clip_status).toBe('completed');
  expect(inspection.render_revision).toBe(1);
  expect(inspection.job_count).toBe(1);
  expect(inspection.job_id).toBeGreaterThan(0);
  expect(inspection.job_project_id).toBe(fixture.project_id);
  expect(inspection.job_queue_name).toBe('media');
  expect(inspection.job_type).toBe('render_clip');
  expect(inspection.job_status).toBe('completed');
  expect(inspection.job_payload).toEqual({ clip_id: fixture.clip_id, render_revision: 1 });
  expect(inspection.profile_count).toBe(1);
  expect(inspection.aspect_ratio).toBe('4:5');
  expect(inspection.reframe_mode).toBe('auto');
  expect(inspection.keyframe_count).toBeGreaterThanOrEqual(2);
  expect(inspection.keyframe_count).toBeLessThanOrEqual(32);
  expect(inspection.ledger_count).toBe(fixture.ledger_count);
  expect(inspection.outbox_count).toBe(0);
  expect(inspection.temporary_count).toBe(0);
  expect(inspection.video_exists).toBe(true);
  expect(inspection.thumbnail_exists).toBe(true);
  expect(inspection.video_size_bytes).toBeGreaterThan(0);
  expect(inspection.thumbnail_size_bytes).toBeGreaterThan(0);
  expect(external).toEqual([]);
});
