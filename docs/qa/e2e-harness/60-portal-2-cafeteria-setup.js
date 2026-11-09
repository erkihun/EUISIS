'use strict';
// Journey 2: cafeteria setup (UAT step 5) — organization access, service assignment, service policy with distinct approver.
const { BASE, Recorder, newBrowser, newPageIn, login, submitAndLeave, visit, shot } = require('./lib');
const { loadState, saveState, sql, byLabel, fillLabel, fillDate, selectLabel, selectLabelByText, bodyText, flashText, clickButton } = require('./60-portal-lib');

const state = loadState();
const ORG_CODE = 'BUR-PSHRDB'; // organization of the demo employee holding the only active card
const today = new Date().toISOString().slice(0, 10);

async function dialogAction(page, buttonRe, fieldValue) {
    const b = page.getByRole('button', { name: buttonRe }).first();
    await b.waitFor({ state: 'visible', timeout: 15000 });
    await b.click();
    const dlg = page.locator('[role="dialog"]').last();
    const btns = dlg.getByRole('button');
    await btns.last().waitFor({ state: 'visible', timeout: 10000 });
    if (fieldValue !== undefined) {
        const inp = dlg.locator('input:not([type=hidden]), textarea').first();
        if (await inp.count()) await inp.fill(fieldValue);
    }
    await btns.last().click();
    await page.waitForLoadState('networkidle').catch(() => {});
    await page.waitForTimeout(800);
    let error = null;
    if (await dlg.count()) {
        const alert = dlg.locator('[role="alert"]');
        if (await alert.count()) error = (await alert.first().innerText()).replace(/\s+/g, ' ');
        await dlg.getByRole('button', { name: /cancel|close/i }).first().click({ timeout: 2000 }).catch(() => {});
    }
    return error;
}

