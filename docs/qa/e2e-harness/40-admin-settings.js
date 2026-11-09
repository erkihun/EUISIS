'use strict';
// Journey 4: System Settings — every tab/route renders; change+revert a label; max_login_attempts validation; MFA role list.
const fs = require('fs');
const path = require('path');
const { BASE, Recorder, newBrowser, login, visit, shot } = require('./lib');
const { sql, body, captureInertia, timedVisit } = require('./40-admin-lib');

const ADMIN = 'qa.sysadmin5@demo.local';
const TABS = ['general', 'localization', 'appearance', 'id_cards', 'notifications', 'email', 'sms', 'telegram', 'security', 'field_work_gps', 'situation_room', 'intelligence', 'assistant'];
const GET_ROUTES = [
    '/system-settings', '/system-settings/api-management', '/system-settings/api-management/docs', '/system-settings/api-management/endpoints',
    '/system-settings/api-management/logs', '/system-settings/id-card-templates', '/system-settings/integrations', '/system-settings/integrations/subscriptions/create',
];

async function saveSection(page, routeRe) {
    const cap = captureInertia(page, /\/system-settings/);
    const resp = page.waitForResponse((r) => routeRe.test(r.url()) && r.request().method() === 'POST', { timeout: 20000 }).catch(() => null);
    await page.getByRole('button', { name: /save changes/i }).first().click();
    const r = await resp;
    await page.waitForLoadState('networkidle').catch(() => {});
    await page.waitForTimeout(500);
    cap.stop();
    return { status: r ? r.status() : null, errors: cap.got.map((g) => g.errors).filter((e) => e && Object.keys(e).length), flash: cap.got.map((g) => g.flash).filter(Boolean) };
}

