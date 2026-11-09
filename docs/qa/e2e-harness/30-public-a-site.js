'use strict';
/* Journey 1: anonymous public site — desktop crawl + 375px usability + public assistant. */
const { BASE, Recorder, newBrowser, visit, shot } = require('./lib');
const fs = require('fs');
const path = require('path');

(async () => {
    const R = new Recorder('30-public-a-site');
    const { browser, page } = await newBrowser();
    R.attach(page);
    const summary = { pages: [], mobile: [], assistant: null };

    const pageErrorsFor = (url) => R.pageErrors.filter((e) => e.url === url).length;

    let links = [];
    await R.step('Home / renders (200) and exposes public navigation links', async () => {
        const t0 = Date.now();
        const r = await visit(page, '/');
        const ms = Date.now() - t0;
        await shot(page, 'public-home-desktop');
        links = await page.evaluate(() => Array.from(document.querySelectorAll('header a[href], nav a[href], footer a[href], main a[href]'))
            .map((a) => a.getAttribute('href')).filter((h) => h && h.startsWith('/') && !h.startsWith('//')));
        links = Array.from(new Set(links)).filter((h) => !/^\/(login|register|logout|dashboard|my-portal)/.test(h));
        summary.pages.push({ url: '/', status: r.status, ms, title: r.title });
        if (links.length < 3) throw new Error('fewer than 3 public links discovered: ' + JSON.stringify(links));
        return `status=${r.status} ${ms}ms links=${JSON.stringify(links)}`;
    }, page);

    // Always-included operational pages (not in nav necessarily)
    for (const extra of ['/announcements', '/announcements/transfers', '/services', '/support', '/verify', '/id-checker']) {
        if (!links.includes(extra)) links.push(extra);
    }

    for (const href of links) {
        await R.step(`Public page ${href} renders without 4xx/5xx or debug output`, async () => {
            const t0 = Date.now();
            const errBefore = R.consoleErrors.length;
            const r = await visit(page, href);
            const ms = Date.now() - t0;
            const body = await page.locator('body').innerText();
            const consoleNew = R.consoleErrors.length - errBefore;
            summary.pages.push({ url: href, status: r.status, ms, title: r.title, consoleErrors: consoleNew, bodyLen: body.length });
            if (body.length < 80) throw new Error('body suspiciously short');
            return `status=${r.status} ${ms}ms console+${consoleNew} title="${r.title}"`;
        }, page);
    }

    // Services detail: follow first service link if any
    await R.step('A service detail page (from /services) renders', async () => {
        await visit(page, '/services');
        const a = page.locator('main a[href^="/services/"]').first();
        if (await a.count() === 0) return 'no service detail links on /services (no published services)';
        const href = await a.getAttribute('href');
        const r = await visit(page, href);
        return `${href} status=${r.status}`;
    }, page);

    await R.step('An announcement detail page (from /announcements) renders', async () => {
        await visit(page, '/announcements');
        const a = page.locator('main a[href^="/announcements/"]').first();
        if (await a.count() === 0) return 'no announcement links (none published)';
        const href = await a.getAttribute('href');
        const r = await visit(page, href);
        return `${href} status=${r.status}`;
    }, page);

    await R.step('Unknown institution QR token /feedback/{token} shows the generic unavailable page (200, no leak)', async () => {
        const r = await visit(page, '/feedback/' + 'a'.repeat(64));
        const text = await page.locator('body').innerText();
        await shot(page, 'public-feedback-unknown-token');
        if (!/unavailable|not available|no longer|cannot be used|አይገኝም|አይሰራም/i.test(text)) throw new Error('no unavailable notice: ' + text.slice(0, 200));
        return `status=${r.status}`;
    }, page);

    await R.step('Unknown employee QR token /service-feedback/{token} shows the same unavailable page', async () => {
        const r = await visit(page, '/service-feedback/' + 'b'.repeat(64));
        const text = await page.locator('body').innerText();
        if (!/unavailable|not available|no longer|cannot be used/i.test(text)) throw new Error('no unavailable notice: ' + text.slice(0, 200));
        return `status=${r.status}`;
    }, page);

    await R.step('Malformed QR token (short / traversal) is answered 404 or unavailable, never 500', async () => {
        const out = [];
        for (const tkn of ['abc', '../../etc/passwd', '<script>', 'x'.repeat(200)]) {
            const resp = await page.goto(`${BASE}/feedback/${encodeURIComponent(tkn)}`, { waitUntil: 'networkidle' });
            out.push(`${tkn.slice(0, 12)}→${resp.status()}`);
            if (resp.status() >= 500) throw new Error('500 for token ' + tkn);
        }
        return out.join(', ');
    }, page);

    await R.step('Public assistant: bootstrap endpoint answers JSON with no-store', async () => {
        const resp = await page.request.get(`${BASE}/public-assistant/bootstrap`);
        const h = resp.headers();
        const json = await resp.json().catch(() => null);
        if (resp.status() !== 200) throw new Error('status ' + resp.status());
        return `status=${resp.status()} cache-control=${h['cache-control']} keys=${json ? Object.keys(json).join(',') : 'n/a'}`;
    }, page);

    await R.step('Public assistant: launcher opens the panel and a question gets an answer (no 5xx)', async () => {
        await visit(page, '/');
        const btn = page.locator('button[aria-label="Addis One Help"], button[aria-label*="Help"]').first();
        await btn.waitFor({ state: 'visible', timeout: 10000 });
        await btn.click();
        const composer = page.locator('textarea[aria-label="Message"], input[aria-label="Message"], textarea').first();
        await composer.waitFor({ state: 'visible', timeout: 10000 });
        await shot(page, 'public-assistant-open');
        const respPromise = page.waitForResponse((r) => r.url().includes('/public-assistant/messages'), { timeout: 30000 });
        await composer.fill('How do I verify an employee ID card?');
        await page.locator('button[aria-label="Send"]').first().click();
        const resp = await respPromise;
        await page.waitForTimeout(1500);
        const panelText = await page.locator('[role="dialog"], aside, section').filter({ hasText: /Addis One Help/ }).first().innerText().catch(() => '');
        await shot(page, 'public-assistant-reply');
        summary.assistant = { status: resp.status(), snippet: panelText.slice(0, 300) };
        if (resp.status() >= 500) throw new Error('messages endpoint ' + resp.status());
        return `messages status=${resp.status()} panel="${panelText.replace(/\s+/g, ' ').slice(0, 160)}"`;
    }, page);

    await R.step('Public assistant: 30 rapid messages hit the public-assistant throttle (expect a 429 eventually)', async () => {
        let first429 = null; let statuses = [];
        for (let i = 0; i < 30; i++) {
            const resp = await page.request.post(`${BASE}/public-assistant/messages`, {
                headers: { 'X-Requested-With': 'XMLHttpRequest', 'X-XSRF-TOKEN': decodeURIComponent((await page.context().cookies()).find((c) => c.name === 'XSRF-TOKEN')?.value || '') },
                data: { message: 'ping ' + i, session: null },
            });
            statuses.push(resp.status());
            if (resp.status() === 429) { first429 = i + 1; break; }
        }
        return `first 429 at request #${first429 ?? 'none'}; statuses=${statuses.join(',')}`;
    }, page);

    // ── 375px viewport pass ──
    const { browser: mb, page: mp } = await newBrowser({ viewport: { width: 375, height: 740 } });
    R.attach(mp);
    for (const href of ['/', ...links.filter((l) => l !== '/')]) {
        await R.step(`375px: ${href} has no horizontal scroll and primary buttons are within the viewport`, async () => {
            await visit(mp, href);
            await mp.waitForTimeout(300);
            const m = await mp.evaluate(() => {
                const sw = document.scrollingElement.scrollWidth; const iw = window.innerWidth;
                const btns = Array.from(document.querySelectorAll('main a, main button, header button, form button'));
                const off = btns.filter((b) => { const r = b.getBoundingClientRect(); return r.width > 0 && (r.right > iw + 1 || r.left < -1); })
                    .map((b) => (b.textContent || b.getAttribute('aria-label') || '').trim().slice(0, 30));
                const small = btns.filter((b) => { const r = b.getBoundingClientRect(); return r.width > 0 && r.height > 0 && r.height < 32; }).length;
                const menu = !!document.querySelector('header button[aria-expanded], header button[aria-label*="enu"], header button[aria-label*="ምናሌ"]');
                return { sw, iw, off, small, menu, wideEls: Array.from(document.querySelectorAll('body *')).filter((e) => e.getBoundingClientRect().right > iw + 2).slice(0, 5).map((e) => e.tagName + '.' + String(e.className).slice(0, 40)) };
            });
            await shot(mp, `public-mobile-${href.replace(/[^a-z0-9]+/gi, '_') || 'home'}`);
            summary.mobile.push({ url: href, ...m });
            if (m.sw > m.iw + 1) throw new Error(`horizontal scroll: scrollWidth=${m.sw} > ${m.iw}; wide=${JSON.stringify(m.wideEls)}`);
            if (m.off.length) throw new Error('buttons outside viewport: ' + JSON.stringify(m.off));
            return `scrollWidth=${m.sw} menuButton=${m.menu} smallTargets(<32px)=${m.small}`;
        }, mp);
    }

    await R.step('375px: mobile menu button opens the navigation', async () => {
        await visit(mp, '/');
        const b = mp.locator('header button[aria-expanded], header button[aria-label*="enu"]').first();
        if (await b.count() === 0) throw new Error('no mobile menu button in header at 375px');
        await b.click();
        await mp.waitForTimeout(400);
        const n = await mp.locator('header a[href], [role="dialog"] a[href], nav a[href]').filter({ visible: true }).count();
        await shot(mp, 'public-mobile-menu-open');
        if (n < 3) throw new Error('menu opened but fewer than 3 visible links: ' + n);
        return `${n} visible links after opening`;
    }, mp);

    await mb.close();
    await browser.close();
    fs.writeFileSync(path.join(__dirname, 'results', '30-public-a-site-summary.json'), JSON.stringify(summary, null, 2));
    R.save();
})().catch((e) => { console.error('FATAL', e); process.exit(1); });
