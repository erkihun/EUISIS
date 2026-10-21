'use strict';
// Journey 5: axe-core on 8 pages (English) + keyboard-only checks.
const fs = require('fs');
const path = require('path');
const { BASE, Recorder, newBrowser, login, shot } = require('./lib');

const ACCOUNT = { email: 'qa.sysadmin6@demo.local', password: 'password' };
const COMPLAINT = '/service-feedback/0789527662ee83db6ce2b116ab560a1d7b2170ff9a17910ef602055acc2dd671/complaint';
const AXE = path.join(__dirname, 'node_modules', 'axe-core', 'axe.min.js');
const PAGES = [
    { url: '/login', auth: false }, { url: '/', auth: false }, { url: COMPLAINT, auth: false, name: '/service-feedback/{token}/complaint' },
    { url: '/dashboard', auth: true }, { url: '/employees', auth: true }, { url: '/employees/create', auth: true }, { url: '/users', auth: true }, { url: '/system-settings', auth: true },
];

(async () => {
    const R = new Recorder('70-quality-a11y');
    const { browser, context, page } = await newBrowser();
    R.attach(page);
    const report = { axe: {}, keyboard: {} };
    let loggedIn = false;

    for (const p of PAGES) {
        const name = p.name || p.url;
        await R.step(`axe ${name}`, async () => {
            if (p.auth && !loggedIn) { await login(page, ACCOUNT.email, ACCOUNT.password); loggedIn = true; }
            await page.goto(`${BASE}${p.url}`, { waitUntil: 'networkidle' });
            await page.waitForTimeout(700);
            await page.addScriptTag({ path: AXE });
            const res = await page.evaluate(async () => {
                const r = await window.axe.run(document, { runOnly: { type: 'tag', values: ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'best-practice'] }, resultTypes: ['violations'] });
                return { url: location.pathname, violations: r.violations.map((v) => ({ id: v.id, impact: v.impact, help: v.help, tags: v.tags.filter((t) => /wcag|best/.test(t)), count: v.nodes.length, nodes: v.nodes.slice(0, 3).map((n) => ({ target: n.target.join(' '), html: n.html.slice(0, 160), summary: n.failureSummary && n.failureSummary.replace(/\s+/g, ' ').slice(0, 220) })) })) };
            });
            report.axe[name] = res;
            const bySev = { critical: 0, serious: 0, moderate: 0, minor: 0 };
            for (const v of res.violations) bySev[v.impact] = (bySev[v.impact] || 0) + v.count;
            const info = `violations: ${res.violations.length} rules — critical=${bySev.critical} serious=${bySev.serious} moderate=${bySev.moderate} minor=${bySev.minor}`;
            if (bySev.critical + bySev.serious > 0) throw new Error(info + ' :: ' + res.violations.filter((v) => /critical|serious/.test(v.impact)).map((v) => `${v.id}(${v.count})`).join(', '));
            return info;
        }, page);
    }

    // ---------- keyboard-only ----------
    await R.step('Keyboard: login form Tab order and visible focus', async () => {
        const c2 = await browser.newContext();
        const p = await c2.newPage();
        await c2.addCookies([{ name: 'euisis_locale', value: 'en', url: BASE }]);
        await p.goto(`${BASE}/login`, { waitUntil: 'networkidle' });
        const seq = [];
        for (let i = 0; i < 10; i++) {
            await p.keyboard.press('Tab');
            const f = await p.evaluate(() => {
                const el = document.activeElement; if (!el || el === document.body) return { tag: 'body' };
                const cs = getComputedStyle(el);
                const ring = cs.outlineStyle !== 'none' && parseFloat(cs.outlineWidth) > 0 || /0 0 0 [1-9]/.test(cs.boxShadow) || cs.boxShadow.includes('rgb') && cs.boxShadow !== 'none';
                return { tag: el.tagName.toLowerCase(), name: el.getAttribute('name') || el.getAttribute('aria-label') || el.textContent.trim().slice(0, 30), type: el.getAttribute('type'), outline: cs.outlineStyle + ' ' + cs.outlineWidth + ' ' + cs.outlineColor, boxShadow: cs.boxShadow.slice(0, 60), focusVisible: el.matches(':focus-visible'), ring };
            });
            seq.push(f);
            if (f.tag === 'button' && f.type === 'submit') break;
        }
        await p.screenshot({ path: path.join(__dirname, 'shots', 'q-kbd-login-focus.png') });
        report.keyboard.login = seq;
        await c2.close();
        const noRing = seq.filter((s) => s.tag !== 'body' && !s.ring);
        const order = seq.map((s) => `${s.tag}:${s.name}`).join(' > ');
        if (!seq.some((s) => s.name === 'email') || !seq.some((s) => s.name === 'password') || !seq.some((s) => s.type === 'submit')) throw new Error('Tab never reached email/password/submit: ' + order);
        if (noRing.length) return `order=${order}; WARN no visible focus ring on: ${noRing.map((s) => s.tag + ':' + s.name).join(', ')}`;
        return order;
    }, page);

    await R.step('Keyboard: skip link exists and becomes visible on focus (admin shell + public)', async () => {
        await page.goto(`${BASE}/employees`, { waitUntil: 'networkidle' });
        await page.keyboard.press('Tab');
        const admin = await page.evaluate(() => {
            const el = document.activeElement; const r = el.getBoundingClientRect(); const cs = getComputedStyle(el);
            return { tag: el.tagName, href: el.getAttribute('href'), text: el.textContent.trim(), w: Math.round(r.width), h: Math.round(r.height), clip: cs.clip, position: cs.position, visible: r.width > 1 && r.height > 1 && cs.clip !== 'rect(0px, 0px, 0px, 0px)' };
        });
        await shot(page, 'q-kbd-skiplink-admin');
        await page.goto(`${BASE}/`, { waitUntil: 'networkidle' });
        await page.keyboard.press('Tab');
        const pub = await page.evaluate(() => {
            const el = document.activeElement; const r = el.getBoundingClientRect(); const cs = getComputedStyle(el);
            return { tag: el.tagName, href: el.getAttribute('href'), text: el.textContent.trim(), w: Math.round(r.width), h: Math.round(r.height), clip: cs.clip, visible: r.width > 1 && r.height > 1 && cs.clip !== 'rect(0px, 0px, 0px, 0px)' };
        });
        await shot(page, 'q-kbd-skiplink-public');
        report.keyboard.skipLink = { admin, pub };
        const problems = [];
        if (admin.href !== '#main-content') problems.push('admin: first Tab is not the skip link: ' + JSON.stringify(admin));
        else if (!admin.visible) problems.push('admin: skip link stays visually hidden when focused (sr-only without focus:not-sr-only): ' + JSON.stringify(admin));
        if (pub.href !== '#main-content') problems.push('public: first Tab is not the skip link: ' + JSON.stringify(pub));
        else if (!pub.visible) problems.push('public: skip link stays hidden on focus');
        if (problems.length) throw new Error(problems.join(' | '));
        return `admin="${admin.text}" visible=${admin.visible}; public="${pub.text}" visible=${pub.visible}`;
    }, page);

    await R.step('Keyboard: Tab through employees list header actions, focus visible', async () => {
        await page.goto(`${BASE}/employees`, { waitUntil: 'networkidle' });
        const seq = [];
        for (let i = 0; i < 30; i++) {
            await page.keyboard.press('Tab');
            const f = await page.evaluate(() => {
                const el = document.activeElement; if (!el || el === document.body) return { tag: 'body' };
                const cs = getComputedStyle(el);
                const ring = (cs.outlineStyle !== 'none' && parseFloat(cs.outlineWidth) > 0) || (cs.boxShadow !== 'none');
                const inHeader = !!el.closest('header');
                const inMain = !!el.closest('main');
                return { tag: el.tagName.toLowerCase(), name: (el.getAttribute('aria-label') || el.getAttribute('name') || el.textContent.trim()).slice(0, 30), ring, focusVisible: el.matches(':focus-visible'), inHeader, inMain, href: el.getAttribute('href') };
            });
            seq.push(f);
        }
        await shot(page, 'q-kbd-employees-focus');
        report.keyboard.employees = seq;
        const mainHits = seq.filter((s) => s.inMain);
        const noRing = seq.filter((s) => s.tag !== 'body' && s.focusVisible && !s.ring);
        return `30 tabs: header=${seq.filter((s) => s.inHeader).length} main=${mainHits.length} first main control="${mainHits[0] && mainHits[0].name}"; no-ring=${noRing.length} [${noRing.slice(0, 4).map((s) => s.tag + ':' + s.name).join(', ')}]`;
    }, page);

    await R.step('Keyboard: account menu opens with Enter and closes with Escape; command palette (Ctrl+K) closes with Escape', async () => {
        await page.goto(`${BASE}/employees`, { waitUntil: 'networkidle' });
        const menuBtn = page.locator('header button[aria-label^="Account"], header button[aria-haspopup="menu"]').first();
        await menuBtn.focus();
        await page.keyboard.press('Enter');
        await page.waitForTimeout(400);
        const menuOpen = await page.locator('[role="menu"]').count();
        await page.keyboard.press('Escape');
        await page.waitForTimeout(400);
        const menuAfterEsc = await page.locator('[role="menu"]').count();
        await page.keyboard.press('Control+k');
        await page.waitForTimeout(500);
        const palette = await page.locator('[role="dialog"]').count();
        await shot(page, 'q-kbd-palette');
        await page.keyboard.press('Escape');
        await page.waitForTimeout(400);
        const paletteAfter = await page.locator('[role="dialog"]').count();
        report.keyboard.menus = { menuOpen, menuAfterEsc, palette, paletteAfter };
        if (!menuOpen) throw new Error('account menu did not open with Enter');
        if (menuAfterEsc) throw new Error('account menu did not close with Escape');
        if (!palette) throw new Error('Ctrl+K palette did not open');
        if (paletteAfter) throw new Error('palette did not close with Escape');
    }, page);

    await R.step('Keyboard: mobile drawer opens with Enter and closes with Escape (375px)', async () => {
        const c2 = await browser.newContext({ viewport: { width: 375, height: 812 } });
        await c2.addCookies([{ name: 'euisis_locale', value: 'en', url: BASE }]);
        const p = await c2.newPage();
        await login(p, ACCOUNT.email, ACCOUNT.password);
        await p.goto(`${BASE}/dashboard`, { waitUntil: 'networkidle' });
        const btn = p.locator('header button').first();
        await btn.focus();
        await p.keyboard.press('Enter');
        await p.waitForTimeout(500);
        const open = await p.locator('[role="dialog"]').count();
        const focusInside = await p.evaluate(() => !!document.activeElement.closest('[role="dialog"]'));
        await p.keyboard.press('Escape');
        await p.waitForTimeout(500);
        const after = await p.locator('[role="dialog"]').count();
        const focusBack = await p.evaluate(() => document.activeElement.tagName + ':' + (document.activeElement.getAttribute('aria-label') || ''));
        await c2.close();
        report.keyboard.drawer = { open, focusInside, after, focusBack };
        if (!open) throw new Error('drawer did not open via keyboard');
        if (after) throw new Error('Escape did not close drawer');
        return `open=${open} focusTrapped=${focusInside} closedByEsc focusReturnedTo=${focusBack}`;
    }, page);

    fs.writeFileSync(path.join(__dirname, 'results', '70-quality-a11y-report.json'), JSON.stringify(report, null, 2));
    await browser.close();
    R.save();
})().catch((e) => { console.error('FATAL', e); process.exit(1); });
