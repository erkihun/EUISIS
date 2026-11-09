'use strict';
// Journey 3: Profile — own password change (wrong current, policy, success → other sessions signed out),
// profile settings update, profile photo. Restores the seeded password hash at the end.
const path = require('path');
const { BASE, Recorder, newBrowser, newPageIn, login, visit, shot } = require('./lib');
const { FIX, sql, body, captureInertia } = require('./40-admin-lib');

const ADMIN = 'qa.sysadmin5@demo.local';
const NEW_PW = 'Marigold-Comet-4482!';

async function openTab(page, re) {
    const tab = page.locator('[role="tab"], button').filter({ hasText: re }).first();
    if (await tab.count()) await tab.click();
    await page.waitForTimeout(300);
}

async function changePassword(page, current, next) {
    await visit(page, '/profile');
    await openTab(page, /security|password/i);
    await page.fill('#current_password', current);
    await page.fill('#password', next);
    await page.fill('#password_confirmation', next);
    const cap = captureInertia(page, /\/profile|\/password/);
    const resp = page.waitForResponse((r) => /\/password$/.test(r.url()) && r.request().method() === 'PUT', { timeout: 15000 }).catch(() => null);
    const follow = page.waitForResponse((r) => /\/profile/.test(r.url()) && r.request().method() === 'GET' && /json/.test(r.headers()['content-type'] || ''), { timeout: 15000 }).catch(() => null);
    await page.evaluate(() => { const f = document.querySelector('#password_confirmation').closest('form'); f.querySelector('button[type=submit]').click(); });
    const r = await resp;
    await follow;
    await page.waitForLoadState('networkidle').catch(() => {});
    await page.waitForTimeout(800);
    cap.stop();
    const visible = (await page.locator('p[class*="text-red"], [id$="-error"], [role="alert"]').allInnerTexts()).map((s) => s.trim()).filter(Boolean).join(' | ');
    return { status: r ? r.status() : null, errors: cap.got.map((g) => g.errors).filter((e) => e && Object.keys(e).length), visible, success: await page.getByText(/password updated|saved/i).count() };
}

