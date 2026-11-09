'use strict';
const path = require('path');
const { BASE, Recorder, newBrowser, login, submitAndLeave, visit, shot } = require('./lib');
const { FIX, sql, captureInertia } = require('./40-admin-lib');
(async () => {
    const R = new Recorder('40-admin-users-photo');
    const { browser, page } = await newBrowser();
    R.attach(page);
    await login(page, 'qa.sysadmin5@demo.local', 'password');
    const id = sql(`select id from users where email='qa.user.one@demo.local'`)[0][0];
    for (const f of ['notimage.png', 'big2mb.png', 'notimage.txt']) {
        await R.step('Upload ' + f + ' as profile photo', async () => {
            await visit(page, `/users/${id}/edit`);
            const cap = captureInertia(page, /\/users\/\d+\/edit/);
            await page.locator('input[type="file"]').setInputFiles(path.join(FIX, f));
            const respP = page.waitForResponse((r) => /\/users\/\d+$/.test(r.url()) && r.request().method() === 'POST', { timeout: 30000 }).catch(() => null);
            await submitAndLeave(page, /\/edit$/, 30000);
            const resp = await respP;
            cap.stop();
            const errs = (await page.locator('p[class*="text-red"]').allInnerTexts()).join(' | ');
            await shot(page, '40-users-photo-' + f.replace('.', '-'));
            return `POST→${resp ? resp.status() : 'n/a'} url=${page.url().replace(BASE, '')} visible=${errs || 'NONE'} server=${JSON.stringify(cap.got.map((g) => g.errors).filter(Boolean))}`;
        }, page);
    }
    await browser.close(); R.save();
})().catch((e) => { console.error('FATAL', e); process.exit(1); });
