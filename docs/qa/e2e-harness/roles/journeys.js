'use strict';
// Cross-role journeys driven through the UI.
//  A. ID card: ID Card Officer requests, cannot approve own request; ID Card Approver approves.
//  B. Employee self-service: Employee role signs in to My Portal and records a daily activity.
const fs = require('fs');
const path = require('path');
const { launchChrome, totp, sleep } = require('./cdp');

const BASE = process.env.BASE_URL || 'http://127.0.0.1:8123';
const SCRATCH = path.resolve(__dirname, '..');
const SHOTS = path.join(__dirname, 'shots');
const { password, accounts } = JSON.parse(fs.readFileSync(path.join(SCRATCH, 'qa-users.json'), 'utf8'));
const acct = (role) => accounts.find((a) => a.role === role);
const results = [];
const step = async (journey, label, fn) => {
    try { const info = await fn(); results.push({ journey, label, status: 'PASS', info: info ?? null }); console.log(`  PASS ${journey} | ${label}${info ? ' — ' + info : ''}`); return true; }
    catch (e) { const msg = String(e.message || e).slice(0, 300); results.push({ journey, label, status: 'FAIL', error: msg }); console.log(`  FAIL ${journey} | ${label} — ${msg}`); return false; }
};
const props = (page) => page.eval(`(history.state && history.state.page && history.state.page.props) || null`);
const flash = (page) => page.eval(`(() => { const p = history.state && history.state.page && history.state.page.props; return p ? JSON.stringify(p.flash || {}) : null; })()`);

async function login(page, email) {
    await page.goto(`${BASE}/login`);
    await page.waitFor(`!!document.querySelector('input[name="email"]')`);
    await page.fill('input[name="email"]', email);
    await page.fill('input[name="password"]', password);
    await page.click('button[type="submit"]');
    if (!await page.waitFor(`!/\\/login(\\?|$)/.test(location.pathname)`, 45000)) throw new Error('login stuck');
    await page.quiet(8000);
    return page.eval('location.pathname');
}
async function markAndClick(page, findExpr, attr) {
    const ok = await page.eval(`(() => { const el = (${findExpr}); if (!el) return false; el.setAttribute('${attr}', '1'); return true; })()`);
    if (!ok) throw new Error('element not found: ' + findExpr.slice(0, 120));
    await page.click(`[${attr}]`);
}
async function postJson(page, url, body) {
    return page.eval(`fetch(${JSON.stringify(url)}, { method: 'POST', headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-XSRF-TOKEN': decodeURIComponent((document.cookie.match(/XSRF-TOKEN=([^;]+)/) || [])[1] || '') }, body: JSON.stringify(${JSON.stringify(body)}) }).then(r => r.status)`);
}

