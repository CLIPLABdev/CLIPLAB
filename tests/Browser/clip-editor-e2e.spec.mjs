import { test, expect } from '@playwright/test';
import { spawn, execFileSync } from 'node:child_process';
import { mkdtemp, readFile, rm } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { createServer } from 'node:net';
import { resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const root = fileURLToPath(new URL('../..', import.meta.url));
let server;
let baseURL;
let exited;
let fixtureDir;
let videoBytes;

test('complete effects template resets legacy fields and rejects invalid payload atomically', async ({ page }) => {
  const options={style:'karaoke',brightness:-25,contrast:125,saturation:135,animation:'fade',animation_duration_ms:320,animation_out:'fade',animation_out_duration_ms:420,motion:'zoom_in',zoom_percent:125,font_italic:1,letter_spacing:3,caption_margin_x:24,caption_offset_y:-18,outline_color:'#ABCDEF',background_mode:'box',video_fade_in_ms:450,video_fade_out_ms:550,blur:2,noise:3,vignette:25};
  await page.route('**/api/editor-library',route=>route.fulfill({json:{presets:[],templates:[{id:1,name:'Completo',aspect_ratio:'9:16',options},{id:2,name:'Legado',aspect_ratio:'original',options:{style:'none'}},{id:3,name:'Invalido',aspect_ratio:'1:1',options:{title:'Do not apply',brightness:999}}],kit:{favorites:[],options:{}},logos:[]}}));
  let saved;
  await page.route('**/templates',route=>route.request().method()==='POST'?(saved=new URLSearchParams(route.request().postData()),route.fulfill({status:303,headers:{location:'/templates'}})):route.fulfill({contentType:'text/html',body:'ok'}));
  await page.goto(baseURL+'/clips/41/editar');
  await page.getByRole('button',{name:'Carregar biblioteca',exact:true}).click();
  const apply=async id=>{await page.locator('[data-library-select]').selectOption('template:'+id);await page.locator('[data-library-apply]').click();};
  await apply(1);
  for(const [key,value] of Object.entries(options)) await expect(page.locator('[name="'+key+'"]')).toHaveValue(String(value));
  await page.getByLabel('Nome do novo template').fill('Efeitos completos');
  await page.locator('[data-library-save]').click();
  await expect(page.locator('[data-library-status]')).toContainText('Template salvo');
  for(const [key,value] of Object.entries(options)) expect(saved.get('options['+key+']')).toBe(String(value));
  await apply(3);
  await expect(page.locator('[name="brightness"]')).toHaveValue('-25');
  await expect(page.locator('[name="aspect_ratio"]')).toHaveValue('9:16');
  await expect(page.locator('[name="title"]')).not.toHaveValue('Do not apply');
  await apply(2);
  await expect(page.locator('[name="brightness"]')).toHaveValue('0');
  await expect(page.locator('[name="zoom_percent"]')).toHaveValue('100');
  await expect(page.locator('[name="animation_duration_ms"]')).toHaveValue('150');
  await expect(page.locator('[name="style"]')).toHaveValue('minimal');
  await page.setViewportSize({width:390,height:844});
  expect(await page.evaluate(()=>document.documentElement.scrollWidth<=window.innerWidth)).toBe(true);
});

test('composed effects change pixels and aligned karaoke stops using timings after text edits', async ({ page },testInfo) => {
  const errors=[];page.on('pageerror',error=>errors.push(error.message));
  await page.route('**/source-preview',serveVideo);
  await page.goto(baseURL+'/clips/43/editar');
  await page.getByRole('button',{name:'Abrir prévia',exact:true}).click();
  const canvas=page.locator('[data-output-preview]');
  await expect(canvas).toHaveAttribute('data-drawn','true');
  await page.locator('video').evaluate(video=>{video.pause();video.currentTime=10.75;});
  await expect(canvas).toHaveAttribute('data-word-alignment','aligned');
  const initial=await canvas.evaluate(node=>node.toDataURL());
  await page.locator('[name="brightness"]').fill('-40');
  await expect.poll(()=>canvas.evaluate(node=>node.toDataURL())).not.toBe(initial);
  await page.locator('[name="blur"]').fill('2');
  await page.locator('[name="vignette"]').fill('50');
  await page.locator('[name="font_italic"]').selectOption('1');
  await page.locator('[name="outline_color"]').fill('#FA1234');
  await page.locator('[name="srt"]').fill('1\n00:00:00,000 --> 00:00:01,000\nTexto alterado\n');
  await expect(canvas).toHaveAttribute('data-word-alignment','segment');
  await expect(page.locator('[data-output-status]')).toContainText('Sem alinhamento');
  await page.screenshot({path:testInfo.outputPath('effects-desktop.png'),fullPage:true});
  await page.setViewportSize({width:390,height:844});
  await page.screenshot({path:testInfo.outputPath('effects-mobile.png'),fullPage:true});
  expect(await page.evaluate(()=>document.documentElement.scrollWidth<=window.innerWidth)).toBe(true);
  expect(errors).toEqual([]);
});

test('style fallback reflects typography and identifies missing video effects preview', async ({ page }) => {
  await page.goto(baseURL+'/clips/41/editar');
  await page.locator('[name="font_family"]').selectOption('Georgia');
  await page.locator('[name="font_italic"]').selectOption('1');
  await page.locator('[name="font_weight"]').selectOption('normal');
  await page.locator('[name="letter_spacing"]').fill('4');
  const caption=page.locator('[data-caption-preview]');
  await expect(caption).toHaveCSS('font-family',/Georgia/);
  await expect(caption).toHaveCSS('font-style','italic');
  await expect(caption).toHaveCSS('font-weight','400');
  await expect(page.locator('[data-output-status]')).toContainText('Prévia de estilo');
});

test('keyboard cue review keeps invalid draft and Enter does not export', async ({ page }) => {
  await page.goto(baseURL+'/clips/41/editar');
  await page.getByLabel('Fim da legenda 1',{exact:true}).fill('0');
  await page.getByLabel('Fim da legenda 1',{exact:true}).press('Enter');
  await expect(page).toHaveURL(baseURL+'/clips/41/editar');
  await expect(page.getByLabel('Fim da legenda 1',{exact:true})).toHaveValue('0');
  await expect(page.locator('[data-cue-status]')).toContainText('Revise início');
  await expect(page.getByLabel('Revisar legendas SRT')).toHaveValue(/00:00:01,000/);
});

test('library failure preserves draft; saving posts only appearance and confirms persisted catalog', async ({ page }) => {
  let savedBody=null,loads=0;
  await page.route('**/api/editor-library',route=>{
    loads++;
    return loads===1?route.fulfill({status:503,json:{error:'unavailable'}}):route.fulfill({json:{presets:[],templates:[{id:8,name:'Meu template',category:'custom',aspect_ratio:'original',options:{}}],kit:{favorites:[],options:{}},logos:[]}});
  });
  await page.route('**/templates',route=>{
    if(route.request().method()==='POST'){savedBody=new URLSearchParams(route.request().postData());return route.fulfill({status:303,headers:{location:'/templates'}});}
    return route.fulfill({contentType:'text/html',body:'<p>Templates salvos</p>'});
  });
  await page.goto(baseURL+'/clips/41/editar');
  await page.getByLabel('Título no vídeo').fill('Minha revisão');
  await page.getByRole('button',{name:'Carregar biblioteca',exact:true}).click();
  await expect(page.locator('[data-library-status]')).toContainText('Não foi possível');
  await expect(page.getByLabel('Título no vídeo')).toHaveValue('Minha revisão');
  await page.getByLabel('Nome do novo template').fill('Meu template');
  await page.getByLabel('Nome do novo template').press('Enter');
  await expect(page.locator('[data-library-status]')).toContainText('Template salvo');
  expect(savedBody.get('options[title]')).toBe('Minha revisão');
  expect(savedBody.get('_token')).toMatch(/^[a-f0-9]+$/);
  expect(savedBody.has('request_key')).toBe(false);
  expect(savedBody.has('srt')).toBe(false);
  await expect(page.getByLabel('Template para aplicar')).toContainText('Meu template');
  await expect(page).toHaveURL(baseURL+'/clips/41/editar');
});

test('failed private logo leaves playable composed preview and is not requested before media gesture', async ({ page }) => {
  const logos=[];
  page.on('request',request=>{if(request.url().includes('/marca/logos/'))logos.push(request.url());});
  await page.route('**/api/editor-library',route=>route.fulfill({json:{presets:[],templates:[],kit:{favorites:[],options:{}},logos:[{id:6,url:'/marca/logos/6',width:200,height:100,size_bytes:1000}]}}));
  await page.route('**/marca/logos/6',route=>route.fulfill({status:404,body:'Não encontrado'}));
  await page.route('**/source-preview',serveVideo);
  await page.goto(baseURL+'/clips/41/editar');
  await page.getByRole('button',{name:'Carregar biblioteca',exact:true}).click();
  await page.getByLabel('Logo da marca',{exact:true}).selectOption('6');
  expect(logos).toEqual([]);
  await page.getByRole('button',{name:'Abrir prévia',exact:true}).click();
  await expect(page.locator('[data-output-status]')).toContainText('Logo indisponível');
  await expect(page.locator('[data-output-preview]')).toBeVisible();
  await page.getByRole('button',{name:'Ir para legenda 1',exact:true}).focus();
  await page.keyboard.press('Enter');
  await expect.poll(()=>page.locator('video').evaluate(v=>v.currentTime)).toBeGreaterThanOrEqual(10);
});

async function serveVideo(route) {
  const range=route.request().headers().range?.match(/^bytes=(\d+)-(\d*)$/);
  const first=range?Number(range[1]):0;
  const last=range&&range[2]?Math.min(Number(range[2]),videoBytes.length-1):videoBytes.length-1;
  await route.fulfill({status:range?206:200,contentType:'video/mp4',headers:{'Accept-Ranges':'bytes',...(range?{'Content-Range':'bytes '+first+'-'+last+'/'+videoBytes.length}:{})},body:videoBytes.subarray(first,last+1)});
}

test.beforeAll(async () => {
  fixtureDir = await mkdtemp(resolve(tmpdir(), 'clip-editor-video-'));
  execFileSync('ffmpeg', ['-hide_banner','-loglevel','error','-f','lavfi','-i','testsrc2=size=320x180:rate=12','-t','22','-c:v','libx264','-pix_fmt','yuv420p','-movflags','+faststart',resolve(fixtureDir,'fixture.mp4')], { windowsHide: true });
  videoBytes = await readFile(resolve(fixtureDir,'fixture.mp4'));
  const reservation = createServer();
  await new Promise(resolve => reservation.listen(0, '127.0.0.1', resolve));
  const port = reservation.address().port;
  await new Promise(resolve => reservation.close(resolve));
  baseURL = `http://127.0.0.1:${port}`;
  const env = Object.fromEntries(['PATH', 'SystemRoot', 'TEMP', 'TMP'].filter(key => process.env[key]).map(key => [key, process.env[key]]));
  server = spawn(process.env.TEST_PHP_BIN || 'C:\\xampp\\php\\php.exe', ['-S', `127.0.0.1:${port}`, resolve(root, 'tests/Browser/support/clip-editor-ui-router.php')], { cwd: root, env, windowsHide: true, stdio: 'ignore' });
  exited = new Promise(resolve => server.once('exit', resolve));
  for (let attempt = 0; attempt < 50; attempt++) {
    try { if (await (await fetch(`${baseURL}/__editor_ready`)).text() === 'editor-ui-fixture') return; } catch {}
    await new Promise(resolve => setTimeout(resolve, 100));
  }
  throw new Error('Isolated editor fixture failed to start');
});

test.afterAll(async () => { if (server && !server.killed) server.kill(); await exited; if (fixtureDir) await rm(fixtureDir,{recursive:true,force:true}); });

for (const width of [320, 360, 768, 1440]) {
  test(`editor works at ${width}px without overflow or eager private media`, async ({ page }, testInfo) => {
    await page.setViewportSize({ width, height: 1000 });
    const media = [];
    page.on('request', request => { if (request.url().includes('/source-preview') || request.url().includes('reframe-worker') || request.url().includes('mediapipe-tasks')) media.push(request.url()); });
    await page.goto(`${baseURL}/clips/41/editar`);
    await expect(page.getByRole('heading', { name: 'Um bom começo' })).toBeVisible();
    await expect(page.locator('[data-style-preview]')).toBeVisible();
    await expect(page.locator('[data-cue-editor]')).toBeVisible();
    const overflow=await page.evaluate(() => ({width:innerWidth,scroll:document.documentElement.scrollWidth,elements:[...document.querySelectorAll('body *')].filter(e=>e.scrollWidth>e.clientWidth+1||e.getBoundingClientRect().right>innerWidth).map(e=>({tag:e.tagName,class:e.className,right:e.getBoundingClientRect().right,width:e.clientWidth,scroll:e.scrollWidth})).slice(-15)}));
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), JSON.stringify(overflow)).toBe(true);
    expect(media).toEqual([]);
    await expect(page.locator('[data-reframe-auto]')).toBeDisabled();
    await page.screenshot({ path: testInfo.outputPath(`editor-${width}.png`), fullPage: true });
    await page.getByLabel('Título no vídeo').fill('<img src=x onerror=alert(1)>');
    await expect(page.locator('[data-title-preview]')).toHaveText('<img src=x onerror=alert(1)>');
    expect(await page.locator('[data-title-preview] img').count()).toBe(0);
  });
}

