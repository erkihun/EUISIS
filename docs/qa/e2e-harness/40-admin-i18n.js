'use strict';
// Journey 10: Amharic UI — English leakage on Users list and System Settings general tab; slow pages; console errors.
const fs = require('fs');
const path = require('path');
const { BASE, Recorder, newBrowser, login, shot } = require('./lib');
const { timedVisit } = require('./40-admin-lib');

// Data values that legitimately stay Latin (names, emails, codes, brand).
const ALLOW = /^(Addis|One|AA-IEMSS|QA|sysadmin\d*|reportviewer|auditor|demo|local|User|Officer|Admin|Super|System|City|HR|Report|Viewer|Auditor|Employee|Manager|Public|Service|Bureau|Organizational|Role|Officer|Grievance|Cafeteria|Transport|Performance|Structure|ID|Card|NFC|API|Settings|Security|Site|Daily|Activity|Reviewer|Design|Catalog|Approver|Designer|Implementation|Change|Requester|Provider|Settlement|Dispatcher|Finance|Operator|Assessment|Oversight|Communication|Moderator|Investigator|Privacy|Disciplinary|Committee|Member|Decision|Authority|Discipline|Case|Registry|Executive|Writer|Chairperson|Administrator|Appeal|Calibrator|Tribunal|Administrative|Provisioning|Organization|Officer|Integrated|Employee|Management|Ababa|Administration|production|dashboard|UTC|Africa|Addis_Ababa|en|am|ltr|rtl|Ctrl|S)$/;

async function leakage(page) {
    const text = await page.evaluate(() => {
        const walker = document.createTreeWalker(document.body, NodeFilter.SHOW_TEXT);
        const parts = [];
        while (walker.nextNode()) {
            const n = walker.currentNode;
            const el = n.parentElement;
            if (!el || ['SCRIPT', 'STYLE', 'NOSCRIPT'].includes(el.tagName)) continue;
            if (el.closest('[aria-hidden="true"]')) continue;
            const s = n.textContent.trim();
            if (s) parts.push(s);
        }
        // also placeholders, aria-labels, titles and option labels
        document.querySelectorAll('[placeholder]').forEach((e) => parts.push('placeholder:' + e.getAttribute('placeholder')));
        document.querySelectorAll('[aria-label]').forEach((e) => parts.push('aria-label:' + e.getAttribute('aria-label')));
        document.querySelectorAll('[title]').forEach((e) => parts.push('title:' + e.getAttribute('title')));
        return parts;
    });
    const latin = [];
    for (const s of text) {
        if (/@|https?:|^\+?\d/.test(s)) continue;
        const words = s.replace(/^(placeholder|aria-label|title):/, '').split(/[^A-Za-z_-]+/).filter((w) => w.length >= 2);
        if (!words.length) continue;
        if (words.every((w) => ALLOW.test(w))) continue;
        latin.push(s.slice(0, 80));
    }
    return [...new Set(latin)];
}

(async () => {
    const R = new Recorder('40-admin-i18n');
    const { browser, page } = await newBrowser({ uiLocale: 'am' });
    R.attach(page);
    const out = {};

    await R.step('Login page in Amharic; System Admin logs in', async () => {
        await page.goto(`${BASE}/login`, { waitUntil: 'networkidle' });
        out.login = await leakage(page);
        await login(page, 'qa.sysadmin5@demo.local', 'password');
        const lang = await page.evaluate(() => document.documentElement.lang);
        return `html lang=${lang}; login-page latin strings=${out.login.length}: ${out.login.slice(0, 8).join(' | ')}`;
    }, page);

    await R.step('Users list in Amharic: English leakage report', async () => {
        const v = await timedVisit(page, '/users');
        out.users = await leakage(page);
        await shot(page, '40-i18n-users');
        return `${v.ms}ms; latin strings=${out.users.length}: ${out.users.join(' | ').slice(0, 900)}`;
    }, page);

    await R.step('System Settings general tab in Amharic: English leakage report', async () => {
        const v = await timedVisit(page, '/system-settings?tab=general');
        out.settingsGeneral = await leakage(page);
        await shot(page, '40-i18n-settings-general');
        return `${v.ms}ms; latin strings=${out.settingsGeneral.length}: ${out.settingsGeneral.join(' | ').slice(0, 900)}`;
    }, page);

    await R.step('Security tab + Roles + Audit logs in Amharic: leakage counts', async () => {
        const res = [];
        for (const u of ['/system-settings?tab=security', '/roles', '/audit-logs', '/recycle-bin', '/code-rules', '/profile']) {
            const v = await timedVisit(page, u);
            const l = await leakage(page);
            out[u] = l;
            res.push(`${u}: ${v.status}/${v.ms}ms latin=${l.length} e.g. ${l.slice(0, 4).join(' | ')}`);
        }
        return res.join(' ;; ');
    }, page);

    fs.writeFileSync(path.join(__dirname, 'results', '40-admin-i18n-leakage.json'), JSON.stringify(out, null, 2));
    await browser.close();
    R.save();
})().catch((e) => { console.error('FATAL', e); process.exit(1); });
