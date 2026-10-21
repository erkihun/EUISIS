'use strict';
// Minimal Chrome DevTools Protocol driver (no Playwright install needed).
const { spawn } = require('child_process');
const crypto = require('crypto');
const fs = require('fs');
const path = require('path');

const CHROME = 'C:/Program Files/Google/Chrome/Application/chrome.exe';
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

async function launchChrome(port, userDataDir) {
    fs.mkdirSync(userDataDir, { recursive: true });
    const proc = spawn(CHROME, [
        '--headless=new', `--remote-debugging-port=${port}`, `--user-data-dir=${userDataDir}`,
        '--no-first-run', '--no-default-browser-check', '--disable-extensions', '--disable-gpu',
        '--disable-background-timer-throttling', '--disable-renderer-backgrounding', '--disable-backgrounding-occluded-windows',
        '--window-size=1366,900', 'about:blank',
    ], { stdio: 'ignore' });
    for (let i = 0; i < 60; i++) {
        try {
            const r = await fetch(`http://127.0.0.1:${port}/json/version`);
            const j = await r.json();
            const b = new Browser(j.webSocketDebuggerUrl, proc);
            await b.open();
            return b;
        } catch { await sleep(250); }
    }
    throw new Error('chrome did not start');
}

class Browser {
    constructor(wsUrl, proc) { this.wsUrl = wsUrl; this.proc = proc; this.id = 0; this.pending = new Map(); this.listeners = new Map(); }
    open() {
        return new Promise((resolve, reject) => {
            this.ws = new WebSocket(this.wsUrl);
            this.ws.onopen = () => resolve();
            this.ws.onerror = (e) => reject(new Error('ws error'));
            this.ws.onmessage = (ev) => {
                const msg = JSON.parse(ev.data);
                if (msg.id && this.pending.has(msg.id)) {
                    const { resolve: res, reject: rej, method } = this.pending.get(msg.id);
                    this.pending.delete(msg.id);
                    if (msg.error) rej(new Error(`${method}: ${msg.error.message}`)); else res(msg.result);
                } else if (msg.method) {
                    const l = this.listeners.get(msg.sessionId || '');
                    if (l) l(msg.method, msg.params);
                }
            };
        });
    }
    send(method, params = {}, sessionId) {
        const id = ++this.id;
        const payload = { id, method, params };
        if (sessionId) payload.sessionId = sessionId;
        return new Promise((resolve, reject) => {
            this.pending.set(id, { resolve, reject, method });
            this.ws.send(JSON.stringify(payload));
            setTimeout(() => { if (this.pending.has(id)) { this.pending.delete(id); reject(new Error(`${method}: CDP timeout`)); } }, 90000);
        });
    }
    async newPage({ viewport = { width: 1366, height: 900 }, locale = 'en', base }) {
        const { browserContextId } = await this.send('Target.createBrowserContext', { disposeOnDetach: true });
        const { targetId } = await this.send('Target.createTarget', { url: 'about:blank', browserContextId });
        const { sessionId } = await this.send('Target.attachToTarget', { targetId, flatten: true });
        const page = new Page(this, sessionId, targetId, browserContextId);
        this.listeners.set(sessionId, (m, p) => page.onEvent(m, p));
        await page.init(viewport, locale, base);
        return page;
    }
    async close() { try { await this.send('Browser.close'); } catch {} try { this.proc.kill(); } catch {} }
}

