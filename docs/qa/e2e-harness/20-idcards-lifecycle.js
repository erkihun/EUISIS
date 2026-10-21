'use strict';
/*
 * 20-idcards-lifecycle.js — ID card lifecycle + public verification (E2E), resumable.
 *
 * Subject: seeded card-area demo employee EMP-0003 ("Printed Card Employee", card AA-2026-DEMO03).
 * Registering a fresh QA employee through /employees/create returns HTTP 500 on this stack
 * (20-idcards-debug-emp5.js, shots/idc-debug-emp5.png, laravel.log ERR-FBLWJZTJE9LX) and tinker writes
 * are not permitted for this agent, so the seeded card-area employee is used.
 *
 * Every phase inspects the DB and skips what an earlier run already did, so the script can be re-run.
 */
const fs = require('fs');
const path = require('path');
const { spawnSync } = require('child_process');
const { BASE, Recorder, newBrowser, newPageIn, login, submitAndLeave, expectUrl, shot } = require('./lib');

const LOG = '/home/user/EUISIS/storage/logs/laravel.log';
const EMP = { id: '01a15ba2-5107-70d4-9f9f-af1685fdba7c', number: 'EMP-0003', name: 'Printed Card Employee', phone: '0911000003' };
const DEMO03 = { id: '01a15ba2-5119-7334-be3d-03e455327cbd', number: 'AA-2026-DEMO03' };
const DEMO02 = { id: '01a15ba2-5105-737a-87fa-b7e558d16239', number: 'AA-2026-DEMO02' };
const USERS = { officer: '15', approver: '16', admin: '10' };
const timings = [];
const notes = [];

function sql(q) {
    const r = spawnSync('psql', ['-h', '/tmp', '-p', '54329', '-U', 'postgres', '-d', 'euisis_app', '-At', '-F', '|', '-c', q], { encoding: 'utf8' });
    if (r.status !== 0) throw new Error('psql: ' + r.stderr.trim());
    return r.stdout.trim();
}
function sqlRows(q) { const o = sql(q); return o ? o.split('\n').map((l) => l.split('|')) : []; }
function logTail(offset) {
    const fd = fs.openSync(LOG, 'r'); const size = fs.statSync(LOG).size; const buf = Buffer.alloc(Math.max(0, size - offset));
    fs.readSync(fd, buf, 0, buf.length, offset); fs.closeSync(fd); return buf.toString('utf8');
}
const card = (id) => { const r = sqlRows(`select id, card_number, status, printed_at is not null, issued_at is not null, activated_at is not null, is_current, public_card_uuid, previous_card_id from id_cards where id='${id}'`)[0]; return r && { id: r[0], number: r[1], status: r[2], printed: r[3] === 't', issued: r[4] === 't', activated: r[5] === 't', current: r[6] === 't', uuid: r[7] || null, prev: r[8] || null }; };
const req = (where) => { const r = sqlRows(`select id, status, request_type, requested_by, approved_by, previous_card_id from card_requests where ${where} order by created_at desc limit 1`)[0]; return r && { id: r[0], status: r[1], type: r[2], requestedBy: r[3], approvedBy: r[4] || null, prev: r[5] || null }; };
const cardOfRequest = (reqId) => { const r = sqlRows(`select id from id_cards where card_request_id='${reqId}' order by created_at desc limit 1`)[0]; return r ? card(r[0]) : null; };

