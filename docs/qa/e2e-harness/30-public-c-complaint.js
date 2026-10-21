'use strict';
/* Journeys 2, 3, 4, 7 (public part), 8 (public part): anonymous complaint / tip-off submission on the
   institution QR and the employee QR, validation, uploads, throttling, tracking, XSS, Amharic. */
const { BASE, Recorder, newBrowser, visit, shot } = require('./lib');
const fs = require('fs');
const path = require('path');
const { execSync } = require('child_process');

const STATE_FILE = path.join(__dirname, 'results', '30-public-state.json');
const state = JSON.parse(fs.readFileSync(STATE_FILE, 'utf8'));
const FIX = path.join(__dirname, 'fixtures');
const XSS1 = '<img src=x onerror=alert(1)>';
const XSS2 = '"><script>window.__xss=1</script>';
const stamp = () => new Date().toISOString().replace(/[-:TZ.]/g, '').slice(0, 14);

/** Reset the public submission throttle for this address + token (test isolation only; cache store = database). */
function clearThrottle(token) {
    const php = `RateLimiter::clear(md5('service-feedback-submit'.'sf-submit-ip:127.0.0.1')); RateLimiter::clear(md5('service-feedback-submit'.'sf-submit:${token}|127.0.0.1')); echo 'cleared';`;
    return execSync(`cd /home/user/EUISIS && php artisan tinker --execute="${php.replace(/"/g, '\\"')}" 2>&1 | tail -1`).toString().trim();
}

/** Inertia page object (props + version) for a URL, read from the server-rendered data-page attribute. */
async function pageProps(page, url) {
    const html = await (await page.request.get(`${BASE}${url}`)).text();
    const m = html.match(/<script data-page="app" type="application\/json">([\s\S]*?)<\/script>/);
    if (!m) throw new Error('no data-page script in HTML');
    return JSON.parse(m[1]);
}
async function xsrf(page) {
    return decodeURIComponent((await page.context().cookies()).find((c) => c.name === 'XSRF-TOKEN')?.value || '');
}
/** Direct multipart POST of a complaint (no browser form), no redirect following. Returns {status, headers}. */
async function postComplaint(page, token, fields) {
    const r = await page.request.post(`${BASE}/feedback/${token}/complaint`, {
        maxRedirects: 0,
        headers: { 'X-XSRF-TOKEN': await xsrf(page), 'X-Requested-With': 'XMLHttpRequest', Accept: 'text/html, application/xhtml+xml' },
        multipart: fields,
    });
    return { status: r.status(), headers: r.headers() };
}
/** The session's flashed validation errors, read through an Inertia JSON GET of the form page. */
async function flashedErrors(page, url, version) {
    const r = await page.request.get(`${BASE}${url}`, { headers: { 'X-Inertia': 'true', 'X-Inertia-Version': version, 'X-Requested-With': 'XMLHttpRequest' } });
    const j = await r.json().catch(() => null);
    return { status: r.status(), errors: j?.props?.errors ?? null, submitted: j?.props?.submitted ?? null };
}

