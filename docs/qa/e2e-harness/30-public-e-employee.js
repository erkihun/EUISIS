'use strict';
/* Journey 6 (+7): employee self-service portal as qa.employee@demo.local (Employee role, linked to EMP-0001),
   then the Grievance Officer processes the grievance. */
const { BASE, Recorder, newBrowser, newPageIn, login, visit, shot } = require('./lib');
const fs = require('fs');
const path = require('path');
const { execSync } = require('child_process');

const STATE_FILE = path.join(__dirname, 'results', '30-public-state.json');
const state = JSON.parse(fs.readFileSync(STATE_FILE, 'utf8'));
const FIX = path.join(__dirname, 'fixtures');
const XSS1 = '<img src=x onerror=alert(1)>';
const XSS2 = '"><script>window.__xss=1</script>';
const sql = (q) => execSync(`psql -h /tmp -p 54329 -U postgres -d euisis_app -At -c "${q.replace(/"/g, '\\"')}"`).toString().trim();
const today = new Date().toISOString().slice(0, 10);
let activityDate = today;
const stamp = Date.now().toString().slice(-6);

/** Put HTML into a TinyMCE field through its API and make sure React saw the change. */
async function setRichText(page, editorId, html) {
    // TinyMCE runs in inline mode here (contenteditable, no iframe).
    await page.waitForFunction((id) => window.tinymce && window.tinymce.get(id) && window.tinymce.get(id).initialized, editorId, { timeout: 20000 });
    await page.evaluate(({ id, html }) => { const ed = window.tinymce.get(id); ed.focus(); ed.setContent(html); ed.fire('change'); ed.fire('keyup'); ed.save(); }, { id: editorId, html });
    const body = page.locator(`#${editorId}[contenteditable], [id="${editorId}"] [contenteditable], [contenteditable="true"]`).first();
    await body.click({ position: { x: 5, y: 5 } }).catch(() => {});
    await page.keyboard.press('End');
    await page.keyboard.type(' ');
    await page.keyboard.press('Backspace');
    await page.waitForTimeout(400);
}

