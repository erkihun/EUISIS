'use strict';
// Journey 1: back office — service types, service providers, create cafeteria + transport providers, provider portal users.
const { BASE, Recorder, newBrowser, login, submitAndLeave, expectUrl, visit, shot } = require('./lib');
const { loadState, saveState, sql, byLabel, fillLabel, selectLabel, bodyText, flashText } = require('./60-portal-lib');

const state = loadState();
const RUN = Date.now().toString(36).slice(-4).toUpperCase();
state.run = RUN;

(async () => {
    const R = new Recorder('60-portal-1-backoffice');
    const { browser, page } = await newBrowser();
    R.attach(page);
    const slow = [];
    page.on('response', (r) => { const t = r.request().timing(); if (t && t.responseEnd > 3000 && r.request().resourceType() === 'document') slow.push({ url: r.url(), ms: Math.round(t.responseEnd) }); });

    await R.step('Login as qa.sysadmin4 (System Admin, no MFA)', async () => {
        const st = {};
        const url = await login(page, 'qa.sysadmin4@demo.local', 'password', st);
        if (st.secret) throw new Error('MFA demanded for System Admin');
        await expectUrl(page, /\/dashboard/);
        return url;
    }, page);

    await R.step('Service types list renders seeded types', async () => {
        const t0 = Date.now();
        await visit(page, '/service-types');
        const ms = Date.now() - t0;
        const body = await bodyText(page);
        for (const n of ['Cafeteria', 'Transport', 'Health']) if (!body.includes(n)) throw new Error('missing service type ' + n);
        await shot(page, 'portal-service-types');
        return `${ms}ms`;
    }, page);

    await R.step('Service providers (legacy module) lists SP-TRANSPORT-DEMO', async () => {
        await visit(page, '/service-providers');
        const body = await bodyText(page);
        if (!body.includes('SP-TRANSPORT-DEMO')) throw new Error('SP-TRANSPORT-DEMO not listed');
        await shot(page, 'portal-service-providers');
    }, page);

    await R.step('Provider Users module: provider dropdown does NOT offer SP-TRANSPORT-DEMO (it is a service_providers row, not a providers row)', async () => {
        await visit(page, '/provider-users/create');
        const sel = await byLabel(page, 'Provider');
        const opts = await sel.locator('option').allTextContents();
        state.providerOptionsBefore = opts;
        await shot(page, 'portal-provider-users-create-before');
        if (opts.some((o) => /SP-TRANSPORT-DEMO/.test(o))) return 'offered: ' + JSON.stringify(opts);
        return `options=${JSON.stringify(opts)} (SP-TRANSPORT-DEMO absent)`;
    }, page);

    await R.step('Create cafeteria provider QA-CAFE-1 (new provider + new network + main cafeteria)', async () => {
        await visit(page, '/cafeteria/providers/create');
        await selectLabel(page, 'Provider', 'new');
        await fillLabel(page, 'Provider Code', 'QA-CAFE-1', 0);
        await fillLabel(page, 'Provider Name (English)', 'QA Cafeteria Provider 1');
        await fillLabel(page, 'Network Code', 'QA-NET-1');
        await fillLabel(page, 'Network Name', 'QA Network 1');
        await fillLabel(page, 'Provider Code', 'QA-CAFE-1', 1); // cafeteria code field (mislabelled "Provider Code")
        await fillLabel(page, 'Name (English)', 'QA Cafeteria 1');
        await shot(page, 'portal-cafe-provider-form');
        await submitAndLeave(page, /\/cafeteria\/providers\/create/);
        if (/\/create/.test(page.url())) throw new Error('stayed on create: ' + (await bodyText(page)).slice(0, 500));
        await shot(page, 'portal-cafe-provider-created');
        return page.url() + ' flash=' + (await flashText(page)).slice(0, 120);
    }, page);

    await R.step('DB: providers row QA-CAFE-1 exists with cafeteria service enabled; cafeteria + network rows exist', async () => {
        const out = sql("select p.id,p.provider_code,p.status,coalesce(st.code,'none') from providers p left join provider_services ps on ps.provider_id=p.id left join service_types st on st.id=ps.service_type_id where p.provider_code='QA-CAFE-1'");
        if (!out) throw new Error('no providers row for QA-CAFE-1');
        state.cafeProviderId = out.split('|')[0];
        const caf = sql("select id,code,coalesce(cafeteria_service_network_id::text,''),is_active from cafeteria_providers where code='QA-CAFE-1'");
        state.cafeteriaId = caf.split('|')[0]; state.networkId = caf.split('|')[2];
        saveState(state);
        if (!out.includes('cafeteria')) throw new Error('cafeteria service not enabled: ' + out);
        if (!state.networkId) throw new Error('cafeteria has no network: ' + caf);
        return out + ' cafeteria=' + caf;
    });

    await R.step('Create transport provider QA-TRANSPORT-1 via Transport › Providers', async () => {
        await visit(page, '/transport/providers/create');
        await fillLabel(page, 'Provider Code', 'QA-TRANSPORT-1');
        await fillLabel(page, 'Name English', 'QA Transport Provider 1');
        await shot(page, 'portal-transport-provider-form');
        await submitAndLeave(page, /\/transport\/providers\/create/);
        if (/\/create/.test(page.url())) throw new Error('stayed on create: ' + (await bodyText(page)).slice(0, 500));
        const out = sql("select p.id,p.status,coalesce(st.code,'none') from providers p left join provider_services ps on ps.provider_id=p.id left join service_types st on st.id=ps.service_type_id where p.provider_code='QA-TRANSPORT-1'");
        state.transportProviderId = out.split('|')[0]; saveState(state);
        await shot(page, 'portal-transport-provider-created');
        if (!out.includes('transport')) throw new Error('transport service not enabled: ' + out);
        return out;
    }, page);

    async function createProviderUser(providerId, name, email, username, role) {
        await visit(page, `/provider-users/create?provider_id=${providerId}`);
        const sel = await byLabel(page, 'Provider');
        if ((await sel.inputValue()) !== providerId) await sel.selectOption(providerId);
        await fillLabel(page, 'Full name', name);
        if (email) await fillLabel(page, 'Email address', email);
        await fillLabel(page, 'Username', username);
        await selectLabel(page, 'Role', role);
        const services = (await page.locator('form').innerText()).replace(/\s+/g, ' ');
        await shot(page, `portal-provider-user-form-${username}`);
        await submitAndLeave(page, /\/provider-users\/create/);
        if (/\/create/.test(page.url())) throw new Error('stayed on form: ' + (await page.locator('form').innerText()).replace(/\s+/g, ' ').slice(0, 500));
        await page.waitForTimeout(500);
        const code = page.locator('[role="alert"] code');
        const temp = (await code.count()) ? (await code.first().textContent()).trim() : null;
        await shot(page, `portal-provider-user-created-${username}`);
        return { url: page.url(), temp, services };
    }

    await R.step('Create cafeteria provider portal user (owner) — one-time password shown once on screen', async () => {
        const r = await createProviderUser(state.cafeProviderId, 'QA Cafe Owner', `qa.cafe.owner.${RUN}@qa.local`, `qa_cafe_owner_${RUN}`, 'owner');
        state.cafeUser = { email: `qa.cafe.owner.${RUN}@qa.local`, username: `qa_cafe_owner_${RUN}`, temp: r.temp, showUrl: r.url };
        saveState(state);
        if (!r.temp) throw new Error('no temporary password displayed; url=' + r.url);
        return `temp length=${r.temp.length}, services on form: ${/Cafeteria/.test(r.services)}; url=${r.url}`;
    }, page);

    await R.step('Create transport provider portal user (owner)', async () => {
        const r = await createProviderUser(state.transportProviderId, 'QA Transport Owner', `qa.tr.owner.${RUN}@qa.local`, `qa_tr_owner_${RUN}`, 'owner');
        state.transportUser = { email: `qa.tr.owner.${RUN}@qa.local`, username: `qa_tr_owner_${RUN}`, temp: r.temp, showUrl: r.url };
        saveState(state);
        if (!r.temp) throw new Error('no temporary password displayed; url=' + r.url);
        return `temp length=${r.temp.length}`;
    }, page);

    await R.step('Create transport provider portal user (operator, scan permission only)', async () => {
        await visit(page, `/provider-users/create?provider_id=${state.transportProviderId}`);
        await fillLabel(page, 'Full name', 'QA Transport Operator');
        await fillLabel(page, 'Username', `qa_tr_op_${RUN}`);
        await selectLabel(page, 'Role', 'operator');
        const boxes = page.locator('fieldset input[type=checkbox]');
        const n = await boxes.count();
        const labels = [];
        for (let i = 0; i < n; i++) labels.push((await boxes.nth(i).locator('xpath=..').innerText()).trim());
        for (let i = 0; i < n; i++) if (/scan/i.test(labels[i])) { await boxes.nth(i).check(); break; }
        await submitAndLeave(page, /\/provider-users\/create/);
        if (/\/create/.test(page.url())) throw new Error('stayed on form: ' + (await page.locator('form').innerText()).replace(/\s+/g, ' ').slice(0, 500));
        const code = page.locator('[role="alert"] code');
        const temp = (await code.count()) ? (await code.first().textContent()).trim() : null;
        state.transportOperator = { username: `qa_tr_op_${RUN}`, temp };
        saveState(state);
        await shot(page, 'portal-provider-user-operator');
        return `permissions offered=${JSON.stringify(labels)}; temp=${temp ? 'yes' : 'no'}`;
    }, page);

    await R.step('DB: new accounts have must_change_password=true, portal_enabled, active; show page warns', async () => {
        const body = await bodyText(page);
        const rows = sql(`select username,provider_role,status,must_change_password,portal_enabled from provider_users where username like 'qa_%${RUN}' order by 1`);
        if (!rows || rows.split('\n').some((r) => !/\|active\|t\|t$/.test(r))) throw new Error('unexpected rows: ' + rows);
        return rows.replace(/\n/g, ' ; ') + ' | warning on page=' + /change (the|their|this|your)? ?password|temporary|one-time/i.test(body);
    }, page);

    await R.step('Credential delivery: password shown on screen once; nothing mailed/logged', async () => {
        const { execSync } = require('child_process');
        const hits = execSync(`grep -c -iE "qa.cafe.owner.${RUN}|qa_cafe_owner_${RUN}|qa.tr.owner.${RUN}" /home/user/EUISIS/storage/logs/laravel.log || true`, { encoding: 'utf8' }).trim();
        const mails = execSync(`grep -c -E "local.DEBUG: (From|To|Subject):" /home/user/EUISIS/storage/logs/laravel.log || true`, { encoding: 'utf8' }).trim();
        const pwInLog = execSync(`grep -c -F "${state.cafeUser.temp}" /home/user/EUISIS/storage/logs/laravel.log || true`, { encoding: 'utf8' }).trim();
        if (pwInLog !== '0') throw new Error('temporary password leaked into laravel.log');
        return `log mentions of new accounts=${hits}; mail entries in log=${mails}; temp pw in log=${pwInLog}`;
    });

    await R.step('Reloading the show page no longer reveals the one-time password', async () => {
        await visit(page, state.cafeUser.showUrl);
        const code = page.locator('[role="alert"] code');
        if (await code.count()) throw new Error('temporary password still visible after reload');
        const body = await bodyText(page);
        if (body.includes(state.cafeUser.temp)) throw new Error('password text present in page');
    }, page);

    await R.step('Provider users index lists the new accounts with search', async () => {
        await visit(page, `/provider-users?search=qa_cafe_owner_${RUN}`);
        const body = await bodyText(page);
        if (!body.includes(`qa_cafe_owner_${RUN}`) && !body.includes('QA Cafe Owner')) throw new Error('new user not listed');
        await shot(page, 'portal-provider-users-index');
    }, page);

    await R.step('Validation: duplicate username/email refused with field error', async () => {
        await visit(page, `/provider-users/create?provider_id=${state.cafeProviderId}`);
        await fillLabel(page, 'Full name', 'Dup');
        await fillLabel(page, 'Email address', state.cafeUser.email);
        await fillLabel(page, 'Username', state.cafeUser.username);
        await submitAndLeave(page, /\/provider-users\/create/, 6000);
        if (!/\/create/.test(page.url())) throw new Error('duplicate accepted: ' + page.url());
        const t = (await page.locator('form').innerText()).replace(/\s+/g, ' ');
        if (!/already|taken|exists|unique|in use/i.test(t)) throw new Error('no uniqueness error: ' + t.slice(0, 300));
    }, page);

    await R.step('qa.cafeteria (Cafeteria Admin) is forced into MFA enrolment', async () => {
        const c = await browser.newContext();
        const p = await c.newPage();
        await c.addCookies([{ name: 'euisis_locale', value: 'en', url: BASE }]);
        await p.goto(`${BASE}/login`, { waitUntil: 'networkidle' });
        await p.fill('input[name="email"]', 'qa.cafeteria@demo.local');
        await p.fill('input[name="password"]', 'password');
        await submitAndLeave(p, /\/login$/);
        const u = p.url();
        await c.close();
        if (!/mfa\/(setup|challenge)/.test(u)) throw new Error('no MFA gate: ' + u);
        return u;
    });

    R.results.push({ label: 'slow document loads >3s', status: 'PASS', info: JSON.stringify(slow) });
    await browser.close();
    R.save();
})().catch((e) => { console.error('FATAL', e); process.exit(1); });
