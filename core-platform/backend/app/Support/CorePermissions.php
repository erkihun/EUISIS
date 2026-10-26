<?php

declare(strict_types=1);

namespace App\Support;

use App\Actions\ManageBreakGlass;
use App\Models\CoreRole;
use App\Models\User;
use App\Services\BreakGlassAccess;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;

/**
 * The Core's granular permissions (ADR 0009). The screens are the EUISIS
 * screens and check these names (useCan, the Core navigation); the backend
 * checks them through the gate (AppServiceProvider), the policies and the
 * actions, so `$user->can()` answers as in EUISIS.
 *
 * Roles live in `core_roles`: the three system roles (viewer, officer,
 * administrator) start with the permissions they always had, and an
 * administrator may change them or add roles. A user's effective permissions
 * are their role's, except that the city-wide permissions below count only
 * for an account whose scope is the whole city. Permission alone is never
 * access: policies add the organization scope and the record's state.
 */
final class CorePermissions
{
    /**
     * Every permission, grouped as the role editor shows them: name => [English, Amharic].
     *
     * @var array<string, array<string, array{0: string, 1: string}>>
     */
    public const CATALOG = [
        'core' => [
            'core.access' => ['Open the Core workspace', 'የዋና መዝገቡን የሥራ ቦታ መክፈት'],
            'core.write' => ['Change master data through the API', 'ዋና መረጃን በኤፒአይ መቀየር'],
            'core.administer' => ['Administer the Core through the API', 'ዋና መዝገቡን በኤፒአይ ማስተዳደር'],
        ],
        'organizations' => [
            'organizations.view' => ['View organizations', 'ተቋማትን ማየት'],
            'organizations.create' => ['Create organizations', 'ተቋማትን መፍጠር'],
            'organizations.update' => ['Edit organizations', 'ተቋማትን ማስተካከል'],
            'organizations.archive' => ['Deactivate organizations', 'ተቋማትን ከአገልግሎት ማውጣት'],
            'organizations.delete' => ['Delete unused organizations', 'ጥቅም ላይ ያልዋሉ ተቋማትን መሰረዝ'],
            'organization-types.viewAny' => ['View organization types', 'የተቋም ዓይነቶችን ማየት'],
            'organization-types.create' => ['Create organization types', 'የተቋም ዓይነቶችን መፍጠር'],
            'organization-types.update' => ['Edit organization types', 'የተቋም ዓይነቶችን ማስተካከል'],
        ],
        'organization_units' => [
            'organization-units.viewAny' => ['View units', 'የሥራ ክፍሎችን ማየት'],
            'organization-units.create' => ['Create units', 'የሥራ ክፍሎችን መፍጠር'],
            'organization-units.update' => ['Edit units', 'የሥራ ክፍሎችን ማስተካከል'],
            'organization-units.archive' => ['Archive units', 'የሥራ ክፍሎችን ወደ ማህደር ማስገባት'],
            'organization-units.restore' => ['Restore units', 'የሥራ ክፍሎችን መመለስ'],
            'organization-unit-types.viewAny' => ['View unit types', 'የሥራ ክፍል ዓይነቶችን ማየት'],
            'organization-unit-types.create' => ['Create unit types', 'የሥራ ክፍል ዓይነቶችን መፍጠር'],
            'organization-unit-types.update' => ['Edit unit types', 'የሥራ ክፍል ዓይነቶችን ማስተካከል'],
            'organization-unit-types.archive' => ['Archive unit types', 'የሥራ ክፍል ዓይነቶችን ወደ ማህደር ማስገባት'],
            'organization-unit-types.restore' => ['Restore unit types', 'የሥራ ክፍል ዓይነቶችን መመለስ'],
        ],
        'hierarchy' => [
            'hierarchy-versions.viewAny' => ['View hierarchy versions', 'የተዋረድ ስሪቶችን ማየት'],
            'hierarchy-versions.manage' => ['Prepare and submit hierarchy drafts', 'የተዋረድ ረቂቆችን ማዘጋጀትና ማቅረብ'],
            'hierarchy-versions.approve' => ['Approve or send back hierarchy versions', 'የተዋረድ ስሪቶችን ማጽደቅ ወይም መመለስ'],
            'hierarchy-versions.publish' => ['Publish approved hierarchy versions', 'የጸደቁ የተዋረድ ስሪቶችን ይፋ ማድረግ'],
        ],
        'positions' => [
            'positions.viewAny' => ['View positions', 'የሥራ መደቦችን ማየት'],
            'positions.create' => ['Create positions', 'የሥራ መደቦችን መፍጠር'],
            'positions.update' => ['Edit positions', 'የሥራ መደቦችን ማስተካከል'],
            'positions.archive' => ['Retire positions', 'የሥራ መደቦችን ከአገልግሎት ማውጣት'],
            'positions.restore' => ['Restore positions', 'የሥራ መደቦችን መመለስ'],
            'position-establishments.viewAny' => ['View establishments', 'የጸደቁ መደቦችን ማየት'],
            'position-establishments.update' => ['Edit establishments', 'የጸደቁ መደቦችን ማስተካከል'],
            'position-establishments.approve' => ['Approve establishments', 'የጸደቁ መደቦችን ማጽደቅ'],
            'position-establishments.archive' => ['Archive establishments', 'የጸደቁ መደቦችን ወደ ማህደር ማስገባት'],
            'positions.approve' => ['Approve draft positions', 'ረቂቅ የሥራ መደቦችን ማጽደቅ'],
            'positions.master.manage' => ['Manage grades, levels, occupations, professions, employment types and categories', 'ደረጃዎችን፣ እርከኖችን፣ የሥራ ዓይነቶችን፣ ሙያዎችን፣ የቅጥር ዓይነቶችንና ምድቦችን ማስተዳደር'],
        ],
        'employees' => [
            'employees.view' => ['View employees', 'ሠራተኞችን ማየት'],
            'employees.create' => ['Register employees', 'ሠራተኞችን መመዝገብ'],
            'employees.update' => ['Edit employee details', 'የሠራተኛ መረጃን ማስተካከል'],
            'employees.manage' => ['Change employment status', 'የቅጥር ሁኔታን መቀየር'],
            'employees.submit' => ['Submit employees for verification', 'ሠራተኞችን ለማረጋገጫ ማቅረብ'],
            'employees.verify' => ['Verify employees', 'ሠራተኞችን ማረጋገጥ'],
            'employees.return' => ['Return employees for correction', 'ሠራተኞችን ለማስተካከያ መመለስ'],
            'employees.approve' => ['Approve employees', 'ሠራተኞችን ማጽደቅ'],
            'employees.duplicates.review' => ['Review possible duplicates', 'ሊደጋገሙ የሚችሉ መዝገቦችን መገምገም'],
        ],
        'employee_documents' => [
            'employee_documents.view' => ['See which documents an employee has', 'የሠራተኛ ሰነዶችን ዝርዝር ማየት'],
            'employee_documents.upload' => ['Upload and replace documents', 'ሰነዶችን መስቀልና መተካት'],
            'employee_documents.verify' => ['Verify or reject documents', 'ሰነዶችን ማረጋገጥ ወይም ውድቅ ማድረግ'],
            'employee_documents.download' => ['Download documents', 'ሰነዶችን ማውረድ'],
            'employee_documents.archive' => ['Archive documents, photos and signatures', 'ሰነዶችን፣ ፎቶዎችንና ፊርማዎችን ወደ ማህደር ማስገባት'],
            'employee_photos.view' => ['See employee photos', 'የሠራተኛ ፎቶዎችን ማየት'],
            'employee_photos.upload' => ['Upload and replace photos', 'ፎቶዎችን መስቀልና መተካት'],
            'employee_photos.verify' => ['Verify or reject photos', 'ፎቶዎችን ማረጋገጥ ወይም ውድቅ ማድረግ'],
            'employee_signatures.view' => ['See employee signatures', 'የሠራተኛ ፊርማዎችን ማየት'],
            'employee_signatures.upload' => ['Upload and replace signatures', 'ፊርማዎችን መስቀልና መተካት'],
            'employee_signatures.verify' => ['Verify or reject signatures', 'ፊርማዎችን ማረጋገጥ ወይም ውድቅ ማድረግ'],
        ],
        'assignments' => [
            'assignments.view' => ['View placements', 'ምደባዎችን ማየት'],
            'assignments.change' => ['Transfer employees and change placements', 'ሠራተኞችን ማዛወርና ምደባዎችን መቀየር'],
        ],
        'cards' => [
            'cards.view' => ['View ID cards', 'የመታወቂያ ካርዶችን ማየት'],
            'id-cards.viewAny' => ['View card requests', 'የካርድ ጥያቄዎችን ማየት'],
            'cards.manage' => ['Work on the card lifecycle', 'በካርድ የሕይወት ዑደት ላይ መሥራት'],
            'card-requests.create' => ['Request cards', 'ካርድ መጠየቅ'],
            'card-requests.verify' => ['Verify card requests', 'የካርድ ጥያቄዎችን ማረጋገጥ'],
            'card-requests.approve' => ['Approve, reject or cancel card requests', 'የካርድ ጥያቄዎችን ማጽደቅ፣ ውድቅ ማድረግ ወይም መሰረዝ'],
            'id-cards.print' => ['Print cards', 'ካርዶችን ማተም'],
            'id-cards.issue' => ['Issue cards', 'ካርዶችን መስጠት'],
            'id-cards.activate' => ['Activate cards', 'ካርዶችን ማንቃት'],
            'id-cards.replace' => ['Report lost or damaged cards and replace them', 'የጠፉ ወይም የተጎዱ ካርዶችን ሪፖርት ማድረግና መተካት'],
            'id-cards.suspend' => ['Suspend and reinstate cards', 'ካርዶችን ማገድና መመለስ'],
            'id-cards.revoke' => ['Revoke cards', 'ካርዶችን መሻር'],
            'cards.quality_check' => ['Check printed cards before issue', 'የታተሙ ካርዶችን ከመሰጠታቸው በፊት መመርመር'],
            'cards.reprint' => ['Reprint cards (with a reason)', 'ካርዶችን እንደገና ማተም (ከምክንያት ጋር)'],
            'card_templates.view' => ['View card templates', 'የካርድ ቅጾችን ማየት'],
            'card_templates.manage' => ['Design and activate card templates', 'የካርድ ቅጾችን መንደፍና ሥራ ላይ ማዋል'],
            // The EUISIS ID card template designer (the screens); card_templates.* is the print record.
            'id_card_templates.view' => ['View ID card templates', 'የመታወቂያ ካርድ ቅጾችን ማየት'],
            'id_card_templates.create' => ['Create ID card templates', 'የመታወቂያ ካርድ ቅጾችን መፍጠር'],
            'id_card_templates.update' => ['Change ID card templates', 'የመታወቂያ ካርድ ቅጾችን መቀየር'],
            'id_card_templates.delete' => ['Delete ID card templates', 'የመታወቂያ ካርድ ቅጾችን መሰረዝ'],
            'id_card_templates.set_default' => ['Choose the default ID card template', 'ነባሪውን የመታወቂያ ካርድ ቅጽ መምረጥ'],
            'card_print_batches.view' => ['View print batches', 'የሕትመት ስብስቦችን ማየት'],
            'card_print_batches.manage' => ['Prepare and run print batches', 'የሕትመት ስብስቦችን ማዘጋጀትና ማካሄድ'],
            'digital_credentials.view' => ['View card credentials', 'የካርድ የማረጋገጫ መለያዎችን ማየት'],
            'digital_credentials.issue' => ['Issue card credentials', 'የካርድ የማረጋገጫ መለያዎችን መስጠት'],
            'digital_credentials.rotate' => ['Replace card credentials', 'የካርድ የማረጋገጫ መለያዎችን መተካት'],
            'digital_credentials.revoke' => ['Revoke card credentials', 'የካርድ የማረጋገጫ መለያዎችን መሰረዝ'],
        ],
        'imports' => [
            'imports.view' => ['View imports', 'የገቢ ፋይሎችን ማየት'],
            'imports.create' => ['Upload imports', 'የገቢ ፋይሎችን መስቀል'],
            'imports.approve' => ['Approve imports', 'የገቢ ፋይሎችን ማጽደቅ'],
        ],
        'integrations' => [
            'integrations.view' => ['Open the Integration Center', 'የትስስር ማዕከልን መክፈት'],
            'integrations.manage' => ['Manage API clients and webhooks', 'የኤፒአይ ደንበኞችንና ዌብሁኮችን ማስተዳደር'],
            'api_management.view' => ['View API usage', 'የኤፒአይ አጠቃቀምን ማየት'],
        ],
        'administration' => [
            'users.viewAny' => ['View accounts', 'መለያዎችን ማየት'],
            'users.create' => ['Create accounts', 'መለያዎችን መፍጠር'],
            'users.update' => ['Edit accounts and their scope', 'መለያዎችንና ወሰናቸውን ማስተካከል'],
            'users.restore' => ['Reactivate accounts', 'መለያዎችን እንደገና ማንቃት'],
            'users.manageUsersWithinScope' => ['Manage accounts within one\'s organizations', 'በራስ ተቋማት ውስጥ ያሉ መለያዎችን ማስተዳደር'],
            'roles.viewAny' => ['View roles', 'ሚናዎችን ማየት'],
            'roles.manage' => ['Create and change roles', 'ሚናዎችን መፍጠርና መቀየር'],
            'audit.view' => ['View the audit trail', 'የኦዲት መዝገቡን ማየት'],
            'system-settings.view' => ['View Core settings', 'የዋና መዝገብ ቅንብሮችን ማየት'],
            'system-settings.update' => ['Change Core settings', 'የዋና መዝገብ ቅንብሮችን መቀየር'],
            'audit.integrity.view' => ['See the audit integrity status', 'የኦዲት መዝገብ ታማኝነት ሁኔታን ማየት'],
            'audit.integrity.verify' => ['Seal and verify the audit trail', 'የኦዲት መዝገቡን ማተምና ማረጋገጥ'],
            'break_glass.request' => ['Request emergency access', 'የአደጋ ጊዜ መዳረሻ መጠየቅ'],
            'break_glass.approve' => ['Approve emergency access of others', 'የሌሎችን የአደጋ ጊዜ መዳረሻ ማጽደቅ'],
            'break_glass.revoke' => ['End emergency access', 'የአደጋ ጊዜ መዳረሻን ማቋረጥ'],
        ],
        // P4/P5 (ADR 0011)
        'code_rules' => [
            'code-rules.viewAny' => ['View code rules', 'የኮድ ደንቦችን ማየት'],
            'code-rules.view' => ['Open a code rule', 'የኮድ ደንብን መክፈት'],
            'code-rules.create' => ['Create code rules', 'የኮድ ደንቦችን መፍጠር'],
            'code-rules.update' => ['Edit code rules', 'የኮድ ደንቦችን ማስተካከል'],
            'code-rules.archive' => ['Archive code rules', 'የኮድ ደንቦችን ወደ መዝገብ ማስገባት'],
            'code-rules.restore' => ['Restore code rules', 'የኮድ ደንቦችን መመለስ'],
            'code-rules.viewDeleted' => ['View archived code rules', 'የተመዘገቡ የኮድ ደንቦችን ማየት'],
            'code-rules.preview' => ['Preview generated codes', 'የሚመነጩ ኮዶችን ቅድመ እይታ'],
            'code-rules.generate' => ['Generate codes from rules', 'ከደንቦች ኮድ ማመንጨት'],
            'code-rules.viewSequences' => ['View code sequences', 'የኮድ ተከታታይ ቁጥሮችን ማየት'],
            'code-rules.resetSequence' => ['Reset code sequences', 'የኮድ ተከታታይ ቁጥሮችን ዳግም ማስጀመር'],
        ],
        'oversight' => [
            'data_quality.view' => ['See data-quality issues', 'የመረጃ ጥራት ችግሮችን ማየት'],
            'data_quality.manage' => ['Work on data-quality issues', 'የመረጃ ጥራት ችግሮችን መከታተል'],
            'data_quality.accept_risk' => ['Accept a data-quality risk', 'የመረጃ ጥራት ስጋትን መቀበል'],
            'reports.view' => ['View Core reports', 'የዋና መዝገብ ሪፖርቶችን ማየት'],
            'reports.export' => ['Export Core reports', 'የዋና መዝገብ ሪፖርቶችን ማውጣት'],
            'reports.export_sensitive' => ['Include personal contact details in exports', 'በሚወጡ ሪፖርቶች ውስጥ የግል መገኛ መረጃን ማካተት'],
            'system_health.view' => ['See system health, queues and backups', 'የሥርዓቱን ጤና፣ ወረፋዎችንና ባካፖችን ማየት'],
            'queue.manage' => ['Retry or remove failed background jobs', 'ያልተሳኩ የጀርባ ሥራዎችን እንደገና መሞከር ወይም ማስወገድ'],
            'security_dashboard.view' => ['See the security dashboard', 'የደህንነት ዳሽቦርዱን ማየት'],
        ],
    ];

