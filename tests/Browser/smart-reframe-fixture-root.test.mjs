import assert from 'node:assert/strict';
import { randomBytes, randomUUID } from 'node:crypto';
import { spawnSync } from 'node:child_process';
import { access, mkdir, mkdtemp, readFile, rm, rmdir, symlink, writeFile } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { dirname, join, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { prepareIsolatedBaseRoot } from './support/isolated-media-root.mjs';

const here = dirname(fileURLToPath(import.meta.url));
const projectRoot = resolve(here, '../..');
const fixture = resolve(here, 'support/smart-reframe-e2e-fixture.php');
const safetyHelper = resolve(here, 'support/smart-reframe-fixture-safety.php');
const phpBin = process.env.TEST_PHP_BIN;
const dsn = process.env.TEST_DB_DSN;
const ffmpeg = process.env.TEST_FFMPEG_BIN;
const ffprobe = process.env.TEST_FFPROBE_BIN;
if (typeof phpBin !== 'string' || phpBin.trim() === ''
    || typeof dsn !== 'string' || dsn.trim() === ''
    || typeof ffmpeg !== 'string' || ffmpeg.trim() === ''
    || typeof ffprobe !== 'string' || ffprobe.trim() === '') {
  throw new Error('The real PHP, FFmpeg, FFprobe, and isolated test database are required.');
}

const testRoot = await mkdtemp(join(tmpdir(), 'cliplab-fixture-root-'));
const authorizedRoot = join(testRoot, 'authorized');
const outsideRoot = join(testRoot, 'outside');
const runId = `sr_${randomUUID().replaceAll('-', '')}`;
const runRoot = join(authorizedRoot, runId);
const externalLink = join(runRoot, 'external-link');
const insideSentinel = join(runRoot, 'inside.txt');
const outsideSentinel = join(outsideRoot, 'outside.txt');
const statePath = join(runRoot, 'fixture-state.json');
const fixtureStateKey = randomBytes(32).toString('hex');
const platformEnv = Object.fromEntries(
  ['PATH', 'SystemRoot', 'TEMP', 'TMP']
    .filter(name => Object.prototype.hasOwnProperty.call(process.env, name))
    .map(name => [name, process.env[name]])
);
const commandEnv = {
  ...platformEnv,
  APP_ENV_FILE: '',
  DB_DSN: dsn,
  DB_USERNAME: process.env.TEST_DB_USERNAME ?? '',
  DB_PASSWORD: process.env.TEST_DB_PASSWORD ?? '',
  MEDIA_PRIVATE_ROOT: runRoot,
  TEST_MEDIA_PRIVATE_ROOT: authorizedRoot,
  FFMPEG_BINARY: ffmpeg,
  FFPROBE_BINARY: ffprobe,
  GEMINI_API_KEY: '',
  GEMINI_MODEL: '',
};
const fixtureEnv = {
  ...commandEnv,
  SMART_REFRAME_FIXTURE_STATE_KEY: fixtureStateKey,
};

const exists = path => access(path).then(() => true, () => false);
const invoke = (script, action, id = runId, env = fixtureEnv, timeout = 90000) => spawnSync(
  phpBin,
  [script, action, id],
  {
    cwd: projectRoot,
    shell: false,
    windowsHide: true,
    encoding: 'utf8',
    timeout,
    env,
  }
);
const parseSuccess = result => {
  assert.equal(result.status, 0, result.stderr || 'fixture command must succeed');
  const lines = result.stdout.split(/\r?\n/).filter(Boolean);
  assert.equal(lines.length, 1, 'fixture command must emit exactly one JSON line');
  return JSON.parse(lines[0]);
};
const invokeSafety = action => invoke(safetyHelper, action, runId, commandEnv);

let validState;
let fixtureSetup = false;
let linkCreated = false;
let ancestorLinkCreated = false;
let safetyPrepared = false;
try {
  await mkdir(authorizedRoot);
  await mkdir(outsideRoot);
  const prepared = parseSuccess(invokeSafety('prepare'));
  safetyPrepared = true;
  const setupResult = invoke(fixture, 'setup');
  assert.equal(setupResult.stdout.includes(fixtureStateKey), false, 'fixture output must never contain its state key');
  const setup = parseSuccess(setupResult);
  fixtureSetup = true;
  assert.equal(setup.run_id, runId);

  validState = await readFile(statePath, 'utf8');
  assert.equal(validState.includes(fixtureStateKey), false, 'authenticated state must never contain its key');
  const sourcePath = join(runRoot, 'sources', String(setup.project_id), `source-${runId}.mp4`);
  const sourceBytes = await readFile(sourcePath);
  const tamperedEnvelope = JSON.parse(validState);
  assert.deepEqual(Object.keys(tamperedEnvelope).sort(), ['mac', 'payload']);
  assert.match(tamperedEnvelope.mac, /^[a-f0-9]{64}$/);
  const nonNullRateSnapshot = tamperedEnvelope.payload.rate_snapshots.find(snapshot => snapshot.row !== null);
  assert.ok(nonNullRateSnapshot, 'fixture must capture the prepared non-null rate snapshot');
  nonNullRateSnapshot.row = null;
  const tamperedState = JSON.stringify(tamperedEnvelope);
  await writeFile(statePath, tamperedState, 'utf8');
  parseSuccess(invokeSafety('mutate'));
  await writeFile(insideSentinel, 'inside', 'utf8');
  await writeFile(outsideSentinel, 'outside', 'utf8');
  await symlink(outsideRoot, externalLink, process.platform === 'win32' ? 'junction' : 'dir');
  linkCreated = true;

  const result = invoke(fixture, 'cleanup');

  assert.notEqual(result.status, 0, 'cleanup must reject a linked entry before deleting anything');
  assert.equal(result.stdout, '', 'rejected cleanup must not emit fixture data');
  assert.deepEqual(parseSuccess(invokeSafety('audit')), {
    fixture_projects: 1,
    fixture_users: 2,
    fixture_plans: 1,
    guard_projects: 1,
    rate_rows: 1,
    rate_attempts: 13,
  }, 'rejected cleanup must preserve fixture, unrelated guard, and rate-limit rows');
  assert.equal(await readFile(insideSentinel, 'utf8'), 'inside');
  assert.equal(await readFile(outsideSentinel, 'utf8'), 'outside');
  assert.equal(await readFile(statePath, 'utf8'), tamperedState);
  assert.deepEqual(await readFile(sourcePath), sourceBytes);

  await rmdir(externalLink);
  linkCreated = false;
  const tamperedOnlyResult = invoke(fixture, 'cleanup');
  assert.deepEqual(parseSuccess(invokeSafety('audit')), {
    fixture_projects: 1,
    fixture_users: 2,
    fixture_plans: 1,
    guard_projects: 1,
    rate_rows: 1,
    rate_attempts: 13,
  }, 'snapshot authentication rejection must preserve database and rate-limit rows');
  assert.notEqual(tamperedOnlyResult.status, 0, 'cleanup must reject a forged null rate snapshot');
  assert.equal(tamperedOnlyResult.stdout, '', 'rejected state must not emit fixture data');
  assert.equal(await readFile(insideSentinel, 'utf8'), 'inside');
  assert.equal(await readFile(outsideSentinel, 'utf8'), 'outside');
  assert.equal(await readFile(statePath, 'utf8'), tamperedState);
  assert.deepEqual(await readFile(sourcePath), sourceBytes);

  const projectEnvelope = JSON.parse(validState);
  projectEnvelope.payload.project_id = prepared.guard_project_id;
  const projectState = JSON.stringify(projectEnvelope);
  await writeFile(statePath, projectState, 'utf8');
  const projectResult = invoke(fixture, 'cleanup');
  assert.notEqual(projectResult.status, 0, 'cleanup must reject a forged project ID');
  assert.deepEqual(parseSuccess(invokeSafety('audit')), {
    fixture_projects: 1,
    fixture_users: 2,
    fixture_plans: 1,
    guard_projects: 1,
    rate_rows: 1,
    rate_attempts: 13,
  });
  assert.equal(await readFile(statePath, 'utf8'), projectState);
  assert.deepEqual(await readFile(sourcePath), sourceBytes);

  const unsignedEnvelope = JSON.parse(validState);
  delete unsignedEnvelope.mac;
  const unsignedState = JSON.stringify(unsignedEnvelope);
  await writeFile(statePath, unsignedState, 'utf8');
  const unsignedResult = invoke(fixture, 'cleanup');
  assert.notEqual(unsignedResult.status, 0, 'cleanup must reject state without a MAC');
  assert.deepEqual(parseSuccess(invokeSafety('audit')), {
    fixture_projects: 1,
    fixture_users: 2,
    fixture_plans: 1,
    guard_projects: 1,
    rate_rows: 1,
    rate_attempts: 13,
  });
  assert.equal(await readFile(statePath, 'utf8'), unsignedState);
  assert.deepEqual(await readFile(sourcePath), sourceBytes);

  await writeFile(statePath, validState, 'utf8');
  const wrongKey = '0'.repeat(64);
  assert.notEqual(fixtureStateKey, wrongKey);
  const wrongKeyResult = invoke(fixture, 'cleanup', runId, {
    ...commandEnv,
    SMART_REFRAME_FIXTURE_STATE_KEY: wrongKey,
  });
  assert.notEqual(wrongKeyResult.status, 0, 'cleanup must reject a valid envelope with the wrong key');
  assert.deepEqual(parseSuccess(invokeSafety('audit')), {
    fixture_projects: 1,
    fixture_users: 2,
    fixture_plans: 1,
    guard_projects: 1,
    rate_rows: 1,
    rate_attempts: 13,
  });
  assert.equal(await readFile(statePath, 'utf8'), validState);
  assert.deepEqual(await readFile(sourcePath), sourceBytes);

  parseSuccess(invoke(fixture, 'cleanup'));
  fixtureSetup = false;
  assert.equal(await exists(runRoot), false, 'normal cleanup must remove the validated run root');
  assert.deepEqual(parseSuccess(invokeSafety('audit')), {
    fixture_projects: 0,
    fixture_users: 0,
    fixture_plans: 0,
    guard_projects: 1,
    rate_rows: 1,
    rate_attempts: 7,
  }, 'normal cleanup must remove only run rows and restore its exact rate snapshot');

  const missingRunId = `sr_${randomUUID().replaceAll('-', '')}`;
  const missingBase = join(testRoot, 'validated-parent', 'missing-base');
  const missingRunRoot = join(missingBase, missingRunId);
  await mkdir(dirname(missingBase));
  const missingEnv = {
    ...fixtureEnv,
    MEDIA_PRIVATE_ROOT: missingRunRoot,
    TEST_MEDIA_PRIVATE_ROOT: missingBase,
  };
  parseSuccess(invoke(fixture, 'setup', missingRunId, missingEnv));
  parseSuccess(invoke(fixture, 'cleanup', missingRunId, missingEnv));
  assert.equal(await exists(missingRunRoot), false, 'a validated missing root must have a normal lifecycle');
  const nodeParent = join(testRoot, 'node-parent');
  const nodeBase = join(nodeParent, 'missing-base');
  await mkdir(nodeParent);
  const nodeLease = await prepareIsolatedBaseRoot(nodeBase, join(projectRoot, 'public'));
  assert.equal(await exists(nodeBase), true, 'Node preflight must create only its validated missing base');
  await nodeLease.cleanup();
  assert.equal(await exists(nodeBase), false, 'Node cleanup must reverse only paths that it created');

  const publicRunId = `sr_${randomUUID().replaceAll('-', '')}`;
  const publicBase = join(projectRoot, 'public', `fixture-root-${publicRunId}`);
  const publicEnv = {
    ...fixtureEnv,
    MEDIA_PRIVATE_ROOT: join(publicBase, publicRunId),
    TEST_MEDIA_PRIVATE_ROOT: publicBase,
  };
  await assert.rejects(
    prepareIsolatedBaseRoot(publicBase, join(projectRoot, 'public')),
    /Test media root is unsafe/,
    'Node preflight must reject public before mkdir'
  );
  const publicResult = invoke(fixture, 'setup', publicRunId, publicEnv);
  assert.notEqual(publicResult.status, 0, 'a missing root under public must fail before creation');
  assert.equal(await exists(publicBase), false, 'rejected public root must never be created');

  const junctionTarget = join(testRoot, 'junction-target');
  const junctionAncestor = join(testRoot, 'junction-ancestor');
  await mkdir(junctionTarget);
  await symlink(junctionTarget, junctionAncestor, process.platform === 'win32' ? 'junction' : 'dir');
  ancestorLinkCreated = true;
  const junctionRunId = `sr_${randomUUID().replaceAll('-', '')}`;
  const junctionBase = join(junctionAncestor, 'missing-base');
  const junctionEnv = {
    ...fixtureEnv,
    MEDIA_PRIVATE_ROOT: join(junctionBase, junctionRunId),
    TEST_MEDIA_PRIVATE_ROOT: junctionBase,
  };
  await assert.rejects(
    prepareIsolatedBaseRoot(junctionBase, join(projectRoot, 'public')),
    /Test media root is unsafe/,
    'Node preflight must reject an ancestor junction before mkdir'
  );
  const junctionResult = invoke(fixture, 'setup', junctionRunId, junctionEnv);
  assert.notEqual(junctionResult.status, 0, 'a missing root below a junction must fail before creation');
  assert.equal(
    await exists(join(junctionTarget, 'missing-base')),
    false,
    'junction rejection must not create through its target'
  );
  await rmdir(junctionAncestor);
  ancestorLinkCreated = false;

  console.log('PASS fixture validates roots and state before any mutation');
} finally {
  if (linkCreated && await exists(externalLink)) await rmdir(externalLink);
  if (ancestorLinkCreated && await exists(junctionAncestor)) await rmdir(junctionAncestor);
  if (fixtureSetup && validState && await exists(runRoot)) {
    await writeFile(statePath, validState, 'utf8');
    invoke(fixture, 'cleanup');
  }
  if (safetyPrepared) invokeSafety('teardown');
  await rm(testRoot, { recursive: true, force: true });
}