test('changing interval warns about transcript and preview position stays within selected clip', async ({ page }) => {
  await page.goto(`${baseURL}/clips/41/editar`);
  await expect(page.locator('[data-editor-duration]')).toHaveText('10 s');
  await page.getByLabel('Fim (segundos)').fill('25');
  await expect(page.locator('[data-interval-warning]')).toBeVisible();
  await expect(page.locator('[data-editor-duration]')).toHaveText('15 s');
  await expect(page.locator('[name="transcript_mode"] option[value="none"]')).toHaveCount(0);
  await expect(page.locator('[name="style"] option[value="none"]')).toHaveCount(0);
  await page.getByLabel('Origem das legendas').selectOption('manual');
  await page.getByLabel('Estilo de legenda').selectOption('viral');
  await expect(page.getByLabel('Origem das legendas')).toHaveValue('manual');
});

test('source preview is requested only after a gesture and failure stays actionable', async ({ page }) => {
  const sources = [];
  page.on('request', request => { if (request.url().includes('/source-preview')) sources.push(request.url()); });
  await page.goto(`${baseURL}/clips/41/editar`);
  expect(sources).toEqual([]);
  await page.getByRole('button', { name: 'Abrir prévia', exact: true }).click();
  await expect(page.locator('[data-reframe-status]')).toContainText('Prévia indisponível');
  expect(sources.length).toBeGreaterThan(0);
  expect(await page.locator('video').evaluate(video => video.autoplay)).toBe(false);
});

