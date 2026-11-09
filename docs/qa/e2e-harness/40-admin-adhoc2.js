'use strict';
const { BASE, Recorder, newBrowser, login, visit, shot } = require('./lib');
const { sql, body } = require('./40-admin-lib');
(async () => {
    const R = new Recorder('40-admin-adhoc2');
    const { browser, page } = await newBrowser();
    R.attach(page);
    await login(page, 'qa.sysadmin5@demo.local', 'password');
    const uid = sql(`select id from users where email='qa.user.one@demo.local'`)[0][0];
    await R.step('audit-logs search by numeric id / by email text', async () => {
        const out = [];
        for (const q of [`event_type=user_created&search=${uid}`, `search=${uid}`, `search=User`, `event_type=user_created`]) {
            await visit(page, `/audit-logs?${q}`);
            const t = await body(page);
            const i = t.indexOf('Timestamp');
            out.push(`[${q}] → ${t.slice(i, i + 160)}`);
        }
        return out.join(' || ');
    }, page);
    await R.step('audit-logs: expand a row — are before/after values and IP shown?', async () => {
        await visit(page, `/audit-logs?event_type=user_updated`);
        const btn = page.getByText(/show changes/i).first();
        if (await btn.count()) await btn.click();
        await page.waitForTimeout(400);
        const t = await body(page);
        await shot(page, '40-audit-expanded');
        const i = t.indexOf('Hide changes') > 0 ? t.indexOf('Hide changes') : t.indexOf('Show changes');
        return t.slice(i, i + 500);
    }, page);
    await R.step('dashboard: scope text, Refresh → KPI vs DB', async () => {
        await visit(page, '/dashboard');
        const t = await body(page);
        const scope = (t.match(/Scope:[^T]{0,60}/) || [''])[0];
        const before = (t.match(/Active Employees\s*(\d+)/) || [])[1];
        const btn = page.getByRole('button', { name: /refresh/i }).first();
        if (await btn.count()) { await btn.click(); await page.waitForLoadState('networkidle'); await page.waitForTimeout(1500); }
        const t2 = await body(page);
        const after = (t2.match(/Active Employees\s*(\d+)/) || [])[1];
        const regAfter = (t2.match(/Employees registered\s*(\d+)/) || [])[1];
        const db = sql(`select count(*) from employees where status='active'`)[0][0];
        const dbAll = sql(`select count(*) from employees`)[0][0];
        const cache = sql(`select key, left(value,40), expiration from cache where key like '%dashboard%' limit 3`).map((r) => r.join('/')).join(' ; ');
        await shot(page, '40-dashboard-refreshed');
        return `scope="${scope}" active_employees before=${before} after_refresh=${after} employees_registered=${regAfter} DB active=${db} all=${dbAll} refreshed_text=${(t2.match(/Last refreshed [^R]*/) || [''])[0]} cache=${cache}`;
    }, page);
    await browser.close(); R.save();
})().catch((e) => { console.error('FATAL', e); process.exit(1); });
