'use strict';
// Journey 5 follow-up: keyboard checks with initial-focus awareness; dark-mode axe color-contrast; drawer tap on touch devices.
const fs = require('fs');
const path = require('path');
const { BASE, Recorder, newBrowser, login, shot } = require('./lib');

const ACCOUNT = { email: 'qa.sysadmin6@demo.local', password: 'password' };
const AXE = path.join(__dirname, 'node_modules', 'axe-core', 'axe.min.js');

const FOCUS_INFO = () => {
    const el = document.activeElement; if (!el || el === document.body) return { tag: 'body' };
    const cs = getComputedStyle(el); const r = el.getBoundingClientRect();
    const ring = (cs.outlineStyle !== 'none' && parseFloat(cs.outlineWidth) > 0) || (cs.boxShadow !== 'none');
    return { tag: el.tagName.toLowerCase(), name: (el.getAttribute('aria-label') || el.getAttribute('name') || el.textContent.trim()).slice(0, 36), type: el.getAttribute('type'), ring, focusVisible: el.matches(':focus-visible'), region: el.closest('header') ? 'header' : el.closest('main') ? 'main' : el.closest('aside') ? 'aside' : el.closest('nav') ? 'nav' : 'other', w: Math.round(r.width), h: Math.round(r.height), clip: cs.clip, href: el.getAttribute('href') };
};