    /** Permissions that count only for an account whose scope is the whole city. */
    public const CITYWIDE_ONLY = [
        'system-settings.view', 'system-settings.update', 'organizations.delete',
        'integrations.view', 'integrations.manage', 'api_management.view',
        'hierarchy-versions.manage', 'hierarchy-versions.approve', 'hierarchy-versions.publish',
        'roles.manage', 'card_templates.manage', 'digital_credentials.rotate', 'audit.integrity.verify',
        'break_glass.approve', 'break_glass.revoke', 'positions.master.manage',
        'system_health.view', 'queue.manage', 'security_dashboard.view',
    ];

    /** Permissions only a city-wide holder of roles.manage may put in a role. */
    public const SENSITIVE = [
        ...self::CITYWIDE_ONLY,
        'core.administer', 'users.create', 'users.update', 'users.restore', 'users.manageUsersWithinScope', 'roles.viewAny',
        'employee_signatures.view', 'employee_signatures.upload', 'employee_signatures.verify', 'audit.view',
        'audit.integrity.view', 'cards.reprint', 'reports.export_sensitive', 'data_quality.accept_risk',
    ];

    private const VIEWER = [
        'core.access',
        'organizations.view', 'organization-types.viewAny', 'organization-units.viewAny', 'organization-unit-types.viewAny',
        'hierarchy-versions.viewAny',
        'positions.viewAny', 'position-establishments.viewAny',
        'employees.view', 'assignments.view',
        'cards.view', 'id-cards.viewAny',
        'employee_documents.view', 'employee_photos.view',
        'card_templates.view', 'card_print_batches.view', 'digital_credentials.view', 'id_card_templates.view',
        'data_quality.view', 'reports.view',
    ];