/** Open the org complaint form and walk the steps. Returns the submit POST response. */
async function orgComplaint(page, opts) {
    await visit(page, `/feedback/${state.orgToken}/complaint`);
    // Step 1 (service): category chip
    if (opts.category !== false) {
        const chip = page.locator('[role=radiogroup] button[role=radio]').filter({ hasText: /QA- Service delay/ }).first();
        if (await chip.count()) {
            await chip.click(); await page.waitForTimeout(500);
            opts.chipChecked = await chip.getAttribute('aria-checked');
            if (opts.chipChecked !== 'true') { await chip.click(); await page.waitForTimeout(500); opts.chipRetried = true; opts.chipChecked = await chip.getAttribute('aria-checked'); }
        }
    }
    await page.getByRole('button', { name: /^Next$/ }).click();
    await page.waitForTimeout(300);
    // Step 2 (details)
    if (opts.message !== undefined) await page.fill('#entry-message', opts.message);
    if (opts.location !== undefined) await page.fill('#incident-location', opts.location);
    if (opts.files) {
        await page.locator('#evidence-document').setInputFiles(opts.files);
        await page.waitForTimeout(400);
    }
    if (opts.stopAfterDetails) return null;
    await page.getByRole('button', { name: /^Next$/ }).click();
    await page.waitForTimeout(300);
    // Step 3 (about you)
    if (opts.name !== undefined) await page.fill('#client_name', opts.name);
    if (opts.phone !== undefined) await page.fill('#client_contact', opts.phone);
    if (opts.email) await page.fill('#client_email', opts.email);
    if (opts.org) await page.fill('#submitter_organization', opts.org);
    opts.reviewText = (await page.locator('main, body').first().innerText()).replace(/\s+/g, ' ');
    const respP = page.waitForResponse((r) => r.request().method() === 'POST' && r.url().includes('/feedback/'), { timeout: 60000 }).catch(() => null);
    await page.getByRole('button', { name: /Submit Complaint/ }).click();
    const resp = await respP;
    await page.waitForLoadState('networkidle').catch(() => {});
    // Wait until the Send button stops showing "Submitting…" (upload + redirect + re-render).
    await page.locator('button[type=submit]').filter({ hasText: /Submitting|በመላክ/ }).first().waitFor({ state: 'hidden', timeout: 30000 }).catch(() => {});
    await page.waitForLoadState('networkidle').catch(() => {});
    await page.waitForTimeout(800);
    return resp;
}

