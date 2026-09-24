// Produces an actual application screenshot from the isolated editor fixture.
// No application DB, account session, private media or provider is accessed.
import { chromium } from '@playwright/test';
import { spawn } from 'node:child_process';
import { createServer } from 'node:net';
import { resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { readFile } from 'node:fs/promises';

const root = fileURLToPath(new URL('..', import.meta.url));
const reservation = createServer();
await new Promise(done => reservation.listen(0, '127.0.0.1', done));
const port = reservation.address().port;
await new Promise(done => reservation.close(done));
const origin = `http://127.0.0.1:${port}`;
const env = Object.fromEntries(['PATH','SystemRoot','TEMP','TMP'].filter(k => process.env[k]).map(k => [k,process.env[k]]));
const server = spawn(process.env.TEST_PHP_BIN || 'C:\\xampp\\php\\php.exe', ['-S', `127.0.0.1:${port}`, resolve(root,'tests/Browser/support/clip-editor-ui-router.php')], { cwd:root, env, windowsHide:true, stdio:'ignore' });
const exited = new Promise(done => { server.once('exit',done); server.once('error',done); });
let browser;
try {
  let ready = false;
  for (let i=0; i<50; i++) {
    try { ready = await (await fetch(origin+'/__editor_ready')).text() === 'editor-ui-fixture'; } catch {}
    if (ready) break;
    await new Promise(done=>setTimeout(done,100));
  }
  if (!ready) throw new Error('Isolated fixture unavailable');
  browser = await chromium.launch({ channel:'chrome', headless:true });
  const page = await browser.newPage({ viewport: {width:1480,height:1080}, deviceScaleFactor:1, reducedMotion:'reduce' });
  await page.route('**/*', route => new URL(route.request().url()).origin === origin ? route.continue() : route.abort());
  if (process.env.CLIPLAB_PREVIEW_SAMPLE) {
    // Use only an explicitly supplied licensed public sample, never account media.
    const sample = await readFile(process.env.CLIPLAB_PREVIEW_SAMPLE);
    await page.route('**/clips/41/source-preview', route => {
      const range=/^bytes=(\d+)-(\d*)$/.exec(route.request().headers().range || '');
      if (!range) return route.fulfill({status:200,contentType:'video/mp4',body:sample});
      const start=Number(range[1]), end=range[2] ? Math.min(Number(range[2]),sample.length-1) : sample.length-1;
      return route.fulfill({status:206,contentType:'video/mp4',body:sample.subarray(start,end+1),
        headers:{'accept-ranges':'bytes','content-range':`bytes ${start}-${end}/${sample.length}`}});
    });
  }
  await page.goto(origin+'/clips/41/editar');
  await page.locator('[data-style-preview]').waitFor({state:'visible'});
  if (process.env.CLIPLAB_PREVIEW_SAMPLE) {
    await page.getByRole('button',{name:'Abrir prévia',exact:true}).click();
    await page.waitForFunction(()=>document.querySelector('video')?.readyState>=2);
    await page.locator('video').evaluate(async video => {
      video.muted=true;
      await video.play();
      await new Promise(done=>video.requestVideoFrameCallback(done));
      video.pause();
      await new Promise(done => { video.addEventListener('seeked', done, {once:true}); video.currentTime=12; });
    });
    await page.waitForFunction(()=>{const video=document.querySelector('video');return video?.readyState>=2 && !video.seeking && video.currentTime>=11.9;});
  }
  const box=await page.locator('.clip-editor-page').boundingBox();
  if (!box) throw new Error('Editor component missing');
  await page.screenshot({path:resolve(root,'public/assets/images/editor-preview.png'),clip:{x:box.x,y:box.y,width:Math.min(box.width,1180),height:Math.min(box.height,900)}});
  console.log('Saved actual editor fixture screenshot: public/assets/images/editor-preview.png');
} finally {
  if (browser) await browser.close();
  if (!server.killed) server.kill();
  await exited;
}
