// Copies the EUISIS screens the Core serves, unchanged, into this frontend.
//
//   node scripts/sync-from-euisis.mjs [path to the EUISIS checkout, default ../..]
//
// The Core pages (PAGES) and the EUISIS entry are followed through every
// import; each file they reach is copied from EUISIS resources/js as it is,
// with the translations, type declarations, styles and the shared UI kit, and
// the EUISIS lang/ files go to the backend (its flash and validation messages),
// with the EUISIS System Settings registry (BACKEND_COPIES).
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
    'Core/Dashboard', 'Core/Assignments', 'Core/DataQuality', 'Core/AuditCenter', 'Core/IntegrationCenter', 'Core/DuplicateReviews', 'Core/Imports', 'Core/ImportBatch', 'Core/JobMasters', 'Core/SecurityCenter', 'Core/Reports', 'Core/Notifications', 'Core/SystemHealth', 'PrintBatches/Index', 'PrintBatches/Create', 'PrintBatches/Show', 'Roles/Index', 'Roles/Create', 'Roles/Edit',
    'Organizations/Index', 'Organizations/Show', 'Organizations/Create', 'Organizations/Edit', 'Organizations/Organogram',
    'OrganizationTypes/Index', 'OrganizationTypes/Show', 'OrganizationTypes/Create', 'OrganizationTypes/Edit',
    'OrganizationUnits/Index', 'OrganizationUnits/Show', 'OrganizationUnits/Create', 'OrganizationUnits/Edit',
    'OrganizationUnitTypes/Index', 'OrganizationUnitTypes/Show', 'OrganizationUnitTypes/Create', 'OrganizationUnitTypes/Edit',
    'HierarchyVersions/Index', 'HierarchyVersions/Show', 'HierarchyVersions/Tree', 'HierarchyVersions/Create', 'HierarchyVersions/Edit', 'HierarchyVersions/EditTree',
    'Positions/Index', 'Positions/Show', 'Positions/Create', 'Positions/Edit', 'Positions/Status',
    'PositionEstablishments/Index', 'PositionEstablishments/Show', 'PositionEstablishments/Edit',
    'Employees/Index', 'Employees/Show', 'Employees/Create', 'Employees/Edit',
    'IdCards/Index', 'IdCards/Show', 'IdCards/Preview',
    'CardRequests/Index', 'CardRequests/Show', 'CardRequests/Create',
    'Users/Index', 'Users/Create', 'Users/Edit',
    'AuditLogs/Index', 'SystemSettings/Index', 'SystemSettings/IdCardTemplates',
    'CodeRules/Index', 'CodeRules/Create', 'CodeRules/Show', 'CodeRules/Edit',
    'Public/IdChecker',
];

/** Copied whole: every language file and type declaration. */
const WHOLE = ['i18n', 'types'];

