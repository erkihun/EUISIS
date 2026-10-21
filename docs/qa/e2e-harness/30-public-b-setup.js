'use strict';
/* Setup as System Admin (UI): QA category, institution feedback QR, employee feedback QR.
   Writes results/30-public-state.json consumed by the later public scripts. */
const { BASE, Recorder, newBrowser, login, visit, shot, expectUrl } = require('./lib');
const fs = require('fs');
const path = require('path');

const ORG_ID = '01a15ba1-3d86-7272-b5ed-9acbad6c9db2'; // Public Service and HR Development Bureau (demo employees' institution)
const EMP_ID = '01a15ba2-4f95-73bb-ace1-64f64f3bc2bf'; // Demo Employee EMP-0001
const STATE_FILE = path.join(__dirname, 'results', '30-public-state.json');

(async () => {
    const R = new Recorder('30-public-b-setup');
    const { browser, page } = await newBrowser();
    R.attach(page);
    const state = fs.existsSync(STATE_FILE) ? JSON.parse(fs.readFileSync(STATE_FILE, 'utf8')) : {};

    await R.step('System Admin logs in without MFA', async () => {
        const url = await login(page, 'qa.sysadmin3@demo.local', 'password');
        if (!/dashboard/.test(url)) throw new Error('landed on ' + url);
    }, page);

    await R.step('Create city-wide public feedback category QA-CAT (complaint + suggestion, both targets)', async () => {
        await visit(page, '/feedback-admin/categories/create');
        await page.fill('#category-code', 'QA-CAT-' + Date.now().toString().slice(-5));
        await page.fill('#category-name-en', 'QA- Service delay');
        await page.fill('#category-name-am', 'QA- የአገልግሎት መዘግየት');
        // Tick complaint + suggestion submission types and both targets (labels per i18n).
        const boxes = page.locator('fieldset input[type=checkbox]');
        const n = await boxes.count();
        const labels = [];
        for (let i = 0; i < n; i++) {
            const label = (await boxes.nth(i).locator('xpath=..').innerText()).trim();
            labels.push(label);
            const checked = await boxes.nth(i).isChecked();
            const want = /complaint|suggestion|tip|employee|institution|organization/i.test(label);
            if (want && !checked) await boxes.nth(i).check();
            if (!want && checked) await boxes.nth(i).uncheck();
        }
        await page.click('button[type=submit]');
        await page.waitForURL(/\/feedback-admin\/categories(\?|$)/, { timeout: 15000 });
        await page.waitForLoadState('networkidle');
        const txt = await page.locator('body').innerText();
        await shot(page, 'setup-category-list');
        if (!/QA- Service delay/.test(txt)) throw new Error('category not listed');
        return `checkbox labels=${JSON.stringify(labels)}`;
    }, page);

    await R.step('Institution QR page: generate the organization feedback QR (deliberate action) and capture the public URL', async () => {
        await visit(page, `/organizations/${ORG_ID}/feedback-qr`);
        const gen = page.getByRole('button', { name: /Generate QR|Enable QR/i });
        if (await gen.count() > 0) {
            await gen.first().click();
            await page.waitForLoadState('networkidle');
            await page.waitForTimeout(800);
        }
        const body = await page.locator('body').innerText();
        const m = body.match(/https?:\/\/[^\s]+\/feedback\/([a-f0-9]{64})/i);
        await shot(page, 'setup-org-qr');
        if (!m) throw new Error('no public /feedback/{token} URL on page: ' + body.slice(0, 300));
        state.orgToken = m[1]; state.orgId = ORG_ID;
        return `token=${m[1].slice(0, 8)}…`;
    }, page);

    await R.step('Employee QR page: generate the employee feedback QR for EMP-0001 and capture the public URL', async () => {
        await visit(page, `/employees/${EMP_ID}/feedback-qr`);
        const gen = page.getByRole('button', { name: /Generate QR/i });
        if (await gen.count() > 0) {
            await gen.first().click();
            await page.waitForLoadState('networkidle');
            await page.waitForTimeout(800);
        }
        const body = await page.locator('body').innerText();
        const m = body.match(/https?:\/\/[^\s]+\/service-feedback\/([a-f0-9]{64})/i);
        await shot(page, 'setup-employee-qr');
        if (!m) throw new Error('no public /service-feedback/{token} URL on page: ' + body.slice(0, 300));
        state.empToken = m[1]; state.empId = EMP_ID;
        return `token=${m[1].slice(0, 8)}…`;
    }, page);

    await R.step('Institution QR list page /feedback-admin/organization-qr renders', async () => {
        const r = await visit(page, '/feedback-admin/organization-qr');
        return `status=${r.status}`;
    }, page);

    fs.mkdirSync(path.dirname(STATE_FILE), { recursive: true });
    fs.writeFileSync(STATE_FILE, JSON.stringify(state, null, 2));
    await browser.close();
    R.save();
})().catch((e) => { console.error('FATAL', e); process.exit(1); });
