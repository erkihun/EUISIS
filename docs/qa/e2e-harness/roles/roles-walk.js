'use strict';
// Role-by-role end-to-end walkthrough: sign in as each role, open every page the
// sidebar offers, probe pages it does not offer, sign out.
// Usage: node roles-walk.js [workers] [roleFilterRegex]
const fs = require('fs');
const path = require('path');
const { launchChrome, totp, sleep } = require('./cdp');

const BASE = process.env.BASE_URL || 'http://127.0.0.1:8123';
const REPO = 'C:/Users/Erkis/Documents/GitHub/EUISIS';
const SCRATCH = path.resolve(__dirname, '..');
const OUT = path.join(__dirname, 'results', 'roles');
const SHOTS = path.join(__dirname, 'shots');
fs.mkdirSync(OUT, { recursive: true }); fs.mkdirSync(SHOTS, { recursive: true });

const WORKERS = Number(process.argv[2] || 4);
const FILTER = process.argv[3] ? new RegExp(process.argv[3], 'i') : null;
const { password, accounts } = JSON.parse(fs.readFileSync(path.join(SCRATCH, 'qa-users.json'), 'utf8'));
const secretsFile = path.join(__dirname, 'results', 'mfa-secrets.json');
const secrets = fs.existsSync(secretsFile) ? JSON.parse(fs.readFileSync(secretsFile, 'utf8')) : {};
const saveSecrets = () => fs.writeFileSync(secretsFile, JSON.stringify(secrets, null, 2));

// ---- Sidebar model from the source: routeName + permission gates ----
const routes = JSON.parse(fs.readFileSync(path.join(SCRATCH, 'routes.json'), 'utf8'));
const uriByName = new Map(routes.filter((r) => r.name).map((r) => [r.name, r.uri]));
const sidebarSrc = fs.readFileSync(path.join(REPO, 'resources/js/Components/AppSidebar.tsx'), 'utf8');
const navModel = [];
for (const line of sidebarSrc.split('\n')) {
    const m = line.match(/routeName:\s*'([^']+)'/);
    if (!m) continue;
    const routeName = m[1];
    const tab = (line.match(/tab:\s*'([^']+)'/) || [])[1];
    const permission = (line.match(/\bpermission:\s*'([^']+)'/) || [])[1];
    const anyRaw = (line.match(/anyPermission:\s*\[([^\]]*)\]/) || [])[1];
    const anyPermission = anyRaw ? [...anyRaw.matchAll(/'([^']+)'/g)].map((x) => x[1]) : null;
    const uri = uriByName.get(routeName);
    if (!uri || /\{/.test(uri)) continue;
    if (routeName.startsWith('court-cases.')) continue; // feature off
    const p = '/' + uri.replace(/^\//, '') + (tab ? `?tab=${tab}` : '');
    if (!permission && !anyPermission) continue; // ungated items are not authorization probes
    if (navModel.some((n) => n.path === p)) continue;
    navModel.push({ routeName, path: p, permission, anyPermission, employeeOnly: /employeeOnly:\s*true/.test(line) });
}

const slug = (s) => s.toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, '');

async function readPageState(page) {
    return page.eval(`(() => {
        let p = (history.state && history.state.page) || null;
        if (!p) { const el = document.querySelector('script[data-page]') || document.getElementById('app');
            try { p = JSON.parse(el && (el.dataset.page || el.textContent)); } catch (e) {} }
        const text = (document.body && document.body.innerText) || '';
        return {
            component: p && p.component, title: document.title,
            h1: (document.querySelector('main h1, h1') || {}).innerText || null,
            errorText: /Server Error|Whoops|SQLSTATE|Stack trace|Something went wrong|This action is unauthorized|Forbidden/i.test(text) ? text.slice(0, 200) : null,
            bodyLen: text.length,
            overflowX: document.documentElement.scrollWidth > document.documentElement.clientWidth + 2,
        };
    })()`);
}

