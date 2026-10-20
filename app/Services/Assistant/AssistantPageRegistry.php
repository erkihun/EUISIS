<?php

declare(strict_types=1);

namespace App\Services\Assistant;

/**
 * Route name → module key (docs/assistant/context-and-tools.md, A-3). The
 * module key selects the page guidance in `lang/{en,am}/assistant.php` (`pages.*`,
 * `modules.*`) and the record types a page may declare. Prefixes are
 * checked longest first; anything unmatched is the `general` module, which
 * still gets general help — never an error.
 */
final class AssistantPageRegistry
{
    public const GENERAL = 'general';

    /** @var array<string, string> route-name prefix → module */
    private const PREFIXES = [
        'employee.' => 'my_portal',
        'profile.' => 'profile',
        'dashboard' => 'dashboard',
        'employees.' => 'employees',
        'employee-assignments.' => 'employees',
        'correction-requests.' => 'employees',
        'organizations.' => 'organizations',
        'organization-units.' => 'organizations',
        'hierarchy-versions.' => 'organizations',
        'reporting-lines.' => 'organizations',
        'organization-types.' => 'organizations',
        'organization-design.' => 'organization_design',
        'positions.' => 'positions',
        'position-establishments.' => 'positions',
        'position-services.' => 'positions',
        'vacancies.' => 'positions',
        'transfers.' => 'transfers',
        'performance.' => 'performance',
        'assessments.' => 'performance',
        'daily-activities.' => 'daily_activity',
        'field-work.' => 'field_work',
        'grievances.' => 'grievances',
        'discipline.' => 'discipline',
        'administrative-tribunal.' => 'discipline',
        'court-cases.' => 'discipline',
        'service-feedback.' => 'service_feedback',
        'service-submissions.' => 'service_feedback',
        'public-feedback.' => 'service_feedback',
        'id-cards.' => 'id_cards',
        'card-requests.' => 'id_cards',
        'print-batches.' => 'id_cards',
        'nfc.' => 'id_cards',
        'situation-room.' => 'situation_room',
        'intelligence.' => 'intelligence',
        'system-settings.' => 'system_settings',
        'api-management.' => 'system_settings',
        'users.' => 'users',
        'roles.' => 'users',
        'permissions.' => 'users',
        'cafeteria.' => 'cafeteria',
        'transport.' => 'transport',
        'communication.' => 'communication',
        'support.' => 'support',
        'functional-reporting.' => 'reports',
        'audit-logs.' => 'system_settings',
    ];

    /** @var array<string, list<string>> module → record types a page may declare */
    private const RECORD_TYPES = [
        'employees' => ['employee'],
        'my_portal' => [],
    ];

    /** @var list<string> modules whose figures the business tool may be asked about from that page */
    public const ANALYTIC_MODULES = ['dashboard', 'employees', 'organizations', 'positions', 'transfers', 'performance', 'daily_activity', 'service_feedback', 'situation_room', 'intelligence'];

    public static function module(?string $routeName, string $path = ''): string
    {
        $name = (string) $routeName;
        if ($name === '' && str_starts_with($path, '/my-portal')) {
            return 'my_portal';
        }
        $best = null;
        foreach (self::PREFIXES as $prefix => $module) {
            if (($name === $prefix || str_starts_with($name, $prefix)) && ($best === null || strlen($prefix) > strlen($best[0]))) {
                $best = [$prefix, $module];
            }
        }

        return $best[1] ?? self::GENERAL;
    }

    public static function hasGuidance(string $module): bool
    {
        return is_array(__('assistant.pages.'.$module)) && $module !== self::GENERAL;
    }

    /** @return list<string> */
    public static function recordTypes(string $module): array
    {
        return self::RECORD_TYPES[$module] ?? [];
    }

    public static function label(string $module): string
    {
        $label = __('assistant.modules.'.$module);

        return is_string($label) ? $label : __('assistant.modules.general');
    }
}
