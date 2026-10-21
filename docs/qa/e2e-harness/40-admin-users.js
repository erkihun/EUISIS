'use strict';
// Journey 1: Users — create (weak/compliant password), edit, national-ID privacy, deactivate/reactivate,
// self-deactivation refusal, last-Super-Admin guard, photo upload (non-image, 2 MB), first login of the new user.
const fs = require('fs');
const path = require('path');
const { BASE, Recorder, newBrowser, newPageIn, login, submitAndLeave, expectUrl, visit, shot } = require('./lib');
const { FIX, sql, body, rowAction, rowMenuItems, confirmDialog, captureInertia, apiCall } = require('./40-admin-lib');

const ADMIN = 'qa.sysadmin5@demo.local';
const NEW_EMAIL = 'qa.user.one@demo.local';
const NEW_NAME = 'QA User One';
const TEMP_PW = 'Violet-Harbor-7731!';
const FINAL_PW = 'Copper-Lantern-5519!';
const NID = '1234567890123456';
const STATE_FILE = path.join(__dirname, 'results', '40-admin-state.json');

(async () => {
    const R = new Recorder('40-admin-users');
    const { browser, context, page } = await newBrowser();
    R.attach(page);
    const st = { newUserId: null, finalPassword: FINAL_PW };

    // A leftover QA user from a previous run is renamed out of the way (audit rows reference it, so it is not deleted).
    const leftover = sql(`select id from users where email='${NEW_EMAIL}'`);
    if (leftover.length) {
        const id = leftover[0][0];
        sql(`update users set email='qa.user.one.old${Date.now()}@demo.local', national_id_hash=null, status='inactive' where id=${id}; delete from sessions where user_id::text='${id}';`);
        console.log('  (renamed leftover user ' + id + ')');
    }

    await R.step('System Admin logs in without MFA and reaches the dashboard', async () => {
        const url = await login(page, ADMIN, 'password');
        await expectUrl(page, /\/dashboard/);
        return url;
    }, page);

    await R.step('Users list renders with Create button and seeded accounts', async () => {
        await visit(page, '/users');
        await page.getByRole('link', { name: /create user|new user|add user/i }).first().waitFor();
        const t = await body(page);
        if (!/qa\.sysadmin5@demo\.local/.test(t)) throw new Error('own account not listed');
        // National IDs must never be listed on the index
        if (/\d{4}\s?\d{4}\s?\d{4}\s?\d{4}/.test(t)) throw new Error('a 16-digit national-ID-like number appears on the users list');
        await shot(page, '40-users-index');
        return 'national IDs not shown on list';
    }, page);

    await R.step('Create user with weak common password is refused by the password policy', async () => {
        await visit(page, '/users/create');
        await page.getByPlaceholder(/full name|name/i).first().fill(NEW_NAME);
        await page.locator('input[type="email"]').fill(NEW_EMAIL);
        const pws = page.locator('input[type="password"]');
        await pws.nth(0).fill('password123');
        await pws.nth(1).fill('password123');
        // HR Officer is an organization-scoped role: pick the first organization and the role
        await page.locator('label').filter({ hasText: /^HR Officer/ }).locator('input[type=checkbox]').check();
        const orgSelect = page.locator('select[multiple]');
        if (await orgSelect.count()) await orgSelect.selectOption({ index: 0 });
        await submitAndLeave(page, /\/users\/create$/, 8000);
        await expectUrl(page, /\/users\/create$/);
        const t = await body(page);
        const m = t.match(/(password[^.]{0,120}\.)/i);
        await shot(page, '40-users-weak-password');
        if (!/common|too easy|not allowed|must be at least|breach|compromised|leaked|password/i.test(t)) throw new Error('no password error shown');
        if (sql(`select id from users where email='${NEW_EMAIL}'`).length) throw new Error('user was created despite weak password');
        return 'error: ' + (m ? m[1] : t.slice(0, 120));
    }, page);

    await R.step('Create user with a short password (7 chars) is refused with min-length error', async () => {
        const pws = page.locator('input[type="password"]');
        await pws.nth(0).fill('Xy7!abc');
        await pws.nth(1).fill('Xy7!abc');
        await submitAndLeave(page, /\/users\/create$/, 8000);
        await expectUrl(page, /\/users\/create$/);
        const t = await body(page);
        if (!/at least 8|minimum|8 characters|አነስተኛ/i.test(t)) throw new Error('no min-length error: ' + t.slice(0, 200));
        return t.match(/([^.]*at least 8[^.]*)/i)?.[1];
    }, page);

    await R.step('Create user with a password containing the holder name is refused (personal-data rule)', async () => {
        const pws = page.locator('input[type="password"]');
        await pws.nth(0).fill('QaUserOne#2026!!');
        await pws.nth(1).fill('QaUserOne#2026!!');
        await submitAndLeave(page, /\/users\/create$/, 8000);
        await expectUrl(page, /\/users\/create$/);
        const errs = (await page.locator('p[class*="text-red"]').allInnerTexts()).join(' | ');
        if (!/personal|name|email|contain|part of/i.test(errs)) throw new Error('personal-data rule not enforced; errors=' + errs);
        return 'errors: ' + errs;
    }, page);

    await R.step('Create user with a compliant password succeeds; must_change_password=true, national ID encrypted at rest', async () => {
        const pws = page.locator('input[type="password"]');
        await pws.nth(0).fill(TEMP_PW);
        await pws.nth(1).fill(TEMP_PW);
        await page.locator('input[maxlength="19"]').fill(NID);
        await submitAndLeave(page, /\/users\/create$/, 15000);
        await expectUrl(page, /\/users(\?|$)/);
        const t = await body(page);
        const rows = sql(`select id, status, must_change_password, national_id, national_id_hash, phone_number from users where email='${NEW_EMAIL}'`);
        if (!rows.length) throw new Error('user row missing');
        const [id, status, mcp, nid, hash] = rows[0];
        st.newUserId = id;
        if (nid === NID) throw new Error('national_id stored in clear text');
        if (!hash || hash.length !== 64) throw new Error('national_id_hash not set');
        await shot(page, '40-users-created');
        const roles = sql(`select r.name from model_has_roles m join roles r on r.id=m.role_id where m.model_id=${id}`).map((r) => r[0]);
        return `id=${id} status=${status} must_change_password=${mcp} roles=${roles.join(',')} nid=${nid.slice(0, 12)}… flash=${/created/i.test(t)}`;
    }, page);

    await R.step('Edit user: national ID is shown in full on the edit form (privacy check), phone/gender/national ID can be changed', async () => {
        await visit(page, `/users/${st.newUserId}/edit`);
        const nidInput = page.locator('input[maxlength="19"]');
        const shown = await nidInput.inputValue();
        const full = shown.replace(/\s/g, '') === NID;
        await shot(page, '40-users-edit-nid');
        await nidInput.fill('6543210987654321');
        await page.locator('input[maxlength="9"]').fill('911223344');
        await page.locator('select').filter({ has: page.locator('option[value="female"]') }).first().selectOption('female');
        await submitAndLeave(page, /\/edit$/, 15000);
        await expectUrl(page, /\/users(\?|$)/);
        const rows = sql(`select gender, national_id_hash, phone_number from users where id=${st.newUserId}`);
        if (rows[0][0] !== 'female') throw new Error('gender not saved: ' + rows[0][0]);
        if (/^\+?251911223344$/.test(rows[0][2])) throw new Error('phone_number stored in clear text');
        const t = await body(page);
        if (!/\+251911223344|911223344/.test(t)) throw new Error('phone not shown on list after update');
        return `national ID displayed ${full ? 'IN FULL (unmasked) on edit form' : 'masked: ' + shown}; phone encrypted at rest; list shows new phone`;
    }, page);

    await R.step('Upload a non-image renamed .png as profile photo is refused', async () => {
        await visit(page, `/users/${st.newUserId}/edit`);
        await page.locator('input[type="file"]').setInputFiles(path.join(FIX, 'notimage.png'));
        await submitAndLeave(page, /\/edit$/, 15000);
        const t = await body(page);
        const stayed = /\/edit$/.test(page.url());
        await shot(page, '40-users-photo-nonimage');
        const dbPath = sql(`select coalesce(profile_photo_path,'-') from users where id=${st.newUserId}`)[0][0];
        if (dbPath !== '-') throw new Error('non-image was stored as profile photo: ' + dbPath);
        if (!stayed || !/image|photo|jpg|png|file/i.test(t)) throw new Error('no validation error for non-image; url=' + page.url());
        return 'error: ' + (t.match(/([^.]*(image|photo)[^.]*\.)/i)?.[1] || '').slice(0, 150);
    }, page);

    await R.step('Upload a 2.02 MB PNG as profile photo (report behaviour; PHP upload_max_filesize=2M)', async () => {
        await visit(page, `/users/${st.newUserId}/edit`);
        await page.locator('input[type="file"]').setInputFiles(path.join(FIX, 'big2mb.png'));
        await submitAndLeave(page, /\/edit$/, 30000);
        const t = await body(page);
        const dbPath = sql(`select coalesce(profile_photo_path,'-') from users where id=${st.newUserId}`)[0][0];
        await shot(page, '40-users-photo-2mb');
        return `url=${page.url().replace(BASE, '')} stored=${dbPath} msg=${(t.match(/([^.]*(image|photo|upload|large|size|kilobytes|MB)[^.]*\.)/i)?.[1] || 'none').slice(0, 160)}`;
    }, page);

    await R.step('Upload a small valid PNG as profile photo succeeds and is stored under users/profile-photos', async () => {
        await visit(page, `/users/${st.newUserId}/edit`);
        await page.locator('input[type="file"]').setInputFiles(path.join(FIX, 'small.png'));
        await submitAndLeave(page, /\/edit$/, 30000);
        await expectUrl(page, /\/users(\?|$)/);
        const dbPath = sql(`select coalesce(profile_photo_path,'-') from users where id=${st.newUserId}`)[0][0];
        if (dbPath === '-') throw new Error('profile_photo_path not set');
        const exists = fs.existsSync(path.join('/home/user/EUISIS/storage/app/public', dbPath));
        return `path=${dbPath} file_exists=${exists}`;
    }, page);

    await R.step('Deactivate the new user from the list (confirm dialog) → status inactive + audit row', async () => {
        await visit(page, `/users?search=${encodeURIComponent(NEW_EMAIL)}`);
        await rowAction(page, NEW_EMAIL, /deactivate/i);
        await confirmDialog(page, /\/deactivate$/);
        const status = sql(`select status from users where id=${st.newUserId}`)[0][0];
        if (status !== 'inactive') throw new Error('status=' + status);
        const audit = sql(`select event_type, actor_user_id, coalesce(request_ip,'NULL') from audit_logs where auditable_id='${st.newUserId}' and event_type='user_deactivated' order by created_at desc limit 1`);
        await shot(page, '40-users-deactivated');
        return `status=inactive audit=${audit[0]?.join('/') || 'MISSING'}`;
    }, page);

    await R.step('Deactivated user cannot log in', async () => {
        const c = await browser.newContext();
        const p = await newPageIn(c);
        await p.goto(`${BASE}/login`, { waitUntil: 'networkidle' });
        await p.fill('input[name="email"]', NEW_EMAIL);
        await p.fill('input[name="password"]', TEMP_PW);
        await submitAndLeave(p, /\/login$/, 8000);
        const u = p.url();
        const t = await body(p);
        await c.close();
        if (!/\/login/.test(u)) throw new Error('inactive user logged in: ' + u);
        return 'stayed on login; msg=' + (t.match(/(inactive|deactivated|disabled|credentials[^.]*\.|not active[^.]*)/i)?.[0] || 'generic');
    });

    await R.step('Reactivate the new user from the list → status active', async () => {
        await visit(page, `/users?search=${encodeURIComponent(NEW_EMAIL)}`);
        await rowAction(page, NEW_EMAIL, /reactivate|restore|activate/i);
        await confirmDialog(page, /\/restore$/);
        const status = sql(`select status from users where id=${st.newUserId}`)[0][0];
        if (status !== 'active') throw new Error('status=' + status);
        return 'status=active';
    }, page);

    await R.step('Self-deactivation: no Deactivate action on own row; direct POST /users/{me}/deactivate refused', async () => {
        await visit(page, `/users?search=${encodeURIComponent(ADMIN)}`);
        const items = await rowMenuItems(page, ADMIN);
        const me = sql(`select id from users where email='${ADMIN}'`)[0][0];
        const r = await apiCall(page, 'POST', `/users/${me}/deactivate`, {});
        const status = sql(`select status from users where id=${me}`)[0][0];
        if (status !== 'active') throw new Error('OWN ACCOUNT WAS DEACTIVATED');
        if (r.status < 400) throw new Error('self-deactivation accepted: HTTP ' + r.status);
        return `menu=[${items.join('|')}] POST→HTTP ${r.status} ${r.text.slice(0, 80)}`;
    }, page);

    await R.step('Self-edit: setting own status=inactive via edit form is refused', async () => {
        const me = sql(`select id from users where email='${ADMIN}'`)[0][0];
        await visit(page, `/users/${me}/edit`);
        await page.locator('select').filter({ has: page.locator('option[value="inactive"]') }).first().selectOption('inactive');
        const cap = captureInertia(page, /\/users\/\d+\/edit/);
        await submitAndLeave(page, /\/edit$/, 15000);
        cap.stop();
        const status = sql(`select status from users where id=${me}`)[0][0];
        if (status !== 'active') throw new Error('OWN ACCOUNT WAS DEACTIVATED via edit');
        const errs = (await page.locator('p[class*="text-red"]').allInnerTexts()).join(' | ');
        await shot(page, '40-users-self-inactive');
        if (!/\/edit$/.test(page.url())) throw new Error('form was accepted (redirected to ' + page.url() + ')');
        return `url=${page.url().replace(BASE, '')} visible_errors=${errs || 'NONE'} server_errors=${JSON.stringify(cap.got.map((g) => g.errors).filter(Boolean))}`;
    }, page);

    await R.step('Removing Super Admin role from the only Super Admin is refused (edit form)', async () => {
        const sa = sql(`select id from users where email='super.admin@demo.local'`)[0][0];
        const before = sql(`select string_agg(r.name, ',') from model_has_roles m join roles r on r.id=m.role_id where m.model_id=${sa}`)[0][0];
        const r = await visit(page, `/users/${sa}/edit`, { expectStatus: undefined }).catch((e) => ({ error: e.message }));
        let how;
        if (r.error) {
            how = 'edit page: ' + r.error;
        } else {
            const cb = page.locator('label').filter({ hasText: /^Super Admin/ }).locator('input[type=checkbox]');
            if (await cb.count() === 0) {
                how = 'Super Admin checkbox not offered';
            } else {
                await cb.uncheck();
                // keep one role checked so roles[] is actually sent (the form omits the field when empty)
                await page.locator('label').filter({ hasText: /^System Admin/ }).locator('input[type=checkbox]').check();
                const cap = captureInertia(page, /\/users\/\d+\/edit/);
                await submitAndLeave(page, /\/edit$/, 15000);
                cap.stop();
                const errs = (await page.locator('p[class*="text-red"]').allInnerTexts()).join(' | ');
                how = `url=${page.url().replace(BASE, '')} visible_errors=${errs || 'NONE'} server_errors=${JSON.stringify(cap.got.map((g) => g.errors).filter(Boolean))}`;
                await shot(page, '40-users-last-superadmin');
            }
        }
        const after = sql(`select string_agg(r.name, ',') from model_has_roles m join roles r on r.id=m.role_id where m.model_id=${sa}`)[0][0];
        if (after !== before) throw new Error(`SUPER ADMIN ROLES CHANGED: ${before} → ${after}`);
        // Also the dedicated assign-roles endpoint
        const api = await apiCall(page, 'POST', `/users/${sa}/assign-roles`, { roles: ['System Admin'] });
        const after2 = sql(`select string_agg(r.name, ',') from model_has_roles m join roles r on r.id=m.role_id where m.model_id=${sa}`)[0][0];
        if (after2 !== before) throw new Error(`SUPER ADMIN ROLES CHANGED via assign-roles: ${before} → ${after2}`);
        return `${how}; assign-roles API → HTTP ${api.status} ${api.text.slice(0, 100)}; roles unchanged (${before})`;
    }, page);

    await R.step('Non-numeric user id in URL (/users/abc/edit) should be 404, not 500', async () => {
        const resp = await page.goto(`${BASE}/users/abc/edit`, { waitUntil: 'networkidle' });
        const status = resp.status();
        if (status >= 500) throw new Error('HTTP ' + status + ' for /users/abc/edit');
        return 'HTTP ' + status;
    }, page);

    await R.step('New user first login is forced to change password; weak new password refused; compliant accepted', async () => {
        const c = await browser.newContext();
        const p = await newPageIn(c);
        const r2 = new Recorder('40-admin-users-newuser'); r2.attach(p);
        await p.goto(`${BASE}/login`, { waitUntil: 'networkidle' });
        await p.fill('input[name="email"]', NEW_EMAIL);
        await p.fill('input[name="password"]', TEMP_PW);
        await submitAndLeave(p, /\/login$/);
        const landed = p.url();
        if (!/change-password/.test(landed)) { await c.close(); throw new Error('not forced to change password, landed on ' + landed); }
        // Protected page stays blocked
        await p.goto(`${BASE}/employees`, { waitUntil: 'networkidle' });
        const blocked = /change-password/.test(p.url());
        await shot(p, '40-users-forced-change');
        await p.fill('#current_password', TEMP_PW);
        await p.fill('#password', 'password123');
        await p.fill('#password_confirmation', 'password123');
        await submitAndLeave(p, /change-password/, 8000);
        const weakRefused = /change-password/.test(p.url());
        await p.fill('#current_password', TEMP_PW);
        await p.fill('#password', FINAL_PW);
        await p.fill('#password_confirmation', FINAL_PW);
        await submitAndLeave(p, /change-password/, 15000);
        const after = p.url();
        const mcp = sql(`select must_change_password from users where id=${st.newUserId}`)[0][0];
        r2.save();
        await c.close();
        if (!weakRefused) throw new Error('weak password accepted on forced change');
        if (/change-password/.test(after)) throw new Error('compliant password not accepted: ' + after);
        if (mcp !== 'f') throw new Error('must_change_password still ' + mcp);
        return `landed=${landed.replace(BASE, '')} employees_blocked=${blocked} weak_refused=${weakRefused} after=${after.replace(BASE, '')} must_change_password=${mcp}`;
    }, page);

    await R.step('New user (HR Officer) sees only HR pages: /employees 200, /users /roles /system-settings /audit-logs 403', async () => {
        const c = await browser.newContext();
        const p = await newPageIn(c);
        const url = await login(p, NEW_EMAIL, FINAL_PW);
        const checks = {};
        for (const u of ['/employees', '/users', '/roles', '/permissions', '/system-settings', '/audit-logs', '/recycle-bin', '/code-rules', '/system-settings/api-management', '/organizations']) {
            const resp = await p.goto(`${BASE}${u}`, { waitUntil: 'networkidle' });
            checks[u] = resp.status() + (p.url().replace(BASE, '') !== u ? '→' + p.url().replace(BASE, '') : '');
        }
        await p.goto(`${BASE}/dashboard`, { waitUntil: 'networkidle' });
        const nav = (await p.locator('nav a, aside a').allInnerTexts()).map((s) => s.trim()).filter(Boolean);
        await shot(p, '40-users-hr-dashboard');
        await c.close();
        const bad = Object.entries(checks).filter(([u, s]) => (u === '/employees' || u === '/organizations' ? !/^200/.test(s) : /^200/.test(s)));
        if (bad.length) throw new Error('unexpected access: ' + JSON.stringify(bad) + ' nav=' + nav.join('|'));
        return `landed=${url.replace(BASE, '')} ${JSON.stringify(checks)} nav=[${nav.slice(0, 25).join('|')}]`;
    }, page);

    fs.mkdirSync(path.dirname(STATE_FILE), { recursive: true });
    fs.writeFileSync(STATE_FILE, JSON.stringify(st, null, 2));
    await browser.close();
    R.save();
})().catch((e) => { console.error('FATAL', e); process.exit(1); });
