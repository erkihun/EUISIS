// Copies the EUISIS cafeteria screens and the cafeteria backend, unchanged, into
// the cafeteria platform.
//
//   node scripts/sync-from-euisis.mjs [path to the EUISIS checkout, default ../..]
//
// The pages (PAGES) and the EUISIS entry are followed through every import;
// each file they reach is copied from EUISIS resources/js as it is, with the
// translations, type declarations, styles and the shared UI kit. The EUISIS
// lang/ files go to the backend (its flash and validation messages), and so do
// the cafeteria module's PHP files (BACKEND_DIRS, BACKEND_COPIES): the models,
// services, actions, controllers, requests, resources, policies and exports,
// under the same paths and namespaces. The only edits are the `replace`
// entries below and PATCHES, applied by this script, where a copied file names
// an EUISIS module the cafeteria platform does not run (NFC, transport, the
// service transaction ledger). Run it again whenever EUISIS changes; CI fails
// when the copy drifts.
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const here = path.dirname(path.dirname(fileURLToPath(import.meta.url)));
const euisis = path.resolve(process.argv[2] ?? path.join(here, '..', '..'));
const sourceJs = path.join(euisis, 'resources', 'js');
const targetJs = path.join(here, 'resources', 'js');
const backend = path.join(here, '..', 'backend');

/** The EUISIS pages the cafeteria backend renders (Inertia::render names). */
export const PAGES = [
    // Sign-in, profile and the administration pages the platform skeleton serves.
    'Auth/Login', 'Auth/ForcedPasswordChange', 'Auth/MfaSetup', 'Auth/MfaChallenge', 'Profile/Edit',
    'Users/Index', 'Users/Create', 'Users/Edit', 'Roles/Index', 'Roles/Create', 'Roles/Edit',
    'AuditLogs/Index', 'SystemSettings/Index',
    'Core/Notifications', 'Core/SystemHealth', 'Core/SecurityCenter', 'Core/IntegrationCenter',
    // The cafeteria module: administration, scanning, the provider portal.
    'Cafeteria/Dashboard', 'Cafeteria/Scan', 'Cafeteria/MobileScan',
    'Cafeteria/Transactions/Index', 'Cafeteria/Transactions/Show', 'Cafeteria/Ledger/Index',
    'Cafeteria/Reports/Index', 'Cafeteria/Reports/Show', 'Cafeteria/Analytics/Index',
    'Cafeteria/Providers/Index', 'Cafeteria/Providers/Create', 'Cafeteria/Providers/Edit', 'Cafeteria/Providers/Show',
    'Cafeteria/Providers/ProviderDashboard', 'Cafeteria/Providers/CafeteriaForm',
    'Cafeteria/Providers/Branches/Create', 'Cafeteria/Providers/Branches/Edit',
    'Cafeteria/SubsidyRules/Index', 'Cafeteria/SubsidyRules/Create', 'Cafeteria/SubsidyRules/Edit',
    'Cafeteria/Holidays/Index', 'Cafeteria/Holidays/Create', 'Cafeteria/Holidays/Edit',
    'Cafeteria/Settings/Index', 'Cafeteria/DayRules/Index',
    'Cafeteria/SpecialDays/Index', 'Cafeteria/SpecialDays/Create', 'Cafeteria/SpecialDays/Edit',
    'Cafeteria/EmployeeExclusions/Index', 'Cafeteria/EmployeeExclusions/Create', 'Cafeteria/EmployeeExclusions/Edit', 'Cafeteria/EmployeeExclusions/Show',
    'Cafeteria/Payees/Index', 'Cafeteria/Payees/Form',
    'Cafeteria/Networks/Index', 'Cafeteria/Networks/Form', 'Cafeteria/Networks/Show',
    'Cafeteria/Access/Index', 'Cafeteria/Access/Form',
    'Cafeteria/Assignments/Index', 'Cafeteria/Assignments/Form',
    'Cafeteria/Policies/Index', 'Cafeteria/Policies/Form', 'Cafeteria/Policies/Show',
    'Cafeteria/Settlements/Index', 'Cafeteria/Settlements/Create', 'Cafeteria/Settlements/Show',
    'Cafeteria/Portal/Dashboard', 'Cafeteria/Portal/Scan', 'Cafeteria/Portal/Profile',
    'Cafeteria/Portal/Transactions/Index', 'Cafeteria/Portal/Transactions/Show',
    'Cafeteria/Portal/Ledger/Index', 'Cafeteria/Portal/Reports/Index',
    'Cafeteria/Portal/Menus/Index', 'Cafeteria/Portal/Menus/Form', 'Cafeteria/Portal/Menus/Show',
    'Cafeteria/Portal/Orders/Index', 'Cafeteria/Portal/Orders/Show',
    'ProviderPortal/Auth/Login', 'ProviderPortal/Auth/ForgotPassword',
];

