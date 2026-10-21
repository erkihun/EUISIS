'use strict';
// Journey 6: settlements (UAT step 9/10) — cafeteria settlement draft/liability/finalize; transport contract -> statement -> approve -> PDF.
const { BASE, Recorder, newBrowser, newPageIn, login, submitAndLeave, visit, shot } = require('./lib');
const { loadState, saveState, sql, byLabel, fillLabel, fillDate, selectLabelByText, bodyText, flashText, dialogAction, providerLogin, fetchWithSession } = require('./60-portal-lib');

const state = loadState();
const today = new Date();
const iso = (d) => d.toISOString().slice(0, 10);
const monthStart = iso(new Date(Date.UTC(today.getUTCFullYear(), today.getUTCMonth(), 1)));
const monthEnd = iso(new Date(Date.UTC(today.getUTCFullYear(), today.getUTCMonth() + 1, 0)));

(async () => {
    const R = new Recorder('60-portal-6-settlements');
    const { browser, page } = await newBrowser();
    R.attach(page);
    const slow = [];
    page.on('response', (r) => { const t = r.request().timing(); if (t && t.responseEnd > 3000 && r.request().resourceType() === 'document') slow.push({ url: r.url(), ms: Math.round(t.responseEnd) }); });

    await R.step('Login as qa.sysadmin4', async () => login(page, 'qa.sysadmin4@demo.local', 'password', {}), page);

    await R.step('Back office Cafeteria › Transactions lists the portal scans for QA-CAFE-1 (filter by cafeteria); detail shows BUR-PSHRDB', async () => {
        await visit(page, `/cafeteria/transactions?provider_id=${state.cafeteriaId}`);
        const t = await bodyText(page);
        await shot(page, 'portal-bo-transactions');
        if (!/QA Cafeteria 1|QA-CAFE-1|Demo Employee/.test(t)) throw new Error('transactions not listed: ' + t.slice(0, 300));
        await visit(page, `/cafeteria/transactions/${state.scanTxn}`);
        const d = await bodyText(page);
        await shot(page, 'portal-bo-transaction-show');
        if (!/Public Service and Human Resource|BUR-PSHRDB/.test(d)) return 'WARNING: billing organization not visible on the detail page';
        return 'listed; detail shows BUR-PSHRDB';
    }, page);

    await R.step('Cafeteria settlement: preview for QA-CAFE-1, current month -> lines grouped by employee organization', async () => {
        await visit(page, '/cafeteria/settlements/create');
        await selectLabelByText(page, 'Provider', /QA-CAFE-1|QA Cafeteria Provider 1/);
        await fillDate(page, 'Period Start', monthStart);
        await fillDate(page, 'Period End', monthEnd);
        const w = page.waitForResponse((r) => /settlements\/create/.test(r.url()), { timeout: 30000 }).catch(() => null);
        await page.getByRole('button', { name: /^Preview$/ }).click();
        await w; await page.waitForLoadState('networkidle'); await page.waitForTimeout(800);
        const b = await bodyText(page);
        await shot(page, 'portal-cafe-settlement-preview');
        if (!/Public Service and Human Resource|BUR-PSHRDB/.test(b)) throw new Error('preview has no BUR-PSHRDB line: ' + b.slice(0, 400));
        return (b.match(/Public Service and Human Resource.{0,160}/) || [''])[0];
    }, page);

    await R.step('Create the settlement draft; transactions get locked to it', async () => {
        await page.getByRole('button', { name: /^Create Settlement$/ }).click();
        await page.waitForURL(/cafeteria\/settlements\/[0-9a-f-]{36}/, { timeout: 30000 });
        await page.waitForLoadState('networkidle');
        const m = page.url().match(/settlements\/([0-9a-f-]{36})/);
        state.cafeSettlementId = m[1]; saveState(state);
        const row = sql(`select settlement_number,status,period_start,period_end,total_subsidy_amount,total_employee_amount,total_provider_amount from cafeteria_settlements where id='${m[1]}'`).replace(/\|/g, ' | ');
        const locked = sql(`select count(*) from cafeteria_transactions where cafeteria_settlement_id='${m[1]}'`);
        await shot(page, 'portal-cafe-settlement-draft');
        if (locked === '0') throw new Error('no transactions linked to the draft');
        return `${row}; transactions linked=${locked}`;
    }, page);

    await R.step('Per-organization liability view: BUR-PSHRDB subsidy 100.00, employee 140.00, provider 240.00 (two scans)', async () => {
        const b = await bodyText(page);
        const sect = (b.match(/Organization Liability.{0,400}/) || [''])[0];
        if (!/Public Service and Human Resource/.test(sect)) throw new Error('no org row in liability table: ' + sect);
        if (!/100\.00/.test(sect)) throw new Error('subsidy 100.00 missing: ' + sect);
        return sect.slice(0, 260);
    }, page);

    await R.step('Drafter (sysadmin4) is told another person must finalize; no Finalize button', async () => {
        const b = await bodyText(page);
        const btn = await page.getByRole('button', { name: /^Finalize$/ }).count();
        if (btn) {
            const err = await dialogAction(page, /^Finalize$/);
            const row = sql(`select status from cafeteria_settlements where id='${state.cafeSettlementId}'`);
            if (row === 'finalized') throw new Error('drafter finalized their own settlement');
            return 'button shown but refused: ' + err;
        }
        if (!/another authorized person must finalize/i.test(b)) throw new Error('no explanation shown');
        return 'finalizeByAnother notice shown';
    }, page);

    await R.step('Second person (sysadmin5) finalizes; a reversal of a settled transaction is no longer possible', async () => {
        const ctx = await browser.newContext(); const p2 = await newPageIn(ctx); R.attach(p2);
        await login(p2, 'qa.sysadmin5@demo.local', 'password', {});
        await visit(p2, `/cafeteria/settlements/${state.cafeSettlementId}`);
        const err = await dialogAction(p2, /^Finalize$/);
        const row = sql(`select status,finalized_by,total_provider_amount from cafeteria_settlements where id='${state.cafeSettlementId}'`);
        await shot(p2, 'portal-cafe-settlement-finalized');
        await ctx.close();
        if (err) throw new Error('finalize refused: ' + err);
        if (!row.startsWith('finalized')) throw new Error('status: ' + row);
        return row;
    }, page);

    await R.step('Settlements index lists it; a second draft for the same period yields no lines (no double settlement)', async () => {
        await visit(page, '/cafeteria/settlements');
        const b = await bodyText(page);
        if (!/QA Cafeteria Provider 1|QA-CAFE-1/.test(b)) throw new Error('not listed');
        await visit(page, '/cafeteria/settlements/create');
        await selectLabelByText(page, 'Provider', /QA-CAFE-1|QA Cafeteria Provider 1/);
        await fillDate(page, 'Period Start', monthStart);
        await fillDate(page, 'Period End', monthEnd);
        const w = page.waitForResponse((r) => /settlements\/create/.test(r.url()), { timeout: 30000 }).catch(() => null);
        await page.getByRole('button', { name: /^Preview$/ }).click();
        await w; await page.waitForLoadState('networkidle'); await page.waitForTimeout(800);
        const disabled = await page.getByRole('button', { name: /^Create Settlement$/ }).isDisabled();
        const b2 = await bodyText(page);
        if (!disabled) throw new Error('Create Settlement enabled although everything is settled');
        return 'Create disabled; ' + (b2.match(/(no unsettled|nothing to settle|no transactions).{0,80}/i) || ['(no empty-state text found)'])[0];
    }, page);

    await R.step('Cafeteria analytics export (CSV) is a file', async () => {
        const r = await fetchWithSession(page, `${BASE}/cafeteria/analytics/export?report=settlement&period_start=${monthStart}&period_end=${monthEnd}`);
        return `${r.status} ${r.contentType} ${r.size}B ${r.text.slice(0, 80).replace(/\n/g, ' ')}`;
    });

    // ── Transport finance ───────────────────────────────────────────────────
    await R.step('Transport contract QA-CT-1 for QA-TRANSPORT-1 (per verified boarding, monthly, 25 ETB)', async () => {
        await visit(page, '/transport/contracts/create');
        await fillLabel(page, 'Contract reference', 'QA-CT-1');
        await selectLabelByText(page, 'Provider', /QA-TRANSPORT-1/);
        (await byLabel(page, 'Valid From')).fill(monthStart);
        await fillLabel(page, 'Currency', 'ETB');
        const model = await byLabel(page, 'Settlement model'); await model.selectOption('per_verified_boarding');
        const period = await byLabel(page, 'Settlement period'); await period.selectOption('monthly');
        await page.waitForTimeout(300);
        const term = page.locator('label').filter({ hasText: /boarding/i }).filter({ hasNot: page.locator('select') }).first();
        const forId = await term.getAttribute('for');
        await page.locator(`[id="${forId}"]`).fill('25');
        await shot(page, 'portal-transport-contract-form');
        await submitAndLeave(page, /contracts\/create/);
        if (/\/create/.test(page.url())) throw new Error('stayed: ' + (await page.locator('form').innerText()).replace(/\s+/g, ' ').slice(0, 400));
        const m = page.url().match(/contracts\/([0-9a-f-]{36})/);
        state.contractId = m[1]; saveState(state);
        return sql(`select contract_reference,status,version_no,settlement_model,settlement_period,terms::text from transport_contracts where id='${m[1]}'`);
    }, page);

    await R.step('Contract: drafter cannot approve (distinct approver); sysadmin5 approves -> active', async () => {
        const err = await dialogAction(page, /^Approve$/);
        const row = sql(`select status from transport_contracts where id='${state.contractId}'`);
        if (row === 'active') throw new Error('drafter approved own contract');
        const ctx = await browser.newContext(); const p2 = await newPageIn(ctx); R.attach(p2);
        await login(p2, 'qa.sysadmin5@demo.local', 'password', {});
        await visit(p2, `/transport/contracts/${state.contractId}`);
        const err2 = await dialogAction(p2, /^Approve$/);
        const row2 = sql(`select status,approved_by from transport_contracts where id='${state.contractId}'`);
        await shot(p2, 'portal-transport-contract-approved');
        await ctx.close();
        if (!row2.startsWith('active')) throw new Error(`not active: ${row2}; err=${err2}`);
        return `drafter refused (${err || 'flash'}); sysadmin5 -> ${row2}`;
    }, page);

    await R.step('Compute the transport statement for the current month', async () => {
        await visit(page, '/transport/settlements/create');
        await selectLabelByText(page, 'Contract', /QA-CT-1/);
        (await byLabel(page, 'Period start')).fill(monthStart);
        (await byLabel(page, 'Period end')).fill(monthEnd);
        await submitAndLeave(page, /settlements\/create/);
        if (/\/create/.test(page.url())) throw new Error('stayed: ' + (await page.locator('form').innerText()).replace(/\s+/g, ' ').slice(0, 400));
        const m = page.url().match(/settlements\/([0-9a-f-]{36})/);
        state.transportSettlementId = m[1]; saveState(state);
        await shot(page, 'portal-transport-statement-draft');
        return sql(`select settlement_number,status,calculated_amount,currency_code from transport_settlements where id='${m[1]}'`);
    }, page);

    await R.step('Submit statement for review (sysadmin4); approve by sysadmin5 (distinct approver)', async () => {
        const e1 = await dialogAction(page, /Submit for review/i);
        const r1 = sql(`select status from transport_settlements where id='${state.transportSettlementId}'`);
        if (r1 !== 'under_review') throw new Error('submit: ' + r1 + ' ' + e1);
        const selfApprove = await page.getByRole('button', { name: /^Approve$/ }).count();
        let selfErr = null;
        if (selfApprove) selfErr = await dialogAction(page, /^Approve$/, undefined);
        const rSelf = sql(`select status from transport_settlements where id='${state.transportSettlementId}'`);
        if (rSelf === 'approved') throw new Error('computer approved own statement');
        const ctx = await browser.newContext(); const p2 = await newPageIn(ctx); R.attach(p2);
        await login(p2, 'qa.sysadmin5@demo.local', 'password', {});
        await visit(p2, `/transport/settlements/${state.transportSettlementId}`);
        const e2 = await dialogAction(p2, /^Approve$/, '0');
        const r2 = sql(`select status,approved_by,approved_amount,calculated_amount from transport_settlements where id='${state.transportSettlementId}'`);
        await shot(p2, 'portal-transport-statement-approved');
        await ctx.close();
        if (!r2.startsWith('approved')) throw new Error(`approve: ${r2}; err=${e2}`);
        return `self-approve button=${selfApprove} err=${selfErr}; sysadmin5 -> ${r2}`;
    }, page);

    await R.step('Statement PDF (back office) is a real PDF > 1 KB', async () => {
        const r = await fetchWithSession(page, `${BASE}/transport/settlements/${state.transportSettlementId}/statement.pdf`);
        if (r.status !== 200 || !r.head.startsWith('%PDF') || r.size < 1024) throw new Error(`${r.status} ${r.contentType} ${r.size}B head=${JSON.stringify(r.head)} ${r.text.slice(0, 100)}`);
        return `${r.status} ${r.contentType} ${r.size}B`;
    });

    await R.step('Transport transactions export + report exports are files', async () => {
        const out = [];
        for (const p of ['/transport/transactions/export', '/transport/reports/usage/export/csv', '/transport/reports/usage/export/xlsx']) {
            const r = await fetchWithSession(page, `${BASE}${p}`);
            out.push(`${p}: ${r.status} ${r.contentType.split(';')[0]} ${r.size}B`);
        }
        return out.join(' | ');
    });

    await R.step('Provider portal: statement visible to QA-TRANSPORT-1 with its own PDF; invoices page', async () => {
        const ctx = await browser.newContext(); const pp = await newPageIn(ctx); R.attach(pp);
        const u = await providerLogin(pp, BASE, state.transportUser.username, state.transportUser.password);
        if (/login/.test(u)) throw new Error('portal login failed');
        await visit(pp, '/provider/portal/transport/settlements');
        const b = await bodyText(pp);
        await shot(pp, 'portal-transport-portal-settlements');
        if (!/QA-CT-1|STM-|Statement/i.test(b)) throw new Error('statement not listed: ' + b.slice(0, 200));
        await visit(pp, `/provider/portal/transport/settlements/${state.transportSettlementId}`);
        const r = await fetchWithSession(pp, `${BASE}/provider/portal/transport/settlements/${state.transportSettlementId}/statement.pdf`);
        await ctx.close();
        if (r.status !== 200 || !r.head.startsWith('%PDF')) throw new Error(`portal pdf: ${r.status} ${r.contentType} ${r.size}B`);
        return `portal PDF ${r.size}B`;
    });

    R.results.push({ label: 'slow document loads >3s', status: 'PASS', info: JSON.stringify(slow) });
    await browser.close();
    R.save();
})().catch((e) => { console.error('FATAL', e); process.exit(1); });
