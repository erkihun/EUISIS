'use strict';
// Journey 7: Amharic on the provider portal — login, scan page, transactions; English leakage and Ethiopian calendar dates.
const { BASE, Recorder, newBrowser, visit, shot } = require('./lib');
const { loadState, bodyText, providerLogin } = require('./60-portal-lib');

const state = loadState();
const ETH_MONTHS = /(መስከረም|ጥቅምት|ኅዳር|ህዳር|ታኅሣሥ|ታህሳስ|ጥር|የካቲት|መጋቢት|ሚያዝያ|ግንቦት|ሰኔ|ሐምሌ|ነሐሴ|ጳጉሜ)/g;
const GREG_MONTHS = /\b(January|February|March|April|May|June|July|August|September|October|November|December)\b/g;
// English words that should not appear in an Amharic UI (brand names and codes excluded)
const ENGLISH = /\b(Scan|Transactions|Dashboard|Provider|Portal|Password|Sign in|Login|Email|Username|Today|Menu|Reports|Ledger|Profile|Usage mode|Start Camera|Stop Camera|QR Token|Process Scan|Gregorian Calendar|Available|Consumed|Closed|Accepted|Denied|Employee|Date|Status|Amount|Subsidy)\b/g;

function leaks(text) {
    const m = text.match(ENGLISH) || [];
    return [...new Set(m)];
}

(async () => {
    const R = new Recorder('60-portal-7-amharic');
    const { browser, page } = await newBrowser({ uiLocale: 'am' });
    R.attach(page);

    await R.step('Provider portal login page in Amharic: labels translated, no English leakage', async () => {
        await visit(page, '/provider/portal/login');
        await shot(page, 'portal-am-login');
        const b = await bodyText(page);
        const l = leaks(b);
        const html = await page.content();
        const lang = (html.match(/<html[^>]*lang="([^"]+)"/) || [])[1];
        if (!/ይግቡ|መግቢያ/.test(b)) throw new Error('no Amharic on login page: ' + b.slice(0, 200));
        return `lang=${lang}; leaks=${JSON.stringify(l)}; sample="${b.slice(0, 120)}"`;
    }, page);

    await R.step('Login (Amharic) and scan page: Ethiopian calendar, Amharic labels, no English leakage', async () => {
        const u = await providerLogin(page, BASE, state.cafeUser.email, state.cafeUser.password);
        if (/login/.test(u)) throw new Error('login failed');
        await visit(page, '/provider/portal/scan');
        await shot(page, 'portal-am-scan');
        const b = await bodyText(page);
        const eth = (b.match(ETH_MONTHS) || []).length;
        const greg = [...new Set(b.match(GREG_MONTHS) || [])];
        const l = leaks(b);
        const cal = (b.match(/(ጥቅምት|መስከረም|ኅዳር|ህዳር)\s*\d{4}/) || [''])[0];
        if (eth === 0) throw new Error('no Ethiopian month on the scan page; gregorian=' + greg.join(',') + '; sample=' + b.slice(0, 300));
        return `calendar header="${cal}"; ethiopian tokens=${eth}; gregorian months=${JSON.stringify(greg)}; english leaks=${JSON.stringify(l)}`;
    }, page);

    await R.step('Scan in Amharic: result banner and today list in Amharic, dates Ethiopian', async () => {
        const { sql } = require('./60-portal-lib');
        const card = sql("select public_card_uuid from id_cards where status='active' and public_card_uuid is not null order by updated_at desc limit 1");
        await page.locator('input[placeholder*="|token"]').fill(card);
        const w = page.waitForResponse((r) => /provider\/portal\/scan$/.test(r.url()) && r.request().method() === 'POST', { timeout: 30000 }).catch(() => null);
        await page.locator('form').filter({ has: page.locator('input[placeholder*="|token"]') }).locator('button[type=submit]').click();
        await w; await page.waitForTimeout(1500);
        await shot(page, 'portal-am-scan-result');
        const b = await bodyText(page);
        const l = leaks(b);
        const greg = [...new Set(b.match(GREG_MONTHS) || [])];
        const banner = (b.match(/.{0,80}(ተመዝግቧል|ተከልክሏል|ውድቅ|ተቀባይነት).{0,80}/) || [''])[0];
        return `banner="${banner}"; english leaks=${JSON.stringify(l)}; gregorian=${JSON.stringify(greg)}`;
    }, page);

    await R.step('Transactions list + dashboard in Amharic: Ethiopian dates, leaks', async () => {
        await visit(page, '/provider/portal/transactions');
        await shot(page, 'portal-am-transactions');
        const b = await bodyText(page);
        const eth = (b.match(ETH_MONTHS) || []).length;
        const iso = (b.match(/\b20\d{2}-\d{2}-\d{2}\b/g) || []).slice(0, 3);
        const l = leaks(b);
        await visit(page, '/provider/portal/dashboard');
        await shot(page, 'portal-am-dashboard');
        const d = await bodyText(page);
        return `transactions: ethiopian tokens=${eth}, iso dates=${JSON.stringify(iso)}, leaks=${JSON.stringify(l)} | dashboard leaks=${JSON.stringify(leaks(d))}`;
    }, page);

    await R.step('Transport portal pages in Amharic (scan + trips): leaks and calendar', async () => {
        const ctx = await browser.newContext(); const p = await (async () => { const { newPageIn } = require('./lib'); return newPageIn(ctx, 'am'); })();
        await providerLogin(p, BASE, state.transportUser.username, state.transportUser.password);
        await visit(p, '/provider/portal/transport/scan');
        await shot(p, 'portal-am-transport-scan');
        const b = await bodyText(p);
        await visit(p, '/provider/portal/transport/trips');
        const t = await bodyText(p);
        await shot(p, 'portal-am-transport-trips');
        await ctx.close();
        return `scan leaks=${JSON.stringify(leaks(b))}; trips leaks=${JSON.stringify(leaks(t))}; trips ethiopian tokens=${(t.match(ETH_MONTHS) || []).length}; iso dates=${JSON.stringify((t.match(/\b20\d{2}-\d{2}-\d{2}\b/g) || []).slice(0, 3))}`;
    });

    await browser.close();
    R.save();
})().catch((e) => { console.error('FATAL', e); process.exit(1); });