async function login(page, acct, log) {
    await page.goto(`${BASE}/login`);
    await page.waitFor(`!!document.querySelector('input[name="email"]')`, 20000);
    await page.fill('input[name="email"]', acct.email);
    await page.fill('input[name="password"]', password);
    await page.click('button[type="submit"]');
    const left = await page.waitFor(`!/\\/login(\\?|$)/.test(location.pathname) ? location.href : false`, 45000);
    if (!left) {
        const err = await page.eval(`document.body.innerText.slice(0,300)`);
        throw new Error('login did not leave /login: ' + err.replace(/\s+/g, ' '));
    }
    await page.quiet(10000);
    for (let i = 0; i < 4; i++) {
        const href = await page.eval('location.href');
        if (/\/mfa\/setup/.test(href)) {
            const secret = await page.waitFor(`(history.state && history.state.page && history.state.page.props.secretKey) || ([...document.querySelectorAll('code,kbd,span,p,div')].map(e => e.innerText && e.innerText.trim()).find(t => /^[A-Z2-7]{16,64}$/.test(t)))`, 15000);
            if (!secret) throw new Error('MFA setup shown but no secret found');
            secrets[acct.email] = secret; saveSecrets();
            log.push('mfa: enrolment required, secret captured');
            await page.fill('input[name="code"]', totp(secret));
            await page.click('button[type="submit"]');
            await page.waitFor(`!/\\/mfa\\/setup/.test(location.pathname)`, 45000);
            await page.quiet(10000);
            continue;
        }
        if (/\/mfa\/challenge/.test(href)) {
            const secret = secrets[acct.email];
            if (!secret) throw new Error('MFA challenge shown but no secret stored');
            // avoid reusing a code consumed in the same window (replay protection)
            const w = 30000 - (Date.now() % 30000); if (w < 29000) await sleep(w + 300);
            await page.fill('input[name="code"]', totp(secret));
            await page.click('button[type="submit"]');
            await page.waitFor(`!/\\/mfa\\/challenge/.test(location.pathname)`, 45000);
            await page.quiet(10000);
            log.push('mfa: challenge passed');
            continue;
        }
        if (/\/password\/forced|change-password/.test(href)) throw new Error('forced password change on login: ' + href);
        break;
    }
    return page.eval('location.href');
}

async function expandSidebar(page) {
    for (let pass = 0; pass < 4; pass++) {
        const n = await page.eval(`(() => { let n = 0; for (const b of document.querySelectorAll('aside button[aria-expanded="false"], nav button[aria-expanded="false"]')) { b.click(); n++; } return n; })()`);
        if (!n) break;
        await sleep(250);
    }
}

async function sidebarLinks(page) {
    return page.eval(`(() => { const out = []; const seen = new Set();
        for (const a of document.querySelectorAll('aside a[href], nav a[href]')) {
            const u = new URL(a.href, location.href);
            if (u.origin !== location.origin) continue;
            const key = u.pathname + u.search;
            if (seen.has(key) || /logout/.test(key)) continue; seen.add(key);
            out.push({ path: key, label: (a.innerText || a.getAttribute('aria-label') || '').trim().slice(0, 60) });
        } return out; })()`);
}

async function probe(cookieHeader, p) {
    const t0 = Date.now();
    try {
        const r = await fetch(BASE + p, { redirect: 'manual', headers: { cookie: cookieHeader, accept: 'text/html' } });
        const loc = r.headers.get('location');
        await r.arrayBuffer();
        return { status: r.status, location: loc ? loc.replace(BASE, '') : null, ms: Date.now() - t0 };
    } catch (e) { return { status: 'error', error: String(e.message || e), ms: Date.now() - t0 }; }
}

async function logout(page) {
    const opened = await page.eval(`(() => { const b = document.querySelector('header button[aria-label^="Account menu" i], button[aria-label*="ccount menu" i]'); if (!b) return false; b.setAttribute('data-qa-acct', '1'); return true; })()`);
    if (opened) {
        await page.click('[data-qa-acct]');
        await sleep(500);
        const found = await page.eval(`(() => { const items = [...document.querySelectorAll('[role="menuitem"], [role="menu"] button, [role="menu"] a')]; const it = items.find(e => /log ?out|sign out/i.test(e.innerText)); if (!it) return false; it.setAttribute('data-qa-logout', '1'); return true; })()`);
        if (found) await page.click('[data-qa-logout]');
        if (found) {
            const ok = await page.waitFor(`/\\/login|^\\/$/.test(location.pathname) ? location.href : false`, 30000);
            if (ok) return 'ui';
        }
    }
    // fallback: POST /logout with the CSRF token
    await page.eval(`fetch('/logout', { method: 'POST', headers: { 'X-XSRF-TOKEN': decodeURIComponent((document.cookie.match(/XSRF-TOKEN=([^;]+)/) || [])[1] || ''), 'X-Requested-With': 'XMLHttpRequest', accept: 'text/html' } }).then(r => r.status)`);
    return 'post-fallback';
}

