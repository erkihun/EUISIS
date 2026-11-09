'use strict';
// Journey 4: dark mode via header toggle and via prefers-color-scheme emulation; contrast sampling.
const fs = require('fs');
const path = require('path');
const { BASE, Recorder, newBrowser, login, shot } = require('./lib');

const ACCOUNT = { email: 'qa.sysadmin6@demo.local', password: 'password' };

const CONTRAST_JS = () => {
    function parse(c) { const m = c.match(/rgba?\(([^)]+)\)/); if (!m) return null; const p = m[1].split(',').map((x) => parseFloat(x)); return { r: p[0], g: p[1], b: p[2], a: p.length > 3 ? p[3] : 1 }; }
    function lum({ r, g, b }) { const f = (v) => { v /= 255; return v <= 0.03928 ? v / 12.92 : Math.pow((v + 0.055) / 1.055, 2.4); }; return 0.2126 * f(r) + 0.7152 * f(g) + 0.0722 * f(b); }
    function blend(fg, bg) { const a = fg.a; return { r: fg.r * a + bg.r * (1 - a), g: fg.g * a + bg.g * (1 - a), b: fg.b * a + bg.b * (1 - a), a: 1 }; }
    function bgOf(el) {
        let node = el; let acc = null;
        while (node && node !== document.documentElement.parentNode) {
            const c = parse(getComputedStyle(node).backgroundColor);
            if (c && c.a > 0) {
                if (c.a >= 1) { return acc ? blend(acc, c) : c; }
                acc = acc ? blend(acc, c) : c; // partial: keep going
            }
            node = node.parentElement;
        }
        const html = parse(getComputedStyle(document.documentElement).backgroundColor);
        const base = html && html.a > 0 ? html : { r: 255, g: 255, b: 255, a: 1 };
        return acc ? blend(acc, base) : base;
    }
    const cands = [];
    const sel = 'h1,h2,h3,p,span,a,td,th,label,button,li,dt,dd,small,time';
    for (const el of document.querySelectorAll(sel)) {
        const t = (el.childNodes.length && Array.from(el.childNodes).some((n) => n.nodeType === 3 && n.textContent.trim())) ? el.innerText.trim() : '';
        if (!t || t.length < 2) continue;
        const r = el.getBoundingClientRect();
        if (r.width === 0 || r.height === 0 || r.top > window.innerHeight * 3) continue;
        const cs = getComputedStyle(el);
        if (cs.visibility === 'hidden' || cs.opacity === '0') continue;
        const fg = parse(cs.color); if (!fg) continue;
        const bg = bgOf(el);
        const fg2 = fg.a < 1 ? blend(fg, bg) : fg;
        const L1 = lum(fg2), L2 = lum(bg);
        const ratio = (Math.max(L1, L2) + 0.05) / (Math.min(L1, L2) + 0.05);
        const size = parseFloat(cs.fontSize); const bold = parseInt(cs.fontWeight) >= 700;
        const large = size >= 24 || (size >= 18.66 && bold);
        cands.push({ tag: el.tagName.toLowerCase(), text: t.replace(/\s+/g, ' ').slice(0, 40), color: cs.color, bg: `rgb(${Math.round(bg.r)},${Math.round(bg.g)},${Math.round(bg.b)})`, ratio: Math.round(ratio * 100) / 100, size, large, pass: ratio >= (large ? 3 : 4.5) });
    }
    // sample: all failing + up to 10 spread
    const fails = cands.filter((c) => !c.pass);
    const step = Math.max(1, Math.floor(cands.length / 10));
    const sample = cands.filter((_, i) => i % step === 0).slice(0, 10);
    return { total: cands.length, failCount: fails.length, fails: fails.slice(0, 25), sample, htmlDark: document.documentElement.classList.contains('dark'), bodyBg: getComputedStyle(document.body).backgroundColor, theme: localStorage.getItem('theme') };
};

