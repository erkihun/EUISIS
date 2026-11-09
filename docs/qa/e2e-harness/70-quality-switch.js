'use strict';
// Journey 2 re-run in a context WITHOUT the harness init script (which re-writes localStorage on every load),
// so persistence across reload / new tab reflects the app, not the harness. Also analyses /login unauthenticated in Amharic.
const fs = require('fs');
const path = require('path');
const { chromium } = require('playwright');
const { BASE, Recorder, login, shot } = require('./lib');

const ACCOUNT = { email: 'qa.sysadmin6@demo.local', password: 'password' };

(async () => {
    const R = new Recorder('70-quality-switch');
    const browser = await chromium.launch({ headless: true });
    const context = await browser.newContext({ viewport: { width: 1366, height: 900 }, locale: 'en-US' });
    const page = await context.newPage();
    page.setDefaultTimeout(20000);
    R.attach(page);
    const report = {};

    await R.step('First visit with no cookie/localStorage renders Amharic by default (html lang, cookie written)', async () => {
        await page.goto(`${BASE}/login`, { waitUntil: 'networkidle' });
        await page.waitForTimeout(500);
        const lang = await page.evaluate(() => document.documentElement.lang);
        const ck = (await context.cookies(BASE)).find((c) => c.name === 'euisis_locale');
        const ls = await page.evaluate(() => localStorage.getItem('euisis_locale'));
        const font = await page.evaluate(() => getComputedStyle(document.body).fontFamily);
        const text = await page.locator('body').innerText();
        const latin = (text.match(/[A-Za-z][A-Za-z' ]{2,}/g) || []).filter((w) => !/Addis One|AA-IEMSS/.test(w));
        const eth = (text.match(/[ሀ-፿]{2,}/g) || []).length;
        report.firstVisit = { lang, cookie: ck && ck.value, ls, font, latin, ethiopicWords: eth, text: text.replace(/\n+/g, ' | ').slice(0, 400) };
        await shot(page, 'q-login-am-fresh');
        if (lang !== 'am') throw new Error('lang=' + lang);
        return `cookie=${ck && ck.value} ls=${ls} latin=${JSON.stringify(latin)}`;
    }, page);

    await R.step('Login page has no language switcher / theme toggle (header controls absent on guest layout)', async () => {
        const n = await page.locator('[role="group"] button[aria-label="English"]').count();
        const t = await page.locator('button[aria-label*="ገጽታ"], button[aria-label*="Theme"]').count();
        report.loginControls = { languageButtons: n, themeButtons: t };
        if (n === 0) throw new Error('no language switcher on /login');
        return `lang=${n} theme=${t}`;
    }, page);

    await R.step('Sign in, switch Amharic → English in the app header', async () => {
        await login(page, ACCOUNT.email, ACCOUNT.password);
        const sw = page.locator('header [role="group"] button[aria-label="English"]').first();
        await sw.waitFor({ state: 'visible' });
        await sw.click();
        await page.waitForLoadState('networkidle');
        await page.waitForTimeout(800);
        const lang = await page.evaluate(() => document.documentElement.lang);
        const ls = await page.evaluate(() => localStorage.getItem('euisis_locale'));
        const ck = (await context.cookies(BASE)).find((c) => c.name === 'euisis_locale');
        const nav = await page.locator('aside').first().innerText().catch(() => '');
        report.afterSwitch = { lang, ls, cookie: ck && ck.value, navSample: nav.replace(/\n+/g, ' | ').slice(0, 160) };
        await shot(page, 'q-switch-en-dashboard');
        if (lang !== 'en' || ls !== 'en' || (ck && ck.value) !== 'en') throw new Error(JSON.stringify(report.afterSwitch));
        return `cookie=${ck.value} ls=${ls} nav="${nav.replace(/\n+/g, ' | ').slice(0, 60)}"`;
    }, page);

    await R.step('English persists after reload', async () => {
        await page.reload({ waitUntil: 'networkidle' });
        await page.waitForTimeout(500);
        const lang = await page.evaluate(() => document.documentElement.lang);
        const pressed = await page.locator('header [role="group"] button[aria-pressed="true"]').first().getAttribute('aria-label');
        const h1 = await page.locator('h1').first().innerText().catch(() => '');
        report.afterReload = { lang, pressed, h1 };
        if (lang !== 'en' || pressed !== 'English') throw new Error(JSON.stringify(report.afterReload));
        return `h1="${h1}" pressed=${pressed}`;
    }, page);

    await R.step('English persists in a new tab (same context)', async () => {
        const p2 = await context.newPage();
        await p2.goto(`${BASE}/employees`, { waitUntil: 'networkidle' });
        await p2.waitForTimeout(500);
        const lang = await p2.evaluate(() => document.documentElement.lang);
        const h1 = await p2.locator('h1').first().innerText().catch(() => '');
        const flashLang = await p2.evaluate(() => document.body.innerText.slice(0, 200));
        await p2.close();
        report.newTab = { lang, h1 };
        if (lang !== 'en' || /[ሀ-፿]/.test(h1)) throw new Error(JSON.stringify({ lang, h1, flashLang }));
        return `h1="${h1}"`;
    }, page);

    await R.step('Cookie-only client (no localStorage) gets server text in English: flash/validation via profile form', async () => {
        // A new context with only the cookie simulates a client whose localStorage was cleared.
        const c2 = await browser.newContext({ viewport: { width: 1366, height: 900 } });
        await c2.addCookies([{ name: 'euisis_locale', value: 'en', url: BASE }]);
        const p = await c2.newPage();
        await p.goto(`${BASE}/login`, { waitUntil: 'networkidle' });
        await p.waitForTimeout(400);
        const lang = await p.evaluate(() => document.documentElement.lang);
        await p.evaluate(() => { document.querySelectorAll('form').forEach((f) => (f.noValidate = true)); document.querySelectorAll('[required]').forEach((i) => i.removeAttribute('required')); });
        await p.click('button[type="submit"]');
        await p.waitForTimeout(1500);
        const t = await p.locator('form').innerText();
        report.cookieOnly = { lang, form: t.replace(/\n+/g, ' | ').slice(0, 300) };
        await c2.close();
        if (lang !== 'en') return `WARN: client rendered lang=${lang} although cookie=en (localStorage wins over cookie); server message: ${t.match(/[^|\n]*(required|አስፈላጊ)[^|\n]*/)?.[0]}`;
        if (!/required/i.test(t)) throw new Error('server validation not English: ' + t.replace(/\n+/g, ' | ').slice(0, 200));
    }, page);

    await R.step('Switch back to Amharic in header and confirm persisted (restore default)', async () => {
        await page.goto(`${BASE}/dashboard`, { waitUntil: 'networkidle' });
        const sw = page.locator('header [role="group"] button[aria-label="Amharic"]').first();
        await sw.click();
        await page.waitForLoadState('networkidle');
        await page.waitForTimeout(800);
        await page.reload({ waitUntil: 'networkidle' });
        const lang = await page.evaluate(() => document.documentElement.lang);
        const ck = (await context.cookies(BASE)).find((c) => c.name === 'euisis_locale');
        const ls = await page.evaluate(() => localStorage.getItem('euisis_locale'));
        report.backToAm = { lang, cookie: ck && ck.value, ls };
        if (lang !== 'am' || ls !== 'am' || (ck && ck.value) !== 'am') throw new Error(JSON.stringify(report.backToAm));
    }, page);

    fs.writeFileSync(path.join(__dirname, 'results', '70-quality-switch-report.json'), JSON.stringify(report, null, 2));
    await browser.close();
    R.save();
})().catch((e) => { console.error('FATAL', e); process.exit(1); });
