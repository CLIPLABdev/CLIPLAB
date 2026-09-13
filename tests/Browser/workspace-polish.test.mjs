import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import {fileURLToPath} from 'node:url';
import {spawnSync} from 'node:child_process';
import {chromium} from '@playwright/test';
const root=fileURLToPath(new URL('../../',import.meta.url));
const render=spawnSync('C:/xampp/php/php.exe',[path.join(root,'tests/Fixtures/workspace-view.php')],{encoding:'utf8',windowsHide:true});
assert.equal(render.status,0,'Production layout fixture must render');
const fixture=JSON.parse(render.stdout);
const browser=await chromium.launch({channel:'chrome',headless:true});
const checks=[];
async function setup(options={}){
 const context=await browser.newContext({viewport:{width:1440,height:900},...options});const page=await context.newPage();
 await context.route('http://workspace.test/**',async route=>{
  const pathname=new URL(route.request().url()).pathname;
  if(pathname.startsWith('/assets/')){const file=path.join(root,'public',pathname);if(!fs.existsSync(file))return route.fulfill({status:404,body:''});return route.fulfill({status:200,contentType:pathname.endsWith('.css')?'text/css':'text/javascript',body:fs.readFileSync(file)});}
  return route.fulfill({status:200,headers:{'Content-Type':'text/html;charset=utf-8','Content-Security-Policy':fixture.csp},body:fixture.html});
 });
 await page.goto('http://workspace.test/clips',{waitUntil:'networkidle'});return {context,page};
}
try{
 const {context,page}=await setup();const errors=[];page.on('pageerror',e=>errors.push(e.message));
 assert.equal(await page.locator('[data-workspace-open]').count(),1,'Header must expose one working page finder');
 await page.locator('[data-workspace-open]').click();
 assert.equal(await page.locator('[data-workspace-dialog]').evaluate(el=>el.open),true);
 assert.equal(await page.locator('[data-workspace-search]').evaluate(el=>el===document.activeElement),true,'Search receives focus');
 await page.locator('[data-workspace-search]').fill('notificacoes');
 assert.equal(await page.locator('[data-workspace-item]:visible').count(),1,'Accent-insensitive navigation filter');
 assert.equal(await page.locator('[data-workspace-item]:visible a').getAttribute('href'),'/notificacoes');
 await page.locator('[data-workspace-search]').fill('pagina inexistente');
 assert.equal(await page.locator('[data-workspace-empty]').isVisible(),true,'Empty state explains no match');
 await page.keyboard.press('Escape');
 assert.equal(await page.locator('[data-workspace-dialog]').evaluate(el=>el.open),false);
 assert.equal(await page.locator('[data-workspace-open]').evaluate(el=>el===document.activeElement),true,'Close restores focus');
 await page.keyboard.press('Control+k');
 assert.equal(await page.locator('[data-workspace-dialog]').evaluate(el=>el.open),true,'Keyboard shortcut works');
 await page.locator('[data-workspace-search]').fill('novo projeto');await page.keyboard.press('ArrowDown');
 assert.equal(await page.evaluate(()=>document.activeElement?.getAttribute('href')),'/projetos/novo','Arrow key reaches matching action');
 await page.keyboard.press('Escape');
 assert.equal(await page.locator('.workspace-nav-heading').count(),3,'Navigation has meaningful groups');
 assert.equal(await page.locator('.app-nav a[aria-current="page"]').getAttribute('href'),'/clips');
 checks.push('finder/focus/keyboard/filter/empty/current-navigation');
 await page.setViewportSize({width:390,height:844});
 assert.equal(await page.getByRole('button',{name:'Buscar páginas',exact:true}).count(),1,'Icon-only finder retains an accessible name on mobile');
 await page.locator('[data-drawer-toggle]').click();
 assert.equal(await page.locator('[data-app-sidebar]').evaluate(el=>el.inert),false);
 await page.keyboard.press('Escape');assert.equal(await page.locator('[data-app-sidebar]').evaluate(el=>el.inert),true);
 assert.equal(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+2),true,'Mobile has no horizontal overflow');
 checks.push('mobile-drawer/focus/no-overflow');
 await page.emulateMedia({reducedMotion:'reduce'});
 assert.equal(await page.locator('.app-content').evaluate(el=>getComputedStyle(el).animationName),'none','Reduced motion is respected');
 assert.deepEqual(errors,[]);await context.close();
 const fallback=await setup({javaScriptEnabled:false,viewport:{width:390,height:844}});
 assert.equal(await fallback.page.locator('[data-workspace-open]').isVisible(),false,'No dead finder button without JavaScript');
 const rect=await fallback.page.locator('.app-nav a[href="/clips"]').boundingBox();
 assert.ok(rect&&rect.x>=0&&rect.y>=0,'Navigation remains on screen without JavaScript');
 assert.equal(await fallback.page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+2),true);
 checks.push('reduced-motion/no-js-navigation');await fallback.context.close();
 console.log(JSON.stringify({passed:true,checks}));
}finally{await browser.close();}
