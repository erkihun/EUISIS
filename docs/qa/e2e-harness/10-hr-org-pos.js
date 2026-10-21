'use strict';
// Stage A of the HR core suite: Organizations (list/search/show/create child/edit/audit/hierarchy),
// organization units (prerequisite for positions) and Positions (create/validation/duplicate code).
const { BASE, Recorder, newBrowser, login, submitAndLeave, expectUrl, visit, shot } = require('./lib');
const H = require('./10-hr-helpers');

const SYSADMIN = ['qa.sysadmin1@demo.local', 'password'];
const REUSE = process.env.QA_REUSE === '1'; // reuse records created by an earlier run (saved in results/10-hr-state.json)
const RUN = process.env.QA_RUN || String(Date.now()).slice(-5); // suffix so re-runs do not collide on unique names

(async () => {
    const R = new Recorder('10-hr-org-pos');
    const { browser, page } = await newBrowser({ timeout: 25000 });
    R.attach(page);
    const posts = [];
    H.captureResponses(page, posts);
    const state = REUSE ? H.loadState() : {};
    state.run = REUSE && state.run ? state.run : RUN;
    const reuse = (key) => REUSE && state[key] && H.sql(`select 1 as ok from ${ {org1:'organizations',org2:'organizations',unit1:'organization_units',unit2:'organization_units',pos1:'positions',pos2:'positions',pos3:'positions'}[key]} where id='${state[key].id}'`).length > 0;
    const timings = {};

    async function timed(name, fn) { const t = Date.now(); const r = await fn(); timings[name] = Date.now() - t; return r; }
    const lastPost = (re) => [...posts].reverse().find((p) => re.test(p.url));

    /** Fill the input that follows a <label> whose text matches `label`. */
    async function fillByLabel(label, value, type = 'input') {
        const el = page.locator(`label:has-text("${label}")`).first().locator(`xpath=following::${type}[1]`);
        await el.fill(value);
    }

    /** Wait for the code-rule preview call and return the previewed code. */
    async function waitCodePreview() {
        const resp = await page.waitForResponse((r) => r.url().includes('/code-rules/preview-code') && r.request().method() === 'POST', { timeout: 15000 });
        const json = await resp.json().catch(() => null);
        return { status: resp.status(), code: json?.code ?? null, error: json?.error ?? null };
    }

    await R.step('System Admin logs in without MFA', async () => {
        const url = await login(page, ...SYSADMIN);
        if (!/dashboard/.test(url)) throw new Error('expected dashboard, got ' + url);
    }, page);

    // ───────────────────────── Organizations ─────────────────────────
    await R.step('Organizations list renders with rows and a search field', async () => {
        const r = await timed('organizations.index', () => visit(page, '/organizations'));
        const rows = await page.locator('table tbody tr').count();
        if (rows < 5) throw new Error('expected seeded organizations in list, rows=' + rows);
        await shot(page, 'hr-org-index');
        return `rows=${rows}, ${timings['organizations.index']}ms`;
    }, page);

    await R.step('Search "SC-01" narrows the list to Lemi Kura', async () => {
        // the first type=search input on the page is the sidebar menu filter; target the list filter by placeholder
        await page.locator('input[placeholder*="Search by code"]').first().fill('SC-01');
        const respP = page.waitForResponse((r) => /\/organizations\?.*search=SC-01/.test(r.url()), { timeout: 20000 });
        const t0 = Date.now();
        await page.locator('button:has-text("Filter")').first().click();
        const resp = await respP;
        timings['organizations.search'] = Date.now() - t0;
        await page.waitForTimeout(400);
        if (resp.status() !== 200) throw new Error('search response ' + resp.status());
        const body = await page.locator('table').innerText();
        if (!/SC-01/.test(body) || !/Lemi Kura/.test(body)) throw new Error('SC-01 not in results: ' + body.slice(0, 200));
        const rows = await page.locator('table tbody tr').count();
        if (rows > 3) throw new Error('search did not narrow: rows=' + rows);
        await shot(page, 'hr-org-search');
        return `rows=${rows}, ${timings['organizations.search']}ms`;
    }, page);

    const sc01 = H.sql(`select id, code, name_en from organizations where code = 'SC-01'`)[0];
    const sc02 = H.sql(`select id, code, name_en from organizations where code = 'SC-02'`)[0];

    await R.step('Open seeded organization SC-01 from the list (show page)', async () => {
        const link = page.locator(`a[href*="/organizations/${sc01.id}"]`).first();
        if (!(await link.count())) throw new Error('no link to SC-01 show page in the table');
        const t0 = Date.now();
        await link.click();
        await page.waitForURL(new RegExp(`/organizations/${sc01.id}`), { timeout: 20000, waitUntil: 'networkidle' });
        timings['organizations.show'] = Date.now() - t0;
        await expectUrl(page, new RegExp(`/organizations/${sc01.id}`));
        const body = await page.locator('body').innerText();
        if (!/Lemi Kura/.test(body)) throw new Error('show page lacks organization name');
        if (!/SC-01/.test(body)) throw new Error('show page lacks code');
        await shot(page, 'hr-org-show-sc01');
        return `${timings['organizations.show']}ms`;
    }, page);

    // A child organization can only be attached inside a DRAFT hierarchy version (OrganizationStoreRequest::after).
    await R.step('Create a draft hierarchy version QA-HV (prerequisite for adding a child organization)', async () => {
        const existing = H.sql(`select id, version_name from hierarchy_versions where status = 'draft' and version_name like 'QA-HV%' order by created_at desc limit 1`)[0];
        if (existing) { state.hvId = existing.id; state.hvName = existing.version_name; return `reused ${existing.version_name}`; }
        await visit(page, '/hierarchy-versions/create');
        state.hvName = `QA-HV-${RUN}`;
        await fillByLabel('Version Name', state.hvName);
        await page.locator('input[type="date"]').first().fill('2026-10-20');
        await submitAndLeave(page, /\/hierarchy-versions\/create/);
        await expectUrl(page, /\/hierarchy-versions\/[0-9a-f-]+$/);
        const row = H.sql(`select id, status from hierarchy_versions where version_name = '${state.hvName}'`)[0];
        if (!row) throw new Error('hierarchy version row missing');
        if (row.status !== 'draft') throw new Error('status=' + row.status);
        state.hvId = row.id;
        await shot(page, 'hr-hv-created');
        return `${state.hvName} id=${row.id}`;
    }, page);

    /** Create a child organization through the UI and return its DB row. */
    async function createChildOrg(parent, name, typeCode) {
        await visit(page, `/organizations/create?parent=${parent.id}`);
        const previewP = waitCodePreview().catch(() => ({ status: 0, code: null, error: 'no preview call' }));
        await fillByLabel('Name (English)', name);
        const typeSel = page.locator('label:has-text("Organization Type")').first().locator('xpath=following::select[1]');
        await typeSel.selectOption({ label: await typeSel.locator('option').filter({ hasText: `(${typeCode})` }).first().textContent() });
        await page.waitForTimeout(800);
        const preview = await previewP;
        // parent is pre-selected via ?parent=, hierarchy version defaults to the first draft
        const hvSel = page.locator('label:has-text("Hierarchy Version")').first().locator('xpath=following::select[1]');
        if (await hvSel.count()) await hvSel.selectOption(state.hvId);
        const body = await page.locator('form').innerText();
        if (!new RegExp(parent.code).test(body)) throw new Error('selected parent not shown in form');
        await shot(page, `hr-org-create-${name}`);
        const mark = H.logMark();
        await submitAndLeave(page, /\/organizations\/create/);
        const post = lastPost(/\/organizations$/);
        const errs = await H.fieldErrors(page);
        const row = H.sql(`select id, code, name_en, status, organization_type_id from organizations where name_en = '${name}'`)[0];
        if (!row) throw new Error(`org row missing; POST=${post?.status}; errors=${errs.join(' | ')}; log=${H.logErrorsSince(mark).join(' || ')}`);
        const edge = H.sql(`select relationship_type from organization_edges where parent_organization_id='${parent.id}' and child_organization_id='${row.id}' and hierarchy_version_id='${state.hvId}'`)[0];
        return { row, post, preview, edge, url: page.url() };
    }

    await R.step('Create child organization QA-ORG-1 (Woreda) under SC-01 with a generated code', async () => {
        if (reuse('org1')) return 'reused ' + state.org1.id;
        const name = `QA-ORG-1-${RUN}`;
        const { row, post, preview, edge, url } = await createChildOrg(sc01, name, 'WOREDA');
        state.org1 = row; state.org1Name = name;
        if (!edge) throw new Error('no organization_edges row to SC-01 in the draft version');
        if (preview.code && preview.code !== row.code) throw new Error(`previewed code ${preview.code} differs from stored code ${row.code}`);
        if (!/^ORG-\d{4}-\d{5}$/.test(row.code)) throw new Error('code not generated by rule {PREFIX}-{YEAR}-{SEQUENCE}: ' + row.code);
        return `code=${row.code} (preview ${preview.code}), POST ${post?.status}, landed ${url.replace(BASE, '')}, edge=${edge.relationship_type}`;
    }, page);

    await R.step('Child organization show page: parent placement as displayed (reads published version only)', async () => {
        await visit(page, `/organizations/${state.org1.id}`);
        const body = await page.locator('body').innerText();
        if (!body.includes(state.org1Name)) throw new Error('name missing');
        await shot(page, 'hr-org1-show');
        const parentShown = /SC-01|Lemi Kura/.test(body);
        const placement = (body.match(/Parent Organization\s*\n\s*([^\n]+)/) || [])[1] || '?';
        return `parent SC-01 shown=${parentShown}; "Parent Organization: ${placement}" (edge lives in draft ${state.hvName}; show page resolves parent from the latest PUBLISHED version only)`;
    }, page);

    await R.step('Edit QA-ORG-1 name; change persists in DB and on reload', async () => {
        await visit(page, `/organizations/${state.org1.id}/edit`);
        const newName = `${state.org1Name.replace(/ Edited.*$/, '')} Edited ${RUN}`;
        await fillByLabel('Name (English)', newName);
        await submitAndLeave(page, /\/edit$/);
        const post = lastPost(new RegExp(`/organizations/${state.org1.id}$`));
        const row = H.sql(`select name_en from organizations where id='${state.org1.id}'`)[0];
        if (row.name_en !== newName) throw new Error(`DB name=${row.name_en}; POST=${post?.status} ${post?.body || ''}`);
        await visit(page, `/organizations/${state.org1.id}`);
        const body = await page.locator('body').innerText();
        if (!body.includes(newName)) throw new Error('edited name not shown after reload');
        state.org1Name = newName;
        await shot(page, 'hr-org1-edited');
        return `POST ${post?.status}`;
    }, page);

    await R.step('audit_logs has organization_created and organization_updated rows for QA-ORG-1', async () => {
        const rows = H.sql(`select event_type, actor_user_id, auditable_type from audit_logs where auditable_id='${state.org1.id}' order by created_at`);
        const types = rows.map((r) => r.event_type);
        if (!types.includes('organization_created')) throw new Error('no organization_created: ' + types.join(','));
        if (!types.includes('organization_updated')) throw new Error('no organization_updated: ' + types.join(','));
        const actor = H.sql(`select id from users where email='${SYSADMIN[0]}'`)[0].id;
        if (!rows.every((r) => String(r.actor_user_id) === String(actor))) throw new Error('actor mismatch: ' + JSON.stringify(rows));
        return types.join(', ');
    }, page);

    await R.step('Hierarchy versions page lists the QA draft version', async () => {
        await timed('hierarchy-versions.index', () => visit(page, '/hierarchy-versions'));
        const body = await page.locator('body').innerText();
        if (!body.includes(state.hvName)) throw new Error('draft version missing from list');
        await shot(page, 'hr-hv-index');
    }, page);

    await R.step('Hierarchy tree page of the draft version shows QA-ORG-1 under SC-01', async () => {
        await timed('hierarchy.tree', () => visit(page, `/hierarchy-versions/${state.hvId}/tree`));
        await page.waitForTimeout(800);
        let body = await page.locator('body').innerText();
        if (!body.includes(state.org1Name)) {
            // nodes may be collapsed: expand everything we can
            const expanders = page.locator('button[aria-expanded="false"], button:has-text("Expand")');
            for (let i = 0; i < Math.min(await expanders.count(), 40); i++) { try { await expanders.nth(i).click({ timeout: 1000 }); } catch {} }
            await page.waitForTimeout(500);
            body = await page.locator('body').innerText();
        }
        await shot(page, 'hr-hv-tree');
        if (!body.includes(state.org1Name)) throw new Error('QA-ORG-1 not visible in tree page (even after expanding)');
        return `${timings['hierarchy.tree']}ms`;
    }, page);

    await R.step('Organization list search finds QA-ORG-1', async () => {
        await visit(page, `/organizations?search=${encodeURIComponent('QA-ORG-1')}`);
        const body = await page.locator('table').innerText().catch(() => '');
        if (!body.includes('QA-ORG-1')) throw new Error('not found by search');
    }, page);

    await R.step('Create second child organization QA-ORG-2 under SC-02 (transfer destination)', async () => {
        if (reuse('org2')) return 'reused ' + state.org2.id;
        const name = `QA-ORG-2-${RUN}`;
        const { row, post, edge } = await createChildOrg(sc02, name, 'WOREDA');
        state.org2 = row; state.org2Name = name;
        if (!edge) throw new Error('no edge');
        return `code=${row.code}, POST ${post?.status}`;
    }, page);

    // ───────────────────────── Organization units (positions require one) ─────────────────────────
    async function createUnit(org, name) {
        await visit(page, `/organization-units/create?organization_id=${org.id}`);
        const previewP = waitCodePreview().catch(() => ({ code: null }));
        const typeSel = page.locator('label:has-text("Unit Type")').first().locator('xpath=following::select[1]');
        await typeSel.selectOption({ label: 'Department' });
        await fillByLabel('Name (English)', name);
        await page.waitForTimeout(600);
        const preview = await previewP;
        await submitAndLeave(page, /\/organization-units\/create/);
        const post = lastPost(/\/organization-units$/);
        const row = H.sql(`select id, code, name_en, status from organization_units where organization_id='${org.id}' and name_en='${name}'`)[0];
        if (!row) throw new Error(`unit missing; POST=${post?.status} ${post?.body || ''}; errors=${(await H.fieldErrors(page)).join(' | ')}`);
        return { row, post, preview };
    }

    await R.step('Create organization unit QA-UNIT-1 in QA-ORG-1 (generated unit code)', async () => {
        if (reuse('unit1')) return 'reused ' + state.unit1.id;
        const { row, post, preview } = await createUnit(state.org1, `QA-UNIT-1-${RUN}`);
        state.unit1 = row;
        return `code=${row.code} (preview ${preview.code}), POST ${post?.status}, landed ${page.url().replace(BASE, '')}`;
    }, page);

    await R.step('Create organization unit QA-UNIT-2 in QA-ORG-2', async () => {
        if (reuse('unit2')) return 'reused ' + state.unit2.id;
        const { row, post } = await createUnit(state.org2, `QA-UNIT-2-${RUN}`);
        state.unit2 = row;
        return `code=${row.code}, POST ${post?.status}`;
    }, page);

    // ───────────────────────── Positions ─────────────────────────
    async function createPosition(org, unit, title) {
        await visit(page, `/positions/create?organization_id=${org.id}&organization_unit_id=${unit.id}`);
        const previewP = waitCodePreview().catch(() => ({ code: null, error: 'no preview call' }));
        await page.locator('#occupation_id').selectOption({ index: 1 });
        await page.locator('#title_en').fill(title);
        const preview = await previewP;
        await page.waitForTimeout(400);
        const shown = await page.locator('[aria-live="polite"]').first().innerText().catch(() => '');
        await shot(page, `hr-pos-create-${title.replace(/\s+/g, '-')}`);
        const mark = H.logMark();
        await submitAndLeave(page, /\/positions\/create/);
        const post = lastPost(/\/positions$/);
        const row = H.sql(`select id, code, job_position_code, title_en, organization_id, organization_unit_id, is_active from positions where title_en='${title}' and organization_id='${org.id}' and deleted_at is null`)[0];
        if (!row) throw new Error(`position missing; POST=${post?.status} ${post?.body || ''}; errors=${(await H.fieldErrors(page)).join(' | ')}; log=${H.logErrorsSince(mark).join(' || ')}`);
        return { row, post, preview, shown };
    }

    await R.step('Create position "QA Analyst" in QA-ORG-1/QA-UNIT-1 with a rule-generated job position code', async () => {
        if (reuse('pos1')) return 'reused ' + state.pos1.id;
        const title = `QA Analyst ${RUN}`;
        const { row, post, preview, shown } = await createPosition(state.org1, state.unit1, title);
        state.pos1 = row;
        if (!row.job_position_code) throw new Error('job_position_code empty');
        if (preview.code && preview.code !== row.job_position_code) throw new Error(`preview ${preview.code} != stored ${row.job_position_code}`);
        return `job_position_code=${row.job_position_code} (preview shown "${shown}"), POST ${post?.status}, landed ${page.url().replace(BASE, '')}`;
    }, page);

    await R.step('Position show page displays the new position', async () => {
        if (REUSE) await visit(page, `/positions/${state.pos1.id}`);
        await expectUrl(page, new RegExp(`/positions/${state.pos1.id}`));
        const body = await page.locator('body').innerText();
        if (!body.includes(state.pos1.job_position_code)) throw new Error('code not displayed');
        await shot(page, 'hr-pos1-show');
    }, page);

    await R.step('Create position "QA Analyst 2" in QA-ORG-1 (for the second employee)', async () => {
        if (reuse('pos2')) return 'reused ' + state.pos2.id;
        const { row, post } = await createPosition(state.org1, state.unit1, `QA Analyst 2 ${RUN}`);
        state.pos2 = row;
        return `job_position_code=${row.job_position_code}, POST ${post?.status}`;
    }, page);

    await R.step('Create vacant position "QA Transfer Target" in QA-ORG-2 (transfer destination)', async () => {
        if (reuse('pos3')) return 'reused ' + state.pos3.id;
        const { row, post } = await createPosition(state.org2, state.unit2, `QA Transfer Target ${RUN}`);
        state.pos3 = row;
        return `job_position_code=${row.job_position_code}, POST ${post?.status}`;
    }, page);

    await R.step('Position form: empty submit is blocked (native required on unit/occupation) and server rejects missing titles', async () => {
        await visit(page, `/positions/create?organization_id=${state.org1.id}&organization_unit_id=${state.unit1.id}`);
        await page.waitForTimeout(800);
        const before = posts.length;
        await page.click('button[type="submit"]');
        await page.waitForTimeout(800);
        const nativeBlocked = posts.length === before;
        const invalidMsg = await page.locator('#occupation_id').evaluate((el) => el.validationMessage).catch(() => '');
        // Now satisfy the native checks and submit with no title at all
        await page.locator('#occupation_id').selectOption({ index: 1 });
        await submitAndLeave(page, /\/positions\/create/, 8000);
        const post = lastPost(/\/positions$/);
        const errs = await H.fieldErrors(page);
        await shot(page, 'hr-pos-validation');
        if (!/\/positions\/create/.test(page.url())) throw new Error('left the create page on invalid submit: ' + page.url());
        if (!errs.some((e) => /title|name/i.test(e))) throw new Error(`no title error shown; POST=${post?.status}; errors=${errs.join(' | ')}`);
        return `native-blocked=${nativeBlocked} ("${invalidMsg}"); server POST ${post?.status}; errors: ${errs.join(' | ')}`;
    }, page);

    await R.step('Duplicate job position code is rejected by the server (422) — UI has no manual override, so posted via XHR', async () => {
        // The code field is auto-generated with manual override disabled, so a duplicate can only reach the server via a crafted request.
        await visit(page, `/positions/create?organization_id=${state.org1.id}&organization_unit_id=${state.unit1.id}`);
        const res = await page.evaluate(async ({ org, unit, dup }) => {
            const occ = document.querySelector('#occupation_id');
            const occId = occ ? occ.options[1].value : '';
            try {
                const r = await window.axios.post('/positions', {
                    job_position_code: dup, title_en: 'QA Dup Code', organization_id: org, organization_unit_id: unit, occupation_id: occId, is_active: true,
                }, { headers: { Accept: 'application/json' } });
                return { status: r.status, data: String(r.data).slice(0, 200) };
            } catch (e) {
                return { status: e.response?.status, data: JSON.stringify(e.response?.data).slice(0, 400), location: e.response?.headers?.location };
            }
        }, { org: state.org1.id, unit: state.unit1.id, dup: state.pos1.job_position_code });
        const dupRows = H.sql(`select count(*)::int as n from positions where job_position_code='${state.pos1.job_position_code}' and deleted_at is null`)[0].n;
        if (dupRows !== 1) throw new Error('duplicate code row was created! count=' + dupRows);
        if (res.status === 200 || res.status === 201) throw new Error('server accepted duplicate code: ' + JSON.stringify(res));
        return `status=${res.status} ${res.location ? 'redirect ' + res.location : ''} ${res.data || ''}`.slice(0, 300);
    }, page);

    await R.step('Positions list shows the QA positions under QA-ORG-1', async () => {
        await timed('positions.index', () => visit(page, `/positions?organization_id=${state.org1.id}&organization_unit_id=${state.unit1.id}`));
        await page.waitForTimeout(500);
        const body = await page.locator('body').innerText();
        if (!body.includes(state.pos1.job_position_code)) throw new Error('QA Analyst not listed');
        await shot(page, 'hr-pos-index');
        return `${timings['positions.index']}ms`;
    }, page);

    H.saveState(state);
    const slow = Object.entries(timings).filter(([, ms]) => ms > 3000);
    console.log('timings', JSON.stringify(timings), 'slow:', JSON.stringify(slow));
    require('fs').writeFileSync(require('path').join(__dirname, 'results', '10-hr-org-pos-posts.json'), JSON.stringify(posts.filter((p) => p.status >= 400), null, 2));
    await browser.close();
    R.save();
})().catch((e) => { console.error('FATAL', e); process.exit(1); });
