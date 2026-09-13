import { test, expect } from '@playwright/test';
import { execFileSync } from 'node:child_process';
import { readFile } from 'node:fs/promises';
import { fileURLToPath } from 'node:url';
import { resolve } from 'node:path';

const root = fileURLToPath(new URL('../..', import.meta.url));
const origin = 'http://127.0.0.1:8199';

async function fixture(page, { error = false, onPost = null } = {}) {
  const html = execFileSync(process.env.TEST_PHP_BIN || 'C:\\xampp\\php\\php.exe', ['tests/Browser/support/project-intake-view.php'], {
    cwd: root, windowsHide: true,
    env: { ...Object.fromEntries(['PATH','SystemRoot','TEMP','TMP'].filter(k => process.env[k]).map(k => [k,process.env[k]])), TEST_FORM_ERROR: error ? '1' : '0' },
  }).toString();
  await page.route('**/*', async route => {
    const url = new URL(route.request().url());
    if (url.origin !== origin) return route.abort();
    if (route.request().method() === 'POST') {
      if (onPost) return onPost(route);
      throw new Error('Invalid form must not submit');
    }
    if (url.pathname.startsWith('/assets/')) {
      return route.fulfill({body: await readFile(resolve(root, 'public' + url.pathname)), contentType: url.pathname.endsWith('.css') ? 'text/css' : 'text/javascript'});
    }
    return route.fulfill({body: html, contentType:'text/html; charset=utf-8'});
  });
  await page.goto(origin + '/projetos/novo');
  await expect(page.getByRole('tab', { name:'Enviar arquivo' })).toBeVisible();
}

test('empty form explains missing project name and focuses it without a request', async ({page}) => {
  await fixture(page);
  await page.getByRole('button',{name:'Criar projeto',exact:true}).click();
  await expect(page.locator('[data-project-feedback]')).toContainText('nome');
  await expect(page.locator('#project-name')).toBeFocused();
  await expect(page.locator('#project-name')).toHaveAttribute('aria-errormessage', 'project-feedback');
  await page.locator('#project-name').fill('Teste acessível');
  await page.getByRole('button',{name:'Criar projeto',exact:true}).click();
  await expect(page.locator('#project-name')).not.toHaveAttribute('aria-errormessage');
  await expect(page.locator('#video-file')).toHaveAttribute('aria-errormessage', 'project-feedback');
});

test('oversized file is rejected locally and the submit remains available', async ({page}) => {
  await fixture(page);
  await page.locator('#project-name').fill('Meu vídeo');
  await page.locator('#video-file').setInputFiles({name:'large.mp4',mimeType:'video/mp4',buffer:Buffer.alloc(2048)});
  await page.getByRole('button',{name:'Criar projeto',exact:true}).click();
  await expect(page.locator('[data-project-feedback]')).toContainText('limite');
  await expect(page.locator('#video-file')).toBeFocused();
  await expect(page.locator('button[type=submit]').last()).toBeEnabled();
});

test('valid upload gives immediate pending state and prevents duplicate submissions', async ({page}) => {
  let posts=0;
  let release;
  const gate=new Promise(resolve => {release=resolve;});
  await fixture(page,{onPost:async route=>{posts++;await gate;await route.fulfill({status:200,body:'Projeto recebido'});}});
  let observed;
  await page.exposeFunction('captureSubmitState', state => { observed = state; });
  await page.evaluate(() => {
    window.addEventListener('submit', event => {
      if (!event.target.matches('[data-project-form]') || event.defaultPrevented) return;
      const duplicate = new SubmitEvent('submit', { bubbles: true, cancelable: true });
      event.target.dispatchEvent(duplicate);
      window.captureSubmitState({
        busy: event.target.getAttribute('aria-busy'),
        feedback: document.querySelector('[data-project-feedback]').textContent,
        disabled: event.target.querySelector('button[type=submit]').disabled,
        duplicatePrevented: duplicate.defaultPrevented,
      });
    });
  });
  await page.locator('#project-name').fill('Meu vídeo');
  await page.locator('#video-file').setInputFiles({name:'tiny.mp4',mimeType:'video/mp4',buffer:Buffer.alloc(16)});
  try {
    await page.getByRole('button',{name:'Criar projeto',exact:true}).click({noWaitAfter:true});
    await expect.poll(() => observed).toBeTruthy();
    expect(observed.busy).toBe('true');
    expect(observed.feedback).toContain('Enviando');
    expect(observed.disabled).toBe(true);
    expect(observed.duplicatePrevented).toBe(true);
    await expect.poll(()=>posts).toBe(1);
  } finally {release();}
});

test('server validation message is brought into focus after redirect', async ({page}) => {
  await fixture(page,{error:true});
  await expect(page.locator('[data-project-feedback]')).toContainText('validar');
  await expect(page.locator('[data-project-feedback]')).toHaveAttribute('role', 'alert');
  await expect(page.locator('[data-project-feedback]')).toBeFocused();
});

test('inactive URL value cannot invalidate the selected upload source', async ({page}) => {
  await fixture(page);
  await page.getByRole('tab',{name:'Importar URL'}).click();
  await page.locator('#source-url').fill('invalid');
  await page.getByRole('tab',{name:'Enviar arquivo'}).click();
  await expect(page.locator('#source-url')).toBeDisabled();
  await expect(page.locator('#video-file')).toBeEnabled();
});

test('YouTube import requires authorization and other sources hide the checkbox', async ({page}) => {
  await fixture(page);
  await page.locator('#project-name').fill('Vídeo público');
  await page.getByRole('tab',{name:'Importar URL'}).click();
  await page.locator('#source-url').fill('https://youtu.be/aqz-KE-bpKQ');
  await expect(page.locator('[data-youtube-consent]')).toBeVisible();
  await page.getByRole('button',{name:'Criar projeto',exact:true}).click();
  await expect(page.locator('[data-project-feedback]')).toContainText('autorização');
  await expect(page.locator('[name=youtube_rights_confirmed]')).toBeFocused();
  await page.locator('#source-url').fill('https://example.org/video.mp4');
  await expect(page.locator('[data-youtube-consent]')).toBeHidden();
  await expect(page.locator('[name=youtube_rights_confirmed]')).toBeDisabled();
});