(async () => {
    const R = new Recorder('40-admin-settings');
    const { browser, page } = await newBrowser();
    R.attach(page);
    const perPage = [];

    await R.step('System Admin logs in', async () => { await login(page, ADMIN, 'password'); }, page);

    await R.step('Every settings tab renders (13 sections) — status, load time, console errors', async () => {
        const out = [];
        for (const tab of TABS) {
            const before = R.consoleErrors.length + R.pageErrors.length;
            const v = await timedVisit(page, `/system-settings?tab=${tab}`);
            const heading = await page.locator('main h2, h2').first().innerText().catch(() => '');
            const t = await body(page);
            const errs = R.consoleErrors.length + R.pageErrors.length - before;
            const bad = /Whoops|Stack trace|SQLSTATE/.test(t);
            out.push(`${tab}:${v.status}/${v.ms}ms/h2="${heading.slice(0, 30)}"${errs ? '/console=' + errs : ''}${bad ? '/DEBUG' : ''}`);
            perPage.push({ page: `/system-settings?tab=${tab}`, status: v.status, ms: v.ms, consoleErrors: errs });
        }
        await shot(page, '40-settings-last-tab');
        const failing = out.filter((o) => !/:200\//.test(o) || /console=|DEBUG/.test(o));
        if (failing.length) throw new Error('problems: ' + failing.join(' ; ') + ' || all: ' + out.join(' ; '));
        return out.join(' ; ');
    }, page);

    await R.step('Settings sub-module GET routes render (api-management, id-card-templates, integrations)', async () => {
        const out = [];
        for (const u of GET_ROUTES) {
            const before = R.consoleErrors.length + R.pageErrors.length;
            const v = await timedVisit(page, u);
            const errs = R.consoleErrors.length + R.pageErrors.length - before;
            out.push(`${u}:${v.status}/${v.ms}ms${errs ? '/console=' + errs : ''}${v.url.replace(BASE, '') !== u ? '→' + v.url.replace(BASE, '') : ''}`);
            perPage.push({ page: u, status: v.status, ms: v.ms, consoleErrors: errs });
        }
        const failing = out.filter((o) => !/:200\//.test(o) || /console=/.test(o));
        if (failing.length) throw new Error('problems: ' + failing.join(' ; ') + ' || all: ' + out.join(' ; '));
        return out.join(' ; ');
    }, page);

    await R.step('Parameterised settings routes: endpoint/application/subscription/template ids that exist (or 404 for a bogus id, never 500)', async () => {
        const ids = {
            endpoint: sql(`select id from api_endpoint_definitions limit 1`)[0]?.[0],
            app: sql(`select id from external_applications limit 1`)[0]?.[0],
            sub: sql(`select id from webhook_subscriptions limit 1`)[0]?.[0],
            tpl: sql(`select id from id_card_templates limit 1`)[0]?.[0],
        };
        const bogus = '00000000-0000-0000-0000-000000000000';
        const urls = [
            `/system-settings/api-management/endpoints/${ids.endpoint || bogus}`,
            `/system-settings/api-management/${ids.app || bogus}`,
            `/system-settings/integrations/subscriptions/${ids.sub || bogus}`,
            `/system-settings/integrations/subscriptions/${ids.sub || bogus}/edit`,
            `/system-settings/integrations/events/${bogus}`,
            `/system-settings/id-card-templates/${ids.tpl || bogus}/background/front`,
            `/system-settings/api-management/endpoints/not-a-uuid`,
        ];
        const out = [];
        for (const u of urls) {
            const resp = await page.goto(`${BASE}${u}`, { waitUntil: 'networkidle' });
            out.push(`${u.replace(/[0-9a-f-]{36}/, '<id>')}:${resp.status()}`);
        }
        const five = out.filter((o) => /:5\d\d$/.test(o));
        if (five.length) throw new Error('5xx: ' + five.join(' ; ') + ' || ' + out.join(' ; '));
        return out.join(' ; ');
    }, page);

    await R.step('General tab: change "Official Short Name" and save → DB updated + audit row; then revert → DB restored', async () => {
        const orig = sql(`select value from system_settings where "group"='general' and key='application_short_name'`)[0][0];
        await visit(page, '/system-settings?tab=general');
        const input = page.getByLabel(/official short name/i).first();
        await input.fill(orig + '-QA');
        const r1 = await saveSection(page, /system-settings\/general/);
        const v1 = sql(`select value from system_settings where "group"='general' and key='application_short_name'`)[0][0];
        await shot(page, '40-settings-general-changed');
        if (v1 !== orig + '-QA') throw new Error(`save failed: POST=${r1.status} errors=${JSON.stringify(r1.errors)} db=${v1}`);
        const audit = sql(`select event_type, actor_user_id, coalesce(request_ip,'NULL'), left(old_values::text,120), left(new_values::text,120) from audit_logs where event_type like 'setting%' order by created_at desc limit 1`)[0];
        // revert
        await visit(page, '/system-settings?tab=general');
        await page.getByLabel(/official short name/i).first().fill(orig);
        const r2 = await saveSection(page, /system-settings\/general/);
        const v2 = sql(`select value from system_settings where "group"='general' and key='application_short_name'`)[0][0];
        if (v2 !== orig) throw new Error(`REVERT FAILED: db=${v2} (orig ${orig}) POST=${r2.status}`);
        return `saved "${v1}" (POST ${r1.status}, flash=${JSON.stringify(r1.flash).slice(0, 80)}) audit=${audit ? audit.join(' | ') : 'MISSING'}; reverted to "${v2}"`;
    }, page);

    await R.step('Security tab: max_login_attempts=0 and =999 are refused with validation errors; DB value unchanged', async () => {
        const orig = sql(`select value from system_settings where "group"='security' and key='max_login_attempts'`)[0][0];
        const results = [];
        for (const v of ['0', '999']) {
            await visit(page, '/system-settings?tab=security');
            const input = page.getByLabel(/max login attempts/i).first();
            // The number input carries HTML min/max; strip them so the SERVER rule is exercised.
            await input.evaluate((el) => { el.removeAttribute('min'); el.removeAttribute('max'); });
            await input.fill(v);
            const r = await saveSection(page, /system-settings\/security/);
            const visible = (await page.locator('[role="alert"], p[class*="text-red"], [id$="-error"]').allInnerTexts()).map((s) => s.trim()).filter(Boolean).join(' | ');
            const db = sql(`select value from system_settings where "group"='security' and key='max_login_attempts'`)[0][0];
            results.push(`${v}: POST=${r.status} server_errors=${JSON.stringify(r.errors)} visible="${visible.slice(0, 160)}" db=${db}`);
            if (db !== orig) { sql(`update system_settings set value='${orig}' where "group"='security' and key='max_login_attempts'`); throw new Error('VALUE WAS SAVED: ' + results.join(' ; ')); }
        }
        await shot(page, '40-settings-security-validation');
        if (!results.every((r) => /max_login_attempts|POST=\d+/.test(r))) throw new Error('no validation error: ' + results.join(' ; '));
        return results.join(' ; ');
    }, page);

    await R.step('Security tab: max_login_attempts=30 (registry says max 20, request rule says max 50) — report, revert if saved', async () => {
        const orig = sql(`select value from system_settings where "group"='security' and key='max_login_attempts'`)[0][0];
        await visit(page, '/system-settings?tab=security');
        const input = page.getByLabel(/max login attempts/i).first();
        const attrs = await input.evaluate((el) => ({ min: el.min, max: el.max }));
        await input.evaluate((el) => { el.removeAttribute('min'); el.removeAttribute('max'); });
        await input.fill('30');
        const r = await saveSection(page, /system-settings\/security/);
        const db = sql(`select value from system_settings where "group"='security' and key='max_login_attempts'`)[0][0];
        let note = '';
        if (db !== orig) {
            await visit(page, '/system-settings?tab=security');
            await page.getByLabel(/max login attempts/i).first().fill(orig);
            const r2 = await saveSection(page, /system-settings\/security/);
            const db2 = sql(`select value from system_settings where "group"='security' and key='max_login_attempts'`)[0][0];
            if (db2 !== orig) { sql(`update system_settings set value='${orig}' where "group"='security' and key='max_login_attempts'`); }
            note = `; ACCEPTED then reverted via UI (POST ${r2.status}) → db=${db2}`;
        }
        return `input min=${attrs.min} max=${attrs.max}; 30 → POST=${r.status} errors=${JSON.stringify(r.errors)} db_after=${db}${note}`;
    }, page);

    await R.step('MFA settings: which roles are required; is "System Admin" listed/required/addable', async () => {
        await visit(page, '/system-settings?tab=security');
        const mfaEnabled = await page.getByLabel(/^enable mfa|enable multi/i).first().isChecked().catch(() => null);
        const group = page.locator('[role="group"][aria-labelledby="mfa-roles-label"]');
        const labels = group.locator('label');
        const n = await labels.count();
        const rows = [];
        for (let i = 0; i < n; i++) {
            const l = labels.nth(i);
            const name = (await l.locator('span span').first().innerText()).trim();
            const cb = l.locator('input[type=checkbox]');
            rows.push({ name, checked: await cb.isChecked(), disabled: await cb.isDisabled() });
        }
        const checked = rows.filter((r) => r.checked).map((r) => r.name);
        const sa = rows.find((r) => r.name === 'System Admin');
        const dbIds = sql(`select value from system_settings where "group"='security' and key='mfa_required_role_ids'`)[0][0];
        const allRequired = sql(`select value from system_settings where "group"='security' and key='mfa_required_for_all'`)[0][0];
        const envBaseline = (fs.readFileSync('/home/user/EUISIS/.env', 'utf8').match(/^MFA_REQUIRED_ROLES=(.*)$/m) || [])[1] || '(unset → default Super Admin,City Admin)';
        await shot(page, '40-settings-mfa');
        // Toggle System Admin on without saving, to prove it is addable, then discard
        let addable = null;
        if (sa && !sa.disabled) {
            await labels.nth(rows.indexOf(sa)).locator('input[type=checkbox]').check();
            addable = await page.getByRole('button', { name: /save changes/i }).first().isEnabled();
            await page.getByRole('button', { name: /discard/i }).first().click().catch(() => {});
        }
        return `mfa_enabled=${mfaEnabled} required_for_all=${allRequired} ui_checked=[${checked.join(',')}] db_role_ids=${dbIds} env_MFA_REQUIRED_ROLES=${envBaseline} roles_listed=${n} SystemAdmin={listed:${!!sa},checked:${sa?.checked},disabled:${sa?.disabled},addable_save_enabled:${addable}}`;
    }, page);

    await R.step('Report Viewer and Auditor cannot open /system-settings (403)', async () => {
        const out = [];
        for (const e of ['qa.reportviewer@demo.local', 'qa.auditor@demo.local']) {
            const c = await browser.newContext();
            const p = await (require('./lib').newPageIn)(c);
            await login(p, e, 'password');
            const s1 = (await p.goto(`${BASE}/system-settings`, { waitUntil: 'networkidle' })).status();
            const s2 = (await p.goto(`${BASE}/system-settings/api-management`, { waitUntil: 'networkidle' })).status();
            const s3 = (await p.goto(`${BASE}/users`, { waitUntil: 'networkidle' })).status();
            out.push(`${e.split('@')[0]}: settings=${s1} api-mgmt=${s2} users=${s3}`);
            await c.close();
        }
        if (out.some((o) => /=200/.test(o))) throw new Error(out.join(' ; '));
        return out.join(' ; ');
    });

    fs.writeFileSync(path.join(__dirname, 'results', '40-admin-settings-pages.json'), JSON.stringify(perPage, null, 2));
    await browser.close();
    R.save();
})().catch((e) => { console.error('FATAL', e); process.exit(1); });