async function timedGoto(page, url, label) {
    const t0 = Date.now();
    const resp = await page.goto(url.startsWith('http') ? url : `${BASE}${url}`, { waitUntil: 'networkidle' });
    const ms = Date.now() - t0;
    timings.push({ label: label || url, url, ms, status: resp ? resp.status() : 0 });
    return { status: resp ? resp.status() : 0, ms, body: await page.locator('body').innerText().catch(() => '') };
}
async function csrf(page) {
    const cookies = await page.context().cookies(BASE);
    const x = cookies.find((c) => c.name === 'XSRF-TOKEN');
    if (x) return decodeURIComponent(x.value);
    return page.evaluate(() => document.querySelector('meta[name="csrf-token"]')?.content ?? '');
}
async function postRaw(context, page, url, data = {}) {
    const token = await csrf(page);
    const r = await context.request.post(`${BASE}${url}`, {
        headers: { 'X-XSRF-TOKEN': token, Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' }, data, maxRedirects: 0,
    });
    const text = await r.text().catch(() => '');
    return { status: r.status(), location: r.headers()['location'] || null, text: text.slice(0, 600) };
}
async function getRaw(context, url) {
    const r = await context.request.get(`${BASE}${url}`, { maxRedirects: 0 });
    return { status: r.status(), location: r.headers()['location'] || null, ctype: r.headers()['content-type'] || '', text: (await r.text().catch(() => '')).slice(0, 400) };
}
/** Inertia v3 embeds the page object in <script data-page="app" type="application/json">. */
async function inertiaProps(context, url) {
    const r = await context.request.get(`${BASE}${url}`);
    const html = await r.text();
    const m = html.match(/<script data-page="app" type="application\/json">([\s\S]*?)<\/script>/);
    return { status: r.status(), props: m ? JSON.parse(m[1]).props : null };
}
async function waitText(page, re, timeout = 12000) {
    const t0 = Date.now();
    while (Date.now() - t0 < timeout) {
        const t = await page.locator('body').innerText().catch(() => '');
        if (re.test(t)) return t;
        await page.waitForTimeout(300);
    }
    throw new Error(`text ${re} not found; body=` + (await page.locator('body').innerText()).replace(/\n+/g, ' | ').slice(0, 300));
}
const MODAL = 'div.fixed.inset-0';
async function clickModalConfirm(page, re) {
    const btn = page.locator(`${MODAL} button`).filter({ hasText: re }).last();
    await btn.waitFor({ state: 'visible' });
    await btn.click();
}
async function modalAction(page, buttonRe, fill, flashRe, confirmRe = buttonRe) {
    await page.getByRole('button', { name: buttonRe }).first().click();
    if (fill !== null) await page.locator(`${MODAL} textarea, ${MODAL} input`).first().fill(fill);
    await clickModalConfirm(page, confirmRe);
    return waitText(page, flashRe);
}
async function latinLeaks(page) {
    return page.evaluate(() => {
        const out = new Set();
        const w = document.createTreeWalker(document.body, NodeFilter.SHOW_TEXT);
        let n;
        while ((n = w.nextNode())) {
            const el = n.parentElement; if (!el) continue;
            if (['SCRIPT', 'STYLE', 'NOSCRIPT', 'TEMPLATE'].includes(el.tagName)) continue;
            const cs = getComputedStyle(el); if (cs.display === 'none' || cs.visibility === 'hidden') continue;
            const t = n.textContent.replace(/\s+/g, ' ').trim(); if (!t) continue;
            if (/[A-Za-z]{3,}/.test(t)) out.add(t.slice(0, 90));
        }
        document.querySelectorAll('input[placeholder], textarea[placeholder]').forEach((i) => { if (/[A-Za-z]{3,}/.test(i.placeholder)) out.add('[placeholder] ' + i.placeholder.slice(0, 90)); });
        document.querySelectorAll('[aria-label]').forEach((i) => { if (/[A-Za-z]{3,}/.test(i.getAttribute('aria-label'))) out.add('[aria-label] ' + i.getAttribute('aria-label').slice(0, 90)); });
        return [...out];
    });
}
const PII = { full_name: EMP.name, employee_number: EMP.number, phone: EMP.phone, surname_fragment: 'Card Employee' };
function piiHits(text) { return Object.entries(PII).filter(([, v]) => text.includes(v)).map(([k]) => k); }

(async () => {
    const R = new Recorder('20-idcards-lifecycle');
    const logStart = fs.existsSync(LOG) ? fs.statSync(LOG).size : 0;
    const { browser, context: offCtx, page: off } = await newBrowser();
    R.attach(off);
    const appCtx = await browser.newContext(); const app = await newPageIn(appCtx); R.attach(app);
    const admCtx = await browser.newContext(); const adm = await newPageIn(admCtx); R.attach(adm);
    const anonCtx = await browser.newContext(); const anon = await newPageIn(anonCtx); R.attach(anon);
    const S = { skipped: [] };
    const who = (userId) => userId === USERS.officer ? [offCtx, off, 'officer'] : userId === USERS.admin ? [admCtx, adm, 'sysadmin2'] : [appCtx, app, 'approver'];

    await R.step('Logins: ID Card Officer, ID Card Approver, System Admin (no MFA for any)', async () => {
        const a = {}; const b = {}; const c = {};
        const u1 = await login(off, 'qa.cardofficer@demo.local', 'password', a);
        const u2 = await login(app, 'qa.cardapprover@demo.local', 'password', b);
        const u3 = await login(adm, 'qa.sysadmin2@demo.local', 'password', c);
        if (a.secret || b.secret || c.secret) throw new Error('MFA enrolment demanded');
        return `${u1} ${u2} ${u3}`;
    }, off);

    // ───────── Contact setup: the OTP is only observable by email (mail=log); SMS gateway is unconfigured ─────────
    adm.on('dialog', (d) => d.accept().catch(() => {}));
    async function setEmployeeEmail(value) {
        await timedGoto(adm, `/employees/${EMP.id}/edit`, 'employees/edit (sysadmin2)');
        await adm.fill('#email', value);
        await adm.locator('button[type="submit"]').last().click();
        await adm.waitForURL((u) => !/\/edit$/.test(u.toString()), { timeout: 30000 }).catch(() => {});
        await adm.waitForLoadState('networkidle');
        return sql(`select coalesce(email,'') from employees where id='${EMP.id}'`);
    }
    const QA_EMAIL = 'qa.cardholder.emp0003@demo.local';
    await R.step('Setup: EMP-0003 has no email and the SMS gateway is unconfigured → give the holder a QA email via Edit Employee (reverted at teardown)', async () => {
        const before = sql(`select coalesce(email,'') from employees where id='${EMP.id}'`);
        if (before === QA_EMAIL) return 'already set';
        const after = await setEmployeeEmail(QA_EMAIL);
        if (after !== QA_EMAIL) throw new Error(`email after save="${after}" url=${adm.url()}`);
        return `email "${before}" → "${after}"`;
    }, adm);

    // ───────── Pre-flow on the seeded cards ─────────
    await R.step('Ordering: issue/activate a PENDING_PRINT card (seeded DEMO02) → 403, state unchanged', async () => {
        const a = await postRaw(appCtx, app, `/id-cards/${DEMO02.id}/issue`, { issued_to: 'QA- too early' });
        const b = await postRaw(appCtx, app, `/id-cards/${DEMO02.id}/activate`, { notes: 'QA- too early' });
        const c = card(DEMO02.id);
        if (c.status !== 'pending_print') throw new Error('DEMO02 status changed: ' + c.status);
        if (a.status !== 403 || b.status !== 403) throw new Error(`issue=${a.status} activate=${b.status}`);
        return `issue=${a.status} activate=${b.status}; DEMO02 still pending_print`;
    }, app);
    await R.step('Pre: seeded printed card DEMO03 → issued → active → reported LOST (skip-aware)', async () => {
        const out = [];
        if (card(DEMO03.id).status === 'printed') {
            const b = await postRaw(appCtx, app, `/id-cards/${DEMO03.id}/activate`, {});
            out.push(`activate-before-issue=${b.status}`);
            await timedGoto(app, `/id-cards/${DEMO03.id}`, 'id-cards/show (approver, printed)');
            await app.getByRole('button', { name: /Issue Card/i }).first().click();
            const inputs = app.locator(`${MODAL} input`);
            await inputs.nth(0).fill('QA- ' + EMP.name); await inputs.nth(1).fill('QA- receiver');
            await clickModalConfirm(app, /Issue Card/i);
            await waitText(app, /Card issued successfully/i);
            out.push('issued');
        }
        if (card(DEMO03.id).status === 'issued') {
            await timedGoto(app, `/id-cards/${DEMO03.id}`, 'id-cards/show (approver, issued)');
            await modalAction(app, /Activate Card/i, 'QA- activated', /Card activated/i);
            out.push('activated');
        }
        if (card(DEMO03.id).status === 'active') {
            await timedGoto(off, `/id-cards/${DEMO03.id}`, 'id-cards/show (officer, active)');
            await modalAction(off, /Report Lost Card/i, 'QA- lost so a replacement request can be filed', /reported as lost/i);
            out.push('lost');
        }
        const c = card(DEMO03.id);
        const audit = sql(`select string_agg(event_type||'@'||actor_user_id, ' > ' order by created_at) from audit_logs where auditable_id='${DEMO03.id}' and event_type in ('card_issued','card_activated','card_lost')`);
        return `${out.length ? 'did: ' + out.join(', ') : 'nothing to do'}; DEMO03 now ${c.status}; audit=${audit}`;
    }, off);

    // ───────── J1: card request via the Create form ─────────
    async function fillRequestForm(page, type) {
        const r = await timedGoto(page, '/card-requests/create', 'card-requests/create');
        if (r.status !== 200) throw new Error('create page HTTP ' + r.status);
        await page.selectOption('#request-type', type);
        await page.fill('#employee-search', EMP.number);
        await page.waitForTimeout(900); await page.waitForLoadState('networkidle');
        const row = page.locator('label').filter({ hasText: EMP.number }).first();
        await row.waitFor({ state: 'visible', timeout: 10000 });
        await row.locator('input[type="checkbox"]').check();
        await page.fill('#request-reason', `QA- E2E ${type} card request for EMP-0003`);
        await submitAndLeave(page, /card-requests\/create/);
        return page.locator('body').innerText();
    }
    await R.step('J1: ID Card Officer submits a card request (POST /card-requests) — must succeed for the role that owns this step', async () => {
        const r = await postRaw(offCtx, off, '/card-requests', { employee_ids: [EMP.id], request_type: 'lost', reason: 'QA- officer request probe' });
        const created = req(`employee_id='${EMP.id}' and requested_by=${USERS.officer}`);
        if (r.status === 403) throw new Error(`HTTP 403 for the ID Card Officer (${r.text.slice(0, 80)}); CardRequestController::store authorizes 'view' on Employee (EmployeePolicy::view needs employees.view, which the role lacks)`);
        return `HTTP ${r.status}; created=${created ? created.id : 'none'}`;
    }, off);
    await R.step('J1: request for the lost DEMO03 exists or is created through the Create form (System Admin fallback)', async () => {
        let q = req(`previous_card_id='${DEMO03.id}'`);
        if (q) { S.skipped.push('J1 create'); S.reqA = q; return `exists: ${q.id} status=${q.status} type=${q.type} requested_by=${q.requestedBy}`; }
        const body = await fillRequestForm(adm, 'lost');
        await expectUrl(adm, /\/card-requests(\?|$)/);
        if (!/Card request submitted/i.test(body)) throw new Error('no flash: ' + body.replace(/\n+/g, ' | ').slice(0, 160));
        q = req(`previous_card_id='${DEMO03.id}'`);
        if (!q || q.type !== 'lost') throw new Error('row=' + JSON.stringify(q));
        S.reqA = q;
        await shot(adm, 'idc-request-created-admin');
        const audit = sql(`select count(*) from audit_logs where event_type='card_requested' and auditable_id='${q.id}'`);
        notes.push('card_requests has no human-readable request number; the only identifier is the uuid (' + q.id + ').');
        return `created ${q.id} status=submitted type=lost previous_card=DEMO03 requested_by=${q.requestedBy} audit card_requested=${audit}`;
    }, adm);
    await R.step('J1: request detail page renders for the officer; can.* flags recorded', async () => {
        const r = await timedGoto(off, `/card-requests/${S.reqA.id}`, 'card-requests/show (officer)');
        if (r.status !== 200) throw new Error('HTTP ' + r.status);
        if (!r.body.includes(EMP.number)) throw new Error('employee number missing');
        await shot(off, 'idc-request-show-officer');
        const { props } = await inertiaProps(offCtx, `/card-requests/${S.reqA.id}`);
        S.officerCan = props?.can;
        return `officer can=${JSON.stringify(props?.can)}; Approve button visible=${(await off.getByRole('button', { name: /Approve Request/i }).count()) > 0}`;
    }, off);

    // ───────── J2: approval with segregation of duties (reusable) ─────────
    async function approveFlow(q, label) {
        await R.step(`J1 [${label}]: a second request while one is pending is refused with a validation error (422)`, async () => {
            q = req(`id='${q.id}'`);
            if (q.status !== 'submitted') return `skipped: request already ${q.status}`;
            const r = await postRaw(admCtx, adm, '/card-requests', { employee_ids: [EMP.id], request_type: 'lost', reason: 'QA- duplicate' });
            const n = sql(`select count(*) from card_requests where employee_id='${EMP.id}' and status in ('submitted','verified')`);
            if (r.status !== 422 || n !== '1') throw new Error(`HTTP ${r.status} ${r.text.slice(0, 120)} pending=${n}`);
            return `HTTP 422 "${(JSON.parse(r.text).message || '').slice(0, 120)}"; pending=${n}`;
        }, adm);
        await R.step(`J2 [${label}]: ID Card Officer (not an approver) tries the approve URL → expect 403`, async () => {
            q = req(`id='${q.id}'`);
            if (q.status !== 'submitted') return `skipped: request already ${q.status}`;
            if (q.requestedBy === USERS.officer) return 'skipped: officer is the requester';
            const r = await postRaw(offCtx, off, `/card-requests/${q.id}/approve`, { notes: 'QA- officer approve probe' });
            const after = req(`id='${q.id}'`);
            const info = `HTTP ${r.status} | DB status=${after.status} approved_by=${after.approvedBy}`;
            if (after.status === 'approved') throw new Error('OFFICER APPROVED A REQUEST: ' + info);
            if (r.status !== 403) throw new Error('expected 403: ' + info);
            return info;
        }, off);
        await R.step(`J2 [${label}]: the REQUESTER must NOT be able to approve their own request (POST approve → expect 403)`, async () => {
            q = req(`id='${q.id}'`);
            if (q.status !== 'submitted') return `skipped: request already ${q.status} (approved_by=${q.approvedBy})`;
            const [ctx, pg, name] = who(q.requestedBy);
            const r = await postRaw(ctx, pg, `/card-requests/${q.id}/approve`, { notes: 'QA- self-approval attempt by requester' });
            const after = req(`id='${q.id}'`);
            const info = `requester=${name} (user ${q.requestedBy}); HTTP ${r.status} location=${r.location} | DB status=${after.status} approved_by=${after.approvedBy}`;
            if (after.status === 'approved') throw new Error('SELF-APPROVAL ACCEPTED: ' + info);
            if (r.status !== 403) throw new Error('expected 403, got ' + info);
            return info;
        }, adm);
        await R.step(`J2 [${label}]: a different approver approves via the modal → approved, new card pending_print linked to the prior card`, async () => {
            q = req(`id='${q.id}'`);
            if (q.status === 'submitted') {
                const [pg, name] = q.requestedBy === USERS.approver ? [adm, 'sysadmin2'] : [app, 'approver'];
                const r = await timedGoto(pg, `/card-requests/${q.id}`, `card-requests/show (${name})`);
                if (r.status !== 200) throw new Error('HTTP ' + r.status);
                await pg.getByRole('button', { name: /Approve Request/i }).first().click();
                await pg.locator(`${MODAL} textarea`).fill('QA- approved by ' + name);
                await clickModalConfirm(pg, /Approve Request/i);
                await waitText(pg, /Card request approved/i);
                await shot(pg, `idc-request-approved-${label}`);
            }
            q = req(`id='${q.id}'`);
            if (q.status !== 'approved') throw new Error('status=' + q.status);
            const c = cardOfRequest(q.id);
            if (!c) throw new Error('no card for request');
            const audit = sql(`select count(*) from audit_logs where event_type='card_approved' and auditable_id='${q.id}'`);
            return `approved_by=${q.approvedBy} (requested_by=${q.requestedBy}); card ${c.number} id=${c.id} status=${c.status} previous_card_id=${c.prev === q.prev ? 'prior card ✓' : c.prev}; audit card_approved=${audit}`;
        }, app);
        await R.step(`J2 [${label}]: approving the same request again is refused and creates no second card`, async () => {
            const r = await postRaw(appCtx, app, `/card-requests/${q.id}/approve`, { notes: 'QA- double approve' });
            const n = sql(`select count(*) from id_cards where card_request_id='${q.id}'`);
            if (n !== '1') throw new Error('cards for request=' + n);
            return `HTTP ${r.status} ${r.text.slice(0, 80)}; cards for request=${n}`;
        }, app);
        return cardOfRequest(q.id);
    }
    S.cardA = await approveFlow(S.reqA, 'A');

    // ───────── J3: print → issue → activate with ordering rules (reusable, skip-aware) ─────────
    async function lifecycle(c, label) {
        const id = c.id;
        await R.step(`J3 [${label}]: issue/activate BEFORE print on a pending_print card → 403`, async () => {
            if (card(id).status !== 'pending_print') return 'skipped: card is ' + card(id).status;
            const a = await postRaw(appCtx, app, `/id-cards/${id}/issue`, { issued_to: 'QA- too early' });
            const b = await postRaw(appCtx, app, `/id-cards/${id}/activate`, { notes: 'QA- too early' });
            if (card(id).status !== 'pending_print') throw new Error('status changed');
            if (a.status !== 403 || b.status !== 403) throw new Error(`issue=${a.status} activate=${b.status}`);
            return `issue=${a.status} activate=${b.status}`;
        }, app);
        await R.step(`J3 [${label}]: officer creates a print batch with the card (draft, item pending, audit)`, async () => {
            if (card(id).status !== 'pending_print') { S.batch = S.batch || { id: sql(`select id from card_print_batches order by created_at desc limit 1`) }; return 'skipped: card is ' + card(id).status; }
            const r = await timedGoto(off, '/print-batches/create', 'print-batches/create');
            if (r.status !== 200) throw new Error('HTTP ' + r.status);
            const row = off.locator('label').filter({ hasText: card(id).number }).first();
            await row.waitFor({ state: 'visible' });
            await row.locator('input[type="checkbox"]').check();
            await submitAndLeave(off, /print-batches\/create/);
            await expectUrl(off, /\/print-batches\/[0-9a-f-]{36}$/);
            S.batch = { id: off.url().match(/print-batches\/([0-9a-f-]{36})/)[1] };
            await waitText(off, /Print batch created/i, 8000);
            const b = sqlRows(`select batch_number, status, created_by, total_cards from card_print_batches where id='${S.batch.id}'`)[0];
            const items = sql(`select count(*) from card_print_batch_items where card_print_batch_id='${S.batch.id}' and id_card_id='${id}' and status='pending'`);
            if (b[1] !== 'draft' || items !== '1') throw new Error('batch ' + b.join(',') + ' items=' + items);
            await shot(off, `idc-batch-created-${label}`);
            return `${b[0]} status=${b[1]} created_by=${b[2]} total=${b[3]} items=${items}`;
        }, off);
        await R.step(`J3 [${label}]: "Mark as Printed" BEFORE the frozen print is confirmed → validation message, batch stays draft`, async () => {
            if (card(id).status !== 'pending_print') return 'skipped';
            await off.getByRole('button', { name: /Mark as Printed/i }).click();
            const t = await waitText(off, /prepare its frozen print|confirm successful printing/i, 10000);
            const b = sqlRows(`select status from card_print_batches where id='${S.batch.id}'`)[0];
            if (b[0] !== 'draft' || card(id).status !== 'pending_print') throw new Error(`batch=${b[0]} card=${card(id).status}`);
            await shot(off, `idc-batch-mark-early-refused-${label}`);
            return `batch still draft; message="${t.match(/Open each card[^|\n]*/)?.[0]?.slice(0, 120)}"`;
        }, off);
        await R.step(`J3 [${label}]: officer prepares the frozen print (snapshot, SVG artifacts) and confirms → card printed`, async () => {
            if (card(id).status !== 'pending_print') return 'skipped: card is ' + card(id).status;
            const r = await timedGoto(off, `/id-cards/${id}`, 'id-cards/show (officer, pending_print)');
            if (r.status !== 200) throw new Error('HTTP ' + r.status);
            await shot(off, `idc-card-show-pending-${label}`);
            await off.getByRole('button', { name: /Prepare frozen print/i }).click();
            await off.waitForURL(/\/id-cards\/[0-9a-f-]{36}\/print\/[0-9a-f-]{36}$/, { timeout: 30000 });
            await off.waitForLoadState('networkidle');
            S.snapshot = off.url().match(/print\/([0-9a-f-]{36})/)[1];
            const front = await getRaw(offCtx, `/id-cards/${id}/print/${S.snapshot}/front`);
            const back = await getRaw(offCtx, `/id-cards/${id}/print/${S.snapshot}/back`);
            if (front.status !== 200 || !/svg/.test(front.ctype)) throw new Error(`front artifact ${front.status} ${front.ctype}`);
            await shot(off, `idc-print-preparation-${label}`);
            await off.locator('form input[type="checkbox"]').check();
            await off.locator('form button').last().click();
            await waitText(off, /saved/i, 12000);
            const c2 = card(id);
            if (c2.status !== 'printed' || !c2.printed) throw new Error('card ' + JSON.stringify(c2));
            const snap = sqlRows(`select printed_at is not null, print_sequence, printed_by from id_card_print_snapshots where id='${S.snapshot}'`)[0];
            return `front=${front.status} back=${back.status}; card=printed; snapshot printed=${snap[0]} seq=${snap[1]} by=${snap[2]}`;
        }, off);
        await R.step(`J3 [${label}]: activate BEFORE issue on the printed card → 403`, async () => {
            if (card(id).status !== 'printed') return 'skipped: card is ' + card(id).status;
            const r = await postRaw(appCtx, app, `/id-cards/${id}/activate`, { notes: 'QA- before issue' });
            if (card(id).status !== 'printed') throw new Error('status changed');
            if (r.status !== 403) throw new Error('HTTP ' + r.status);
            return `HTTP ${r.status}`;
        }, app);
        await R.step(`J3 [${label}]: officer marks the batch printed → completed, item printed, audit print_batch_completed`, async () => {
            if (!S.batch || sql(`select status from card_print_batches where id='${S.batch.id}'`) === 'completed') return 'skipped: batch already completed';
            await timedGoto(off, `/print-batches/${S.batch.id}`, 'print-batches/show');
            await off.getByRole('button', { name: /Mark as Printed/i }).click();
            await waitText(off, /Batch marked as printed/i, 12000);
            const b = sqlRows(`select status, printed_count, printed_by from card_print_batches where id='${S.batch.id}'`)[0];
            const item = sql(`select status from card_print_batch_items where card_print_batch_id='${S.batch.id}'`);
            const audit = sql(`select count(*) from audit_logs where event_type='print_batch_completed' and auditable_id='${S.batch.id}'`);
            if (b[0] !== 'completed' || item !== 'printed') throw new Error(`batch ${b.join(',')} item=${item}`);
            await shot(off, `idc-batch-completed-${label}`);
            return `batch=${b[0]} printed_count=${b[1]} by=${b[2]} item=${item} audit=${audit}`;
        }, off);
        await R.step(`J3 [${label}]: approver issues the printed card → issued (card_issuances, audit)`, async () => {
            if (card(id).status !== 'printed') return 'skipped: card is ' + card(id).status;
            const r = await timedGoto(app, `/id-cards/${id}`, 'id-cards/show (approver, printed)');
            if (r.status !== 200) throw new Error('HTTP ' + r.status);
            await app.getByRole('button', { name: /Issue Card/i }).first().click();
            const inputs = app.locator(`${MODAL} input`);
            await inputs.nth(0).fill('QA- ' + EMP.name); await inputs.nth(1).fill('QA- receiver');
            await clickModalConfirm(app, /Issue Card/i);
            await waitText(app, /Card issued successfully/i);
            const c2 = card(id);
            if (c2.status !== 'issued' || !c2.issued) throw new Error('card ' + JSON.stringify(c2));
            const iss = sqlRows(`select issued_by, issued_to, received_by from card_issuances where id_card_id='${id}'`)[0];
            await shot(app, `idc-card-issued-${label}`);
            return `status=issued; issuance by=${iss[0]} to="${iss[1]}" received_by="${iss[2]}"`;
        }, app);
        await R.step(`J3 [${label}]: approver activates the issued card → active (audit card_activated)`, async () => {
            if (card(id).status !== 'issued') return 'skipped: card is ' + card(id).status;
            await timedGoto(app, `/id-cards/${id}`, 'id-cards/show (approver, issued)');
            await modalAction(app, /Activate Card/i, 'QA- activated', /Card activated/i);
            const c2 = card(id);
            if (c2.status !== 'active' || !c2.activated) throw new Error('card ' + JSON.stringify(c2));
            await shot(app, `idc-card-active-${label}`);
            return 'status=active';
        }, app);
        await R.step(`J3 [${label}]: audit trail for the card (requested > approved > snapshot > printed > issued > activated)`, async () => {
            const reqId = sql(`select card_request_id from id_cards where id='${id}'`);
            const trail = sql(`select string_agg(event_type||'@'||coalesce(actor_user_id::text,'anon'), ' > ' order by created_at) from audit_logs where auditable_id in ('${id}','${reqId}') and event_type in ('card_requested','card_approved','card_snapshot_created','card_printed','card_issued','card_activated')`);
            for (const e of ['card_approved@', 'card_snapshot_created@15', 'card_printed@15', 'card_issued@16', 'card_activated@16']) if (!trail.includes(e)) throw new Error('missing ' + e + ' in ' + trail);
            if (!trail.includes('card_requested@')) { const prev = card(id).prev; const repl = sql(`select count(*) from audit_logs where event_type='card_replaced' and auditable_id='${prev}'`); notes.push(`no card_requested audit row for the request behind ${card(id).number}: created by ReplaceCardAction, which logs card_replaced on the old card (rows=${repl}) only`); }
            if ((trail.match(/card_printed@/g) || []).length > 1) notes.push(`card_printed audit row written twice for ${card(id).number} (once by IdCardPrintSnapshotService::confirm, once by MarkCardPrintedAction)`);
            return trail;
        });
        return card(id);
    }
    S.cardA = await lifecycle(S.cardA, 'A');

    // ───────── Replacement flow from the card page (reusable) ─────────
    async function replaceFlow(lost, label) {
        let q;
        await R.step(`J5 [${label}]: approver files a replacement from the LOST card page → old card replaced, request linked (previous_card_id)`, async () => {
            if (card(lost.id).status === 'replaced') { q = req(`previous_card_id='${lost.id}'`); return `already replaced earlier; request ${q.id} status=${q.status}`; }
            await timedGoto(app, `/id-cards/${lost.id}`, 'id-cards/show (approver, lost)');
            await modalAction(app, /Replace card/i, 'QA- replacement after loss', /Replacement request submitted/i, /Submit request/i);
            await expectUrl(app, /\/card-requests/);
            const old = card(lost.id);
            q = req(`previous_card_id='${lost.id}'`);
            const repl = sqlRows(`select old_card_id, new_card_id from card_replacements where old_card_id='${lost.id}'`)[0];
            if (old.status !== 'replaced' || old.current) throw new Error('old card ' + JSON.stringify(old));
            if (!q) throw new Error('replacement request not linked');
            await shot(app, `idc-replacement-submitted-${label}`);
            return `old=${old.status} is_current=${old.current}; request ${q.id} type=${q.type} status=${q.status} requested_by=${q.requestedBy}; card_replacements.new_card_id=${repl?.[1] === lost.id ? 'OLD CARD (placeholder)' : repl?.[1]}`;
        }, app);
        const c = await approveFlow(q, label);
        await R.step(`J5 [${label}]: new card and prior card cross-link on their detail pages; card_replacements row checked`, async () => {
            const r = await timedGoto(app, `/id-cards/${c.id}`, 'id-cards/show (replacement card)');
            const showsPrev = r.body.includes(lost.number);
            const r2 = await timedGoto(app, `/id-cards/${lost.id}`, 'id-cards/show (replaced card)');
            const showsNext = r2.body.includes(c.number);
            const repl = sqlRows(`select new_card_id from card_replacements where old_card_id='${lost.id}'`)[0];
            if (repl && repl[0] === lost.id) notes.push(`card_replacements.new_card_id still equals the OLD card id (${lost.number}) after approval — placeholder never updated (ReplaceCardAction.php:85; ApproveCardRequestAction never touches card_replacements)`);
            if (c.prev !== lost.id) throw new Error('previous_card_id not set');
            if (!showsNext) notes.push(`replaced card ${lost.number} page does not show its newest replacement ${c.number}: IdCard::replacementCard is hasOne(previous_card_id) and returns the first replacement (app/Models/IdCard.php:103)`);
            if (!showsPrev) throw new Error('new card page does not show the prior card');
            await shot(app, `idc-card-replacement-${label}`);
            return `new ${c.number} previous_card_id=prior ✓; new page shows prior number=${showsPrev}; prior page shows new number=${showsNext}; card_replacements.new_card_id=${repl?.[0] === lost.id ? 'STILL OLD CARD' : repl?.[0]}`;
        }, app);
        return c;
    }

    // ───────── Ensure an ACTIVE current card for the public checks ─────────
    const activeCard = () => { const r = sqlRows(`select id from id_cards where employee_id='${EMP.id}' and status='active' order by created_at desc limit 1`)[0]; return r ? card(r[0]) : null; };
    { const r = sqlRows(`select id from id_cards where employee_id='${EMP.id}' and status='lost' order by created_at desc limit 1`)[0]; S.lostCard = r ? card(r[0]) : null; }
    if (!activeCard() && S.lostCard) {
        await R.step('J5 [A]: public checker on the LOST card: not valid, OTP refused, no PII', async () => {
            if (!S.lostCard.uuid) await inertiaProps(offCtx, `/id-cards/${S.lostCard.id}`);
            const uuid = card(S.lostCard.id).uuid;
            const r = await timedGoto(anon, `/id-checker/${uuid}`, 'id-checker/{uuid} (anon, lost)');
            const { props } = await inertiaProps(anonCtx, `/id-checker/${uuid}`);
            const hits = piiHits(r.body + JSON.stringify(props?.card));
            if (hits.length) throw new Error('PII: ' + hits);
            if (props?.card?.checkable !== false || props?.card?.status_code !== 'lost') throw new Error('card=' + JSON.stringify(props?.card));
            const s = await postRaw(anonCtx, anon, `/id-checker/${uuid}/send-otp`, {});
            await shot(anon, 'idc-public-lost');
            S.publicLostText = r.body.replace(/\n+/g, ' | ');
            return `card=${JSON.stringify(props.card)}; send-otp=${s.status}; visible="${S.publicLostText.slice(0, 300)}"`;
        }, anon);
        S.cardB = await replaceFlow(S.lostCard, 'B');
        S.cardB = await lifecycle(S.cardB, 'B');
    }
    S.active = activeCard();

    // ───────── J4: public verification on the active card ─────────
    await R.step('J4: card detail page carries the stable QR URL (/id-checker/{uuid}); uuid persisted; token hash not exposed', async () => {
        if (!S.active) throw new Error('no active card available');
        const r = await timedGoto(off, `/id-cards/${S.active.id}`, 'id-cards/show (officer, active)');
        if (r.status !== 200) throw new Error('HTTP ' + r.status);
        const { props } = await inertiaProps(offCtx, `/id-cards/${S.active.id}`);
        const url = props?.card?.qr_verification_url;
        S.active = card(S.active.id);
        if (!url || !url.includes('/id-checker/')) throw new Error('qr_verification_url=' + url);
        if (!S.active.uuid || !url.endsWith(S.active.uuid)) throw new Error(`uuid mismatch url=${url} db=${S.active.uuid}`);
        if (props?.card?.token_hash) throw new Error('token_hash exposed in page props');
        await shot(off, 'idc-card-show-active-officer');
        return `qr url=${url}; qr_payload in props=${Boolean(props?.card?.qr_payload)}; url text on page=${r.body.includes('/id-checker/')}`;
    }, off);
    await R.step('J4: anonymous visit of the QR URL — card state only; NO name/photo/national id/phone/employee number', async () => {
        const r = await timedGoto(anon, `/id-checker/${S.active.uuid}`, 'id-checker/{uuid} (anon, active)');
        if (r.status !== 200) throw new Error('HTTP ' + r.status);
        await shot(anon, 'idc-public-active');
        const { props } = await inertiaProps(anonCtx, `/id-checker/${S.active.uuid}`);
        const hitsBody = piiHits(r.body); const hitsProps = piiHits(JSON.stringify(props ?? {}));
        const imgs = await anon.locator('main img, section img, article img').count();
        if (hitsBody.length || hitsProps.length) throw new Error(`PII leaked body=${hitsBody} props=${hitsProps}`);
        if (r.body.includes(S.active.number)) throw new Error('full card number shown');
        S.publicActiveText = r.body.replace(/\n+/g, ' | ');
        return `card props=${JSON.stringify(props?.card)}; images=${imgs}; visible="${S.publicActiveText.slice(0, 520)}"`;
    }, anon);
    await R.step('J4: holder OTP — send-otp returns sent:true, OTP row stored hashed, audit otp_sent, delivery attempt observed in log', async () => {
        S.otpLogOffset = fs.statSync(LOG).size;
        const sres = await postRaw(anonCtx, anon, `/id-checker/${S.active.uuid}/send-otp`, {});
        if (sres.status !== 200 || !/"sent":true/.test(sres.text)) throw new Error(`send-otp ${sres.status} ${sres.text}`);
        await anon.waitForTimeout(1500);
        const row = sqlRows(`select otp_hash like '$2y$%' or otp_hash like '$argon%', attempts, expires_at > now() from public_id_check_otps where card_uuid='${S.active.uuid}' order by created_at desc limit 1`)[0];
        const audit = sql(`select string_agg(event_type||':'||coalesce(reason,''), ' | ' order by created_at) from audit_logs where auditable_id='${S.active.id}' and event_type like 'public_id_check.otp%'`);
        const tail = logTail(S.otpLogOffset);
        const delivery = (tail.match(/(SMS not delivered[^"]{0,80}|Public ID check OTP email failed[^}]{0,160})/g) || []).map((x) => x.slice(0, 160));
        S.otpDelivery = delivery;
        return `HTTP 200 sent:true; otp row hashed=${row?.[0]} attempts=${row?.[1]} unexpired=${row?.[2]}; audit=${audit}; delivery log=${JSON.stringify(delivery)}`;
    }, anon);
    await R.step('J4: wrong OTP → 422 verificationFailed, attempt counted, audit otp_failed', async () => {
        const wrong = await postRaw(anonCtx, anon, `/id-checker/${S.active.uuid}/verify-otp`, { otp: '000000' });
        const att = sql(`select attempts from public_id_check_otps where card_uuid='${S.active.uuid}' order by created_at desc limit 1`);
        const audit = sql(`select count(*) from audit_logs where auditable_id='${S.active.id}' and event_type='public_id_check.otp_failed'`);
        if (wrong.status !== 422) throw new Error('wrong otp HTTP ' + wrong.status + ' ' + wrong.text);
        const bad = await postRaw(anonCtx, anon, `/id-checker/${S.active.uuid}/verify-otp`, { otp: 'abc' });
        return `HTTP 422 ${JSON.parse(wrong.text).message_key}; attempts=${att}; audit otp_failed=${audit}; malformed otp → ${bad.status}`;
    }, anon);
    await R.step('J4: right OTP reveals ONLY the safe field set (needs the delivered code: mail=smtp→127.0.0.1:587 unconfigured, SMS unconfigured)', async () => {
        let code = null;
        const m = [...logTail(S.otpLogOffset).matchAll(/verification code: (\d{6})|\*\*(\d{6})\*\*|<strong>(\d{6})<\/strong>/g)];
        if (m.length) code = m[m.length - 1][1] || m[m.length - 1][2] || m[m.length - 1][3];
        if (!code) throw new Error('BLOCKED by environment: code never delivered — ' + (S.otpDelivery || []).join(' / '));
        const ok = await postRaw(anonCtx, anon, `/id-checker/${S.active.uuid}/verify-otp`, { otp: code });
        if (ok.status !== 200) throw new Error('right otp HTTP ' + ok.status + ' ' + ok.text);
        const data = JSON.parse(ok.text);
        const keys = Object.keys(data.employee || {}).sort();
        const expected = ['card_number', 'card_status', 'employee_number', 'expires_at', 'full_name', 'issued_at', 'organization', 'organization_unit', 'position', 'verified_at'];
        if (JSON.stringify(keys) !== JSON.stringify(expected)) throw new Error('unexpected field set: ' + keys);
        const txt = JSON.stringify(data);
        if (txt.includes(EMP.phone) || /national|date_of_birth|email/i.test(txt)) throw new Error('contact/national data in OTP result: ' + txt.slice(0, 300));
        S.otpResult = data.employee;
        return `right=${ok.status}; fields=${keys.join(',')}`;
    }, anon);
    await R.step('J4: random UUID → neutral "invalid" page (200, found=false), send-otp 422', async () => {
        const u = '0b8d9c36-4b2e-4d7c-9f9d-aaaaaaaaaaaa';
        const r = await timedGoto(anon, `/id-checker/${u}`, 'id-checker/{random}');
        const { props } = await inertiaProps(anonCtx, `/id-checker/${u}`);
        if (r.status !== 200) throw new Error('HTTP ' + r.status);
        if (props?.card?.found !== false || props?.card?.status_code !== 'invalid') throw new Error('card=' + JSON.stringify(props?.card));
        const s = await postRaw(anonCtx, anon, `/id-checker/${u}/send-otp`, {});
        await shot(anon, 'idc-public-unknown');
        S.publicUnknownText = r.body.replace(/\n+/g, ' | ');
        return `card=${JSON.stringify(props.card)}; send-otp=${s.status} ${s.text.slice(0, 80)}; visible="${S.publicUnknownText.slice(0, 300)}"`;
    }, anon);
    await R.step('J4: malformed id and landing page do not 500', async () => {
        const a = await timedGoto(anon, '/id-checker/not-a-uuid-at-all', 'id-checker/{garbage}');
        const b = await timedGoto(anon, '/id-checker', 'id-checker landing');
        if (a.status >= 500 || b.status !== 200) throw new Error(`garbage=${a.status} landing=${b.status}`);
        await shot(anon, 'idc-public-landing');
        return `garbage=${a.status} landing=${b.status}`;
    }, anon);
    await R.step('J4: legacy /verify/card/{uuid} answers 301 to the id-checker', async () => {
        const r = await getRaw(anonCtx, `/verify/card/${S.active.uuid}`);
        if (r.status !== 301) throw new Error(`HTTP ${r.status} location=${r.location}`);
        if (!r.location || !r.location.endsWith(`/id-checker/${S.active.uuid}`)) throw new Error('location=' + r.location);
        return `301 → ${r.location}`;
    });

    // ───────── J5: suspend / lost / replace / revoke ─────────
    await R.step('J5: "suspend" — no suspend route exists; legacy POST /id-cards/{card}/incident status=suspended probed (officer)', async () => {
        const r = await postRaw(offCtx, off, `/id-cards/${S.active.id}/incident`, { status: 'suspended', reason: 'QA- suspend probe' });
        let follow = null;
        if (r.location) follow = await getRaw(offCtx, r.location.replace(BASE, ''));
        const c = card(S.active.id);
        if (c.status !== 'active') throw new Error('card status changed by incident probe: ' + c.status);
        return `HTTP ${r.status} → ${r.location} → GET ${follow?.status}; card still ${c.status} (CardStatus::Suspended unreachable from the web UI)`;
    }, off);
    if (!S.cardB) {
        // Fresh-run path: the active card is A → lose it, verify public state, replace it (B stays pending_print).
        await R.step('J5 [A]: officer reports the active card LOST (stand-in for suspend) → public checker: not valid, OTP refused, no PII', async () => {
            await timedGoto(off, `/id-cards/${S.active.id}`, 'id-cards/show (officer, active)');
            await modalAction(off, /Report Lost Card/i, 'QA- lost (stand-in for suspend)', /reported as lost/i);
            const c = card(S.active.id);
            if (c.status !== 'lost') throw new Error('status=' + c.status);
            const r = await timedGoto(anon, `/id-checker/${c.uuid}`, 'id-checker/{uuid} (anon, lost)');
            const { props } = await inertiaProps(anonCtx, `/id-checker/${c.uuid}`);
            const hits = piiHits(r.body + JSON.stringify(props?.card));
            if (hits.length) throw new Error('PII: ' + hits);
            if (props?.card?.checkable !== false || props?.card?.status_code !== 'lost') throw new Error('card=' + JSON.stringify(props?.card));
            const s = await postRaw(anonCtx, anon, `/id-checker/${c.uuid}/send-otp`, {});
            await shot(anon, 'idc-public-lost');
            S.publicLostText = r.body.replace(/\n+/g, ' | ');
            return `card=${JSON.stringify(props.card)}; send-otp=${s.status}; visible="${S.publicLostText.slice(0, 300)}"`;
        }, off);
        S.cardB = await replaceFlow(card(S.active.id), 'B');
    }
    await R.step('J5: approver REVOKES the current card (closest to "suspend") → revoked; public checker: revoked, no PII, OTP refused', async () => {
        const target = card(S.cardB.id);
        if (target.status !== 'revoked') {
            await timedGoto(app, `/id-cards/${target.id}`, 'id-cards/show (approver, before revoke)');
            await modalAction(app, /Revoke Card/i, 'QA- revoke stands in for suspend', /Card revoked/i);
        }
        const c = card(target.id);
        if (c.status !== 'revoked') throw new Error('status=' + c.status);
        if (!c.uuid) await inertiaProps(offCtx, `/id-cards/${c.id}`);
        const uuid = card(c.id).uuid;
        S.revoked = card(c.id);
        const r = await timedGoto(anon, `/id-checker/${uuid}`, 'id-checker/{uuid} (anon, revoked)');
        const { props } = await inertiaProps(anonCtx, `/id-checker/${uuid}`);
        const hits = piiHits(r.body + JSON.stringify(props?.card));
        if (hits.length) throw new Error('PII: ' + hits);
        if (props?.card?.status_code !== 'revoked' || props?.card?.checkable !== false) throw new Error('card=' + JSON.stringify(props?.card));
        const s = await postRaw(anonCtx, anon, `/id-checker/${uuid}/send-otp`, {});
        await shot(anon, 'idc-public-revoked');
        S.publicRevokedText = r.body.replace(/\n+/g, ' | ');
        const audit = sql(`select count(*) from audit_logs where event_type='card_revoked' and auditable_id='${c.id}'`);
        return `${c.number} status=${c.status} audit card_revoked=${audit}; public card=${JSON.stringify(props.card)}; send-otp=${s.status}; visible="${S.publicRevokedText.slice(0, 260)}"`;
    }, app);
    await R.step('J5: unknown vs revoked vs lost public pages — wording compared (enumeration oracle check)', async () => {
        const norm = (t) => (t || '').replace(/\s+/g, ' ').match(/Card status.*?Check another card/)?.[0] || '';
        const u = norm(S.publicUnknownText), rv = norm(S.publicRevokedText), lo = norm(S.publicLostText);
        return `unknown="${u}" | revoked="${rv}" | lost="${lo}" | identical=${u === rv && rv === lo}`;
    });
    await R.step('J5: replacing a REVOKED card — UI button, policy, request and action must agree', async () => {
        await timedGoto(app, `/id-cards/${S.revoked.id}`, 'id-cards/show (revoked)');
        const btn = await app.getByRole('button', { name: /Replace card/i }).count();
        const { props } = await inertiaProps(appCtx, `/id-cards/${S.revoked.id}`);
        const r = await postRaw(appCtx, app, `/id-cards/${S.revoked.id}/replace`, { reason: 'QA- replace revoked card' });
        const st = card(S.revoked.id).status;
        const info = `Replace button shown=${btn > 0} can.replace=${props?.can?.replace}; POST replace → HTTP ${r.status} ${r.text.slice(0, 80)}; card=${st}`;
        notes.push('Revoked-card replacement: ' + info);
        return info;
    }, app);

    // ───────── J6: preview / SVG / PNG export ─────────
    const X = S.active;
    await R.step('J6: card preview page and server SVG previews render (officer); audit previewed_svg', async () => {
        const p = await timedGoto(off, `/id-cards/${X.id}/preview`, 'id-cards/preview');
        if (p.status !== 200) throw new Error('preview HTTP ' + p.status);
        await shot(off, 'idc-card-preview');
        const f = await getRaw(offCtx, `/id-cards/${X.id}/preview/svg/front`);
        const b = await getRaw(offCtx, `/id-cards/${X.id}/preview/svg/back`);
        if (f.status !== 200 || !/svg/.test(f.ctype) || b.status !== 200) throw new Error(`front=${f.status} ${f.ctype} back=${b.status}`);
        const audit = sql(`select count(*) from audit_logs where event_type='card.previewed_svg' and auditable_id='${X.id}'`);
        return `preview page 200; svg front=${f.status} (${f.ctype}) back=${b.status}; audit previewed_svg=${audit}`;
    }, off);
    await R.step('J6: PNG export without Imagick is refused gracefully (expect 503 plain text, never 500)', async () => {
        const f = await getRaw(offCtx, `/id-cards/${X.id}/export/png/front`);
        const both = await getRaw(offCtx, `/id-cards/${X.id}/export/png/both`);
        if (f.status === 500 || both.status === 500) throw new Error(`front ${f.status} both ${both.status}: ${f.text.slice(0, 100)}`);
        const audit = sql(`select count(*) from audit_logs where event_type='card.exported_png_server' and auditable_id='${X.id}'`);
        return `front=${f.status} (${f.ctype}) "${f.text.slice(0, 110)}"; both=${both.status}; audit exported_png_server rows=${audit} (written although export failed)`;
    });
    await R.step('J6: "Export as PNG" button opens the export modal without console/page errors', async () => {
        const before = R.consoleErrors.length + R.pageErrors.length;
        await timedGoto(off, `/id-cards/${X.id}`, 'id-cards/show (export)');
        const btn = off.getByRole('button', { name: /Export as PNG/i });
        if (!(await btn.count())) throw new Error('Export as PNG button not shown to officer');
        await btn.first().click();
        await off.waitForTimeout(1500);
        await shot(off, 'idc-export-modal');
        const modalText = (await off.locator(MODAL).last().innerText().catch(() => '')).replace(/\n+/g, ' | ').slice(0, 300);
        const after = R.consoleErrors.length + R.pageErrors.length;
        return `modal="${modalText}"; new console/page errors=${after - before}`;
    }, off);
    await R.step('J6: PDF export — no card PDF route exists (routes.json: only png/svg) → observation', async () => {
        const routes = JSON.parse(fs.readFileSync(path.join(__dirname, '..', 'routes.json'), 'utf8'));
        const pdf = routes.filter((r) => /id-cards.*pdf|card.*pdf/i.test(r.uri || '')).map((r) => r.uri);
        return `pdf routes for cards: ${pdf.length ? pdf.join(', ') : 'none'}`;
    });

    // ───────── J7: authorization boundaries ─────────
    await R.step('J7: officer → /users is 403', async () => {
        const r = await timedGoto(off, '/users', 'users (officer)');
        if (r.status !== 403) throw new Error('HTTP ' + r.status);
        await shot(off, 'idc-officer-users-403');
        return 'HTTP 403';
    }, off);
    await R.step('J7: approver → /users, /card-requests/create, print-batches (index/create/show/store/mark-printed), prepare-print are 403', async () => {
        const batchId = S.batch?.id || sql(`select id from card_print_batches order by created_at desc limit 1`);
        const a = await timedGoto(app, '/users', 'users (approver)');
        const b = await timedGoto(app, '/card-requests/create', 'card-requests/create (approver)');
        const c = await timedGoto(app, '/print-batches', 'print-batches (approver)');
        const c2 = await timedGoto(app, '/print-batches/create', 'print-batches/create (approver)');
        const c3 = await timedGoto(app, `/print-batches/${batchId}`, 'print-batches/show (approver)');
        const d = await postRaw(appCtx, app, `/print-batches/${batchId}/mark-printed`, {});
        const e = await postRaw(appCtx, app, '/print-batches', { card_ids: [DEMO02.id] });
        const f = await postRaw(appCtx, app, `/id-cards/${DEMO02.id}/prepare-print`, {});
        const info = `users=${a.status} requestCreate=${b.status} batchIndex=${c.status} batchCreate=${c2.status} batchShow=${c3.status} markPrinted=${d.status} batchStore=${e.status} preparePrint=${f.status}`;
        if ([a, b, c, c2, c3, d, e, f].some((x) => x.status !== 403)) throw new Error(info);
        await shot(app, 'idc-approver-batches-403');
        return info;
    }, app);
    await R.step('J7: officer → reject/approve URLs on an approved request, and /nfc-management', async () => {
        const rej = await postRaw(offCtx, off, `/card-requests/${S.reqA.id}/reject`, { rejection_reason: 'QA- officer reject probe' });
        const n = await timedGoto(off, '/nfc-management', 'nfc-management (officer)');
        return `reject=${rej.status} (request stays ${req(`id='${S.reqA.id}'`).status}); nfc-management=${n.status}`;
    }, off);
    await R.step('J7: anonymous → staff card pages/artifacts redirect to login (no data leak)', async () => {
        const snap = S.snapshot || sql(`select id from id_card_print_snapshots order by created_at desc limit 1`);
        const a = await getRaw(anonCtx, '/card-requests');
        const b = await getRaw(anonCtx, `/id-cards/${X.id}`);
        const c = await getRaw(anonCtx, `/id-cards/${X.id}/preview/svg/front`);
        const d = await getRaw(anonCtx, `/id-cards/${X.id}/print/${snap}/front`);
        const bad = [a, b, c, d].filter((x) => !(x.status === 302 && /\/login/.test(x.location || '')));
        if (bad.length) throw new Error(JSON.stringify([a, b, c, d].map((x) => [x.status, x.location])));
        return 'all 302 → /login';
    });

    // ───────── J8: Amharic leak scan ─────────
    const amCtx = await browser.newContext(); const am = await newPageIn(amCtx, 'am'); R.attach(am);
    await R.step('J8: Amharic — card request list: Latin text inventory', async () => {
        await login(am, 'qa.cardofficer@demo.local', 'password', {});
        const r = await timedGoto(am, '/card-requests', 'card-requests (am)');
        if (r.status !== 200) throw new Error('HTTP ' + r.status);
        await shot(am, 'idc-am-card-requests');
        S.amListLeaks = await latinLeaks(am);
        return `${S.amListLeaks.length} Latin strings: ${S.amListLeaks.join(' ‖ ')}`;
    }, am);
    await R.step('J8: Amharic — card request detail + card page: Latin text inventory', async () => {
        await timedGoto(am, `/card-requests/${S.reqA.id}`, 'card-requests/show (am)');
        S.amShowLeaks = await latinLeaks(am);
        await shot(am, 'idc-am-request-show');
        await timedGoto(am, `/id-cards/${X.id}`, 'id-cards/show (am)');
        S.amCardLeaks = await latinLeaks(am);
        await shot(am, 'idc-am-card-show');
        return `request show (${S.amShowLeaks.length}): ${S.amShowLeaks.join(' ‖ ')} /// card show (${S.amCardLeaks.length}): ${S.amCardLeaks.join(' ‖ ')}`;
    }, am);
    const amAnonCtx = await browser.newContext(); const amAnon = await newPageIn(amAnonCtx, 'am'); R.attach(amAnon);
    await R.step('J8: Amharic — public checker (landing, active card page, unknown): Latin text inventory', async () => {
        await timedGoto(amAnon, '/id-checker', 'id-checker landing (am)');
        const landing = await latinLeaks(amAnon);
        await shot(amAnon, 'idc-am-public-landing');
        await timedGoto(amAnon, `/id-checker/${X.uuid}`, 'id-checker/{uuid} (am)');
        const cardPage = await latinLeaks(amAnon);
        await shot(amAnon, 'idc-am-public-card');
        await timedGoto(amAnon, '/id-checker/0b8d9c36-4b2e-4d7c-9f9d-aaaaaaaaaaaa', 'id-checker/{random} (am)');
        const unknown = await latinLeaks(amAnon);
        S.amPublicLeaks = { landing, cardPage, unknown };
        return `landing: ${landing.join(' ‖ ')} /// card: ${cardPage.join(' ‖ ')} /// unknown: ${unknown.join(' ‖ ')}`;
    }, amAnon);

    // ───────── wrap-up ─────────
    await R.step('Wrap-up: local.ERROR lines written to laravel.log during this run (shared log, attributed by userId)', async () => {
        const errs = logTail(logStart).split('\n').filter((l) => /local\.ERROR/.test(l)).map((l) => ({ line: l.slice(0, 200), userId: (l.match(/"userId":(\d+)/) || [])[1] || null }));
        S.logErrors = errs;
        const env = errs.filter((e) => /OTP email failed|127\.0\.0\.1:587/.test(e.line));
        const mine = errs.filter((e) => [USERS.admin, USERS.officer, USERS.approver, null].includes(e.userId) && !env.includes(e));
        if (env.length) notes.push(`${env.length} 'Public ID check OTP email failed' ERROR line(s): system_settings.mail_mailer=smtp with empty host → 127.0.0.1:587 refused (environment)`);
        if (mine.length) throw new Error(mine.length + ' ERROR lines from this run: ' + mine.map((e) => e.line).slice(0, 3).join(' || '));
        return errs.length ? `${errs.length} ERROR lines, all from other users (${[...new Set(errs.map((e) => e.userId))].join(',')}): ${errs[0].line.slice(0, 120)}` : 'none';
    });

    await R.step('Teardown: clear the QA email from EMP-0003 (restore seeded state)', async () => {
        const after = await setEmployeeEmail('');
        if (after !== '') throw new Error('email still "' + after + '"');
        return 'email cleared';
    }, adm);

    fs.writeFileSync(path.join(__dirname, 'results', '20-idcards-state.json'), JSON.stringify({ S, timings, slow: timings.filter((t) => t.ms > 3000), notes }, null, 2));
    await browser.close();
    R.save();
    console.log('SLOW (>3s):', JSON.stringify(timings.filter((t) => t.ms > 3000)));
    console.log('NOTES:\n  ' + notes.join('\n  '));
})().catch((e) => { console.error('FATAL', e); process.exit(1); });
