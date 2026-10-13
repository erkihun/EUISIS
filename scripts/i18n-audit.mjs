#!/usr/bin/env node
// Localization audit (docs/amharic-language-audit.md, docs/amharic-terminology-guide.md).
//
//   node scripts/i18n-audit.mjs                 # summary table per module
//   node scripts/i18n-audit.mjs --json out.json # full findings as JSON
//   node scripts/i18n-audit.mjs --strict        # exit 1 on blocking findings (CI)
//
// Loads the frontend translation tree exactly as useLocale builds it (esbuild
// over resources/js/hooks/useLocale.ts) and every PHP language file through
// PHP, then reports, per module:
//   - keys present in one locale only (blocking)
//   - empty values (blocking)
//   - Amharic values that are English text (no Ethiopic letters, not an
//     allowed acronym, number, URL or placeholder) — "english_in_am" (blocking)
//   - English values containing Ethiopic script — "amharic_in_en" (blocking,
//     except keys listed in ALLOWED_AM_IN_EN: language names, bilingual labels)
//   - placeholder sets that differ between the locales (blocking)
//   - deprecated Amharic terms from resources/data/amharic-terminology.json
//     (blocking when the entry says "block": true, otherwise a warning)
//   - bare status codes (UPPER_SNAKE) in Amharic display text (blocking). A code may
//     appear only as a bracketed reference after its Amharic meaning, e.g.
//     "ውሳኔ ያስፈልጋል (NEEDS_DECISION)", and format tokens such as {SEQUENCE} are syntax
//   - spelling-variant statistics for the orthography policy (informational)
// It never rewrites text: wording is a human decision recorded in the glossary.
import fs from 'node:fs';
import path from 'node:path';
import { execFileSync } from 'node:child_process';
import { pathToFileURL } from 'node:url';
import { createRequire } from 'node:module';

const root = process.cwd();
const args = process.argv.slice(2);
const jsonOut = args.includes('--json') ? args[args.indexOf('--json') + 1] : null;
const strict = args.includes('--strict');

// ---------------------------------------------------------------- load trees
async function loadFrontend() {
    const esbuild = createRequire(path.join(root, 'package.json'))('esbuild');
    const source = fs.readFileSync(path.join(root, 'resources/js/hooks/useLocale.ts'), 'utf8') + '\nexport { translations };\n';
    const out = await esbuild.build({
        stdin: { contents: source, resolveDir: path.join(root, 'resources/js/hooks'), loader: 'ts', sourcefile: 'useLocale.ts' },
        bundle: true, format: 'esm', platform: 'node', write: false, logLevel: 'silent',
        external: ['react', 'react-dom', '@inertiajs/react'],
        plugins: [{
            name: 'alias',
            setup(build) {
                build.onResolve({ filter: /^@\// }, async (a) => {
                    const base = path.join(root, 'resources/js', a.path.slice(2));
                    for (const c of [base, `${base}.ts`, `${base}.tsx`, path.join(base, 'index.ts'), path.join(base, 'index.tsx')]) {
                        if (fs.existsSync(c) && fs.statSync(c).isFile()) return { path: c };
                    }
                    return { path: `${base}.ts` };
                });
            },
        }],
    });
    const dir = path.join(root, 'storage/framework/testing');
    fs.mkdirSync(dir, { recursive: true });
    const tmp = path.join(dir, `i18n-audit-${process.pid}-${Date.now()}.mjs`);
    fs.writeFileSync(tmp, out.outputFiles[0].text);
    try {
        const { translations } = await import(pathToFileURL(tmp).href);
        return translations;
    } finally {
        fs.unlinkSync(tmp);
    }
}

function loadPhp(locale) {
    const dir = path.join(root, 'lang', locale).replace(/\\/g, '/');
    // Laravel loads the framework's own language files first and the application's over them,
    // so an English key missing from lang/en (most of validation.php) still resolves.
    const framework = path.join(root, 'vendor/laravel/framework/src/Illuminate/Translation/lang', locale).replace(/\\/g, '/');
    const code = `$o=[];foreach([${JSON.stringify(framework)}, ${JSON.stringify(dir)}] as $d){foreach(glob($d.'/*.php') ?: [] as $f){$n=basename($f,'.php');$o[$n]=array_replace_recursive($o[$n]??[], require $f);}}echo json_encode($o, JSON_UNESCAPED_UNICODE);`;
    return JSON.parse(execFileSync('php', ['-r', code], { encoding: 'utf8', maxBuffer: 64 * 1024 * 1024 }));
}