/** Copied whole: every language file and type declaration. */
const WHOLE = ['i18n', 'types'];

/**
 * EUISIS backend folders copied file by file (same path, same namespace).
 * `exclude` drops the files of modules the platform does not run.
 */
const BACKEND_DIRS = [
    { dir: 'app/Services/Cafeteria' },
    { dir: 'app/Actions/Cafeteria' },
    { dir: 'app/Http/Controllers/Web/Cafeteria' },
    { dir: 'app/Http/Controllers/ProviderPortal', exclude: /Transport/ },
    { dir: 'app/Http/Resources/ProviderPortal' },
    { dir: 'app/Exports/Cafeteria' },
    { dir: 'app/Services/ProviderPortal' },
    { dir: 'app/Support/ProviderPortal' },
    { dir: 'app/Backbone/Contracts' },
    { dir: 'app/Backbone/Data' },
];

/**
 * Replacements inside files that BACKEND_DIRS copy, by their EUISIS path. The
 * platform's master-data models (synced from EUISIS over its API) keep plain
 * string statuses where EUISIS casts enums.
 */
const BACKEND_DIR_REPLACES = {
    'app/Services/Cafeteria/CafeteriaEligibilityService.php': [
        ['return $employee->status === EmployeeStatus::Active;', 'return ($employee->status instanceof EmployeeStatus ? $employee->status : EmployeeStatus::tryFrom((string) $employee->status)) === EmployeeStatus::Active;'],
        ["return in_array(\$card->status->value, ['active', 'issued'], true);", "return in_array(\$card->status instanceof \\BackedEnum ? \$card->status->value : (string) \$card->status, ['active', 'issued'], true);"],
    ],
    'app/Services/Cafeteria/CafeteriaQrScanService.php': [
        ["'card_status' => \$card?->status?->value,", "'card_status' => \$card?->status instanceof \\BackedEnum ? \$card->status->value : \$card?->status,"],
    ],
    'app/Backbone/Data/AssignmentContext.php': [
        ['status: $assignment->assignment_status->value,', 'status: $assignment->assignment_status instanceof \\BackedEnum ? $assignment->assignment_status->value : (string) $assignment->assignment_status,'],
    ],
    'app/Backbone/Data/EmployeeIdentity.php': [
        ['status: $employee->status->value,', 'status: $employee->status instanceof \\BackedEnum ? $employee->status->value : (string) $employee->status,'],
    ],
};

