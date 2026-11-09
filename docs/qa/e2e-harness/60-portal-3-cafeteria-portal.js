'use strict';
// Journey 3: cafeteria provider portal — first login with one-time password, forced change, scan, duplicate refusal, lists.
const { BASE, Recorder, newBrowser, newPageIn, login, visit, shot } = require('./lib');
const { loadState, saveState, sql, bodyText, providerLogin, fetchWithSession } = require('./60-portal-lib');

const state = loadState();
const NEW_PW = 'Mountain-River-7731!zq';

(async () => {
    const R = new Recorder('60-portal-3-cafeteria-portal');
    const { browser, page } = await newBrowser();
    R.attach(page);
    const u = state.cafeUser;

    await R.step('Legacy /cafeteria/portal/login and /cafe redirect to /provider/portal/login', async () => {
        const r1 = await page.goto(`${BASE}/cafeteria/portal/login`, { waitUntil: 'networkidle' });
        const u1 = page.url();
        await page.goto(`${BASE}/cafe`, { waitUntil: 'networkidle' });
        const u2 = page.url();
        if (!/\/provider\/portal\/login/.test(u1) || !/\/provider\/portal\/login/.test(u2)) throw new Error(`${u1} / ${u2}`);
        await shot(page, 'portal-login-page');
        return `${r1.status()} -> ${u1}`;
    }, page);

    await R.step('Wrong password refused with a generic message', async () => {
        const url = await providerLogin(page, BASE, u.username, 'definitely-wrong');
        if (!/\/provider\/portal\/login/.test(url)) throw new Error('logged in with wrong password: ' + url);
        const t = await bodyText(page);
        if (!/invalid|incorrect|do not match|failed|not match|credentials/i.test(t)) throw new Error('no error text: ' + t.slice(0, 200));
        return t.match(/.{0,60}(invalid|incorrect|do not match|failed|not match|credentials).{0,60}/i)[0];
    }, page);

    await R.step('Login with the one-time password (username) lands on profile with forced password change', async () => {
        if (!u.temp) throw new Error('no temporary password captured in journey 1');
        const url = await providerLogin(page, BASE, u.username, u.temp);
        await shot(page, 'portal-cafe-first-login');
        if (!/\/provider\/portal\/profile/.test(url)) throw new Error('expected profile redirect, got ' + url);
        const t = await bodyText(page);
        return url + ' | ' + (t.match(/.{0,80}(change|temporary|password).{0,80}/i) || [''])[0];
    }, page);

    await R.step('Scan page is unreachable until the password is changed', async () => {
        await page.goto(`${BASE}/provider/portal/scan`, { waitUntil: 'networkidle' });
        if (!/\/provider\/portal\/profile/.test(page.url())) throw new Error('scan reachable: ' + page.url());
    }, page);

    await R.step('Change password (weak one rejected, strong one accepted)', async () => {
        await page.goto(`${BASE}/provider/portal/profile`, { waitUntil: 'networkidle' });
        const forms = page.locator('form').filter({ has: page.locator('input[type=password]') });
        const f = forms.first();
        const pw = f.locator('input[type=password]');
        await pw.nth(0).fill(u.temp); await pw.nth(1).fill('short'); await pw.nth(2).fill('short');
        await f.locator('button[type=submit]').click();
        await page.waitForLoadState('networkidle'); await page.waitForTimeout(800);
        const weak = sql(`select must_change_password from provider_users where username='${u.username}'`);
        const weakText = (await f.innerText()).replace(/\s+/g, ' ');
        if (weak !== 't') throw new Error('weak password accepted');
        await pw.nth(0).fill(u.temp); await pw.nth(1).fill(NEW_PW); await pw.nth(2).fill(NEW_PW);
        const waitPatch = page.waitForResponse((r) => /profile/.test(r.url()) && r.request().method() !== 'GET', { timeout: 20000 }).catch(() => null);
        await f.locator('button[type=submit]').click();
        await waitPatch;
        await page.waitForLoadState('networkidle'); await page.waitForTimeout(800);
        const after = sql(`select must_change_password from provider_users where username='${u.username}'`);
        await shot(page, 'portal-cafe-password-changed');
        const errs = await page.locator('form p.text-red-600, form .text-red-600, form [role=alert]').allInnerTexts().catch(() => []);
        if (after !== 'f') throw new Error('must_change_password still set; errors: ' + JSON.stringify(errs).slice(0, 300));
        state.cafeUser.password = NEW_PW; saveState(state);
        return 'weak rejected (' + (weakText.match(/.{0,60}(at least|characters|minimum|strong).{0,40}/i) || ['no text'])[0] + '); strong accepted';
    }, page);

    await R.step('Old one-time password no longer works; new one does', async () => {
        const ctx = await browser.newContext();
        const p = await newPageIn(ctx);
        const a = await providerLogin(p, BASE, u.username, u.temp);
        const b = await providerLogin(p, BASE, u.email, NEW_PW);
        await ctx.close();
        if (!/login/.test(a)) throw new Error('old password accepted');
        if (/login/.test(b)) throw new Error('new password refused by email identifier');
        return `old -> ${a.replace(BASE, '')}; new(email) -> ${b.replace(BASE, '')}`;
    });

    await R.step('Portal dashboard and scan page render for the cafeteria provider', async () => {
        await visit(page, '/provider/portal/dashboard');
        await shot(page, 'portal-cafe-dashboard');
        const t0 = Date.now();
        await visit(page, '/provider/portal/scan');
        const ms = Date.now() - t0;
        await page.locator('input[placeholder*="|token"]').waitFor({ state: 'visible' });
        await shot(page, 'portal-cafe-scan-page');
        return `scan page ${ms}ms`;
    }, page);

    const card = sql("select id,card_number,public_card_uuid,employee_id from id_cards where status='active' and public_card_uuid is not null order by updated_at desc limit 1");
    const [cardId, cardNumber, publicUuid, employeeId] = card.split('|');
    const noQr = sql("select id,card_number from id_cards where status='active' and public_card_uuid is null limit 1");

    async function scan(token) {
        const inp = page.locator('input[placeholder*="|token"]');
        await inp.fill(token);
        const wait = page.waitForResponse((r) => /provider\/portal\/scan$/.test(r.url()) && r.request().method() === 'POST', { timeout: 30000 }).catch(() => null);
        await page.locator('form').filter({ has: inp }).locator('button[type=submit]').click();
        const resp = await wait;
        await page.waitForLoadState('networkidle').catch(() => {});
        await page.waitForTimeout(600);
        const banner = page.locator('p.font-semibold').filter({ hasText: /recorded|denied|accepted|refused|Scan/i }).first();
        const text = (await banner.count()) ? (await banner.innerText()).trim() : (await bodyText(page)).slice(0, 300);
        return { status: resp ? resp.status() : null, text };
    }

    await R.step(`Scan active card ${cardNumber} (public uuid) -> accepted with subsidy 100 / employee share 20`, async () => {
        if (!publicUuid) throw new Error('BLOCKED: no active card with a public_card_uuid');
        const r = await scan(publicUuid);
        await shot(page, 'portal-cafe-scan-accepted');
        const row = sql(`select t.id,t.status,t.subsidy_amount_applied,t.employee_payable_amount,o.code,p.provider_code,t.pricing_source from cafeteria_transactions t left join organizations o on o.id=t.employee_organization_id left join providers p on p.id=t.provider_id where t.id_card_id='${cardId}' order by t.created_at desc limit 1`);
        state.scanTxn = row.split('|')[0]; saveState(state);
        if (!row) throw new Error(`no transaction row; page: ${r.text}`);
        const [, status, subsidy, share, org, prov] = row.split('|');
        if (status !== 'accepted') throw new Error(`transaction status=${status}; page: ${r.text}; row=${row}`);
        if (Number(subsidy) !== 100 || Number(share) !== 20) throw new Error('amounts differ from policy: ' + row);
        if (org !== 'BUR-PSHRDB' || prov !== 'QA-CAFE-1') throw new Error('billing owner/payee wrong: ' + row);
        return `HTTP ${r.status}; "${r.text}"; row=${row}`;
    }, page);

    await R.step('Second scan of the same card today is refused (no second accepted row)', async () => {
        await page.waitForTimeout(1500);
        const r = await scan(publicUuid);
        await shot(page, 'portal-cafe-scan-duplicate');
        const rows = sql(`select status,coalesce(blocked_reason,''),is_extra_scan from cafeteria_transactions where id_card_id='${cardId}' and transaction_date=current_date order by created_at`);
        const accepted = rows.split('\n').filter((l) => l.startsWith('accepted')).length;
        if (accepted > 1) throw new Error('second accepted transaction recorded: ' + rows);
        if (!/denied|refused|already|used|consumed|Scan denied/i.test(r.text)) throw new Error('no refusal shown: ' + r.text);
        return `"${r.text}"; rows=${rows.replace(/\n/g, ' ; ')}`;
    }, page);

    await R.step('Card without QR reference (seeded AA-2026-DEMO01) cannot be scanned by its primary id', async () => {
        if (!noQr) return 'no such card any more';
        const [id, num] = noQr.split('|');
        const r = await scan(id);
        if (!/denied|not found|invalid|unknown|refused/i.test(r.text)) throw new Error('unexpected: ' + r.text);
        return `${num}: "${r.text}"`;
    }, page);

    await R.step('Garbage token refused without 5xx', async () => {
        const r = await scan('not-a-card-token-xyz');
        if (r.status && r.status >= 500) throw new Error('HTTP ' + r.status);
        return `HTTP ${r.status}: "${r.text}"`;
    }, page);

    await R.step('Portal transactions list shows the accepted scan; detail page opens', async () => {
        await visit(page, '/provider/portal/transactions');
        const t = await bodyText(page);
        const emp = sql(`select full_name from employees where id='${employeeId}'`);
        await shot(page, 'portal-cafe-transactions');
        if (!t.includes(emp) && !t.includes(cardNumber)) throw new Error('transaction not listed for ' + emp);
        await visit(page, `/provider/portal/transactions/${state.scanTxn}`);
        await shot(page, 'portal-cafe-transaction-show');
        const d = await bodyText(page);
        if (/national|ብሔራዊ መታወቂያ/i.test(d) && /\d{10,}/.test(d)) return 'WARNING: possible national id on detail page';
        return `listed for ${emp}`;
    }, page);

    await R.step('scan/today JSON endpoint returns the scan, no PII beyond name/number', async () => {
        const resp = await page.context().request.get(`${BASE}/provider/portal/scan/today`, { headers: { Accept: 'application/json' } });
        if (resp.status() !== 200) throw new Error('HTTP ' + resp.status());
        const json = await resp.json();
        const keys = json.data && json.data[0] ? Object.keys(json.data[0]) : [];
        if (keys.some((k) => /national|phone|token|hash/i.test(k))) throw new Error('sensitive key exposed: ' + keys.join(','));
        return `count=${json.data ? json.data.length : 0}; keys=${keys.join(',')}`;
    });

    await R.step('Transaction exports from the portal (CSV / XLSX / PDF / payment claim) are real files', async () => {
        const out = [];
        for (const p of ['/provider/portal/transactions/export/csv', '/provider/portal/transactions/export/xlsx', '/provider/portal/transactions/export/pdf', '/provider/portal/transactions/export/payment-claim/xlsx', '/provider/portal/transactions/export/payment-claim/pdf']) {
            const r = await fetchWithSession(page, `${BASE}${p}`);
            out.push(`${p.replace('/provider/portal/transactions/export', '')}: ${r.status} ${r.contentType.split(';')[0]} ${r.size}B head=${JSON.stringify(r.head.slice(0, 4))}`);
            if (r.status !== 200) throw new Error(out.join(' | '));
            if (/pdf/.test(p) && !r.head.startsWith('%PDF')) throw new Error('not a PDF: ' + out.at(-1));
            if (/xlsx/.test(p) && !r.head.startsWith('PK')) throw new Error('not an XLSX: ' + out.at(-1));
            if (r.size < 300) throw new Error('too small: ' + out.at(-1));
        }
        return out.join(' | ');
    });

    await R.step('Back office: transaction visible in Cafeteria › Transactions, billed to BUR-PSHRDB, payee QA-CAFE-1', async () => {
        const ctx = await browser.newContext();
        const p = await newPageIn(ctx);
        R.attach(p);
        await login(p, 'qa.sysadmin4@demo.local', 'password', {});
        await visit(p, `/cafeteria/transactions?provider_id=${state.cafeProviderId}`);
        const t = await bodyText(p);
        await shot(p, 'portal-bo-transactions');
        await visit(p, `/cafeteria/transactions/${state.scanTxn}`);
        const d = await bodyText(p);
        await shot(p, 'portal-bo-transaction-show');
        await ctx.close();
        if (!/QA Cafeteria Provider 1|QA-CAFE-1/.test(t)) throw new Error('transaction not in back-office list');
        if (!/Public Service and Human Resource|BUR-PSHRDB/.test(d)) throw new Error('billing organization not shown on detail');
        return 'listed; detail shows BUR-PSHRDB';
    });

    await browser.close();
    R.save();
})().catch((e) => { console.error('FATAL', e); process.exit(1); });
