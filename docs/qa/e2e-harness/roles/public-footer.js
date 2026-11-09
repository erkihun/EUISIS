'use strict';
// Public pages: the footer carries the design credit, in English and Amharic, desktop and phone.
const path = require('path');
const { launchChrome } = require('./cdp');

const BASE = process.env.BASE_URL || 'http://127.0.0.1:8123';
const SCRATCH = path.resolve(__dirname, '..');

(async () => {
    const browser = await launchChrome(9800 + Math.floor(Math.random() * 100), path.join(SCRATCH, 'chrome-public-' + process.pid));
    for (const [locale, viewport] of [['en', { width: 1366, height: 900 }], ['am', { width: 1366, height: 900 }], ['en', { width: 375, height: 800 }]]) {
        const page = await browser.newPage({ base: BASE, locale, viewport });
        for (const p of ['/', '/announcements', '/login']) {
            const g = await page.goto(BASE + p);
            const res = await page.eval(`(() => { const f = [...document.querySelectorAll('footer')].map(e => e.innerText).join(' | ');
                return { footer: !!document.querySelector('footer'), credit: (f.match(/Designed and developed by Erkihun Bewket|ዲዛይንና ልማት፦ Erkihun Bewket/) || [null])[0],
                         overflowX: document.documentElement.scrollWidth > document.documentElement.clientWidth + 2 }; })()`);
            console.log(`${locale} ${viewport.width}px ${p} HTTP ${g.status} footer=${res.footer} credit=${res.credit} overflowX=${res.overflowX}`);
            if (p === '/') await page.eval('window.scrollTo(0, document.body.scrollHeight)').then(() => page.screenshot(path.join(__dirname, 'shots', `public-footer-${locale}-${viewport.width}.png`)));
        }
        await page.close();
    }
    await browser.close();
})().catch((e) => { console.error('FATAL', e); process.exit(1); });