test('no-JavaScript owner can export a manually focused version and download SRT', async ({ browser }) => {
  const context = await browser.newContext({ javaScriptEnabled: false, viewport: { width: 320, height: 900 } });
  const page = await context.newPage();
  await page.goto(`${baseURL}/clips/41/editar`);
  await expect(page.getByRole('link', { name: 'Abrir vídeo original' })).toBeVisible();
  await page.getByLabel('Proporção de saída').selectOption('9:16');
  await page.getByLabel('Modo de enquadramento').selectOption('manual');
  await page.getByLabel('Foco horizontal').fill('0.25');
  await page.getByLabel('Foco vertical').fill('0.5');
  const download = await context.request.get(`${baseURL}/clips/41/legendas.srt`);
  expect(download.headers()['content-disposition']).toContain('clipe-41.srt');
  expect(await download.text()).toContain('Olá, mundo!');
  await page.getByRole('button', { name: 'Criar versão e exportar' }).click();
  await expect(page).toHaveURL(`${baseURL}/clips/42/editar`);
  await expect(page.getByRole('heading', { name: 'Na fila' })).toBeVisible();
  await expect(page.getByText('Nova versão enviada para processamento. O clipe anterior foi preservado.')).toBeVisible();
  await context.close();
});

test('invalid SRT is preserved with linked errors and focus for correction', async ({ page }) => {
  await page.goto(`${baseURL}/clips/41/editar`);
  await page.getByLabel('Revisar legendas SRT').fill('texto sem tempos');
  await page.getByRole('button', { name: 'Criar versão e exportar' }).click();
  await expect(page.locator('[data-editor-errors]')).toBeFocused();
  await expect(page.getByLabel('Revisar legendas SRT')).toHaveValue('texto sem tempos');
  await expect(page.getByLabel('Revisar legendas SRT')).toHaveAttribute('aria-invalid', 'true');
});