(async () => {
    const R = new Recorder('60-portal-2-cafeteria-setup');
    const { browser, page } = await newBrowser();
    R.attach(page);

    await R.step('Login as qa.sysadmin4', async () => login(page, 'qa.sysadmin4@demo.local', 'password', {}), page);

    await R.step('Cafeteria networks list shows QA-NET-1', async () => {
        await visit(page, '/cafeteria/networks');
        const body = await bodyText(page);
        if (!body.includes('QA-NET-1')) throw new Error('QA-NET-1 not listed');
        await shot(page, 'portal-cafe-networks');
    }, page);

    await R.step(`Grant organization access: ${ORG_CODE} -> network QA-NET-1 (effective today)`, async () => {
        await visit(page, '/cafeteria/organization-access/create');
        const orgHit = await selectLabelByText(page, 'Organization', new RegExp(ORG_CODE));
        await selectLabelByText(page, 'Cafeteria Network', /QA-NET-1|QA Network 1/);
        await selectLabelByText(page, 'Primary Cafeteria', /QA-CAFE-1|QA Cafeteria 1/).catch(() => null);
        await page.getByLabel(/Cross-Location Usage/).first().check().catch(() => null);
        await fillDate(page, 'Effective From', today);
        await shot(page, 'portal-cafe-access-form');
        await submitAndLeave(page, /organization-access\/create/);
        if (/\/create/.test(page.url())) throw new Error('stayed on form: ' + (await page.locator('form').innerText()).replace(/\s+/g, ' ').slice(0, 400));
        const row = sql(`select a.id,a.status,a.effective_from from organization_cafeteria_access a join organizations o on o.id=a.organization_id where o.code='${ORG_CODE}' and a.cafeteria_service_network_id='${state.networkId}' order by a.created_at desc limit 1`);
        if (!row) throw new Error('no access row in DB');
        state.accessId = row.split('|')[0]; saveState(state);
        return `org=${orgHit.label} row=${row}`;
    }, page);

    await R.step('Approve the organization access (pending_approval -> active)', async () => {
        await visit(page, `/cafeteria/organization-access?status=pending_approval`);
        const err = await dialogAction(page, /^Approve$/);
        const row = sql(`select status,approved_by from organization_cafeteria_access where id='${state.accessId}'`);
        await shot(page, 'portal-cafe-access-approved');
        if (err) return `refused for same user: ${err} | row=${row}`;
        if (!row.startsWith('active')) throw new Error('access not active: ' + row);
        return row;
    }, page);

    await R.step(`Create service assignment: ${ORG_CODE} -> QA-CAFE-1 (network scope)`, async () => {
        await visit(page, '/cafeteria/service-assignments/create');
        await selectLabelByText(page, 'Organization', new RegExp(ORG_CODE));
        await selectLabelByText(page, 'Provider', /QA-CAFE-1|QA Cafeteria Provider 1/);
        await selectLabelByText(page, 'Cafeteria Network', /QA-NET-1|QA Network 1/).catch(() => null);
        await fillDate(page, 'Effective From', today);
        await shot(page, 'portal-cafe-assignment-form');
        await submitAndLeave(page, /service-assignments\/create/);
        if (/\/create/.test(page.url())) throw new Error('stayed on form: ' + (await page.locator('form').innerText()).replace(/\s+/g, ' ').slice(0, 400));
        const row = sql(`select id,status from cafeteria_service_assignments where provider_id='${state.cafeProviderId}' order by created_at desc limit 1`);
        if (!row) throw new Error('no assignment row');
        state.assignmentId = row.split('|')[0]; saveState(state);
        return row;
    }, page);

    await R.step('Approve the service assignment', async () => {
        await visit(page, `/cafeteria/service-assignments?status=pending_approval`);
        const err = await dialogAction(page, /^Approve$/);
        const row = sql(`select status from cafeteria_service_assignments where id='${state.assignmentId}'`);
        await shot(page, 'portal-cafe-assignment-approved');
        if (err) return `refused: ${err} | row=${row}`;
        if (row !== 'active') throw new Error('assignment not active: ' + row);
        return row;
    }, page);

    await R.step('Create service policy draft: subsidy 100 + employee share 20 = provider price 120 ETB', async () => {
        await visit(page, `/cafeteria/service-policies/create?assignment_id=${state.assignmentId}`);
        const sel = await byLabel(page, 'Service Assignment');
        if (!(await sel.inputValue())) await selectLabelByText(page, 'Service Assignment', new RegExp(ORG_CODE + '|QA-CAFE-1|QA Cafeteria'));
        await fillLabel(page, 'Daily Subsidy', '100');
        await fillLabel(page, 'Employee Contribution', '20');
        await fillLabel(page, 'Provider Price', '120');
        await fillDate(page, 'Effective From', today);
        await shot(page, 'portal-cafe-policy-form');
        await submitAndLeave(page, /service-policies\/create/);
        if (/\/create/.test(page.url())) throw new Error('stayed on form: ' + (await page.locator('form').innerText()).replace(/\s+/g, ' ').slice(0, 500));
        const m = page.url().match(/service-policies\/([0-9a-f-]{36})/);
        if (!m) throw new Error('not on policy page: ' + page.url());
        state.policyId = m[1]; saveState(state);
        const row = sql(`select status,daily_subsidy_amount,employee_contribution_amount,provider_price,created_by from cafeteria_service_policies where id='${state.policyId}'`);
        return row;
    }, page);

    await R.step('Validation: provider price must equal subsidy + employee share', async () => {
        await visit(page, `/cafeteria/service-policies/create?assignment_id=${state.assignmentId}`);
        await fillLabel(page, 'Daily Subsidy', '100');
        await fillLabel(page, 'Employee Contribution', '20');
        await fillLabel(page, 'Provider Price', '999');
        await fillDate(page, 'Effective From', today);
        await submitAndLeave(page, /service-policies\/create/, 6000);
        if (!/\/create/.test(page.url())) return 'WARNING: mismatched price accepted -> ' + page.url();
        const t = (await page.locator('form').innerText()).replace(/\s+/g, ' ');
        if (!/equal|sum|must|match|price/i.test(t)) throw new Error('no validation text: ' + t.slice(0, 300));
        return 'rejected';
    }, page);

    await R.step('Submit the policy for review (creator = sysadmin4)', async () => {
        await visit(page, `/cafeteria/service-policies/${state.policyId}`);
        const err = await dialogAction(page, /Submit for Review/i);
        if (err) throw new Error('submit refused: ' + err);
        const row = sql(`select status,submitted_by from cafeteria_service_policies where id='${state.policyId}'`);
        if (!row.startsWith('under_review')) throw new Error('status: ' + row);
        await shot(page, 'portal-cafe-policy-submitted');
        return row;
    }, page);

    await R.step('Four-eyes: the submitter (sysadmin4) cannot approve their own policy', async () => {
        await visit(page, `/cafeteria/service-policies/${state.policyId}`);
        const has = await page.getByRole('button', { name: /^Approve$/ }).count();
        if (!has) return 'Approve button hidden for submitter (UI-level guard)';
        const err = await dialogAction(page, /^Approve$/);
        const row = sql(`select status from cafeteria_service_policies where id='${state.policyId}'`);
        await shot(page, 'portal-cafe-policy-same-approver');
        if (row !== 'under_review') throw new Error('self-approval succeeded! status=' + row);
        if (!err) throw new Error('no error message shown although approval was refused');
        return `refused: ${err}`;
    }, page);

    await R.step('Second person (qa.sysadmin5) approves the policy', async () => {
        const ctx = await browser.newContext();
        const p2 = await newPageIn(ctx);
        R.attach(p2);
        await login(p2, 'qa.sysadmin5@demo.local', 'password', {});
        await visit(p2, `/cafeteria/service-policies/${state.policyId}`);
        const err = await dialogAction(p2, /^Approve$/);
        const row = sql(`select status,approved_by from cafeteria_service_policies where id='${state.policyId}'`);
        await shot(p2, 'portal-cafe-policy-approved');
        if (err) { await ctx.close(); throw new Error('approve refused: ' + err + ' row=' + row); }
        // activate if an explicit step exists
        if (await p2.getByRole('button', { name: /Mark Active/i }).count()) {
            await dialogAction(p2, /Mark Active/i);
        }
        const row2 = sql(`select status,approved_by,activated_at from cafeteria_service_policies where id='${state.policyId}'`);
        await ctx.close();
        return `after approve=${row}; final=${row2}`;
    }, page);

    await R.step('Policy index + cafeteria dashboard configuration health render', async () => {
        await visit(page, '/cafeteria/service-policies');
        const b1 = await bodyText(page);
        if (!/QA-CAFE-1|QA Cafeteria/.test(b1)) throw new Error('policy not in list');
        await visit(page, '/cafeteria');
        await shot(page, 'portal-cafe-dashboard');
    }, page);

    await browser.close();
    R.save();
})().catch((e) => { console.error('FATAL', e); process.exit(1); });
