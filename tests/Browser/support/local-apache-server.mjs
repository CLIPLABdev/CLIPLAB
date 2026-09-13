import { spawn } from 'node:child_process';
import { mkdtemp, readFile, rm, writeFile } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { dirname, join, resolve } from 'node:path';

const sleep = milliseconds => new Promise(resolve => setTimeout(resolve, milliseconds));
const apachePath = process.env.TEST_APACHE_BIN ?? 'C:/xampp/apache/bin/httpd.exe';
const apacheRoot = resolve(dirname(dirname(apachePath)));
const apachePathValue = path => path.replaceAll('\\', '/');

async function stopChild(child, exited) {
  if (child.exitCode === null) child.kill();
  await Promise.race([exited, sleep(3000)]);
}

export async function startLocalApacheServer({ port, root }) {
  const temporaryRoot = await mkdtemp(join(tmpdir(), 'clipforge-mediapipe-apache-'));
  const configPath = join(temporaryRoot, 'httpd.conf');
  const config = [
    `ServerRoot "${apachePathValue(apacheRoot)}"`,
    `Listen 127.0.0.1:${port}`,
    'ServerName 127.0.0.1',
    `PidFile "${apachePathValue(join(temporaryRoot, 'httpd.pid'))}"`,
    `ErrorLog "${apachePathValue(join(temporaryRoot, 'error.log'))}"`,
    'LogLevel warn',
    'LoadModule authz_core_module modules/mod_authz_core.so',
    'LoadModule authz_host_module modules/mod_authz_host.so',
    'LoadModule dir_module modules/mod_dir.so',
    'LoadModule headers_module modules/mod_headers.so',
    'LoadModule mime_module modules/mod_mime.so',
    'LoadModule rewrite_module modules/mod_rewrite.so',
    'TypesConfig conf/mime.types',
    `<Directory "${apachePathValue(resolve(root, '..'))}">`,
    '  AllowOverride FileInfo Options',
    '  Require all granted',
    '</Directory>',
    `DocumentRoot "${apachePathValue(root)}"`,
    `<Directory "${apachePathValue(root)}">`,
    '  AllowOverride FileInfo Options',
    '  Require all granted',
    '</Directory>',
  ].join('\n');
  await writeFile(configPath, `${config}\n`, 'utf8');
  const child = spawn(apachePath, ['-f', configPath, '-DFOREGROUND'], {
    shell: false,
    windowsHide: true,
    stdio: ['ignore', 'pipe', 'pipe'],
  });
  let lastResponse = 'no response';
  let startupError = '';
  child.stderr.on('data', chunk => { startupError += chunk.toString(); });
  const exited = new Promise(resolve => {
    child.once('exit', (code, signal) => resolve({ code, signal }));
    child.once('error', error => resolve({ error }));
  });
  const baseUrl = `http://127.0.0.1:${port}`;
  try {
    for (let attempt = 0; attempt < 50; attempt += 1) {
      const terminal = await Promise.race([
        fetch(`${baseUrl}/assets/vendor/mediapipe-tasks-vision-1.0.1/vision_bundle.mjs`, { signal: AbortSignal.timeout(500) })
          .then(response => ({ response }))
          .catch(() => ({ response: null })),
        exited,
      ]);
      if (terminal?.error) throw terminal.error;
      if (terminal?.code !== undefined) throw new Error(`Apache exited during startup (${terminal.code}).`);
      if (terminal?.response) lastResponse = `HTTP ${terminal.response.status}`;
      if (terminal?.response?.ok) {
        return {
          baseUrl,
          async stop() {
            await stopChild(child, exited);
            await rm(temporaryRoot, { recursive: true, force: true });
          },
        };
      }
      await sleep(100);
    }
    throw new Error(`Apache server did not become ready: ${lastResponse} ${startupError}`);
  } catch (error) {
    const errorLog = await readFile(join(temporaryRoot, 'error.log'), 'utf8').catch(() => '');
    await stopChild(child, exited);
    await rm(temporaryRoot, { recursive: true, force: true });
    throw new Error(`${error instanceof Error ? error.message : String(error)} ${errorLog}`.trim());
  }
}
