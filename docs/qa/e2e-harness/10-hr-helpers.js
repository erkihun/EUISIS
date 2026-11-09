'use strict';
// Shared helpers for the 10-hr-* suite (HR core: organizations, positions, employees, transfers).
const { execFileSync } = require('child_process');
const fs = require('fs');
const path = require('path');

const STATE_FILE = path.join(__dirname, 'results', '10-hr-state.json');
const LOG = '/home/user/EUISIS/storage/logs/laravel.log';

/** Run a read-only SQL statement and return rows as objects (psql -A -t with JSON). */
function sql(query) {
    const out = execFileSync('psql', ['-h', '/tmp', '-p', '54329', '-U', 'postgres', '-d', 'euisis_app', '-At', '-c',
        `select coalesce(json_agg(t), '[]'::json) from (${query}) t`], { encoding: 'utf8' });
    return JSON.parse(out.trim() || '[]');
}

function loadState() {
    try { return JSON.parse(fs.readFileSync(STATE_FILE, 'utf8')); } catch { return {}; }
}
function saveState(state) {
    fs.mkdirSync(path.dirname(STATE_FILE), { recursive: true });
    fs.writeFileSync(STATE_FILE, JSON.stringify(state, null, 2));
}

/** Byte offset of the app log now, so later errors can be attributed to a step. */
function logMark() { try { return fs.statSync(LOG).size; } catch { return 0; } }
/** New `local.ERROR` lines written since `mark` (the Pest suite logs as `testing.*`, which we ignore). */
function logErrorsSince(mark) {
    try {
        const fd = fs.openSync(LOG, 'r');
        const size = fs.statSync(LOG).size;
        const len = Math.max(0, size - mark);
        const buf = Buffer.alloc(len);
        fs.readSync(fd, buf, 0, len, mark);
        fs.closeSync(fd);
        return buf.toString('utf8').split('\n').filter((l) => /local\.(ERROR|CRITICAL)/.test(l)).map((l) => l.slice(0, 600));
    } catch { return []; }
}

/** Attach request/response capture for form POSTs so we can report exact HTTP statuses. */
function captureResponses(page, store) {
    page.on('response', async (r) => {
        const req = r.request();
        if (['POST', 'PATCH', 'PUT', 'DELETE'].includes(req.method())) {
            let body = null;
            if (r.status() >= 400) {
                try { body = (await r.text()).slice(0, 400); } catch {}
            }
            store.push({ method: req.method(), url: r.url(), status: r.status(), at: Date.now(), body, location: r.headers()['location'] || null, postData: (req.postData() || '').slice(0, 600) });
        }
    });
}

/** Flash toast / alert text visible on the page (best effort). */
async function visibleMessages(page) {
    const texts = await page.locator('[role="status"], [role="alert"], [data-sonner-toast], .toast, [class*="toast"]').allInnerTexts().catch(() => []);
    return texts.map((t) => t.replace(/\s+/g, ' ').trim()).filter(Boolean);
}

/** Collect visible error-ish messages (red text) on the page. */
async function fieldErrors(page) {
    const texts = await page.locator('p.text-red-600, p[class*="text-red"], li[class*="text-red"], [role="alert"], [class*="text-danger"], [aria-invalid="true"] ~ p').allInnerTexts().catch(() => []);
    return [...new Set(texts.map((t) => t.replace(/\s+/g, ' ').trim()).filter(Boolean))];
}

/** Words of Latin letters on an Amharic page, ignoring codes/emails/numbers/known neutral tokens. */
function latinLeaks(bodyText) {
    const neutral = new Set(['ok', 'id', 'qr', 'pdf', 'csv', 'sms', 'nfc', 'api', 'am', 'en', 'euisis', 'addis', 'one']);
    const words = bodyText.split(/\s+/).map((w) => w.replace(/^[^A-Za-z]+|[^A-Za-z]+$/g, ''));
    const hits = {};
    for (const w of words) {
        if (!/^[A-Za-z][A-Za-z'-]{2,}$/.test(w)) continue;           // pure latin word, 3+ letters
        if (/^[A-Z0-9-]+$/.test(w)) continue;                        // codes like SC-01, EMP, QA
        if (neutral.has(w.toLowerCase())) continue;
        if (/^QA/.test(w)) continue;
        hits[w] = (hits[w] || 0) + 1;
    }
    return Object.entries(hits).sort((a, b) => b[1] - a[1]).map(([w, c]) => `${w}(${c})`);
}

module.exports = { sql, loadState, saveState, logMark, logErrorsSince, captureResponses, visibleMessages, fieldErrors, latinLeaks, LOG };
