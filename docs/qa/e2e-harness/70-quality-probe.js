'use strict';
// Follow-up probes: employee-number preview error on /employees/create, Gregorian date context on Amharic dashboard,
// organization select labels in Amharic, notifications feed double fetch.
const fs = require('fs');
const path = require('path');
const { BASE, Recorder, newBrowser, login, shot } = require('./lib');
const ACCOUNT = { email: 'qa.sysadmin6@demo.local', password: 'password' };

(async () => {
    const R = new Recorder('70-quality-probe');
    const { browser, context, page } = await newBrowser({ uiLocale: 'am' });
    R.attach(page);
    const report = {};
    const reqs = [];
    page.on('response', async (r) => { const u = r.url(); if (/preview|next-number|employee-number|notifications\/feed|dashboard\?|locale=/.test(u) || r.request().resourceType() === 'xhr' || r.request().resourceType() === 'fetch') { let body = ''; try { body = (await r.text()).slice(0, 300); } catch {} reqs.push({ page: page.url(), url: u.replace(BASE, ''), status: r.status(), type: r.request().resourceType(), body }); } });

    await R.step('Login (am)', async () => { await login(page, ACCOUNT.email, ACCOUNT.password); }, page);

    await R.step('/employees/create: "Preview unavailable" error and its request', async () => {
        reqs.length = 0;
        await page.goto(`${BASE}/employees/create`, { waitUntil: 'networkidle' });
        await page.waitForTimeout(1500);
        const errText = await page.locator('p.text-red-600, p.text-red-700, [class*="text-red"]').allInnerTexts();
        const preview = await page.locator('input[name="employee_number"], input[readonly]').first().inputValue().catch(() => null);
        const refresh = page.locator('button[aria-label*="ቅድመ"], button[aria-label*="preview" i]').first();
        if (await refresh.count()) { await refresh.click(); await page.waitForTimeout(1500); }
        const errText2 = await page.locator('[class*="text-red"]').allInnerTexts();
        report.previewError = { errText, preview, errText2, requests: reqs.slice() };
        await shot(page, 'q-probe-employee-create-preview');
        return `preview=${preview} red="${errText.join(' / ')}" after refresh="${errText2.join(' / ')}" xhr=${JSON.stringify(reqs.map((r) => r.status + ' ' + r.url))}`;
    }, page);

    await R.step('Amharic dashboard: element showing the Gregorian date 2026-09-22 / dd/mm/yyyy', async () => {
        await page.goto(`${BASE}/dashboard`, { waitUntil: 'networkidle' });
        await page.waitForTimeout(800);
        const ctx = await page.evaluate(() => {
            const out = [];
            const walker = document.createTreeWalker(document.body, NodeFilter.SHOW_TEXT);
            let n;
            while ((n = walker.nextNode())) {
                if (/\d{4}-\d{2}-\d{2}|\d{2}\/\d{2}\/\d{4}/.test(n.textContent)) {
                    const el = n.parentElement; const card = el.closest('section, article, div[class*="rounded"]');
                    out.push({ text: n.textContent.trim().slice(0, 60), tag: el.tagName, cls: String(el.className).slice(0, 60), heading: card && (card.querySelector('h2,h3,h4,dt,p')?.textContent || '').trim().slice(0, 50) });
                }
            }
            return out;
        });
        report.dashboardGregorian = ctx;
        return JSON.stringify(ctx).slice(0, 400);
    }, page);

    await R.step('Amharic employees list: organization filter options and organization column text', async () => {
        await page.goto(`${BASE}/employees`, { waitUntil: 'networkidle' });
        const opts = await page.locator('select').nth(0).locator('option').allInnerTexts();
        const cells = await page.locator('tbody tr').first().innerText().catch(() => '');
        report.employeeOrgOptions = { opts: opts.slice(0, 5), firstRow: cells.replace(/\n+/g, ' | ').slice(0, 200) };
        return `options=${JSON.stringify(opts.slice(0, 4))} row="${cells.replace(/\n+/g, ' | ').slice(0, 120)}"`;
    }, page);

    await R.step('Audit log in Amharic: event / entity labels and date format', async () => {
        await page.goto(`${BASE}/audit-logs`, { waitUntil: 'networkidle' });
        const rows = await page.locator('tbody tr').allInnerTexts();
        report.auditRows = rows.slice(0, 3).map((r) => r.replace(/\n+/g, ' | ').slice(0, 220));
        await shot(page, 'q-probe-audit-am');
        return report.auditRows[0];
    }, page);

    await R.step('Users list in Amharic: role column and role filter', async () => {
        await page.goto(`${BASE}/users`, { waitUntil: 'networkidle' });
        const row = await page.locator('tbody tr').first().innerText().catch(() => '');
        const th = await page.locator('thead').first().innerText().catch(() => '');
        report.users = { head: th.replace(/\n+/g, ' | '), row: row.replace(/\n+/g, ' | ').slice(0, 200) };
        return `head="${report.users.head.slice(0, 100)}" row="${report.users.row.slice(0, 120)}"`;
    }, page);

    fs.writeFileSync(path.join(__dirname, 'results', '70-quality-probe-report.json'), JSON.stringify(report, null, 2));
    await browser.close();
    R.save();
})().catch((e) => { console.error('FATAL', e); process.exit(1); });
