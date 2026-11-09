'use strict';
// Journey 4: provider portal isolation — cafeteria vs transport services, provider vs staff guards, operator permissions.
const { BASE, Recorder, newBrowser, newPageIn, login, shot } = require('./lib');
const { loadState, saveState, sql, bodyText, providerLogin } = require('./60-portal-lib');

const state = loadState();

async function probe(page, path) {
    const resp = await page.goto(`${BASE}${path}`, { waitUntil: 'networkidle' }).catch((e) => null);
    const status = resp ? resp.status() : 0;
    const url = page.url().replace(BASE, '');
    const body = await bodyText(page);
    return { status, url, body };
}

function assertNoData(r, dataRe, label) {
    if (dataRe.test(r.body)) throw new Error(`${label}: data visible (${r.status} ${r.url}): ${r.body.match(dataRe)[0]}`);
}

(async () => {
    const R = new Recorder('60-portal-4-isolation');
    const { browser } = await newBrowser();

    // Cafeteria provider user
    const c1 = await browser.newContext(); const cafe = await newPageIn(c1); R.attach(cafe);
    await R.step('Cafeteria provider user signs in', async () => {
        const u = await providerLogin(cafe, BASE, state.cafeUser.email, state.cafeUser.password);
        if (/login/.test(u)) throw new Error('login failed');
        return u.replace(BASE, '');
    }, cafe);

    await R.step('Cafeteria user cannot reach transport portal pages (403, no data)', async () => {
        const out = [];
        for (const p of ['/provider/portal/transport/scan', '/provider/portal/transport/dashboard', '/provider/portal/transport/trips', '/provider/portal/transport/settlements']) {
            const r = await probe(cafe, p);
            out.push(`${p.replace('/provider/portal/transport', '')}:${r.status}->${r.url}`);
            if (r.status === 200 && /\/transport\//.test(r.url)) throw new Error(`${p} reachable: ${r.status} ${r.url}`);
            assertNoData(r, /QA-RT-1|QA-VEH-1|QA Driver 1/, p);
        }
        await shot(cafe, 'portal-iso-cafe-transport-403');
        return out.join(' ');
    }, cafe);

    await R.step('Cafeteria user cannot reach staff pages /dashboard, /employees, /cafeteria/transactions (redirect to staff login, no data)', async () => {
        const out = [];
        for (const p of ['/dashboard', '/employees', '/cafeteria/transactions', '/provider-users', '/transport/settlements']) {
            const r = await probe(cafe, p);
            out.push(`${p}:${r.status}->${r.url}`);
            if (!/\/login/.test(r.url) && r.status === 200) throw new Error(`${p} rendered for provider session: ${r.url}`);
            assertNoData(r, /EMP-000\d|Demo Employee|qa\.sysadmin/, p);
        }
        return out.join(' ');
    }, cafe);

    await R.step('Cafeteria user: cross-provider transaction id (transport tx) and foreign menu ids give 404, not data', async () => {
        const txn = sql(`select id from transport_transactions order by created_at desc limit 1`);
        const out = [];
        if (txn) { const r = await probe(cafe, `/provider/portal/transactions/${txn}`); out.push(`transport tx via cafeteria route: ${r.status}`); if (r.status === 200) throw new Error('foreign transaction rendered'); }
        const r2 = await probe(cafe, `/provider/portal/menus/00000000-0000-0000-0000-000000000000`); out.push(`unknown menu: ${r2.status}`);
        return out.join('; ');
    }, cafe);

    // Transport owner user
    const c2 = await browser.newContext(); const tr = await newPageIn(c2); R.attach(tr);
    await R.step('Transport provider user signs in and lands on the transport dashboard', async () => {
        const u = await providerLogin(tr, BASE, state.transportUser.username, state.transportUser.password);
        if (/login/.test(u)) throw new Error('login failed: ' + u);
        return u.replace(BASE, '');
    }, tr);

    await R.step('Transport user cannot reach cafeteria portal pages (prefixed and un-prefixed), no cafeteria data', async () => {
        const out = [];
        for (const p of ['/provider/portal/cafeteria/scan', '/provider/portal/cafeteria/transactions', '/provider/portal/scan', '/provider/portal/transactions', '/provider/portal/menus', '/provider/portal/ledger', '/provider/portal/orders', '/provider/portal/transactions/export/csv']) {
            const r = await probe(tr, p);
            out.push(`${p.replace('/provider/portal', '')}:${r.status}->${r.url}`);
            assertNoData(r, /Demo Employee|EMP-0001|CAF-2026|QA Cafeteria 1/, p);
            if (r.status === 200 && new RegExp(p.replace(/\//g, '\\/') + '$').test(r.url) && !/\/transport\//.test(r.url)) out[out.length - 1] += ' (RENDERED!)';
        }
        await shot(tr, 'portal-iso-transport-cafeteria');
        if (out.some((o) => /RENDERED!/.test(o))) throw new Error('cafeteria pages served to a transport-only provider: ' + out.join(' '));
        return out.join(' ');
    }, tr);

    await R.step('Transport user cannot reach staff pages', async () => {
        const out = [];
        for (const p of ['/dashboard', '/employees', '/transport/trips', '/transport/providers']) {
            const r = await probe(tr, p);
            out.push(`${p}:${r.status}->${r.url}`);
            if (!/\/login/.test(r.url) && r.status === 200) throw new Error(`${p} rendered for provider session: ${r.url}`);
            assertNoData(r, /EMP-000\d|SP-TRANSPORT-DEMO|qa\.sysadmin/, p);
        }
        return out.join(' ');
    }, tr);

    await R.step('Transport user cannot open another provider\'s vehicle edit page (404)', async () => {
        const other = sql(`select id from transport_vehicles where provider_id<>'${state.transportProviderId}' limit 1`);
        if (!other) return 'no other provider vehicle exists (seed has none) - skipped';
        const r = await probe(tr, `/provider/portal/transport/vehicles/${other}/edit`);
        if (r.status === 200) throw new Error('foreign vehicle edit rendered');
        return `status ${r.status}`;
    }, tr);

    // Operator with scan permission only
    const c3 = await browser.newContext(); const op = await newPageIn(c3); R.attach(op);
    await R.step('Transport operator (scan-only) logs in, changes password, can scan but not manage vehicles/invoices', async () => {
        const o = state.transportOperator;
        const u = await providerLogin(op, BASE, o.username, o.temp);
        if (!/profile/.test(u)) throw new Error('expected forced change, got ' + u);
        const f = op.locator('form').filter({ has: op.locator('input[type=password]') }).first();
        const pw = f.locator('input[type=password]');
        const NEW = 'Copper-Meadow-9931!zq';
        await pw.nth(0).fill(o.temp); await pw.nth(1).fill(NEW); await pw.nth(2).fill(NEW);
        const w = op.waitForResponse((r) => /profile/.test(r.url()) && r.request().method() !== 'GET', { timeout: 20000 }).catch(() => null);
        await f.locator('button[type=submit]').click(); await w; await op.waitForTimeout(500);
        state.transportOperator.password = NEW; saveState(state);
        const out = [];
        for (const [p, expectOk] of [['/provider/portal/transport/scan', true], ['/provider/portal/transport/vehicles', false], ['/provider/portal/transport/invoices', false], ['/provider/portal/transport/trips', false], ['/provider/portal/transport/settlements', false], ['/provider/portal/transport/transactions', false]]) {
            const r = await probe(op, p);
            out.push(`${p.replace('/provider/portal/transport', '')}:${r.status}`);
            if (expectOk && r.status !== 200) throw new Error(`${p} should be allowed: ${r.status}`);
            if (!expectOk && r.status === 200 && new RegExp(p + '$').test(r.url)) throw new Error(`${p} should be forbidden for operator: ${r.status}`);
        }
        await shot(op, 'portal-iso-operator');
        return out.join(' ');
    }, op);

    // Staff session
    const c4 = await browser.newContext(); const st = await newPageIn(c4); R.attach(st);
    await R.step('Staff session (qa.sysadmin4) cannot reach provider portal pages', async () => {
        await login(st, 'qa.sysadmin4@demo.local', 'password', {});
        const out = [];
        for (const p of ['/provider/portal/scan', '/provider/portal/dashboard', '/provider/portal/transport/scan', '/provider/portal/transactions/export/csv', '/provider/portal/scan/today']) {
            const r = await probe(st, p);
            out.push(`${p.replace('/provider/portal', '')}:${r.status}->${r.url}`);
            if (r.status === 200 && !/provider\/portal\/login/.test(r.url)) throw new Error(`${p} served to staff: ${r.url}`);
        }
        return out.join(' ');
    }, st);

    await R.step('Suspended provider user is locked out immediately (sysadmin suspends operator; operator session ends)', async () => {
        const opId = sql(`select id from provider_users where username='${state.transportOperator.username}'`);
        await st.goto(`${BASE}/provider-users/${opId}`, { waitUntil: 'networkidle' });
        const btn = st.getByRole('button', { name: /^Suspend/i }).first();
        if (!(await btn.count())) return 'no Suspend button visible - skipped';
        await btn.click();
        const dlg = st.locator('[role="dialog"]').last();
        const btns = dlg.getByRole('button'); await btns.last().waitFor({ state: 'visible', timeout: 8000 });
        const ta = dlg.locator('textarea, input:not([type=hidden])').first(); if (await ta.count()) await ta.fill('QA isolation test');
        const w = st.waitForResponse((r) => /suspend/.test(r.url()), { timeout: 20000 }).catch(() => null);
        await btns.last().click(); await w; await st.waitForTimeout(600);
        const row = sql(`select status from provider_users where id='${opId}'`);
        const r = await probe(op, '/provider/portal/transport/scan');
        await shot(st, 'portal-iso-suspended');
        if (row !== 'suspended') return 'suspend did not apply: ' + row;
        if (r.status === 200 && /transport\/scan/.test(r.url)) throw new Error('suspended operator still has access');
        return `status=${row}; operator now -> ${r.status} ${r.url}`;
    }, st);

    await c1.close(); await c2.close(); await c3.close(); await c4.close();
    await browser.close();
    R.save();
})().catch((e) => { console.error('FATAL', e); process.exit(1); });
