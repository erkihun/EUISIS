'use strict';
// Journey 7: API management — sync endpoint catalog, create application with one scope + one endpoint,
// issue token (shown once), call in-scope endpoint (200) and out-of-scope endpoint (403) with curl.
const { execFileSync } = require('child_process');
const { BASE, Recorder, newBrowser, login, visit, shot } = require('./lib');
const { sql, body, confirmDialog } = require('./40-admin-lib');

const ADMIN = 'qa.sysadmin5@demo.local';
const CODE = 'QA-INT';

function curl(url, token, extra = []) {
    const out = execFileSync('curl', ['-s', '-w', '\n__STATUS__%{http_code}', '-H', 'Accept: application/json', '-H', `Authorization: Bearer ${token}`, ...extra, `${BASE}${url}`], { encoding: 'utf8', env: { ...process.env, HTTPS_PROXY: '', HTTP_PROXY: '', http_proxy: '', https_proxy: '', NO_PROXY: '*' } });
    const m = out.match(/__STATUS__(\d+)$/);
    return { status: m ? Number(m[1]) : 0, body: out.replace(/\n__STATUS__\d+$/, '').slice(0, 300) };
}

(async () => {
    const R = new Recorder('40-admin-api');
    const { browser, page } = await newBrowser();
    R.attach(page);
    const st = {};

    // repeatability
    const left = sql(`select id from external_applications where code='${CODE}'`);
    if (left.length) sql(`delete from personal_access_tokens where tokenable_id::text='${left[0][0]}'; delete from api_request_logs where external_application_id='${left[0][0]}'; delete from external_application_endpoints where external_application_id='${left[0][0]}'; delete from external_applications where id='${left[0][0]}';`);

    await R.step('System Admin logs in; API management index renders', async () => {
        await login(page, ADMIN, 'password');
        await visit(page, '/system-settings/api-management');
        await shot(page, '40-api-index');
    }, page);

    await R.step('Endpoint catalog: sync from routes → definitions created', async () => {
        await visit(page, '/system-settings/api-management/endpoints');
        const before = sql(`select count(*) from api_endpoint_definitions`)[0][0];
        const btn = page.getByRole('button', { name: /sync/i }).first();
        if (await btn.count()) {
            const resp = page.waitForResponse((r) => /endpoints\/sync/.test(r.url()), { timeout: 30000 }).catch(() => null);
            await btn.click();
            await resp;
            await page.waitForLoadState('networkidle');
        }
        const after = sql(`select count(*) from api_endpoint_definitions`)[0][0];
        const dir = sql(`select id, required_scope, status from api_endpoint_definitions where uri like '%organizations/directory%' limit 1`)[0];
        await shot(page, '40-api-endpoints');
        if (!dir) throw new Error(`directory endpoint not in catalog (before=${before} after=${after})`);
        st.endpointId = dir[0];
        return `definitions ${before}→${after}; GET organizations/directory scope=${dir[1]} status=${dir[2]}`;
    }, page);

    await R.step('Create application QA-INT with scope organizations.read + endpoint GET /api/v1/organizations/directory, city-wide access', async () => {
        await visit(page, '/system-settings/api-management');
        await page.waitForTimeout(1000);
        const nb = await page.getByRole('button', { name: /new\s+application/i }).count();
        await shot(page, '40-api-before-new');
        const texts = await page.evaluate(() => [...document.querySelectorAll('button')].map((b) => JSON.stringify(b.textContent)).filter((t) => /appl/i.test(t)));
        if (nb === 0 || !texts.length) throw new Error('no New Application button; nb=' + nb + ' texts=' + texts.join(',') + ' body: ' + (await body(page)).slice(0, 300));
        console.log('     button texts:', texts.join(','));
        let clicked = 'none';
        for (let i = 0; i < 5 && clicked !== 'clicked'; i++) {
            clicked = await page.evaluate(() => { const b = [...document.querySelectorAll('button')].find((x) => /new\s+application/i.test(x.textContent || '')); if (!b) return 'none:' + document.querySelectorAll('button').length; b.click(); return 'clicked'; });
            console.log('     click attempt', i, clicked);
            if (clicked !== 'clicked') await page.waitForTimeout(1000);
        }
        await page.getByLabel(/^name/i).first().waitFor({ state: 'visible', timeout: 10000 });
        await page.getByLabel(/^name/i).first().fill('QA Integration');
        await page.getByLabel(/^code/i).first().fill(CODE);
        await page.locator('label').filter({ hasText: /organizations\.read|Organizations.*read/i }).first().locator('input[type=checkbox]').check();
        // endpoint assignment: checkbox in a row mentioning organizations/directory
        const ep = page.locator('label, tr, li').filter({ hasText: /organizations\/directory/ }).first().locator('input[type=checkbox]');
        if (await ep.count()) await ep.check();
        const access = page.getByLabel(/organization access/i).first();
        if (await access.count()) await access.selectOption('city').catch(() => {});
        const storeResp = page.waitForResponse((r) => r.request().method() === 'POST' && /api-management$/.test(r.url()), { timeout: 60000 }).catch(() => null);
        const saveTxt = await page.evaluate(() => { const forms = [...document.querySelectorAll('form')]; const f = forms.find((x) => x.querySelector('input[type=checkbox]')) || forms[forms.length - 1]; const b = f.querySelector('button[type=submit]'); if (!b) return 'no submit'; const t = b.textContent; b.click(); return t; });
        console.log('     submit button:', saveTxt);
        const sr = await storeResp;
        console.log('     store POST →', sr ? sr.status() : 'no response');
        await page.waitForURL(/api-management\/[0-9a-f-]{36}/, { timeout: 30000 }).catch(() => {});
        await page.waitForLoadState('networkidle');
        await page.waitForTimeout(500);
        const row = sql(`select id, status, allowed_scopes, organization_access from external_applications where code='${CODE}'`)[0];
        await shot(page, '40-api-created');
        if (!row) {
            const errs = await page.locator('[role="alert"]').allInnerTexts();
            throw new Error('application not created: ' + errs.join(' | ').slice(0, 300));
        }
        st.appId = row[0];
        const eps = sql(`select count(*) from external_application_endpoints where external_application_id='${row[0]}'`)[0][0];
        return `id=${row[0]} status=${row[1]} scopes=${row[2]} access=${row[3]} endpoints=${eps} url=${page.url().replace(BASE, '')}`;
    }, page);

    await R.step('Generate token: plaintext shown exactly once; only a hash is stored; gone after reload', async () => {
        await visit(page, `/system-settings/api-management/${st.appId}`);
        await page.evaluate(() => { const b = [...document.querySelectorAll('button')].find((x) => /generate\s+token/i.test(x.textContent)); b.click(); });
        await page.waitForLoadState('networkidle');
        await page.waitForTimeout(800);
        const code = page.locator('code.select-all, code').filter({ hasText: /\|/ }).first();
        const token = (await code.innerText()).trim();
        if (!/^\d+\|[A-Za-z0-9_]{20,}$/.test(token)) throw new Error('no token revealed: ' + token.slice(0, 40));
        st.token = token;
        await shot(page, '40-api-token');
        const dbTok = sql(`select token, abilities from personal_access_tokens where tokenable_id::text='${st.appId}' order by id desc limit 1`)[0];
        const plain = token.split('|')[1];
        if (dbTok[0] === plain || dbTok[0].includes(plain)) throw new Error('plaintext token stored in DB');
        await page.reload({ waitUntil: 'networkidle' });
        const t = await body(page);
        const again = t.includes(plain);
        if (again) throw new Error('token still visible after reload');
        return `token prefix=${token.slice(0, 6)}… abilities=${dbTok[1]} stored_hash_len=${dbTok[0].length} visible_after_reload=${again}`;
    }, page);

    await R.step('curl in-scope GET /api/v1/organizations/directory → 200 with data', async () => {
        const r = curl('/api/v1/organizations/directory', st.token);
        if (r.status !== 200) throw new Error(`HTTP ${r.status} ${r.body}`);
        return `HTTP ${r.status} body=${r.body.slice(0, 120)}`;
    });

    await R.step('curl out-of-scope GET /api/v1/positions (positions.read, not granted) → 403', async () => {
        const r = curl('/api/v1/positions', st.token);
        const r2 = curl('/api/v1/employees', st.token);
        const r3 = curl('/api/v1/organizations/directory', 'invalid-token');
        if (r.status !== 403 || r2.status !== 403) throw new Error(`positions=${r.status} ${r.body} employees=${r2.status}`);
        if (r3.status !== 401) throw new Error('bad token → ' + r3.status);
        const logs = sql(`select endpoint, status_code, coalesce(failure_reason,'') from api_request_logs where external_application_id='${st.appId}' order by requested_at`);
        return `positions=${r.status} (${r.body.slice(0, 80)}) employees=${r2.status} bad-token=${r3.status}; request log: ${logs.map((l) => l.join(':')).join(' ; ')}`;
    });

    await R.step('API logs page shows the calls; revoke token → 401 afterwards', async () => {
        await visit(page, '/system-settings/api-management/logs');
        const t = await body(page);
        const shown = /organizations\/directory/.test(t);
        await visit(page, `/system-settings/api-management/${st.appId}`);
        const row = page.locator('tr').filter({ hasText: CODE }).last();
        await row.locator('button[aria-label], button[aria-haspopup]').last().click();
        await page.locator('[role="menuitem"]').filter({ hasText: /revoke/i }).first().click();
        await confirmDialog(page, /tokens\/\d+$/).catch(() => {});
        await page.waitForTimeout(500);
        const r = curl('/api/v1/organizations/directory', st.token);
        const n = sql(`select count(*) from personal_access_tokens where tokenable_id::text='${st.appId}'`)[0][0];
        if (r.status !== 401) throw new Error(`revoked token still works: ${r.status} (tokens left ${n})`);
        return `logs page shows call=${shown}; after revoke → ${r.status}; tokens left=${n}`;
    }, page);

    await R.step('Cleanup: delete QA-INT application', async () => {
        await visit(page, `/system-settings/api-management/${st.appId}`);
        const delTxt = await page.evaluate(() => { const b = [...document.querySelectorAll('button')].find((x) => /delete/i.test(x.textContent || '') && !/token/i.test(x.textContent || '')); if (!b) return 'none'; b.click(); return b.textContent; });
        console.log('     delete button:', delTxt);
        await confirmDialog(page, /api-management\/[0-9a-f-]+$/);
        const n = sql(`select count(*) from external_applications where code='${CODE}'`)[0][0];
        if (n !== '0') { sql(`delete from personal_access_tokens where tokenable_id::text='${st.appId}'; delete from api_request_logs where external_application_id='${st.appId}'; delete from external_application_endpoints where external_application_id='${st.appId}'; delete from external_applications where id='${st.appId}';`); return 'UI delete did not remove; cleaned via SQL'; }
        return 'deleted via UI';
    }, page);

    await browser.close();
    R.save();
})().catch((e) => { console.error('FATAL', e); process.exit(1); });
