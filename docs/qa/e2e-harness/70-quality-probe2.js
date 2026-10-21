'use strict';
const { BASE, newBrowser, login } = require('./lib');
(async () => {
    const { browser, page } = await newBrowser({ uiLocale: 'am' });
    await login(page, 'qa.sysadmin6@demo.local', 'password');
    await page.goto(`${BASE}/dashboard`, { waitUntil: 'networkidle' });
    await page.waitForTimeout(800);
    const r = await page.evaluate(() => {
        const out = [];
        for (const el of document.querySelectorAll('span, time, p, dd')) {
            if (el.children.length === 0 && /\d{4}-\d{2}-\d{2}|\d{2}\/\d{2}\/\d{4}/.test(el.textContent)) {
                let chain = []; let n = el; for (let i = 0; i < 4 && n; i++) { chain.push(n.tagName + '.' + String(n.className).slice(0, 40)); n = n.parentElement; }
                const card = el.closest('section, article');
                out.push({ text: el.textContent.trim(), outer: el.outerHTML.slice(0, 160), chain, cardHeading: card ? (card.querySelector('h2,h3')?.textContent || '').slice(0, 40) : null, nearby: (el.parentElement.innerText || '').replace(/\s+/g, ' ').slice(0, 120) });
            }
        }
        return out;
    });
    console.log(JSON.stringify(r, null, 1));
    await browser.close();
})().catch((e) => { console.error(e); process.exit(1); });
