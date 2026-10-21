'use strict';
// Journey 2: Roles — create QA Role with 3 permissions, assign to QA User One, verify a page becomes reachable,
// remove the permission → 403; Super Admin role edit/delete refused. Plus: deactivated-user login behaviour.
const fs = require('fs');
const path = require('path');
const { BASE, Recorder, newBrowser, newPageIn, login, submitAndLeave, expectUrl, visit, shot } = require('./lib');
const { sql, body, rowAction, rowMenuItems, confirmDialog, captureInertia, apiCall } = require('./40-admin-lib');

const ADMIN = 'qa.sysadmin5@demo.local';
const NEW_EMAIL = 'qa.user.one@demo.local';
const ROLE = 'QA Role';
const PERMS = ['code-rules.viewAny', 'code-rules.view', 'audit-logs.viewAny'];
const STATE_FILE = path.join(__dirname, 'results', '40-admin-state.json');

(async () => {
    const R = new Recorder('40-admin-roles');
    const st = JSON.parse(fs.readFileSync(STATE_FILE, 'utf8'));
    const { browser, context, page } = await newBrowser();
    R.attach(page);
    const userId = sql(`select id from users where email='${NEW_EMAIL}'`)[0][0];
    const userPw = st.finalPassword;

    // Repeatability: drop a leftover QA Role (no audit FK on roles).
    const left = sql(`select id from roles where name='${ROLE}'`);
    if (left.length) sql(`delete from model_has_roles where role_id=${left[0][0]}; delete from role_has_permissions where role_id=${left[0][0]}; delete from roles where id=${left[0][0]};`);

    // Second browser: the QA user, to observe permission changes taking effect.
    const uc = await browser.newContext();
    const up = await newPageIn(uc);
    const UR = new Recorder('40-admin-roles-user'); UR.attach(up);

    await R.step('System Admin logs in; QA user logs in (separate context)', async () => {
        await login(page, ADMIN, 'password');
        const u = await login(up, NEW_EMAIL, userPw);
        if (!/dashboard/.test(u)) throw new Error('QA user login failed: ' + u);
    }, page);

    await R.step('Before role: QA user gets 403 on /code-rules and /audit-logs', async () => {
        const a = (await up.goto(`${BASE}/code-rules`, { waitUntil: 'networkidle' })).status();
        const b = (await up.goto(`${BASE}/audit-logs`, { waitUntil: 'networkidle' })).status();
        if (a !== 403 || b !== 403) throw new Error(`code-rules=${a} audit-logs=${b}`);
        return `code-rules=${a} audit-logs=${b}`;
    }, up);

    await R.step('Roles list renders; Super Admin row is marked full access and offers no Edit/Delete to a System Admin', async () => {
        await visit(page, '/roles?search=Super+Admin');
        const items = await rowMenuItems(page, 'Super Admin');
        const t = await body(page);
        await shot(page, '40-roles-index');
        if (items.some((i) => /edit|delete/i.test(i))) throw new Error('Super Admin row offers: ' + items.join('|'));
        return `menu=[${items.join('|') || 'no menu'}] full-access badge=${/full access/i.test(t)}`;
    }, page);

    await R.step('Create role "QA Role" (global) with 3 permissions', async () => {
        await visit(page, '/roles/create');
        await page.locator('form input:not([type=search]):not([type=checkbox])').first().fill(ROLE);
        await page.locator('select').first().selectOption('global');
        for (const p of PERMS) {
            const lab = page.locator('label').filter({ has: page.locator(`span.font-mono`, { hasText: new RegExp('^' + p.replace('.', '\\.') + '$') }) }).first();
            await lab.locator('input[type=checkbox]').check();
        }
        await submitAndLeave(page, /\/roles\/create$/, 15000);
        await expectUrl(page, /\/roles(\?|$)/);
        const rows = sql(`select r.id, r.scope_type, string_agg(p.name, ',' order by p.name) from roles r left join role_has_permissions rp on rp.role_id=r.id left join permissions p on p.id=rp.permission_id where r.name='${ROLE}' group by r.id, r.scope_type`);
        if (!rows.length) throw new Error('role row missing');
        st.roleId = rows[0][0];
        if (rows[0][2] !== PERMS.slice().sort().join(',')) throw new Error('permissions mismatch: ' + rows[0][2]);
        const audit = sql(`select count(*) from audit_logs where event_type='role_created' and new_values::text like '%${ROLE}%'`)[0][0];
        return `id=${rows[0][0]} scope=${rows[0][1]} perms=${rows[0][2]} audit_rows=${audit}`;
    }, page);

    await R.step('Duplicate role name is refused', async () => {
        await visit(page, '/roles/create');
        await page.locator('form input:not([type=search]):not([type=checkbox])').first().fill(ROLE);
        await submitAndLeave(page, /\/roles\/create$/, 8000);
        await expectUrl(page, /\/roles\/create$/);
        const errs = (await page.locator('p[class*="text-red"]').allInnerTexts()).join(' | ');
        if (!/taken|exists|already/i.test(errs)) throw new Error('no uniqueness error: ' + errs);
        return errs;
    }, page);

    await R.step('Assign QA Role to QA User One via the user edit form', async () => {
        await visit(page, `/users/${userId}/edit`);
        await page.locator('label').filter({ hasText: new RegExp('^' + ROLE) }).locator('input[type=checkbox]').check();
        await submitAndLeave(page, /\/edit$/, 15000);
        await expectUrl(page, /\/users(\?|$)/);
        const roles = sql(`select string_agg(r.name, ',' order by r.name) from model_has_roles m join roles r on r.id=m.role_id where m.model_id=${userId}`)[0][0];
        if (!roles.includes(ROLE)) throw new Error('roles=' + roles);
        const audit = sql(`select old_values::text, new_values::text from audit_logs where event_type='permission_changed' and auditable_id='${userId}' order by created_at desc limit 1`)[0];
        return `roles=${roles} audit=${audit ? audit.join(' → ') : 'MISSING'}`;
    }, page);

    await R.step('Permission takes effect: QA user now reaches /code-rules (200); /audit-logs needs audit.view not audit-logs.viewAny', async () => {
        const a = (await up.goto(`${BASE}/code-rules`, { waitUntil: 'networkidle' })).status();
        const b = (await up.goto(`${BASE}/audit-logs`, { waitUntil: 'networkidle' })).status();
        await shot(up, '40-roles-user-code-rules');
        if (a !== 200) throw new Error(`code-rules=${a} audit-logs=${b}`);
        const nav = (await up.locator('nav a, aside a').allInnerTexts()).map((s) => s.trim());
        return `code-rules=${a} audit-logs=${b} nav has Audit=${nav.some((n) => /audit/i.test(n))} Code Rules=${nav.some((n) => /code rule/i.test(n))}`;
    }, up);

    await R.step('Edit QA Role: remove code-rules.viewAny → QA user gets 403 on /code-rules', async () => {
        await visit(page, `/roles/${st.roleId}/edit`);
        const lab = page.locator('label').filter({ has: page.locator('span.font-mono', { hasText: /^code-rules\.viewAny$/ }) }).first();
        await lab.locator('input[type=checkbox]').uncheck();
        await submitAndLeave(page, /\/edit$/, 15000);
        await expectUrl(page, /\/roles(\?|$)/);
        const perms = sql(`select string_agg(p.name, ',' order by p.name) from role_has_permissions rp join permissions p on p.id=rp.permission_id where rp.role_id=${st.roleId}`)[0][0];
        const a = (await up.goto(`${BASE}/code-rules`, { waitUntil: 'networkidle' })).status();
        const b = (await up.goto(`${BASE}/audit-logs`, { waitUntil: 'networkidle' })).status();
        if (a !== 403) throw new Error(`perms=${perms} code-rules=${a} audit-logs=${b}`);
        return `perms=${perms} code-rules=${a} audit-logs=${b}`;
    }, page);

    await R.step('Super Admin role: GET /roles/{id}/edit → 403, PATCH → 403, DELETE → 403 for a System Admin', async () => {
        const sa = sql(`select id from roles where name='Super Admin'`)[0][0];
        const before = sql(`select count(*) from role_has_permissions where role_id=${sa}`)[0][0];
        const g = (await page.goto(`${BASE}/roles/${sa}/edit`, { waitUntil: 'networkidle' })).status();
        const p = await apiCall(page, 'PATCH', `/roles/${sa}`, { name: 'Super Admin', scope_type: 'global', permissions: ['users.viewAny'] });
        const d = await apiCall(page, 'DELETE', `/roles/${sa}`);
        const after = sql(`select count(*) from role_has_permissions where role_id=${sa}`)[0][0];
        const exists = sql(`select count(*) from roles where id=${sa}`)[0][0];
        await shot(page, '40-roles-superadmin-edit');
        if (after !== before || exists !== '1') throw new Error(`SUPER ADMIN ROLE CHANGED perms ${before}→${after} exists=${exists}`);
        if (g !== 403 || p.status !== 403 || d.status !== 403) throw new Error(`GET=${g} PATCH=${p.status} DELETE=${d.status}`);
        return `GET=${g} PATCH=${p.status} DELETE=${d.status}; perms ${before} unchanged`;
    }, page);

    await R.step('System Admin role (protected) is also not editable/deletable by a System Admin', async () => {
        const id = sql(`select id from roles where name='System Admin'`)[0][0];
        const g = (await page.goto(`${BASE}/roles/${id}/edit`, { waitUntil: 'networkidle' })).status();
        const d = await apiCall(page, 'DELETE', `/roles/${id}`);
        return `GET edit=${g} DELETE=${d.status}`;
    }, page);

    await R.step('Deactivated user (no forced password change) cannot use the app: login attempt after deactivation', async () => {
        // Deactivate QA User One (via the list), then try to log in in a fresh context.
        await visit(page, `/users?search=${encodeURIComponent(NEW_EMAIL)}`);
        await rowAction(page, NEW_EMAIL, /deactivate/i);
        await confirmDialog(page, /\/deactivate$/);
        const status = sql(`select status, must_change_password from users where id=${userId}`)[0];
        // Existing session of the deactivated user: is it cut off?
        const existing = await up.goto(`${BASE}/dashboard`, { waitUntil: 'networkidle' });
        const existingInfo = `existing session → HTTP ${existing.status()} at ${up.url().replace(BASE, '')}`;
        const c = await browser.newContext();
        const p = await newPageIn(c);
        await p.goto(`${BASE}/login`, { waitUntil: 'networkidle' });
        await p.fill('input[name="email"]', NEW_EMAIL);
        await p.fill('input[name="password"]', userPw);
        await submitAndLeave(p, /\/login$/, 10000);
        const landed = p.url().replace(BASE, '');
        const t = await body(p);
        await shot(p, '40-roles-inactive-login');
        // Can they reach a page?
        const r2 = await p.goto(`${BASE}/employees`, { waitUntil: 'networkidle' });
        const after = `${r2.status()} at ${p.url().replace(BASE, '')}`;
        await c.close();
        // reactivate
        await visit(page, `/users?search=${encodeURIComponent(NEW_EMAIL)}`);
        await rowAction(page, NEW_EMAIL, /reactivate|restore|activate/i);
        await confirmDialog(page, /\/restore$/);
        const back = sql(`select status from users where id=${userId}`)[0][0];
        const info = `status=${status.join('/')} ${existingInfo}; fresh login landed=${landed} msg=${(t.match(/(deactivated[^.]*\.|inactive[^.]*\.|credentials[^.]*\.)/i) || [])[1] || 'none'}; then /employees → ${after}; reactivated=${back}`;
        if (!/\/login/.test(landed) || !/\/login/.test(after) || !/\/login/.test(existingInfo)) throw new Error('inactive account can sign in: ' + info);
        return info;
    }, page);

    fs.writeFileSync(STATE_FILE, JSON.stringify(st, null, 2));
    UR.save();
    await browser.close();
    R.save();
})().catch((e) => { console.error('FATAL', e); process.exit(1); });
