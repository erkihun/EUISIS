'use strict';
const crypto = require('crypto');
const fs = require('fs');
const path = require('path');
const { chromium } = require('playwright');

const BASE = process.env.BASE_URL || 'http://127.0.0.1:8123';
const SHOTS = path.join(__dirname, 'shots');
fs.mkdirSync(SHOTS, { recursive: true });

function base32Decode(s) {
    const alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    let bits = '';
    for (const c of s.replace(/=+$/, '').toUpperCase()) {
        const v = alphabet.indexOf(c);
        if (v < 0) continue;
        bits += v.toString(2).padStart(5, '0');
    }
    const bytes = [];
    for (let i = 0; i + 8 <= bits.length; i += 8) bytes.push(parseInt(bits.slice(i, i + 8), 2));
    return Buffer.from(bytes);
}

function totp(secret, offset = 0) {
    const key = base32Decode(secret);
    const counter = Math.floor(Date.now() / 1000 / 30) + offset;
    const buf = Buffer.alloc(8);
    buf.writeUInt32BE(Math.floor(counter / 2 ** 32), 0);
    buf.writeUInt32BE(counter >>> 0, 4);
    const h = crypto.createHmac('sha1', key).update(buf).digest();
    const o = h[h.length - 1] & 0xf;
    const code = ((h.readUInt32BE(o) & 0x7fffffff) % 1e6).toString().padStart(6, '0');
    return code;
}

class Recorder {
    constructor(name) {
        this.name = name;
        this.results = [];
        this.consoleErrors = [];
        this.pageErrors = [];
        this.failedRequests = [];
    }
    attach(page) {
        page.on('console', (m) => {
            if (m.type() === 'error' || m.type() === 'warning') {
                this.consoleErrors.push({ url: page.url(), type: m.type(), text: m.text().slice(0, 400) });
            }
        });
        page.on('pageerror', (e) => this.pageErrors.push({ url: page.url(), text: String(e).slice(0, 400) }));
        page.on('response', (r) => {
            const s = r.status();
            if (s >= 500 || s === 419) this.failedRequests.push({ url: r.url(), status: s, page: page.url() });
        });
    }
    async step(label, fn, page) {
        const t0 = Date.now();
        try {
            const info = await fn();
            this.results.push({ label, status: 'PASS', ms: Date.now() - t0, info: info ?? null });
            console.log(`  PASS  ${label}${info ? ' — ' + info : ''}`);
        } catch (e) {
            const msg = String(e && e.message ? e.message : e).split('\n')[0].slice(0, 300);
            let shot = null;
            if (page) {
                shot = path.join(SHOTS, `${this.name}-${this.results.length + 1}-fail.png`);
                try { await page.screenshot({ path: shot, fullPage: true }); } catch {}
            }
            this.results.push({ label, status: 'FAIL', ms: Date.now() - t0, error: msg, url: page ? page.url() : null, shot });
            console.log(`  FAIL  ${label} — ${msg}${page ? ' @ ' + page.url() : ''}`);
        }
    }
    save() {
        const out = path.join(__dirname, 'results', `${this.name}.json`);
        fs.mkdirSync(path.dirname(out), { recursive: true });
        fs.writeFileSync(out, JSON.stringify({
            name: this.name,
            results: this.results,
            consoleErrors: this.consoleErrors,
            pageErrors: this.pageErrors,
            failedRequests: this.failedRequests,
        }, null, 2));
        const fails = this.results.filter((r) => r.status === 'FAIL').length;
        console.log(`\n${this.name}: ${this.results.length - fails} passed, ${fails} failed, ` +
            `${this.consoleErrors.length} console warnings/errors, ${this.pageErrors.length} page errors, ${this.failedRequests.length} 5xx/419 responses`);
    }
}

async function newBrowser(opts = {}) {
    const browser = await chromium.launch({ headless: true });
    const context = await browser.newContext({
        viewport: opts.viewport || { width: 1366, height: 900 },
        locale: opts.locale || 'en-US',
        ignoreHTTPSErrors: true,
        ...(opts.contextOptions || {}),
    });
    const uiLocale = opts.uiLocale || 'en';
    await context.addCookies([{ name: 'euisis_locale', value: uiLocale, url: BASE }]);
    await context.addInitScript((l) => { try { localStorage.setItem('euisis_locale', l); localStorage.setItem('locale', l); } catch {} }, uiLocale);
    const page = await context.newPage();
    page.setDefaultTimeout(opts.timeout || 20000);
    return { browser, context, page };
}

