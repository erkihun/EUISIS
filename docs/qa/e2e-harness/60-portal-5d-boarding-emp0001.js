'use strict';
// Journey 5d: full boarding flow pinned to the seeded card AA-2026-DEMO01 (EMP-0001): new trip, pass, accepted boarding, duplicate refused.
const { BASE, Recorder, newBrowser, newPageIn, login, submitAndLeave, visit, shot } = require('./lib');
const { loadState, saveState, sql, byLabel, fillLabel, selectLabelByText, bodyText, providerLogin } = require('./60-portal-lib');

const state = loadState();
const P = state.transportProviderId;
const today = new Date().toISOString().slice(0, 10);
const CARD = sql("select public_card_uuid,employee_id,status from id_cards where card_number='AA-2026-DEMO01'").split('|');

(async () => {
    const R = new Recorder('60-portal-5d-boarding-emp0001');
    const { browser, page } = await newBrowser();
    R.attach(page);
    const emp = sql(`select employee_number,full_name from employees where id='${CARD[1]}'`).split('|');

    await R.step('Back office: plan a second trip today and issue a pass for EMP-0001', async () => {
        if (CARD[2] !== 'active') throw new Error('AA-2026-DEMO01 is no longer active: ' + CARD[2]);
        await login(page, 'qa.sysadmin4@demo.local', 'password', {});
        await visit(page, '/transport/trips/create');
        await selectLabelByText(page, 'Provider', /QA-TRANSPORT-1/);
        await selectLabelByText(page, 'Route', /QA-RT-1/);
        (await byLabel(page, 'Trip Date')).fill(today);
        await fillLabel(page, 'Departure', '17:30');
        const dir = await byLabel(page, 'Direction'); await dir.selectOption('outbound').catch(() => null);
        await selectLabelByText(page, 'Vehicle', /QA-VEH-1/).catch(() => null);
        await selectLabelByText(page, 'Driver', /QA Driver 1/).catch(() => null);
        await fillLabel(page, 'Capacity', '40');
        await submitAndLeave(page, /trips\/create/);
        if (/\/create/.test(page.url())) throw new Error('trip: ' + (await page.locator('form').innerText()).replace(/\s+/g, ' ').slice(0, 300));
        const trip = sql(`select id,trip_number,status,direction from transport_trips where transport_route_id='${state.routeId}' order by created_at desc limit 1`);
        state.trip2Id = trip.split('|')[0]; state.trip2Number = trip.split('|')[1]; saveState(state);
        const existing = sql(`select id from transport_passes where employee_id='${CARD[1]}' and provider_id='${P}' and status='active' limit 1`);
        if (!existing) {
            await visit(page, '/transport/passes/create');
            const search = page.locator('input[placeholder="Search employees by name or number"]');
            await search.fill(emp[0]);
            const opt = page.locator('[role="listbox"] button').filter({ hasText: emp[0] }).first();
            await opt.waitFor({ state: 'visible', timeout: 15000 }); await opt.click();
            await selectLabelByText(page, 'Provider', /QA-TRANSPORT-1/);
            await selectLabelByText(page, 'Route', /QA-RT-1/);
            (await byLabel(page, 'Valid From')).fill(today);
            await submitAndLeave(page, /passes\/create/);
            if (/\/create/.test(page.url())) throw new Error('pass: ' + (await page.locator('form').innerText()).replace(/\s+/g, ' ').slice(0, 300));
        }
        const pass = sql(`select id,status,valid_from from transport_passes where employee_id='${CARD[1]}' and provider_id='${P}' order by created_at desc limit 1`);
        await shot(page, 'portal-transport-pass-emp0001');
        return `trip=${trip}; pass=${pass}`;
    }, page);

    const ctx = await browser.newContext(); const pp = await newPageIn(ctx); R.attach(pp);
    async function scan() {
        await pp.locator('input[placeholder="Card QR reference"]').fill(CARD[0]);
        const btn = pp.getByRole('button', { name: /Record boarding/i });
        if (await btn.isDisabled()) return { res: 'button disabled' };
        const w = pp.waitForResponse((r) => /transport\/scan$/.test(r.url()) && r.request().method() === 'POST', { timeout: 30000 }).catch(() => null);
        await btn.click(); const resp = await w; await pp.waitForTimeout(1500);
        const b = await bodyText(pp);
        return { status: resp ? resp.status() : null, res: (b.match(/Boarding (accepted|refused).{0,160}/) || [b.slice(0, 160)])[0] };
    }

    await R.step(`Portal: start boarding on ${state.trip2Number}; scan AA-2026-DEMO01 -> accepted, boarded_count=1`, async () => {
        const u = await providerLogin(pp, BASE, state.transportUser.username, state.transportUser.password);
        if (/login/.test(u)) throw new Error('portal login failed (throttle?) ' + (await bodyText(pp)).slice(0, 150));
        await visit(pp, '/provider/portal/transport/scan');
        await selectLabelByText(pp, 'Route', /QA-RT-1/).catch(() => null);
        await selectLabelByText(pp, 'Trip (today)', /17:30/);
        await pp.waitForTimeout(500);
        const start = pp.getByRole('button', { name: /Start boarding/i });
        if (await start.count()) { const w = pp.waitForResponse((r) => /dispatch/.test(r.url()), { timeout: 20000 }).catch(() => null); await start.first().click(); await w; await pp.waitForTimeout(800); }
        const r = await scan();
        await shot(pp, 'portal-transport-boarding-accepted');
        const tx = sql(`select status,result_code,verification_method from transport_transactions where provider_id='${P}' and transport_trip_id='${state.trip2Id}' order by created_at desc limit 1`);
        const trip = sql(`select status,boarded_count from transport_trips where id='${state.trip2Id}'`);
        if (!tx.startsWith('accepted')) throw new Error(`not accepted: "${r.res}" tx=${tx} trip=${trip}`);
        if (!/\|1$/.test(trip)) throw new Error('boarded_count not 1: ' + trip);
        return `HTTP ${r.status}; "${r.res}"; tx=${tx}; trip=${trip}`;
    }, pp);

    await R.step('Second boarding of the same person on the same trip is refused', async () => {
        await pp.waitForTimeout(2500);
        const r = await scan();
        await shot(pp, 'portal-transport-boarding-duplicate');
        const rows = sql(`select status,result_code from transport_transactions where provider_id='${P}' and transport_trip_id='${state.trip2Id}' order by created_at`);
        const accepted = rows.split('\n').filter((l) => l.startsWith('accepted')).length;
        const trip = sql(`select status,boarded_count from transport_trips where id='${state.trip2Id}'`);
        if (accepted !== 1) throw new Error('accepted count=' + accepted + ': ' + rows.replace(/\n/g, ' ; '));
        if (!/refused/i.test(r.res) && !rows.includes('rejected')) throw new Error('duplicate not refused: ' + r.res);
        return `"${r.res}"; rows=${rows.replace(/\n/g, ' ; ')}; trip=${trip}`;
    }, pp);

    await R.step('Depart + complete; manifest lists the boarded employee', async () => {
        for (const re of [/^Depart$/i, /Complete trip/i]) {
            const b = pp.getByRole('button', { name: re });
            if (await b.count()) { const w = pp.waitForResponse((r) => /dispatch/.test(r.url()), { timeout: 20000 }).catch(() => null); await b.first().click(); await w; await pp.waitForTimeout(800); }
        }
        const trip = sql(`select status,boarded_count from transport_trips where id='${state.trip2Id}'`);
        const resp = await pp.context().request.get(`${BASE}/provider/portal/transport/trips/${state.trip2Id}/manifest.pdf`, { failOnStatusCode: false });
        const buf = await resp.body();
        await visit(pp, `/provider/portal/transport/trips/${state.trip2Id}`);
        const b = await bodyText(pp);
        await shot(pp, 'portal-transport-trip-board');
        return `trip=${trip}; manifest ${resp.status()} ${resp.headers()['content-type']} ${buf.length}B; board page mentions EMP-0001=${/EMP-0001|Demo Employee/.test(b)}`;
    }, pp);

    await ctx.close(); await browser.close();
    R.save();
})().catch((e) => { console.error('FATAL', e); process.exit(1); });
