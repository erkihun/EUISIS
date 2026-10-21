'use strict';
// Journey 1 (Amharic rendering on 16 pages) + Journey 2 (language switch persistence, server-side messages).
const fs = require('fs');
const path = require('path');
const { BASE, Recorder, newBrowser, newPageIn, login, submitAndLeave, visit, shot } = require('./lib');

const ACCOUNT = { email: 'qa.sysadmin6@demo.local', password: 'password' };
const COMPLAINT = '/service-feedback/0789527662ee83db6ce2b116ab560a1d7b2170ff9a17910ef602055acc2dd671/complaint';
const PAGES = ['/login', '/dashboard', '/employees', '/employees/create', '/organizations', '/positions', '/card-requests', '/id-cards',
    '/users', '/system-settings', '/audit-logs', '/profile', '/my-portal', '/', COMPLAINT, '/reports'];

const ALLOWED_LATIN = new Set(['QR', 'OTP', 'MFA', 'SMS', 'PDF', 'EXCEL', 'CSV', 'API', 'GPS', 'KPI', 'FTE', 'ISCO', 'ISIC', 'CPC', 'ID', 'NFC', 'TOTP',
    'ADDIS', 'ONE', 'AA-IEMSS', 'IEMSS', 'AA', 'CTRL', 'K', 'URL', 'HTTP', 'HTTPS', 'WWW', 'AM', 'PM', 'EN', 'E.C.', 'EC', 'GC', 'QA', 'UUID', 'PNG', 'JPG', 'JPEG', 'XLSX', 'DOCX', 'JSON', 'IP', 'USB', 'HR', 'IT', 'TV', 'VS']);

// Collect visible text per leaf-ish element, plus page-level facts.
async function analyze(page) {
    return page.evaluate(() => {
        const out = { snippets: [], text: '', fffd: 0 };
        const walker = document.createTreeWalker(document.body, NodeFilter.SHOW_TEXT);
        const seen = new Set();
        let n;
        const parts = [];
        while ((n = walker.nextNode())) {
            const t = n.textContent.replace(/\s+/g, ' ').trim();
            if (!t) continue;
            const el = n.parentElement;
            if (!el) continue;
            if (['SCRIPT', 'STYLE', 'NOSCRIPT', 'TEMPLATE'].includes(el.tagName)) continue;
            const cs = getComputedStyle(el);
            if (cs.display === 'none' || cs.visibility === 'hidden') continue;
            const r = el.getBoundingClientRect();
            if (r.width === 0 && r.height === 0) continue;
            if (el.closest('.sr-only')) continue;
            parts.push(t);
            if (/[A-Za-z]{2,}/.test(t) && !seen.has(t)) {
                seen.add(t);
                const sel = el.tagName.toLowerCase() + (el.id ? '#' + el.id : '') + (el.getAttribute('name') ? '[name=' + el.getAttribute('name') + ']' : '');
                out.snippets.push({ sel, text: t.slice(0, 120), lang: el.closest('[lang]')?.getAttribute('lang') || null, font: cs.fontFamily.split(',')[0] });
            }
        }
        // placeholders / aria-labels / titles / option text also count as visible-ish UI text
        document.querySelectorAll('[placeholder],[aria-label],[title],option').forEach((el) => {
            for (const a of ['placeholder', 'aria-label', 'title']) {
                const v = el.getAttribute(a);
                if (v && /[A-Za-z]{2,}/.test(v) && !seen.has(v)) { seen.add(v); out.snippets.push({ sel: el.tagName.toLowerCase() + '[' + a + ']', text: v.slice(0, 120), attr: a }); }
            }
            if (el.tagName === 'OPTION' && /[A-Za-z]{2,}/.test(el.textContent) && !seen.has(el.textContent.trim())) { seen.add(el.textContent.trim()); out.snippets.push({ sel: 'option', text: el.textContent.trim().slice(0, 120) }); }
        });
        out.text = parts.join('\n');
        out.fffd = (document.body.innerText.match(/�/g) || []).length;
        out.lang = document.documentElement.lang;
        out.dir = document.documentElement.getAttribute('dir');
        out.bodyFont = getComputedStyle(document.body).fontFamily;
        out.bodyLocaleClass = document.body.classList.contains('locale-am');
        out.fontCheckAbyssinica = document.fonts.check('16px "Abyssinica SIL"');
        out.fontsLoaded = Array.from(document.fonts).filter((f) => f.status === 'loaded').map((f) => f.family + ' ' + f.weight);
        out.title = document.title;
        out.ethMonths = (document.body.innerText.match(/መስከረም|ጥቅምት|ኅዳር|ታኅሣሥ|ጥር|የካቲት|መጋቢት|ሚያዝያ|ግንቦት|ሰኔ|ሐምሌ|ነሐሴ|ጳጉሜ/g) || []).slice(0, 10);
        out.gregDates = (document.body.innerText.match(/\b\d{2}\/\d{2}\/\d{4}\b|\b\d{4}-\d{2}-\d{2}\b|\b(?:Jan|Feb|Mar|Apr|May|Jun|Jul|Aug|Sep|Oct|Nov|Dec)[a-z]*\.? \d{1,2},? \d{4}\b|\b\d{1,2} (?:Jan|Feb|Mar|Apr|May|Jun|Jul|Aug|Sep|Oct|Nov|Dec)[a-z]* \d{4}\b/g) || []).slice(0, 10);
        out.amYears = (document.body.innerText.match(/\d{4}\s*ዓ\.?ም/g) || []).slice(0, 5);
        out.rawKeys = (document.body.innerText.match(/\b[a-z]+\.[a-zA-Z]+(?:\.[a-zA-Z]+)+\b/g) || []).filter((k) => !/\.(com|net|org|et|local|js|css|png|jpg)$/.test(k)).slice(0, 10);
        return out;
    });
}

