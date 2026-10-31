<?php

declare(strict_types=1);

namespace Modules\Feedback\Domain\Enums;

use App\Models\OrganizationUnit;
use App\Models\Role;
use App\Models\User;

/**
 * Who a routed or assigned public submission goes to (brief §45). Only the
 * handler kinds the platform already models: a named user, an organization
 * unit, or everyone holding a role. A handler is recorded by type and id,
 * with the name snapshotted at assignment time so history survives renames.
 */
enum PublicFeedbackHandlerType: string
{
    case User = 'user';
    case OrganizationUnit = 'organization_unit';
    case Role = 'role';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * Bilingual display names of the handler, or null when it does not exist.
     *
     * @return array{en: string, am: ?string}|null
     */
    public function names(string $handlerId): ?array
    {
        return match ($this) {
            self::User => ($user = User::query()->find($handlerId)) === null
                ? null : ['en' => (string) $user->name, 'am' => null],
            self::OrganizationUnit => ($unit = OrganizationUnit::query()->find($handlerId)) === null
                ? null : ['en' => (string) $unit->name_en, 'am' => $unit->name_am],
            self::Role => ($role = Role::query()->where('guard_name', 'web')->find($handlerId)) === null
                ? null : ['en' => (string) $role->name, 'am' => null],
        };
    }
}
