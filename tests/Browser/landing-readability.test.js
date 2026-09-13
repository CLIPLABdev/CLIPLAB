const { chromium } = require('@playwright/test');
const assert = require('node:assert/strict');

(async () => {
    const browser = await chromium.launch({ channel: 'chrome', headless: true });
    try {
        const page = await browser.newPage();
        for (const width of [360, 768, 1440]) {
            await page.setViewportSize({ width, height: 1000 });
            await page.goto('http://127.0.0.1:8093/');
            const undersized = await page.evaluate(() => {
                const rules = [
                    ['.studio-demo-controls button', 14],
                    ['.landing-steps p, .landing-editor-notes p, .landing-benefits article p, .landing-faq-grid>div>p:not(.landing-kicker), .landing-faq details>p, .landing-plan-card li, #planos .section-heading p:not(.eyebrow)', 16],
                ];
                return rules.flatMap(([selector, minimum]) => Array.from(document.querySelectorAll(selector))
                    .filter(element => parseFloat(getComputedStyle(element).fontSize) < minimum)
                    .map(element => ({ text: element.textContent.trim().slice(0, 60), fontSize: getComputedStyle(element).fontSize, minimum })));
            });
            assert.deepEqual(undersized, [], `Readable body and demo controls at ${width}px`);
            for (const stage of [1, 2, 3]) {
                await page.locator(`[data-demo-select="${stage}"]`).click();
                assert.equal(await page.locator(`[data-demo-stage="${stage}"]`).isVisible(), true);
                assert.equal(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), true, `Stage ${stage} fits at ${width}px`);
            }
        }
        console.log('PASS: readable body/control sizes and all demo states fit at 360, 768 and 1440px');
    } finally { await browser.close(); }
})().catch(error => { console.error(error); process.exitCode = 1; });