    private const OFFICER = [
        'core.write',
        'organizations.create', 'organizations.update', 'organizations.archive',
        'organization-types.create', 'organization-types.update',
        'organization-units.create', 'organization-units.update', 'organization-units.archive', 'organization-units.restore',
        'organization-unit-types.create', 'organization-unit-types.update', 'organization-unit-types.archive', 'organization-unit-types.restore',
        'positions.create', 'positions.update', 'positions.archive', 'positions.restore',
        'position-establishments.update', 'position-establishments.approve', 'position-establishments.archive',
        'employees.create', 'employees.update', 'employees.manage', 'employees.submit', 'employees.verify', 'employees.return', 'employees.duplicates.review',
        'employee_documents.upload', 'employee_documents.verify', 'employee_documents.download', 'employee_documents.archive',
        'employee_photos.upload', 'employee_photos.verify',
        'employee_signatures.view', 'employee_signatures.upload', 'employee_signatures.verify',
        'assignments.change',
        'cards.manage', 'card-requests.create', 'card-requests.verify', 'card-requests.approve',
        'id-cards.print', 'id-cards.issue', 'id-cards.activate', 'id-cards.replace', 'id-cards.suspend', 'id-cards.revoke',
        'imports.view', 'imports.create',
        'positions.approve', 'cards.quality_check', 'card_print_batches.manage', 'digital_credentials.issue', 'digital_credentials.revoke',
        'break_glass.request',
        'data_quality.manage', 'reports.export',
        'code-rules.viewAny', 'code-rules.view', 'code-rules.preview', 'code-rules.generate',
    ];

