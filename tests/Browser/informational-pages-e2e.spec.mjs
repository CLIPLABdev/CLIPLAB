import { test, expect } from '@playwright/test';
import { spawn } from 'node:child_process';
import { createServer } from 'node:net';
import { resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const root = fileURLToPath(new URL('../..', import.meta.url));
let server;
let baseURL;
let exited;

test.beforeAll(async () => {
  const reservation = createServer();
  await new Promise(resolve => reservation.listen(0, '127.0.0.1', resolve));
  const port = reservation.address().port;
  await new Promise(resolve => reservation.close(resolve));
  baseURL = `http://127.0.0.1:${port}`;
  const env = Object.fromEntries(['PATH', 'SystemRoot', 'TEMP', 'TMP'].filter(key => process.env[key]).map(key => [key, process.env[key]]));
  server = spawn(process.env.TEST_PHP_BIN || 'C:\\xampp\\php\\php.exe', ['-S', `127.0.0.1:${port}`, resolve(root, 'tests/Browser/support/informational-ui-router.php')], { cwd: root, env, windowsHide: true, stdio: 'ignore' });
  exited = new Promise(resolve => server.once('exit', resolve));
  for (let attempt = 0; attempt < 50; attempt++) {
    try { if (await (await fetch(`${baseURL}/__information_ready`)).text() === 'information-ui-fixture') return; } catch {}
    await new Promise(resolve => setTimeout(resolve, 100));
  }
  throw new Error('Isolated information fixture failed to start');
});

test.afterAll(async () => { if (server && !server.killed) server.kill(); await exited; });

for (const [path, title] of [['/privacidade', 'Privacidade'], ['/termos', 'Termos de uso']]) {
  for (const width of [320, 768, 1440]) {
    test(`${path} is readable and navigable without JavaScript at ${width}px`, async ({ page }, testInfo) => {
      await page.setViewportSize({ width, height: 1000 });
      const unexpected = [];
      page.on('request', request => { if (!request.url().startsWith(baseURL) || ['script', 'media'].includes(request.resourceType())) unexpected.push(request.url()); });
      page.on('response', response => { if (response.status() >= 400) unexpected.push(response.url()); });
      const response = await page.goto(`${baseURL}${path}`);
      expect(response.status()).toBe(200);
      await expect(page.getByRole('heading', { name: title, exact: true, level: 1 })).toBeVisible();
      const navigation = page.getByRole('navigation', { name: 'Navegação principal' });
      for (const name of ['Privacidade', 'Termos', 'Entrar']) await expect(navigation.getByRole('link', { name, exact: true })).toBeVisible();
      expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
      const bounds = await page.locator('main').boundingBox();
      expect(bounds.x).toBeGreaterThanOrEqual(16);
      expect(bounds.x + bounds.width).toBeLessThanOrEqual(width - 16);
      expect((await page.locator('.information-article').boundingBox()).width).toBeLessThanOrEqual(780);
      expect(unexpected).toEqual([]);
      await page.screenshot({ path: testInfo.outputPath(`${path.slice(1)}-${width}.png`), fullPage: true });
      const firstLink = page.getByRole('navigation', { name: 'Nesta página' }).getByRole('link').first();
      const target = await firstLink.getAttribute('href');
      await firstLink.click();
      await expect(page).toHaveURL(`${baseURL}${path}${target}`);
      const targetBounds = await page.locator(`${target} h2`).boundingBox();
      expect(targetBounds.y).toBeGreaterThanOrEqual(0);
      expect(targetBounds.y).toBeLessThan(1000);
    });
  }
}

test('keyboard skip link reaches main and counterpart links work without scripts', async ({ page }) => {
  await page.setViewportSize({ width: 320, height: 900 });
  await page.goto(`${baseURL}/privacidade`);
  await page.keyboard.press('Tab');
  await expect(page.getByRole('link', { name: 'Pular para o conteúdo' })).toBeFocused();
  await page.keyboard.press('Enter');
  await expect(page.locator('main')).toBeFocused();
  await page.getByRole('link', { name: 'Ler os termos de uso' }).click();
  await expect(page).toHaveURL(`${baseURL}/termos`);
  await expect(page.getByRole('heading', { name: 'Termos de uso', level: 1 })).toBeVisible();
  await page.getByRole('link', { name: 'Ler sobre privacidade' }).click();
  await expect(page).toHaveURL(`${baseURL}/privacidade`);
});
