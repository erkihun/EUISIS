'use strict';
// Journey 4b: re-activate the suspended scan-only operator, first login, forced change, permission boundaries.
const { BASE, Recorder, newBrowser, newPageIn, login, shot } = require('./lib');
const { loadState, saveState, sql, bodyText, providerLogin } = require('./60-portal-lib');

const state = loadState();

(async () => {
    const R = new Recorder('60-portal-4b-operator');
    const { browser, page } = await newBrowser();
    R.attach(page);
    const o = state.transportOperator;
    const opId = sql(`select id from provider_users where username='${o.username}'`);

    await R.step('sysadmin4 re-activates the suspended operator from the account page', async () => {
        await login(page, 'qa.sysadmin4@demo.local', 'password', {});
        await page.goto(`${BASE}/provider-users/${opId}`, { waitUntil: 'networkidle' });
        const before = sql(`select status from provider_users where id='${opId}'`);
        if (before === 'suspended') {
            const btn = page.getByRole('button', { name: /^Activate$/i }).first();
            await btn.waitFor({ state: 'visible' });
            await btn.click();
            const dlg = page.locator('[role="dialog"]').last();
            const btns = dlg.getByRole('button'); await btns.last().waitFor({ state: 'visible', timeout: 8000 });
            const w = page.waitForResponse((r) => /activate/.test(r.url()), { timeout: 20000 }).catch(() => null);
            await btns.last().click(); await w; await page.waitForTimeout(600);
        }
        const after = sql(`select status,must_change_password from provider_users where id='${opId}'`);
        await shot(page, 'portal-operator-activated');
        if (!after.startsWith('active')) throw new Error('not active: ' + after);
        return `${before} -> ${after}`;
    }, page);

    await R.step('Operator login with the one-time password: identifier=username', async () => {
        const ctx = await browser.newContext(); const op = await newPageIn(ctx); R.attach(op);
        state._opCtx = null;
        const u = await providerLogin(op, BASE, o.username, o.temp);
        const b = await bodyText(op);
        await shot(op, 'portal-operator-login');
        if (/\/provider\/portal\/login/.test(u)) {
            const row = sql(`select status,portal_enabled,must_change_password,suspended_at from provider_users where id='${opId}'`);
            throw new Error(`login refused: "${(b.match(/.{0,80}(credentials|locked|too many|suspended|disabled).{0,80}/i) || [''])[0]}" row=${row}`);
        }
        const f = op.locator('form').filter({ has: op.locator('input[type=password]') }).first();
        const pw = f.locator('input[type=password]');
        const NEW = 'Copper-Meadow-9931!zq';
        await pw.nth(0).fill(o.temp); await pw.nth(1).fill(NEW); await pw.nth(2).fill(NEW);
        const w = op.waitForResponse((r) => /profile/.test(r.url()) && r.request().method() !== 'GET', { timeout: 20000 }).catch(() => null);
        await f.locator('button[type=submit]').click(); await w; await op.waitForTimeout(500);
        state.transportOperator.password = NEW; saveState(state);
        const out = [];
        for (const [p, expectOk] of [['/provider/portal/transport/scan', true], ['/provider/portal/transport/vehicles', false], ['/provider/portal/transport/invoices', false], ['/provider/portal/transport/trips', false], ['/provider/portal/transport/settlements', false], ['/provider/portal/transport/transactions', false], ['/provider/portal/transport/routes/create', false]]) {
            const resp = await op.goto(`${BASE}${p}`, { waitUntil: 'networkidle' });
            const s = resp.status(); out.push(`${p.replace('/provider/portal/transport', '')}:${s}`);
            if (expectOk && s !== 200) throw new Error(`${p} should be allowed: ${s}`);
            if (!expectOk && s === 200 && new RegExp(p + '$').test(op.url())) throw new Error(`${p} should be forbidden for a scan-only operator: ${s}`);
        }
        await op.goto(`${BASE}/provider/portal/transport/scan`, { waitUntil: 'networkidle' });
        await shot(op, 'portal-operator-scan');
        const nav = await op.locator('nav a, aside a').allInnerTexts();
        await ctx.close();
        return `${u.replace(BASE, '')}; ${out.join(' ')}; nav=${JSON.stringify(nav.map((s) => s.trim()).filter(Boolean))}`;
    }, page);

    await browser.close();
    R.save();
})().catch((e) => { console.error('FATAL', e); process.exit(1); });
