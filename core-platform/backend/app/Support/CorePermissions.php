<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\User;

/**
 * The EUISIS permission names a Core role holds. The screens are the EUISIS
 * screens and check these names (useCan, the Core navigation); the backend
 * checks them through the gate (AppServiceProvider), so `$user->can()` and
 * the `can:` middleware answer as they do in EUISIS.
 */
final class CorePermissions
{
    private const VIEWER = [
        'core.access',
        'organizations.view', 'organization-types.viewAny', 'organization-units.viewAny', 'organization-unit-types.viewAny',
        'hierarchy-versions.viewAny',
        'positions.viewAny', 'position-establishments.viewAny',
        'employees.view',
        'cards.view', 'id-cards.viewAny',
    ];

    private const OFFICER = [
        'organizations.create', 'organizations.update', 'organizations.archive',
        'organization-types.create', 'organization-types.update',
        'organization-units.create', 'organization-units.update', 'organization-units.archive', 'organization-units.restore',
        'organization-unit-types.create', 'organization-unit-types.update', 'organization-unit-types.archive', 'organization-unit-types.restore',
        'positions.create', 'positions.update', 'positions.archive', 'positions.restore',
        'position-establishments.update', 'position-establishments.approve', 'position-establishments.archive',
        'employees.create', 'employees.update', 'employees.manage',
        'card-requests.create', 'card-requests.approve',
        'id-cards.issue', 'id-cards.activate', 'id-cards.revoke', 'cards.manage',
    ];

    private const ADMIN = [
        'users.viewAny', 'users.create', 'users.update', 'users.restore', 'users.manageUsersWithinScope',
        'audit.view',
    ];

    /** Core-wide settings and deleting organizations: a city-wide administrator only (EnsureCoreUser city-admin). */
    private const CITY_ADMIN = ['system-settings.view', 'system-settings.update', 'organizations.delete'];

    /** @return list<string> */
    public static function for(?User $user): array
    {
        if ($user === null || ! $user->is_active) {
            return [];
        }

        return match ($user->role) {
            'core_admin' => [...self::VIEWER, ...self::OFFICER, ...self::ADMIN, ...($user->isCityWide() ? self::CITY_ADMIN : [])],
            'core_officer' => [...self::VIEWER, ...self::OFFICER],
            'core_viewer' => self::VIEWER,
            default => [],
        };
    }

    /** The EUISIS role names shown for a Core role. */
    public static function roleName(User $user): string
    {
        return match ($user->role) {
            'core_admin' => 'Core Administrator',
            'core_officer' => 'Core Officer',
            default => 'Core Viewer',
        };
    }
}
