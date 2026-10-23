<?php

declare(strict_types=1);

namespace App\Services\Core;

use App\Models\User;
use Illuminate\Support\Facades\Route;

/**
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
                        ['route' => 'reporting-lines.index', 'label_key' => 'nav.reportingLines', 'icon' => 'BranchingUpIcon', 'permission' => 'relationships.viewAny'],
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
                    'key' => 'corePositions', 'label_key' => 'core.nav.groupPositions', 'icon' => 'Briefcase',
                    'items' => [
                        ['route' => 'positions.index', 'label_key' => 'nav.positions', 'icon' => 'Briefcase', 'permission' => 'positions.viewAny'],
                        ['route' => 'positions.status', 'label_key' => 'nav.newJobPositionsStatus', 'icon' => 'SuitcaseTagIcon', 'permission' => 'positions.viewAny'],
                        ['route' => 'position-establishments.index', 'label_key' => 'nav.positionEstablishments', 'icon' => 'ClipboardTextIcon', 'permission' => 'position-establishments.viewAny'],
                        ['route' => 'grade-levels.index', 'label_key' => 'nav.gradeLevels', 'icon' => 'RankingIcon', 'permission' => 'grade-levels.viewAny'],
                        ['route' => 'occupations.index', 'label_key' => 'nav.occupations', 'icon' => 'AcademicCapIcon', 'permission' => 'occupations.viewAny'],
                    ],
                ],
                [
                    'key' => 'coreEmployees', 'label_key' => 'core.nav.groupEmployees', 'icon' => 'UsersTwoIcon',
                    'items' => [
                        ['route' => 'employees.index', 'label_key' => 'nav.employees', 'icon' => 'Users', 'permission' => 'employees.view'],
                        ['route' => 'employees.import.create', 'label_key' => 'nav.employeeImport', 'icon' => 'ImportIcon', 'permission' => 'employees.import.view'],
                        ['route' => 'employee-corrections.index', 'label_key' => 'nav.employeeCorrections', 'icon' => 'PenIcon', 'permission' => 'employees.manage'],
                    ],
                ],
                [
                    'key' => 'coreCredentials', 'label_key' => 'nav.groupIdentity', 'icon' => 'IdCardIcon',
                    'items' => [
                        ['route' => 'id-cards.index', 'label_key' => 'nav.idCards', 'icon' => 'CreditCard', 'permission' => 'cards.view'],
                        ['route' => 'card-requests.index', 'label_key' => 'nav.cardRequests', 'icon' => 'CardReceiveIcon', 'permission' => 'id-cards.viewAny'],
                        ['route' => 'id-cards.reprint-required', 'label_key' => 'nav.reprintRequired', 'icon' => 'RestartIcon', 'permission' => 'cards.view'],
                        ['route' => 'print-batches.index', 'label_key' => 'nav.printBatches', 'icon' => 'PrinterIcon', 'permission' => ['id-cards.createPrintBatch', 'cards.manage']],
                        ['route' => 'id-card-templates.index', 'label_key' => 'nav.idCardTemplates', 'icon' => 'PaletteIcon', 'permission' => 'id_card_templates.view'],
                        ['route' => 'nfc-management.credentials.index', 'label_key' => 'nav.nfcCredentials', 'icon' => 'CardTransferIcon', 'permission' => 'nfc_credentials.view'],
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
                        ['route' => 'api-management.index', 'label_key' => 'nav.apiManagement', 'icon' => 'CodeIcon', 'permission' => 'api_management.view'],
                        ['route' => 'integrations.index', 'label_key' => 'core.nav.eventIntegration', 'icon' => 'NetworkIcon', 'permission' => 'integrations.view'],
                    ],
                ],
                [
                    'key' => 'coreControl', 'label_key' => 'core.nav.groupControl', 'icon' => 'EyeScanIcon',
                    'items' => [
                        ['route' => 'code-rules.index', 'label_key' => 'nav.codeRules', 'icon' => 'HashIcon', 'permission' => 'code-rules.viewAny'],
                        ['route' => 'audit-logs.index', 'label_key' => 'nav.auditLogs', 'icon' => 'ClipboardClockIcon', 'permission' => 'audit.view'],
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