(async () => {
    const R = new Recorder('30-public-e-employee');
    const { browser, page } = await newBrowser();
    R.attach(page);
    const dialogs = [];
    page.on('dialog', async (d) => { if (d.type() === 'beforeunload') { await d.accept(); return; } dialogs.push(d.message()); await d.dismiss(); });

    await R.step('Employee user (role Employee, users.employee_id → EMP-0001) logs in and lands on /my-portal', async () => {
        const url = await login(page, 'qa.employee@demo.local', 'password');
        await shot(page, 'emp-portal-home');
        const txt = await page.locator('body').innerText();
        if (!/my-portal/.test(url)) throw new Error('landed on ' + url);
        if (!/Demo Employee/.test(txt)) throw new Error('employee name not shown');
        return `landed ${url.replace(BASE, '')}`;
    }, page);

    await R.step('Employee cannot open the admin dashboard or the employee register', async () => {
        const a = await page.goto(`${BASE}/dashboard`, { waitUntil: 'networkidle' });
        const b = await page.goto(`${BASE}/employees`, { waitUntil: 'networkidle' });
        return `/dashboard → ${a.status()} (${page.url().replace(BASE, '').slice(0, 30)}); /employees → ${b.status()}`;
    }, page);

    for (const [u, label] of [['/my-portal/services', 'My Services'], ['/my-portal/profile', 'Profile'], ['/my-portal/employment', 'Employment'], ['/my-portal/documents', 'Documents'], ['/my-portal/requests', 'Requests'], ['/my-portal/security', 'Security']]) {
        await R.step(`Portal page ${label} ${u} renders`, async () => {
            const t0 = Date.now();
            const r = await visit(page, u);
            const txt = await page.locator('main').innerText();
            await shot(page, `emp${u.replace(/[^a-z]+/g, '-')}`);
            return `status=${r.status} ${Date.now() - t0}ms len=${txt.length}`;
        }, page);
    }

    // ── Daily activity ──
    let logId = null;
    let reusedLog = false;
    await R.step('Daily Activity entry: add an activity with TinyMCE formatted text + XSS payloads, save as draft', async () => {
        const existing = sql(`select l.id||'|'||l.status from daily_activity_logs l join employees e on e.id=l.employee_id join daily_activity_items i on i.daily_activity_log_id=l.id where e.employee_number='EMP-0001' and l.activity_date='${today}' and i.title like 'QA- daily activity%' order by l.created_at desc limit 1`);
        if (existing && existing.endsWith('submitted')) {
            // Today's day was already registered AND submitted by an earlier run of this script (days are locked after submission;
            // other days are "not assigned" for this demo employee), so the creation evidence is the earlier run + the DB row.
            logId = existing.split('|')[0]; reusedLog = true;
            const item = sql(`select title||' || '||coalesce(description,'') from daily_activity_items where daily_activity_log_id='${logId}' order by created_at desc limit 1`);
            return `REUSED earlier run's submitted log ${logId} (day locked): stored="${item.slice(0, 300)}"`;
        }
        await visit(page, '/my-portal/daily-activity');
        await page.waitForTimeout(500);
        let editable = await page.locator('#item-0-title').count() > 0 || await page.getByRole('button', { name: /Add activity/ }).isEnabled().catch(() => false);
        if (!editable || sql(`select status from daily_activity_logs l join employees e on e.id=l.employee_id where e.employee_number='EMP-0001' and l.activity_date='${activityDate}'`) === 'submitted') {
            // Today's day was already submitted by an earlier run: register yesterday instead (late entry).
            activityDate = new Date(Date.now() - 86400000).toISOString().slice(0, 10);
            await visit(page, `/my-portal/daily-activity?date=${activityDate}`);
            await page.waitForTimeout(500);
        }
        if (await page.locator('#item-0-title').count() === 0) {
            await page.getByRole('button', { name: /Add activity/ }).click();
        }
        await page.fill('#item-0-title', `QA- daily activity ${stamp} ${XSS2}`);
        await setRichText(page, 'item-0-description', `<p><strong>QA bold</strong> and <em>italic</em></p><ul><li>item one</li><li>item two ${XSS1}</li></ul><p>${XSS2.replace(/</g, '&lt;').replace(/>/g, '&gt;')}</p><p>raw: ${XSS1}</p>`);
        await page.fill('#item-0-output', `QA output ${XSS1}`);
        await page.selectOption('#item-0-progress', 'completed');
        if (await page.locator('#late_reason').count()) await page.fill('#late_reason', 'QA- late entry (test)');
        await shot(page, 'emp-daily-activity-filled');
        const respP = page.waitForResponse((r) => r.request().method() === 'POST' && r.url().includes('/my-portal/daily-activity/'), { timeout: 30000 }).catch(() => null);
        await page.getByRole('button', { name: /^Save draft$/ }).click();
        const resp = await respP;
        await page.waitForLoadState('networkidle');
        await page.waitForTimeout(1000);
        const txt = await page.locator('body').innerText();
        const errs = await page.locator('.text-red-700, [role=alert]').allInnerTexts();
        logId = sql(`select l.id from daily_activity_logs l join employees e on e.id=l.employee_id where e.employee_number='EMP-0001' and l.activity_date='${activityDate}' order by l.created_at desc limit 1`);
        if (!logId) throw new Error(`no log row; POST=${resp?.status()} errors=${JSON.stringify(errs)} text=${txt.replace(/\s+/g, ' ').slice(0, 200)}`);
        const item = sql(`select title||' || '||coalesce(description,'') from daily_activity_items where daily_activity_log_id='${logId}' order by created_at desc limit 1`);
        return `date=${activityDate} POST=${resp?.status()} flash=${/saved as draft|Saved/i.test(txt)} log=${logId} stored="${item.slice(0, 300)}"`;
    }, page);

    await R.step('Daily activity: stored description is server-sanitised (no onerror/script), formatting kept', async () => {
        const desc = sql(`select coalesce(description,'') from daily_activity_items where daily_activity_log_id='${logId}' order by created_at desc limit 1`);
        const title = sql(`select title from daily_activity_items where daily_activity_log_id='${logId}' order by created_at desc limit 1`);
        if (/onerror|<script/i.test(desc)) throw new Error('unsafe markup stored: ' + desc);
        if (!/<strong>|<em>|<ul>/.test(desc)) throw new Error('formatting lost: ' + desc);
        return `description="${desc.slice(0, 200)}" | title(plain, raw)="${title.slice(0, 80)}"`;
    }, page);

    await R.step('Daily activity: reload entry page — persisted, no XSS execution; then submit the day', async () => {
        await visit(page, `/my-portal/daily-activity?date=${activityDate}`);
        await page.waitForTimeout(800);
        const x = await page.evaluate(() => window.__xss);
        const titleVal = reusedLog ? (await page.locator('main').innerText()) : await page.locator('#item-0-title').inputValue().catch(() => '');
        await shot(page, 'emp-daily-activity-reload');
        if (x || dialogs.length) throw new Error(`XSS executed: __xss=${x} dialogs=${dialogs.length}`);
        if (!titleVal.includes('QA- daily activity')) throw new Error('item not shown after reload: title=' + titleVal.slice(0, 200));
        if (reusedLog) return `read-only submitted day shows the item; __xss=undefined; status=${sql(`select status from daily_activity_logs where id='${logId}'`)}`;
        const submitBtn = page.getByRole('button', { name: /Submit daily activity/ });
        if (await submitBtn.count() === 0) return 'persisted; no submit button offered';
        await submitBtn.click();
        await page.waitForTimeout(500);
        const confirm = page.locator('[role=dialog], [role=alertdialog]').getByRole('button', { name: /Confirm|Submit|Yes|OK/i }).last();
        if (await confirm.count()) await confirm.click();
        await page.waitForLoadState('networkidle');
        await page.waitForTimeout(1200);
        const status = sql(`select status from daily_activity_logs where id='${logId}'`);
        await shot(page, 'emp-daily-activity-submitted');
        const errs = await page.locator('.text-red-700, [role=alert]').allInnerTexts();
        return `persisted; after submit status=${status} errors=${JSON.stringify(errs).slice(0, 200)}`;
    }, page);

    await R.step('Daily activity history and calendar pages render the entry (XSS inert)', async () => {
        await visit(page, '/my-portal/daily-activity/history');
        const h = await page.locator('main').innerText();
        await visit(page, '/my-portal/daily-activity/calendar');
        const x = await page.evaluate(() => window.__xss);
        if (x || dialogs.length) throw new Error('XSS executed');
        return `history mentions entry=${h.includes('QA- daily activity')}`;
    }, page);

    // ── Grievance application ──
    let grievanceId = null;
    await R.step('Grievance form: PDF > 2 MB is refused client-side with "PDF only, 2 MB at most"', async () => {
        await visit(page, '/my-portal/grievances/create');
        await page.locator('input[type=file]').setInputFiles([path.join(FIX, 'qa-2500kb.pdf')]);
        await page.waitForTimeout(400);
        const txt = await page.locator('main').innerText();
        const m = txt.match(/Not attached[^\n]*/);
        if (!m) throw new Error('no client-side rejection: ' + txt.slice(0, 200));
        return m[0];
    }, page);

    await R.step('Grievance form: EXE renamed .pdf is refused by the server (mimetypes), no 500', async () => {
        await visit(page, '/my-portal/grievances/create');
        await page.locator('form input').first().fill(`QA- grievance exe probe ${stamp}`);
        await page.locator('form select').first().selectOption({ index: 1 });
        await setRichText(page, 'grievance-description', '<p>QA exe probe</p>');
        await page.locator('input[type=file]').setInputFiles([path.join(FIX, 'qa-exe-renamed.pdf')]);
        await page.waitForTimeout(400);
        const clientMsg = (await page.locator('main').innerText()).match(/Not attached[^\n]*/);
        const respP = page.waitForResponse((r) => r.request().method() === 'POST' && r.url().includes('/my-portal/grievances'), { timeout: 30000 }).catch(() => null);
        await page.getByRole('button', { name: /^Save draft$/ }).click();
        const resp = await respP;
        await page.waitForLoadState('networkidle');
        await page.waitForTimeout(800);
        const txt = await page.locator('main').innerText();
        const errs = await page.locator('.text-red-700, .text-red-600, [role=alert]').allInnerTexts();
        const stored = sql(`select count(*) from grievances where subject like 'QA- grievance exe probe ${stamp}%'`);
        await shot(page, 'emp-grievance-exe');
        if (resp && resp.status() >= 500) throw new Error('500');
        if (stored !== '0' && !clientMsg) {
            const ev = sql(`select count(*) from grievance_evidence ev join grievances g on g.id=ev.grievance_id where g.subject like 'QA- grievance exe probe ${stamp}%'`);
            if (ev !== '0') throw new Error('disguised exe stored as evidence');
            return `draft stored without the file (evidence rows=${ev}); POST=${resp?.status()} errors=${JSON.stringify(errs).slice(0, 200)}`;
        }
        return `clientSide=${clientMsg ? clientMsg[0] : 'none'} POST=${resp?.status()} storedDrafts=${stored} errors=${JSON.stringify(errs).slice(0, 200)} url=${page.url().replace(BASE, '')}`;
    }, page);

    await R.step('Grievance: create with formatted description + XSS, 100 KB PDF, all Art. 32 fields → save draft', async () => {
        await visit(page, '/my-portal/grievances/create');
        const inputs = page.locator('form input:not([type=file]):not([type=checkbox]):not([type=date]):not([type=hidden])');
        await inputs.nth(0).fill(`QA- grievance ${stamp} ${XSS2}`); // subject
        await page.locator('form select').first().selectOption({ index: 1 });
        const dates = page.locator('form input[type=date]');
        await dates.nth(0).fill(today); // incident date
        await setRichText(page, 'grievance-description', `<p><strong>QA grievance</strong> description with <em>formatting</em></p><ol><li>step one</li><li>step two</li></ol><p>${XSS1}</p><p>&lt;script&gt;window.__xss=1&lt;/script&gt;</p>`);
        const areas = page.locator('form textarea');
        await areas.nth(0).fill(`QA respondent ${XSS1}`);
        await areas.nth(1).fill(`QA remedy ${XSS2}`);
        await inputs.nth(1).fill('QA Supervisor'); // immediate supervisor
        if (await dates.count() > 1) await dates.nth(1).fill(today); // written issue raised at
        await inputs.nth(2).fill('QA Address, Addis Ababa'); // applicant address (after supervisor)
        await page.locator('input[type=file]').setInputFiles([path.join(FIX, 'qa-100kb.pdf')]);
        await page.waitForTimeout(400);
        await page.locator('form input[type=checkbox]').last().check();
        await shot(page, 'emp-grievance-form-filled');
        const respP = page.waitForResponse((r) => r.request().method() === 'POST' && r.url().includes('/my-portal/grievances'), { timeout: 30000 }).catch(() => null);
        await page.getByRole('button', { name: /^Save draft$/ }).click();
        const resp = await respP;
        await page.waitForLoadState('networkidle');
        await page.waitForTimeout(1000);
        const errs = await page.locator('.text-red-700, .text-red-600, [role=alert]').allInnerTexts();
        grievanceId = sql(`select id from grievances where subject like 'QA- grievance ${stamp} %' order by created_at desc limit 1`);
        if (!grievanceId) throw new Error(`no grievance row; POST=${resp?.status()} errors=${JSON.stringify(errs)} url=${page.url()}`);
        const row = sql(`select reference_number||'|'||status||'|'||(select count(*) from grievance_evidence where grievance_id=g.id) from grievances g where id='${grievanceId}'`);
        state.grievanceId = grievanceId;
        return `POST=${resp?.status()} id=${grievanceId} ref|status|evidence=${row} url=${page.url().replace(BASE, '')}`;
    }, page);

    await R.step('Grievance: stored description is server-sanitised (HTMLPurifier), formatting kept', async () => {
        const desc = sql(`select description from grievances where id='${grievanceId}'`);
        if (/onerror|<script/i.test(desc)) throw new Error('unsafe markup stored: ' + desc);
        if (!/<strong>|<em>|<ol>/.test(desc)) throw new Error('formatting lost: ' + desc);
        return `description="${desc.slice(0, 220)}"`;
    }, page);

    await R.step('Grievance: portal detail page renders the description (RichTextViewer) with no XSS execution', async () => {
        await visit(page, `/my-portal/grievances/${grievanceId}`);
        await page.waitForTimeout(600);
        const x = await page.evaluate(() => window.__xss);
        const txt = await page.locator('main').innerText();
        await shot(page, 'emp-grievance-show');
        if (x || dialogs.length) throw new Error('XSS executed');
        const strong = await page.locator('main .rich-text strong').count();
        return `__xss=undefined dialogs=0 bold rendered=${strong > 0} evidence listed=${/qa-100kb|QA/.test(txt)}`;
    }, page);

    await R.step('Grievance: "Submit grievance" on the detail page for a draft saved without the declaration → error wording', async () => {
        await visit(page, `/my-portal/grievances/${grievanceId}`);
        const btn = page.getByRole('button', { name: /^Submit grievance$/ }).first();
        if (await btn.count() === 0) throw new Error('no submit button on detail page');
        const respP = page.waitForResponse((r) => r.request().method() === 'POST' && r.url().includes('/submit'), { timeout: 60000 }).catch(() => null);
        await btn.click();
        const resp = await respP;
        await page.waitForLoadState('networkidle');
        await page.waitForTimeout(1500);
        const errs = await page.locator('[role=alert]').allInnerTexts();
        const status = sql(`select status from grievances where id='${grievanceId}'`);
        const missing = sql(`select concat_ws(',', case when incident_date is null then 'incident_date' end, case when immediate_supervisor_name is null then 'supervisor' end, case when applicant_address is null then 'address' end, case when requested_remedy is null then 'remedy' end, case when applicant_declared_at is null then 'declaration' end) from grievances where id='${grievanceId}'`);
        await shot(page, 'emp-grievance-submit-from-detail');
        return `POST=${resp?.status()} status=${status} missingFields=${missing || 'none'} errorShown=${JSON.stringify(errs).slice(0, 200)}`;
    }, page);

    await R.step('Grievance: edit page → tick the declaration → "Submit grievance" → status leaves draft; report routing outcome', async () => {
        await visit(page, `/my-portal/grievances/${grievanceId}/edit`);
        await page.locator('form input[type=checkbox]').last().check();
        const respP = page.waitForResponse((r) => r.request().method() === 'POST' && r.url().includes('/my-portal/grievances'), { timeout: 60000 }).catch(() => null);
        await page.getByRole('button', { name: /^Submit grievance$/ }).first().click();
        const resp = await respP;
        await page.waitForLoadState('networkidle');
        await page.waitForTimeout(1500);
        const txt = await page.locator('main').innerText();
        const errs = await page.locator('.text-red-700, .text-red-600, [role=alert]').allInnerTexts();
        const status = sql(`select status||'|'||coalesce(current_stage_id::text,'-')||'|'||coalesce(applicant_declared_at::text,'-') from grievances where id='${grievanceId}'`);
        await shot(page, 'emp-grievance-submitted');
        if (status.startsWith('draft')) throw new Error(`still draft; POST=${resp?.status()} errors=${JSON.stringify(errs)} text=${txt.replace(/\s+/g, ' ').slice(0, 200)}`);
        return `POST=${resp?.status()} status|stage|declared=${status} flash=${/submitted/i.test(txt)} errors=${JSON.stringify(errs).slice(0, 200)} url=${page.url().replace(BASE, '')}`;
    }, page);

    await R.step('Grievance: create a second application and press "Submit grievance" directly on the create form', async () => {
        await visit(page, '/my-portal/grievances/create');
        const inputs = page.locator('form input:not([type=file]):not([type=checkbox]):not([type=date]):not([type=hidden])');
        await inputs.nth(0).fill(`QA- grievance direct ${stamp} ${XSS2}`);
        await page.locator('form select').first().selectOption({ index: 1 });
        const dates = page.locator('form input[type=date]');
        await dates.nth(0).fill(today);
        await setRichText(page, 'grievance-description', `<p><strong>QA direct</strong> submission ${XSS1}</p>`);
        const areas = page.locator('form textarea');
        await areas.nth(0).fill('QA respondent');
        await areas.nth(1).fill('QA remedy');
        await inputs.nth(1).fill('QA Supervisor');
        if (await dates.count() > 1) await dates.nth(1).fill(today);
        await inputs.nth(2).fill('QA Address, Addis Ababa');
        await page.locator('input[type=file]').setInputFiles([path.join(FIX, 'qa-100kb.pdf')]);
        await page.locator('form input[type=checkbox]').last().check();
        const respP = page.waitForResponse((r) => r.request().method() === 'POST' && r.url().includes('/my-portal/grievances'), { timeout: 60000 }).catch(() => null);
        await page.getByRole('button', { name: /^Submit grievance$/ }).first().click();
        const resp = await respP;
        await page.waitForLoadState('networkidle');
        await page.waitForTimeout(1500);
        const errs = await page.locator('.text-red-700, .text-red-600, [role=alert]').allInnerTexts();
        const id = sql(`select id from grievances where subject like 'QA- grievance direct ${stamp} %' order by created_at desc limit 1`);
        if (!id) throw new Error(`no row; POST=${resp?.status()} errors=${JSON.stringify(errs)}`);
        const row = sql(`select reference_number||'|'||status||'|'||coalesce(current_stage_id::text,'-')||'|'||coalesce(applicant_declared_at::text,'-') from grievances where id='${id}'`);
        await shot(page, 'emp-grievance-direct-submitted');
        if (/\|draft\|/.test(row)) throw new Error(`still draft: ${row}; errors=${JSON.stringify(errs)}`);
        grievanceId = id; state.grievanceId = id;
        return `POST=${resp?.status()} ref|status|stage|declared=${row} errors=${JSON.stringify(errs).slice(0, 200)} url=${page.url().replace(BASE, '')}`;
    }, page);

    await R.step('Grievance: My grievances list shows the case with its status', async () => {
        await visit(page, '/my-portal/grievances');
        const txt = await page.locator('main').innerText();
        await shot(page, 'emp-grievances-list');
        if (!txt.includes(`QA- grievance ${stamp}`)) throw new Error('not listed');
        return 'listed';
    }, page);

    // ── Grievance Officer processes it ──
    const ctx2 = await browser.newContext();
    const off = await newPageIn(ctx2);
    R.attach(off);
    off.on('dialog', async (d) => { if (d.type() === 'beforeunload') { await d.accept(); return; } dialogs.push(d.message()); await d.dismiss(); });

    await R.step('Grievance Officer: intake review tab lists the submitted grievance', async () => {
        await login(off, 'qa.grievance@demo.local', 'password');
        await visit(off, '/grievances/cases?tab=intake');
        const txt = await off.locator('main').innerText();
        await shot(off, 'officer-intake-list');
        const ref = sql(`select reference_number from grievances where id='${grievanceId}'`);
        if (!txt.replace(/\s+/g, '').includes(ref.replace(/\s+/g, ''))) throw new Error('not in intake list: ' + txt.replace(/\s+/g, ' ').slice(0, 300));
        return `listed in Intake review as ${ref}`;
    }, off);

    await R.step('Grievance Officer: case page renders description/notes with XSS inert; list offered actions', async () => {
        await visit(off, `/grievances/cases/${grievanceId}`);
        await off.waitForTimeout(800);
        const x = await off.evaluate(() => window.__xss);
        const txt = await off.locator('main').innerText();
        await shot(off, 'officer-case-show');
        if (x || dialogs.length) throw new Error('XSS executed');
        const actions = await off.locator('main h2, main h3, main summary, main button[type=submit]').allInnerTexts();
        return `actions/headings=${JSON.stringify(Array.from(new Set(actions.map((a) => a.trim()).filter(Boolean)))).slice(0, 500)}`;
    }, off);

    await R.step('Grievance Officer: intake decision "accept" — report routing result (no route/committee configured in this DB)', async () => {
        await visit(off, `/grievances/cases/${grievanceId}`);
        await off.locator('details > summary').filter({ hasText: /Intake review/ }).first().evaluate((el) => { el.closest('details').open = true; });
        await off.waitForTimeout(300);
        const form = off.locator('form').filter({ has: off.locator('button', { hasText: /Record intake decision/ }) }).first();
        if (await form.count() === 0) throw new Error('no intake form offered');
        const decisions = await form.locator('select').first().locator('option').allTextContents();
        await form.locator('select').first().selectOption('accept');
        await form.locator('textarea').first().fill(`QA- intake note ${XSS1}`);
        const respP = off.waitForResponse((r) => r.request().method() === 'POST' && r.url().includes('/intake'), { timeout: 60000 }).catch(() => null);
        await form.locator('button').last().click();
        const resp = await respP;
        await off.waitForLoadState('networkidle');
        await off.waitForTimeout(1500);
        const errs = await off.locator('.text-red-700, .text-red-600, [role=alert]').allInnerTexts();
        const status = sql(`select status||'|'||coalesce(intake_notes,'')||'|'||coalesce(current_stage_id::text,'-') from grievances where id='${grievanceId}'`);
        await shot(off, 'officer-intake-decision');
        return `decisions offered=${JSON.stringify(decisions)} POST=${resp?.status()} db(status|intake_notes|stage)=${status} errors=${JSON.stringify(errs).slice(0, 300)}`;
    }, off);

    await R.step('Grievance Officer: add an internal note (XSS payload) if the Notes tab is offered', async () => {
        await visit(off, `/grievances/cases/${grievanceId}`);
        const tab = off.getByRole('tab', { name: /Notes/ }).or(off.getByRole('button', { name: /Notes & tasks/ })).or(off.getByRole('link', { name: /Notes & tasks/ }));
        if (await tab.count() === 0) return 'Notes tab not offered to the intake officer (internal view only for the handling body)';
        await tab.first().click();
        await off.waitForTimeout(400);
        const form = off.locator('form').filter({ has: off.locator('button', { hasText: /Add note/ }) }).first();
        if (await form.count() === 0) return 'Notes tab visible but no add-note form';
        await form.locator('textarea').first().fill(`QA- officer note ${XSS2} ${XSS1}`);
        await form.locator('button[type=submit]').click();
        await off.waitForLoadState('networkidle');
        await off.waitForTimeout(800);
        const n = sql(`select count(*) from grievance_notes where grievance_id='${grievanceId}'`);
        const x = await off.evaluate(() => window.__xss);
        return `notes in DB=${n} __xss=${x}`;
    }, off);

    await R.step('Audit log rows exist for the grievance (submitted, intake) and carry no narrative', async () => {
        const rows = sql(`select event_type||'|'||coalesce(actor_user_id::text,'-') from audit_logs where auditable_id='${grievanceId}' order by created_at`);
        const leak = sql(`select count(*) from audit_logs where auditable_id='${grievanceId}' and (new_values::text ilike '%onerror%' or old_values::text ilike '%onerror%')`);
        if (!rows) throw new Error('no audit rows');
        return `events=${rows.split('\n').map((r) => r.split('|')[0]).join(',')} rowsWithPayload=${leak}`;
    }, off);

    await R.step('Grievance Officer: Amharic case page renders; dates in Ethiopian calendar; English leakage', async () => {
        const c = await browser.newContext();
        const p = await newPageIn(c, 'am');
        R.attach(p);
        await login(p, 'qa.grievance@demo.local', 'password');
        await visit(p, `/grievances/cases/${grievanceId}`);
        const txt = await p.locator('main').innerText();
        await shot(p, 'officer-case-show-am');
        const english = Array.from(new Set(txt.split('\n').map((l) => l.trim()).filter((l) => /^[A-Za-z][A-Za-z ,.'()/:&-]{3,}$/.test(l) && !/^(QA|GRV|PSC)/.test(l))));
        const ethiopic = txt.match(/[ሀ-፿]{2,}\s\d{1,2}[,\s]+20[01]\d/g) || [];
        const gregorian = txt.match(/\b\d{1,2}\/\d{1,2}\/20\d{2}\b/g) || [];
        await c.close();
        return `englishLines=${JSON.stringify(english).slice(0, 400)} ethiopianDates=${JSON.stringify(ethiopic.slice(0, 3))} gregorianDates=${JSON.stringify(gregorian.slice(0, 3))}`;
    });

    await R.step('Employee: Amharic portal home + daily activity entry — English leakage', async () => {
        const c = await browser.newContext();
        const p = await newPageIn(c, 'am');
        R.attach(p);
        await login(p, 'qa.employee@demo.local', 'password');
        await visit(p, '/my-portal');
        const a = await p.locator('main').innerText();
        await shot(p, 'emp-portal-am');
        await visit(p, '/my-portal/daily-activity');
        const b = await p.locator('main').innerText();
        await shot(p, 'emp-daily-activity-am');
        const english = Array.from(new Set((a + '\n' + b).split('\n').map((l) => l.trim()).filter((l) => /^[A-Za-z][A-Za-z ,.'()/:&-]{3,}$/.test(l) && !/^(QA|EMP-)/.test(l))));
        await c.close();
        return `englishLines=${JSON.stringify(english).slice(0, 500)}`;
    });

    fs.writeFileSync(STATE_FILE, JSON.stringify(state, null, 2));
    await browser.close();
    R.save();
})().catch((e) => { console.error('FATAL', e); process.exit(1); });
