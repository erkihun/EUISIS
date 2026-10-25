// Copies the EUISIS screens the Core serves, unchanged, into this frontend.
//
//   node scripts/sync-from-euisis.mjs [path to the EUISIS checkout, default ../..]
//
// The Core pages (PAGES) and the EUISIS entry are followed through every
// import; each file they reach is copied from EUISIS resources/js as it is,
// with the translations, type declarations, styles and the shared UI kit, and
// the EUISIS lang/ files go to the backend (its flash and validation messages).
// Nothing is edited by hand here: the only differences from EUISIS are the
// PATCHES below, applied by this script. Run it again whenever EUISIS changes.
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const here = path.dirname(path.dirname(fileURLToPath(import.meta.url)));
const euisis = path.resolve(process.argv[2] ?? path.join(here, '..', '..'));
const sourceJs = path.join(euisis, 'resources', 'js');
const targetJs = path.join(here, 'resources', 'js');

/** The EUISIS pages the Core backend renders (Inertia::render names). */
export const PAGES = [
    'Auth/Login', 'Auth/ForcedPasswordChange', 'Auth/MfaSetup', 'Auth/MfaChallenge', 'Profile/Edit',
    'Core/Dashboard', 'Core/Assignments', 'Core/DataQuality', 'Core/Settings', 'Core/AuditCenter', 'Core/IntegrationCenter', 'Core/DuplicateReviews', 'Core/Imports', 'Core/ImportBatch', 'Core/JobMasters', 'Core/CardTemplates', 'Core/CardPreview', 'Core/SecurityCenter', 'Core/Reports', 'PrintBatches/Index', 'PrintBatches/Create', 'PrintBatches/Show', 'Roles/Index', 'Roles/Create', 'Roles/Edit',
    'Organizations/Index', 'Organizations/Show', 'Organizations/Create', 'Organizations/Edit', 'Organizations/Organogram',
    'OrganizationTypes/Index', 'OrganizationTypes/Show', 'OrganizationTypes/Create', 'OrganizationTypes/Edit',
    'OrganizationUnits/Index', 'OrganizationUnits/Show', 'OrganizationUnits/Create', 'OrganizationUnits/Edit',
    'OrganizationUnitTypes/Index', 'OrganizationUnitTypes/Show', 'OrganizationUnitTypes/Create', 'OrganizationUnitTypes/Edit',
    'HierarchyVersions/Index', 'HierarchyVersions/Show', 'HierarchyVersions/Tree', 'HierarchyVersions/Create', 'HierarchyVersions/Edit', 'HierarchyVersions/EditTree',
    'Positions/Index', 'Positions/Show', 'Positions/Create', 'Positions/Edit', 'Positions/Status',
    'PositionEstablishments/Index', 'PositionEstablishments/Show', 'PositionEstablishments/Edit',
    'Employees/Index', 'Employees/Show', 'Employees/Create', 'Employees/Edit',
    'IdCards/Index', 'IdCards/Show',
    'CardRequests/Index', 'CardRequests/Show', 'CardRequests/Create',
    'Users/Index', 'Users/Create', 'Users/Edit',
    'AuditLogs/Index', 'SystemSettings/Index',
    'Public/IdChecker',
];

/** Copied whole: every language file and type declaration. */
const WHOLE = ['i18n', 'types'];

/**
 * The Core's only changes to the copied files. The Core has fewer routes than
 * EUISIS; a link to a page that stays in EUISIS resolves to "#" instead of
 * throwing, so a shared component that offers it still renders.
 */
const PATCHES = [
    {
        file: 'app.tsx',
        from: 'globalThis.route = ziggyRoute;',
        to: [
            '// Core: a route that stays in EUISIS is not in the Core route list; link to nothing instead of throwing.',
            "globalThis.route = ((name?: string, ...rest: unknown[]) => (name !== undefined && !(name in (globalThis.Ziggy as ZiggyConfig).routes) ? '#' : (ziggyRoute as (...args: unknown[]) => unknown)(name, ...rest))) as typeof ziggyRoute;",
        ].join('\n'),
    },
];