async function walkRole(browser, acct) {
    const r = { role: acct.role, email: acct.email, scope: acct.scope, permissionCount: acct.permissions.length, log: [], steps: [], visits: [], probes: [], issues: [] };
    const page = await browser.newPage({ base: BASE });
    const issue = (kind, detail) => r.issues.push({ kind, ...detail });
    try {
        // 1. sign in
        const t0 = Date.now();
        const landing = await login(page, acct, r.log);
        r.landing = landing.replace(BASE, ''); r.loginMs = Date.now() - t0;
        const st = await readPageState(page);
        r.landingComponent = st.component;
        r.auth = await page.eval(`(() => { const a = (history.state && history.state.page && history.state.page.props.auth) || {}; return { roles: a.roles, isSuperAdmin: a.isSuperAdmin, perms: (a.permissions || []).length, hasEmployee: !!(a.user && (a.user.employee_id || a.user.employee)), userType: a.user && a.user.user_type }; })()`);
        await page.screenshot(path.join(SHOTS, `${slug(acct.role)}-landing.png`));
        if (st.errorText) issue('landing-error', { path: r.landing, text: st.errorText });
        r.footerCredit = await page.eval(`[...document.querySelectorAll('footer')].some(f => /Designed and developed by Erkihun Bewket/.test(f.innerText))`);
        if (!r.footerCredit) issue('footer-credit-missing', { path: r.landing });
        r.steps.push({ step: 'sign in', ok: true, info: `${r.landing} (${st.component}) in ${r.loginMs} ms` });

        // 2. sidebar as rendered
        await expandSidebar(page);
        const links = await sidebarLinks(page);
        r.sidebar = links;
        r.steps.push({ step: 'sidebar', ok: links.length > 0, info: `${links.length} links` });

        // 3. open every sidebar page in the browser (MAX_VISITS caps it for quick re-checks)
        const maxVisits = Number(process.env.MAX_VISITS || 1e9);
        for (const l of links.slice(0, maxVisits)) {
            const v = { path: l.path, label: l.label };
            try {
                const g = await page.goto(BASE + l.path);
                const s = await readPageState(page);
                Object.assign(v, { status: g.status, ms: g.ms, final: g.href.replace(BASE, ''), component: s.component, h1: s.h1, overflowX: s.overflowX });
                const d = page.drain();
                v.console = d.console.filter((c) => !/Download the React DevTools|\[vite\]|preload/i.test(c.text));
                v.exceptions = d.exceptions; v.bad = d.bad;
                if (g.status >= 500) issue('server-error', { path: l.path, status: g.status });
                else if (g.status === 403) issue('sidebar-link-forbidden', { path: l.path, label: l.label });
                else if (g.status >= 400) issue('sidebar-link-http', { path: l.path, status: g.status });
                if (s.errorText && g.status < 400) issue('error-text', { path: l.path, text: s.errorText });
                if (v.exceptions.length) issue('js-exception', { path: l.path, text: v.exceptions[0].text });
                if (v.bad.length) issue('failed-request', { path: l.path, requests: v.bad.slice(0, 3) });
                if (v.console.some((c) => c.type === 'error' || c.type === 'log-error')) issue('console-error', { path: l.path, text: v.console.find((c) => c.type !== 'warning').text });
                if (g.ms > 8000) issue('slow-page', { path: l.path, ms: g.ms });
                if (!/\/login/.test(l.path) && /\/login/.test(v.final)) issue('bounced-to-login', { path: l.path });
                if (g.status >= 400 || v.exceptions.length || (s.errorText && g.status < 400)) {
                    v.shot = await page.screenshot(path.join(SHOTS, `${slug(acct.role)}-${slug(l.path)}.png`));
                }
            } catch (e) {
                v.error = String(e.message || e).slice(0, 200);
                issue('visit-failed', { path: l.path, error: v.error });
            }
            r.visits.push(v);
        }

        // 4. pages the sidebar does NOT offer this role -> must be refused
        const cookies = await page.cookies(BASE);
        const cookieHeader = cookies.map((c) => `${c.name}=${c.value}`).join('; ');
        const held = new Set(acct.permissions);
        const superish = r.auth && r.auth.isSuperAdmin;
        const offered = new Set(links.map((l) => l.path));
        const targets = process.env.NO_PROBES ? [] : navModel.filter((n) => !superish && !offered.has(n.path)
            && !(n.permission && held.has(n.permission)) && !(n.anyPermission && n.anyPermission.some((p) => held.has(p))));
        for (let i = 0; i < targets.length; i += 6) {
            const batch = targets.slice(i, i + 6);
            const res = await Promise.all(batch.map((n) => probe(cookieHeader, n.path)));
            batch.forEach((n, k) => {
                const pr = { path: n.path, need: n.permission || n.anyPermission, ...res[k] };
                r.probes.push(pr);
                if (pr.status === 200) issue('reachable-without-permission', { path: n.path, need: pr.need });
                if (typeof pr.status === 'number' && pr.status >= 500) issue('probe-server-error', { path: n.path, status: pr.status });
            });
        }
        // and permitted-by-model items the sidebar did not render
        r.hiddenButPermitted = navModel.filter((n) => !offered.has(n.path) && (superish || (n.permission && held.has(n.permission)) || (n.anyPermission && n.anyPermission.some((p) => held.has(p))))).map((n) => n.path);

        // 5. profile page and sign out
        const pv = await page.goto(BASE + '/profile');
        r.steps.push({ step: 'profile', ok: pv.status === 200, info: `HTTP ${pv.status}` });
        if (pv.status !== 200) issue('profile-http', { status: pv.status });
        page.drain();
        if (links.length) await page.goto(BASE + (r.landing || '/dashboard'));
        const how = await logout(page);
        const after = await page.goto(BASE + '/dashboard');
        const loggedOut = /\/login/.test(after.href);
        r.steps.push({ step: 'sign out', ok: loggedOut, info: `${how}; /dashboard -> ${after.href.replace(BASE, '')}` });
        if (!loggedOut) issue('logout-failed', { how, after: after.href });
        if (how !== 'ui') issue('logout-menu-not-found', {});
    } catch (e) {
        r.fatal = String(e.message || e).slice(0, 300);
        try { r.fatalShot = await page.screenshot(path.join(SHOTS, `${slug(acct.role)}-fatal.png`)); r.fatalUrl = await page.eval('location.href'); } catch {}
        issue('fatal', { error: r.fatal, url: r.fatalUrl });
    }
    await page.close();
    fs.writeFileSync(path.join(OUT, `${slug(acct.role)}.json`), JSON.stringify(r, null, 2));
    return r;
}

(async () => {
    const list = accounts.filter((a) => !FILTER || FILTER.test(a.role));
    console.log(`nav model: ${navModel.length} gated sidebar pages; roles: ${list.length}; workers: ${WORKERS}`);
    const browser = await launchChrome(9333 + Math.floor(Math.random() * 200), path.join(SCRATCH, 'chrome-profile-' + process.pid));
    const queue = [...list];
    const t0 = Date.now();
    await Promise.all(Array.from({ length: WORKERS }, async () => {
        while (queue.length) {
            const acct = queue.shift();
            const t = Date.now();
            const r = await walkRole(browser, acct);
            const kinds = {};
            for (const i of r.issues) kinds[i.kind] = (kinds[i.kind] || 0) + 1;
            console.log(`${acct.role.padEnd(34)} ${r.fatal ? 'FATAL ' + r.fatal : `landing=${r.landing} links=${r.sidebar.length} probes=${r.probes.length}`} ${JSON.stringify(kinds)} ${Math.round((Date.now() - t) / 1000)}s`);
        }
    }));
    console.log(`done in ${Math.round((Date.now() - t0) / 1000)} s`);
    await browser.close();
})().catch((e) => { console.error('FATAL', e); process.exit(1); });