function classifyLatin(snippets) {
    const leaks = [];
    for (const s of snippets) {
        const words = s.text.match(/[A-Za-z][A-Za-z'’\-\.]*/g) || [];
        const significant = words.filter((w) => {
            const u = w.toUpperCase().replace(/\.$/, '');
            if (ALLOWED_LATIN.has(u)) return false;
            if (/^[A-Z0-9\-_]+$/.test(w) && /\d/.test(w)) return false; // codes like ORG-001
            if (/@|https?:/.test(s.text)) return false;
            return w.length >= 2;
        });
        if (significant.length === 0) continue;
        leaks.push({ ...s, words: significant.slice(0, 8) });
    }
    return leaks;
}

(async () => {
    const R = new Recorder('70-quality-amharic');
    const { browser, context, page } = await newBrowser({ uiLocale: 'am' });
    R.attach(page);
    const report = { pages: {}, switch: {} };
    const TOKEN = COMPLAINT.split('/')[2];

    await R.step('Login (Amharic UI) as System Admin', async () => {
        const url = await login(page, ACCOUNT.email, ACCOUNT.password);
        if (!/dashboard/.test(url)) throw new Error('landed on ' + url);
        return url;
    }, page);

    for (const p of PAGES) {
        const name = p === COMPLAINT ? '/service-feedback/{token}/complaint' : p;
        await R.step(`Amharic render ${name}`, async () => {
            const resp = await page.goto(`${BASE}${p}`, { waitUntil: 'networkidle' });
            const status = resp ? resp.status() : 0;
            const finalUrl = page.url();
            await page.waitForTimeout(600);
            const a = await analyze(page);
            const leaks = classifyLatin(a.snippets);
            const entry = { status, finalUrl, title: a.title, lang: a.lang, dir: a.dir, bodyFont: a.bodyFont, localeClass: a.bodyLocaleClass,
                abyssinicaAvailable: a.fontCheckAbyssinica, fontsLoaded: [...new Set(a.fontsLoaded)].slice(0, 8), fffd: a.fffd, ethMonths: a.ethMonths, gregDates: a.gregDates, amYears: a.amYears, rawKeys: a.rawKeys,
                latinSnippetCount: leaks.length, latin: leaks.slice(0, 60) };
            report.pages[name] = entry;
            const shotName = 'q-am-' + name.replace(/[^a-z0-9]+/gi, '_').replace(/^_|_$/g, '') || 'home';
            await shot(page, shotName);
            fs.writeFileSync(path.join(__dirname, 'results', `am-text-${shotName}.txt`), a.text);
            if (status >= 400) throw new Error(`HTTP ${status} (final ${finalUrl})`);
            if (!/^am/.test(a.lang)) throw new Error(`html lang=${a.lang}`);
            if (a.fffd > 0) throw new Error(`${a.fffd} U+FFFD replacement characters`);
            if (!/Abyssinica/i.test(a.bodyFont)) throw new Error('body font is ' + a.bodyFont);
            return `latin=${leaks.length} ethMonths=${a.ethMonths.length} greg=${a.gregDates.length} font=${a.bodyFont.split(',')[0]} fontLoaded=${a.fontCheckAbyssinica} dir=${a.dir}`;
        }, page);
    }

    // ---------- Journey 2: language switch ----------
    await R.step('Switch header language Amharic → English persists (cookie + localStorage + reload)', async () => {
        await page.goto(`${BASE}/dashboard`, { waitUntil: 'networkidle' });
        const sw = page.locator('header [role="group"] button[aria-label="English"]').first();
        await sw.waitFor({ state: 'visible' });
        await sw.click();
        await page.waitForLoadState('networkidle');
        await page.waitForTimeout(800);
        const lang1 = await page.evaluate(() => document.documentElement.lang);
        const ls1 = await page.evaluate(() => localStorage.getItem('euisis_locale'));
        const ck1 = (await context.cookies(BASE)).find((c) => c.name === 'euisis_locale');
        await page.reload({ waitUntil: 'networkidle' });
        const lang2 = await page.evaluate(() => document.documentElement.lang);
        const pressed = await page.locator('header [role="group"] button[aria-pressed="true"]').first().getAttribute('aria-label');
        const navText = await page.locator('nav, aside').first().innerText().catch(() => '');
        report.switch.toEnglish = { lang1, ls1, cookie: ck1 && ck1.value, langAfterReload: lang2, pressedAfterReload: pressed, navSample: navText.slice(0, 120) };
        await shot(page, 'q-switch-en');
        if (lang2 !== 'en' || ls1 !== 'en' || !ck1 || ck1.value !== 'en' || pressed !== 'English') throw new Error(JSON.stringify(report.switch.toEnglish));
        if (/[ሀ-፿]{3,}/.test(navText.replace(/Addis One|AA-IEMSS/g, ''))) return 'WARN: Ethiopic still in nav after switch: ' + navText.slice(0, 80);
    }, page);

    await R.step('New tab in same browser keeps English', async () => {
        const p2 = await context.newPage();
        await p2.goto(`${BASE}/employees`, { waitUntil: 'networkidle' });
        const lang = await p2.evaluate(() => document.documentElement.lang);
        const h = await p2.locator('h1').first().innerText().catch(() => '');
        await p2.close();
        report.switch.newTab = { lang, h1: h };
        if (lang !== 'en') throw new Error('new tab lang=' + lang);
        return `h1="${h}"`;
    }, page);

    await R.step('Server-side validation follows English: empty login form', async () => {
        const c2 = await browser.newContext();
        const p = await newPageIn(c2, 'en');
        await p.goto(`${BASE}/login`, { waitUntil: 'networkidle' });
        await p.evaluate(() => { document.querySelectorAll('form').forEach((f) => (f.noValidate = true)); document.querySelectorAll('[required]').forEach((i) => i.removeAttribute('required')); });
        await submitAndLeave(p, /\/login$/, 5000);
        const t = await p.locator('form').innerText();
        await shot(p, 'q-login-validation-en');
        await c2.close();
        report.switch.loginErrorEn = t.replace(/\n+/g, ' | ').slice(0, 300);
        if (!/required/i.test(t)) throw new Error('no English required-message: ' + t.replace(/\n+/g, ' | ').slice(0, 200));
        return t.match(/[^|]*required[^|]*/i)?.[0].trim();
    }, page);

    await R.step('Server-side validation follows Amharic: empty login form', async () => {
        const c2 = await browser.newContext();
        const p = await newPageIn(c2, 'am');
        await p.goto(`${BASE}/login`, { waitUntil: 'networkidle' });
        await p.evaluate(() => { document.querySelectorAll('form').forEach((f) => (f.noValidate = true)); document.querySelectorAll('[required]').forEach((i) => i.removeAttribute('required')); });
        await submitAndLeave(p, /\/login$/, 5000);
        const t = await p.locator('form').innerText();
        await shot(p, 'q-login-validation-am');
        // wrong password → auth.failed in Amharic
        await p.fill('input[name="email"]', 'nobody@demo.local');
        await p.fill('input[name="password"]', 'wrong-pass-1');
        await submitAndLeave(p, /\/login$/, 5000);
        const t2 = await p.locator('form').innerText();
        await c2.close();
        report.switch.loginErrorAm = t.replace(/\n+/g, ' | ').slice(0, 300);
        report.switch.loginFailedAm = t2.replace(/\n+/g, ' | ').slice(0, 300);
        if (!/አስፈላጊ|ያስፈልጋል|መስክ/.test(t)) throw new Error('no Amharic required-message: ' + t.replace(/\n+/g, ' | ').slice(0, 200));
        if (/required|field/i.test(t)) throw new Error('English leaked in Amharic validation: ' + t.replace(/\n+/g, ' | ').slice(0, 200));
        if (!/ትክክል አይደለም|አይዛመድም/.test(t2)) throw new Error('auth.failed not Amharic: ' + t2.replace(/\n+/g, ' | ').slice(0, 200));
        return t.match(/[^|]*አስፈላጊ[^|]*/)?.[0].trim();
    }, page);

    await R.step('Public home: switch English → Amharic via public header, persists on reload', async () => {
        await page.goto(`${BASE}/`, { waitUntil: 'networkidle' });
        const sw = page.locator('[role="group"] button[aria-label="Amharic"]').first();
        await sw.waitFor({ state: 'visible' });
        await sw.click();
        await page.waitForLoadState('networkidle');
        await page.waitForTimeout(800);
        await page.reload({ waitUntil: 'networkidle' });
        const lang = await page.evaluate(() => document.documentElement.lang);
        const ck = (await context.cookies(BASE)).find((c) => c.name === 'euisis_locale');
        const ls = await page.evaluate(() => localStorage.getItem('euisis_locale'));
        report.switch.backToAm = { lang, cookie: ck && ck.value, ls };
        if (lang !== 'am' || ls !== 'am' || !ck || ck.value !== 'am') throw new Error(JSON.stringify(report.switch.backToAm));
    }, page);

    await R.step('Login page: language switcher present?', async () => {
        const c2 = await browser.newContext();
        const p = await newPageIn(c2, 'am');
        await p.goto(`${BASE}/login`, { waitUntil: 'networkidle' });
        const n = await p.locator('[role="group"][aria-label] button[aria-label="English"]').count();
        const dark = await p.locator('button[aria-label*="Theme"], button[aria-label*="ገጽታ"], button[title*="ገጽታ"]').count();
        await c2.close();
        report.switch.loginSwitcher = { languageButtons: n, themeButtons: dark };
        if (n === 0) throw new Error('no language switcher on /login (visitor cannot change language before signing in)');
        return `language buttons=${n} theme=${dark}`;
    }, page);

    fs.writeFileSync(path.join(__dirname, 'results', '70-quality-amharic-report.json'), JSON.stringify(report, null, 2));
    await browser.close();
    R.save();
})().catch((e) => { console.error('FATAL', e); process.exit(1); });
