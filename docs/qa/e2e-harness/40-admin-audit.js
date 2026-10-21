'use strict';
// Journey 5 + 6: Audit logs (actor/IP/before-after/PII) and Recycle bin (delete QA role; users/roles not recyclable).
const fs = require('fs');
const path = require('path');
const { BASE, Recorder, newBrowser, newPageIn, login, visit, shot } = require('./lib');
const { sql, body, rowAction, confirmDialog, apiCall } = require('./40-admin-lib');

const ADMIN = 'qa.sysadmin5@demo.local';
const NEW_EMAIL = 'qa.user.one@demo.local';
const ROLE = 'QA Role';
const PASSWORDS = ['Violet-Harbor-7731!', 'Copper-Lantern-5519!', 'password123', 'QaUserOne#2026!!'];

(async () => {
    const R = new Recorder('40-admin-audit');
    const { browser, page } = await newBrowser();
    R.attach(page);
    const userId = sql(`select id from users where email='${NEW_EMAIL}'`)[0][0];
    const adminId = sql(`select id from users where email='${ADMIN}'`)[0][0];

    await R.step('System Admin logs in; /audit-logs renders with filters and rows', async () => {
        await login(page, ADMIN, 'password');
        await visit(page, '/audit-logs');
        const t = await body(page);
        if (!/event|actor/i.test(t)) throw new Error('audit page text unexpected');
        await shot(page, '40-audit-index');
    }, page);

    await R.step('Audit UI: filter event_type=user_created shows the QA User One creation by the System Admin', async () => {
        await visit(page, `/audit-logs?event_type=user_created&search=${userId}`);
        const t = await body(page);
        await shot(page, '40-audit-user-created');
        if (!/user_created/i.test(t)) throw new Error('no user_created row shown');
        const hasActor = /QA sysadmin5/.test(t);
        const hasUser = new RegExp(`User.*${userId}|${userId}`).test(t);
        return `actor shown=${hasActor} auditable shown=${hasUser} new-values visible=${/QA User One|qa\.user\.one/.test(t)}`;
    }, page);

    await R.step('DB: user_created / permission_changed / user_updated / user_deactivated rows record actor, before/after; report IP + UA', async () => {
        const rows = sql(`select event_type, actor_user_id, coalesce(request_ip,'NULL'), coalesce(left(user_agent,20),'NULL'), coalesce(left(old_values::text,150),''), coalesce(left(new_values::text,200),'') from audit_logs where auditable_id='${userId}' and auditable_type like '%User' order by created_at`);
        if (!rows.length) throw new Error('no audit rows for user ' + userId);
        const types = [...new Set(rows.map((r) => r[0]))];
        const noIp = rows.filter((r) => r[2] === 'NULL').map((r) => r[0]);
        const wrongActor = rows.filter((r) => r[1] !== adminId && !['user_logged_in', 'user_password_changed', 'profile_updated', 'user_logged_out'].includes(r[0]));
        const created = rows.find((r) => r[0] === 'user_created');
        const updated = rows.find((r) => r[0] === 'user_updated');
        const info = `rows=${rows.length} types=[${types.join(',')}] actor_ok=${wrongActor.length === 0} user_created.new=${created?.[5]} user_updated.old=${updated?.[4]} new=${updated?.[5]} NO_IP_for=[${[...new Set(noIp)].join(',')}]`;
        if (!created) throw new Error('user_created missing: ' + info);
        return info;
    }, page);

    await R.step('DB: role_created / role_updated / setting_updated rows exist with before/after values', async () => {
        const role = sql(`select event_type, actor_user_id, coalesce(request_ip,'NULL'), left(old_values::text,160), left(new_values::text,160) from audit_logs where event_type in ('role_created','role_updated','role_deleted') and (new_values::text like '%${ROLE}%' or old_values::text like '%${ROLE}%') order by created_at`);
        const setting = sql(`select event_type, actor_user_id, coalesce(request_ip,'NULL'), left(old_values::text,160), left(new_values::text,160), coalesce(reason,'') from audit_logs where event_type like 'setting%' and (new_values::text like '%application_short_name%' or old_values::text like '%application_short_name%' or reason like '%application_short_name%') order by created_at desc limit 2`);
        if (!role.length) throw new Error('no role audit rows');
        if (!setting.length) throw new Error('no setting_updated audit rows for application_short_name');
        return `role: ${role.map((r) => r.join(' | ')).join(' ;; ')} || setting: ${setting.map((r) => r.join(' | ')).join(' ;; ')}`;
    }, page);

    await R.step('PII: national ID, phone and every password used are NOT stored in clear text in audit_logs', async () => {
        const nids = ['1234567890123456', '6543210987654321', '911223344'];
        const hits = [];
        for (const v of [...nids, ...PASSWORDS]) {
            const n = sql(`select count(*) from audit_logs where old_values::text like '%${v}%' or new_values::text like '%${v}%' or coalesce(reason,'') like '%${v}%'`)[0][0];
            if (n !== '0') hits.push(`${v}:${n}`);
        }
        const redacted = sql(`select count(*) from audit_logs where auditable_id='${userId}' and (old_values::text like '%[REDACTED]%' or new_values::text like '%[REDACTED]%')`)[0][0];
        const nidKeys = sql(`select count(*) from audit_logs where new_values::text like '%national_id"%' and new_values::text not like '%national_id_configured%' and new_values::text not like '%[REDACTED]%'`)[0][0];
        if (hits.length) throw new Error('PII in clear text: ' + hits.join(', '));
        return `no clear-text hits; rows with [REDACTED] for this user=${redacted}; unredacted national_id keys anywhere=${nidKeys}`;
    }, page);

    await R.step('Auditor can open /audit-logs; Report Viewer cannot (403)', async () => {
        const out = [];
        for (const e of ['qa.auditor@demo.local', 'qa.reportviewer@demo.local']) {
            const c = await browser.newContext();
            const p = await newPageIn(c);
            await login(p, e, 'password');
            out.push(`${e.split('@')[0]}=${(await p.goto(`${BASE}/audit-logs`, { waitUntil: 'networkidle' })).status()}`);
            await c.close();
        }
        if (!/auditor=200/.test(out[0]) || !/=403/.test(out[1])) throw new Error(out.join(' '));
        return out.join(' ');
    });

    await R.step('Recycle bin renders; lists supported record types (expect no users/roles type)', async () => {
        await visit(page, '/recycle-bin');
        const opts = (await page.locator('select option').allInnerTexts()).map((s) => s.trim());
        await shot(page, '40-recycle-index');
        return `types=[${opts.join('|')}]`;
    }, page);

    await R.step('Delete QA Role from roles list → role gone from DB; not restorable from recycle bin (hard delete)', async () => {
        const id = sql(`select id from roles where name='${ROLE}'`)[0]?.[0];
        if (!id) throw new Error('QA Role missing (roles script not run?)');
        await visit(page, `/roles?search=${encodeURIComponent(ROLE)}`);
        await rowAction(page, ROLE, /delete/i);
        await confirmDialog(page, /\/roles\/\d+$/);
        const left = sql(`select count(*) from roles where id=${id}`)[0][0];
        const userRoles = sql(`select string_agg(r.name, ',') from model_has_roles m join roles r on r.id=m.role_id where m.model_id=${userId}`)[0]?.[0] || '';
        const orphan = sql(`select count(*) from model_has_roles where role_id=${id}`)[0][0];
        const audit = sql(`select count(*) from audit_logs where event_type='role_deleted' and old_values::text like '%${ROLE}%'`)[0][0];
        await visit(page, `/recycle-bin?search=${encodeURIComponent(ROLE)}`);
        const t = await body(page);
        const inBin = new RegExp(ROLE).test(t);
        if (left !== '0') throw new Error('role still exists');
        return `deleted (audit role_deleted rows=${audit}); orphan model_has_roles rows=${orphan}; user roles now=[${userRoles}]; in recycle bin=${inBin}`;
    }, page);

    await R.step('Recycle bin restore for an unsupported type (roles/users) is refused, not 500', async () => {
        const a = await apiCall(page, 'POST', `/recycle-bin/roles/1/restore`, {});
        const b = await apiCall(page, 'POST', `/recycle-bin/users/${userId}/restore`, {});
        if (a.status >= 500 || b.status >= 500) throw new Error(`roles=${a.status} users=${b.status}`);
        return `roles→${a.status} users→${b.status}`;
    }, page);

    await browser.close();
    R.save();
})().catch((e) => { console.error('FATAL', e); process.exit(1); });
