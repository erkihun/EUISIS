'use strict';
// Journey 3: responsive layout at 375x812 and 768x1024 (English UI) — overflow, tap targets, mobile drawer.
const fs = require('fs');
const path = require('path');
const { BASE, Recorder, newBrowser, login, shot } = require('./lib');

const ACCOUNT = { email: 'qa.sysadmin6@demo.local', password: 'password' };
const PAGES = ['/login', '/dashboard', '/employees', '/employees/create', '/organizations', '/'];
const VIEWPORTS = [{ w: 375, h: 812, tag: 'mobile' }, { w: 768, h: 1024, tag: 'tablet' }];

async function measure(page) {
    return page.evaluate(() => {
        const vw = window.innerWidth;
        const out = { scrollWidth: document.documentElement.scrollWidth, innerWidth: vw, overflow: document.documentElement.scrollWidth > vw, wideElements: [], smallTargets: [], buttons: 0 };
        // Elements that extend beyond the viewport width
        const all = document.querySelectorAll('body *');
        let count = 0;
        for (const el of all) {
            const r = el.getBoundingClientRect();
            if (r.width === 0) continue;
            if (r.right > vw + 1 && getComputedStyle(el).position !== 'fixed' && !el.closest('[aria-hidden="true"]')) {
                if (count++ < 12) out.wideElements.push({ tag: el.tagName.toLowerCase(), cls: (el.className && String(el.className).slice(0, 60)) || '', right: Math.round(r.right), width: Math.round(r.width), text: (el.innerText || '').replace(/\s+/g, ' ').slice(0, 40) });
            }
        }
        // Primary interactive controls: buttons, links styled as buttons, inputs of type submit
        const targets = document.querySelectorAll('button, a[class*="btn"], a[class*="button"], a[role="button"], input[type="submit"], [role="tab"], [role="menuitem"]');
        for (const el of targets) {
            const r = el.getBoundingClientRect();
            const cs = getComputedStyle(el);
            if (r.width === 0 || r.height === 0 || cs.visibility === 'hidden' || cs.display === 'none') continue;
            if (el.closest('.sr-only')) continue;
            out.buttons++;
            if (r.width < 44 || r.height < 44) {
                if (out.smallTargets.length < 40) out.smallTargets.push({ tag: el.tagName.toLowerCase(), w: Math.round(r.width), h: Math.round(r.height), label: (el.getAttribute('aria-label') || el.innerText || el.value || '').replace(/\s+/g, ' ').slice(0, 40), cls: String(el.className || '').slice(0, 50) });
            }
        }
        return out;
    });
}

(async () => {
    const R = new Recorder('70-quality-responsive');
    const report = {};
    for (const vp of VIEWPORTS) {
        const { browser, context, page } = await newBrowser({ viewport: { width: vp.w, height: vp.h }, contextOptions: { isMobile: vp.w < 500, hasTouch: vp.w < 500 } });
        R.attach(page);
        // /login first (unauthenticated), then sign in for the rest
        for (const p of PAGES) {
            const key = `${vp.tag} ${p}`;
            await R.step(`Responsive ${vp.w}x${vp.h} ${p}`, async () => {
                if (p === '/dashboard' && !/dashboard/.test(page.url())) await login(page, ACCOUNT.email, ACCOUNT.password);
                const resp = await page.goto(`${BASE}${p}`, { waitUntil: 'networkidle' });
                await page.waitForTimeout(600);
                const m = await measure(page);
                const shotName = `q-resp-${vp.tag}-${p.replace(/[^a-z0-9]+/gi, '_').replace(/^_|_$/g, '') || 'home'}`;
                await page.screenshot({ path: path.join(__dirname, 'shots', shotName + '.png'), fullPage: true });
                report[key] = { status: resp && resp.status(), url: page.url(), ...m, shot: shotName + '.png' };
                const primarySmall = m.smallTargets.filter((t) => t.h < 36 || t.w < 36);
                let info = `scrollW=${m.scrollWidth}/${m.innerWidth} buttons=${m.buttons} <44px=${m.smallTargets.length} (<36px=${primarySmall.length})`;
                if (m.overflow) throw new Error(`horizontal overflow: scrollWidth ${m.scrollWidth} > innerWidth ${m.innerWidth}; wide: ${JSON.stringify(m.wideElements.slice(0, 3))}`);
                return info;
            }, page);
        }

        await R.step(`Mobile drawer open/close at ${vp.w}x${vp.h} (dashboard)`, async () => {
            await page.goto(`${BASE}/dashboard`, { waitUntil: 'networkidle' });
            const menuBtn = page.locator('header button[aria-label]').filter({ hasText: /^$/ }).first();
            const openBtn = page.locator('header button').first();
            const label = await openBtn.getAttribute('aria-label');
            const visibleBefore = await page.locator('[role="dialog"]').count();
            await openBtn.click();
            await page.waitForTimeout(500);
            const dialog = page.locator('[role="dialog"]').first();
            const open = await dialog.isVisible().catch(() => false);
            const navLinks = open ? await dialog.locator('a').count() : 0;
            await shot(page, `q-resp-${vp.tag}-drawer-open`);
            // Escape closes
            await page.keyboard.press('Escape');
            await page.waitForTimeout(500);
            const afterEsc = await page.locator('[role="dialog"]').count();
            // Open again, click backdrop/overlay closes
            await openBtn.click();
            await page.waitForTimeout(400);
            await page.mouse.click(vp.w - 5, vp.h / 2);
            await page.waitForTimeout(500);
            const afterBackdrop = await page.locator('[role="dialog"]').count();
            // Open again, navigate via a link closes the drawer
            await openBtn.click();
            await page.waitForTimeout(400);
            const link = page.locator('[role="dialog"] a[href*="/employees"]').first();
            const hasLink = (await link.count()) > 0;
            if (hasLink) { await link.click(); await page.waitForLoadState('networkidle'); await page.waitForTimeout(500); }
            const afterNav = await page.locator('[role="dialog"]').count();
            report[`${vp.tag} drawer`] = { menuLabel: label, open, navLinks, dialogsBefore: visibleBefore, afterEsc, afterBackdrop, afterNav, urlAfterNav: page.url() };
            if (!open) throw new Error('drawer did not open via header button labelled ' + label);
            if (afterEsc !== 0) throw new Error('Escape did not close the drawer');
            if (afterBackdrop !== 0) throw new Error('backdrop click did not close the drawer');
            if (hasLink && afterNav !== 0) throw new Error('drawer stayed open after navigation');
            return `label="${label}" links=${navLinks} esc=closed backdrop=closed nav=closed`;
        }, page);

        await browser.close();
    }
    fs.writeFileSync(path.join(__dirname, 'results', '70-quality-responsive-report.json'), JSON.stringify(report, null, 2));
    R.save();
})().catch((e) => { console.error('FATAL', e); process.exit(1); });
