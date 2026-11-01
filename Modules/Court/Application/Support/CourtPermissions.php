<?php

declare(strict_types=1);

namespace Modules\Court\Application\Support;

use Illuminate\Support\Facades\Auth;

/**
 * The court's permission check for whoever is signed in on the current guard
 * (court-ms's global `userHasPermission()` helper).
 */
final class CourtPermissions
{
    public static function check(string $permission): bool
    {
        $user = Auth::user();

        return $user !== null && method_exists($user, 'hasPermission') && (bool) $user->hasPermission($permission);
    }
}
