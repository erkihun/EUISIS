<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\Route;

/**
 * The platform's sidebar (the `workspace.nav` prop the EUISIS sidebar renders
 * for a server-sent navigation): the EUISIS Cafeteria Management group, as
 * the EUISIS admin sidebar lists it, then the platform's own administration.
 *
 * Every entry opens an existing page, gated by the permission that page
 * checks. The pages re-check permission and organization scope themselves.
 */
final class CafeteriaNavigation
{
    /**
     * Icon names are NavIcons exports, mapped in the sidebar.
     *
     * @var list<array{label_key: string, groups: list<array{key: string, label_key: string, icon: string, items: list<array{route: string, label_key: string, icon: string, permission: string|list<string>}>}>}>
     */
    private const SECTIONS = [
        [
            'label_key' => 'nav.groupCafeteria',
            'groups' => [
                [
                    'key' => 'cafeteria', 'label_key' => 'nav.groupCafeteria', 'icon' => 'CupHotIcon',
                    'items' => [
                        ['route' => 'cafeteria.dashboard', 'label_key' => 'nav.cafeteriaDashboard', 'icon' => 'LayoutDashboard', 'permission' => 'cafeteria_transactions.view'],
                        ['route' => 'cafeteria.scan', 'label_key' => 'nav.cafeteriaScan', 'icon' => 'QrCodeIcon', 'permission' => 'cafeteria_transactions.scan'],
                        ['route' => 'cafeteria.payees.index', 'label_key' => 'cafeteriaPolicy.navProviders', 'icon' => 'HandshakeIcon', 'permission' => 'cafeteria_providers.viewAny'],
                        ['route' => 'cafeteria.networks.index', 'label_key' => 'cafeteriaPolicy.navNetworks', 'icon' => 'NetworkIcon', 'permission' => 'cafeteria_networks.view'],
                        ['route' => 'cafeteria.providers.index', 'label_key' => 'cafeteriaPolicy.navCafeterias', 'icon' => 'CupHotIcon', 'permission' => 'cafeteria_providers.viewAny'],
                        ['route' => 'cafeteria.access.index', 'label_key' => 'cafeteriaPolicy.navAccess', 'icon' => 'KeyIcon', 'permission' => 'cafeteria_access.view'],
                        ['route' => 'cafeteria.assignments.index', 'label_key' => 'cafeteriaPolicy.navAssignments', 'icon' => 'UserPlusIcon', 'permission' => 'cafeteria_assignments.view'],
                        ['route' => 'cafeteria.employee-exclusions.index', 'label_key' => 'nav.cafeteriaEmployeeExclusions', 'icon' => 'UserBlockIcon', 'permission' => 'cafeteria_employee_exclusions.view'],
                        ['route' => 'cafeteria.policies.index', 'label_key' => 'cafeteriaPolicy.navPolicies', 'icon' => 'FileTextIcon', 'permission' => 'cafeteria_policies.view'],
                        ['route' => 'cafeteria.day-rules.index', 'label_key' => 'cafeteriaPolicy.navWorkingDays', 'icon' => 'CalendarDateIcon', 'permission' => 'cafeteria_day_rules.view'],
                        ['route' => 'cafeteria.special-days.index', 'label_key' => 'nav.cafeteriaSpecialDays', 'icon' => 'CalendarMarkIcon', 'permission' => 'cafeteria_special_days.view'],
                        ['route' => 'cafeteria.holidays.index', 'label_key' => 'cafeteriaPolicy.navHolidays', 'icon' => 'ConfettiIcon', 'permission' => 'public_holidays.viewAny'],
                        ['route' => 'cafeteria.transactions.index', 'label_key' => 'nav.cafeteriaTransactions', 'icon' => 'ReceiptTextIcon', 'permission' => 'cafeteria_transactions.view'],
                        ['route' => 'cafeteria.ledger.index', 'label_key' => 'nav.cafeteriaLedger', 'icon' => 'WalletIcon', 'permission' => 'cafeteria_ledger.view'],
                        ['route' => 'cafeteria.settlements.index', 'label_key' => 'cafeteriaPolicy.navSettlements', 'icon' => 'HandMoneyIcon', 'permission' => 'cafeteria_settlements.view'],
                        ['route' => 'cafeteria.reports.index', 'label_key' => 'nav.cafeteriaReports', 'icon' => 'FileChartIcon', 'permission' => 'cafeteria_reports.view'],
                        ['route' => 'cafeteria.analytics.index', 'label_key' => 'cafeteriaPolicy.navAnalytics', 'icon' => 'PieChartIcon', 'permission' => 'cafeteria_reports.view'],
                        ['route' => 'cafeteria.settings.index', 'label_key' => 'cafeteriaPolicy.navSystemDefaults', 'icon' => 'SettingsIcon', 'permission' => 'cafeteria_settings.view'],
                    ],
                ],
            ],
        ],
        [
            'label_key' => 'core.nav.sectionGovernance',
            'groups' => [
                [
                    'key' => 'cafeteriaAdministration', 'label_key' => 'core.nav.groupControl', 'icon' => 'ShieldUserIcon',
                    'items' => [
                        ['route' => 'users.index', 'label_key' => 'nav.users', 'icon' => 'UserCircleIcon', 'permission' => 'users.viewAny'],
                        ['route' => 'roles.index', 'label_key' => 'nav.roles', 'icon' => 'ShieldUserIcon', 'permission' => 'roles.viewAny'],
                        ['route' => 'audit-logs.index', 'label_key' => 'nav.auditLogs', 'icon' => 'ClipboardListIcon', 'permission' => 'audit.view'],
                        ['route' => 'core.security', 'label_key' => 'core.nav.securityCenter', 'icon' => 'ShieldUserIcon', 'permission' => 'core.access'],
                        ['route' => 'core.integration', 'label_key' => 'core.nav.integrationCenter', 'icon' => 'NetworkIcon', 'permission' => 'integrations.view'],
                        ['route' => 'core.system-health', 'label_key' => 'core.nav.systemHealth', 'icon' => 'ActivityIcon', 'permission' => 'core.access'],
                        ['route' => 'system-settings.index', 'label_key' => 'nav.systemSettings', 'icon' => 'SettingsIcon', 'permission' => 'system-settings.view'],
                    ],
                ],
            ],
        ],
    ];

    /** @return list<array<string, mixed>> the sections this user may open, empty groups dropped */
    public function for(User $user): array
    {
        $sections = [];
        foreach (self::SECTIONS as $section) {
            $groups = [];
            foreach ($section['groups'] as $group) {
                $items = array_values(array_filter($group['items'], function (array $item) use ($user): bool {
                    if (! Route::has($item['route'])) {
                        return false;
                    }
                    foreach ((array) $item['permission'] as $permission) {
                        if ($user->can($permission)) {
                            return true;
                        }
                    }

                    return false;
                }));
                if ($items !== []) {
                    $groups[] = [...$group, 'items' => $items];
                }
            }
            if ($groups !== []) {
                $sections[] = [...$section, 'groups' => $groups];
            }
        }

        return $sections;
    }
}