// The permission catalogue that seeds the role pages (database/seeders/data/permissions.php and the
// files it includes). database_path() is the only framework helper those files use.
function loadPermissions() {
    const code = `function database_path($p = ''){ return getcwd().'/database/'.$p; } echo json_encode(require 'database/seeders/data/permissions.php', JSON_UNESCAPED_UNICODE);`;
    return JSON.parse(execFileSync('php', ['-r', code], { cwd: root, encoding: 'utf8', maxBuffer: 64 * 1024 * 1024 }));
}

function flatten(tree, prefix = '', out = {}) {
    for (const [k, v] of Object.entries(tree ?? {})) {
        const key = prefix ? `${prefix}.${k}` : k;
        if (v !== null && typeof v === 'object' && !Array.isArray(v)) flatten(v, key, out);
        else if (Array.isArray(v)) v.forEach((item, i) => (typeof item === 'object' && item !== null ? flatten(item, `${key}.${i}`, out) : (out[`${key}.${i}`] = item)));
        else out[key] = v;
    }
    return out;
}

// ---------------------------------------------------------------- rules
const ETHIOPIC = /[ሀ-᎟ⶀ-⷟꬀-꬯]/;
const LATIN_WORD = /[A-Za-z]{2,}/g;
// Acronyms, product and standard names that stay in Latin script on Amharic pages (glossary §General UI).
const ALLOWED_LATIN = new Set([
    'QR', 'OTP', 'MFA', 'SMS', 'PDF', 'Excel', 'CSV', 'XLSX', 'API', 'GPS', 'KPI', 'KPIs', 'ISCO', 'ISIC', 'CPC', 'ID', 'NFC', 'URL', 'URI', 'HTTP', 'HTTPS',
    'IP', 'PNG', 'SVG', 'JPG', 'JPEG', 'WEBP', 'ICO', 'ZIP', 'JSON', 'XML', 'CSS', 'HTML', 'UUID', 'TOTP', 'SMTP', 'TLS', 'SSL', 'DNS', 'CORS', 'CSP', 'HSTS',
    'Telegram', 'Google', 'Authenticator', 'Microsoft', 'Gmail', 'WhatsApp', 'EPMS', 'IDP', 'PIP', 'FTE', 'HR', 'HRD', 'IT', 'ICT', 'UAT', 'PostgreSQL', 'Redis',
    'Addis', 'One', 'AA', 'IEMSS', 'PSC', 'WAL', 'PITR', 'S3', 'MB', 'KB', 'GB', 'px', 'mm', 'cm', 'am', 'en', 'EN', 'AM', 'OK', 'Ctrl', 'Shift', 'Alt', 'Enter', 'Esc',
    'Tab', 'Cmd', 'Base64', 'Bearer', 'Sanctum', 'OAuth', 'SSO', 'LDAP', 'SAML', 'NID', 'TIN', 'SKU', 'Wi', 'Fi', 'GC', 'EC', 'ETB', 'Birr', 'vs', 'eg',
    'CRUD', 'SLA', 'KPI', 'CEO', 'PDF/A', 'A4', 'A5', 'Unicode', 'Noto', 'Sans', 'Ethiopic', 'Abyssinica', 'SIL', 'Nyala', 'Ethiopia', 'Code', 'Ctrl',
]);
const PLACEHOLDER_PHP = /:([a-z_][a-z0-9_]*)/gi;
const PLACEHOLDER_TS = /\{([a-zA-Z_][a-zA-Z0-9_]*)\}/g;
const UPPER_SNAKE = /\b[A-Z]{2,}(?:_[A-Z0-9]{2,})+\b/g;

