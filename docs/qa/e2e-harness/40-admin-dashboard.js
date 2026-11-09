'use strict';
// Journey 9: Dashboard as System Admin and Report Viewer — widgets, XHR 5xx, time to interactive, numbers vs DB.
const fs = require('fs');
const path = require('path');
const { BASE, Recorder, newBrowser, newPageIn, login, shot } = require('./lib');
const { sql } = require('./40-admin-lib');

async function dashboardProbe(page, label) {
    const xhr = [];
    const h = (r) => { if (r.url().startsWith(BASE)) xhr.push({ url: r.url().replace(BASE, ''), status: r.status() }); };
    page.on('response', h);
    const t0 = Date.now();
    await page.goto(`${BASE}/dashboard`, { waitUntil: 'domcontentloaded' });
    const tDom = Date.now() - t0;
    await page.waitForLoadState('networkidle');
    const tIdle = Date.now() - t0;
    // "interactive": first metric card or chart title visible
    await page.locator('p.text-2xl, h3').first().waitFor({ state: 'visible', timeout: 15000 }).catch(() => {});
    const tInteractive = Date.now() - t0;
    await page.waitForTimeout(1500);
    page.off('response', h);
    const metrics = await page.evaluate(() => {
        const out = [];
        document.querySelectorAll('p.text-2xl').forEach((v) => {
            const card = v.closest('div');
            const label = card?.querySelector('p.text-sm')?.textContent?.trim() || card?.parentElement?.querySelector('p.text-sm')?.textContent?.trim() || '?';
            out.push({ label, value: v.textContent.trim() });
        });
        return out;
    });
    const charts = (await page.locator('h3').allInnerTexts()).map((s) => s.trim()).filter(Boolean);
    const tabs = (await page.locator('[role="tab"]').allInnerTexts()).map((s) => s.trim()).filter(Boolean);
    const empties = await page.getByText(/no data|no dashboard data/i).count();
    await shot(page, `40-dashboard-${label}`);
    return { tDom, tIdle, tInteractive, xhr: xhr.filter((x) => x.status >= 400), metrics, charts, tabs, empties };
}

(async () => {
    const R = new Recorder('40-admin-dashboard');
    const { browser, page } = await newBrowser();
    R.attach(page);
    const db = {
        employees: sql(`select count(*) from employees`)[0][0],
        employeesActive: sql(`select count(*) from employees where status='active'`)[0]?.[0],
        organizations: sql(`select count(*) from organizations where deleted_at is null`)[0][0],
        organizationsActive: sql(`select count(*) from organizations where deleted_at is null and status='active'`)[0][0],
        positions: sql(`select count(*) from positions where deleted_at is null`)[0]?.[0],
    };
    const report = { db };

    await R.step('Dashboard as System Admin: widgets, XHR errors, timings, KPI values vs DB', async () => {
        await login(page, 'qa.sysadmin5@demo.local', 'password');
        const r = await dashboardProbe(page, 'sysadmin');
        report.sysadmin = r;
        const emp = r.metrics.find((m) => /registered employees/i.test(m.label));
        const org = r.metrics.find((m) => /organizations?$/i.test(m.label) || /^organizations/i.test(m.label));
        const info = `dom=${r.tDom}ms idle=${r.tIdle}ms interactive=${r.tInteractive}ms xhr4xx5xx=${JSON.stringify(r.xhr)} tabs=[${r.tabs.join('|')}] kpis=${JSON.stringify(r.metrics).slice(0, 600)} charts=[${r.charts.join('|')}] empty-widgets=${r.empties} DB employees=${db.employees}/${db.employeesActive} organizations=${db.organizations}/${db.organizationsActive}`;
        if (r.xhr.some((x) => x.status >= 500)) throw new Error('5xx: ' + info);
        if (emp && emp.value.replace(/\D/g, '') !== db.employees) throw new Error(`registered employees KPI ${emp.value} != DB ${db.employees}; ` + info);
        return info;
    }, page);

    await R.step('Dashboard as Report Viewer: widgets, XHR errors, timings', async () => {
        const c = await browser.newContext();
        const p = await newPageIn(c);
        const R2 = new Recorder('40-admin-dashboard-reportviewer'); R2.attach(p);
        const url = await login(p, 'qa.reportviewer@demo.local', 'password');
        const r = await dashboardProbe(p, 'reportviewer');
        report.reportviewer = r;
        report.reportviewerConsole = R2.consoleErrors;
        const nav = (await p.locator('nav a, aside a').allInnerTexts()).map((s) => s.trim()).filter(Boolean);
        R2.save();
        await c.close();
        const info = `landed=${url.replace(BASE, '')} dom=${r.tDom}ms idle=${r.tIdle}ms interactive=${r.tInteractive}ms xhr4xx5xx=${JSON.stringify(r.xhr)} tabs=[${r.tabs.join('|')}] kpis=${JSON.stringify(r.metrics).slice(0, 400)} charts=[${r.charts.join('|')}] empty-widgets=${r.empties} nav=[${nav.join('|')}] console=${R2.consoleErrors.length}`;
        if (r.xhr.some((x) => x.status >= 500)) throw new Error('5xx: ' + info);
        return info;
    });

    await R.step('Dashboard filters (date range / organization) do not produce 5xx', async () => {
        const out = [];
        for (const q of ['?date_range=7d', '?date_range=90d', '?date_range=custom&date_from=2026-01-01&date_to=2026-10-20', '?organization_id=00000000-0000-0000-0000-000000000000', '?date_range=bogus']) {
            const resp = await page.goto(`${BASE}/dashboard${q}`, { waitUntil: 'networkidle' });
            out.push(`${q}:${resp.status()}`);
        }
        if (out.some((o) => /:5\d\d/.test(o))) throw new Error(out.join(' '));
        return out.join(' ');
    }, page);

    fs.writeFileSync(path.join(__dirname, 'results', '40-admin-dashboard-report.json'), JSON.stringify(report, null, 2));
    await browser.close();
    R.save();
})().catch((e) => { console.error('FATAL', e); process.exit(1); });
