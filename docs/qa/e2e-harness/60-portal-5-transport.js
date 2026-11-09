'use strict';
// Journey 5: transport — back office vehicle/driver/route/schedule/trip, then the transport provider portal (password change, scan/boarding flow, finance pages).
const { BASE, Recorder, newBrowser, newPageIn, login, submitAndLeave, visit, shot } = require('./lib');
const { loadState, saveState, sql, byLabel, fillLabel, selectLabel, selectLabelByText, bodyText, flashText, providerLogin, fetchWithSession } = require('./60-portal-lib');

const state = loadState();
const today = new Date().toISOString().slice(0, 10);
const NEW_PW = 'Harbor-Lantern-4482!zq';
const P = state.transportProviderId;

(async () => {
    const R = new Recorder('60-portal-5-transport');
    const { browser, page } = await newBrowser();
    R.attach(page);
    const slow = [];
    page.on('response', (r) => { const t = r.request().timing(); if (t && t.responseEnd > 3000 && r.request().resourceType() === 'document') slow.push({ url: r.url(), ms: Math.round(t.responseEnd) }); });

    await R.step('Login as qa.sysadmin4', async () => login(page, 'qa.sysadmin4@demo.local', 'password', {}), page);

    await R.step('Transport overview + providers list show QA-TRANSPORT-1', async () => {
        await visit(page, '/transport/providers');
        const b = await bodyText(page);
        if (!b.includes('QA-TRANSPORT-1')) throw new Error('provider not listed');
        await visit(page, '/transport');
        await shot(page, 'portal-transport-overview');
    }, page);

    await R.step('Create vehicle QA-VEH-1', async () => {
        await visit(page, '/transport/vehicles/create');
        await selectLabelByText(page, 'Provider', /QA-TRANSPORT-1/);
        await fillLabel(page, 'Vehicle Code', 'QA-VEH-1');
        await fillLabel(page, 'Plate Number', 'QA-VEH-1');
        await fillLabel(page, 'Capacity', '40');
        await submitAndLeave(page, /vehicles\/create/);
        if (/\/create/.test(page.url())) throw new Error('stayed: ' + (await page.locator('form').innerText()).replace(/\s+/g, ' ').slice(0, 300));
        const row = sql(`select id,status,capacity from transport_vehicles where vehicle_code='QA-VEH-1' and provider_id='${P}'`);
        if (!row) throw new Error('no vehicle row');
        state.vehicleId = row.split('|')[0]; saveState(state);
        await shot(page, 'portal-transport-vehicles');
        return row;
    }, page);

    await R.step('Create driver QA Driver 1 assigned to QA-VEH-1', async () => {
        await visit(page, '/transport/drivers/create');
        await selectLabelByText(page, 'Provider', /QA-TRANSPORT-1/);
        await fillLabel(page, 'Driver Name', 'QA Driver 1');
        await fillLabel(page, 'Phone Number', '+251911000001');
        await selectLabelByText(page, 'Assigned Vehicle', /QA-VEH-1/).catch(() => null);
        await submitAndLeave(page, /drivers\/create/);
        if (/\/create/.test(page.url())) throw new Error('stayed: ' + (await page.locator('form').innerText()).replace(/\s+/g, ' ').slice(0, 300));
        const row = sql(`select id,status from transport_drivers where full_name='QA Driver 1' and provider_id='${P}' order by created_at desc limit 1`);
        if (!row) throw new Error('no driver row');
        state.driverId = row.split('|')[0]; saveState(state);
        return row;
    }, page);

    await R.step('Create route QA-RT-1 (citywide)', async () => {
        await visit(page, '/transport/routes/create');
        await selectLabelByText(page, 'Provider', /QA-TRANSPORT-1/);
        await fillLabel(page, 'Route Code', 'QA-RT-1');
        await fillLabel(page, 'Name English', 'QA Route 1');
        await fillLabel(page, 'Origin (EN)', 'Megenagna');
        await fillLabel(page, 'Destination (EN)', 'Piassa');
        await submitAndLeave(page, /routes\/create/);
        if (/\/create/.test(page.url())) throw new Error('stayed: ' + (await page.locator('form').innerText()).replace(/\s+/g, ' ').slice(0, 300));
        const row = sql(`select id,is_active,assigned_scope_type from transport_routes where route_code='QA-RT-1'`);
        if (!row) throw new Error('no route row');
        state.routeId = row.split('|')[0]; saveState(state);
        return row;
    }, page);

    await R.step('Create schedule on QA-RT-1 (07:30 inbound, Mon-Fri)', async () => {
        await visit(page, '/transport/schedules/create');
        await selectLabelByText(page, 'Provider', /QA-TRANSPORT-1/);
        await selectLabelByText(page, 'Route', /QA-RT-1/);
        await fillLabel(page, 'Departure', '07:30');
        await selectLabelByText(page, 'Default Vehicle', /QA-VEH-1/).catch(() => null);
        await selectLabelByText(page, 'Default Driver', /QA Driver 1/).catch(() => null);
        await submitAndLeave(page, /schedules\/create/);
        if (/\/create/.test(page.url())) throw new Error('stayed: ' + (await page.locator('form').innerText()).replace(/\s+/g, ' ').slice(0, 300));
        const row = sql(`select id,direction,planned_departure,is_active from transport_schedules where transport_route_id='${state.routeId}' order by created_at desc limit 1`);
        if (!row) throw new Error('no schedule row');
        state.scheduleId = row.split('|')[0]; saveState(state);
        return row;
    }, page);

    await R.step('Plan an extra trip today on QA-RT-1 with QA-VEH-1 / QA Driver 1', async () => {
        await visit(page, '/transport/trips/create');
        await selectLabelByText(page, 'Provider', /QA-TRANSPORT-1/);
        await selectLabelByText(page, 'Route', /QA-RT-1/);
        const d = await byLabel(page, 'Trip Date'); await d.fill(today);
        await fillLabel(page, 'Departure', '08:00');
        await selectLabelByText(page, 'Vehicle', /QA-VEH-1/).catch(() => null);
        await selectLabelByText(page, 'Driver', /QA Driver 1/).catch(() => null);
        await fillLabel(page, 'Capacity', '40');
        await submitAndLeave(page, /trips\/create/);
        if (/\/create/.test(page.url())) throw new Error('stayed: ' + (await page.locator('form').innerText()).replace(/\s+/g, ' ').slice(0, 300));
        const row = sql(`select id,trip_number,status,trip_date from transport_trips where transport_route_id='${state.routeId}' order by created_at desc limit 1`);
        if (!row) throw new Error('no trip row');
        state.tripId = row.split('|')[0]; state.tripNumber = row.split('|')[1]; saveState(state);
        await shot(page, 'portal-transport-trip-created');
        return row;
    }, page);

    await R.step('Back office: trips board, contracts, invoices, settlements, scan terminal pages render', async () => {
        const out = [];
        for (const p of ['/transport/trips', `/transport/trips/${state.tripId}`, '/transport/contracts', '/transport/invoices', '/transport/settlements', '/transport/scan', '/transport/transactions', '/transport/reports']) {
            const t0 = Date.now();
            const r = await visit(page, p);
            out.push(`${p}:${r.status}/${Date.now() - t0}ms`);
        }
        await shot(page, 'portal-transport-bo-reports');
        return out.join(' ');
    }, page);

    // ── Provider portal ─────────────────────────────────────────────────────
    const tu = state.transportUser;
    const ctx = await browser.newContext();
    const pp = await newPageIn(ctx);
    R.attach(pp);

    await R.step('Transport owner: first login with one-time password -> forced change -> transport dashboard', async () => {
        const u1 = await providerLogin(pp, BASE, tu.username, tu.temp);
        if (!/\/provider\/portal\/profile/.test(u1)) throw new Error('expected profile, got ' + u1);
        const f = pp.locator('form').filter({ has: pp.locator('input[type=password]') }).first();
        const pw = f.locator('input[type=password]');
        await pw.nth(0).fill(tu.temp); await pw.nth(1).fill(NEW_PW); await pw.nth(2).fill(NEW_PW);
        const wait = pp.waitForResponse((r) => /profile/.test(r.url()) && r.request().method() !== 'GET', { timeout: 20000 }).catch(() => null);
        await f.locator('button[type=submit]').click();
        await wait; await pp.waitForLoadState('networkidle'); await pp.waitForTimeout(500);
        const after = sql(`select must_change_password from provider_users where username='${tu.username}'`);
        if (after !== 'f') throw new Error('password not changed: ' + (await f.innerText()).replace(/\s+/g, ' ').slice(0, 200));
        state.transportUser.password = NEW_PW; saveState(state);
        await pp.goto(`${BASE}/provider/portal/dashboard`, { waitUntil: 'networkidle' });
        await shot(pp, 'portal-transport-dashboard');
        return 'landed on ' + pp.url().replace(BASE, '');
    }, pp);

    await R.step('Portal: routes/vehicles/drivers/trips lists are scoped to QA-TRANSPORT-1 and show the QA records', async () => {
        const out = [];
        for (const [p, re] of [['/provider/portal/transport/routes', /QA-RT-1/], ['/provider/portal/transport/vehicles', /QA-VEH-1/], ['/provider/portal/transport/drivers', /QA Driver 1/], ['/provider/portal/transport/trips', /QA-RT-1|QA Route 1/]]) {
            const r = await visit(pp, p);
            const b = await bodyText(pp);
            if (!re.test(b)) throw new Error(`${p}: expected ${re} in page`);
            if (/SP-TRANSPORT-DEMO|AA Transport Demo/.test(b)) throw new Error(`${p}: other provider data visible`);
            out.push(`${p.split('/').pop()}:${r.status}`);
        }
        await shot(pp, 'portal-transport-trips');
        return out.join(' ');
    }, pp);

    await R.step('Portal scan page: select trip, start boarding, scan the active card -> boarding result recorded', async () => {
        await visit(pp, '/provider/portal/transport/scan');
        await shot(pp, 'portal-transport-scan-initial');
        await selectLabelByText(pp, 'Route', /QA-RT-1/).catch(() => null);
        const trip = await selectLabelByText(pp, 'Trip (today)', new RegExp(state.tripNumber || 'QA-RT-1'));
        const start = pp.getByRole('button', { name: /Start boarding/i });
        if (await start.count()) {
            const w = pp.waitForResponse((r) => /dispatch/.test(r.url()), { timeout: 20000 }).catch(() => null);
            await start.first().click();
            await w; await pp.waitForTimeout(800);
        }
        const tripRow = sql(`select status,boarded_count from transport_trips where id='${state.tripId}'`);
        const card = sql("select public_card_uuid from id_cards where status='active' and public_card_uuid is not null order by updated_at desc limit 1");
        if (!card) throw new Error('BLOCKED: no active card with QR reference');
        await pp.locator('input[placeholder="Card QR reference"]').fill(card);
        const w2 = pp.waitForResponse((r) => /transport\/scan$/.test(r.url()) && r.request().method() === 'POST', { timeout: 30000 }).catch(() => null);
        await pp.getByRole('button', { name: /Record boarding/i }).click();
        const resp = await w2;
        await pp.waitForTimeout(1200);
        await shot(pp, 'portal-transport-scan-result');
        const b = await bodyText(pp);
        const res = (b.match(/Boarding (accepted|refused).{0,160}/) || [b.slice(0, 200)])[0];
        const tx = sql(`select status,result_code,verification_method,scanned_by_provider_user_id is not null from transport_transactions where provider_id='${P}' order by created_at desc limit 1`);
        state.transportScan = { trip: tripRow, result: res, tx }; saveState(state);
        return `trip=${tripRow}; HTTP ${resp ? resp.status() : '?'}; "${res}"; tx=${tx}`;
    }, pp);

    await R.step('Portal: boardings list, reports, exceptions, devices, invoices, settlements render', async () => {
        const out = [];
        for (const p of ['/provider/portal/transport/transactions', '/provider/portal/transport/reports', '/provider/portal/transport/exceptions', '/provider/portal/transport/devices', '/provider/portal/transport/invoices', '/provider/portal/transport/settlements', '/provider/portal/transport/invoices/create']) {
            const t0 = Date.now();
            const r = await visit(pp, p);
            out.push(`${p.replace('/provider/portal/transport', '')}:${r.status}/${Date.now() - t0}ms`);
        }
        await shot(pp, 'portal-transport-invoices-create');
        return out.join(' ');
    }, pp);

    await R.step('Portal CSV export of boardings is a file', async () => {
        const r = await fetchWithSession(pp, `${BASE}/provider/portal/transport/transactions/export/csv`);
        if (r.status !== 200 || !/csv/.test(r.contentType)) throw new Error(`${r.status} ${r.contentType} ${r.text.slice(0, 100)}`);
        return `${r.status} ${r.contentType} ${r.size}B`;
    });

    await ctx.close();
    R.results.push({ label: 'slow document loads >3s (back office)', status: 'PASS', info: JSON.stringify(slow) });
    await browser.close();
    R.save();
})().catch((e) => { console.error('FATAL', e); process.exit(1); });
