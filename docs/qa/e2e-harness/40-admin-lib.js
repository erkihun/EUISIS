'use strict';
// Shared helpers for the 40-admin-* platform-administration scripts.
const { execFileSync } = require('child_process');
const path = require('path');
const { BASE } = require('./lib');

const FIX = path.join(__dirname, 'fixtures');

/** Run a read query against PostgreSQL and return rows as arrays of strings (tab separated). */
function sql(query) {
    const out = execFileSync('psql', ['-h', '/tmp', '-p', '54329', '-U', 'postgres', '-d', 'euisis_app', '-At', '-F', '\t', '-c', query], { encoding: 'utf8' });
    const t = out.replace(/\n+$/, '');
    return t === '' ? [] : t.split('\n').map((l) => l.split('\t'));
}

async function body(page) { return (await page.locator('body').innerText()).replace(/\s+/g, ' '); }

/** Open the row action menu (kebab button) of the table row containing `rowText`, then click the item labelled `itemRe`. */
async function rowAction(page, rowText, itemRe) {
    const row = page.locator('tr').filter({ hasText: rowText }).first();
    await row.waitFor({ state: 'visible' });
    const btn = row.locator('button[aria-haspopup], button[aria-label]').last();
    await btn.click();
    const item = page.locator('[role="menuitem"]').filter({ hasText: itemRe }).first();
    await item.waitFor({ state: 'visible', timeout: 5000 });
    await item.click();
}

/** Items in the currently open row menu of the row containing rowText (closes it afterwards). */
async function rowMenuItems(page, rowText) {
    const row = page.locator('tr').filter({ hasText: rowText }).first();
    await row.waitFor({ state: 'visible' });
    const btn = row.locator('button[aria-haspopup], button[aria-label]').last();
    if (await btn.count() === 0) return [];
    await btn.click();
    await page.waitForTimeout(300);
    const items = await page.locator('[role="menuitem"]').allInnerTexts();
    await page.keyboard.press('Escape');
    return items.map((s) => s.trim());
}

/** Click the confirm (last) button of the open confirmation dialog. */
async function confirmDialog(page, waitUrlRe = null) {
    const dlg = page.locator('[role="dialog"], [role="alertdialog"]').last();
    await dlg.waitFor({ state: 'visible', timeout: 5000 });
    const wait = waitUrlRe ? page.waitForResponse((r) => waitUrlRe.test(r.url()), { timeout: 15000 }).catch(() => null) : null;
    await dlg.locator('button').last().click();
    if (wait) await wait;
    await page.waitForLoadState('networkidle').catch(() => {});
    await page.waitForTimeout(500);
}

/** Collect Inertia JSON responses (props.errors / props.flash) matching urlRe until stop() is called. */
function captureInertia(page, urlRe) {
    const got = [];
    const h = async (r) => {
        try {
            if (!urlRe.test(r.url())) return;
            if (!/json/.test(r.headers()['content-type'] || '')) return;
            const j = await r.json();
            got.push({ url: r.url(), errors: j?.props?.errors ?? null, flash: j?.props?.flash ?? null });
        } catch {}
    };
    page.on('response', h);
    return { got, stop: () => page.off('response', h) };
}

/** Perform a same-origin fetch from the page using the session + XSRF cookie; returns {status, text}. */
async function apiCall(page, method, url, data) {
    return page.evaluate(async ({ method, url, data }) => {
        const xsrf = decodeURIComponent((document.cookie.match(/XSRF-TOKEN=([^;]+)/) || [])[1] || '');
        const r = await fetch(url, {
            method,
            headers: { 'X-XSRF-TOKEN': xsrf, 'Content-Type': 'application/json', Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            body: data ? JSON.stringify(data) : undefined,
            credentials: 'same-origin',
        });
        return { status: r.status, text: (await r.text()).slice(0, 600) };
    }, { method, url: url.startsWith('http') ? url : `${BASE}${url}`, data });
}

/** Time a navigation; returns {ms, status}. */
async function timedVisit(page, url) {
    const t0 = Date.now();
    const resp = await page.goto(url.startsWith('http') ? url : `${BASE}${url}`, { waitUntil: 'networkidle' });
    return { ms: Date.now() - t0, status: resp ? resp.status() : 0, url: page.url() };
}

module.exports = { FIX, sql, body, rowAction, rowMenuItems, confirmDialog, captureInertia, apiCall, timedVisit };