function resolveImport(from, spec) {
    let base;
    if (spec.startsWith('@/')) base = path.join(sourceJs, spec.slice(2));
    else if (spec.startsWith('.')) base = path.resolve(path.dirname(from), spec);
    else return null;
    for (const ext of ['', '.tsx', '.ts', '.js', '.jsx', '/index.tsx', '/index.ts', '/index.js']) {
        const file = base + ext;
        if (fs.existsSync(file) && fs.statSync(file).isFile()) return file;
    }
    return null;
}

const reached = new Set();
function follow(file) {
    if (reached.has(file)) return;
    reached.add(file);
    if (!/\.(tsx?|jsx?)$/.test(file)) return;
    const source = fs.readFileSync(file, 'utf8');
    for (const match of source.matchAll(/(?:import|export)\s[^'"]*?from\s*['"]([^'"]+)['"]|import\(\s*['"]([^'"]+)['"]\s*\)|import\s+['"]([^'"]+)['"]/g)) {
        const target = resolveImport(file, match[1] ?? match[2] ?? match[3]);
        if (target) follow(target);
    }
}

function copyFile(from, to) {
    fs.mkdirSync(path.dirname(to), { recursive: true });
    fs.copyFileSync(from, to);
}

function copyTree(from, to) {
    for (const entry of fs.readdirSync(from, { withFileTypes: true })) {
        const source = path.join(from, entry.name);
        const target = path.join(to, entry.name);
        if (entry.isDirectory()) copyTree(source, target);
        else copyFile(source, target);
    }
}

if (!fs.existsSync(path.join(sourceJs, 'app.tsx'))) {
    console.error(`No EUISIS checkout at ${euisis}`);
    process.exit(1);
}

follow(path.join(sourceJs, 'app.tsx'));
for (const page of PAGES) follow(path.join(sourceJs, 'Pages', `${page}.tsx`));

// The generated route list belongs to the Core backend; keep it across syncs.
const ziggyFile = path.join(targetJs, 'ziggy.js');
const ziggy = fs.existsSync(ziggyFile) ? fs.readFileSync(ziggyFile) : null;
fs.rmSync(targetJs, { recursive: true, force: true });

let copied = 0;
for (const file of reached) {
    const relative = path.relative(sourceJs, file);
    if (relative.startsWith('..')) {
        // Outside resources/js (the stylesheet the entry imports).
        copyFile(file, path.join(here, 'resources', path.relative(path.join(euisis, 'resources'), file)));
    } else if (path.basename(file) !== 'ziggy.js') {
        copyFile(file, path.join(targetJs, relative));
    }
    copied++;
}
for (const folder of WHOLE) copyTree(path.join(sourceJs, folder), path.join(targetJs, folder));
copyTree(path.join(euisis, 'resources', 'css'), path.join(here, 'resources', 'css'));
fs.rmSync(path.join(here, 'packages', 'euisis-ui'), { recursive: true, force: true });
copyTree(path.join(euisis, 'packages', 'euisis-ui'), path.join(here, 'packages', 'euisis-ui'));
if (ziggy) fs.writeFileSync(ziggyFile, ziggy);
// The server-side messages (flash, validation) the pages show, in both languages.
for (const locale of ['en', 'am']) {
    const target = path.join(here, '..', 'backend', 'lang', locale);
    fs.rmSync(target, { recursive: true, force: true });
    copyTree(path.join(euisis, 'lang', locale), target);
}

for (const patch of PATCHES) {
    const file = path.join(targetJs, patch.file);
    const source = fs.readFileSync(file, 'utf8');
    if (!source.includes(patch.from)) {
        console.error(`Patch for ${patch.file} no longer applies: EUISIS changed "${patch.from}"`);
        process.exit(1);
    }
    fs.writeFileSync(file, source.replace(patch.from, patch.to));
}

console.log(`Copied ${copied} files reached from ${PAGES.length} pages, plus ${WHOLE.join(', ')}, styles, @euisis/ui and the backend's lang/.`);
