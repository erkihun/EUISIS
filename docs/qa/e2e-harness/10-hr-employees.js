'use strict';
// Stage B of the HR core suite: Employee registration (incl. the suspected PostgreSQL 500), show/edit persistence,
// duplicate detection, CSV import, Amharic leak check and HR Officer scope boundaries.
// Requires state from 10-hr-org-pos.js (results/10-hr-state.json).
const fs = require('fs');
const path = require('path');
const { BASE, Recorder, newBrowser, newPageIn, login, submitAndLeave, expectUrl, visit, shot } = require('./lib');
const H = require('./10-hr-helpers');

const SYSADMIN = ['qa.sysadmin1@demo.local', 'password'];
const HR = ['hr.officer@demo.local', 'password'];

(async () => {
    const R = new Recorder('10-hr-employees');
    const state = H.loadState();
    if (!state.org1 || !state.pos1 || !state.pos2) throw new Error('run 10-hr-org-pos.js first (state missing)');
    const RUN = state.run;
    const { browser, context, page } = await newBrowser({ timeout: 25000 });
    R.attach(page);
    const posts = [];
    H.captureResponses(page, posts);
    const dialogs = [];
    // The create/edit forms guard navigation with window.confirm("unsaved changes"); accept so redirects proceed.
    page.on('dialog', async (d) => { dialogs.push(d.message().slice(0, 120)); await d.accept(); });
    const timings = {};
    const { execFileSync } = require('child_process');
    const tinker = (code) => execFileSync('php', ['artisan', 'tinker', '--execute', code], { cwd: '/home/user/EUISIS', encoding: 'utf8', timeout: 120000 });
    const lastPost = (re) => [...posts].reverse().find((p) => re.test(p.url));
    const evidence = {};

    async function waitCodePreview() {
        const resp = await page.waitForResponse((r) => r.url().includes('/code-rules/preview-code') && r.request().method() === 'POST', { timeout: 15000 });
        const json = await resp.json().catch(() => null);
        return { status: resp.status(), code: json?.code ?? null, error: json?.error ?? null };
    }

    /**
     * Fill and submit /employees/create. Returns everything observed: POST status, visible errors/messages,
     * laravel.log errors written during the submit, and the employee row (if any).
     */
    async function registerEmployee({ first, last, dob, phone, email, position, org = state.org1, unit = state.unit1 }) {
        await visit(page, '/employees/create');
        const previewP = waitCodePreview().catch(() => ({ code: null, error: 'no preview call' }));
        await page.locator('#organization_id').selectOption(org.id);
        await page.waitForLoadState('networkidle'); // changing the organization reloads units/positions (router.reload)
        await page.waitForTimeout(800);
        await page.locator('#organization_unit_id').selectOption(unit.id);
        await page.waitForTimeout(300);
        await page.locator('#position_id').selectOption(position.id);
        await page.locator('#first_name').fill(first);
        await page.locator('#last_name').fill(last);
        await page.locator('#gender').selectOption({ index: 1 });
        if (dob) await page.locator('input[type="date"]').first().fill(dob);
        if (phone) await page.locator('#phone').fill(phone);
        if (email) await page.locator('#email').fill(email);
        await page.locator('#employment_type').selectOption('permanent');
        const preview = await previewP;
        await page.waitForTimeout(300);
        const numberShown = await page.locator('[aria-live="polite"]').first().innerText().catch(() => '');
        await shot(page, `hr-emp-create-${first}`);
        const mark = H.logMark();
        const t0 = Date.now();
        await submitAndLeave(page, /\/employees\/create/, 40000);
        const ms = Date.now() - t0;
        const post = lastPost(/\/employees$/);
        const errs = await H.fieldErrors(page);
        const msgs = await H.visibleMessages(page);
        const bodyText = await page.locator('body').innerText();
        const logErrors = H.logErrorsSince(mark);
        const row = H.sql(`select id, employee_number, full_name, status, current_assignment_id, phone, date_of_birth from employees where first_name='${first}' and last_name='${last}' order by created_at desc limit 1`)[0] || null;
        const serverError = /Error 500|Something went wrong|Whoops|SQLSTATE/i.test(bodyText);
        const errRef = (bodyText.match(/ERR-[A-Z0-9]+/) || [])[0] || null;
        await shot(page, `hr-emp-after-submit-${last}`);
        return { preview, numberShown, post, errs, msgs, logErrors, row, url: page.url(), serverError, errRef, ms, bodyHead: bodyText.replace(/\s+/g, ' ').slice(0, 300) };
    }

    await R.step('System Admin logs in', async () => {
        const url = await login(page, ...SYSADMIN);
        if (!/dashboard/.test(url)) throw new Error('expected dashboard, got ' + url);
    }, page);

    await R.step('Employees list renders (English) with the registry table', async () => {
        const t0 = Date.now();
        await visit(page, '/employees');
        timings['employees.index'] = Date.now() - t0;
        const body = await page.locator('body').innerText();
        if (!/EMP-0001|Demo Employee/.test(body)) throw new Error('seeded employee missing from list');
        await shot(page, 'hr-emp-index');
        return `${timings['employees.index']}ms`;
    }, page);

    await R.step('Employee create form: employee number preview is generated by the code rule (EMP-YYYY-NNNNNN)', async () => {
        const previewP = waitCodePreview();
        await visit(page, '/employees/create');
        const preview = await previewP;
        await page.waitForTimeout(300);
        const shown = await page.locator('[aria-live="polite"]').first().innerText().catch(() => '');
        if (preview.status !== 200 || !preview.code) throw new Error('no preview: ' + JSON.stringify(preview));
        if (!/^EMP-\d{4}-\d{6}$/.test(preview.code)) throw new Error('unexpected format ' + preview.code);
        const manual = await page.locator('#employee_number, input[aria-label*="Employee Number"]').count();
        return `preview=${preview.code}, shown="${shown}", manual-input-count=${manual}`;
    }, page);

    await R.step('Employee create: empty submit shows required-field errors (first/last name) and no row is created', async () => {
        await visit(page, '/employees/create');
        await page.waitForTimeout(800);
        const before = H.sql(`select count(*)::int as n from employees`)[0].n;
        await submitAndLeave(page, /\/employees\/create/, 10000);
        const post = lastPost(/\/employees$/);
        const errs = await H.fieldErrors(page);
        await shot(page, 'hr-emp-validation');
        const after = H.sql(`select count(*)::int as n from employees`)[0].n;
        if (after !== before) throw new Error('row created on empty submit');
        if (!/\/employees\/create/.test(page.url())) throw new Error('left create page: ' + page.url());
        if (!errs.some((e) => /first name/i.test(e)) || !errs.some((e) => /last name/i.test(e))) throw new Error(`missing name errors; POST=${post?.status}; errors=${errs.join(' | ')}`);
        return `POST ${post?.status}; ${errs.length} errors: ${errs.slice(0, 5).join(' | ')}`;
    }, page);

    await R.step('Register employee #1 (QA-Emp One) in position "QA Analyst" — observe HTTP status, UI, log and DB', async () => {
        const r = await registerEmployee({ first: `QA-Emp`, last: `One-${RUN}`, dob: '1990-05-15', phone: `+2519${RUN}001`, email: `qa.emp1.${RUN}@qa.local`, position: state.pos1 });
        evidence.emp1 = r;
        fs.writeFileSync(path.join(__dirname, 'results', '10-hr-emp1-register.json'), JSON.stringify(r, null, 2));
        if (r.row) state.emp1 = r.row;
        const summary = `POST ${r.post?.status} in ${r.ms}ms; landed ${r.url.replace(BASE, '')}; UI: ${r.serverError ? 'error page "' + r.bodyHead.slice(0, 120) + '"' : 'no error page'}; ref=${r.errRef}; row=${r.row ? r.row.employee_number + ' assignment=' + r.row.current_assignment_id : 'NONE'}; preview=${r.preview.code}; logErrors=${r.logErrors.length}; errors=${r.errs.join(' | ')}; msgs=${r.msgs.join(' | ')}`;
        if (!r.row) throw new Error('employee not created: ' + summary + ' LOG: ' + r.logErrors.join(' || ').slice(0, 700));
        if (r.serverError) throw new Error('server error page shown although row exists: ' + summary);
        if (r.preview.code && r.row.employee_number !== r.preview.code) throw new Error(`number mismatch preview=${r.preview.code} stored=${r.row.employee_number}; ` + summary);
        return summary;
    }, page);

    await R.step('Register employee #2 (QA-Emp Two) in position "QA Analyst 2" — second registration behaviour', async () => {
        const r = await registerEmployee({ first: `QA-Emp`, last: `Two-${RUN}`, dob: '1988-01-02', phone: `+2519${RUN}002`, position: state.pos2 });
        evidence.emp2 = r;
        fs.writeFileSync(path.join(__dirname, 'results', '10-hr-emp2-register.json'), JSON.stringify(r, null, 2));
        if (r.row) state.emp2 = r.row;
        const summary = `POST ${r.post?.status} in ${r.ms}ms; landed ${r.url.replace(BASE, '')}; UI: ${r.serverError ? 'error page' : 'no error page'}; ref=${r.errRef}; row=${r.row ? r.row.employee_number : 'NONE'}; logErrors=${r.logErrors.length}; errors=${r.errs.join(' | ')}; msgs=${r.msgs.join(' | ')}`;
        if (!r.row) throw new Error('employee not created: ' + summary + ' LOG: ' + r.logErrors.join(' || ').slice(0, 400));
        if (r.serverError) throw new Error('server error shown: ' + summary);
        return summary;
    }, page);

    await R.step('FALLBACK (test-data only): create QA-Emp One/Two directly via tinker when UI registration failed, so later journeys can run', async () => {
        if (state.emp1 && state.emp2) return 'not needed (UI registration succeeded)';
        const hv = H.sql(`select id from hierarchy_versions where status='published' limit 1`)[0].id;
        const code = `
            $mk = function (string $n, string $last, string $posId, string $dob, string $phone) {
                $e = \\App\\Models\\Employee::create(['employee_number' => 'QA-EMP-${RUN}-' . $n, 'first_name' => 'QA-Emp', 'last_name' => $last, 'full_name' => 'QA-Emp ' . $last, 'date_of_birth' => $dob, 'gender' => 'male', 'phone' => $phone, 'status' => \\App\\Enums\\EmployeeStatus::Active, 'employment_type' => 'permanent', 'is_demo' => true]);
                $a = \\App\\Models\\EmployeeAssignment::create(['employee_id' => $e->id, 'organization_id' => '${state.org1.id}', 'organization_unit_id' => '${state.unit1.id}', 'position_id' => $posId, 'hierarchy_version_id' => '${hv}', 'assignment_status' => \\App\\Enums\\AssignmentStatus::Active, 'effective_from' => now()->toDateString(), 'is_current' => true]);
                $e->update(['current_assignment_id' => $a->id]);
                echo $e->id, PHP_EOL;
            };
            $mk('1', 'One-${RUN}', '${state.pos1.id}', '1990-05-15', '+2519${RUN}001');
            $mk('2', 'Two-${RUN}', '${state.pos2.id}', '1988-01-02', '+2519${RUN}002');
        `;
        const out = tinker(code);
        const rows = H.sql(`select id, employee_number, full_name, current_assignment_id from employees where employee_number in ('QA-EMP-${RUN}-1','QA-EMP-${RUN}-2') order by employee_number`);
        if (rows.length !== 2) throw new Error('fallback failed: ' + out.slice(0, 300));
        state.emp1 = rows[0]; state.emp2 = rows[1];
        H.saveState(state);
        return `created ${rows.map((r) => r.employee_number).join(', ')} (NOT via UI; numbers hand-assigned)`;
    }, page);

    await R.step('Registration side effects: assignment, status history, audit log, feedback token, queued sync job', async () => {
        if (!state.emp1) throw new Error('no employee #1');
        const id = state.emp1.id;
        const a = H.sql(`select organization_id, position_id, is_current, assignment_status from employee_assignments where employee_id='${id}'`);
        const hist = H.sql(`select status from employment_status_histories where employee_id='${id}'`);
        const audit = H.sql(`select event_type from audit_logs where auditable_id='${id}'`);
        const token = H.sql(`select count(*)::int as n from employee_feedback_tokens where employee_id='${id}'`)[0].n;
        const jobs = H.sql(`select count(*)::int as n from jobs where payload like '%SyncEmployeeCommunicationMemberships%'`)[0].n;
        const locks = H.sql(`select key from cache_locks where key like '%${id}%'`);
        const failed = H.sql(`select count(*)::int as n from failed_jobs`)[0].n;
        if (a.length !== 1 || !a[0].is_current || a[0].position_id !== state.pos1.id) throw new Error('assignment wrong: ' + JSON.stringify(a));
        if (!audit.includes('employee_created') && !/^QA-EMP-/.test(state.emp1.employee_number)) throw new Error('no employee_created audit row: ' + audit.join(','));
        return `assignment ok (${a[0].organization_id === state.org1.id ? 'QA-ORG-1' : a[0].organization_id}); status_history=${hist.length}; audit=${audit.join(',')}; feedback_tokens=${token}; pending sync jobs=${jobs}; unique-locks=${locks.length}; failed_jobs=${failed}`;
    }, page);

    await R.step('Employee show page displays number, names and current organization/position', async () => {
        const t0 = Date.now();
        await visit(page, `/employees/${state.emp1.id}`);
        timings['employees.show'] = Date.now() - t0;
        const body = await page.locator('body').innerText();
        for (const needle of [state.emp1.employee_number, 'QA-Emp', state.pos1.title_en]) {
            if (!body.includes(needle)) throw new Error('missing on show page: ' + needle);
        }
        await shot(page, 'hr-emp1-show');
        return `${timings['employees.show']}ms`;
    }, page);

    await R.step('Edit employee #1 phone + middle name; persisted in DB and visible after reload', async () => {
        await visit(page, `/employees/${state.emp1.id}/edit`);
        const newPhone = `+2519${RUN}111`;
        await page.locator('#phone').fill(newPhone);
        await page.locator('#middle_name').fill('Edited');
        const mark = H.logMark();
        await submitAndLeave(page, /\/edit$/, 30000);
        const post = lastPost(new RegExp(`/employees/${state.emp1.id}$`));
        const row = H.sql(`select phone, middle_name, full_name from employees where id='${state.emp1.id}'`)[0];
        const errs = await H.fieldErrors(page);
        if (row.phone !== newPhone || row.middle_name !== 'Edited') throw new Error(`not persisted: ${JSON.stringify(row)}; POST=${post?.status}; errors=${errs.join(' | ')}; log=${H.logErrorsSince(mark).join(' || ')}`);
        await visit(page, `/employees/${state.emp1.id}`);
        const body = await page.locator('body').innerText();
        if (!body.includes(newPhone)) throw new Error('new phone not shown after reload');
        const audit = H.sql(`select event_type from audit_logs where auditable_id='${state.emp1.id}' order by created_at`).map((r) => r.event_type);
        await shot(page, 'hr-emp1-edited');
        return `POST ${post?.status}; landed ${page.url().replace(BASE, '')}; full_name="${row.full_name}"; audit=${audit.join(',')}`;
    }, page);

    await R.step('Employees list search finds QA-Emp by name and by employee number', async () => {
        await visit(page, `/employees?search=${encodeURIComponent(state.emp1.employee_number)}`);
        await page.waitForTimeout(500);
        const body = await page.locator('table').innerText().catch(() => '');
        if (!body.includes(state.emp1.employee_number)) throw new Error('not found by number');
        await visit(page, `/employees?search=QA-Emp`);
        const rows = await page.locator('table tbody tr').count();
        if (rows < 2) throw new Error('expected both QA employees, rows=' + rows);
        return `rows=${rows}`;
    }, page);

    // ───────────────────────── Duplicate detection ─────────────────────────
    await R.step('Duplicate detection: registering the same names + DOB as employee #1 (into a third position)', async () => {
        // need a free position: create one quickly through the UI
        await visit(page, `/positions/create?organization_id=${state.org1.id}&organization_unit_id=${state.unit1.id}`);
        await page.locator('#occupation_id').selectOption({ index: 1 });
        await page.locator('#title_en').fill(`QA Dup Seat ${RUN}`);
        await page.waitForTimeout(800);
        await submitAndLeave(page, /\/positions\/create/);
        const pos = H.sql(`select id, job_position_code, title_en from positions where title_en='QA Dup Seat ${RUN}' and deleted_at is null`)[0];
        if (!pos) throw new Error('could not create helper position');
        state.posDup = pos;
        const r = await registerEmployee({ first: `QA-Emp`, last: `One-${RUN}`, dob: '1990-05-15', position: pos });
        evidence.dup = r;
        fs.writeFileSync(path.join(__dirname, 'results', '10-hr-dup-register.json'), JSON.stringify(r, null, 2));
        const rows = H.sql(`select id, employee_number, created_at from employees where first_name='QA-Emp' and last_name='One-${RUN}' order by created_at`);
        const flags = rows.length > 1 ? H.sql(`select employee_id, matched_employee_id, matched_fields, risk_score, status from employee_duplicate_flags where employee_id='${rows[rows.length - 1].id}'`) : [];
        if (rows.length > 1) state.empDup = rows[rows.length - 1];
        let shown = '';
        if (state.empDup) {
            await visit(page, `/employees/${state.empDup.id}`);
            const body = await page.locator('body').innerText();
            shown = /Duplicate/i.test(body) ? (body.match(/Duplicate[^\n]*\n[^\n]*\n[^\n]*/i) || [''])[0].replace(/\s+/g, ' ') : 'NO duplicate warning on show page';
            await shot(page, 'hr-emp-dup-show');
        }
        const uiResult = `UI: POST ${r.post?.status}${r.serverError ? ' error page ref=' + r.errRef : ''}; same-name rows=${rows.length}; errors=${r.errs.join(' | ')}; msgs=${r.msgs.join(' | ')}; flags=${JSON.stringify(flags)}; show-page: ${shown}`;
        if (rows.length <= 1) throw new Error('duplicate registration did not reach duplicate detection — ' + uiResult);
        return uiResult;
    }, page);

    await R.step('SUPPLEMENTARY: duplicate detection logic exercised directly (tinker) — flag row + show page warning + list indicator', async () => {
        if (state.empDup) return 'not needed (UI duplicate registration worked)';
        const hv = H.sql(`select id from hierarchy_versions where status='published' limit 1`)[0].id;
        const pos = state.posDup;
        const out = tinker(`
            $e = \\App\\Models\\Employee::create(['employee_number' => 'QA-EMP-${RUN}-DUP', 'first_name' => 'QA-Emp', 'last_name' => 'One-${RUN}', 'full_name' => 'QA-Emp One-${RUN}', 'date_of_birth' => '1990-05-15', 'gender' => 'male', 'status' => \\App\\Enums\\EmployeeStatus::Active, 'is_demo' => true]);
            $a = \\App\\Models\\EmployeeAssignment::create(['employee_id' => $e->id, 'organization_id' => '${state.org1.id}', 'organization_unit_id' => '${state.unit1.id}', 'position_id' => '${pos.id}', 'hierarchy_version_id' => '${hv}', 'assignment_status' => \\App\\Enums\\AssignmentStatus::Active, 'effective_from' => now()->toDateString(), 'is_current' => true]);
            $e->update(['current_assignment_id' => $a->id]);
            $flag = app(\\App\\Actions\\Employees\\DetectDuplicateEmployeeAction::class)->execute($e);
            echo $e->id, '|', $flag?->matched_employee_id, '|', json_encode($flag?->matched_fields), PHP_EOL;
        `);
        const emp = H.sql(`select id, employee_number from employees where employee_number='QA-EMP-${RUN}-DUP'`)[0];
        if (!emp) throw new Error('tinker failed: ' + out.slice(0, 300));
        state.empDup = emp;
        const flags = H.sql(`select matched_employee_id, matched_fields, risk_score, status from employee_duplicate_flags where employee_id='${emp.id}'`);
        await visit(page, `/employees/${emp.id}`);
        const body = await page.locator('body').innerText();
        const warn = (body.match(/Duplicate[^\n]*\n[^\n]*\n[^\n]*/i) || ['NO duplicate warning on show page'])[0].replace(/\s+/g, ' ');
        await shot(page, 'hr-emp-dup-show');
        if (!flags.length) throw new Error('DetectDuplicateEmployeeAction created no flag (full_name + date_of_birth match expected)');
        if (!/Duplicate/i.test(body)) throw new Error('flag exists but show page shows no duplicate warning');
        return `flag=${JSON.stringify(flags[0])} (matched ${flags[0].matched_employee_id === state.emp1.id ? 'QA-Emp One' : flags[0].matched_employee_id}); show page: "${warn.slice(0, 160)}"`;
    }, page);

    await R.step('Employees list marks the duplicate with a flag indicator', async () => {
        if (!state.empDup) return 'skipped (duplicate was not created)';
        await visit(page, `/employees?search=${encodeURIComponent(state.empDup.employee_number)}`);
        await page.waitForTimeout(500);
        const row = page.locator('table tbody tr').first();
        const html = await row.innerHTML();
        const text = await row.innerText();
        const flagged = /duplicate|flag/i.test(html);
        await shot(page, 'hr-emp-dup-list');
        return `flag indicator present=${flagged}; row text="${text.replace(/\s+/g, ' ').slice(0, 160)}"`;
    }, page);

    // ───────────────────────── CSV import ─────────────────────────
    const csvDir = path.join(__dirname, 'results', 'csv');
    fs.mkdirSync(csvDir, { recursive: true });
    const badCsv = path.join(csvDir, 'qa-bad-headers.csv');
    fs.writeFileSync(badCsv, 'name,surname,org\nQA-Bad,Headers,SC-01\n');
    const validCsv = path.join(csvDir, 'qa-valid.csv');

    await R.step('CSV import page renders with template download and upload area', async () => {
        const t0 = Date.now();
        await visit(page, '/employees/import');
        timings['employees.import'] = Date.now() - t0;
        const body = await page.locator('body').innerText();
        if (!/Validate CSV/i.test(body)) throw new Error('no validate button');
        await shot(page, 'hr-import-page');
        return `${timings['employees.import']}ms`;
    }, page);

    await R.step('CSV import: file with wrong headers is rejected with row-level "column is required" errors', async () => {
        await page.setInputFiles('input#file', badCsv);
        const mark = H.logMark();
        await page.locator('button:has-text("Validate CSV")').click();
        await page.waitForLoadState('networkidle');
        await page.waitForTimeout(1500);
        const post = lastPost(/\/employees\/import$/);
        const body = await page.locator('body').innerText();
        const errs = await H.fieldErrors(page);
        await shot(page, 'hr-import-bad');
        const batch = H.sql(`select file_name, total_rows, valid_rows, failed_rows, status from employee_import_batches order by created_at desc limit 1`)[0];
        const rowErrs = H.sql(`select r.errors from employee_import_batch_rows r join employee_import_batches b on b.id=r.batch_id where b.file_name='qa-bad-headers.csv' order by r.row_number limit 1`);
        const logErrors = H.logErrorsSince(mark);
        if (post?.status >= 500 || logErrors.length) throw new Error(`server error: POST ${post?.status}; ${logErrors.join(' || ')}`);
        const msgLine = (body.match(/[^\n]*(column is required|required|missing|invalid)[^\n]*/i) || [''])[0];
        if (!/required|missing|invalid|not csv|error/i.test(body + errs.join(' '))) throw new Error('no validation message visible; POST ' + post?.status);
        return `POST ${post?.status}; batch=${JSON.stringify(batch)}; row errors=${JSON.stringify(rowErrs[0]?.errors || '')}; visible: "${msgLine.trim().slice(0, 200)}"`;
    }, page);

    await R.step('CSV import: valid single-row file validates (preview) and can be confirmed; employee number generated', async () => {
        // a free position for the imported employee
        await visit(page, `/positions/create?organization_id=${state.org1.id}&organization_unit_id=${state.unit1.id}`);
        await page.locator('#occupation_id').selectOption({ index: 1 });
        await page.locator('#title_en').fill(`QA Import Seat ${RUN}`);
        await page.waitForTimeout(800);
        await submitAndLeave(page, /\/positions\/create/);
        const pos = H.sql(`select id, job_position_code from positions where title_en='QA Import Seat ${RUN}' and deleted_at is null`)[0];
        if (!pos) throw new Error('could not create import position');
        const header = 'employee_number,first_name,father_name,grandfather_name,gender,phone,email,organization_code,organization_unit_code,position_code,employment_status,assignment_start_date,nationality,employment_type';
        const line = `,QA-Import,Row-${RUN},Grand,female,+2519${RUN}777,qa.import.${RUN}@qa.local,${state.org1.code},${state.unit1.code},${pos.job_position_code},active,2026-10-20,Ethiopian,permanent`;
        fs.writeFileSync(validCsv, header + '\n' + line + '\n');
        await visit(page, '/employees/import');
        await page.setInputFiles('input#file', validCsv);
        let mark = H.logMark();
        await page.locator('button:has-text("Validate CSV")').click();
        await page.waitForLoadState('networkidle');
        await page.waitForTimeout(1500);
        const post = lastPost(/\/employees\/import$/);
        const batch = H.sql(`select id, total_rows, valid_rows, failed_rows, status from employee_import_batches where file_name='qa-valid.csv' order by created_at desc limit 1`)[0];
        const rowErrs = H.sql(`select errors, status from employee_import_batch_rows where batch_id='${batch?.id}'`);
        await shot(page, 'hr-import-valid-preview');
        if (!batch) throw new Error('no batch row; POST ' + post?.status);
        if (batch.valid_rows !== 1) throw new Error(`row not valid: ${JSON.stringify(batch)} ${JSON.stringify(rowErrs)}`);
        const confirmBtn = page.locator('button:has-text("Confirm Import")');
        if (!(await confirmBtn.count())) throw new Error('no Confirm Import button after valid preview');
        mark = H.logMark();
        await confirmBtn.click();
        await page.waitForLoadState('networkidle');
        await page.waitForTimeout(2500);
        const confirmPost = lastPost(/\/employees\/import\/[0-9a-f-]+\/confirm$/);
        const logErrors = H.logErrorsSince(mark);
        const emp = H.sql(`select id, employee_number, full_name from employees where first_name='QA-Import' order by created_at desc limit 1`)[0];
        const batchAfter = H.sql(`select status from employee_import_batches where id='${batch.id}'`)[0];
        const msgs = await H.visibleMessages(page);
        const bodyAfter = await page.locator('body').innerText();
        const flashLine = (bodyAfter.match(/[^\n]*(fail|error|could not|imported|success)[^\n]*/i) || [''])[0].trim();
        await shot(page, 'hr-import-confirmed');
        if (emp) state.empImport = emp;
        if (!emp) throw new Error(`import confirm did not create employee: POST ${confirmPost?.status} -> ${confirmPost?.location || ''}; batch=${batchAfter?.status}; visible="${flashLine.slice(0, 200)}"; msgs=${msgs.join(' | ')}; log=${logErrors.join(' || ').slice(0, 500)}`);
        return `validate POST ${post?.status}, preview valid=${batch.valid_rows}; confirm POST ${confirmPost?.status}; employee=${emp.employee_number} "${emp.full_name}"; batch=${batchAfter?.status}; logErrors=${logErrors.length}`;
    }, page);

    // ───────────────────────── Amharic leak check ─────────────────────────
    await R.step('Amharic UI (uiLocale=am): employees list and create pages — English leaks', async () => {
        const c = await browser.newContext({ viewport: { width: 1366, height: 900 } });
        const p = await newPageIn(c, 'am');
        await login(p, ...SYSADMIN);
        const out = {};
        for (const [name, url] of [['index', '/employees'], ['create', '/employees/create']]) {
            await p.goto(BASE + url, { waitUntil: 'networkidle' });
            await p.waitForTimeout(800);
            const text = await p.locator('main, body').first().innerText();
            const amharic = (text.match(/[ሀ-፿]/g) || []).length;
            out[name] = { amharicChars: amharic, leaks: H.latinLeaks(text).slice(0, 40) };
            await shot(p, `hr-emp-am-${name}`);
        }
        await c.close();
        fs.writeFileSync(path.join(__dirname, 'results', '10-hr-am-leaks.json'), JSON.stringify(out, null, 2));
        if (out.index.amharicChars < 50) throw new Error('employees list did not render in Amharic');
        return `index: ${out.index.leaks.length} latin words [${out.index.leaks.slice(0, 12).join(', ')}]; create: ${out.create.leaks.length} [${out.create.leaks.slice(0, 12).join(', ')}]`;
    }, page);

    // ───────────────────────── Authorization (HR Officer scope) ─────────────────────────
    await R.step('HR Officer scope from DB: subtree of BUR-PSHRDB; QA employees are outside it', async () => {
        const scope = H.sql(`select o.code, s.scope_type, s.is_active from user_organization_scopes s join users u on u.id=s.user_id join organizations o on o.id=s.organization_id where u.email='${HR[0]}'`);
        const inScope = H.sql(`select count(*)::int as n from organization_closure_paths where ancestor_organization_id=(select organization_id from user_organization_scopes s join users u on u.id=s.user_id where u.email='${HR[0]}' limit 1) and descendant_organization_id='${state.org1.id}'`)[0].n;
        if (inScope !== 0) throw new Error('QA-ORG-1 unexpectedly inside HR scope');
        return JSON.stringify(scope);
    }, page);

    await R.step('HR Officer: opening QA employee #1 by URL is refused (403), no data shown', async () => {
        const c = await browser.newContext({ viewport: { width: 1366, height: 900 } });
        const p = await newPageIn(c);
        await login(p, ...HR);
        const resp = await p.goto(`${BASE}/employees/${state.emp1.id}`, { waitUntil: 'networkidle' });
        const status = resp.status();
        const body = await p.locator('body').innerText();
        await shot(p, 'hr-officer-emp-forbidden');
        const leaked = body.includes(state.emp1.employee_number) || /QA-Emp/.test(body);
        const r2 = await p.goto(`${BASE}/employees/${state.emp1.id}/edit`, { waitUntil: 'networkidle' });
        const r3 = await p.goto(`${BASE}/organizations/${state.org1.id}`, { waitUntil: 'networkidle' });
        const r4 = await p.goto(`${BASE}/employees`, { waitUntil: 'networkidle' });
        const listBody = await p.locator('body').innerText();
        const listLeak = /QA-Emp/.test(listBody);
        await c.close();
        if (leaked) throw new Error(`employee data visible to HR Officer (status ${status})`);
        if (status !== 403 && !/\/dashboard|\/employees$/.test(p.url())) throw new Error(`unexpected status ${status} url ${p.url()}`);
        if (listLeak) throw new Error('QA employees listed for HR Officer registry');
        return `show=${status}, edit=${r2.status()}, org show=${r3.status()}, list=${r4.status()} (QA rows in list: ${listLeak})`;
    }, page);

    await R.step('HR Officer: /users is forbidden (403) and /users/create too', async () => {
        const c = await browser.newContext({ viewport: { width: 1366, height: 900 } });
        const p = await newPageIn(c);
        await login(p, ...HR);
        const r1 = await p.goto(`${BASE}/users`, { waitUntil: 'networkidle' });
        const body = await p.locator('body').innerText();
        await shot(p, 'hr-officer-users-forbidden');
        const r2 = await p.goto(`${BASE}/users/create`, { waitUntil: 'networkidle' });
        await c.close();
        if (r1.status() !== 403) throw new Error(`/users returned ${r1.status()} at ${p.url()}`);
        if (/qa\.sysadmin1@demo\.local/.test(body)) throw new Error('user list content leaked in 403 page');
        return `/users=${r1.status()}, /users/create=${r2.status()}`;
    }, page);

    H.saveState(state);
    const slow = Object.entries(timings).filter(([, ms]) => ms > 3000);
    console.log('timings', JSON.stringify(timings), 'slow:', JSON.stringify(slow), 'dialogs:', JSON.stringify(dialogs));
    fs.writeFileSync(path.join(__dirname, 'results', '10-hr-employees-posts.json'), JSON.stringify(posts.filter((p) => p.status >= 400), null, 2));
    await browser.close();
    R.save();
})().catch((e) => { console.error('FATAL', e); process.exit(1); });
