'use strict';
// Visit every parameterless GET web route as the given user and record HTTP status,
// console errors, page errors, 5xx XHRs and render time. Usage: node 02-crawl.js <email> <password> [name]
const fs = require('fs');
const path = require('path');
const { BASE, Recorder, newBrowser, login } = require('./lib');

const email = process.argv[2] || 'super.admin@demo.local';
const password = process.argv[3] || 'password';
const name = process.argv[4] || '02-crawl-superadmin';
const routes = JSON.parse(fs.readFileSync(path.join(__dirname, '..', 'routes.json'), 'utf8'));

const SKIP = [
    /^api\//, /^sanctum/, /^_debugbar/, /^telescope/, /^pulse/, /^_inertia/, /^up$/, /^health\//,
    /^logout/, /^mfa\/disable/, /^broadcasting/, /^storage\//, /^livewire/, /^_dusk/, /^horizon/,
    /download|export|\.pdf$|\.csv$|\.xlsx$|print$/i, // file responses: tested via API probes
    /^provider\/portal/, /^cafeteria\/portal/, // separate guard: covered by portal journey
];

(async () => {
    const R = new Recorder(name);
    const { browser, page } = await newBrowser({ timeout: 30000 });
    R.attach(page);
    const state = {};
    const secretFile = path.join(__dirname, 'results', 'superadmin-secret.txt');
    if (email === 'super.admin@demo.local' && fs.existsSync(secretFile)) state.secret = fs.readFileSync(secretFile, 'utf8').trim();
    const landed = await login(page, email, password, state);
    if (!/dashboard/.test(landed)) { console.error('login failed, landed on', landed); process.exit(1); }

    const targets = routes
        .filter((r) => r.method.startsWith('GET') && !r.uri.includes('{') && !SKIP.some((re) => re.test(r.uri)))
        .map((r) => ({ uri: r.uri === '/' ? '/' : '/' + r.uri, name: r.name, mw: (r.middleware || []).join(',') }));
    console.log(`Crawling ${targets.length} GET routes as ${email}`);

    const rows = [];
    for (const t of targets) {
        const t0 = Date.now();
        const errBefore = R.consoleErrors.length, pageErrBefore = R.pageErrors.length, failBefore = R.failedRequests.length;
        let status = 0, finalUrl = '', title = '', note = '';
        try {
            const resp = await page.goto(BASE + t.uri, { waitUntil: 'networkidle', timeout: 30000 });
            status = resp ? resp.status() : 0;
            finalUrl = page.url().replace(BASE, '');
            title = await page.title();
            const body = await page.content();
            if (/Whoops|Stack trace|SQLSTATE|vendor\/laravel\/framework/i.test(body)) note = 'DEBUG OUTPUT';
            const txt = await page.locator('body').innerText().catch(() => '');
            if (/Server Error|Something went wrong|500/.test(title) || /Server Error/i.test(txt.slice(0, 300))) note = note || 'ERROR PAGE';
        } catch (e) {
            note = 'EXC: ' + String(e.message).split('\n')[0].slice(0, 120);
        }
        const ms = Date.now() - t0;
        const row = {
            uri: t.uri, name: t.name, status, finalUrl, title, ms, note,
            consoleErrors: R.consoleErrors.slice(errBefore).map((e) => e.text),
            pageErrors: R.pageErrors.slice(pageErrBefore).map((e) => e.text),
            failedRequests: R.failedRequests.slice(failBefore).map((e) => `${e.status} ${e.url.replace(BASE, '')}`),
        };
        rows.push(row);
        const flag = (status >= 500 || note || row.pageErrors.length || row.failedRequests.length) ? '!!' : (status >= 400 ? ' ?' : '  ');
        console.log(`${flag} ${String(status).padStart(3)} ${String(ms).padStart(5)}ms ${t.uri}${finalUrl && finalUrl !== t.uri ? ' -> ' + finalUrl : ''}${note ? ' [' + note + ']' : ''}${row.pageErrors.length ? ' pageErr:' + row.pageErrors[0].slice(0, 80) : ''}${row.failedRequests.length ? ' xhr:' + row.failedRequests.join(',') : ''}`);
        if (/^\/login$/.test(finalUrl)) {
            // session lost — re-login and continue
            await login(page, email, password, state);
        }
    }
    fs.mkdirSync(path.join(__dirname, 'results'), { recursive: true });
    fs.writeFileSync(path.join(__dirname, 'results', `${name}.json`), JSON.stringify({ email, rows, consoleErrors: R.consoleErrors, pageErrors: R.pageErrors, failedRequests: R.failedRequests }, null, 2));
    const s5 = rows.filter((r) => r.status >= 500 || r.note).length;
    const s4 = rows.filter((r) => r.status >= 400 && r.status < 500).length;
    const slow = rows.filter((r) => r.ms > 3000).length;
    console.log(`\n${name}: ${rows.length} pages, ${s5} server errors/debug, ${s4} 4xx, ${slow} slower than 3s, ${R.pageErrors.length} page errors, ${R.consoleErrors.length} console warnings/errors`);
    await browser.close();
})().catch((e) => { console.error('FATAL', e); process.exit(1); });
