import {test,expect} from '@playwright/test';
import {execFileSync} from 'node:child_process';
import {readFile} from 'node:fs/promises';
import {fileURLToPath} from 'node:url';
import {resolve} from 'node:path';
const root=fileURLToPath(new URL('../..',import.meta.url));
for(const screen of ['thumbnail','publication']) {
 test(`${screen} forms remain readable without JavaScript at 320px`,async({browser})=>{
  const context=await browser.newContext({viewport:{width:320,height:900},javaScriptEnabled:false});const page=await context.newPage();
  const html=execFileSync(process.env.TEST_PHP_BIN||'C:\\xampp\\php\\php.exe',[resolve(root,'tests/Browser/support/thumbnail-publication-view.php'),screen],{windowsHide:true}).toString();
  await page.route('**/*',async route=>{const path=new URL(route.request().url()).pathname;if(path==='/fixture'){await route.fulfill({contentType:'text/html',body:html});return;}if(path.startsWith('/assets/')&&!path.includes('..')){try{await route.fulfill({body:await readFile(resolve(root,'public'+path)),contentType:path.endsWith('.css')?'text/css':path.endsWith('.js')?'text/javascript':'image/svg+xml'});return;}catch{}}await route.abort();});
  await page.goto('http://thumbnail-fixture.test/fixture');await expect(page.getByRole('heading',{name:screen==='thumbnail'?'Criar variante 1280 × 720':'Nova preparação'})).toBeVisible();
  await expect(page.getByRole('button',{name:screen==='thumbnail'?'Renderizar variante':'Salvar rascunho'}).first()).toBeVisible();
  const widths=await page.evaluate(()=>({page:document.documentElement.scrollWidth,viewport:innerWidth}));expect(widths.page).toBeLessThanOrEqual(widths.viewport+1);
  if(process.env.TEST_THUMBNAIL_EVIDENCE_DIR)await page.screenshot({path:resolve(process.env.TEST_THUMBNAIL_EVIDENCE_DIR,screen+'-320.png'),fullPage:true});await context.close();
 });
}
