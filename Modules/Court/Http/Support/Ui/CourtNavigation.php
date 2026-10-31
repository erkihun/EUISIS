<?php

declare(strict_types=1);

namespace Modules\Court\Http\Support\Ui;

use Modules\Court\Domain\Models\Permission;
use Modules\Court\Domain\Models\Role;
use Modules\Court\Domain\Models\CourtStaffMember as User;
use Illuminate\Support\Facades\Route;

/**
 * The court-ms sidebar, sent as the `workspace` prop the EUISIS sidebar draws
 * (EUISIS Modules\Court\Application\Services\Core\CoreNavigation has the same shape). The items,
 * labels and permissions are the Blade sidebar's (components/admin-layout),
 * filtered here by the person's permissions and by the routes that exist;
 * a null permission means every signed-in admin sees it, as in Blade.
 * Labels are court-ms translation keys (`cm.*`, see court:client-lang).
 * Icons are names the EUISIS sidebar maps (AppSidebar CORE_ICONS).
 */
final class CourtNavigation
{
    /** @var list<array{label_key: string, groups: list<array{key: string, label_key: string, icon: string, items: list<array{route: string, label_key: string, icon: string, permission: ?string}>}>}> */
    private const SECTIONS = [
        [
            'label_key' => 'cm.app.case_management',
            'groups' => [
                [
                    'key' => 'courtCases', 'label_key' => 'cm.app.Cases', 'icon' => 'ClipboardListIcon',
                    'items' => [
                        ['route' => 'court.cases.index', 'label_key' => 'cm.app.Cases', 'icon' => 'ClipboardListIcon', 'permission' => 'cases.view'],
                        ['route' => 'court.admin.hearings.index', 'label_key' => 'cm.app.Hearings', 'icon' => 'ClipboardClockIcon', 'permission' => 'cases.view'],
                        ['route' => 'court.recordes.index', 'label_key' => 'cm.app.Records', 'icon' => 'PrinterIcon', 'permission' => 'cases.view'],
                        ['route' => 'court.appeals.index', 'label_key' => 'cm.app.Appeals', 'icon' => 'RestartIcon', 'permission' => 'appeals.view'],
                        ['route' => 'court.applicants.index', 'label_key' => 'cm.app.Applicant', 'icon' => 'Users', 'permission' => 'applicants.view'],
                        ['route' => 'court.lawyers.index', 'label_key' => 'cm.lawyer.register.nav', 'icon' => 'Briefcase', 'permission' => 'applicants.view'],
                        ['route' => 'court.case-types.index', 'label_key' => 'cm.app.case_types', 'icon' => 'TagsIcon', 'permission' => 'cases.types'],
                    ],
                ],
                [
                    'key' => 'courtDecisions', 'label_key' => 'cm.app.Decisions', 'icon' => 'FileCheckIcon',
                    'items' => [
                        ['route' => 'court.decisions.index', 'label_key' => 'cm.app.Decisions', 'icon' => 'FileCheckIcon', 'permission' => 'decision.view'],
                        ['route' => 'court.decision-templates.index', 'label_key' => 'cm.decision_templates.title', 'icon' => 'DiplomaIcon', 'permission' => 'decision.templet.view'],
                    ],
                ],
                [
                    'key' => 'courtInspections', 'label_key' => 'cm.app.case_inspections', 'icon' => 'FileSearchIcon',
                    'items' => [
                        ['route' => 'court.case-inspection-requests.index', 'label_key' => 'cm.case_inspections.requests.index_title', 'icon' => 'EyeScanIcon', 'permission' => 'inspection-requests.manage'],
                        ['route' => 'court.case-inspection-findings.index', 'label_key' => 'cm.case_inspections.findings.index_title', 'icon' => 'FileSearchIcon', 'permission' => 'inspection-findings.manage'],
                    ],
                ],
                [
                    'key' => 'courtReports', 'label_key' => 'cm.app.Reports', 'icon' => 'ClipboardTextIcon',
                    'items' => [
                        ['route' => 'court.reports.index', 'label_key' => 'cm.app.Reports', 'icon' => 'ClipboardTextIcon', 'permission' => 'reports.view'],
                        ['route' => 'court.performance-evaluations.index', 'label_key' => 'cm.app.performance_evaluations', 'icon' => 'RankingIcon', 'permission' => 'performance-evaluations.view'],
                    ],
                ],
            ],
        ],
        [
            'label_key' => 'cm.app.communication',
            'groups' => [
                [
                    'key' => 'courtLetters', 'label_key' => 'cm.app.Letters', 'icon' => 'FilePenIcon',
                    'items' => [
                        ['route' => 'court.letter-templates.index', 'label_key' => 'cm.app.letter_templates', 'icon' => 'DiplomaIcon', 'permission' => 'templates.manage'],
                        ['route' => 'court.letter-categories.index', 'label_key' => 'cm.app.letter_categories', 'icon' => 'TagsIcon', 'permission' => 'templates.manage'],
                        ['route' => 'court.letters.index', 'label_key' => 'cm.app.Letters', 'icon' => 'FilePenIcon', 'permission' => 'cases.edit'],
                        ['route' => 'court.letters.compose', 'label_key' => 'cm.app.compose_letter', 'icon' => 'PenIcon', 'permission' => 'cases.edit'],
                    ],
                ],
                [
                    'key' => 'courtNotices', 'label_key' => 'cm.app.Notifications', 'icon' => 'ClipboardListIcon',
                    'items' => [
                        ['route' => 'court.admin.notifications.index', 'label_key' => 'cm.app.Notifications', 'icon' => 'ClipboardListIcon', 'permission' => null],
                        ['route' => 'court.announcements.index', 'label_key' => 'cm.app.Announcements', 'icon' => 'ClipboardTextIcon', 'permission' => 'announcements.manage'],
                    ],
                ],
            ],
        ],
        [
            'label_key' => 'cm.app.users_access',
            'groups' => [
                [
                    'key' => 'courtAccess', 'label_key' => 'cm.app.user_control', 'icon' => 'ShieldUserIcon',
                    'items' => [
                        ['route' => 'court.users.index', 'label_key' => 'cm.app.Users', 'icon' => 'UserCircleIcon', 'permission' => 'users.manage'],
                        ['route' => 'court.permissions.index', 'label_key' => 'cm.app.Permissions', 'icon' => 'LockIcon', 'permission' => 'permissions.manage'],
                        ['route' => 'court.teams.index', 'label_key' => 'cm.app.Teams', 'icon' => 'UsersTwoIcon', 'permission' => 'teams.manage'],
                        ['route' => 'court.roles.index', 'label_key' => 'cm.app.Roles', 'icon' => 'ShieldUserIcon', 'permission' => 'roles.manage'],
                    ],
                ],
            ],
        ],
        [
            'label_key' => 'cm.app.system_section',
            'groups' => [
                [
                    'key' => 'courtSetup', 'label_key' => 'cm.app.Settings', 'icon' => 'SettingsIcon',
                    'items' => [
                        ['route' => 'court.settings.system.edit', 'label_key' => 'cm.app.System_Settings', 'icon' => 'SettingsIcon', 'permission' => 'settings.manage'],
                        ['route' => 'court.settings.performance-evaluation.index', 'label_key' => 'cm.performance.settings.title', 'icon' => 'RankingIcon', 'permission' => 'settings.manage'],
                        ['route' => 'court.terms.index', 'label_key' => 'cm.app.Terms', 'icon' => 'ClipboardTextIcon', 'permission' => 'settings.manage'],
                        ['route' => 'court.about.index', 'label_key' => 'cm.app.About', 'icon' => 'CityIcon', 'permission' => 'about.manage'],
                        ['route' => 'court.admin.landing.index', 'label_key' => 'court.nav.landing', 'icon' => 'PaletteIcon', 'permission' => 'settings.manage'],
                        ['route' => 'court.admin.audit', 'label_key' => 'cm.app.System_Audit', 'icon' => 'EyeScanIcon', 'permission' => 'audit.view'],
                    ],
                ],
            ],
        ],
    ];

