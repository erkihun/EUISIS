<?php

declare(strict_types=1);

namespace App\Support\Rbac;

use InvalidArgumentException;

/**
 * The one list of default permissions (docs/rbac-architecture.md).
 *
 * Entries live in database/seeders/data/permissions.php (which pulls in the
 * daily-activity, performance and transport files). Every seeder, the role
 * matrix, the discovery test and the migration read them from here, so a
 * permission name exists in exactly one place.
 *
 * A permission says WHAT a user may do. WHERE (organization scope), WHOSE
 * (ownership) and FOR WHOM (team coverage) are enforced by policies,
 * OrganizationScopeService and the module services, never by the name.
 */
final class PermissionCatalog
{
    public const GUARD = 'web';

    /** Module group (the `permissions.group` column) → UI category. */
    public const CATEGORIES = [
        'Dashboard & Reports' => ['dashboard', 'functional-reporting'],
        'Organization & Structure' => [
            'organizations', 'organization-types', 'organization-units', 'organization-unit-types', 'organization-unit-requests',
            'organization-edges', 'hierarchy-versions', 'relationships', 'institution-offices', 'structural-offices',
            'organizational-change-requests', 'positions', 'position-requests', 'position-establishments',
            'grade-levels', 'occupations', 'isic-activities', 'code-rules',
        ],
        'Employee Management' => ['employees', 'transfers', 'vacancy-announcements', 'vacancy-applications', 'grievances'],
        'ID & Verification' => ['id-cards', 'id_card_templates', 'nfc_credentials', 'nfc_terminals', 'nfc_logs'],
        'Daily Activity' => ['daily_activities', 'daily_activity_settings'],
        'Performance Management' => [
            'performance_cycles', 'strategic_goals', 'performance_plans', 'kpis', 'performance_agreements', 'performance_reviews',
            'performance_calibration', 'performance_appeals', 'performance_reports', 'performance_settings',
        ],
        'Service Management' => [
            'service-types', 'entitlements', 'entitlement-rules', 'service-transactions', 'service_feedback', 'public-holidays',
            'cafeteria-providers', 'cafeteria-settings', 'cafeteria-transactions', 'cafeteria-reports', 'cafeteria-ledger', 'cafeteria-exclusions',
            'transport-providers', 'transport-routes', 'transport-vehicles', 'transport-drivers', 'transport-passes',
            'transport-transactions', 'transport-scan', 'transport-reports', 'transport-settings',
        ],
        'Providers & External Applications' => ['service-providers', 'cafeteria-provider-users', 'cafeteria-portal', 'api_management'],
        'Security & Access Control' => ['users', 'user-organization-scopes', 'roles', 'permissions'],
        'Public Site' => [
            'public_site', 'public_home', 'public_announcements', 'public_services', 'public_support', 'public_navigation',
            'public_footer', 'public_branding', 'public_seo', 'public_site_settings',
        ],
        'System Settings' => ['system-settings'],
        'Audit & Monitoring' => ['audit', 'audit-logs', 'recycle-bin'],
    ];

    /** @var array<string, array<string, mixed>>|null */
    private static ?array $entries = null;

    /** @return array<string, array<string, mixed>> name => entry */
    public static function all(): array
    {
        if (self::$entries === null) {
            $entries = [];
            foreach (require database_path('seeders/data/permissions.php') as $entry) {
                if (isset($entries[$entry['name']])) {
                    throw new InvalidArgumentException("Duplicate permission in catalog: {$entry['name']}");
                }
                $entries[$entry['name']] = $entry;
            }
            self::$entries = $entries;
        }

        return self::$entries;
    }

    /** @return list<string> */
    public static function names(): array
    {
        return array_keys(self::all());
    }

    public static function has(string $name): bool
    {
        return isset(self::all()[$name]);
    }

    /**
     * Names under the given module prefixes (e.g. 'transport-routes', 'public_').
     * A prefix ending in "." or "_" matches as written; otherwise it matches the module before the dot.
     *
     * @return list<string>
     */
    public static function matching(string ...$prefixes): array
    {
        return array_values(array_filter(self::names(), static function (string $name) use ($prefixes): bool {
            foreach ($prefixes as $prefix) {
                $needle = str_ends_with($prefix, '.') || str_ends_with($prefix, '_') ? $prefix : $prefix.'.';
                if (str_starts_with($name, $needle)) {
                    return true;
                }
            }

            return false;
        }));
    }

    public static function category(string $group): string
    {
        foreach (self::CATEGORIES as $category => $groups) {
            if (in_array($group, $groups, true)) {
                return $category;
            }
        }

        return 'Other';
    }

    /**
     * Structural problems (empty list = valid): unique dot-notation names,
     * a group that belongs to a category, bilingual labels and descriptions.
     * Defaults to the catalog file; tests pass their own entries.
     *
     * @param  list<array<string, mixed>>|null  $entries
     * @return list<string>
     */
    public static function problems(?array $entries = null): array
    {
        $entries ??= array_values(self::all());
        $problems = [];
        foreach (array_count_values(array_column($entries, 'name')) as $name => $count) {
            if ($count > 1) {
                $problems[] = "{$name}: duplicate name";
            }
        }
        foreach ($entries as $entry) {
            $name = (string) ($entry['name'] ?? '');
            if (! preg_match('/^[a-z][a-z0-9_-]*(\.[a-zA-Z0-9_-]+)+$/', $name)) {
                $problems[] = "{$name}: not dot notation";
            }
            foreach (['group', 'label_en', 'label_am', 'description_en', 'description_am'] as $key) {
                if (trim((string) ($entry[$key] ?? '')) === '') {
                    $problems[] = "{$name}: missing {$key}";
                }
            }
            if (isset($entry['group']) && self::category($entry['group']) === 'Other') {
                $problems[] = "{$name}: group '{$entry['group']}' has no category";
            }
            if (str_starts_with((string) ($entry['description_en'] ?? ''), 'Allows performing the ')) {
                $problems[] = "{$name}: placeholder description";
            }
        }

        return $problems;
    }

    /** For tests that change the data file. */
    public static function flush(): void
    {
        self::$entries = null;
    }
}
