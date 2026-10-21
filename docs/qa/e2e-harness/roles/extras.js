'use strict';
// Module entry points that roles reach outside the sidebar (header icons, in-module tabs).
const fs = require('fs');
const path = require('path');
const { launchChrome } = require('./cdp');

const BASE = process.env.BASE_URL || 'http://127.0.0.1:8123';
const SCRATCH = path.resolve(__dirname, '..');
const SHOTS = path.join(__dirname, 'shots');
const { password, accounts } = JSON.parse(fs.readFileSync(path.join(SCRATCH, 'qa-users.json'), 'utf8'));
const CHECKS = process.env.ONLY ? JSON.parse(process.env.ONLY) : {
    'Communication Moderator': { allow: ['/communication', '/communication/messages', '/communication/moderation'], deny: ['/communication/investigations', '/communication/admin/settings'] },
    'Communication Investigator': { allow: ['/communication', '/communication/investigations'], deny: ['/communication/moderation', '/communication/admin/settings'] },
    'Communication Privacy Officer': { allow: ['/communication', '/communication/investigations'], deny: ['/communication/moderation', '/communication/admin/settings'] },
    'Comm UAT Employee': { allow: ['/communication', '/communication/messages'], deny: ['/communication/moderation', '/communication/investigations'] },
    'Report Viewer': { allow: [], deny: ['/communication/moderation', '/communication/investigations'] },
};

async function login(page, email) {
    await page.goto(`${BASE}/login`);
    await page.waitFor(`!!document.querySelector('input[name="email"]')`);
    await page.fill('input[name="email"]', email);
    await page.fill('input[name="password"]', password);
    await page.click('button[type="submit"]');
    if (!await page.waitFor(`!/\\/login(\\?|$)/.test(location.pathname)`, 45000)) throw new Error('login stuck');
    await page.quiet(8000);
}

(async () => {
    const browser = await launchChrome(9700 + Math.floor(Math.random() * 100), path.join(SCRATCH, 'chrome-extras-' + process.pid));
    const out = [];
    for (const [role, chk] of Object.entries(CHECKS)) {
        const a = accounts.find((x) => x.role === role);
        const page = await browser.newPage({ base: BASE });
        await login(page, a.email);
        const header = await page.eval(`[...document.querySelectorAll('header a[href], header button[aria-label]')].map(e => (e.getAttribute('aria-label') || e.innerText || '').trim() + ' ' + (e.getAttribute('href') || '')).filter(Boolean)`);
        for (const [kind, list] of [['allow', chk.allow], ['deny', chk.deny]]) {
            for (const p of list) {
                const g = await page.goto(BASE + p);
                const comp = await page.eval(`(history.state && history.state.page && history.state.page.component) || null`).catch(() => null);
                const d = page.drain();
                const ok = kind === 'allow' ? g.status === 200 && !d.exceptions.length : g.status === 403 || g.status === 404 || !g.href.endsWith(p);
                const row = { role, kind, path: p, status: g.status, final: g.href.replace(BASE, ''), component: comp, exceptions: d.exceptions.map((e) => e.text.slice(0, 200)), bad: d.bad.slice(0, 2), ok };
                if (!ok) row.shot = await page.screenshot(path.join(SHOTS, `extras-${role.replace(/\W+/g, '-')}-${p.replace(/\W+/g, '-')}.png`));
                out.push(row);
                console.log(`${ok ? 'PASS' : 'FAIL'} ${role} ${kind} ${p} -> ${g.status} ${row.final} ${comp || ''} ${d.exceptions.length ? 'JS:' + d.exceptions[0].text.slice(0, 120) : ''}`);
            }
        }
        out.push({ role, header });
        await page.close();
    }
    fs.writeFileSync(path.join(__dirname, 'results', 'extras.json'), JSON.stringify(out, null, 2));
    await browser.close();
})().catch((e) => { console.error('FATAL', e); process.exit(1); });