/** EUISIS backend files the platform reads as they are, with the listed replacements only. */
const BACKEND_COPIES = [
    // Models.
    ...[
        'CafeteriaDayRule', 'CafeteriaFoodOrder', 'CafeteriaFoodOrderItem', 'CafeteriaMenu', 'CafeteriaMenuItem', 'CafeteriaProvider',
        'CafeteriaProviderAssignment', 'CafeteriaProviderBranch', 'CafeteriaProviderLedgerEntry', 'CafeteriaProviderUser', 'CafeteriaReportRun',
        'CafeteriaServiceAssignment', 'CafeteriaServiceNetwork', 'CafeteriaServicePolicy', 'CafeteriaSetting', 'CafeteriaSettlement',
        'CafeteriaSettlementLine', 'CafeteriaSpecialDay', 'CafeteriaSubsidyLedger', 'CafeteriaSubsidyRule', 'CafeteriaTransaction',
        'CafeteriaTransactionConsumedDay', 'EmployeeCafeteriaExclusion', 'OrganizationCafeteriaAccess', 'OrganizationCafeteriaLocationAccess',
        'PublicHoliday', 'Provider', 'ProviderType', 'ProviderUser', 'ProviderService', 'ProviderUserServicePermission', 'ProviderPasswordResetCode',
        'ServiceType', 'ServiceProviderUser', 'ServiceTransaction', 'AuditLog',
    ].map((model) => ({ from: `app/Models/${model}.php` })),
    { from: 'app/Models/ServiceProvider.php' },
    // Enums.
    ...[
        'CafeteriaExclusionStatus', 'CafeteriaExclusionType', 'CafeteriaExtraScanPolicy', 'CafeteriaGrantStatus', 'CafeteriaLedgerEntryType',
        'CafeteriaLocationType', 'CafeteriaOperationalStatus', 'CafeteriaPolicyStatus', 'CafeteriaReportType', 'CafeteriaSettlementStatus',
        'CafeteriaSpecialDayType', 'CafeteriaSubsidyScope', 'CafeteriaTransactionStatus', 'CafeteriaUsageMode',
        'AuditEventType', 'EmployeeStatus', 'OrganizationStatus', 'TransactionStatus', 'CardStatus', 'CalendarSystem',
    ].map((name) => ({ from: `app/Enums/${name}.php` })),
    // Controllers (admin), requests, resources, policies, middleware.
    ...[
        'CafeteriaDashboardController', 'CafeteriaDayRuleController', 'CafeteriaProviderBranchController', 'CafeteriaProviderController',
        'CafeteriaProviderDashboardController', 'CafeteriaProviderUserController', 'CafeteriaReportController', 'CafeteriaSettingController',
        'CafeteriaSpecialDayController', 'CafeteriaSubsidyLedgerController', 'CafeteriaSubsidyRuleController',
        'EmployeeCafeteriaExclusionController', 'PublicHolidayController', 'ScanPhotoController',
    ].map((name) => ({ from: `app/Http/Controllers/Web/${name}.php` })),
    // NFC credentials stay in EUISIS: the platform's NfcCredentialService answers every tap "not available".
    { from: 'app/Http/Controllers/Web/CafeteriaTransactionController.php' },
    ...[
        'EndEmployeeCafeteriaExclusionRequest', 'GenerateCafeteriaReportRequest', 'ProcessCafeteriaQrScanRequest', 'ReverseCafeteriaTransactionRequest',
        'StoreCafeteriaProviderRequest', 'StoreCafeteriaSpecialDayRequest', 'StoreCafeteriaSubsidyRuleRequest', 'StoreEmployeeCafeteriaExclusionRequest',
        'UpdateCafeteriaDayRuleRequest', 'UpdateCafeteriaProviderRequest', 'UpdateCafeteriaSettingsRequest', 'UpdateCafeteriaSpecialDayRequest',
        'UpdateCafeteriaSubsidyRuleRequest', 'UpdateEmployeeCafeteriaExclusionRequest', 'StorePublicHolidayRequest', 'UpdatePublicHolidayRequest',
        'ProviderPortal/ProviderLoginRequest', 'ProviderPortal/ProviderScanRequest',
    ].map((name) => ({ from: `app/Http/Requests/${name}.php` })),
    ...[
        'CafeteriaDayRuleResource', 'CafeteriaProviderResource', 'CafeteriaReportRunResource', 'CafeteriaSpecialDayResource',
        'CafeteriaSubsidyLedgerResource', 'CafeteriaSubsidyRuleResource', 'CafeteriaTransactionResource', 'EmployeeCafeteriaExclusionResource',
        'PublicHolidayResource',
    ].map((name) => ({ from: `app/Http/Resources/${name}.php` })),
    ...[
        'CafeteriaDayRulePolicy', 'CafeteriaProviderPolicy', 'CafeteriaProviderUserPolicy', 'CafeteriaReportRunPolicy', 'CafeteriaSettingPolicy',
        'CafeteriaSpecialDayPolicy', 'CafeteriaSubsidyLedgerPolicy', 'CafeteriaSubsidyRulePolicy', 'CafeteriaTransactionPolicy',
        'EmployeeCafeteriaExclusionPolicy', 'ProviderUserPolicy', 'PublicHolidayPolicy', 'Concerns/DeniesNonAdminUsers',
    ].map((name) => ({ from: `app/Policies/${name}.php` })),
    { from: 'app/Http/Middleware/EnsureCafeteriaPortalUser.php' },
    { from: 'app/Http/Middleware/EnsureCafeteriaProviderAssigned.php' },
    { from: 'app/Http/Middleware/SetCafeteriaPortalContext.php' },
    { from: 'app/Http/Middleware/EnsureProviderPortalUser.php' },
    { from: 'app/Http/Middleware/EnsureProviderServiceEnabled.php' },
    { from: 'app/Http/Middleware/EnsureProviderServicePermission.php' },
    { from: 'app/Http/Middleware/SetProviderPortalContext.php' },
    // The backbone adapters that read the mirrored placements and employees (the credential and eligibility ones are the platform's own).
    { from: 'app/Backbone/Adapters/EloquentAssignmentContextReader.php' },
    { from: 'app/Backbone/Adapters/EloquentEmployeeDirectory.php' },
    // Services, actions and helpers the module reads.
    { from: 'app/Services/Calendar/PublicHolidayCalendar.php' },
    { from: 'app/Services/Calendar/CalendarService.php' },
    { from: 'app/Actions/Audit/WriteAuditLogAction.php' },
    { from: 'app/Security/LoginThrottle.php' },
    { from: 'app/Contracts/SmsGateway.php' },
    { from: 'app/Notifications/ProviderPasswordResetCodeNotification.php' },
    { from: 'app/Support/EmployeeScanPhoto.php' },
    { from: 'app/Support/Features.php' },
    { from: 'config/cafeteria.php' },
    { from: 'config/features.php' },
    { from: 'database/seeders/CafeteriaDayRuleSeeder.php' },
    { from: 'database/seeders/CafeteriaSettingsSeeder.php' },
];