    /** @return list<array{label_key: string, groups: list<array{key: string, label_key: string, icon: string, items: list<array{route: string, label_key: string, icon: string}>}>}> */
    public function for(User $user): array
    {
        // As User::hasPermission: Admin (the active role, or any role without one) passes every check.
        $everything = $user->hasRole('admin');
        $granted = $everything ? [] : array_flip(self::permissionNames($user));
        $sections = [];
        foreach (self::SECTIONS as $section) {
            $groups = [];
            foreach ($section['groups'] as $group) {
                $items = [];
                foreach ($group['items'] as $item) {
                    if (! Route::has($item['route'])) {
                        continue;
                    }
                    if (! $everything && $item['permission'] !== null && ! isset($granted[$item['permission']])) {
                        continue;
                    }
                    $items[] = ['route' => $item['route'], 'label_key' => $item['label_key'], 'icon' => $item['icon']];
                }
                if ($items !== []) {
                    $groups[] = [...$group, 'items' => $items];
                }
            }
            if ($groups !== []) {
                $sections[] = ['label_key' => $section['label_key'], 'groups' => $groups];
            }
        }

        return $sections;
    }

    /**
     * Every permission name the person holds, for the frontend's `can()`, by
     * the same rules as User::hasPermission: the active role when one is set
     * (Admin holds all), otherwise every role the person has.
     *
     * @return list<string>
     */
    public static function permissionNames(?User $user): array
    {
        if ($user === null) {
            return [];
        }
        $all = fn (): array => Permission::query()->orderBy('name')->pluck('name')->all();

        $active = $user->activeRole();
        if ($active instanceof Role) {
            return mb_strtolower((string) $active->name) === 'admin'
                ? $all()
                : $active->loadMissing('permissions')->permissions->pluck('name')->unique()->sort()->values()->all();
        }
        if ($user->hasRole('admin')) {
            return $all();
        }

        return $user->roles()->with('permissions')->get()
            ->flatMap(fn (Role $role) => $role->permissions->pluck('name'))
            ->unique()->sort()->values()->all();
    }
}
