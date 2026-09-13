import { test, expect } from '@playwright/test';

function watchErrors(page) {
  const errors = [];
  page.on('pageerror', error => errors.push(error.message));
  page.on('console', message => { if (message.type() === 'error') errors.push(message.text()); });
  return errors;
}

for (const width of [360, 768, 1440]) {
  test(`public journey, FAQ and layout at ${width}px`, async ({ page }, info) => {
    const errors = watchErrors(page);
    await page.setViewportSize({ width, height: 1000 });
    const response = await page.goto('/');
    expect(response.status()).toBe(200);
    await expect(page.locator('main h1')).toHaveCount(1);
    await expect(page.locator('main a[href="/cadastro"]').first()).toBeVisible();
    await expect(page.locator('link[href*="design-system.css"]')).toHaveCount(1);
    const editorImage = page.locator('img[src="/assets/images/editor-preview.png"]');
    await editorImage.scrollIntoViewIfNeeded();
    await expect.poll(() => editorImage.evaluate(image => image.complete && image.naturalWidth > 0)).toBe(true);
    const question = page.locator('main details summary').first();
    await question.click();
    await expect(page.locator('main details').first()).toHaveAttribute('open', '');
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
    await page.screenshot({ path: info.outputPath(`landing-${width}.png`), fullPage: true });
    expect(errors).toEqual([]);
    await page.locator('main a[href="/cadastro"]').first().click();
    await expect(page.locator('form[action="/cadastro"]')).toBeVisible();
    await expect(page.getByLabel('Nome', { exact: true })).toBeVisible();
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
  });
}

test('mobile menu closes with Escape and preserves keyboard access', async ({ page }) => {
  await page.setViewportSize({ width: 360, height: 800 });
  await page.goto('/');
  const toggle = page.locator('[data-nav-toggle]');
  await toggle.click();
  await expect(toggle).toHaveAttribute('aria-expanded', 'true');
  await page.keyboard.press('Escape');
  await expect(toggle).toHaveAttribute('aria-expanded', 'false');
  await expect(toggle).toBeFocused();
});

test('public content cannot be edited without authentication', async ({ request }) => {
  const response = await request.get('/admin/conteudo', { maxRedirects: 0 });
  expect([302, 401, 403]).toContain(response.status());
});

test('authenticated screens keep forms, links and layout usable', async ({ page }, info) => {
  test.skip(!process.env.TEST_LOCAL_ADMIN_EMAIL || !process.env.TEST_LOCAL_ADMIN_PASSWORD, 'Local test account required');
  const errors = watchErrors(page);
  await page.goto('/login');
  await page.getByLabel('E-mail', { exact: true }).fill(process.env.TEST_LOCAL_ADMIN_EMAIL);
  await page.getByLabel('Senha', { exact: true }).fill(process.env.TEST_LOCAL_ADMIN_PASSWORD);
  await Promise.all([page.waitForURL('**/dashboard'), page.getByRole('button', { name: 'Entrar', exact: true }).click()]);
  const paths = ['/dashboard', '/projetos/novo', '/clips', '/conta/plano', '/perfil', '/admin', '/admin/conteudo', '/admin/configuracoes/gemini'];
  for (const width of [360, 1440]) {
    await page.setViewportSize({ width, height: 1000 });
    for (const path of paths) {
      const response = await page.goto(path);
      expect(response.status(), path).toBe(200);
      await expect(page.locator('main')).toBeVisible();
      expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), path + ' ' + width).toBe(true);
      if (path === '/dashboard' || path === '/projetos/novo' || path === '/admin/conteudo') {
        await page.screenshot({ path: info.outputPath(path.replaceAll('/', '-') + '-' + width + '.png'), fullPage: true });
      }
    }
  }
  expect(errors).toEqual([]);
});