/** Where the backend copies land (relative to backend/); CI checks them for drift. */
export const BACKEND_COPY_PATHS = [...new Set(BACKEND_COPIES.map(({ from, to }) => to ?? from))];

/**
 * The platform's only changes to the copied screens. It has fewer routes than
 * EUISIS; a link to a page that stays in EUISIS resolves to "#" instead of
 * throwing, so a shared component that offers it still renders.
 */
const PATCHES = [
    {
        // The server-sent navigation names the cafeteria icons the EUISIS sidebar already imports.
        file: 'Components/AppSidebar.tsx',
        from: '    ToolboxIcon, UserCircleIcon, Users, UsersTwoIcon,\n};',
        to: '    ToolboxIcon, UserCircleIcon, Users, UsersTwoIcon,\n    // Cafeteria platform: the Cafeteria Management group and the platform administration.\n    ActivityIcon, CalendarDateIcon, CalendarMarkIcon, ConfettiIcon, CupHotIcon, FileChartIcon, FileTextIcon, HandMoneyIcon, HandshakeIcon,\n    KeyIcon, LayoutDashboard, PieChartIcon, ReceiptTextIcon, UserBlockIcon, UserPlusIcon, WalletIcon,\n};',
    },
    {
        file: 'app.tsx',
        from: 'globalThis.route = ziggyRoute;',
        to: [
            '// Cafeteria platform: a route that stays in EUISIS is not in this route list; link to nothing instead of throwing.',
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

function listPhp(dir) {
    const files = [];
    for (const entry of fs.readdirSync(dir, { withFileTypes: true })) {
        const file = path.join(dir, entry.name);
        if (entry.isDirectory()) files.push(...listPhp(file));
        else if (entry.name.endsWith('.php')) files.push(file);
    }
    return files;
}

function copyBackendFile(from, to, namespace, replace) {
    let source = fs.readFileSync(path.join(euisis, from), 'utf8');
    if (namespace) source = source.replace(/^namespace [^;]+;/m, `namespace ${namespace};`);
    for (const [search, replacement] of replace) {
        if (!source.includes(search)) {
            console.error(`Replacement in ${from} no longer applies: EUISIS changed "${search.trim()}"`);
            process.exit(1);
        }
        source = source.replace(search, replacement);
    }
    const target = path.join(backend, to ?? from);
    fs.mkdirSync(path.dirname(target), { recursive: true });
    fs.writeFileSync(target, source);
}

if (!fs.existsSync(path.join(sourceJs, 'app.tsx'))) {
    console.error(`No EUISIS checkout at ${euisis}`);
    process.exit(1);
}

follow(path.join(sourceJs, 'app.tsx'));
for (const page of PAGES) {
    const file = path.join(sourceJs, 'Pages', `${page}.tsx`);
    if (!fs.existsSync(file)) {
        console.error(`No EUISIS page ${page}`);
        process.exit(1);
    }
    follow(file);
}

// The generated route list belongs to the backend; keep it across syncs.
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
    const target = path.join(backend, 'lang', locale);
    fs.rmSync(target, { recursive: true, force: true });
    copyTree(path.join(euisis, 'lang', locale), target);
}
// The cafeteria backend, as it is in EUISIS.
let backendCopied = 0;
export const BACKEND_DIR_PATHS = [];
for (const { dir, exclude } of BACKEND_DIRS) {
    for (const file of listPhp(path.join(euisis, dir))) {
        const relative = path.relative(euisis, file).replace(/\\/g, '/');
        if (exclude && exclude.test(relative)) continue;
        copyBackendFile(relative, undefined, undefined, BACKEND_DIR_REPLACES[relative] ?? []);
        BACKEND_DIR_PATHS.push(relative);
        backendCopied++;
    }
}
for (const { from, to, namespace, replace = [] } of BACKEND_COPIES) {
    copyBackendFile(from, to, namespace, replace);
    backendCopied++;
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

console.log(`Copied ${copied} files reached from ${PAGES.length} pages, plus ${WHOLE.join(', ')}, styles, @euisis/ui, ${backendCopied} backend files and the backend's lang/.`);