    private const ADMIN = [
        'core.administer',
        'users.viewAny', 'users.create', 'users.update', 'users.restore', 'users.manageUsersWithinScope',
        'roles.viewAny', 'roles.manage',
        'employees.approve', 'audit.view', 'imports.approve',
        'system-settings.view', 'system-settings.update', 'organizations.delete',
        'integrations.view', 'integrations.manage', 'api_management.view',
        'hierarchy-versions.manage', 'hierarchy-versions.approve', 'hierarchy-versions.publish',
        // P3 (ADR 0010)
        'positions.master.manage', 'cards.reprint', 'card_templates.manage', 'digital_credentials.rotate',
        'id_card_templates.create', 'id_card_templates.update', 'id_card_templates.delete', 'id_card_templates.set_default',
        'audit.integrity.view', 'audit.integrity.verify', 'break_glass.approve', 'break_glass.revoke',
        // P4/P5 (ADR 0011)
        'data_quality.accept_risk', 'reports.export_sensitive', 'system_health.view', 'queue.manage', 'security_dashboard.view',
        // Code Rules (the EUISIS screen; the Core numbers from an active rule, the settings formats otherwise)
        'code-rules.create', 'code-rules.update', 'code-rules.archive', 'code-rules.restore', 'code-rules.viewDeleted', 'code-rules.viewSequences', 'code-rules.resetSequence',
    ];

