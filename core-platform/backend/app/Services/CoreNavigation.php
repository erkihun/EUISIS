<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\Route;

/**
 * A copy of EUISIS App\Services\Core\CoreNavigation (the `workspace.nav`
 * prop). Pages that stay in EUISIS have no Core route and drop out through
 * Route::has, so the list can be copied again unchanged.
 *
 * The Core workspace's navigation: the master-record pages of the
 * application, grouped the way Core is organized, and nothing else.
 *
 * Every entry opens an existing page, gated by the permission that page
 * checks (any one of them when a page accepts several). The pages re-check
 * permission and organization scope themselves; this only decides what the
 * sidebar offers.
 */
final class CoreNavigation
{
    /**
     * Icon names are NavIcons exports, mapped in the sidebar.
     *
     * @var list<array{label_key: string, groups: list<array{key: string, label_key: string, icon: string, items: list<array{route: string, label_key: string, icon: string, permission: string|list<string>}>}>}>
     */
    private const SECTIONS = [
        [
            'label_key' => 'core.nav.sectionRecords',
            'groups' => [
                [
                    'key' => 'coreOrganization', 'label_key' => 'nav.groupOrganization', 'icon' => 'CityIcon',
                    'items' => [
                        ['route' => 'organizations.index', 'label_key' => 'nav.organizations', 'icon' => 'Building2', 'permission' => 'organizations.view'],
                        ['route' => 'organization-types.index', 'label_key' => 'nav.organizationTypes', 'icon' => 'TagsIcon', 'permission' => 'organization-types.viewAny'],
                        ['route' => 'organization-units.index', 'label_key' => 'nav.organizationUnits', 'icon' => 'GitBranchIcon', 'permission' => 'organization-units.viewAny'],
                        ['route' => 'organization-unit-types.index', 'label_key' => 'nav.organizationUnitTypes', 'icon' => 'BoxesIcon', 'permission' => 'organization-unit-types.viewAny'],
                        ['route' => 'hierarchy-versions.index', 'label_key' => 'nav.hierarchyVersions', 'icon' => 'GitForkIcon', 'permission' => 'hierarchy-versions.viewAny'],
                        ['route' => 'reporting-lines.index', 'label_key' => 'core.nav.relationships', 'icon' => 'BranchingUpIcon', 'permission' => 'relationships.viewAny'],
                    ],
                ],
                [
                    'key' => 'coreChangeRequests', 'label_key' => 'core.nav.groupChangeRequests', 'icon' => 'FilePenIcon',
                    'items' => [
                        ['route' => 'organizational-change-requests.index', 'label_key' => 'nav.changeRequestsMine', 'icon' => 'FilePenIcon', 'permission' => 'organizational-change-requests.view_own'],
                        ['route' => 'organizational-change-requests.review-queue', 'label_key' => 'nav.changeRequestsReview', 'icon' => 'FileSearchIcon', 'permission' => 'organizational-change-requests.review'],
                        ['route' => 'organizational-change-requests.pending-implementation', 'label_key' => 'nav.changeRequestsImplement', 'icon' => 'ToolboxIcon', 'permission' => 'organizational-change-requests.view_approved'],
                        ['route' => 'organizational-change-requests.completed', 'label_key' => 'nav.changeRequestsCompleted', 'icon' => 'FileCheckIcon', 'permission' => 'organizational-change-requests.view_own'],
                    ],
                ],
                [
                    'key' => 'coreWorkforce', 'label_key' => 'core.nav.groupWorkforce', 'icon' => 'Briefcase',
                    'items' => [
                        ['route' => 'positions.index', 'label_key' => 'nav.positions', 'icon' => 'Briefcase', 'permission' => 'positions.viewAny'],
                        ['route' => 'positions.status', 'label_key' => 'nav.newJobPositionsStatus', 'icon' => 'SuitcaseTagIcon', 'permission' => 'positions.viewAny'],
                        ['route' => 'position-establishments.index', 'label_key' => 'nav.positionEstablishments', 'icon' => 'ClipboardTextIcon', 'permission' => 'position-establishments.viewAny'],
                        // Core-only (ADR 0010): grades, levels, occupations, professions, employment types and categories in one place.
                        ['route' => 'core.job-masters', 'label_key' => 'core.nav.jobMasters', 'icon' => 'RankingIcon', 'permission' => 'positions.viewAny'],
                        ['route' => 'grade-levels.index', 'label_key' => 'nav.gradeLevels', 'icon' => 'RankingIcon', 'permission' => 'grade-levels.viewAny'],
                        ['route' => 'occupations.index', 'label_key' => 'nav.occupations', 'icon' => 'AcademicCapIcon', 'permission' => 'occupations.viewAny'],
                        ['route' => 'organization-design.professions.index', 'label_key' => 'core.nav.professions', 'icon' => 'DiplomaIcon', 'permission' => ['occupations.viewAny', 'professions.manage']],
                    ],
                ],
                [
                    'key' => 'coreEmployees', 'label_key' => 'core.nav.groupEmployees', 'icon' => 'UsersTwoIcon',
                    'items' => [
                        ['route' => 'employees.index', 'label_key' => 'nav.employees', 'icon' => 'Users', 'permission' => 'employees.view'],
                        ['route' => 'core.assignments', 'label_key' => 'core.nav.assignments', 'icon' => 'ClipboardListIcon', 'permission' => 'employees.view'],
                        // Core-only (ADR 0009): the duplicate review queue.
                        ['route' => 'core.duplicates', 'label_key' => 'core.nav.duplicateReviews', 'icon' => 'FileSearchIcon', 'permission' => 'employees.duplicates.review'],
                        // Core-only (ADR 0009): business imports of organizations, positions, employees and placements.
                        ['route' => 'core.imports', 'label_key' => 'core.nav.imports', 'icon' => 'ImportIcon', 'permission' => 'imports.view'],
                        ['route' => 'employees.import.create', 'label_key' => 'nav.employeeImport', 'icon' => 'ImportIcon', 'permission' => 'employees.import.view'],
                        ['route' => 'employee-corrections.index', 'label_key' => 'nav.employeeCorrections', 'icon' => 'PenIcon', 'permission' => 'employees.manage'],
                    ],
                ],
                [
                    'key' => 'coreCredentials', 'label_key' => 'core.nav.groupIdentity', 'icon' => 'IdCardIcon',
                    'items' => [
                        ['route' => 'id-cards.index', 'label_key' => 'nav.idCards', 'icon' => 'CreditCard', 'permission' => 'cards.view'],
                        ['route' => 'card-requests.index', 'label_key' => 'nav.cardRequests', 'icon' => 'CardReceiveIcon', 'permission' => 'id-cards.viewAny'],
                        ['route' => 'id-cards.reprint-required', 'label_key' => 'nav.reprintRequired', 'icon' => 'RestartIcon', 'permission' => 'cards.view'],
                        // Card production (ADR 0010): batches on the EUISIS screens, templates on a Core screen.
                        ['route' => 'print-batches.index', 'label_key' => 'nav.printBatches', 'icon' => 'PrinterIcon', 'permission' => 'card_print_batches.view'],
                        ['route' => 'core.card-templates', 'label_key' => 'nav.idCardTemplates', 'icon' => 'PaletteIcon', 'permission' => 'card_templates.view'],
                        ['route' => 'nfc-management.credentials.index', 'label_key' => 'nav.nfcCredentials', 'icon' => 'CardTransferIcon', 'permission' => 'nfc_credentials.view'],
                        // The public checker anyone can use; listed so staff find it, gated like the card pages.
                        ['route' => 'id-checker.index', 'label_key' => 'core.nav.verification', 'icon' => 'QrCodeIcon', 'permission' => 'cards.view'],
                    ],
                ],
            ],
        ],
        [
            'label_key' => 'core.nav.sectionGovernance',
            'groups' => [
                [
                    'key' => 'coreAccess', 'label_key' => 'nav.adminAccess', 'icon' => 'ShieldUserIcon',
                    'items' => [
                        ['route' => 'users.index', 'label_key' => 'nav.users', 'icon' => 'UserCircleIcon', 'permission' => 'users.viewAny'],
                        ['route' => 'roles.index', 'label_key' => 'nav.roles', 'icon' => 'ShieldUserIcon', 'permission' => 'roles.viewAny'],
                        ['route' => 'permissions.index', 'label_key' => 'nav.permissions', 'icon' => 'LockIcon', 'permission' => 'permissions.viewAny'],
                    ],
                ],
                [
                    'key' => 'coreIntegration', 'label_key' => 'core.nav.groupIntegration', 'icon' => 'CodeIcon',
                    'items' => [
                        ['route' => 'core.integration', 'label_key' => 'core.nav.integrationCenter', 'icon' => 'NetworkIcon', 'permission' => ['api_management.view', 'integrations.view']],
                        ['route' => 'api-management.index', 'label_key' => 'nav.apiManagement', 'icon' => 'CodeIcon', 'permission' => 'api_management.view'],
                        ['route' => 'integrations.index', 'label_key' => 'core.nav.eventIntegration', 'icon' => 'NetworkIcon', 'permission' => 'integrations.view'],
                    ],
                ],
                [
                    'key' => 'coreControl', 'label_key' => 'core.nav.groupControl', 'icon' => 'EyeScanIcon',
                    'items' => [
                        ['route' => 'core.audit', 'label_key' => 'core.nav.auditCenter', 'icon' => 'EyeScanIcon', 'permission' => 'audit.view'],
                        // Core-only (ADR 0010): emergency access and the audit trail's integrity.
                        ['route' => 'core.security', 'label_key' => 'core.nav.securityCenter', 'icon' => 'ShieldWarningIcon', 'permission' => ['break_glass.request', 'break_glass.approve', 'audit.integrity.view']],
                        ['route' => 'core.data-quality', 'label_key' => 'core.nav.dataQuality', 'icon' => 'FileSearchIcon', 'permission' => 'audit.view'],
                        ['route' => 'audit-logs.index', 'label_key' => 'nav.auditLogs', 'icon' => 'ClipboardClockIcon', 'permission' => 'audit.view'],
                        ['route' => 'code-rules.index', 'label_key' => 'nav.codeRules', 'icon' => 'HashIcon', 'permission' => 'code-rules.viewAny'],
                    ],
                ],
                [
                    'key' => 'coreSettings', 'label_key' => 'core.nav.settings', 'icon' => 'SettingsIcon',
                    'items' => [
                        ['route' => 'core.settings', 'label_key' => 'core.nav.settings', 'icon' => 'SettingsIcon', 'permission' => 'system-settings.view'],
                    ],
                ],
            ],
        ],
    ];

    /**
     * The sections, groups and pages this person may open; empty groups and
     * sections are left out.
     *
     * @return list<array{label_key: string, groups: list<array{key: string, label_key: string, icon: string, items: list<array{route: string, label_key: string, icon: string}>}>}>
     */
    public function for(User $user): array
    {
        $sections = [];
        foreach (self::SECTIONS as $section) {
            $groups = [];
            foreach ($section['groups'] as $group) {
                $items = [];
                foreach ($group['items'] as $item) {
                    if (Route::has($item['route']) && $this->allows($user, $item['permission'])) {
                        $items[] = ['route' => $item['route'], 'label_key' => $item['label_key'], 'icon' => $item['icon']];
                    }
                }
                if ($items !== []) {
                    $groups[] = ['key' => $group['key'], 'label_key' => $group['label_key'], 'icon' => $group['icon'], 'items' => $items];
                }
            }
            if ($groups !== []) {
                $sections[] = ['label_key' => $section['label_key'], 'groups' => $groups];
            }
        }

        return $sections;
    }

    /** @param  string|list<string>  $permission */
    private function allows(User $user, string|array $permission): bool
    {
        foreach ((array) $permission as $name) {
            if ($user->can($name)) {
                return true;
            }
        }

        return false;
    }
}