(async () => {
    const R = new Recorder('30-public-c-complaint');
    const { browser, context, page: page0 } = await newBrowser({ viewport: { width: 390, height: 844 } });
    let page = page0;
    R.attach(page);
    const dialogs = [];
    page.on('dialog', async (d) => { dialogs.push(d.message()); await d.dismiss(); });
    state.submissions = state.submissions || [];
    /** A fresh anonymous session (new cookies, no sessionStorage draft) with the throttle counters reset. */
    async function freshVisitor(label) {
        await R.step(`[${label}] fresh anonymous browser context + throttle reset`, async () => {
            const { newPageIn } = require('./lib');
            const ctx = await browser.newContext({ viewport: { width: 390, height: 844 } });
            page = await newPageIn(ctx);
            R.attach(page);
            page.on('dialog', async (d) => { dialogs.push(d.message()); await d.dismiss(); });
            return clearThrottle(state.orgToken) + ' / ' + clearThrottle(state.empToken);
        });
    }

    await R.step('Throttle reset (tinker) so earlier runs do not skew the results', async () => clearThrottle(state.orgToken) + ' / ' + clearThrottle(state.empToken));

    await R.step('Institution QR landing /feedback/{token} offers Feedback, Tip-off and Complaint', async () => {
        const r = await visit(page, `/feedback/${state.orgToken}`);
        const txt = await page.locator('main, body').first().innerText();
        await shot(page, 'public-org-landing');
        const found = ['Feedback', 'Tip-off', 'Complaint'].filter((k) => new RegExp(k, 'i').test(txt));
        if (found.length < 3) throw new Error('choices missing: ' + found.join(','));
        if (!/Public Service and Human Resource Development Bureau/.test(txt)) throw new Error('institution name not shown');
        return `status=${r.status} choices=${found.join('/')}`;
    }, page);

    await R.step('Complaint form shows the QA category chip and the step indicator', async () => {
        await visit(page, `/feedback/${state.orgToken}/complaint`);
        const txt = await page.locator('main, body').first().innerText();
        await shot(page, 'public-org-complaint-step1');
        if (!/QA- Service delay/.test(txt)) throw new Error('category chip not shown');
        if (!/Step 1 of/.test(txt)) throw new Error('no step indicator');
        return txt.match(/Step 1 of \d/)[0];
    }, page);

    await R.step('Missing required fields (empty narrative) → client-side field error on the step, no POST, no 500', async () => {
        const posts = [];
        page.on('request', (rq) => { if (rq.method() === 'POST') posts.push(rq.url()); });
        await orgComplaint(page, { message: '', stopAfterDetails: true });
        await page.getByRole('button', { name: /^Next$/ }).click();
        await page.waitForTimeout(400);
        const alerts = await page.locator('[role=alert]').allInnerTexts();
        await shot(page, 'public-org-complaint-missing-client');
        if (!alerts.some((a) => /required/i.test(a))) throw new Error('no required-field error: ' + JSON.stringify(alerts));
        return `alerts=${JSON.stringify(alerts)} posts=${posts.length}`;
    }, page);

    await R.step('Server-side: POST complaint with empty message/name/phone (bypassing the browser checks) → 302 back with field errors, not 500', async () => {
        const formUrl = `/feedback/${state.orgToken}/complaint`;
        const pg = await pageProps(page, formUrl);
        const post = await postComplaint(page, state.orgToken, { form_token: pg.props.formToken, message: '', client_name: '', client_contact: '' });
        if (post.status >= 500) throw new Error('server ' + post.status);
        const flash = await flashedErrors(page, formUrl, pg.version);
        if (!flash.errors || Object.keys(flash.errors).length === 0) throw new Error(`no field errors (post=${post.status}, get=${flash.status})`);
        return `POST=${post.status} errors=${JSON.stringify(flash.errors)}`;
    }, page);

    let reference = null;
    await freshVisitor('complaint');
    await R.step('Submit a complaint with category + XSS payloads + 100 KB PDF → receipt with PSC reference', async () => {
        const msg = `QA- complaint ${stamp()} ${XSS1} ${XSS2} end`;
        const o = {
            message: msg, location: `QA- Kirkos office ${XSS1}`, files: [path.join(FIX, 'qa-100kb.pdf')],
            name: `QA Citizen ${XSS2}`, phone: '0911223344', email: 'qa.citizen@example.com', org: `QA Org ${XSS1}`,
        };
        const resp = await orgComplaint(page, o);
        state.firstComplaintUi = { chipChecked: o.chipChecked, reviewHasCategory: /QA- Service delay/.test(o.reviewText || '') };
        const txt = await page.locator('body').innerText();
        await shot(page, 'public-org-complaint-receipt');
        const m = txt.match(/PSC-\d{4}-[A-Z0-9-]+/);
        if (!m) throw new Error(`no reference; POST=${resp?.status()} text=${txt.replace(/\s+/g, ' ').slice(0, 300)}`);
        reference = m[0];
        state.submissions.push({ kind: 'complaint', target: 'organization', reference, message: msg });
        return `POST ${resp?.status()} reference=${reference} chipChecked=${o.chipChecked} chipRetried=${Boolean(o.chipRetried)} reviewShowsCategory=${/QA- Service delay/.test(o.reviewText || '')}`;
    }, page);

    await R.step('DB: complaint row persisted with category, attachment (100 KB PDF) and raw (unsanitised) narrative', async () => {
        const row = execSync(`psql -h /tmp -p 54329 -U postgres -d euisis_app -At -c "select s.id, s.status, s.type, s.target_type, c.code, (select count(*) from employee_service_feedback_attachments a where a.employee_service_feedback_id=s.id) as files, s.comment from employee_service_feedback s left join public_feedback_categories c on c.id=s.category_id where s.reference_number='${reference}'"`).toString().trim();
        if (!row) throw new Error('row not found for ' + reference);
        const [id, status, type, target, cat, files, comment] = row.split('|');
        state.submissions[state.submissions.length - 1].id = id;
        if (type !== 'complaint' || target !== 'organization') throw new Error('type/target: ' + row);
        if (!/QA-CAT/.test(cat)) throw new Error('category not stored: ' + cat);
        if (files !== '1') throw new Error('attachments stored = ' + files);
        return `id=${id} status=${status} category=${cat} files=${files} storedNarrativeContainsRawTag=${/<img src=x onerror/.test(comment)}`;
    }, page);

    await R.step('XSS: receipt page did not execute scripts (no dialog, window.__xss undefined)', async () => {
        const x = await page.evaluate(() => window.__xss);
        if (dialogs.length || x) throw new Error(`executed: dialogs=${dialogs.length} __xss=${x}`);
        return 'no alert, __xss=undefined';
    }, page);

    await freshVisitor('3MB');
    await R.step('3 MB PDF attachment: report server behaviour (policy says PDF ≤ 5 MB; task expected 2 MB refusal)', async () => {
        const resp = await orgComplaint(page, {
            message: `QA- 3MB pdf test ${stamp()}`, files: [path.join(FIX, 'qa-3mb.pdf')], name: 'QA Citizen Big', phone: '0911223345',
        });
        const txt = await page.locator('body').innerText();
        const alerts = await page.locator('[role=alert]').allInnerTexts();
        await shot(page, 'public-org-complaint-3mb');
        const m = txt.match(/PSC-\d{4}-[A-Z0-9-]+/);
        if (m) state.submissions.push({ kind: 'complaint', target: 'organization', reference: m[0], note: '3MB' });
        return `POST=${resp?.status()} accepted=${Boolean(m)} ref=${m ? m[0] : '-'} alerts=${JSON.stringify(alerts)}`;
    }, page);

    await freshVisitor('exe');
    await R.step('EXE renamed to .pdf (MZ header): refused by content sniffing with a field error, not stored, no 500', async () => {
        const resp = await orgComplaint(page, {
            message: `QA- exe renamed test ${stamp()}`, files: [path.join(FIX, 'qa-exe-renamed.pdf')], name: 'QA Citizen Exe', phone: '0911223346',
        });
        const txt = await page.locator('body').innerText();
        const alerts = await page.locator('[role=alert]').allInnerTexts();
        await shot(page, 'public-org-complaint-exe');
        const m = txt.match(/PSC-\d{4}-[A-Z0-9-]+/);
        if (m) throw new Error('disguised executable was ACCEPTED, reference ' + m[0]);
        if (resp && resp.status() >= 500) throw new Error('500 on upload');
        const visible = (txt.match(/[^\n]*(Only photos|accepted|Too many|failed)[^\n]*/i) || [''])[0].trim();
        if (!alerts.some((a) => /only photos|accepted|not accepted|file/i.test(a))) throw new Error('no attachment error visible: ' + JSON.stringify(alerts) + ' / visible="' + visible + '"');
        return `POST=${resp?.status()} alerts=${JSON.stringify(alerts)}`;
    }, page);

    await freshVisitor('tip-off');

    await R.step('Submit an anonymous Tip-off (suggestion) on the institution QR with category and XSS payload → receipt', async () => {
        await visit(page, `/feedback/${state.orgToken}/suggestion`);
        await page.locator('input[name=tip_type]').first().check();
        const msg = `QA- tipoff ${stamp()} ${XSS1} ${XSS2}`;
        await page.fill('#entry-message', msg);
        await page.getByRole('button', { name: /^Next$/ }).click();
        await page.waitForTimeout(300);
        const chip = page.locator('[role=radiogroup] button[role=radio]').filter({ hasText: /QA- Service delay/ }).first();
        if (await chip.count()) await chip.click();
        await page.getByRole('button', { name: /^Next$/ }).click();
        await page.waitForTimeout(300);
        // leave identity empty: anonymous
        const respP = page.waitForResponse((r) => r.request().method() === 'POST' && r.url().includes('/feedback/'), { timeout: 60000 }).catch(() => null);
        await page.getByRole('button', { name: /Submit Tip-off/ }).click();
        const resp = await respP;
        await page.waitForLoadState('networkidle').catch(() => {});
        await page.getByText(/received|submitted/i).first().waitFor({ timeout: 15000 }).catch(() => {});
        await page.waitForTimeout(500);
        const txt = await page.locator('body').innerText();
        await shot(page, 'public-org-tipoff-receipt');
        if (!/received|submitted/i.test(txt)) throw new Error(`no receipt; POST=${resp?.status()} ${txt.slice(0, 200)}`);
        const ref = (txt.match(/PSC-\d{4}-[A-Z0-9-]+|[A-Z]{2,4}-\d{4}-[A-Z0-9-]+/) || [null])[0];
        const row = execSync(`psql -h /tmp -p 54329 -U postgres -d euisis_app -At -c "select id, status, tip_type, client_name is null, reference_number from employee_service_feedback where type='suggestion' and comment like 'QA- tipoff ${msg.slice(11, 25)}%'"`).toString().trim();
        state.submissions.push({ kind: 'suggestion', target: 'organization', id: row.split('|')[0], reference: ref, message: msg });
        return `POST=${resp?.status()} referenceShown=${ref ?? 'none'} db=${row}`;
    }, page);

    await freshVisitor('employee-qr');
    await R.step('Employee QR landing /service-feedback/{token} shows the employee name and the three choices', async () => {
        const r = await visit(page, `/service-feedback/${state.empToken}`);
        const txt = await page.locator('body').innerText();
        await shot(page, 'public-emp-landing');
        if (!/Demo Employee/.test(txt)) throw new Error('employee name not shown: ' + txt.slice(0, 200));
        return `status=${r.status}`;
    }, page);

    await R.step('Employee QR complaint (single screen) with category + XSS + 100 KB PDF → receipt with PSC reference', async () => {
        await visit(page, `/service-feedback/${state.empToken}/complaint`);
        const msg = `QA- employee complaint ${stamp()} ${XSS1} ${XSS2}`;
        await page.fill('#entry-message', msg);
        const chip = page.locator('[role=radiogroup] button[role=radio]').filter({ hasText: /QA- Service delay/ }).first();
        const hasChip = await chip.count();
        if (hasChip) await chip.click();
        await page.locator('#evidence-document').setInputFiles([path.join(FIX, 'qa-100kb.pdf')]);
        await page.fill('#client_name', 'QA Citizen Emp');
        await page.fill('#client_contact', '0911223347');
        const respP = page.waitForResponse((r) => r.request().method() === 'POST' && r.url().includes('/service-feedback/'), { timeout: 60000 }).catch(() => null);
        await page.getByRole('button', { name: /Submit Complaint/ }).click();
        const resp = await respP;
        await page.waitForLoadState('networkidle').catch(() => {});
        await page.locator('button[type=submit]').filter({ hasText: /Submitting|በመላክ/ }).first().waitFor({ state: 'hidden', timeout: 30000 }).catch(() => {});
        await page.waitForLoadState('networkidle').catch(() => {});
        await page.waitForTimeout(800);
        const txt = await page.locator('body').innerText();
        await shot(page, 'public-emp-complaint-receipt');
        const m = txt.match(/PSC-\d{4}-[A-Z0-9-]+/);
        if (!m) throw new Error(`no reference; POST=${resp?.status()} ${txt.replace(/\s+/g, ' ').slice(0, 250)}`);
        const id = execSync(`psql -h /tmp -p 54329 -U postgres -d euisis_app -At -c "select id from employee_service_feedback where reference_number='${m[0]}'"`).toString().trim();
        state.submissions.push({ kind: 'complaint', target: 'employee', reference: m[0], id, message: msg });
        return `POST=${resp?.status()} ref=${m[0]} categoryChipShown=${Boolean(hasChip)}`;
    }, page);

    await R.step('Public tracking page by reference: probe common URLs (docs say none is built)', async () => {
        const out = [];
        for (const u of ['/track', '/feedback/track', `/track/${reference}`, `/complaints/${reference}`, `/feedback/${state.orgToken}/track`, '/service-feedback/track']) {
            const r = await page.goto(`${BASE}${u}`, { waitUntil: 'domcontentloaded' });
            out.push(`${u}→${r.status()}`);
        }
        const receiptNote = await (async () => { await visit(page, `/feedback/${state.orgToken}`); return (await page.locator('body').innerText()).includes('track'); })();
        return out.join(', ') + ` | landing mentions "track": ${receiptNote}`;
    }, page);

    await freshVisitor('replay');
    await R.step('Replaying a consumed one-time form token is refused (form_token error), no 500', async () => {
        const formUrl = `/feedback/${state.orgToken}/complaint`;
        const pg = await pageProps(page, formUrl);
        const fields = () => ({ form_token: pg.props.formToken, message: 'QA- replay probe ' + Date.now(), client_name: 'QA Replay', client_contact: '0911223348' });
        const first = await postComplaint(page, state.orgToken, fields());
        const f1 = await flashedErrors(page, formUrl, pg.version);
        const second = await postComplaint(page, state.orgToken, fields());
        const f2 = await flashedErrors(page, formUrl, pg.version);
        if (second.status >= 500) throw new Error('500 on replay');
        if (f2.submitted) throw new Error('replayed form token was ACCEPTED');
        return `first: POST=${first.status} submitted=${f1.submitted} errors=${JSON.stringify(f1.errors)} | replay: POST=${second.status} submitted=${f2.submitted} errors=${JSON.stringify(f2.errors)}`;
    }, page);

    await freshVisitor('abuse');

    await R.step('Abuse: 15 rapid complaint submissions on one QR from one address → throttle (expect 429 after 3 per code)', async () => {
        const results = [];
        const formUrl = `/feedback/${state.orgToken}/complaint`;
        for (let i = 1; i <= 15; i++) {
            const pg = await pageProps(page, formUrl);
            const out = await postComplaint(page, state.orgToken, { form_token: pg.props.formToken, message: `QA- throttle probe ${i} ${Date.now()}`, client_name: 'QA Throttle', client_contact: '0911223349' });
            results.push({ status: out.status, retryAfter: out.headers['retry-after'], remaining: out.headers['x-ratelimit-remaining'] });
        }
        const first429 = results.findIndex((r) => r.status === 429) + 1;
        state.throttleProbe = results;
        if (!first429) throw new Error('no 429 in 15 submissions: ' + JSON.stringify(results.map((r) => r.status)));
        return `statuses=${results.map((r) => r.status).join(',')} first429=#${first429} retry-after=${results[first429 - 1].retryAfter}s remaining-before=${results[first429 - 2]?.remaining}`;
    }, page);

    await R.step('Abuse (UI): what the citizen sees when throttled — submit via the form while limited', async () => {
        const resp = await orgComplaint(page, { message: `QA- throttled ui ${stamp()}`, name: 'QA Throttle UI', phone: '0911223350' });
        await page.waitForTimeout(1000);
        const txt = await page.locator('body').innerText();
        await shot(page, 'public-org-complaint-throttled');
        const alerts = await page.locator('[role=alert], [role=status]').allInnerTexts();
        return `POST=${resp?.status()} url=${page.url().replace(BASE, '')} visible="${(txt.match(/[^\n]*(too many|wait|try again|429|limit)[^\n]*/i) || ['(none)'])[0].trim().slice(0, 160)}" alerts=${JSON.stringify(alerts).slice(0, 200)}`;
    }, page);

    await R.step('Throttle reset after the abuse test (leave shared state usable)', async () => clearThrottle(state.orgToken) + ' / ' + clearThrottle(state.empToken));

    // ── Amharic ──
    await R.step('Amharic: public complaint form renders in Amharic; list any English leakage; date picker calendar', async () => {
        const { browser: ab, page: ap } = await newBrowser({ uiLocale: 'am', viewport: { width: 390, height: 844 } });
        R.attach(ap);
        await visit(ap, `/feedback/${state.orgToken}/complaint`);
        const txt = await ap.locator('main, body').first().innerText();
        await shot(ap, 'public-org-complaint-am-step1');
        await ap.getByRole('button', { name: /ቀጣይ|Next/ }).click().catch(() => {});
        await ap.waitForTimeout(300);
        const txt2 = await ap.locator('main, body').first().innerText();
        await shot(ap, 'public-org-complaint-am-step2');
        await ap.getByRole('button', { name: /ቀጣይ|Next/ }).click().catch(() => {});
        await ap.waitForTimeout(300);
        const txt3 = await ap.locator('main, body').first().innerText();
        await shot(ap, 'public-org-complaint-am-step3');
        const all = [txt, txt2, txt3].join('\n');
        const english = Array.from(new Set(all.split('\n').map((l) => l.trim()).filter((l) => /^[A-Za-z][A-Za-z ,.'()/:&-]{3,}$/.test(l) && !/^(QA-|PSC-|Addis One|AA-IEMSS)/.test(l))));
        const dateProbe = await ap.locator('#service_date, #incident-date, input[id*=date]').first().getAttribute('placeholder').catch(() => null);
        const datePickerText = await ap.locator('[id*=date]').first().innerText().catch(() => '');
        await ab.close();
        const ethiopic = /[ሀ-፿]/.test(all);
        return `amharicPresent=${ethiopic} englishLines=${JSON.stringify(english).slice(0, 500)} datePlaceholder=${dateProbe} dateText="${datePickerText.slice(0, 40)}"`;
    });

    fs.writeFileSync(STATE_FILE, JSON.stringify(state, null, 2));
    await browser.close();
    R.save();
})().catch((e) => { console.error('FATAL', e); process.exit(1); });