(async () => {
    const R = new Recorder('40-admin-profile');
    const me = sql(`select id from users where email='${ADMIN}'`)[0][0];
    const origHash = sql(`select password from users where id=${me}`)[0][0];
    const origProfile = sql(`select name, coalesce(gender,''), coalesce(profile_photo_path,''), coalesce(phone_number,'') from users where id=${me}`)[0];
    const histBefore = sql(`select count(*) from password_histories where authenticatable_id='${me}'`)[0][0];
    const { browser, page } = await newBrowser();
    R.attach(page);
    const c2 = await browser.newContext();
    const p2 = await newPageIn(c2);

    try {
        await R.step('Two sessions for the same System Admin (A and B) both reach the dashboard', async () => {
            await login(page, ADMIN, 'password');
            const u = await login(p2, ADMIN, 'password');
            if (!/dashboard/.test(u)) throw new Error('B login failed ' + u);
            const n = sql(`select count(*) from sessions where user_id::text='${me}'`)[0][0];
            return `db sessions for user=${n}`;
        }, page);

        await R.step('Profile page renders (account/security/devices tabs) and lists sessions', async () => {
            await visit(page, '/profile');
            const t = await body(page);
            await shot(page, '40-profile');
            await openTab(page, /devices/i);
            const t2 = await body(page);
            await shot(page, '40-profile-devices');
            return `tabs seen: account=${/account/i.test(t)} security=${/security/i.test(t)} devices=${/device/i.test(t)}; devices text has current/other session=${/current|this device|other/i.test(t2)}`;
        }, page);

        await R.step('Wrong current password → error, no change', async () => {
            const r = await changePassword(page, 'definitely-wrong', NEW_PW);
            const hash = sql(`select password from users where id=${me}`)[0][0];
            if (hash !== origHash) throw new Error('PASSWORD CHANGED with wrong current password');
            if (!r.errors.some((e) => e.current_password) && !/password is incorrect|current password|incorrect|does not match/i.test(r.visible)) throw new Error(`no error: ${JSON.stringify(r)}`);
            return `PUT ${r.status} server=${JSON.stringify(r.errors)} visible="${r.visible.slice(0, 120)}"`;
        }, page);

        await R.step('Policy violation (common password "password123") → error, no change', async () => {
            const r = await changePassword(page, 'password', 'password123');
            const hash = sql(`select password from users where id=${me}`)[0][0];
            if (hash !== origHash) throw new Error('WEAK PASSWORD ACCEPTED');
            if (!r.errors.some((e) => e.password) && !/password/i.test(r.visible)) throw new Error(`no error: ${JSON.stringify(r)}`);
            return `PUT ${r.status} server=${JSON.stringify(r.errors)} visible="${r.visible.slice(0, 120)}"`;
        }, page);

        await R.step('Reusing the current password as the new one is refused (history)', async () => {
            const r = await changePassword(page, 'password', 'password');
            const hash = sql(`select password from users where id=${me}`)[0][0];
            if (hash !== origHash) throw new Error('same password accepted');
            return `PUT ${r.status} server=${JSON.stringify(r.errors)} visible="${r.visible.slice(0, 120)}"`;
        }, page);

        await R.step('Successful change → hash rotated, history row added, audit row; session A still signed in; session B signed out', async () => {
            const r = await changePassword(page, 'password', NEW_PW);
            const hash = sql(`select password from users where id=${me}`)[0][0];
            if (hash === origHash) throw new Error('password not changed: ' + JSON.stringify(r));
            const hist = sql(`select count(*) from password_histories where authenticatable_id='${me}'`)[0][0];
            const audit = sql(`select event_type, coalesce(request_ip,'NULL') from audit_logs where actor_user_id=${me} and event_type like '%password%' order by created_at desc limit 1`)[0];
            await shot(page, '40-profile-password-changed');
            const a = await page.goto(`${BASE}/dashboard`, { waitUntil: 'networkidle' });
            const aOk = /dashboard/.test(page.url());
            const b = await p2.goto(`${BASE}/dashboard`, { waitUntil: 'networkidle' });
            const bOut = /\/login/.test(p2.url());
            await shot(p2, '40-profile-session-b');
            const sessions = sql(`select count(*) from sessions where user_id::text='${me}'`)[0][0];
            if (!aOk) throw new Error('session A was signed out');
            if (!bOut) throw new Error(`session B still signed in at ${p2.url()} (sessions in db=${sessions})`);
            return `PUT ${r.status} success_msg=${r.success} history ${histBefore}→${hist} audit=${audit?.join('/')} A=${a.status()} at /dashboard; B → ${p2.url().replace(BASE, '')}; db sessions=${sessions}`;
        }, page);

        await R.step('Old password no longer works; new password works', async () => {
            const c = await browser.newContext();
            const p = await newPageIn(c);
            await p.goto(`${BASE}/login`, { waitUntil: 'networkidle' });
            await p.fill('input[name="email"]', ADMIN);
            await p.fill('input[name="password"]', 'password');
            await (require('./lib').submitAndLeave)(p, /\/login$/, 8000);
            const oldOk = !/\/login/.test(p.url());
            const u = await login(p, ADMIN, NEW_PW);
            await c.close();
            if (oldOk) throw new Error('old password still accepted');
            if (!/dashboard/.test(u)) throw new Error('new password rejected: ' + u);
            return 'old rejected, new accepted';
        });

        await R.step('Update profile settings (gender, phone) and upload a profile photo → persisted + audit without PII', async () => {
            await visit(page, '/profile');
            await openTab(page, /account/i);
            const sel = page.locator('select').filter({ has: page.locator('option[value="female"]') }).first();
            await sel.selectOption('female');
            const phone = page.locator('input[inputmode="tel"], input[type="tel"]').first();
            if (await phone.count()) await phone.fill('911000111');
            await page.locator('input[type="file"]').first().setInputFiles(path.join(FIX, 'small.png'));
            const cap = captureInertia(page, /\/profile/);
            const resp = page.waitForResponse((r) => /\/profile$/.test(r.url()) && r.request().method() === 'POST', { timeout: 15000 }).catch(() => null);
            await page.evaluate(() => { const f = [...document.querySelectorAll('form')].find((x) => x.querySelector('select')); f.querySelector('button[type=submit]').click(); });
            const r = await resp;
            await page.waitForLoadState('networkidle');
            await page.waitForTimeout(600);
            cap.stop();
            const row = sql(`select coalesce(gender,''), coalesce(profile_photo_path,''), coalesce(phone_number,'') from users where id=${me}`)[0];
            const audit = sql(`select event_type, left(new_values::text,200), coalesce(request_ip,'NULL') from audit_logs where actor_user_id=${me} and event_type in ('profile_updated','profile_photo_updated') order by created_at desc limit 2`);
            await shot(page, '40-profile-updated');
            const errs = cap.got.map((g) => g.errors).filter((e) => e && Object.keys(e).length);
            if (row[0] !== 'female' || !row[1]) throw new Error(`not persisted: ${row.join('/')} POST=${r?.status()} errors=${JSON.stringify(errs)}`);
            if (/911000111/.test(row[2])) throw new Error('phone stored in clear text');
            return `POST ${r?.status()} gender=${row[0]} photo=${row[1]} phone_encrypted=${row[2].startsWith('eyJ')} audit=${audit.map((a) => a.join(' | ')).join(' ;; ')}`;
        }, page);
    } finally {
        // Restore seeded state: password hash, history, profile fields, photo path.
        sql(`update users set password='${origHash}', gender=${origProfile[1] ? `'${origProfile[1]}'` : 'NULL'}, profile_photo_path=${origProfile[2] ? `'${origProfile[2]}'` : 'NULL'}, phone_number=${origProfile[3] ? `'${origProfile[3]}'` : 'NULL'}, must_change_password=false where id=${me}; delete from password_histories where authenticatable_id='${me}' and id not in (select id from password_histories where authenticatable_id='${me}' order by id limit ${histBefore});`);
        const check = sql(`select password = '${origHash}' from users where id=${me}`)[0][0];
        console.log('  (restored seeded password hash for ' + ADMIN + ': ' + check + ')');
    }

    await R.step('Seeded password restored: "password" logs in again', async () => {
        const c = await browser.newContext();
        const p = await newPageIn(c);
        const u = await login(p, ADMIN, 'password');
        await c.close();
        if (!/dashboard/.test(u)) throw new Error('restore failed: ' + u);
    });

    await c2.close();
    await browser.close();
    R.save();
})().catch((e) => { console.error('FATAL', e); process.exit(1); });