/** EUISIS backend files the Core reads as they are; only the namespace differs. */
const BACKEND_COPIES = [
    // The System Settings registry, under its own namespace so it sits beside the Core's registry.
    { from: 'app/Services/SystemSettings/SystemSettingsRegistry.php', to: 'app/Settings/Euisis/SystemSettingsRegistry.php', namespace: 'App\\Settings\\Euisis' },
    // The ID card template designer: model, rules, action, request and controller, as in EUISIS.
    { from: 'app/Enums/IdCardTemplate.php' },
    { from: 'app/Models/IdCardTemplate.php' },
    { from: 'app/Models/Concerns/HasUuidPrimaryKey.php' },
    { from: 'app/Services/IdCards/IdCardTemplateService.php' },
    { from: 'app/Services/IdCards/IdCardBackPhoto.php' },
    { from: 'app/Services/IdCards/IdCardHeaderContent.php' },
    { from: 'app/Services/IdCards/IdCardLayoutElement.php' },
    { from: 'app/Services/IdCards/IdCardTextStyle.php' },
    { from: 'app/Services/IdCards/IdCardLayoutSettings.php' },
    { from: 'app/Services/IdCards/IdCardLayoutSettingsService.php' },
    { from: 'app/Actions/IdCards/SaveIdCardTemplateAction.php' },
    { from: 'app/Http/Controllers/Web/IdCardTemplateController.php' },
    // The printed card: the EUISIS SVG renderer, its data object, QR code, assets, PNG export and the Ethiopian calendar it dates with.
    { from: 'app/Services/IdCards/IdCardSvgRenderer.php' },
    { from: 'app/Services/IdCards/IdCardRenderData.php' },
    { from: 'app/Services/IdCards/IdCardBackTextLayout.php' },
    { from: 'app/Services/IdCards/IdCardQrCodeRenderer.php' },
    { from: 'app/Services/IdCards/QrCodeVersionResolver.php' },
    { from: 'app/Services/IdCards/QrPayloadSecurityValidator.php' },
    { from: 'app/Services/IdCards/QrPayloadContainsPiiException.php' },
    { from: 'app/Services/IdCards/IdCardAssetResolver.php' },
    { from: 'app/Services/IdCards/IdCardPngExporter.php' },
    { from: 'app/Services/Calendar/EthiopianCalendarService.php' },
    // The printed-field list lives in a service the Core does not have (it reads EUISIS print snapshots).
    {
        from: 'app/Http/Requests/IdCards/SaveIdCardTemplateRequest.php',
        replace: [
            ['use App\\Services\\IdCards\\IdCardFieldImpactService;\n', ''],
            ['use App\\Services\\SystemSettings\\SystemSettingsService;\n', 'use App\\Services\\SystemSettings\\SystemSettingsService;\nuse App\\Support\\IdCardFields;\n'],
            ['IdCardFieldImpactService::ROW_FIELDS', 'IdCardFields::ROW_FIELDS'],
        ],
    },
    // Code Rules (the EUISIS screens over the Core's rules): enums, exceptions, models, the token registry, the
    // sequence and rule resolvers and the generator as they are. The target registry, the position context
    // resolver, the actions, the policy and the controller are the Core's own (app/Services/CodeGeneration,
    // app/Actions/CodeRules, app/Policies, app/Http/Controllers/Web): they name EUISIS modules the Core does not run.
    { from: 'app/Enums/CodeRuleEntityType.php' },
    { from: 'app/Enums/CodeRuleResetFrequency.php' },
    { from: 'app/Enums/CodeRuleScopeStrategy.php' },
    { from: 'app/Enums/CodeRuleScopeType.php' },
    { from: 'app/Exceptions/MissingSequenceScopeContextException.php' },
    { from: 'app/Exceptions/MissingTokenContextException.php' },
    { from: 'app/Exceptions/RandomCodeGenerationException.php' },
    { from: 'app/Models/CodeRule.php' },
    { from: 'app/Models/CodeRuleSequence.php' },
    { from: 'app/Models/CodeGenerationLog.php' },
    { from: 'app/Services/CodeGeneration/CodeFormatTokenRegistry.php' },
    { from: 'app/Services/CodeGeneration/SequenceScopeResolver.php' },
    { from: 'app/Services/CodeGeneration/CodeGeneratorService.php' },
    { from: 'app/Services/CodeGeneration/CodeRuleResolver.php' },
    { from: 'app/Http/Requests/PreviewCodeForEntityRequest.php' },
    { from: 'app/Http/Requests/PreviewCodeRuleRequest.php' },
    { from: 'app/Http/Resources/CodeGenerationLogResource.php' },
    // Service types and service providers are EUISIS modules; their tokens and scope resolve to nothing on the Core.
    {
        from: 'app/Services/CodeGeneration/CodeFormatTokenResolver.php',
        replace: [
            ['use App\\Backbone\\Contracts\\ProviderCodeLookup;\n', ''],
            ['use App\\Models\\ServiceType;\n', ''],
            ['        // Provider codes come from service delivery through the backbone contract.\n        private readonly ?ProviderCodeLookup $providerCodes = null,\n', ''],
            ['        return ($this->providerCodes ?? app(ProviderCodeLookup::class))->codeFor($providerId);', '        return null; // The Core has no service providers.'],
            ["        $code = ServiceType::query()->whereKey($serviceTypeId)->value('code');", '        $code = null; // The Core has no service types.'],
            ["        $serviceType = ServiceType::query()->whereKey($serviceTypeId)->first(['id', 'code']);", '        $serviceType = null; // The Core has no service types.'],
        ],
    },
    {
        from: 'app/Http/Requests/StoreCodeRuleRequest.php',
        replace: [
            ['use App\\Models\\ServiceType;\n', ''],
            ['            CodeRuleScopeType::ServiceType->value => ServiceType::query()->whereKey($scopeId)->exists(),\n', ''],
        ],
    },
    {
        from: 'app/Http/Requests/UpdateCodeRuleRequest.php',
        replace: [
            ['use App\\Models\\ServiceType;\n', ''],
            ['            CodeRuleScopeType::ServiceType->value => ServiceType::query()->whereKey($scopeId)->exists(),\n', ''],
        ],
    },
    {
        from: 'app/Http/Resources/CodeRuleResource.php',
        replace: [
            ['use App\\Models\\ServiceType;\n', ''],
            [
                "            CodeRuleScopeType::ServiceType->value => $this->scope_id !== null\n                ? ServiceType::query()->whereKey($this->scope_id)->value('name_en')\n                : __('code-rules.scope_type_service_type'),\n",
                "            CodeRuleScopeType::ServiceType->value => __('code-rules.scope_type_service_type'),\n",
            ],
        ],
    },
];

/** Where the backend copies land (relative to backend/); CI checks them for drift. */
export const BACKEND_COPY_PATHS = [...new Set(BACKEND_COPIES.map(({ from, to }) => to ?? from))];

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
// EUISIS backend files the Core runs as they are (the settings registry, the card template designer):
// the same path and namespace unless the entry says otherwise, with its listed replacements only.
for (const { from, to, namespace, replace = [] } of BACKEND_COPIES) {
    let source = fs.readFileSync(path.join(euisis, from), 'utf8');
    if (namespace) source = source.replace(/^namespace [^;]+;/m, `namespace ${namespace};`);
    for (const [search, replacement] of replace) {
        if (!source.includes(search)) {
            console.error(`Replacement in ${from} no longer applies: EUISIS changed "${search.trim()}"`);
            process.exit(1);
        }
        source = source.replace(search, replacement);
    }
    const target = path.join(here, '..', 'backend', to ?? from);
    fs.mkdirSync(path.dirname(target), { recursive: true });
    fs.writeFileSync(target, source);
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
