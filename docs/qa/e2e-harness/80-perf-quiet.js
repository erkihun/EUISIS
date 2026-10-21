'use strict';
const fs = require('fs'); const path = require('path');
const { BASE, newBrowser, login } = require('./lib');
const pages = ['/dashboard','/employees','/organizations','/organizations/create','/organization-unit-types/create','/reporting-lines','/organizations/import-structure','/performance/plans','/users/create','/audit-logs','/card-requests','/id-cards','/vacancy-applications','/hierarchy-versions','/transport/trips'];
(async () => {
  const { browser, page } = await newBrowser({ timeout: 60000 });
  await login(page, 'qa.sysadmin8@demo.local', 'password');
  const out = [];
  for (const p of pages) {
    const runs = [];
    for (let i = 0; i < 3; i++) {
      const t0 = Date.now();
      const resp = await page.goto(BASE + p, { waitUntil: 'load' });
      const loadMs = Date.now() - t0;
      await page.waitForLoadState('networkidle').catch(() => {});
      const idleMs = Date.now() - t0;
      const nav = await page.evaluate(() => { const n = performance.getEntriesByType('navigation')[0]; return n ? { ttfb: Math.round(n.responseStart - n.requestStart), dcl: Math.round(n.domContentLoadedEventEnd), load: Math.round(n.loadEventEnd) } : null; });
      runs.push({ status: resp.status(), loadMs, idleMs, ...nav });
    }
    const med = (k) => runs.map(r => r[k]).sort((a, b) => a - b)[1];
    out.push({ page: p, status: runs[0].status, ttfb: med('ttfb'), dcl: med('dcl'), load: med('load'), idle: med('idleMs') });
    console.log(String(runs[0].status).padStart(3), String(med('ttfb')).padStart(5)+'ms ttfb', String(med('dcl')).padStart(5)+'ms dcl', String(med('load')).padStart(5)+'ms load', String(med('idleMs')).padStart(5)+'ms idle', p);
  }
  fs.writeFileSync(path.join(__dirname, 'results', '80-perf-quiet.json'), JSON.stringify(out, null, 2));
  await browser.close();
})().catch(e => { console.error('FATAL', e.message); process.exit(1); });