test('cue edits sync SRT and seek playable video; trim is explicit and detection blocks seek', async ({ page }) => {
  await page.route('**/source-preview', serveVideo);
  await page.goto(`${baseURL}/clips/41/editar`);
  await page.getByLabel('Revisar legendas SRT').fill('1\n00:00:00,000 --> 00:00:01,000\nOlá\n\n2\n00:00:02,000 --> 00:00:04,000\nOutro texto\n');
  await page.getByRole('button',{name:'Abrir prévia',exact:true}).click();
  await page.getByRole('button',{name:'Ir para legenda 2',exact:true}).click();
  await expect.poll(() => page.locator('video').evaluate(v=>v.currentTime)).toBeGreaterThanOrEqual(12);
  await expect(page.locator('[data-cue-index="1"]')).toHaveAttribute('aria-current','true');
  await page.getByLabel('Texto da legenda 2',{exact:true}).fill('Texto corrigido');
  await page.getByRole('button',{name:'Atualizar legenda 2',exact:true}).click();
  await expect(page.getByLabel('Revisar legendas SRT')).toHaveValue(/Texto corrigido/);
  await page.locator('[data-clip-editor]').evaluate(f=>{f.dataset.reframeBusy='1';f.dispatchEvent(new CustomEvent('reframe:busy',{detail:{busy:true}}));});
  await expect(page.getByRole('button',{name:'Ir para legenda 1',exact:true})).toBeDisabled();
  await page.locator('[data-clip-editor]').evaluate(f=>{f.dataset.reframeBusy='0';f.dispatchEvent(new CustomEvent('reframe:busy',{detail:{busy:false}}));});
  await page.getByRole('button',{name:'Selecionar intervalo da legenda 2',exact:true}).click();
  await expect(page.getByLabel('Início (segundos)')).toHaveValue('10.000');
  await page.getByRole('button',{name:'Confirmar intervalo da frase',exact:true}).click();
  await expect(page.getByLabel('Início (segundos)')).toHaveValue('12');
  await expect(page.getByLabel('Fim (segundos)')).toHaveValue('14');
  await expect(page.getByLabel('Revisar legendas SRT')).toHaveValue('1\n00:00:00,000 --> 00:00:02,000\nTexto corrigido\n');
  await page.getByRole('button',{name:'Remover legenda 1',exact:true}).click();
  await expect(page.getByLabel('Revisar legendas SRT')).toHaveValue('');
  await expect(page.getByLabel('Fim (segundos)')).toHaveValue('14');
});

