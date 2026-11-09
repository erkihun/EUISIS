'use strict';
const { BASE, Recorder, newBrowser, newPageIn, login, visit, shot } = require('./lib');
const { sql, body } = require('./40-admin-lib');
(async () => {
    const R = new Recorder('40-admin-adhoc');
    const { browser, page } = await newBrowser();
    R.attach(page);
    await login(page, 'qa.sysadmin5@demo.local', 'password');
    await R.step('security max_login_attempts=0: full inertia props after submit', async () => {
        await visit(page, '/system-settings?tab=security');
        const input = page.getByLabel(/max login attempts/i).first();
        await input.evaluate((el) => { el.removeAttribute('min'); el.removeAttribute('max'); });
        await input.fill('0');
        const got = [];
        const h = async (r) => { try { if (/system-settings/.test(r.url()) && /json/.test(r.headers()['content-type'] || '')) { const j = await r.json(); got.push({ url: r.url().replace(BASE, ''), status: r.status(), errors: j.props?.errors, flash: j.props?.flash }); } } catch {} };
        page.on('response', h);
        await page.getByRole('button', { name: /save changes/i }).first().click();
        await page.waitForTimeout(2500);
        page.off('response', h);
        const vis = await page.locator('[aria-invalid="true"]').count();
        const errText = await page.locator('[id$="-error"], [role="alert"], p.text-red-600, .text-red-600, [class*="text-danger"], [class*="text-red"]').allInnerTexts();
        await shot(page, '40-adhoc-security-0');
        const near = await input.evaluate((el) => el.closest('div')?.parentElement?.innerText);
        return `db=${sql(`select value from system_settings where "group"='security' and key='max_login_attempts'`)[0][0]} responses=${JSON.stringify(got).slice(0, 500)} invalid=${vis} errText=${JSON.stringify(errText).slice(0, 300)} near=${JSON.stringify(near).slice(0, 200)}`;
    }, page);
    await R.step('audit-logs user_created filter body', async () => {
        await visit(page, '/audit-logs?event_type=user_created&search=25');
        const t = await body(page);
        await visit(page, '/audit-logs?event_type=user_created');
        const t2 = await body(page);
        return `with search: ${t.slice(0, 500)} ||| without search rows: ${(t2.match(/user[_ ]created/gi) || []).length} sample: ${t2.slice(t2.indexOf('Event'), t2.indexOf('Event') + 500)}`;
    }, page);
    await R.step('report viewer dashboard body', async () => {
        const c = await browser.newContext(); const p = await newPageIn(c);
        await login(p, 'qa.reportviewer@demo.local', 'password');
        await p.goto(`${BASE}/dashboard`, { waitUntil: 'networkidle' });
        const t = await body(p);
        await shot(p, '40-adhoc-rv-dashboard');
        await c.close();
        return t.slice(0, 700);
    });
    await R.step('sysadmin dashboard metric cards text', async () => {
        await visit(page, '/dashboard');
        const cards = await page.evaluate(() => Array.from(document.querySelectorAll('p.text-2xl')).map((v) => (v.closest('a, article, section, div[class*="rounded"]') || v.parentElement).innerText.replace(/\s+/g, ' ').slice(0, 90)));
        return JSON.stringify(cards);
    }, page);
    await browser.close(); R.save();
})().catch((e) => { console.error('FATAL', e); process.exit(1); });
