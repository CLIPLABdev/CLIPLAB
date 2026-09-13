import { chromium } from '@playwright/test';
import { execFileSync } from 'node:child_process';
import { readFile, mkdir } from 'node:fs/promises';
import { resolve } from 'node:path';
import assert from 'node:assert/strict';

const root = resolve(import.meta.dirname, '../..');
const html = execFileSync('C:/xampp/php/php.exe', [resolve(root,'tests/Browser/support/admin-workspace-render.php')], {encoding:'utf8',windowsHide:true});
const browser = await chromium.launch({channel:'chrome',headless:true});
const out = resolve(root,'dist/visual-polish');
await mkdir(out,{recursive:true});
async function fixture(options, body = html) {
  const context = await browser.newContext(options);
  await context.route('**/*', async route => {
    const path = new URL(route.request().url()).pathname;
    if (path === '/admin') return route.fulfill({contentType:'text/html',body});
    if (!path.startsWith('/assets/')) return route.abort();
    try { await route.fulfill({body:await readFile(resolve(root,'public'+path)),contentType:path.endsWith('.js')?'text/javascript':path.endsWith('.css')?'text/css':'image/svg+xml'}); }
    catch { await route.abort(); }
  });
  const page = await context.newPage();
  await page.goto('http://admin-fixture.test/admin');
  return {context,page};
}
try {
  const {context,page} = await fixture({viewport:{width:390,height:844},reducedMotion:'reduce'});
  const toggle = page.locator('[data-admin-drawer-toggle]');
  await toggle.waitFor({state:'visible',timeout:2000});
  assert.equal(await page.locator('.admin-sidebar').evaluate(el=>el.inert),true);
  await toggle.click();
  assert.equal(await toggle.getAttribute('aria-expanded'),'true');
  assert.equal(await page.locator('.admin-main-wrap').evaluate(el=>el.inert),true);
  assert.equal(await page.evaluate(()=>document.querySelector('.admin-sidebar').contains(document.activeElement)),true);
  await page.screenshot({path:resolve(out,'admin-mobile-drawer.png')});
  await page.keyboard.press('Shift+Tab');
  assert.equal(await page.evaluate(()=>document.querySelector('.admin-sidebar').contains(document.activeElement)),true);
  await page.keyboard.press('Escape');
  assert.equal(await toggle.getAttribute('aria-expanded'),'false');
  assert.equal(await toggle.evaluate(el=>el===document.activeElement),true);
  assert.equal(await page.locator('.admin-main-wrap').evaluate(el=>el.inert),false);
  await toggle.click();
  await page.locator('[data-admin-drawer-backdrop]').click({position:{x:380,y:300}});
  assert.equal(await toggle.getAttribute('aria-expanded'),'false');
  await page.screenshot({path:resolve(out,'admin-mobile.png'),fullPage:true});
  assert.equal(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),true);
  assert.equal(await page.locator('.admin-metrics article').first().evaluate(el=>getComputedStyle(el).animationName),'none');
  await toggle.click();
  await page.setViewportSize({width:1440,height:1000});
  await page.waitForFunction(()=>!document.body.classList.contains('admin-drawer-open'));
  assert.equal(await page.locator('.admin-main-wrap').evaluate(el=>el.inert),false);
  assert.equal(await page.locator('.admin-sidebar').evaluate(el=>el.inert),false);
  assert.equal(await page.evaluate(()=>document.activeElement.getClientRects().length>0),true);
  await page.screenshot({path:resolve(out,'admin-desktop.png'),fullPage:true});
  assert.equal(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),true);
  await context.close();
  const nojs = await fixture({viewport:{width:390,height:844},javaScriptEnabled:false});
  assert.equal(await nojs.page.locator('.admin-nav a').count(),19);
  assert.equal(await nojs.page.locator('.admin-nav a').last().isVisible(),true);
  assert.equal(await nojs.page.locator('.admin-metrics article').count(),15);
  assert.equal(await nojs.page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),true);
  await nojs.context.close();
  const usersHtml = execFileSync('C:/xampp/php/php.exe', [resolve(root,'tests/Browser/support/admin-workspace-render.php'),'users'], {encoding:'utf8',windowsHide:true});
  const users = await fixture({viewport:{width:390,height:844},reducedMotion:'reduce'}, usersHtml);
  assert.equal(await users.page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),true);
  assert.equal(await users.page.locator('form[action="/admin/usuarios/17/status"] input[name="status"]').inputValue(),'suspended');
  assert.equal(await users.page.locator('form[action="/admin/usuarios/17/status"] button').innerText(),'Suspender');
  const table = users.page.getByRole('region',{name:'Contas e ações'});
  await table.focus();
  await users.page.keyboard.press('End');
  assert.equal(await table.evaluate(el=>el.scrollWidth>el.clientWidth),true);
  await users.page.evaluate(()=>{document.activeElement.blur();window.scrollTo(0,0);document.querySelector('.admin-table-wrap').scrollLeft=0;});
  await users.page.screenshot({path:resolve(out,'admin-users-mobile.png'),fullPage:true});
  await users.page.setViewportSize({width:1440,height:1000});
  await users.page.evaluate(()=>{window.scrollTo(0,0);document.querySelector('.admin-sidebar').scrollTop=0;});
  await users.page.screenshot({path:resolve(out,'admin-users-desktop.png'),fullPage:true});
  await users.context.close();
  console.log('PASS: mobile drawer focus, Escape, backdrop, resize, desktop/mobile overflow, reduced motion, no-JS navigation and metrics.');
} finally { await browser.close(); }
