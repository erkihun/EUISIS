'use strict';
/* Journey 5 (+7/8 admin parts): back office for public complaints. qa.grievance (Grievance Officer) is checked
   for access; the complaint workflow is driven as qa.sysadmin3 because the Grievance Officer role holds no
   service_feedback.* grant (see report). */
const { BASE, Recorder, newBrowser, newPageIn, login, visit, shot } = require('./lib');
const fs = require('fs');
const path = require('path');
const { execSync } = require('child_process');

const STATE_FILE = path.join(__dirname, 'results', '30-public-state.json');
const state = JSON.parse(fs.readFileSync(STATE_FILE, 'utf8'));
const XSS1 = '<img src=x onerror=alert(1)>';
const XSS2 = '"><script>window.__xss=1</script>';
const sql = (q) => execSync(`psql -h /tmp -p 54329 -U postgres -d euisis_app -At -c "${q.replace(/"/g, '\\"')}"`).toString().trim();

(async () => {
    const R = new Recorder('30-public-d-admin');
    const { browser, page } = await newBrowser();
    R.attach(page);
    const dialogs = [];
    page.on('dialog', async (d) => { dialogs.push(d.message()); await d.dismiss(); });
    // The latest institution complaint that is still unrouted (earlier runs may have closed theirs).
    const complaint = state.submissions.filter((s) => s.kind === 'complaint' && s.target === 'organization' && s.id)
        .reverse().find((s) => sql(`select status from employee_service_feedback where id='${s.id}'`) === 'unrouted');
    const empComplaint = state.submissions.filter((s) => s.kind === 'complaint' && s.target === 'employee' && s.id).pop();
    if (!complaint) throw new Error('no organization complaint in state');

    // ── Grievance Officer: access boundary ──
    await R.step('qa.grievance (Grievance Officer) logs in without MFA', async () => {
        const url = await login(page, 'qa.grievance@demo.local', 'password');
        return `landed ${url.replace(BASE, '')}`;
    }, page);

    for (const [url, label] of [['/service-complaints', 'public complaints inbox'], ['/feedback-admin', 'client feedback admin'], ['/feedback-admin/dashboard', 'feedback dashboard'], [`/service-submissions/complaint/${complaint.id}`, 'complaint detail']]) {
        await R.step(`Grievance Officer: ${label} ${url} is denied (403) — role has no service_feedback grants`, async () => {
            const resp = await page.goto(`${BASE}${url}`, { waitUntil: 'networkidle' });
            const txt = await page.locator('body').innerText();
            if (resp.status() === 200 && !/403|forbidden|not authori[sz]ed|permission/i.test(txt)) throw new Error(`reachable (200): ${txt.replace(/\s+/g, ' ').slice(0, 120)}`);
            return `HTTP ${resp.status()}`;
        }, page);
    }

    await R.step('Grievance Officer: /grievances/dashboard and /grievances/cases render (own back office)', async () => {
        const a = await visit(page, '/grievances/dashboard');
        const b = await visit(page, '/grievances/cases');
        await shot(page, 'admin-grievance-cases-officer');
        return `dashboard=${a.status} cases=${b.status}`;
    }, page);

    await R.step('Grievance Officer: sidebar does not offer Public Feedback Management', async () => {
        await visit(page, '/grievances/dashboard');
        const nav = await page.locator('nav, aside').allInnerTexts();
        const has = /Public Feedback Management|Client feedback|Complaints/i.test(nav.join('\n'));
        return `publicFeedbackInSidebar=${has}`;
    }, page);

    // ── System Admin: complaint workflow ──
    const ctx2 = await browser.newContext();
    const admin = await newPageIn(ctx2);
    R.attach(admin);
    admin.on('dialog', async (d) => { dialogs.push(d.message()); await d.dismiss(); });

    await R.step('System Admin logs in and opens /service-complaints (status tabs, my queue)', async () => {
        await login(admin, 'qa.sysadmin3@demo.local', 'password');
        const r = await visit(admin, '/service-complaints');
        const txt = await admin.locator('main').innerText();
        await shot(admin, 'admin-complaints-inbox');
        const tabs = (txt.match(/Pending|Unrouted|Assigned|Under review|Resolved|Closed/gi) || []).map((t) => t.toLowerCase());
        const qaRefs = (txt.match(/PSC-\d{4}-\d+/g) || []).length;
        if (qaRefs === 0) throw new Error('no PSC references listed');
        return `status=${r.status} tabs=${Array.from(new Set(tabs)).join('/')} ${qaRefs} PSC refs on page 1 (newest first); target ${complaint.reference} on page 1: ${txt.includes(complaint.reference)}`;
    }, admin);

    await R.step('Unrouted tab lists the QA complaint (no routing rule matched → unrouted)', async () => {
        await visit(admin, '/service-complaints?status=unrouted');
        let found = false; let pages = 0;
        for (let i = 0; i < 6; i++) {
            pages++;
            const txt = await admin.locator('main').innerText();
            if (txt.includes(complaint.reference)) { found = true; break; }
            const next = admin.locator('main a[rel=next], main a[aria-label*="Next"], main a').filter({ hasText: /^(Next|›|»)$/ }).first();
            if (await next.count() === 0) break;
            const href = await next.getAttribute('href');
            if (!href) break;
            await visit(admin, href.replace(BASE, ''));
        }
        if (!found) throw new Error(`not in unrouted list within ${pages} page(s)`);
        return `listed under unrouted (page ${pages})`;
    }, admin);

    await R.step('Complaint detail renders narrative with XSS payload as inert text (no dialog, no __xss)', async () => {
        await visit(admin, `/service-submissions/complaint/${complaint.id}`);
        await admin.waitForTimeout(800);
        const txt = await admin.locator('main').innerText();
        await shot(admin, 'admin-complaint-detail');
        const x = await admin.evaluate(() => window.__xss);
        const rawImg = await admin.locator('main img[src="x"]').count();
        if (x || dialogs.length || rawImg) throw new Error(`XSS executed: __xss=${x} dialogs=${dialogs.length} rawImg=${rawImg}`);
        if (!txt.includes('onerror=alert(1)')) throw new Error('narrative payload not displayed as text: ' + txt.slice(0, 200));
        return `payload shown literally; __xss=undefined; attachments listed=${/qa-100kb\.pdf/.test(txt)}; submitter identity shown=${/QA Citizen/.test(txt)}`;
    }, admin);

    let offered = [];
    await R.step('Transition form offers only the statuses allowed from "unrouted" for a complaint', async () => {
        const opts = await admin.locator('#transition-status option').allTextContents().catch(() => []);
        offered = opts;
        return `from unrouted → ${JSON.stringify(opts)}`;
    }, admin);

    await R.step('Assign/route the complaint to a handler (user) with an XSS reason', async () => {
        await admin.getByRole('button', { name: /Assign handler|Reassign/ }).first().click();
        await admin.locator('#assign-form').waitFor({ timeout: 10000 });
        const types = await admin.locator('#assign-type option').allTextContents();
        const handlers = await admin.locator('#assign-handler option').allTextContents();
        await admin.fill('#assign-reason', `QA- routed to handler ${XSS1}`);
        await shot(admin, 'admin-complaint-assign-dialog');
        const respP = admin.waitForResponse((r) => r.request().method() === 'POST' && r.url().includes('/assign'), { timeout: 60000 }).catch(() => null);
        await admin.locator('button[type=submit][form=assign-form]').click();
        await respP;
        await admin.waitForLoadState('networkidle');
        await admin.waitForTimeout(1500);
        const txt = await admin.locator('main').innerText();
        const row = sql(`select status, assigned_at is not null from employee_service_feedback where id='${complaint.id}'`);
        if (!row.startsWith('assigned')) throw new Error('DB status after assign: ' + row + ' / ' + (await admin.locator('[role=alert]').allInnerTexts()).join(' | '));
        return `handlerTypes=${JSON.stringify(types)} handlers(first3)=${JSON.stringify(handlers.slice(0, 3))} db=${row} pageShowsAssigned=${/Assigned/i.test(txt)}`;
    }, admin);

    const transitions = [];
    async function transitionTo(status, reason) {
        await visit(admin, `/service-submissions/complaint/${complaint.id}`);
        const opts = await admin.locator('#transition-status option').evaluateAll((os) => os.map((o) => ({ value: o.value, text: o.textContent })));
        transitions.push({ from: sql(`select status from employee_service_feedback where id='${complaint.id}'`), offered: opts.map((o) => o.value) });
        if (!opts.some((o) => o.value === status)) return { ok: false, offered: opts.map((o) => o.value) };
        await admin.selectOption('#transition-status', status);
        await admin.fill('#transition-reason', reason);
        const respP = admin.waitForResponse((r) => r.request().method() === 'POST' && r.url().includes('/transition'), { timeout: 60000 }).catch(() => null);
        await admin.locator('#transition-status').locator('xpath=ancestor::form').locator('button[type=submit]').click();
        await respP;
        await admin.waitForLoadState('networkidle');
        await admin.waitForTimeout(1200);
        return { ok: true, db: sql(`select status from employee_service_feedback where id='${complaint.id}'`), offered: opts.map((o) => o.value) };
    }

    await R.step('Transition assigned → under_review with an internal note (XSS payload)', async () => {
        const r = await transitionTo('under_review', `QA- internal note ${XSS2} ${XSS1}`);
        if (!r.ok) throw new Error('under_review not offered: ' + JSON.stringify(r.offered));
        if (r.db !== 'under_review') throw new Error('db=' + r.db);
        return `offered=${JSON.stringify(r.offered)} db=${r.db}`;
    }, admin);

    await R.step('Invalid transition: direct POST under_review → closed is refused (validation error, not applied)', async () => {
        const xsrf = decodeURIComponent((await ctx2.cookies()).find((c) => c.name === 'XSRF-TOKEN')?.value || '');
        const resp = await admin.request.post(`${BASE}/service-submissions/complaint/${complaint.id}/transition`, {
            maxRedirects: 0, headers: { 'X-XSRF-TOKEN': xsrf, 'X-Requested-With': 'XMLHttpRequest', Accept: 'application/json' }, form: { status: 'closed', reason: 'QA- skip ahead' },
        });
        const body = await resp.text();
        const db = sql(`select status from employee_service_feedback where id='${complaint.id}'`);
        if (db !== 'under_review') throw new Error('status changed to ' + db);
        if (resp.status() >= 500) throw new Error('500');
        return `HTTP ${resp.status()} body=${body.slice(0, 160)} db=${db}`;
    }, admin);

    await R.step('Invalid transition: direct POST to a status outside the enum (e.g. "hidden"/"pending") is refused', async () => {
        const xsrf = decodeURIComponent((await ctx2.cookies()).find((c) => c.name === 'XSRF-TOKEN')?.value || '');
        const out = [];
        for (const st of ['pending', 'hidden', 'accepted', 'bogus']) {
            const resp = await admin.request.post(`${BASE}/service-submissions/complaint/${complaint.id}/transition`, {
                maxRedirects: 0, headers: { 'X-XSRF-TOKEN': xsrf, 'X-Requested-With': 'XMLHttpRequest', Accept: 'application/json' }, form: { status: st, reason: 'QA-' },
            });
            out.push(`${st}→${resp.status()}`);
            if (resp.status() >= 500) throw new Error('500 for ' + st);
        }
        const db = sql(`select status from employee_service_feedback where id='${complaint.id}'`);
        if (db !== 'under_review') throw new Error('status changed to ' + db);
        return out.join(', ') + ` db=${db}`;
    }, admin);

    await R.step('Transition under_review → resolved → closed through the UI', async () => {
        const a = await transitionTo('resolved', 'QA- resolved: refund issued');
        if (!a.ok || a.db !== 'resolved') throw new Error('resolved failed: ' + JSON.stringify(a));
        const b = await transitionTo('closed', 'QA- closed');
        if (!b.ok || b.db !== 'closed') throw new Error('closed failed: ' + JSON.stringify(b));
        await shot(admin, 'admin-complaint-closed');
        const after = await admin.locator('#transition-status option').allTextContents().catch(() => []);
        return `resolved offered=${JSON.stringify(a.offered)}; closed offered=${JSON.stringify(b.offered)}; transition form after close: ${JSON.stringify(after)}`;
    }, admin);

    await R.step('Status history on the detail page lists every transition with actor and note (XSS inert)', async () => {
        await visit(admin, `/service-submissions/complaint/${complaint.id}`);
        const txt = await admin.locator('main').innerText();
        const x = await admin.evaluate(() => window.__xss);
        const hist = sql(`select string_agg(coalesce(from_status,'-')||'>'||to_status, ',' order by transitioned_at) from public_feedback_status_history where submission_id='${complaint.id}'`);
        if (x || dialogs.length) throw new Error('XSS executed on detail page');
        if (!/Status history/i.test(txt)) throw new Error('no history section');
        return `db history=${hist}; note visible=${txt.includes('QA- internal note')}`;
    }, admin);

    await R.step('Audit log rows exist for assignment and each status change (auditable = submission id, actor = sysadmin)', async () => {
        const rows = sql(`select event_type||'|'||coalesce(actor_user_id::text,'-')||'|'||coalesce(new_values::text,'') from audit_logs where auditable_id='${complaint.id}' order by created_at`);
        const lines = rows.split('\n').filter(Boolean);
        const types = lines.map((l) => l.split('|')[0]);
        if (!types.includes('service_feedback.assigned')) throw new Error('no assigned audit row: ' + types.join(','));
        if (types.filter((t) => t === 'service_feedback.status_changed').length < 3) throw new Error('fewer than 3 status_changed rows: ' + types.join(','));
        const leaks = lines.filter((l) => /onerror|QA Citizen|0911/.test(l)).length;
        return `events=${types.join(',')} rowsWithNarrativeOrContact=${leaks}`;
    }, admin);

    await R.step('/audit-logs page (if present) shows the complaint events to the System Admin', async () => {
        const resp = await admin.goto(`${BASE}/audit-logs?search=${complaint.id}`, { waitUntil: 'networkidle' });
        const txt = await admin.locator('main').innerText().catch(() => '');
        await shot(admin, 'admin-audit-logs');
        return `HTTP ${resp.status()} mentionsStatusChange=${/status_changed|status changed|Status Changed/i.test(txt)}`;
    }, admin);

    await R.step('Employee-QR complaint detail (target employee) renders; attachment download works with no-store/nosniff', async () => {
        if (!empComplaint) return 'no employee complaint in state';
        await visit(admin, `/service-submissions/complaint/${empComplaint.id}`);
        const link = admin.locator('main a[href*="/attachments/"]').first();
        if (await link.count() === 0) return 'no attachment link visible';
        const href = await link.getAttribute('href');
        const resp = await admin.request.get(href, { maxRedirects: 0 });
        const h = resp.headers();
        return `GET ${href.replace(BASE, '')} → ${resp.status()} content-type=${h['content-type']} cache-control=${h['cache-control']} x-content-type-options=${h['x-content-type-options']} disposition=${(h['content-disposition'] || '').slice(0, 60)}`;
    }, admin);

    // ── Amharic admin lists ──
    await R.step('Amharic: grievance admin list /grievances/cases as Grievance Officer — English leakage and calendar', async () => {
        const c = await browser.newContext();
        const p = await newPageIn(c, 'am');
        R.attach(p);
        await login(p, 'qa.grievance@demo.local', 'password');
        await visit(p, '/grievances/cases');
        const txt = await p.locator('main').innerText();
        await shot(p, 'admin-grievance-cases-am');
        const english = Array.from(new Set(txt.split('\n').map((l) => l.trim()).filter((l) => /^[A-Za-z][A-Za-z ,.'()/:&-]{3,}$/.test(l))));
        const dates = txt.match(/\d{1,2}\/\d{1,2}\/\d{4}|\d{4}-\d{2}-\d{2}|[ሀ-፿]+ \d{1,2}, \d{4}/g) || [];
        await c.close();
        return `englishLines=${JSON.stringify(english).slice(0, 400)} dates=${JSON.stringify(dates.slice(0, 4))}`;
    });

    await R.step('Amharic: public complaints inbox + detail as System Admin — English leakage and Ethiopian dates', async () => {
        const c = await browser.newContext();
        const p = await newPageIn(c, 'am');
        R.attach(p);
        await login(p, 'qa.sysadmin3@demo.local', 'password');
        await visit(p, '/service-complaints');
        const txt = await p.locator('main').innerText();
        await shot(p, 'admin-complaints-am');
        await visit(p, `/service-submissions/complaint/${complaint.id}`);
        const txt2 = await p.locator('main').innerText();
        await shot(p, 'admin-complaint-detail-am');
        const all = txt + '\n' + txt2;
        const english = Array.from(new Set(all.split('\n').map((l) => l.trim()).filter((l) => /^[A-Za-z][A-Za-z ,.'()/:&-]{3,}$/.test(l) && !/^(QA|PSC|PTO)/.test(l))));
        const gregorian = all.match(/\b\d{1,2}\/\d{1,2}\/20\d{2}\b/g) || [];
        const ethiopic = all.match(/\b\d{1,2}\/\d{1,2}\/20[01]\d\b|[ሀ-፿]{2,}\s\d{1,2}[,\s]+20[01]\d/g) || [];
        await c.close();
        return `englishLines=${JSON.stringify(english).slice(0, 500)} gregorianDates=${JSON.stringify(gregorian.slice(0, 3))} ethiopianDates=${JSON.stringify(ethiopic.slice(0, 3))}`;
    });

    state.adminTransitions = transitions;
    fs.writeFileSync(STATE_FILE, JSON.stringify(state, null, 2));
    await browser.close();
    R.save();
})().catch((e) => { console.error('FATAL', e); process.exit(1); });
