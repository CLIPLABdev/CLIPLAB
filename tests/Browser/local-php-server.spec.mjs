import { test, expect } from '@playwright/test';
import { mkdtemp, rm, writeFile } from 'node:fs/promises';
import { createServer } from 'node:http';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { startLocalPhpServer } from './support/local-php-server.mjs';

test('local PHP server helper rejects an unlaunchable executable without an unhandled error', async () => {
  await expect(startLocalPhpServer({
    phpBin: '__clipforge_missing_php_binary__',
    port: 8194,
    root: process.cwd(),
  })).rejects.toThrow();
});

test('local PHP server helper passes only its explicit environment', async () => {
  const phpBin = process.env.TEST_PHP_BIN;
  if (typeof phpBin !== 'string' || phpBin.trim() === '') {
    throw new Error('TEST_PHP_BIN is required');
  }
  const root = await mkdtemp(join(tmpdir(), 'clipforge-server-env-'));
  const inheritedProbe = 'CLIPFORGE_SERVER_ENV_LEAK_PROBE';
  const previous = process.env[inheritedProbe];
  process.env[inheritedProbe] = 'must-not-leak';
  let server;
  try {
    await writeFile(
      join(root, 'index.php'),
      '<?php header("Content-Type: application/json"); echo json_encode(['
        + '"inherited" => getenv("CLIPFORGE_SERVER_ENV_LEAK_PROBE") !== false,'
        + '"allowed" => getenv("CLIPFORGE_SERVER_ALLOWED")]);',
      'utf8'
    );
    server = await startLocalPhpServer({
      phpBin,
      port: 8195,
      root,
      env: { CLIPFORGE_SERVER_ALLOWED: 'explicit' },
    });
    const response = await fetch(server.baseUrl);
    expect(await response.json()).toEqual({ inherited: false, allowed: 'explicit' });
  } finally {
    await server?.stop();
    if (previous === undefined) delete process.env[inheritedProbe];
    else process.env[inheritedProbe] = previous;
    await rm(root, { recursive: true, force: true });
  }
});

test('local PHP server helper rejects a foreign service already occupying the port', async () => {
  const phpBin = process.env.TEST_PHP_BIN;
  if (typeof phpBin !== 'string' || phpBin.trim() === '') {
    throw new Error('TEST_PHP_BIN is required');
  }
  const candidateRoot = await mkdtemp(join(tmpdir(), 'clipforge-server-candidate-'));
  const occupied = createServer((request, response) => {
    response.writeHead(200, { 'Content-Type': 'text/plain' });
    response.end('occupied');
  });
  let incorrectlyAccepted;
  let startupError;
  try {
    await writeFile(join(candidateRoot, 'index.php'), '<?php echo "candidate";', 'utf8');
    await new Promise((resolvePromise, reject) => {
      occupied.once('error', reject);
      occupied.listen(0, '127.0.0.1', resolvePromise);
    });
    const address = occupied.address();
    if (!address || typeof address === 'string') throw new Error('Foreign service did not bind a TCP port.');
    try {
      incorrectlyAccepted = await startLocalPhpServer({ phpBin, port: address.port, root: candidateRoot });
    } catch (error) {
      startupError = error;
    }
    expect(incorrectlyAccepted).toBeUndefined();
    expect(startupError).toBeInstanceOf(Error);
    expect(await (await fetch(`http://127.0.0.1:${address.port}`)).text()).toBe('occupied');
  } finally {
    await incorrectlyAccepted?.stop();
    occupied.closeAllConnections();
    await new Promise(resolvePromise => occupied.close(resolvePromise));
    await rm(candidateRoot, { recursive: true, force: true });
  }
});