    /**
     * The system roles as first seeded: key => [English name, Amharic name, permissions].
     *
     * @return array<string, array{0: string, 1: string, 2: list<string>}>
     */
    public static function systemRoles(): array
    {
        return [
            'core_viewer' => ['Core Viewer', 'የዋና መዝገብ ተመልካች', self::VIEWER],
            'core_officer' => ['Core Officer', 'የዋና መዝገብ ባለሙያ', [...self::VIEWER, ...self::OFFICER]],
            'core_admin' => ['Core Administrator', 'የዋና መዝገብ አስተዳዳሪ', [...self::VIEWER, ...self::OFFICER, ...self::ADMIN]],
        ];
    }

    /** @return list<string> every permission the catalog knows */
    public static function all(): array
    {
        return array_merge(...array_map(fn (array $group): array => array_keys($group), array_values(self::CATALOG)));
    }

    /** @return list<string> the permissions a user holds now */
    public static function for(?User $user): array
    {
        if ($user === null || ! $user->is_active) {
            return [];
        }
        $permissions = self::rolePermissions((string) $user->role);
        // Break-glass (ADR 0010): an approved grant in force adds its permissions; a city-wide grant counts as city-wide.
        $grant = BreakGlassAccess::for($user);
        if ($grant !== null) {
            $permissions = array_values(array_unique([...$permissions, ...array_intersect((array) $grant->permissions, ManageBreakGlass::grantable())]));
        }

        return $user->isCityWide() || $grant?->scope_type === User::SCOPE_CITYWIDE ? $permissions : array_values(array_diff($permissions, self::CITYWIDE_ONLY));
    }

