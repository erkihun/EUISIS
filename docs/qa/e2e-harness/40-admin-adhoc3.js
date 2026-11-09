'use strict';
const { BASE, newBrowser, login, visit, shot } = require('./lib');
(async () => {
    const { browser, page } = await newBrowser();
    await login(page, 'qa.sysadmin5@demo.local', 'password');
    await visit(page, '/system-settings/api-management');
    const btns = page.getByRole('button', { name: /new application/i });
    console.log('count', await btns.count());
    for (let i = 0; i < await btns.count(); i++) console.log(i, await btns.nth(i).isVisible(), await btns.nth(i).isEnabled(), JSON.stringify(await btns.nth(i).boundingBox()), await btns.nth(i).evaluate((e) => e.outerHTML.slice(0, 160)));
    await btns.first().evaluate((e) => e.click());
    await page.waitForTimeout(1500);
    await shot(page, '40-api-form');
    const labels = await page.locator('form label').allInnerTexts();
    console.log('labels', JSON.stringify(labels.map((s) => s.trim()).filter(Boolean)).slice(0, 1200));
    const t = await page.locator('form').first().innerText().catch(() => '');
    console.log('form text', t.replace(/\s+/g, ' ').slice(0, 1200));
    await browser.close();
})().catch((e) => { console.error('FATAL', e); process.exit(1); });
