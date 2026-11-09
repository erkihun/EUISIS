'use strict';
// Journey 8 (+6): Code rules — list, show, preview, archive a rule in use, restore it from the recycle bin.
const { BASE, Recorder, newBrowser, login, visit, shot } = require('./lib');
const { sql, body, confirmDialog, apiCall } = require('./40-admin-lib');

const ADMIN = 'qa.sysadmin5@demo.local';
const RULE = 'Public Tip-off Reference';

(async () => {
    const R = new Recorder('40-admin-coderules');
    const { browser, page } = await newBrowser();
    R.attach(page);
    const rule = sql(`select id, entity_type, is_active, coalesce(deleted_at::text,'') from code_rules where name_en='${RULE}'`)[0];
    if (rule[3]) sql(`update code_rules set deleted_at=null, is_active=true where id='${rule[0]}'`);
    const ruleId = rule[0];

    await R.step('System Admin logs in; /code-rules lists rules with preview codes', async () => {
        await login(page, ADMIN, 'password');
        await visit(page, '/code-rules');
        const t = await body(page);
        const n = sql(`select count(*) from code_rules where deleted_at is null`)[0][0];
        await shot(page, '40-coderules-index');
        if (!t.includes(RULE)) throw new Error('rule not listed');
        return `rules in DB=${n}; list shows "${RULE}" preview visible=${/Preview/i.test(t)}`;
    }, page);

    await R.step('Show page renders the rule with a preview code; preview endpoint returns a code', async () => {
        await visit(page, `/code-rules/${ruleId}`);
        const t = await body(page);
        await shot(page, '40-coderules-show');
        const prev = await apiCall(page, 'POST', '/code-rules/preview-code', { entity_type: rule[1], context: {} });
        return `show ok; body has preview=${/preview/i.test(t)}; preview-code API → ${prev.status} ${prev.text.slice(0, 160)}`;
    }, page);

    await R.step('Archive a rule in use (in-use check?) → observe behaviour; DB deleted_at/is_active', async () => {
        await visit(page, `/code-rules/${ruleId}`);
        const btn = page.getByRole('button', { name: /archive|delete rule/i }).first();
        const resp = page.waitForResponse((r) => /\/archive$/.test(r.url()), { timeout: 15000 }).catch(() => null);
        await btn.click();
        // a confirm dialog may or may not appear
        await confirmDialog(page, /\/archive$/).catch(() => {});
        await resp;
        await page.waitForLoadState('networkidle');
        await page.waitForTimeout(500);
        const row = sql(`select is_active, coalesce(deleted_at::text,'NULL'), coalesce(deletion_reason,'') from code_rules where id='${ruleId}'`)[0];
        const t = await body(page);
        await shot(page, '40-coderules-archived');
        const info = `url=${page.url().replace(BASE, '')} is_active=${row[0]} deleted_at=${row[1]} reason="${row[2]}" flash=${(t.match(/(archived[^.]*\.|deleted[^.]*\.|cannot[^.]*\.|in use[^.]*\.)/i) || [])[1] || 'none'}`;
        if (row[1] === 'NULL') throw new Error('rule not archived: ' + info);
        return info;
    }, page);

    await R.step('Archived rule: preview-code for its entity type now reports no active rule', async () => {
        const prev = await apiCall(page, 'POST', '/code-rules/preview-code', { entity_type: rule[1], context: {} });
        return `preview-code → ${prev.status} ${prev.text.slice(0, 160)}`;
    }, page);

    await R.step('Recycle bin lists the archived rule under code_rules and restores it', async () => {
        await visit(page, `/recycle-bin?type=code_rules`);
        const t = await body(page);
        if (!t.includes(RULE)) throw new Error('archived rule not listed in recycle bin');
        const row = page.locator('tr').filter({ hasText: RULE }).first();
        const resp = page.waitForResponse((r) => /\/restore$/.test(r.url()), { timeout: 15000 }).catch(() => null);
        await row.getByRole('button', { name: /restore/i }).first().click();
        await confirmDialog(page, /\/restore$/);
        await resp;
        const after = sql(`select is_active, coalesce(deleted_at::text,'NULL') from code_rules where id='${ruleId}'`)[0];
        await shot(page, '40-coderules-restored');
        if (after[1] !== 'NULL') throw new Error('not restored: ' + after.join('/'));
        const audit = sql(`select string_agg(event_type, ',' order by created_at) from audit_logs where auditable_id='${ruleId}' and created_at > now() - interval '10 minutes'`)[0][0];
        return `restored is_active=${after[0]} deleted_at=${after[1]}; audit=${audit}`;
    }, page);

    await R.step('Code-rules create/edit pages render', async () => {
        const a = (await page.goto(`${BASE}/code-rules/create`, { waitUntil: 'networkidle' })).status();
        const b = (await page.goto(`${BASE}/code-rules/${ruleId}/edit`, { waitUntil: 'networkidle' })).status();
        const c = (await page.goto(`${BASE}/code-rules/${ruleId}/sequences`, { waitUntil: 'networkidle' })).status();
        if (a !== 200 || b !== 200) throw new Error(`create=${a} edit=${b} sequences=${c}`);
        return `create=${a} edit=${b} sequences=${c}`;
    }, page);

    // safety: make sure the rule is active again
    sql(`update code_rules set deleted_at=null, is_active=true where id='${ruleId}' and (deleted_at is not null or is_active=false)`);
    await browser.close();
    R.save();
})().catch((e) => { console.error('FATAL', e); process.exit(1); });
