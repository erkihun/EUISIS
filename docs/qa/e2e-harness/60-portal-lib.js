'use strict';
const fs = require('fs');
const path = require('path');
const { execSync } = require('child_process');

const STATE_FILE = path.join(__dirname, 'results', '60-portal-state.json');
function loadState() { return fs.existsSync(STATE_FILE) ? JSON.parse(fs.readFileSync(STATE_FILE, 'utf8')) : {}; }
function saveState(state) { fs.mkdirSync(path.dirname(STATE_FILE), { recursive: true }); fs.writeFileSync(STATE_FILE, JSON.stringify(state, null, 2)); }

function sql(q) {
    return execSync(`psql -h /tmp -p 54329 -U postgres -d euisis_app -At -F '|' -c ${JSON.stringify(q)}`, { encoding: 'utf8' }).trim();
}

const esc = (s) => s.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');

/** Control bound to the n-th <label> whose text equals `text` (ignoring the required asterisk). */
async function byLabel(page, text, nth = 0) {
    const re = new RegExp('^\\s*' + esc(text) + '\\s*(\\*\\s*(required|ያስፈልጋል)?)?\\s*$', 'i');
    const label = page.locator('label').filter({ hasText: re }).nth(nth);
    await label.waitFor({ state: 'visible', timeout: 15000 });
    const forId = await label.getAttribute('for');
    if (forId) return page.locator(`[id="${forId}"]`);
    const inner = label.locator('input, select, textarea');
    if (await inner.count()) return inner.first();
    const id = await label.getAttribute('id');
    if (id) return page.locator(`[aria-labelledby="${id}"]`).first();
    throw new Error('label without control: ' + text);
}

async function fillLabel(page, text, value, nth = 0) { const c = await byLabel(page, text, nth); await c.fill(value); return c; }
async function selectLabel(page, text, value, nth = 0) {
    const c = await byLabel(page, text, nth);
    try { await c.selectOption(value); } catch { await c.selectOption({ label: value }); }
    return c;
}
async function selectLabelByText(page, text, re, nth = 0) {
    const c = await byLabel(page, text, nth);
    const opts = await c.locator('option').evaluateAll((os) => os.map((o) => ({ value: o.value, label: o.textContent.trim() })));
    const hit = opts.find((o) => re.test(o.label));
    if (!hit) throw new Error(`no option matching ${re} in ${text}: ${JSON.stringify(opts).slice(0, 300)}`);
    await c.selectOption(hit.value);
    return hit;
}

/** LocalizedDatePicker: the label names a trigger button; the real control is the sr-only <input type=date> before it. */
async function fillDate(page, text, iso, nth = 0) {
    const ctl = await byLabel(page, text, nth);
    const tag = await ctl.evaluate((e) => e.tagName.toLowerCase());
    if (tag === 'input') { await ctl.fill(iso); return; }
    const input = ctl.locator('xpath=preceding-sibling::input[@type="date"]').first();
    await input.fill(iso, { force: true });
}

async function bodyText(page) { return (await page.locator('body').innerText()).replace(/\s+/g, ' '); }
async function flashText(page) {
    const l = page.locator('[role="alert"], [role="status"], .toast, [data-sonner-toast]');
    const n = await l.count();
    const out = [];
    for (let i = 0; i < n; i++) out.push((await l.nth(i).innerText().catch(() => '')).replace(/\s+/g, ' '));
    return out.join(' | ');
}

/** Click a button by text and wait for Inertia to settle. */
async function clickButton(page, re, scope) {
    const root = scope || page;
    const b = root.getByRole('button', { name: re }).first();
    await b.waitFor({ state: 'visible', timeout: 15000 });
    await b.click();
    await page.waitForLoadState('networkidle').catch(() => {});
    await page.waitForTimeout(400);
}

/** Click a page button that opens an AppDialog, confirm it, wait for the POST to settle. Returns the dialog's error text (null when it closed). */
async function dialogAction(page, buttonRe, fieldValue, scope) {
    const b = (scope || page).getByRole('button', { name: buttonRe }).first();
    await b.waitFor({ state: 'visible', timeout: 15000 });
    await b.click();
    const dlg = page.locator('[role="dialog"]').last();
    const btns = dlg.getByRole('button');
    await btns.last().waitFor({ state: 'visible', timeout: 10000 });
    if (fieldValue !== undefined) {
        const inp = dlg.locator('input:not([type=hidden]), textarea').first();
        if (await inp.count()) await inp.fill(fieldValue);
    }
    const waitPost = page.waitForResponse((r) => r.request().method() === 'POST', { timeout: 20000 }).catch(() => null);
    await btns.last().click();
    await waitPost;
    await Promise.race([
        dlg.waitFor({ state: 'detached', timeout: 15000 }),
        dlg.locator('[role="alert"]').first().waitFor({ state: 'visible', timeout: 15000 }),
    ]).catch(() => {});
    await page.waitForLoadState('networkidle').catch(() => {});
    await page.waitForTimeout(400);
    let error = null;
    if (await dlg.count()) {
        const alert = dlg.locator('[role="alert"]');
        if (await alert.count()) error = (await alert.first().innerText()).replace(/\s+/g, ' ');
        await dlg.getByRole('button', { name: /cancel|close|ይቅር/i }).first().click({ timeout: 2000 }).catch(() => {});
    }
    return error;
}

/** Login to the provider portal; returns the landing URL. */
async function providerLogin(page, BASE, identifier, password) {
    await page.goto(`${BASE}/provider/portal/login`, { waitUntil: 'networkidle' });
    await page.fill('#identifier', identifier);
    await page.fill('#password', password);
    await page.click('button[type="submit"]');
    await page.waitForURL((u) => !/\/provider\/portal\/login$/.test(u.toString()), { timeout: 20000, waitUntil: 'networkidle' }).catch(() => {});
    await page.waitForLoadState('networkidle').catch(() => {});
    await page.waitForTimeout(300);
    return page.url();
}

/** Fetch a URL with the page's cookies and return {status, contentType, size, head}. */
async function fetchWithSession(page, url) {
    const resp = await page.context().request.get(url, { maxRedirects: 0, failOnStatusCode: false });
    const buf = await resp.body();
    return { status: resp.status(), contentType: resp.headers()['content-type'] || '', disposition: resp.headers()['content-disposition'] || '', size: buf.length, head: buf.slice(0, 8).toString('latin1'), text: buf.length < 4000 ? buf.toString('utf8').slice(0, 400) : '' };
}

module.exports = { loadState, saveState, sql, byLabel, fillLabel, fillDate, selectLabel, selectLabelByText, bodyText, flashText, clickButton, dialogAction, providerLogin, fetchWithSession };