async function shot(page, name) {
    const p = path.join(SHOTS, `${name}.png`);
    await page.screenshot({ path: p, fullPage: false });
    return p;
}

/**
 * Sign in as a staff user. Handles forced password change (none for demo),
 * MFA enrolment (first login for MFA-required roles) and MFA challenge.
 * Returns the TOTP secret when enrolment or a challenge happened.
 */
/** Click the submit button and wait until the URL no longer matches `fromRe` (or timeout). */
async function submitAndLeave(page, fromRe, timeout = 30000) {
    const before = page.url();
    await page.click('button[type="submit"]');
    try {
        await page.waitForURL((u) => !fromRe.test(u.toString()) && u.toString() !== before, { timeout, waitUntil: 'networkidle' });
    } catch {
        // stayed on the same page (validation error etc.)
    }
    await page.waitForLoadState('networkidle').catch(() => {});
    await page.waitForTimeout(300);
    return page.url();
}

async function login(page, email, password, state = {}) {
    await page.goto(`${BASE}/login`, { waitUntil: 'networkidle' });
    await page.fill('input[name="email"]', email);
    await page.fill('input[name="password"]', password);
    await submitAndLeave(page, /\/login$/);

    for (let i = 0; i < 3; i++) {
        const url = page.url();
        if (url.includes('/mfa/setup')) {
            const secret = (await page.locator('text=/^[A-Z2-7]{16,64}$/').first().textContent()).trim();
            state.secret = secret;
            await page.fill('input[name="code"]', totp(secret));
            await submitAndLeave(page, /\/mfa\/setup/);
            continue;
        }
        if (url.includes('/mfa/challenge')) {
            if (!state.secret) throw new Error('MFA challenge shown but no secret known');
            await page.fill('input[name="code"]', totp(state.secret));
            await submitAndLeave(page, /\/mfa\/challenge/);
            continue;
        }
        break;
    }
    return page.url();
}

async function newPageIn(context, uiLocale = 'en') {
    await context.addCookies([{ name: 'euisis_locale', value: uiLocale, url: BASE }]);
    await context.addInitScript((l) => { try { localStorage.setItem('euisis_locale', l); localStorage.setItem('locale', l); } catch {} }, uiLocale);
    const page = await context.newPage();
    page.setDefaultTimeout(20000);
    return page;
}

async function logout(page) {
    const menu = page.locator('button[aria-label*="ccount"], button[aria-label*="መለያ"]').first();
    await menu.click();
    const item = page.locator('[role="menuitem"] button, [role="menuitem"]').filter({ hasText: /log ?out|sign out|ውጣ|ይውጡ/i }).first();
    await item.click();
    await page.waitForLoadState('networkidle');
    await page.waitForTimeout(500);
}

async function expectUrl(page, re) {
    const u = page.url();
    if (!re.test(u)) throw new Error(`expected url ${re}, got ${u}`);
    return u;
}

async function expectText(page, text, timeout = 15000) {
    await page.getByText(text, { exact: false }).first().waitFor({ state: 'visible', timeout });
}

/** Visit a URL, assert it rendered (status 200, no Laravel error page, Inertia root present). */
async function visit(page, url, opts = {}) {
    const resp = await page.goto(url.startsWith('http') ? url : `${BASE}${url}`, { waitUntil: 'networkidle' });
    const status = resp ? resp.status() : 0;
    const body = await page.content();
    if (/Whoops|Stack trace|SQLSTATE|vendor\/laravel/i.test(body)) throw new Error(`debug/stack output on ${url}`);
    if (opts.expectStatus !== undefined && status !== opts.expectStatus) {
        throw new Error(`expected HTTP ${opts.expectStatus}, got ${status}`);
    }
    if (opts.expectStatus === undefined && status >= 400) throw new Error(`HTTP ${status}`);
    const title = await page.title();
    return { status, title, url: page.url() };
}

module.exports = { BASE, totp, Recorder, newBrowser, newPageIn, login, logout, submitAndLeave, expectUrl, expectText, visit, shot, SHOTS };
