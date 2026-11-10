#!/usr/bin/env node
/*
 * Letters: every `lt('…')` key the pages and components use must exist in both
 * dictionaries (resources/js/i18n/{en,am}/letters.ts), and both must carry the
 * same keys. The strict audit (scripts/i18n-audit.mjs) checks the words, not
 * whether a page asks for a key that is gone, which is how a renamed section
 * once printed "letters.settings.workingDays" on the register form.
 *
 *   npm run i18n:letters
 */
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const tmp = fs.mkdtempSync(path.join(os.tmpdir(), 'letters-i18n-'));

async function load(lang) {
    const source = fs.readFileSync(path.join(root, `resources/js/i18n/${lang}/letters.ts`), 'utf8');
    const file = path.join(tmp, `letters-${lang}.mjs`);
    fs.writeFileSync(file, source.replace(/ as const/g, '').replace(/^import .*$/gm, ''));
    return (await import(pathToFileURL(file).href)).default;
}

const get = (object, key) => key.split('.').reduce((o, k) => (o && typeof o === 'object' ? o[k] : undefined), object);
const flat = (object, prefix = '') => Object.entries(object).flatMap(([k, v]) => (v && typeof v === 'object' ? flat(v, `${prefix}${k}.`) : [`${prefix}${k}`]));

const dictionaries = { en: await load('en'), am: await load('am') };
const files = [];
const walk = (dir) => {
    for (const entry of fs.readdirSync(dir, { withFileTypes: true })) {
        const full = path.join(dir, entry.name);
        if (entry.isDirectory()) walk(full);
        else if (/\.tsx$/.test(entry.name)) files.push(full);
    }
};
walk(path.join(root, 'resources/js/Pages/Letters'));
walk(path.join(root, 'resources/js/Components/letters'));

const problems = [];
for (const file of files) {
    const source = fs.readFileSync(file, 'utf8');
    for (const match of source.matchAll(/\blt\(\s*(['"`])([^'"`]+)\1/g)) {
        const key = match[2];
        const dynamic = key.includes('${');
        // A computed key (`status.${s}`) is checked down to its fixed group.
        const lookup = dynamic ? key.slice(0, key.indexOf('${')).replace(/\.$/, '') : key;
        if (lookup === '') continue;
        for (const lang of ['en', 'am']) {
            const value = get(dictionaries[lang], lookup);
            if (dynamic ? typeof value !== 'object' : typeof value !== 'string') {
                problems.push(`${lang}: ${key}  (${path.relative(root, file)})`);
            }
        }
    }
}

const en = new Set(flat(dictionaries.en));
const am = new Set(flat(dictionaries.am));
for (const key of en) if (!am.has(key)) problems.push(`am: missing ${key}`);
for (const key of am) if (!en.has(key)) problems.push(`en: missing ${key}`);

fs.rmSync(tmp, { recursive: true, force: true });

if (problems.length > 0) {
    console.error(`Letters dictionary keys: ${problems.length} problem(s)`);
    for (const problem of [...new Set(problems)]) console.error(`  ${problem}`);
    process.exit(1);
}
console.log(`Letters dictionary keys: ok (${files.length} files, ${en.size} keys per language)`);