(async () => {
    const R = new Recorder('70-quality-dark');
    const { browser, context, page } = await newBrowser();
    R.attach(page);
    const report = {};

    await R.step('Login', async () => { await login(page, ACCOUNT.email, ACCOUNT.password); }, page);

    await R.step('Baseline light contrast on dashboard (sanity)', async () => {
        await page.goto(`${BASE}/dashboard`, { waitUntil: 'networkidle' });
        const r = await page.evaluate(CONTRAST_JS);
        report.lightDashboard = r;
        return `dark=${r.htmlDark} sampled=${r.total} fails=${r.failCount}`;
    }, page);

    await R.step('Header theme toggle cycles system → light → dark and applies html.dark', async () => {
        const btn = page.locator('header button[aria-label*="Theme" i], header button[title*="Theme" i]').first();
        await btn.waitFor({ state: 'visible' });
        const seq = [];
        for (let i = 0; i < 3; i++) {
            const label = await btn.getAttribute('aria-label');
            const dark = await page.evaluate(() => document.documentElement.classList.contains('dark'));
            const stored = await page.evaluate(() => localStorage.getItem('theme'));
            seq.push({ label, dark, stored });
            await btn.click();
            await page.waitForTimeout(300);
        }
        const finalLabel = await btn.getAttribute('aria-label');
        const finalDark = await page.evaluate(() => document.documentElement.classList.contains('dark'));
        const finalStored = await page.evaluate(() => localStorage.getItem('theme'));
        seq.push({ label: finalLabel, dark: finalDark, stored: finalStored });
        report.toggleSequence = seq;
        if (!seq.some((s) => s.dark && s.stored === 'dark')) throw new Error('never reached dark: ' + JSON.stringify(seq));
        return seq.map((s) => `${s.stored}/${s.dark ? 'dark' : 'light'}`).join(' → ');
    }, page);

    await R.step('Dark mode persists across reload (localStorage theme=dark)', async () => {
        await page.evaluate(() => { localStorage.setItem('theme', 'dark'); });
        await page.reload({ waitUntil: 'networkidle' });
        const dark = await page.evaluate(() => document.documentElement.classList.contains('dark'));
        const bg = await page.evaluate(() => getComputedStyle(document.body).backgroundColor);
        if (!dark) throw new Error('html.dark missing after reload');
        return `body bg=${bg}`;
    }, page);

    await R.step('Dark dashboard screenshot + contrast sampling', async () => {
        await page.goto(`${BASE}/dashboard`, { waitUntil: 'networkidle' });
        await page.waitForTimeout(500);
        await page.screenshot({ path: path.join(__dirname, 'shots', 'q-dark-dashboard.png'), fullPage: true });
        const r = await page.evaluate(CONTRAST_JS);
        report.darkDashboard = r;
        if (r.failCount > 0) return `WARN ${r.failCount}/${r.total} text elements < 4.5:1 — e.g. ${JSON.stringify(r.fails.slice(0, 3))}`;
        return `sampled=${r.total} all pass`;
    }, page);

    await R.step('Dark employees list screenshot + contrast sampling', async () => {
        await page.goto(`${BASE}/employees`, { waitUntil: 'networkidle' });
        await page.waitForTimeout(500);
        await page.screenshot({ path: path.join(__dirname, 'shots', 'q-dark-employees.png'), fullPage: true });
        const r = await page.evaluate(CONTRAST_JS);
        report.darkEmployees = r;
        if (r.failCount > 0) return `WARN ${r.failCount}/${r.total} text elements < 4.5:1 — e.g. ${JSON.stringify(r.fails.slice(0, 3))}`;
        return `sampled=${r.total} all pass`;
    }, page);

    await R.step('Dark mode on Amharic dashboard (font + contrast)', async () => {
        await page.evaluate(() => localStorage.setItem('euisis_locale', 'am'));
        await context.addCookies([{ name: 'euisis_locale', value: 'am', url: BASE }]);
        await page.goto(`${BASE}/dashboard`, { waitUntil: 'networkidle' });
        await page.waitForTimeout(500);
        await page.screenshot({ path: path.join(__dirname, 'shots', 'q-dark-dashboard-am.png'), fullPage: true });
        const r = await page.evaluate(CONTRAST_JS);
        const lang = await page.evaluate(() => document.documentElement.lang);
        report.darkDashboardAm = { lang, ...r };
        await page.evaluate(() => localStorage.setItem('euisis_locale', 'en'));
        await context.addCookies([{ name: 'euisis_locale', value: 'en', url: BASE }]);
        return `lang=${lang} fails=${r.failCount}/${r.total}`;
    }, page);

    await R.step('prefers-color-scheme: dark emulation with theme=system follows OS', async () => {
        await page.evaluate(() => localStorage.setItem('theme', 'system'));
        await page.emulateMedia({ colorScheme: 'dark' });
        await page.goto(`${BASE}/employees`, { waitUntil: 'networkidle' });
        const dark = await page.evaluate(() => document.documentElement.classList.contains('dark'));
        await shot(page, 'q-dark-system-employees');
        await page.emulateMedia({ colorScheme: 'light' });
        await page.reload({ waitUntil: 'networkidle' });
        const light = await page.evaluate(() => !document.documentElement.classList.contains('dark'));
        if (!dark) throw new Error('system preference dark not applied');
        if (!light) throw new Error('system preference light not applied');
    }, page);

    await R.step('Public home + login in dark mode (screenshots, contrast)', async () => {
        await page.evaluate(() => localStorage.setItem('theme', 'dark'));
        await page.goto(`${BASE}/`, { waitUntil: 'networkidle' });
        await page.waitForTimeout(500);
        await page.screenshot({ path: path.join(__dirname, 'shots', 'q-dark-home.png'), fullPage: false });
        const r1 = await page.evaluate(CONTRAST_JS);
        report.darkHome = r1;
        const c2 = await browser.newContext();
        await c2.addInitScript(() => localStorage.setItem('theme', 'dark'));
        const p2 = await c2.newPage();
        await p2.goto(`${BASE}/login`, { waitUntil: 'networkidle' });
        await p2.screenshot({ path: path.join(__dirname, 'shots', 'q-dark-login.png') });
        const r2 = await p2.evaluate(CONTRAST_JS);
        report.darkLogin = r2;
        await c2.close();
        return `home fails=${r1.failCount}/${r1.total} login fails=${r2.failCount}/${r2.total} loginDark=${r2.htmlDark}`;
    }, page);

    await R.step('Restore theme preference to system', async () => {
        await page.evaluate(() => localStorage.removeItem('theme'));
    }, page);

    fs.writeFileSync(path.join(__dirname, 'results', '70-quality-dark-report.json'), JSON.stringify(report, null, 2));
    await browser.close();
    R.save();
})().catch((e) => { console.error('FATAL', e); process.exit(1); });
