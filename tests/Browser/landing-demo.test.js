const { chromium } = require('@playwright/test');
const fs = require('node:fs');
const path = require('node:path');
const assert = require('node:assert/strict');

(async () => {
    const browser = await chromium.launch({ channel: 'chrome', headless: true });
    try {
        const page = await browser.newPage({ reducedMotion: 'reduce' });
        await page.setContent(`<section data-landing-demo><div data-demo-stage="1">Origem</div><div data-demo-stage="2" hidden>Sugestões</div><div data-demo-stage="3" hidden>Exportação</div><button data-demo-select="1" aria-current="step">Origem</button><button data-demo-select="2">Sugestões</button><button data-demo-select="3">Exportação</button><button data-demo-prev disabled>Etapa anterior</button><button data-demo-next>Próxima etapa</button><span data-demo-status aria-live="polite"></span></section>`);
        const requests = [];
        page.on('request', request => requests.push(request.url()));
        const script = path.resolve(__dirname, '../../public/assets/js/landing-demo.js');
        if (fs.existsSync(script)) await page.addScriptTag({ path: script });
        await page.getByRole('button', { name: 'Próxima etapa' }).click();
        assert.equal(await page.locator('[data-demo-stage="2"]').isVisible(), true, 'Next must show suggestions even with reduced motion');
        assert.equal(await page.locator('[data-demo-select="2"]').getAttribute('aria-current'), 'step');
        await page.getByRole('button', { name: 'Próxima etapa' }).click();
        assert.equal(await page.locator('[data-demo-stage="3"]').isVisible(), true);
        assert.equal(await page.locator('[data-demo-next]').isDisabled(), true);
        await page.getByRole('button', { name: 'Etapa anterior' }).click();
        assert.equal(await page.locator('[data-demo-stage="2"]').isVisible(), true);
        await page.locator('[data-demo-select="1"]').click();
        assert.equal(await page.locator('[data-demo-stage="1"]').isVisible(), true);
        assert.equal(await page.locator('[data-demo-prev]').isDisabled(), true);
        assert.deepEqual(requests, [], 'Illustration controls must never send media or API requests');
        console.log('PASS: illustrative demo navigation, boundaries, aria-current, reduced motion, no requests');
    } finally { await browser.close(); }
})().catch(error => { console.error(error); process.exitCode = 1; });
