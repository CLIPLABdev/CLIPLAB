const { chromium } = require('@playwright/test');
const assert = require('node:assert/strict');

(async () => {
    const browser = await chromium.launch({ channel: 'chrome', headless: true });
    try {
        const page = await browser.newPage({ javaScriptEnabled: false, viewport: { width: 360, height: 800 } });
        await page.goto('http://127.0.0.1:8093/');
        assert.equal(await page.locator('[data-nav-menu] a[href="/login"]').isVisible(), true, 'Mobile navigation must expose its real links without JavaScript');
        assert.equal(await page.locator('[data-nav-toggle]').isVisible(), false, 'An inactive menu toggle must be hidden without JavaScript');
        assert.equal(await page.locator('[data-demo-stage="1"]').isVisible(), true, 'The static example remains readable');
        await page.locator('[data-nav-menu] a[href="/login"]').click();
        assert.equal(await page.locator('form[action="/login"]').isVisible(), true);
        console.log('PASS: mobile navigation works without JavaScript and static demo remains visible');
    } finally { await browser.close(); }
})().catch(error => { console.error(error); process.exitCode = 1; });