test('library template copies to form without export and canvas draws real cropped video', async ({ page }, testInfo) => {
  await page.emulateMedia({reducedMotion:'reduce'});
  await page.route('**/source-preview', serveVideo);
  await page.route('**/api/editor-library',route=>route.fulfill({json:{presets:[],templates:[{id:3,name:'Minha identidade',category:'custom',aspect_ratio:'9:16',options:{font_family:'Georgia',style:'viral',title:'Meu vídeo',logo_asset_id:0}}],kit:{favorites:[3],options:{},aspect_ratio:'original'},logos:[]}}));
  await page.goto(`${baseURL}/clips/41/editar`);
  await page.getByRole('button',{name:'Carregar biblioteca',exact:true}).click();
  await page.getByLabel('Template para aplicar',{exact:true}).selectOption('template:3');
  await page.getByRole('button',{name:'Aplicar ao corte',exact:true}).click();
  await expect(page.getByLabel('Fonte',{exact:true})).toHaveValue('Georgia');
  await expect(page.getByLabel('Proporção de saída')).toHaveValue('9:16');
  await expect(page).toHaveURL(`${baseURL}/clips/41/editar`);
  await page.getByRole('button',{name:'Abrir prévia',exact:true}).click();
  await expect.poll(()=>page.locator('[data-output-preview]').evaluate(c=>c.dataset.drawn)).toBe('true');
  await expect.poll(()=>page.locator('video').evaluate(v=>v.currentTime)).toBeGreaterThanOrEqual(10);
  await expect.poll(()=>page.locator('[data-output-preview]').evaluate(c=>{
    const data=c.getContext('2d').getImageData(c.width/2,c.height/3,5,5).data;
    return Array.from(data).filter((v,i)=>i%4!==3).some(v=>v>30);
  })).toBe(true);
  expect(await page.locator('[data-output-preview]').evaluate(c=>c.width/c.height)).toBeCloseTo(9/16,2);
  await expect(page.getByRole('link',{name:'Estúdio de thumbnail'})).toHaveAttribute('href','/clips/41/capas');
  await expect(page.getByRole('link',{name:'Preparar publicação'})).toHaveAttribute('href','/clips/41/publicacao');
  await page.screenshot({path:testInfo.outputPath('composed-preview.png'),fullPage:true});
});
