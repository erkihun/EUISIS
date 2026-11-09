'use strict';
// Journey 5c: issue a transport pass for the card holder, then the portal boarding is accepted; duplicate boarding refused.
const { BASE, Recorder, newBrowser, newPageIn, login, submitAndLeave, visit, shot } = require('./lib');
const { loadState, saveState, sql, byLabel, selectLabelByText, bodyText, providerLogin } = require('./60-portal-lib');

const state = loadState();
const P = state.transportProviderId;
const today = new Date().toISOString().slice(0, 10);

(async () => {
    const R = new Recorder('60-portal-5c-pass-boarding');
    const { browser, page } = await newBrowser();
    R.attach(page);

    const card = sql("select public_card_uuid,employee_id from id_cards where status='active' and public_card_uuid is not null order by updated_at desc limit 1");
    const [publicUuid, employeeId] = card.split('|');
    const emp = sql(`select employee_number,full_name from employees where id='${employeeId}'`).split('|');

    await R.step(`Back office: issue a transport pass for ${emp[0]} on QA-RT-1`, async () => {
        await login(page, 'qa.sysadmin4@demo.local', 'password', {});
        await visit(page, '/transport/passes/create');
        const search = page.locator('input[placeholder="Search employees by name or number"]');
        await search.fill(emp[0]);
        const opt = page.locator('[role="listbox"] button').filter({ hasText: emp[0] }).first();
        await opt.waitFor({ state: 'visible', timeout: 15000 });
        await opt.click();
        await selectLabelByText(page, 'Provider', /QA-TRANSPORT-1/);
        await selectLabelByText(page, 'Route', /QA-RT-1/);
        (await byLabel(page, 'Valid From')).fill(today);
        await shot(page, 'portal-transport-pass-form');
        await submitAndLeave(page, /passes\/create/);
        if (/\/create/.test(page.url())) throw new Error('stayed: ' + (await page.locator('form').innerText()).replace(/\s+/g, ' ').slice(0, 400));
        const row = sql(`select id,status,valid_from from transport_passes where employee_id='${employeeId}' and provider_id='${P}' order by created_at desc limit 1`);
        if (!row) throw new Error('no pass row');
        state.passId = row.split('|')[0]; saveState(state);
        return row;
    }, page);

    const ctx = await browser.newContext(); const pp = await newPageIn(ctx); R.attach(pp);
    async function scan() {
        await pp.locator('input[placeholder="Card QR reference"]').fill(publicUuid);
        const btn = pp.getByRole('button', { name: /Record boarding/i });
        if (await btn.isDisabled()) return { res: 'button disabled' };
        const w = pp.waitForResponse((r) => /transport\/scan$/.test(r.url()) && r.request().method() === 'POST', { timeout: 30000 }).catch(() => null);
        await btn.click(); const resp = await w; await pp.waitForTimeout(1500);
        const b = await bodyText(pp);
        return { status: resp ? resp.status() : null, res: (b.match(/Boarding (accepted|refused).{0,160}/) || [b.slice(0, 160)])[0] };
    }

    await R.step('Portal: boarding with a valid pass is accepted and counted on the trip', async () => {
        await providerLogin(pp, BASE, state.transportUser.username, state.transportUser.password);
        await visit(pp, '/provider/portal/transport/scan');
        await selectLabelByText(pp, 'Route', /QA-RT-1/).catch(() => null);
        await selectLabelByText(pp, 'Trip (today)', /QA-RT-1/);
        await pp.waitForTimeout(500);
        const start = pp.getByRole('button', { name: /Start boarding/i });
        if (await start.count()) { const w = pp.waitForResponse((r) => /dispatch/.test(r.url()), { timeout: 20000 }).catch(() => null); await start.first().click(); await w; await pp.waitForTimeout(800); }
        const r = await scan();
        await shot(pp, 'portal-transport-boarding-accepted');
        const tx = sql(`select status,result_code,transport_trip_id is not null from transport_transactions where provider_id='${P}' order by created_at desc limit 1`);
        const trip = sql(`select status,boarded_count from transport_trips where id='${state.tripId}'`);
        if (!tx.startsWith('accepted')) throw new Error(`not accepted: "${r.res}" tx=${tx}`);
        return `HTTP ${r.status}; "${r.res}"; tx=${tx}; trip=${trip}`;
    }, pp);

    await R.step('Second boarding of the same person on the same trip is refused', async () => {
        await pp.waitForTimeout(2500);
        const r = await scan();
        await shot(pp, 'portal-transport-boarding-duplicate');
        const rows = sql(`select status,result_code from transport_transactions where provider_id='${P}' and transport_trip_id='${state.tripId}' order by created_at`);
        const accepted = rows.split('\n').filter((l) => l.startsWith('accepted')).length;
        if (accepted !== 1) throw new Error('accepted count=' + accepted + ': ' + rows.replace(/\n/g, ' ; '));
        return `"${r.res}"; rows=${rows.replace(/\n/g, ' ; ')}`;
    }, pp);

    await R.step('Depart + complete the trip from the portal; manifest PDF downloads', async () => {
        for (const re of [/^Depart$/i, /Complete trip/i]) {
            const b = pp.getByRole('button', { name: re });
            if (await b.count()) { const w = pp.waitForResponse((r) => /dispatch/.test(r.url()), { timeout: 20000 }).catch(() => null); await b.first().click(); await w; await pp.waitForTimeout(800); }
        }
        const trip = sql(`select status,boarded_count from transport_trips where id='${state.tripId}'`);
        const resp = await pp.context().request.get(`${BASE}/provider/portal/transport/trips/${state.tripId}/manifest.pdf`, { failOnStatusCode: false });
        const buf = await resp.body();
        return `trip=${trip}; manifest: ${resp.status()} ${resp.headers()['content-type']} ${buf.length}B head=${buf.slice(0, 4).toString()}`;
    }, pp);

    await R.step('Back office Boardings list shows accepted + refused rows for QA-TRANSPORT-1', async () => {
        await visit(page, `/transport/transactions?provider_id=${P}`);
        const b = await bodyText(page);
        await shot(page, 'portal-transport-bo-boardings');
        if (!/QA-RT-1|Demo Employee|EMP-0001/.test(b)) throw new Error('rows missing');
        return 'ok';
    }, page);

    await ctx.close(); await browser.close();
    R.save();
})().catch((e) => { console.error('FATAL', e); process.exit(1); });
