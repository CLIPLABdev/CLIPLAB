import { chromium } from '@playwright/test';
import { execFileSync } from 'node:child_process';
import { readFile, mkdir, writeFile } from 'node:fs/promises';
import path from 'node:path';
import assert from 'node:assert/strict';

const before=process.argv.includes('--before');
const root=process.cwd();
const fixtures=JSON.parse(execFileSync('C:/xampp/php/php.exe',['tests/Fixtures/email-studio.php'],{cwd:root,encoding:'utf8'}));
const output=path.join(root,'dist/visual-polish/emails');await mkdir(output,{recursive:true});
const browser=await chromium.launch({channel:'chrome',headless:true});
const blocked=[];const errors=[];
try {
  for(const width of [1440,390]) {
    const context=await browser.newContext({viewport:{width,height:1000}});
    await context.route('**/*',async route=>{
      const url=new URL(route.request().url());
      if(url.origin!=='http://email-studio.test'){blocked.push(url.href);return route.abort();}
      if(route.request().method()!=='GET')throw new Error('Fixture forbids writes');
      const fixture=fixtures[url.pathname+url.search];
      if(fixture)return route.fulfill(fixture);
      if(/^\/assets\/[a-z0-9/_.-]+$/i.test(url.pathname)){
        try{return route.fulfill({body:await readFile(path.join(root,'public',url.pathname)),contentType:url.pathname.endsWith('.css')?'text/css':'application/javascript'});}catch{}
      }
      return route.fulfill({status:404,body:''});
    });
    const page=await context.newPage();
    page.on('pageerror',error=>errors.push(error.message));
    page.on('console',message=>{if(message.type()==='error'&&/Content Security Policy|Refused to|violates/i.test(message.text()))errors.push(message.text());});
    await page.goto('http://email-studio.test/admin/emails');
    await page.screenshot({path:path.join(output,`${before?'before':'after'}-studio-${width}.png`),fullPage:before,animations:'disabled'});
    if(!before){
      assert.equal(await page.locator('[data-email-card]').count(),19);
      assert.equal(await page.locator('[data-email-card]:visible').count(),19);
      await page.getByLabel('Buscar modelo').fill('Redefinir');
      assert.equal(await page.locator('[data-email-card]:visible').count(),1);
      await page.getByLabel('Buscar modelo').fill('xxxxxxxx');
      assert.equal(await page.locator('[data-email-card]:visible').count(),0);
      assert.equal(await page.locator('[data-email-empty]').isVisible(),true);
      await page.getByRole('button',{name:'Limpar filtros'}).click();
      await page.locator('[data-email-category]').selectOption('billing');
      assert.equal(await page.locator('[data-email-card]:visible').count(),8);
      await page.getByRole('button',{name:'Limpar filtros'}).click();
      await page.getByLabel('Status do modelo').selectOption('draft');
      assert.equal(await page.locator('[data-email-card]:visible').count(),1);
      await page.getByRole('button',{name:'Limpar filtros'}).click();
      const first=page.locator('[data-email-card]').first();
      await first.getByText('Visualizar e ações',{exact:true}).click();
      await first.getByRole('button',{name:'Celular'}).click();
      assert.equal(await first.locator('[data-email-preview]').getAttribute('data-size'),'mobile');
      const frame=first.frameLocator('iframe');
      await frame.locator('.cf-email-brand').waitFor();
      assert.equal(await frame.locator('.cf-email-brand').evaluate(el=>getComputedStyle(el).backgroundColor),'rgb(32, 37, 31)');
      await first.getByRole('button',{name:'Desktop'}).click();
      assert.equal(await first.locator('[data-email-preview]').getAttribute('data-size'),'desktop');
      await first.screenshot({path:path.join(output,`after-expanded-${width}.png`),animations:'disabled'});
      assert.ok(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth));
      await page.goto('http://email-studio.test/admin/emails?modelo=auth.password_reset#email-editor');
      assert.equal(await page.getByLabel('Assunto',{exact:true}).inputValue(),'Redefina sua senha do ClipForge');
      assert.equal(await page.locator('[data-email-variables]:visible').count(),1);
      assert.equal(await page.locator('[data-email-variables]:visible').getAttribute('data-email-variables'),'auth.password_reset');
      await page.getByLabel('Evento da mensagem').selectOption('marketing.campaign');
      assert.equal(await page.locator('[data-email-variables]:visible').getAttribute('data-email-variables'),'marketing.campaign');
      await page.getByLabel('Evento da mensagem').selectOption('auth.password_reset');
      await page.locator('#email-editor').screenshot({path:path.join(output,`after-editor-${width}.png`),animations:'disabled'});
    }
    await page.goto('http://email-studio.test/admin/emails/6/preview');
    await page.screenshot({path:path.join(output,`${before?'before':'after'}-message-${width}.png`),fullPage:true,animations:'disabled'});
    if(!before){
      assert.equal(await page.locator('.cf-email-brand').evaluate(el=>getComputedStyle(el).backgroundColor),'rgb(32, 37, 31)');
      assert.ok(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth));
      assert.ok(await page.locator('.cf-email-card').evaluate(el=>el.getBoundingClientRect().width<=600));
    }
    await page.goto('http://email-studio.test/admin/email-configuracao');
    await page.screenshot({path:path.join(output,`${before?'before':'after'}-settings-${width}.png`),fullPage:true,animations:'disabled'});
    await context.close();
  }
  if(!before){
    const context=await browser.newContext({javaScriptEnabled:false,viewport:{width:390,height:900}});
    await context.route('**/*',async route=>{
      const url=new URL(route.request().url());const fixture=fixtures[url.pathname+url.search];
      if(fixture)return route.fulfill(fixture);
      if(url.origin==='http://email-studio.test'&&url.pathname.startsWith('/assets/'))return route.fulfill({body:await readFile(path.join(root,'public',url.pathname)),contentType:'text/css'});
      return route.abort();
    });
    const page=await context.newPage();await page.goto('http://email-studio.test/admin/emails');
    assert.equal(await page.locator('[data-email-card]:visible').count(),19);
    assert.equal(await page.getByRole('button',{name:'Salvar nova versão'}).isVisible(),true);
    await context.close();
    assert.deepEqual(blocked,[]);assert.deepEqual(errors,[]);
  }
  await writeFile(path.join(output,`${before?'before':'after'}-result.json`),JSON.stringify({mode:before?'before':'after',widths:[1440,390],blocked,errors},null,2));
  console.log(JSON.stringify({ok:true,mode:before?'before':'after',output,errors,blocked}));
}finally{await browser.close();}
