<?php

declare(strict_types=1);

namespace App\Services\OrganizationScope;

/**
 * Decides whether a permission belongs to a module that may legitimately be
 * exercised system-wide, or to an operational module that must always stay
 * inside the actor's organization scope.
 *
 * A global role is not a blanket bypass: it only lifts organization scoping for
 * system modules. Operational data (employees, organizations, id cards, ...)
 * remains scoped no matter which role grants the permission.
 */
readonly class PermissionScopeClassifier
{
    /**
     * Permission prefixes that administer the system itself rather than the
     * organizational data inside it. These may act system-wide.
     *
     * @var array<int, string>
     */
    private const SYSTEM_MODULES = [
        'roles',
        'permissions',
        'audit_logs',
        'audit-logs',
        'code_rules',
        'code-rules',
        'system_settings.security',
    ];

    /**
     * Operational modules. Always organization-scoped, even for a global role.
     *
     * @var array<int, string>
     */
    private const OPERATIONAL_MODULES = [
        'organizations',
        'organization_units',
        'organization-units',
        'positions',
        'employees',
        'id_cards',
        'id-cards',
        'reports',
        'services',
    ];

    /**
     * True when the permission administers the system and may be exercised
     * outside any organization scope.
     */
    public function isSystemPermission(string $permission): bool
    {
        // Operational modules win ties: `reports.*` stays scoped even though a
        // system-ish prefix might otherwise match it.
        if ($this->matchesAny($permission, self::OPERATIONAL_MODULES)) {
            return false;
        }

        return $this->matchesAny($permission, self::SYSTEM_MODULES);
    }

    /**
     * True when the permission touches operational data and must be checked
     * against the actor's organization scope.
     */
    public function isOperationalPermission(string $permission): bool
    {
        return $this->matchesAny($permission, self::OPERATIONAL_MODULES);
    }

    /** @param array<int, string> $modules */
    private function matchesAny(string $permission, array $modules): bool
    {
        $normalized = mb_strtolower(trim($permission));

        foreach ($modules as $module) {
            if ($normalized === $module || str_starts_with($normalized, $module.'.')) {
                return true;
            }
        }

        return false;
    }
}