(async () => {
    const R = new Recorder('70-quality-a11y2');
    const { browser, context, page } = await newBrowser();
    R.attach(page);
    const report = {};

    await R.step('Keyboard: login form — initial focus, Tab order, visible focus ring', async () => {
        await page.goto(`${BASE}/login`, { waitUntil: 'networkidle' });
        const initial = await page.evaluate(FOCUS_INFO);
        const seq = [initial];
        for (let i = 0; i < 8; i++) { await page.keyboard.press('Tab'); seq.push(await page.evaluate(FOCUS_INFO)); if (seq[seq.length - 1].type === 'submit') break; }
        await shot(page, 'q-kbd-login-focus2');
        report.login = seq;
        const order = seq.map((s) => `${s.tag}:${s.name}${s.ring ? '' : '(NO RING)'}`).join(' > ');
        const noRing = seq.filter((s) => s.tag !== 'body' && s.focusVisible && !s.ring);
        if (noRing.length) throw new Error('no visible focus ring: ' + noRing.map((s) => s.tag + ':' + s.name).join(', ') + ' | ' + order);
        return order;
    }, page);

    await R.step('Skip link (admin shell): exists, is first in tab order, becomes visible on focus', async () => {
        await login(page, ACCOUNT.email, ACCOUNT.password);
        await page.goto(`${BASE}/employees`, { waitUntil: 'networkidle' });
        const initial = await page.evaluate(FOCUS_INFO);
        await page.evaluate(() => { document.activeElement && document.activeElement.blur(); });
        await page.keyboard.press('Tab');
        const first = await page.evaluate(FOCUS_INFO);
        const skip = await page.evaluate(() => {
            const a = document.querySelector('a[href="#main-content"]'); if (!a) return null;
            a.focus(); const r = a.getBoundingClientRect(); const cs = getComputedStyle(a);
            return { text: a.textContent.trim(), cls: a.className, w: Math.round(r.width), h: Math.round(r.height), clip: cs.clip, visibleWhenFocused: r.width > 1 && r.height > 1 && cs.clip === 'auto' };
        });
        await shot(page, 'q-kbd-skiplink-admin2');
        report.skipAdmin = { initialFocus: initial, firstTab: first, skip };
        if (!skip) throw new Error('no a[href="#main-content"] in admin shell');
        if (!skip.visibleWhenFocused) throw new Error(`admin skip link stays visually hidden when focused (class="${skip.cls}", clip=${skip.clip}, ${skip.w}x${skip.h}); initial focus=${initial.tag}:${initial.name}, first Tab → ${first.tag}:${first.name}`);
        return `first Tab → ${first.tag}:${first.name}; skip visible=${skip.visibleWhenFocused}`;
    }, page);

    await R.step('Skip link (public site): first Tab, visible on focus, Enter moves focus to main', async () => {
        await page.goto(`${BASE}/`, { waitUntil: 'networkidle' });
        await page.keyboard.press('Tab');
        const first = await page.evaluate(FOCUS_INFO);
        await shot(page, 'q-kbd-skiplink-public2');
        await page.keyboard.press('Enter');
        await page.waitForTimeout(300);
        const after = await page.evaluate(() => ({ id: document.activeElement.id, tag: document.activeElement.tagName, hash: location.hash }));
        report.skipPublic = { first, after };
        if (first.href !== '#main-content') throw new Error('first Tab is not skip link: ' + JSON.stringify(first));
        if (!(first.w > 1 && first.h > 1 && first.clip === 'auto')) throw new Error('public skip link not visible on focus: ' + JSON.stringify(first));
        return `skip "${first.name}" visible ${first.w}x${first.h}; after Enter focus=${after.tag}#${after.id}`;
    }, page);

    await R.step('Keyboard: Tabs needed to reach the employees list header actions from page top', async () => {
        await page.goto(`${BASE}/employees`, { waitUntil: 'networkidle' });
        await page.evaluate(() => document.activeElement && document.activeElement.blur());
        const seq = []; let toMain = null;
        for (let i = 1; i <= 120; i++) {
            await page.keyboard.press('Tab');
            const f = await page.evaluate(FOCUS_INFO);
            seq.push(f);
            if (f.region === 'main' && toMain === null) toMain = i;
            if (toMain && i >= toMain + 12) break;
        }
        await shot(page, 'q-kbd-employees-focus2');
        const regions = seq.reduce((a, s) => { a[s.region] = (a[s.region] || 0) + 1; return a; }, {});
        const mainSeq = seq.filter((s) => s.region === 'main').slice(0, 12);
        const noRing = seq.filter((s) => s.tag !== 'body' && s.focusVisible && !s.ring);
        report.employeesTabs = { toMain, regions, mainSeq, noRing: noRing.slice(0, 10) };
        const info = `Tabs to reach <main>: ${toMain}; regions=${JSON.stringify(regions)}; main controls: ${mainSeq.map((s) => s.tag + ':' + s.name).join(' > ')}; no-ring=${noRing.length}`;
        if (toMain === null) throw new Error('main content never reached in 120 Tabs; ' + info);
        if (noRing.length) throw new Error('missing focus ring on: ' + noRing.map((s) => s.tag + ':' + s.name).join(', ') + ' | ' + info);
        return info;
    }, page);

    await R.step('Keyboard: row Actions menu opens with Enter, closes with Escape (employees)', async () => {
        const btn = page.locator('main button[aria-label="Actions"], main button[aria-haspopup="menu"]').first();
        if (!(await btn.count())) return 'no Actions button found';
        await btn.focus(); await page.keyboard.press('Enter'); await page.waitForTimeout(400);
        const open = await page.locator('[role="menu"]').count();
        await page.keyboard.press('Escape'); await page.waitForTimeout(400);
        const after = await page.locator('[role="menu"]').count();
        if (!open) throw new Error('row menu did not open with Enter');
        if (after) throw new Error('row menu did not close with Escape');
    }, page);

    for (const url of ['/dashboard', '/employees']) {
        await R.step(`axe color-contrast in dark mode ${url}`, async () => {
            await page.evaluate(() => localStorage.setItem('theme', 'dark'));
            await page.goto(`${BASE}${url}`, { waitUntil: 'networkidle' });
            await page.waitForTimeout(500);
            await page.addScriptTag({ path: AXE });
            const res = await page.evaluate(async () => {
                const r = await window.axe.run(document, { runOnly: { type: 'rule', values: ['color-contrast'] }, resultTypes: ['violations'] });
                return { dark: document.documentElement.classList.contains('dark'), violations: r.violations.map((v) => ({ id: v.id, impact: v.impact, count: v.nodes.length, nodes: v.nodes.slice(0, 8).map((n) => ({ target: n.target.join(' '), html: n.html.slice(0, 120), summary: n.failureSummary && n.failureSummary.replace(/\s+/g, ' ').slice(0, 200) })) })) };
            });
            report['darkAxe' + url] = res;
            const v = res.violations[0];
            if (v) return `WARN ${v.count} contrast failures: ` + v.nodes.slice(0, 4).map((n) => `${n.html.replace(/<[^>]+>/g, '').slice(0, 30)} ${n.summary.match(/contrast of ([\d.]+)/)?.[1]}`).join('; ');
            return `dark=${res.dark} no contrast violations`;
        }, page);
    }
    await page.evaluate(() => localStorage.removeItem('theme'));

    // Drawer on touch device
    for (const mode of [{ isMobile: true, hasTouch: true, tag: 'touch' }, { isMobile: false, hasTouch: false, tag: 'mouse' }]) {
        await R.step(`Mobile drawer at 375x812 (${mode.tag}): tap/click open, Escape close, backdrop close`, async () => {
            const c2 = await browser.newContext({ viewport: { width: 375, height: 812 }, isMobile: mode.isMobile, hasTouch: mode.hasTouch });
            await c2.addCookies([{ name: 'euisis_locale', value: 'en', url: BASE }]);
            await c2.addInitScript(() => { try { localStorage.setItem('euisis_locale', 'en'); } catch {} });
            const p = await c2.newPage();
            await login(p, ACCOUNT.email, ACCOUNT.password);
            await p.goto(`${BASE}/dashboard`, { waitUntil: 'networkidle' });
            const btn = p.locator('header button[aria-label="Open menu"]');
            if (mode.hasTouch) await btn.tap(); else await btn.click();
            const states = [];
            for (const t of [100, 400, 1000]) { await p.waitForTimeout(t); states.push(await p.locator('[role="dialog"]').count()); }
            const links = await p.locator('[role="dialog"] a').count();
            await p.screenshot({ path: path.join(__dirname, 'shots', `q-drawer-${mode.tag}-open.png`) });
            await p.keyboard.press('Escape'); await p.waitForTimeout(500);
            const afterEsc = await p.locator('[role="dialog"]').count();
            if (mode.hasTouch) await btn.tap(); else await btn.click();
            await p.waitForTimeout(600);
            const reopened = await p.locator('[role="dialog"]').count();
            if (mode.hasTouch) await p.touchscreen.tap(360, 400); else await p.mouse.click(360, 400);
            await p.waitForTimeout(600);
            const afterBackdrop = await p.locator('[role="dialog"]').count();
            await c2.close();
            report['drawer-' + mode.tag] = { states, links, afterEsc, reopened, afterBackdrop };
            if (!states[2]) throw new Error(`drawer not open after ${mode.tag} (dialog count over time ${states})`);
            if (afterEsc) throw new Error('Escape did not close');
            if (!reopened) throw new Error('did not reopen');
            if (afterBackdrop) throw new Error('backdrop tap did not close');
            return `open=${states} links=${links} esc=closed backdrop=closed`;
        }, page);
    }

    fs.writeFileSync(path.join(__dirname, 'results', '70-quality-a11y2-report.json'), JSON.stringify(report, null, 2));
    await browser.close();
    R.save();
})().catch((e) => { console.error('FATAL', e); process.exit(1); });