function latinLeftovers(value) {
    const stripped = String(value)
        .replace(/https?:\/\/\S+/g, ' ')
        .replace(/[\w.+-]+@[\w.-]+/g, ' ')
        .replace(PLACEHOLDER_TS, ' ')
        .replace(/:[a-z_][a-z0-9_]*/gi, ' ')
        .replace(/`[^`]*`/g, ' ');
    return (stripped.match(LATIN_WORD) ?? []).filter((w) => !ALLOWED_LATIN.has(w) && !ALLOWED_LATIN.has(w.toUpperCase()));
}

function placeholders(value, kind) {
    const re = kind === 'php' ? PLACEHOLDER_PHP : PLACEHOLDER_TS;
    const found = new Set();
    for (const m of String(value).matchAll(re)) found.add(m[1]);
    // `:` placeholders: ignore times like 10:30 and words after a colon in Amharic prose ("ማሳሰቢያ: ...").
    return [...found].filter((p) => kind !== 'php' || /^[a-z_][a-z0-9_]*$/.test(p)).sort();
}

// Status codes that are not inside a format token and not a bracketed reference after Amharic text.
function bareCodes(value) {
    const text = String(value).replace(/\{[A-Za-z0-9_]+\}/g, ' ');
    const out = [];
    for (const m of text.matchAll(UPPER_SNAKE)) {
        const before = text.slice(0, m.index);
        const bracketed = before.endsWith('(') && text[m.index + m[0].length] === ')' && ETHIOPIC.test(before);
        if (!bracketed) out.push(m[0]);
    }
    return out;
}

// Spelling statistics count a variant only at the start of a word (optionally after a
// preposition prefix), so ስራ inside ያስተሳስራል is not counted as a spelling of ሥራ.
const wordStart = (w) => new RegExp(`(?<![ሀ-፿])(?:[የበለከ]|ወደ|እንደ)?${w}`, 'u');

const moduleOf = (source, key) => (source === 'ts' ? key.split('.')[0] : key.split('.')[0]);

// Framework sample keys that are never displayed.
const IGNORED_KEYS = [/^validation\.custom\.attribute-name\./];

// Amharic values that are deliberately Latin script.
const ALLOWED_LATIN_VALUE_KEYS = [
    /^users\.phonePlaceholder$/, /^calendar\.dateFormat$/, // input and date format masks
    /^settings\.emailInEffect\.connections\.starttls$/, /^settings\.options\.mail_mailer\.(sendmail|ses)$/, // protocol and product names
    /^backup\.archiveMode$/, // the PostgreSQL setting name an operator looks up
    /^employee-portal\.languages\.en$/, // a language switcher names each language in itself
];

// Keys whose Amharic value names format tokens without braces (code-rule syntax users type).
const ALLOWED_CODE_KEYS = [/^code-rules\.custom_tokens_cannot_include_sequence$/];

// Keys whose English value legitimately carries Ethiopic (language names, bilingual labels).
const ALLOWED_AM_IN_EN = [
    /^(ts:)?(common\.)?(language|locale)/i, /amharic/i, /\.am$/, /Am$/, /_am$/, /AmPlaceholder$/, /bilingual/i, /calendar\.(months|days)/i, /ethiopian/i, /monthsAm/i,
    // Public QR landing: the three submission types are labelled in both languages on purpose, so a
    // visitor who cannot read the selected language still finds the right form (docs/public-feedback.md §1).
    /^serviceFeedback\.type(Feedback|Suggestion|Complaint)$/,
];

// Spelling pairs watched for the orthography policy (informational).
const SPELLING = [
    ['ሠራተኛ', 'ሰራተኛ'], ['ሥራ', 'ስራ'], ['ሥርዓት', 'ስርዓት'], ['ሥርዓት', 'ሥርአት'], ['ኃላፊ', 'ሃላፊ'], ['ኃላፊነት', 'ሃላፊነት'], ['ጽሕፈት', 'ፅህፈት'], ['ጽሑፍ', 'ፅሁፍ'],
    ['ሕግ', 'ህግ'], ['ሕዝብ', 'ህዝብ'], ['ዓመት', 'አመት'], ['ዓይነት', 'አይነት'], ['ሪፖርት', 'ሪፓርት'], ['ኮሚቴ', 'ኮሚቲ'], ['ቅጽ', 'ቅፅ'], ['ውሳኔ', 'ዉሳኔ'],
];

// ---------------------------------------------------------------- run
const terminology = fs.existsSync(path.join(root, 'resources/data/amharic-terminology.json'))
    ? JSON.parse(fs.readFileSync(path.join(root, 'resources/data/amharic-terminology.json'), 'utf8'))
    : { terms: [], deprecated: [] };

const frontend = await loadFrontend();
const sets = [
    { source: 'ts', en: flatten(frontend.en), am: flatten(frontend.am) },
    { source: 'php', en: flatten(loadPhp('en')), am: flatten(loadPhp('am')) },
];

const findings = [];
const modules = {};
const spelling = Object.fromEntries(SPELLING.map(([a, b]) => [`${a}/${b}`, [0, 0]]));
const SPELLING_RE = SPELLING.map(([a, b]) => [a, b, wordStart(a), wordStart(b)]);
const add = (severity, rule, source, key, detail) => findings.push({ severity, rule, source, key, detail });

for (const { source, en, am } of sets) {
    const keys = new Set([...Object.keys(en), ...Object.keys(am)]);
    for (const key of keys) {
        if (IGNORED_KEYS.some((re) => re.test(key))) continue;
        const mod = `${source}:${moduleOf(source, key)}`;
        modules[mod] ??= { strings: 0, en: 0, am: 0, missing_en: 0, missing_am: 0, flagged: 0 };
        const m = modules[mod];
        m.strings++;
        const e = en[key];
        const a = am[key];
        if (typeof e === 'string') m.en++;
        if (typeof a === 'string') m.am++;
        if (e === undefined) { m.missing_en++; m.flagged++; add('block', 'missing_en', source, key, a); continue; }
        if (a === undefined) { m.missing_am++; m.flagged++; add('block', 'missing_am', source, key, e); continue; }
        if (typeof e !== 'string' || typeof a !== 'string') continue;
        if (e.trim() === '' || a.trim() === '') { m.flagged++; add('block', 'empty', source, key, { en: e, am: a }); continue; }

        const leftovers = latinLeftovers(a);
        if (!ETHIOPIC.test(a) && leftovers.length > 0) {
            if (!ALLOWED_LATIN_VALUE_KEYS.some((re) => re.test(key))) { m.flagged++; add('block', 'english_in_am', source, key, a); }
        }
        else if (ETHIOPIC.test(a) && leftovers.length > 0) add('warn', 'latin_words_in_am', source, key, { am: a, words: leftovers });

        if (ETHIOPIC.test(e) && !ALLOWED_AM_IN_EN.some((re) => re.test(key))) { m.flagged++; add('block', 'amharic_in_en', source, key, e); }

        const pe = placeholders(e, source);
        const pa = placeholders(a, source);
        if (pe.join(',') !== pa.join(',')) { m.flagged++; add('block', 'placeholder_mismatch', source, key, { en: pe, am: pa }); }

        if (!ALLOWED_CODE_KEYS.some((re) => re.test(key))) {
            for (const token of bareCodes(a)) { m.flagged++; add('block', 'raw_enum_in_text', source, key, token); }
        }

        // A system permission is የመዳረሻ መብት (prose: መብት); ፈቃድ is leave. A browser's camera or
        // location permission is consent and may keep ፈቃድ.
        if (/permission/i.test(e) && /ፈቃ[ድዶ]|ፍቃ[ድዶ]/.test(a) && !/camera|location|browser/i.test(e)) {
            m.flagged++; add('block', 'permission_as_leave', source, key, a);
        }

        for (const d of terminology.deprecated ?? []) {
            if (d.pattern && new RegExp(d.pattern, 'u').test(a) && !(d.allowKeys ?? []).some((k) => key.startsWith(k))) {
                if (d.block) m.flagged++;
                add(d.block ? 'block' : 'warn', 'deprecated_term', source, key, { term: d.current, use: d.recommended, am: a });
            }
        }
        for (const [x, y, rx, ry] of SPELLING_RE) {
            if (rx.test(a)) spelling[`${x}/${y}`][0]++;
            if (ry.test(a)) spelling[`${x}/${y}`][1]++;
        }
    }
}

// ---------------------------------------------------------------- permission catalogue
// Labels are commands in the polite form (ይመልከቱ, ያስተዳድሩ); the familiar forms below are blocked.
// Transport permissions are out of production scope and are not audited.
const FAMILIAR_IMPERATIVES = new Set([
    'ፍጠሩ', 'ፍጠር', 'ዘርዝሩ', 'ዘርዝር', 'አዘምኑ', 'አዘምን', 'ሰርዙ', 'ሰርዝ', 'አስተዳድሩ', 'አስተዳድር', 'አጽድቁ', 'አጽድቅ', 'አትሙ', 'ሰይሙ', 'አቁሙ',
    'አስጀምሩ', 'አረጋግጡ', 'አድርጉ', 'ዝጉ', 'መርምሩ', 'አስወግዱ', 'አቦዝኑ', 'አጽዱ', 'ፈትሹ', 'ስጡ', 'ቀይሩ', 'አንቀሳቅስ', 'አጠናቅቁ', 'አንሱ', 'ምረጡ',
    'ጀምሩ', 'አቀናብሩ', 'ተቆጣጠር', 'መድብ', 'ላክ', 'ላኩ', 'መልሱ', 'አንቁ', 'አውድቁ', 'አሳይ', 'ፍቀድ', 'አንቃ', 'ተመልከት', 'ይላቁ',
]);
{
    const mod = 'perm:catalogue';
    modules[mod] = { strings: 0, en: 0, am: 0, missing_en: 0, missing_am: 0, flagged: 0 };
    const m = modules[mod];
    const catalogue = loadPermissions();
    // Each group heads a section on the role editor; it needs a label in both languages.
    const tsSet = sets.find((s) => s.source === 'ts');
    for (const group of new Set(catalogue.map((p) => p.group).filter(Boolean))) {
        const key = `permissions.groupLabels.${group}`;
        for (const locale of ['en', 'am']) {
            if (typeof tsSet[locale][key] !== 'string') { m.flagged++; add('block', 'permission_group_label', 'ts', key, locale); }
        }
    }
    for (const p of catalogue) {
        if (String(p.name ?? '').startsWith('transport')) continue;
        for (const field of ['label', 'description']) {
            const e = String(p[`${field}_en`] ?? '');
            const a = String(p[`${field}_am`] ?? '');
            const key = `${p.name}.${field}`;
            m.strings++;
            if (e.trim() !== '') m.en++;
            if (a.trim() !== '') m.am++;
            if (a.trim() === '') { m.missing_am++; m.flagged++; add('block', 'missing_am', 'perm', key, e); continue; }
            if (!ETHIOPIC.test(a) && latinLeftovers(a).length > 0) { m.flagged++; add('block', 'english_in_am', 'perm', key, a); }
            const last = a.trim().replace(/\s*\([^)]*\)$/, '').split(/\s+/).pop();
            if (field === 'label' && FAMILIAR_IMPERATIVES.has(last)) { m.flagged++; add('block', 'familiar_imperative', 'perm', key, a); }
            for (const d of terminology.deprecated ?? []) {
                if (d.pattern && new RegExp(d.pattern, 'u').test(a) && !(d.allowKeys ?? []).some((k) => key.startsWith(k))) {
                    if (d.block) m.flagged++;
                    add(d.block ? 'block' : 'warn', 'deprecated_term', 'perm', key, { term: d.current, use: d.recommended, am: a });
                }
            }
        }
    }
}

// ---------------------------------------------------------------- hard-coded translations in components
// A ternary on the locale with an Amharic literal hides wording from this audit. Calendar data
// (month and weekday names) is fixed data, not a translation, and is not matched by this rule.
{
    const INLINE = /locale\s*===\s*['"]am['"]\s*\?\s*(['"`])([^'"`]*[ሀ-፿][^'"`]*)\1/g;
    const walk = (dir) => fs.readdirSync(dir, { withFileTypes: true }).flatMap((e) => {
        const p = path.join(dir, e.name);
        if (e.isDirectory()) return p.endsWith(`${path.sep}i18n`) ? [] : walk(p);
        return /\.(tsx?|jsx?)$/.test(e.name) ? [p] : [];
    });
    for (const file of walk(path.join(root, 'resources/js'))) {
        const rel = path.relative(root, file).replace(/\\/g, '/');
        fs.readFileSync(file, 'utf8').split(/\r?\n/).forEach((line, i) => {
            for (const m of line.matchAll(INLINE)) add('block', 'hardcoded_translation', 'tsx', `${rel}:${i + 1}`, m[2]);
        });
    }
}

// ---------------------------------------------------------------- keys used in code but defined nowhere
// t('a.b') in React, __('a.b') / trans() / @lang() in PHP and Blade, and registry 'label_key' entries.
// An undefined key is displayed as the key itself. Keys built by concatenation ('x.prefix_' . $y) and
// code comments are skipped.
{
    const keysOf = (src) => { const s = new Set(); for (const { source, en } of sets) if (source === src) for (const k of Object.keys(en)) s.add(k); return s; };
    const prefixes = (keys) => new Set([...keys].flatMap((k) => k.split('.').map((_, i, a) => a.slice(0, i + 1).join('.'))));
    const tsKeys = keysOf('ts'); const phpKeys = keysOf('php');
    const tsAll = prefixes(tsKeys); const phpAll = prefixes(phpKeys);
    const walk = (dir, re) => (fs.existsSync(dir) ? fs.readdirSync(dir, { withFileTypes: true }) : []).flatMap((e) => {
        const p = path.join(dir, e.name);
        if (e.isDirectory()) return ['i18n', 'node_modules', 'vendor'].includes(e.name) ? [] : walk(p, re);
        return re.test(e.name) ? [p] : [];
    });
    const scan = (files, patterns, known, source) => {
        for (const file of files) {
            const rel = path.relative(root, file).replace(/\\/g, '/');
            fs.readFileSync(file, 'utf8').split(/\r?\n/).forEach((line, i) => {
                if (/^\s*(\*|\/\/|#)/.test(line)) return;
                for (const re of patterns) {
                    for (const m of line.matchAll(re)) {
                        const key = m[1];
                        if (/[._]$/.test(key) || known.has(key)) continue;
                        add('block', 'unknown_key', source, key, `${rel}:${i + 1}`);
                    }
                }
            });
        }
    };
    scan(walk(path.join(root, 'resources/js'), /\.(tsx?|jsx?)$/), [/\bt\(\s*'([a-zA-Z][\w-]*(?:\.[\w-]+)+)'/g], tsAll, 'ts');
    scan([...walk(path.join(root, 'app'), /\.php$/), ...walk(path.join(root, 'resources/views'), /\.php$/)],
        [/(?:\b__|\btrans|@lang)\(\s*'([a-z][\w-]*(?:\.[\w-]+)+)'/g], phpAll, 'php');
    scan(walk(path.join(root, 'app'), /\.php$/), [/'label_key'\s*=>\s*'([a-zA-Z][\w-]*(?:\.[\w-]+)+)'/g], new Set([...tsAll, ...phpAll]), 'ts');
}

const blocking = findings.filter((f) => f.severity === 'block');
const byRule = findings.reduce((acc, f) => ((acc[`${f.severity}:${f.rule}`] = (acc[`${f.severity}:${f.rule}`] ?? 0) + 1), acc), {});

if (args.includes('--pairs')) {
    const pairsOut = args[args.indexOf('--pairs') + 1];
    const rows = [];
    for (const { source, en, am } of sets) {
        for (const key of Object.keys(am)) {
            if (typeof am[key] === 'string') rows.push({ source, key, en: typeof en[key] === 'string' ? en[key] : null, am: am[key] });
        }
    }
    fs.writeFileSync(pairsOut, JSON.stringify(rows));
}

if (jsonOut) {
    fs.writeFileSync(jsonOut, JSON.stringify({ generated: new Date().toISOString(), modules, byRule, spelling, findings }, null, 2));
}

const totalStrings = Object.values(modules).reduce((s, m) => s + m.strings, 0);
const totalAm = Object.values(modules).reduce((s, m) => s + m.am, 0);
console.log(`Translation keys: ${totalStrings} (Amharic values present: ${totalAm}) across ${Object.keys(modules).length} modules`);
console.log('Findings by rule:', JSON.stringify(byRule));
console.log('Spelling variants (first/second):', JSON.stringify(spelling));
if (!jsonOut) {
    for (const f of blocking.slice(0, 40)) console.log(`BLOCK ${f.rule} ${f.source}:${f.key} ${JSON.stringify(f.detail).slice(0, 140)}`);
    if (blocking.length > 40) console.log(`… ${blocking.length - 40} more blocking findings`);
}
process.exit(strict && blocking.length > 0 ? 1 : 0);