(async () => {
    const browser = await launchChrome(9600 + Math.floor(Math.random() * 100), path.join(SCRATCH, 'chrome-journeys-' + process.pid));

    // ---------------- A. ID card request segregation ----------------
    const J = 'ID card request';
    const officer = await browser.newPage({ base: BASE });
    let requestId = null; let employee = null;
    await step(J, 'ID Card Officer signs in', async () => login(officer, acct('ID Card Officer').email));
    await step(J, 'Officer opens New card request and sees eligible employees', async () => {
        const g = await officer.goto(`${BASE}/card-requests/create`);
        if (g.status !== 200) throw new Error('HTTP ' + g.status);
        const p = await props(officer);
        const eligible = (p.employees || []).filter((e) => (e.eligible_request_types || []).includes('new'));
        if (!eligible.length) throw new Error(`no employee eligible for a new card (listed ${(p.employees || []).length})`);
        employee = eligible[0];
        return `${(p.employees || []).length} listed, ${eligible.length} eligible; picking ${employee.employee_number}`;
    });
    await step(J, 'Officer selects the employee and submits', async () => {
        if (!employee) throw new Error('no employee');
        await markAndClick(officer, `[...document.querySelectorAll('label')].find(l => l.innerText.includes(${JSON.stringify(employee.employee_number)}))?.querySelector('input[type=checkbox]')`, 'data-qa-emp');
        await sleep(300);
        await markAndClick(officer, `document.querySelector('button[type=submit]')`, 'data-qa-submit');
        const left = await officer.waitFor(`!/\\/card-requests\\/create/.test(location.pathname) ? location.pathname : false`, 45000);
        if (!left) throw new Error('stayed on create: ' + (await officer.eval(`[...document.querySelectorAll('[role=alert]')].map(e=>e.innerText).join(' | ')`)));
        await officer.quiet(8000);
        const m = left.match(/card-requests\/([0-9a-f-]{20,})/);
        if (m) requestId = m[1];
        if (!requestId) {
            await officer.goto(`${BASE}/card-requests`);
            const p = await props(officer);
            const rows = (p.cardRequests && (p.cardRequests.data || p.cardRequests)) || (p.requests && (p.requests.data || p.requests)) || [];
            const row = rows.find((r) => JSON.stringify(r).includes(employee.employee_number));
            requestId = row && row.id;
        }
        if (!requestId) throw new Error('could not find the new request after submit; landed ' + left);
        return `landed ${left}; flash ${await flash(officer)}; request ${requestId}`;
    });
    await step(J, 'Officer sees no Approve/Reject on own request', async () => {
        const g = await officer.goto(`${BASE}/card-requests/${requestId}`);
        if (g.status !== 200) throw new Error('HTTP ' + g.status);
        const p = await props(officer);
        await officer.screenshot(path.join(SHOTS, 'journey-card-officer-show.png'));
        if (p.can && (p.can.approve || p.can.reject)) throw new Error('officer offered approve/reject: ' + JSON.stringify(p.can));
        return `status=${p.cardRequest && p.cardRequest.status} can=${JSON.stringify(p.can)}`;
    });
    await step(J, 'Officer approving by direct POST is refused', async () => {
        const st = await postJson(officer, `/card-requests/${requestId}/approve`, { notes: 'QA self-approval attempt' });
        if (st !== 403) throw new Error('expected 403, got ' + st);
        return 'HTTP 403';
    });
    await officer.close();

    const approver = await browser.newPage({ base: BASE });
    await step(J, 'ID Card Approver signs in and opens the request', async () => {
        await login(approver, acct('ID Card Approver').email);
        const g = await approver.goto(`${BASE}/card-requests/${requestId}`);
        if (g.status !== 200) throw new Error('HTTP ' + g.status);
        const p = await props(approver);
        if (!p.can || !p.can.approve) throw new Error('approver not offered approve: ' + JSON.stringify(p.can) + ' status=' + (p.cardRequest && p.cardRequest.status));
        return `status=${p.cardRequest.status} can=${JSON.stringify(p.can)}`;
    });
    await step(J, 'Approver approves through the confirm dialog', async () => {
        await markAndClick(approver, `[...document.querySelectorAll('button')].find(b => /^\\s*approve/i.test(b.innerText))`, 'data-qa-approve');
        await sleep(600);
        const a11y = await approver.eval(`(() => { const o = [...document.querySelectorAll('div.fixed.inset-0')].pop(); const d = document.querySelector('[role=dialog],[role=alertdialog]'); return { overlay: !!o, roleDialog: !!d, ariaModal: !!document.querySelector('[aria-modal=true]'), focusInside: !!(o && o.contains(document.activeElement)) }; })()`);
        results.push({ journey: J, label: 'Confirm dialog accessibility (observation)', status: 'INFO', info: JSON.stringify(a11y) });
        await markAndClick(approver, `[...[...document.querySelectorAll('div.fixed.inset-0')].pop().querySelectorAll('button')].pop()`, 'data-qa-confirm');
        const done = await approver.waitFor(`(history.state && history.state.page && history.state.page.props.cardRequest && history.state.page.props.cardRequest.status) === 'approved'`, 45000);
        await approver.quiet(5000);
        await approver.screenshot(path.join(SHOTS, 'journey-card-approved.png'));
        const p = await props(approver);
        if (!done) throw new Error('status still ' + (p.cardRequest && p.cardRequest.status) + '; flash ' + JSON.stringify(p.flash));
        return `approved_by=${p.cardRequest.approved_by && p.cardRequest.approved_by.name}; flash ${JSON.stringify(p.flash)}`;
    });
    await approver.close();

    // ---------------- B. Employee self-service ----------------
    const E = 'Employee portal';
    const emp = await browser.newPage({ base: BASE });
    await step(E, 'Employee signs in and lands on My Portal', async () => {
        const p = await login(emp, acct('Employee').email);
        if (!/my-portal|dashboard/.test(p)) throw new Error('landed ' + p);
        const pr = await props(emp);
        return `landed ${p} (${await emp.eval('history.state.page.component')}), employee linked=${!!(pr.auth && pr.auth.user && (pr.auth.user.employee_id || pr.auth.user.employee))}`;
    });
    for (const p of ['/my-portal', '/my-portal/employment', '/my-portal/daily-activity', '/my-portal/grievances', '/support/tickets', '/profile']) {
        await step(E, `Employee opens ${p}`, async () => {
            const g = await emp.goto(BASE + p);
            const d = emp.drain();
            if (g.status !== 200) throw new Error('HTTP ' + g.status + ' -> ' + g.href.replace(BASE, ''));
            if (d.exceptions.length) throw new Error('JS: ' + d.exceptions[0].text);
            return `${g.href.replace(BASE, '')} ${await emp.eval('history.state.page.component')} ${g.ms} ms`;
        });
    }
    await step(E, 'Employee is refused admin pages', async () => {
        const out = [];
        for (const p of ['/employees', '/users', '/roles', '/system-settings', '/audit-logs', '/card-requests']) {
            const g = await emp.goto(BASE + p);
            out.push(`${p}=${g.status}${g.href.replace(BASE, '') !== p ? '->' + g.href.replace(BASE, '') : ''}`);
        }
        const leaked = out.filter((o) => /=200$/.test(o));
        if (leaked.length) throw new Error('reachable: ' + leaked.join(', '));
        return out.join(' ');
    });
    await emp.close();

    fs.writeFileSync(path.join(__dirname, 'results', 'journeys.json'), JSON.stringify({ requestId, employee: employee && employee.employee_number, results }, null, 2));
    const fails = results.filter((r) => r.status === 'FAIL').length;
    console.log(`journeys: ${results.length - fails} passed, ${fails} failed`);
    await browser.close();
})().catch((e) => { console.error('FATAL', e); process.exit(1); });