    /** @return list<string> a role's permissions (cached; flushed on every role change) */
    public static function rolePermissions(string $role): array
    {
        return self::roles()[$role]['permissions'] ?? [];
    }

    public static function roleHas(string $role, string $permission): bool
    {
        return in_array($permission, self::rolePermissions($role), true);
    }

    /** @return list<string> the keys of the roles holding a permission */
    public static function rolesWith(string $permission): array
    {
        return array_keys(array_filter(self::roles(), fn (array $role): bool => in_array($permission, $role['permissions'], true)));
    }

    /** Whether the user's role holds anything beyond reading (MFA's officer category, ADR 0008). */
    public static function changesData(User $user): bool
    {
        return array_diff(self::for($user), self::VIEWER) !== [];
    }

    /** The role name shown for a user; a city-wide grant (ADR 0008) is named as such. */
    public static function roleName(User $user, ?string $locale = null): string
    {
        $role = self::roles()[(string) $user->role] ?? null;
        $am = ($locale ?? app()->getLocale()) === 'am';
        $name = $role === null ? (string) $user->role : (string) ($am && $role['name_am'] ? $role['name_am'] : $role['name_en']);

        return $user->isCityWide() ? ($am ? 'የመላው ከተማ '.$name : 'City-wide '.$name) : $name;
    }

    public static function flush(): void
    {
        Cache::forget(self::cacheKey());
        Cache::forget(self::CACHE_KEY);
        app()->forgetInstance(self::CACHE_KEY);
    }

    private const CACHE_KEY = 'core.roles';

    /**
     * The cache entry is keyed by the catalog, so a release whose catalog differs never
     * reads another release's entry (rc2 rehearsal: a rollback and fix-forward left the
     * viewers without a permission the new release had granted, for the entry's lifetime).
     */
    private static function cacheKey(): string
    {
        return self::CACHE_KEY.'.'.substr(sha1(implode(',', self::all())), 0, 12);
    }

    /**
     * Read once per request (the container), and from the cache between requests.
     *
     * @return array<string, array{name_en: string, name_am: ?string, permissions: list<string>}>
     */
    private static function roles(): array
    {
        if (app()->bound(self::CACHE_KEY)) {
            return app(self::CACHE_KEY);
        }
        $catalog = self::all();
        try {
            $roles = Cache::remember(self::cacheKey(), 600, fn (): array => CoreRole::query()->with('permissions:core_role_id,permission')->get()
                ->mapWithKeys(fn (CoreRole $role): array => [$role->key => [
                    'name_en' => $role->name_en,
                    'name_am' => $role->name_am,
                    // A permission no longer in the catalog grants nothing.
                    'permissions' => array_values(array_intersect($role->permissions->pluck('permission')->all(), $catalog)),
                ]])->all());
        } catch (QueryException) {
            return []; // before the roles table exists (fresh install mid-migration): nothing is granted
        }
        app()->instance(self::CACHE_KEY, $roles);

        return $roles;
    }
}
