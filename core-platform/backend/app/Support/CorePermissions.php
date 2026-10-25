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
        // Evidence metadata and the photo (ADR 0009); documents and signatures themselves need more.
        'employee_documents.view', 'employee_photos.view',
    ];

    private const OFFICER = [
        'organizations.create', 'organizations.update', 'organizations.archive',
        'organization-types.create', 'organization-types.update',
        'organization-units.create', 'organization-units.update', 'organization-units.archive', 'organization-units.restore',
        'organization-unit-types.create', 'organization-unit-types.update', 'organization-unit-types.archive', 'organization-unit-types.restore',
        'positions.create', 'positions.update', 'positions.archive', 'positions.restore',
        'position-establishments.update', 'position-establishments.approve', 'position-establishments.archive',
        'employees.create', 'employees.update', 'employees.manage',
        // The verified-employee stage (ADR 0008): officers submit, verify and return; approval is an administrator's.
        'employees.submit', 'employees.verify', 'employees.return',
        // The duplicate review queue (ADR 0009).
        'employees.duplicates.review',
        'employee_documents.upload', 'employee_documents.verify', 'employee_documents.download', 'employee_documents.archive',
        'employee_photos.upload', 'employee_photos.verify',
        'employee_signatures.view', 'employee_signatures.upload', 'employee_signatures.verify',
        'card-requests.create', 'card-requests.approve',
        'id-cards.issue', 'id-cards.activate', 'id-cards.revoke', 'cards.manage',
    ];

    private const ADMIN = [
        'users.viewAny', 'users.create', 'users.update', 'users.restore', 'users.manageUsersWithinScope',
        'employees.approve',
        'audit.view',
    ];

    /** Core-wide settings, the Integration Center and deleting organizations: a city-wide administrator only (EnsureCoreUser city-admin). */
    private const CITY_ADMIN = ['system-settings.view', 'system-settings.update', 'organizations.delete', 'integrations.view', 'api_management.view'];

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

    /** The EUISIS role names shown for a Core role; a city-wide grant (ADR 0008) is named as such. */
    public static function roleName(User $user): string
    {
        $name = match ($user->role) {
            'core_admin' => 'Core Administrator',
            'core_officer' => 'Core Officer',
            default => 'Core Viewer',
        };

        return $user->isCityWide() ? 'City-wide '.$name : $name;
    }
}
