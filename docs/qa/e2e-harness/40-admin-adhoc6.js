'use strict';
const { BASE, newBrowser, login, visit } = require('./lib');
(async () => {
    const { browser, page } = await newBrowser();
    await login(page, 'qa.sysadmin5@demo.local', 'password');
    await visit(page, '/system-settings/api-management');
    const info = await page.evaluate(() => {
        const bs = [...document.querySelectorAll('button')].filter((b) => /appl/i.test(b.textContent));
        return bs.map((b) => ({ codes: [...b.textContent].map((c) => c.charCodeAt(0)), m1: /new\s+application/i.test(b.textContent), m2: /new application/i.test(b.textContent), tag: b.tagName, disabled: b.disabled, type: b.type }));
    });
    console.log(JSON.stringify(info));
    const r = await page.evaluate(() => { const b = [...document.querySelectorAll('button')].find((x) => /new\s+application/i.test(x.textContent)); if (!b) return 'none'; b.click(); return 'clicked'; });
    console.log(r);
    await page.waitForTimeout(1000);
    console.log('name field visible:', await page.getByLabel(/^name/i).first().isVisible().catch(() => false));
    await browser.close();
})().catch((e) => { console.error('FATAL', e); process.exit(1); });
