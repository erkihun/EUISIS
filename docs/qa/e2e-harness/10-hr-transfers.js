'use strict';
// Stage C of the HR core suite: Transfer module end to end — announcement (create + publish), employee application,
// screening, candidate selection, release/receiving approvals, implementation; employee UUID/number invariants.
// Requires state from 10-hr-org-pos.js and 10-hr-employees.js (emp1, org2, pos3).
const fs = require('fs');
const path = require('path');
const { execFileSync } = require('child_process');
const { BASE, Recorder, newBrowser, newPageIn, login, submitAndLeave, expectUrl, visit, shot } = require('./lib');
const H = require('./10-hr-helpers');

const SYSADMIN = ['qa.sysadmin1@demo.local', 'password'];
const tinker = (code) => execFileSync('php', ['artisan', 'tinker', '--execute', code], { cwd: '/home/user/EUISIS', encoding: 'utf8', timeout: 120000 });

(async () => {
    const R = new Recorder('10-hr-transfers');
    const state = H.loadState();
    if (!state.emp1 || !state.org2 || !state.pos3) throw new Error('run 10-hr-org-pos.js and 10-hr-employees.js first');
    const RUN = state.run;
    const { browser, page } = await newBrowser({ timeout: 25000 });
    R.attach(page);
    const posts = [];
    H.captureResponses(page, posts);
    const dialogs = [];
    page.on('dialog', async (d) => { dialogs.push(d.message().slice(0, 120)); await d.accept(); });
    const lastPost = (re) => [...posts].reverse().find((p) => re.test(p.url));
    const timings = {};
    const empUserEmail = `qa.employee1.${RUN}@demo.local`;

    const before = H.sql(`select id, employee_number, current_assignment_id from employees where id='${state.emp1.id}'`)[0];
    const appStatus = () => state.app ? H.sql(`select status from transfer_applications where id='${state.app.id}'`)[0]?.status : null;

    /** Click a page action button, then confirm in the app dialog (button with the same label unless given). */
    async function clickAndConfirm(label, confirmLabel = label) {
        await page.locator(`button:has-text("${label}")`).first().click();
        const dlg = page.locator('[role="dialog"]').last();
        await dlg.waitFor({ state: 'visible', timeout: 8000 });
        const mark = H.logMark();
        await dlg.locator(`button:has-text("${confirmLabel}")`).last().click();
        await page.waitForLoadState('networkidle');
        await page.waitForTimeout(1200);
        return { msgs: await H.visibleMessages(page), log: H.logErrorsSince(mark) };
    }

    await R.step('System Admin logs in; transfer dashboard renders', async () => {
        await login(page, ...SYSADMIN);
        const t0 = Date.now();
        await visit(page, '/transfers');
        timings['transfers.dashboard'] = Date.now() - t0;
        await shot(page, 'hr-tr-dashboard');
        return `${timings['transfers.dashboard']}ms`;
    }, page);

    await R.step('Transfer settings: defaults (release + receiving consent required, no final approval)', async () => {
        await visit(page, '/transfers/settings');
        const row = H.sql(`select releasing_consent_required, receiving_consent_required, final_approval_required, minimum_service_months, allow_cross_institution from transfer_settings limit 1`)[0];
        await shot(page, 'hr-tr-settings');
        return JSON.stringify(row || 'no settings row yet (created on first use)');
    }, page);

    await R.step('Create transfer announcement for "QA Transfer Target" in QA-ORG-2 (1 vacancy, open today)', async () => {
        await visit(page, '/transfer-announcements/create');
        await page.locator('#org-0').selectOption(state.org2.id);
        await page.waitForTimeout(400);
        const options = await page.locator('#position-0 option').allTextContents();
        if (!(await page.locator(`#position-0 option[value="${state.pos3.id}"]`).count())) throw new Error('QA Transfer Target not offered as a vacant position: ' + options.join(' | '));
        await page.locator('#position-0').selectOption(state.pos3.id);
        await page.locator('#vacancies-0').fill('1');
        const today = new Date().toISOString().slice(0, 10);
        const close = new Date(Date.now() + 30 * 86400000).toISOString().slice(0, 10);
        await page.locator('#opening-date').locator('xpath=preceding-sibling::input[@type="date"]').fill(today);
        await page.locator('#closing-date').locator('xpath=preceding-sibling::input[@type="date"]').fill(close);
        await shot(page, 'hr-tr-ann-create');
        await submitAndLeave(page, /\/transfer-announcements\/create/, 20000);
        const post = lastPost(/\/transfer-announcements$/);
        const errs = await H.fieldErrors(page);
        const ann = H.sql(`select id, status, opening_date, closing_date, number_of_vacancies from transfer_announcements where organization_id='${state.org2.id}' and position_id='${state.pos3.id}' order by created_at desc limit 1`)[0];
        if (!ann) throw new Error(`announcement not created; POST ${post?.status}; errors=${errs.join(' | ')}`);
        state.ann = ann;
        H.saveState(state);
        return `id=${ann.id} status=${ann.status} ${ann.opening_date}..${ann.closing_date}; POST ${post?.status}; landed ${page.url().replace(BASE, '')}`;
    }, page);

    await R.step('Publish the announcement (confirm dialog); status becomes published', async () => {
        await visit(page, `/transfer-announcements/${state.ann.id}`);
        const body = await page.locator('body').innerText();
        if (/Not ready to publish/i.test(body)) throw new Error('readiness issues shown: ' + (body.match(/Not ready[^\n]*\n[^\n]*/i) || [''])[0]);
        const r = await clickAndConfirm('Publish');
        const row = H.sql(`select status, published_at from transfer_announcements where id='${state.ann.id}'`)[0];
        await shot(page, 'hr-tr-ann-published');
        if (row.status !== 'published') throw new Error(`status=${row.status}; msgs=${r.msgs.join(' | ')}; log=${r.log.join(' || ')}`);
        return `published_at=${row.published_at}; msgs=${r.msgs.join(' | ')}`;
    }, page);

    await R.step('TEST DATA: portal user linked to QA-Emp One (role Employee) so the employee can apply', async () => {
        const existing = H.sql(`select id from users where email='${empUserEmail}'`)[0];
        if (existing) return 'exists';
        const out = tinker(`
            $u = \\App\\Models\\User::create(['name' => 'QA Employee One', 'email' => '${empUserEmail}', 'password' => 'password', 'status' => 'active', 'employee_id' => '${state.emp1.id}', 'is_demo' => true]);
            $u->assignRole('Employee');
            echo $u->id, PHP_EOL;
        `);
        const u = H.sql(`select id, employee_id from users where email='${empUserEmail}'`)[0];
        if (!u) throw new Error('user not created: ' + out.slice(0, 300));
        return `user id=${u.id} linked to ${state.emp1.employee_number}`;
    }, page);

    await R.step('Employee applies to the announcement from the public announcement page', async () => {
        const c = await browser.newContext({ viewport: { width: 1366, height: 900 } });
        const p = await newPageIn(c);
        p.on('dialog', async (d) => d.accept());
        const ps = []; H.captureResponses(p, ps);
        const landed = await login(p, empUserEmail, 'password');
        await p.goto(`${BASE}/announcements/transfer/${state.ann.id}/apply`, { waitUntil: 'networkidle' });
        const url1 = p.url();
        const body1 = await p.locator('body').innerText();
        await shot(p, 'hr-tr-apply-form');
        if (!/\/apply$/.test(url1)) { await c.close(); throw new Error(`apply page redirected to ${url1}: ${body1.replace(/\s+/g, ' ').slice(0, 200)}`); }
        const posSel = p.locator('#announcement-position');
        if (await posSel.count()) await posSel.selectOption({ index: 1 });
        const cover = p.locator('#cover-letter');
        if (await cover.count()) await cover.fill('QA transfer application (automated E2E).');
        const mark = H.logMark();
        await p.click('button[type="submit"]');
        await p.waitForLoadState('networkidle');
        await p.waitForTimeout(1500);
        const post = [...ps].reverse().find((x) => /\/apply$/.test(x.url));
        const errs = await H.fieldErrors(p);
        const msgs = await H.visibleMessages(p);
        const body2 = await p.locator('body').innerText();
        await shot(p, 'hr-tr-applied');
        await c.close();
        const app = H.sql(`select id, application_number, status, releasing_organization_id, receiving_organization_id from transfer_applications where employee_id='${state.emp1.id}' and announcement_id='${state.ann.id}' order by created_at desc limit 1`)[0];
        if (!app) throw new Error(`no application; login landed ${landed}; POST ${post?.status}; errors=${errs.join(' | ')}; msgs=${msgs.join(' | ')}; body=${body2.replace(/\s+/g, ' ').slice(0, 200)}; log=${H.logErrorsSince(mark).join(' || ').slice(0, 300)}`);
        state.app = app;
        H.saveState(state);
        if (!/^TRA-\d{4}-\d{6}$/.test(app.application_number)) throw new Error('application number not rule-generated: ' + app.application_number);
        return `${app.application_number} status=${app.status}; releasing=${app.releasing_organization_id === state.org1.id ? 'QA-ORG-1' : app.releasing_organization_id} receiving=${app.receiving_organization_id === state.org2.id ? 'QA-ORG-2' : app.receiving_organization_id}; POST ${post?.status}`;
    }, page);

    await R.step('Application appears in the admin applications list and detail page', async () => {
        const t0 = Date.now();
        await visit(page, '/transfer-applications');
        timings['transfer-applications.index'] = Date.now() - t0;
        const body = await page.locator('body').innerText();
        if (!body.includes(state.app.application_number)) throw new Error('application not listed');
        await visit(page, `/transfer-applications/${state.app.id}`);
        const detail = await page.locator('body').innerText();
        if (!detail.includes(state.emp1.employee_number) && !/QA-Emp/.test(detail)) throw new Error('employee not shown on detail');
        await shot(page, 'hr-tr-app-show');
        return `${timings['transfer-applications.index']}ms`;
    }, page);

    await R.step('Screening: "Start Review" moves the application to under_review', async () => {
        await visit(page, `/transfer-applications/${state.app.id}`);
        const r = await clickAndConfirm('Start Review');
        const st = appStatus();
        if (st !== 'under_review') throw new Error(`status=${st}; msgs=${r.msgs.join(' | ')}; log=${r.log.join(' || ')}`);
        return `status=${st}; msgs=${r.msgs.join(' | ')}`;
    }, page);

    await R.step('"Select Candidate" starts the approval chain (release_pending) and creates approval records', async () => {
        await page.locator('button:has-text("Select Candidate")').first().click();
        const dlg = page.locator('[role="dialog"]').last();
        await dlg.waitFor({ state: 'visible', timeout: 8000 });
        await dlg.locator('button[type="submit"], button:has-text("Select Candidate")').last().click();
        await page.waitForLoadState('networkidle');
        await page.waitForTimeout(1500);
        const st = appStatus();
        const approvals = H.sql(`select approval_type, status from transfer_approvals where transfer_application_id='${state.app.id}' order by created_at`);
        await shot(page, 'hr-tr-app-selected');
        if (st !== 'release_pending') throw new Error(`status=${st}; approvals=${JSON.stringify(approvals)}; msgs=${(await H.visibleMessages(page)).join(' | ')}`);
        return `status=${st}; approvals=${approvals.map((a) => a.approval_type + ':' + a.status).join(', ')}`;
    }, page);

    await R.step('Releasing organization approval ("Approve Release") -> receiving_pending', async () => {
        const r = await clickAndConfirm('Approve Release', 'Approve');
        const st = appStatus();
        if (st !== 'receiving_pending') throw new Error(`status=${st}; msgs=${r.msgs.join(' | ')}; log=${r.log.join(' || ')}`);
        return `status=${st}; msgs=${r.msgs.join(' | ')}`;
    }, page);

    await R.step('Receiving organization approval ("Approve Receiving") -> approved (no final approval configured)', async () => {
        const r = await clickAndConfirm('Approve Receiving', 'Approve');
        const st = appStatus();
        const legacy = H.sql(`select status from employee_transfers where employee_id='${state.emp1.id}' order by created_at desc limit 1`);
        await shot(page, 'hr-tr-app-approved');
        if (st !== 'approved') throw new Error(`status=${st}; msgs=${r.msgs.join(' | ')}; log=${r.log.join(' || ')}`);
        const mid = H.sql(`select id, employee_number, current_assignment_id from employees where id='${state.emp1.id}'`)[0];
        if (mid.current_assignment_id !== before.current_assignment_id) throw new Error('assignment changed before implementation!');
        return `status=${st}; employee_transfers row=${JSON.stringify(legacy)}; assignment unchanged before implementation`;
    }, page);

    await R.step('"Implement Transfer" closes the old assignment, creates the new one in QA-ORG-2 and marks transferred', async () => {
        await visit(page, `/transfer-applications/${state.app.id}`);
        const r = await clickAndConfirm('Implement Transfer');
        const st = appStatus();
        const emp = H.sql(`select id, employee_number, current_assignment_id from employees where id='${state.emp1.id}'`)[0];
        const assignments = H.sql(`select organization_id, position_id, is_current, assignment_status, effective_to from employee_assignments where employee_id='${state.emp1.id}' order by created_at`);
        const app = H.sql(`select status, implementation_failure, implemented_at from transfer_applications where id='${state.app.id}'`)[0];
        const body = await page.locator('body').innerText();
        await shot(page, 'hr-tr-app-implemented');
        fs.writeFileSync(path.join(__dirname, 'results', '10-hr-transfer-implement.json'), JSON.stringify({ app, emp, assignments, msgs: r.msgs, log: r.log, posts: posts.slice(-3) }, null, 2));
        if (st !== 'transferred') {
            throw new Error(`status=${st}; implementation_failure="${app.implementation_failure}"; msgs=${r.msgs.join(' | ')}; page: ${(body.match(/[^\n]*(failed|error)[^\n]*/i) || [''])[0].trim().slice(0, 200)}; log=${r.log.join(' || ').slice(0, 600)}`);
        }
        const current = assignments.find((a) => a.is_current);
        if (!current || current.organization_id !== state.org2.id) throw new Error('current assignment not in QA-ORG-2: ' + JSON.stringify(assignments));
        return `status=${st}; assignments=${assignments.map((a) => (a.organization_id === state.org2.id ? 'ORG-2' : 'ORG-1') + ':' + a.assignment_status + (a.is_current ? '(current)' : '')).join(', ')}`;
    }, page);

    await R.step('Invariant: employee UUID and employee number are unchanged after the transfer workflow', async () => {
        const after = H.sql(`select id, employee_number, current_assignment_id from employees where id='${state.emp1.id}'`)[0];
        if (!after) throw new Error('employee row vanished');
        if (after.id !== before.id || after.employee_number !== before.employee_number) throw new Error(`changed: ${JSON.stringify({ before, after })}`);
        const audit = H.sql(`select event_type from audit_logs where auditable_id='${state.app.id}' order by created_at`).map((r) => r.event_type);
        return `id=${after.id} number=${after.employee_number} (unchanged); current_assignment ${after.current_assignment_id === before.current_assignment_id ? 'unchanged' : 'moved'}; audit events on application: ${audit.join(', ') || 'none'}`;
    }, page);

    await R.step('Employee show page reflects the transfer (organization + transfer history)', async () => {
        await visit(page, `/employees/${state.emp1.id}`);
        const body = await page.locator('body').innerText();
        await shot(page, 'hr-tr-emp-after');
        const inOrg2 = body.includes(state.org2Name) || body.includes(state.org2.code);
        const history = /Transfer History[\s\S]{0,400}/i.exec(body)?.[0].replace(/\s+/g, ' ').slice(0, 200);
        return `shows QA-ORG-2=${inOrg2}; transfer history: "${history}"`;
    }, page);

    H.saveState(state);
    console.log('timings', JSON.stringify(timings), 'dialogs:', JSON.stringify(dialogs));
    fs.writeFileSync(path.join(__dirname, 'results', '10-hr-transfers-posts.json'), JSON.stringify(posts.filter((p) => p.status >= 400), null, 2));
    await browser.close();
    R.save();
})().catch((e) => { console.error('FATAL', e); process.exit(1); });
