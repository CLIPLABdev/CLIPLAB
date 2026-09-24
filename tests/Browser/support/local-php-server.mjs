import { spawn } from 'node:child_process';
import { randomBytes } from 'node:crypto';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const sleep = milliseconds => new Promise(resolve => setTimeout(resolve, milliseconds));
const router = resolve(dirname(fileURLToPath(import.meta.url)), 'local-php-router.php');

async function stopChild(child, exited) {
  if (child.exitCode === null && child.signalCode === null && !child.killed) child.kill();
  await Promise.race([exited, sleep(3000)]);
}

export async function startLocalPhpServer({ phpBin, port, root, env = {} }) {
  if (!phpBin) throw new Error('phpBin is required');
  const baseUrl = `http://127.0.0.1:${port}`;
  const documentRoot = resolve(root);
  const nonce = randomBytes(16).toString('hex');
  const child = spawn(phpBin, ['-S', `127.0.0.1:${port}`, '-t', documentRoot, router], {
    shell: false,
    windowsHide: true,
    env: {
      ...env,
      CLIPLAB_TEST_SERVER_NONCE: nonce,
      CLIPLAB_TEST_SERVER_ROOT: documentRoot,
    },
    stdio: ['ignore', 'pipe', 'pipe'],
  });
  let startupError = '';
  child.stderr.on('data', chunk => { startupError += chunk.toString(); });
  const exited = new Promise(resolve => {
    child.once('exit', (code, signal) => resolve({ code, signal }));
    child.once('error', error => resolve({ error }));
  });
  try {
    for (let attempt = 0; attempt < 50; attempt += 1) {
      const terminal = await Promise.race([
        fetch(`${baseUrl}/__cliplab_test_server_ready`, { signal: AbortSignal.timeout(500) })
          .then(async response => ({ response, body: await response.text() }))
          .catch(() => ({ response: null })),
        exited,
      ]);
      if (terminal?.error) throw terminal.error;
      if (terminal?.code !== undefined) throw new Error(`PHP server exited during startup: ${startupError}`);
      if (terminal?.response?.status === 200 && terminal.body === nonce) {
        return { baseUrl, stop: () => stopChild(child, exited) };
      }
      await sleep(100);
    }
    throw new Error(`PHP server did not become ready: ${startupError}`);
  } catch (error) {
    await stopChild(child, exited);
    throw error;
  }
}
