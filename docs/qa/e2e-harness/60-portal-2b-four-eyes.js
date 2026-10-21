'use strict';
// Journey 2b: server-side "second person approves" rule on cafeteria service policies (new version of the active policy).
const { BASE, Recorder, newBrowser, newPageIn, login, submitAndLeave, visit, shot } = require('./lib');
const { loadState, saveState, sql, fillDate, bodyText, dialogAction } = require('./60-portal-lib');

const state = loadState();
const tomorrow = new Date(Date.now() + 86400000 * 3).toISOString().slice(0, 10);

(async () => {
    const R = new Recorder('60-portal-2b-four-eyes');
    const { browser, page } = await newBrowser();
    R.attach(page);

    await R.step('Login as qa.sysadmin4', async () => login(page, 'qa.sysadmin4@demo.local', 'password', {}), page);

    await R.step('Policy chain recap for the QA assignment', async () => {
        const rows = sql(`select id,status,version_no,created_by,submitted_by,approved_by,effective_from from cafeteria_service_policies where cafeteria_service_assignment_id='${state.assignmentId}' order by version_no`);
        const last = rows.split('\n').at(-1).split('|');
        state.basePolicyId = last[0]; state.baseVersion = Number(last[2]);
        return rows.replace(/\n/g, ' ; ');
    });

    await R.step('Create new policy version (v2) effective tomorrow, subsidy 110/20/130', async () => {
        await visit(page, `/cafeteria/service-policies/${state.basePolicyId}`);
        const btn = page.getByRole('button', { name: /Create New Version|New Version/i }).first();
        await btn.waitFor({ state: 'visible' });
        await btn.click();
        await page.waitForURL(/service-policies\/[0-9a-f-]{36}\/edit/, { timeout: 20000 });
        await page.waitForLoadState('networkidle');
        const m = page.url().match(/service-policies\/([0-9a-f-]{36})\/edit/);
        state.policyV2Id = m[1]; saveState(state);
        const { fillLabel } = require('./60-portal-lib');
        await fillLabel(page, 'Daily Subsidy', '110');
        await fillLabel(page, 'Employee Contribution', '20');
        await fillLabel(page, 'Provider Price', '130');
        await fillDate(page, 'Effective From', tomorrow);
        await shot(page, 'portal-cafe-policy-v2-edit');
        await submitAndLeave(page, /\/edit$/);
        if (/\/edit$/.test(page.url())) throw new Error('stayed on edit: ' + (await page.locator('form').innerText()).replace(/\s+/g, ' ').slice(0, 400));
        return sql(`select status,version_no,effective_from,daily_subsidy_amount from cafeteria_service_policies where id='${state.policyV2Id}'`);
    }, page);

    await R.step('Submit v2 for review as sysadmin4', async () => {
        await visit(page, `/cafeteria/service-policies/${state.policyV2Id}`);
        const err = await dialogAction(page, /Submit for Review/i);
        const row = sql(`select status,submitted_by from cafeteria_service_policies where id='${state.policyV2Id}'`);
        if (err) throw new Error('refused: ' + err);
        if (!row.startsWith('under_review')) throw new Error('status: ' + row);
        return row;
    }, page);

    await R.step('Four-eyes (server): the submitter sysadmin4 cannot approve v2', async () => {
        await visit(page, `/cafeteria/service-policies/${state.policyV2Id}`);
        const visible = await page.getByRole('button', { name: /^Approve$/ }).count();
        let err = null;
        if (visible) err = await dialogAction(page, /^Approve$/);
        const row = sql(`select status,approved_by from cafeteria_service_policies where id='${state.policyV2Id}'`);
        await shot(page, 'portal-cafe-policy-v2-self-approve');
        if (!row.startsWith('under_review')) throw new Error('self-approval went through: ' + row);
        // Direct POST bypassing the UI (same session)
        const token = await page.evaluate(() => document.querySelector('meta[name="csrf-token"]')?.getAttribute('content'));
        const xsrf = (await page.context().cookies()).find((c) => c.name === 'XSRF-TOKEN');
        const resp = await page.request.post(`${BASE}/cafeteria/service-policies/${state.policyV2Id}/approve`, {
            headers: { 'X-XSRF-TOKEN': decodeURIComponent(xsrf?.value || ''), 'X-Requested-With': 'XMLHttpRequest', Accept: 'application/json', ...(token ? { 'X-CSRF-TOKEN': token } : {}) },
            data: {}, maxRedirects: 0, failOnStatusCode: false,
        });
        const body = (await resp.text()).slice(0, 300);
        const row2 = sql(`select status from cafeteria_service_policies where id='${state.policyV2Id}'`);
        if (row2 !== 'under_review') throw new Error('direct POST approved own policy! ' + body);
        return `button visible=${visible}; dialog error=${err}; direct POST status=${resp.status()} body=${body}`;
    }, page);

    await R.step('Cleanup: cancel the under-review version so it never supersedes the live policy', async () => {
        await visit(page, `/cafeteria/service-policies/${state.policyV2Id}`);
        let err = null;
        if (await page.getByRole('button', { name: /Cancel Policy/i }).count()) err = await dialogAction(page, /Cancel Policy/i, 'QA cleanup');
        else if (await page.getByRole('button', { name: /Return to Draft/i }).count()) { err = await dialogAction(page, /Return to Draft/i, 'QA cleanup'); if (!err) err = await dialogAction(page, /Cancel Policy/i, 'QA cleanup'); }
        const row = sql(`select status from cafeteria_service_policies where id='${state.policyV2Id}'`);
        const live = sql(`select status,effective_to from cafeteria_service_policies where id='${state.policyId}'`);
        if (err) return `cancel refused: ${err}; status=${row}; v1=${live}`;
        return `status=${row}; v1=${live}`;
    }, page);

    await R.step('Policy page in Amharic shows Ethiopian calendar dates, no English leakage in headings', async () => {
        const ctx = await browser.newContext();
        const p2 = await newPageIn(ctx, 'am');
        await login(p2, 'qa.sysadmin4@demo.local', 'password', {});
        await visit(p2, `/cafeteria/service-policies/${state.policyId}`);
        const body = await bodyText(p2);
        await shot(p2, 'portal-cafe-policy-am');
        await ctx.close();
        const greg = (body.match(/\b\d{2}\/\d{2}\/20\d{2}\b/g) || []).slice(0, 5);
        const eth = (body.match(/(መስከረም|ጥቅምት|ኅዳር|ህዳር|ታኅሣሥ|ታህሳስ|ጥር|የካቲት|መጋቢት|ሚያዝያ|ግንቦት|ሰኔ|ሐምሌ|ነሐሴ|ጳጉሜ)/g) || []).length;
        const english = (body.match(/\b(Daily Subsidy|Effective From|Service Policy|Submit for Review|Approve|Version History)\b/g) || []);
        return `ethiopian month tokens=${eth}; gregorian dd/mm/yyyy=${JSON.stringify(greg)}; english=${JSON.stringify(english)}`;
    });

    await browser.close();
    R.save();
})().catch((e) => { console.error('FATAL', e); process.exit(1); });
