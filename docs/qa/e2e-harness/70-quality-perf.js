'use strict';
// Journey 6: Navigation Timing, JS requests/bytes, XHR after load, median of 3 cold runs. English UI.
const fs = require('fs');
const path = require('path');
const { chromium } = require('playwright');
const { BASE, Recorder } = require('./lib');

const ACCOUNT = { email: 'qa.sysadmin6@demo.local', password: 'password' };
// /reports is not a route (404 in journey 1); organization-design/reports stands in as the "reports" page.
const PAGES = ['/dashboard', '/employees', '/organizations', '/audit-logs', '/organization-design/reports', '/reports'];

function median(a) { const s = [...a].sort((x, y) => x - y); return s.length ? s[Math.floor(s.length / 2)] : null; }

(async () => {
    const R = new Recorder('70-quality-perf');
    const browser = await chromium.launch({ headless: true });
    // One signed-in session; cookies reused by each fresh (cold-cache) context
    const seed = await browser.newContext();
    await seed.addCookies([{ name: 'euisis_locale', value: 'en', url: BASE }]);
    const sp = await seed.newPage();
    await sp.goto(`${BASE}/login`, { waitUntil: 'networkidle' });
    await sp.fill('input[name="email"]', ACCOUNT.email);
    await sp.fill('input[name="password"]', ACCOUNT.password);
    await sp.click('button[type="submit"]');
    await sp.waitForURL(/dashboard/, { timeout: 30000 });
    const cookies = await seed.cookies();
    await seed.close();

    const report = {};
    for (const url of PAGES) {
        const runs = [];
        await R.step(`Perf ${url} ×3 cold`, async () => {
            for (let i = 0; i < 3; i++) {
                const ctx = await browser.newContext();
                await ctx.addCookies(cookies);
                await ctx.addInitScript(() => { try { localStorage.setItem('euisis_locale', 'en'); } catch {} });
                const page = await ctx.newPage();
                R.attach(page);
                const js = []; const xhr = []; let loadedAt = null; let status = 0;
                page.on('response', async (r) => {
                    const t = r.request().resourceType();
                    if (t === 'script') { let size = 0; try { const h = r.headers()['content-length']; size = h ? parseInt(h) : (await r.body()).length; } catch {} js.push({ url: r.url().replace(BASE, ''), size, status: r.status() }); }
                    if (t === 'xhr' || t === 'fetch') xhr.push({ url: r.url().replace(BASE, ''), status: r.status(), afterLoad: loadedAt !== null, t: Date.now() });
                });
                page.on('load', () => { loadedAt = Date.now(); });
                const t0 = Date.now();
                const resp = await page.goto(`${BASE}${url}`, { waitUntil: 'networkidle', timeout: 60000 });
                status = resp ? resp.status() : 0;
                const wall = Date.now() - t0;
                const nav = await page.evaluate(() => { const n = performance.getEntriesByType('navigation')[0]; return n ? { ttfb: Math.round(n.responseStart), dcl: Math.round(n.domContentLoadedEventEnd), load: Math.round(n.loadEventEnd), transfer: n.transferSize, type: n.type } : null; });
                const firstPaint = await page.evaluate(() => { const p = performance.getEntriesByName('first-contentful-paint')[0]; return p ? Math.round(p.startTime) : null; });
                const inertia = await page.evaluate(() => { const el = document.getElementById('app'); const d = el && el.getAttribute('data-page'); return d ? d.length : 0; });
                const finalUrl = page.url();
                runs.push({ status, finalUrl, wall, nav, fcp: firstPaint, jsCount: js.length, jsBytes: js.reduce((s, j) => s + j.size, 0), xhrAfterLoad: xhr.filter((x) => x.afterLoad).length, xhrTotal: xhr.length, xhr: xhr.map((x) => `${x.status} ${x.url}`), inertiaPropsBytes: inertia, js: js.sort((a, b) => b.size - a.size).slice(0, 5) });
                await ctx.close();
            }
            const m = {
                status: runs[0].status, finalUrl: runs[0].finalUrl, medianWall: median(runs.map((r) => r.wall)), medianDCL: median(runs.map((r) => r.nav && r.nav.dcl)), medianLoad: median(runs.map((r) => r.nav && r.nav.load)), medianTTFB: median(runs.map((r) => r.nav && r.nav.ttfb)), medianFCP: median(runs.map((r) => r.fcp)),
                jsCount: runs[0].jsCount, jsBytes: runs[0].jsBytes, xhrAfterLoad: median(runs.map((r) => r.xhrAfterLoad)), xhrSample: runs[0].xhr.slice(0, 12), inertiaPropsBytes: runs[0].inertiaPropsBytes, largestJs: runs[0].js.slice(0, 3), runs,
            };
            report[url] = m;
            if (m.status >= 400) throw new Error(`HTTP ${m.status}`);
            const info = `status=${m.status} TTFB=${m.medianTTFB}ms DCL=${m.medianDCL}ms load=${m.medianLoad}ms FCP=${m.medianFCP}ms wall(networkidle)=${m.medianWall}ms js=${m.jsCount} req/${(m.jsBytes / 1024).toFixed(0)}KB xhrAfterLoad=${m.xhrAfterLoad} props=${(m.inertiaPropsBytes / 1024).toFixed(0)}KB`;
            if (m.medianLoad > 3000 || m.medianWall > 3000) throw new Error('SLOW ' + info);
            return info;
        });
    }

    // Build manifest chunk sizes
    const manifest = JSON.parse(fs.readFileSync('/home/user/EUISIS/public/build/manifest.json', 'utf8'));
    const chunks = [];
    for (const [k, v] of Object.entries(manifest)) {
        if (v.file && v.file.endsWith('.js')) { const p = path.join('/home/user/EUISIS/public/build', v.file); try { chunks.push({ key: k, file: v.file, bytes: fs.statSync(p).size }); } catch {} }
    }
    chunks.sort((a, b) => b.bytes - a.bytes);
    report._manifest = { jsChunks: chunks.length, totalBytes: chunks.reduce((s, c) => s + c.bytes, 0), top: chunks.slice(0, 10) };
    fs.writeFileSync(path.join(__dirname, 'results', '70-quality-perf-report.json'), JSON.stringify(report, null, 2));
    await browser.close();
    R.save();
})().catch((e) => { console.error('FATAL', e); process.exit(1); });
