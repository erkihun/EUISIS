'use strict';
const { BASE, totp, Recorder, newBrowser, newPageIn, login, logout, submitAndLeave, expectUrl, visit, shot } = require('./lib');

(async () => {
    const R = new Recorder('01-auth');
    const { browser, context, page } = await newBrowser();
    R.attach(page);
    const state = {};

    await R.step('Login page renders with email/password fields', async () => {
        const r = await visit(page, '/login');
        await page.waitForSelector('input[name="email"]');
        await page.waitForSelector('input[name="password"]');
        await shot(page, 'auth-login');
        return `title="${r.title}"`;
    }, page);

    await R.step('Wrong password is rejected with a field error, no redirect', async () => {
        await page.fill('input[name="email"]', 'super.admin@demo.local');
        await page.fill('input[name="password"]', 'wrong-password-123');
        await submitAndLeave(page, /\/login$/, 5000);
        await expectUrl(page, /\/login$/);
        const text = await page.locator('body').innerText();
        if (!/credentials|do not match|incorrect|invalid/i.test(text)) throw new Error('no auth error shown: ' + text.slice(0, 200));
    }, page);

    await R.step('Super Admin login forces MFA enrolment, TOTP accepted, lands on dashboard', async () => {
        const url = await login(page, 'super.admin@demo.local', 'password', state);
        if (!state.secret) throw new Error('MFA enrolment was not required for Super Admin (expected by MFA_REQUIRED_ROLES)');
        await expectUrl(page, /\/dashboard/);
        await shot(page, 'auth-dashboard-superadmin');
        return `secret captured, url=${url}`;
    }, page);

    await R.step('Dashboard shows signed-in shell (no debug output, has nav)', async () => {
        const body = await page.locator('body').innerText();
        if (body.length < 200) throw new Error('dashboard body suspiciously short');
        const navCount = await page.locator('nav a, aside a').count();
        if (navCount < 5) throw new Error('navigation has fewer than 5 links: ' + navCount);
        return `${navCount} nav links`;
    }, page);

    await R.step('Logout returns to login and dashboard is protected again', async () => {
        await logout(page);
        await page.goto(`${BASE}/dashboard`, { waitUntil: 'networkidle' });
        await expectUrl(page, /\/login/);
    }, page);

    await R.step('Second login goes through MFA challenge (not setup) with stored secret', async () => {
        await page.goto(`${BASE}/login`, { waitUntil: 'networkidle' });
        await page.fill('input[name="email"]', 'super.admin@demo.local');
        await page.fill('input[name="password"]', 'password');
        await submitAndLeave(page, /\/login$/);
        await expectUrl(page, /\/mfa\/challenge/);
        await shot(page, 'auth-mfa-challenge');
    }, page);

    await R.step('MFA challenge rejects a wrong code', async () => {
        await page.fill('input[name="code"]', '000000');
        await submitAndLeave(page, /\/mfa\/challenge/, 5000);
        await expectUrl(page, /\/mfa\/challenge/);
        const t = await page.locator('form').innerText();
        if (!/invalid|incorrect|wrong|not valid/i.test(t)) throw new Error('no error text after wrong code: ' + t.replace(/\n+/g,' | ').slice(0,200));
    }, page);

    await R.step('Protected page is blocked until the MFA challenge passes', async () => {
        await page.goto(`${BASE}/employees`, { waitUntil: 'networkidle' });
        await expectUrl(page, /\/mfa\/challenge/);
    }, page);

    async function waitNewWindow() { const w = 30000 - (Date.now() % 30000); if (w < 29000) await page.waitForTimeout(w + 200); }

    await R.step('MFA challenge accepts the correct TOTP', async () => {
        await waitNewWindow();
        await page.fill('input[name="code"]', totp(state.secret));
        await submitAndLeave(page, /\/mfa\/challenge/);
        await expectUrl(page, /\/dashboard|\/employees/);
    }, page);

    await R.step('Reusing the same TOTP code is refused (replay protection) on a fresh session', async () => {
        await waitNewWindow();
        const code = totp(state.secret);
        const c2 = await browser.newContext();
        const p2 = await newPageIn(c2);
        await p2.goto(`${BASE}/login`, { waitUntil: 'networkidle' });
        await p2.fill('input[name="email"]', 'super.admin@demo.local');
        await p2.fill('input[name="password"]', 'password');
        await submitAndLeave(p2, /\/login$/);
        // First use in this session
        await p2.fill('input[name="code"]', code);
        await submitAndLeave(p2, /\/mfa\/challenge/);
        const firstOk = /dashboard/.test(p2.url());
        // Replay in a third session
        const c3 = await browser.newContext();
        const p3 = await newPageIn(c3);
        await p3.goto(`${BASE}/login`, { waitUntil: 'networkidle' });
        await p3.fill('input[name="email"]', 'super.admin@demo.local');
        await p3.fill('input[name="password"]', 'password');
        await submitAndLeave(p3, /\/login$/);
        await p3.fill('input[name="code"]', code);
        await submitAndLeave(p3, /\/mfa\/challenge/, 8000);
        const replayBlocked = /mfa\/challenge/.test(p3.url());
        await c2.close(); await c3.close();
        if (!firstOk) throw new Error('fresh TOTP code was rejected in a new session');
        if (!replayBlocked) throw new Error('replayed TOTP code was accepted');
        return `first=${firstOk ? 'accepted' : 'rejected'}, replay blocked`;
    });

    await R.step('HR Officer (non-MFA role) logs in without MFA and reaches dashboard', async () => {
        const c = await browser.newContext();
        const p = await newPageIn(c);
        const st = {};
        const url = await login(p, 'hr.officer@demo.local', 'password', st);
        if (st.secret) throw new Error('HR Officer was asked to enrol MFA');
        if (!/dashboard/.test(url)) throw new Error('expected dashboard, got ' + url);
        await shot(p, 'auth-dashboard-hr');
        await c.close();
    });

    await R.step('Login throttling: 10 bad attempts produce a lockout/too-many message', async () => {
        const c = await browser.newContext();
        const p = await newPageIn(c);
        let lockoutSeen = false;
        for (let i = 0; i < 10; i++) {
            await p.goto(`${BASE}/login`, { waitUntil: 'networkidle' });
            await p.fill('input[name="email"]', 'city.admin@demo.local');
            await p.fill('input[name="password"]', 'bad-' + i);
            await submitAndLeave(p, /\/login$/, 4000);
            const t = await p.locator('body').innerText();
            if (/too many|locked|try again in|seconds|throttl/i.test(t)) { lockoutSeen = true; break; }
        }
        await c.close();
        if (!lockoutSeen) throw new Error('no lockout message after 10 failed attempts');
    });

    await R.step('Forgot-password page renders and accepts an email without leaking account existence', async () => {
        const c = await browser.newContext();
        const p = await newPageIn(c);
        await p.goto(`${BASE}/forgot-password`, { waitUntil: 'networkidle' });
        await p.fill('input[name="email"]', 'nobody-does-not-exist@demo.local');
        await submitAndLeave(p, /\/forgot-password$/, 5000);
        const t = await p.locator('body').innerText();
        await c.close();
        if (/can't find a user|no user|not registered|አልተገኘም/i.test(t)) throw new Error('account enumeration: ' + t.slice(0, 200));
    });

    await R.step('Registration is disabled: /register redirects to login', async () => {
        const c = await browser.newContext();
        const p = await newPageIn(c);
        await p.goto(`${BASE}/register`, { waitUntil: 'networkidle' });
        const u = p.url();
        await c.close();
        if (!/\/login/.test(u)) throw new Error('register page reachable: ' + u);
    });

    require('fs').writeFileSync(require('path').join(__dirname, 'results', 'superadmin-secret.txt'), state.secret || '');
    await browser.close();
    R.save();
})().catch((e) => { console.error('FATAL', e); process.exit(1); });