class Page {
    constructor(browser, sessionId, targetId, ctxId) {
        this.b = browser; this.s = sessionId; this.targetId = targetId; this.ctxId = ctxId;
        this.inflight = new Map(); this.lastNet = Date.now(); this.loadWaiters = [];
        this.console = []; this.exceptions = []; this.badResponses = []; this.docStatus = new Map(); this.mainFrame = null;
    }
    send(m, p) { return this.b.send(m, p, this.s); }
    async init(viewport, locale, base) {
        await this.send('Page.enable');
        await this.send('Network.enable');
        await this.send('Runtime.enable');
        await this.send('Log.enable');
        const tree = await this.send('Page.getFrameTree');
        this.mainFrame = tree.frameTree.frame.id;
        await this.send('Emulation.setDeviceMetricsOverride', { width: viewport.width, height: viewport.height, deviceScaleFactor: 1, mobile: viewport.width < 700 });
        await this.send('Network.setCookie', { name: 'euisis_locale', value: locale, url: base });
        await this.send('Page.addScriptToEvaluateOnNewDocument', { source: `try{localStorage.setItem('euisis_locale','${locale}');localStorage.setItem('locale','${locale}')}catch(e){}` });
    }
    onEvent(method, p) {
        switch (method) {
            case 'Network.requestWillBeSent':
                if (!/^(data|blob):/.test(p.request.url) && p.type !== 'EventSource' && p.type !== 'WebSocket') this.inflight.set(p.requestId, p.request.url);
                this.lastNet = Date.now();
                break;
            case 'Network.loadingFinished':
            case 'Network.loadingFailed':
                if (method === 'Network.loadingFailed' && !p.canceled && this.inflight.has(p.requestId)) {
                    this.badResponses.push({ url: this.inflight.get(p.requestId), status: 'failed', error: p.errorText, page: this.url });
                }
                this.inflight.delete(p.requestId); this.lastNet = Date.now();
                break;
            case 'Network.responseReceived': {
                const st = p.response.status;
                if (p.type === 'Document' && p.frameId === this.mainFrame) this.docStatus.set(p.response.url, st);
                if (st >= 500 || st === 419 || (st >= 400 && p.type !== 'Document' && st !== 401 && st !== 404 && st !== 422 && st !== 429)) {
                    this.badResponses.push({ url: p.response.url, status: st, type: p.type, page: this.url });
                }
                if (p.type === 'XHR' || p.type === 'Fetch') this.lastXhr = { url: p.response.url, status: st };
                break;
            }
            case 'Runtime.consoleAPICalled':
                if (p.type === 'error' || p.type === 'warning' || p.type === 'assert') {
                    const text = p.args.map((a) => a.value ?? a.description ?? '').join(' ').slice(0, 400);
                    this.console.push({ type: p.type, text, page: this.url });
                }
                break;
            case 'Log.entryAdded':
                if (p.entry.level === 'error' && !/Failed to load resource/.test(p.entry.text)) this.console.push({ type: 'log-error', text: p.entry.text.slice(0, 400), page: this.url, src: p.entry.url });
                break;
            case 'Runtime.exceptionThrown':
                this.exceptions.push({ text: (p.exceptionDetails.exception?.description || p.exceptionDetails.text || '').slice(0, 500), page: this.url });
                break;
            case 'Page.loadEventFired':
                for (const w of this.loadWaiters.splice(0)) w();
                break;
            case 'Page.frameNavigated':
                if (!p.frame.parentId) this.url = p.frame.url;
                break;
        }
    }
    async eval(expr) {
        const r = await this.send('Runtime.evaluate', { expression: expr, returnByValue: true, awaitPromise: true });
        if (r.exceptionDetails) throw new Error('eval: ' + (r.exceptionDetails.exception?.description || r.exceptionDetails.text).slice(0, 300));
        return r.result.value;
    }
    async quiet(maxMs = 15000, quietMs = 700) {
        const t0 = Date.now();
        while (Date.now() - t0 < maxMs) {
            if (this.inflight.size === 0 && Date.now() - this.lastNet > quietMs) return true;
            await sleep(100);
        }
        return false;
    }
    async goto(url, { timeout = 60000 } = {}) {
        this.inflight.clear();
        const t0 = Date.now();
        const loaded = new Promise((r) => this.loadWaiters.push(r));
        const nav = await this.send('Page.navigate', { url });
        if (nav.errorText) throw new Error(`navigate ${url}: ${nav.errorText}`);
        const ok = await Promise.race([loaded.then(() => true), sleep(timeout).then(() => false)]);
        if (!ok) throw new Error(`load timeout ${url}`);
        await this.quiet(15000);
        const href = await this.eval('location.href');
        const status = this.docStatus.get(href) ?? this.docStatus.get(url) ?? null;
        return { ms: Date.now() - t0, href, status };
    }
    async waitFor(expr, timeout = 30000, every = 150) {
        const t0 = Date.now();
        while (Date.now() - t0 < timeout) {
            try { const v = await this.eval(expr); if (v) return v; } catch {}
            await sleep(every);
        }
        return null;
    }
    async fill(selector, value) {
        const ok = await this.eval(`(() => { const el = document.querySelector(${JSON.stringify(selector)}); if (!el) return false;
            const proto = el.tagName === 'TEXTAREA' ? HTMLTextAreaElement.prototype : HTMLInputElement.prototype;
            Object.getOwnPropertyDescriptor(proto, 'value').set.call(el, ${JSON.stringify(value)});
            el.dispatchEvent(new Event('input', { bubbles: true })); el.dispatchEvent(new Event('change', { bubbles: true })); return true; })()`);
        if (!ok) throw new Error(`no element ${selector}`);
    }
    async click(selector) {
        const box = await this.eval(`(() => { const el = document.querySelector(${JSON.stringify(selector)}); if (!el) return null; el.scrollIntoView({block:'center'}); const r = el.getBoundingClientRect(); return { x: r.x + r.width / 2, y: r.y + r.height / 2 }; })()`);
        if (!box) throw new Error(`no element ${selector}`);
        await this.send('Input.dispatchMouseEvent', { type: 'mouseMoved', x: box.x, y: box.y });
        await this.send('Input.dispatchMouseEvent', { type: 'mousePressed', x: box.x, y: box.y, button: 'left', clickCount: 1 });
        await this.send('Input.dispatchMouseEvent', { type: 'mouseReleased', x: box.x, y: box.y, button: 'left', clickCount: 1 });
    }
    async screenshot(file) {
        const { data } = await this.send('Page.captureScreenshot', { format: 'png' });
        fs.mkdirSync(path.dirname(file), { recursive: true });
        fs.writeFileSync(file, Buffer.from(data, 'base64'));
        return file;
    }
    async cookies(base) { const { cookies } = await this.send('Network.getCookies', { urls: [base] }); return cookies; }
    async close() { try { await this.b.send('Target.disposeBrowserContext', { browserContextId: this.ctxId }); } catch {} this.b.listeners.delete(this.s); }
    drain() { const out = { console: this.console, exceptions: this.exceptions, bad: this.badResponses }; this.console = []; this.exceptions = []; this.badResponses = []; return out; }
}

function base32Decode(s) {
    const alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    let bits = '';
    for (const c of s.replace(/=+$/, '').toUpperCase()) { const v = alphabet.indexOf(c); if (v >= 0) bits += v.toString(2).padStart(5, '0'); }
    const bytes = [];
    for (let i = 0; i + 8 <= bits.length; i += 8) bytes.push(parseInt(bits.slice(i, i + 8), 2));
    return Buffer.from(bytes);
}
function totp(secret, offset = 0) {
    const key = base32Decode(secret);
    const counter = Math.floor(Date.now() / 1000 / 30) + offset;
    const buf = Buffer.alloc(8);
    buf.writeUInt32BE(Math.floor(counter / 2 ** 32), 0); buf.writeUInt32BE(counter >>> 0, 4);
    const h = crypto.createHmac('sha1', key).update(buf).digest();
    const o = h[h.length - 1] & 0xf;
    return ((h.readUInt32BE(o) & 0x7fffffff) % 1e6).toString().padStart(6, '0');
}

module.exports = { launchChrome, totp, sleep };
