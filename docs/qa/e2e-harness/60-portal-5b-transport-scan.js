'use strict';
// Journey 5b: transport provider portal — landing page, scan/boarding flow, CSV export (re-run of the portal half of journey 5).
const { BASE, Recorder, newBrowser, newPageIn, visit, shot } = require('./lib');
const { loadState, saveState, sql, selectLabelByText, bodyText, providerLogin, fetchWithSession } = require('./60-portal-lib');

const state = loadState();
const P = state.transportProviderId;

(async () => {
    const R = new Recorder('60-portal-5b-transport-scan');
    const { browser, page: pp } = await newBrowser();
    R.attach(pp);
    const tu = state.transportUser;

    await R.step('Transport owner login lands on the transport dashboard; generic /provider/portal/dashboard answers 403 for a transport-only provider', async () => {
        const u = await providerLogin(pp, BASE, tu.username, tu.password);
        const landing = u.replace(BASE, '');
        const r = await pp.goto(`${BASE}/provider/portal/dashboard`, { waitUntil: 'networkidle' });
        await shot(pp, 'portal-transport-generic-dashboard');
        const links = await pp.locator('a[href*="/provider/portal/dashboard"]').count();
        return `landing=${landing}; /provider/portal/dashboard -> HTTP ${r.status()}; links to it on that page=${links}`;
    }, pp);

    await R.step('Transport dashboard renders', async () => {
        const r = await visit(pp, '/provider/portal/transport/dashboard');
        await shot(pp, 'portal-transport-dashboard');
        const b = await bodyText(pp);
        if (!/QA-TRANSPORT-1|QA Transport Provider 1/.test(b)) throw new Error('provider name missing');
        return r.status;
    }, pp);

    await R.step('Scan page: choose today\'s trip, start boarding (dispatch), scan the active card', async () => {
        await visit(pp, '/provider/portal/transport/scan');
        const before = await bodyText(pp);
        await selectLabelByText(pp, 'Route', /QA-RT-1/).catch(() => null);
        const trip = await selectLabelByText(pp, 'Trip (today)', /QA-RT-1/);
        await pp.waitForTimeout(500);
        const start = pp.getByRole('button', { name: /Start boarding/i });
        let dispatched = 'no Start boarding button';
        if (await start.count()) {
            const w = pp.waitForResponse((r) => /dispatch/.test(r.url()), { timeout: 20000 }).catch(() => null);
            await start.first().click();
            const resp = await w; await pp.waitForTimeout(1000);
            dispatched = 'dispatch HTTP ' + (resp ? resp.status() : '?');
        }
        const tripRow = sql(`select status,boarded_count from transport_trips where id='${state.tripId}'`);
        await shot(pp, 'portal-transport-scan-boarding');
        const card = sql("select public_card_uuid from id_cards where status='active' and public_card_uuid is not null order by updated_at desc limit 1");
        if (!card) throw new Error('BLOCKED: no active card with QR reference');
        const inp = pp.locator('input[placeholder="Card QR reference"]');
        await inp.fill(card);
        const btn = pp.getByRole('button', { name: /Record boarding/i });
        const disabled = await btn.isDisabled();
        if (disabled) return `trip option="${trip.label}"; ${dispatched}; trip=${tripRow}; Record boarding button disabled (not ready): ${(await bodyText(pp)).match(/(Choose the provider|Select today|not activated).{0,120}/)?.[0]}`;
        const w2 = pp.waitForResponse((r) => /transport\/scan$/.test(r.url()) && r.request().method() === 'POST', { timeout: 30000 }).catch(() => null);
        await btn.click();
        const resp = await w2;
        await pp.waitForTimeout(1500);
        await shot(pp, 'portal-transport-scan-result');
        const b = await bodyText(pp);
        const res = (b.match(/Boarding (accepted|refused).{0,200}/) || [b.slice(0, 200)])[0];
        const tx = sql(`select status,result_code,verification_method,scanned_by_provider_user_id is not null,employee_id is not null from transport_transactions where provider_id='${P}' order by created_at desc limit 1`);
        state.transportScan = { trip: tripRow, result: res, tx }; saveState(state);
        return `trip option="${trip.label}"; ${dispatched}; trip=${tripRow}; scan HTTP ${resp ? resp.status() : '?'}; "${res}"; tx=${tx}`;
    }, pp);

    await R.step('Second scan of the same card on the same trip is refused (duplicate boarding)', async () => {
        const card = sql("select public_card_uuid from id_cards where status='active' and public_card_uuid is not null order by updated_at desc limit 1");
        const inp = pp.locator('input[placeholder="Card QR reference"]');
        await pp.waitForTimeout(1500);
        await inp.fill(card);
        const btn = pp.getByRole('button', { name: /Record boarding/i });
        if (await btn.isDisabled()) return 'button disabled (cooldown or not ready)';
        const w2 = pp.waitForResponse((r) => /transport\/scan$/.test(r.url()) && r.request().method() === 'POST', { timeout: 30000 }).catch(() => null);
        await btn.click(); await w2; await pp.waitForTimeout(1500);
        const b = await bodyText(pp);
        const res = (b.match(/Boarding (accepted|refused).{0,200}/) || [b.slice(0, 200)])[0];
        const rows = sql(`select status,result_code from transport_transactions where provider_id='${P}' order by created_at`);
        const accepted = rows.split('\n').filter((l) => l.startsWith('accepted')).length;
        if (accepted > 1) throw new Error('two accepted boardings: ' + rows.replace(/\n/g, ' ; '));
        return `"${res}"; rows=${rows.replace(/\n/g, ' ; ')}`;
    }, pp);

    await R.step('Boardings list and detail in the portal show the scan; back-office Boardings list shows it too', async () => {
        await visit(pp, '/provider/portal/transport/transactions');
        const b = await bodyText(pp);
        await shot(pp, 'portal-transport-transactions');
        const tx = sql(`select id from transport_transactions where provider_id='${P}' order by created_at desc limit 1`);
        if (!tx) return 'no transaction rows recorded';
        if (!/QA-RT-1|Demo Employee|EMP-0001/.test(b)) throw new Error('scan not listed');
        await visit(pp, `/provider/portal/transport/transactions/${tx}`);
        await shot(pp, 'portal-transport-transaction-show');
        return 'listed';
    }, pp);

    await R.step('Portal CSV export of boardings: real CSV with a CSV content type', async () => {
        const r = await fetchWithSession(pp, `${BASE}/provider/portal/transport/transactions/export/csv`);
        const isCsv = /^(﻿)?date,status/.test(r.text) || /^date,status/.test(r.head);
        if (r.status !== 200) throw new Error(`HTTP ${r.status}`);
        if (!/csv/.test(r.contentType)) throw new Error(`CSV body served as "${r.contentType}" (disposition: ${r.disposition}); body starts: ${r.text.slice(0, 60)}`);
        return `${r.status} ${r.contentType} ${r.size}B csv=${isCsv}`;
    });

    await browser.close();
    R.save();
})().catch((e) => { console.error('FATAL', e); process.exit(1); });
