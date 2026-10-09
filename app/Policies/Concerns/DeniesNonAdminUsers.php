<?php

declare(strict_types=1);

namespace App\Policies\Concerns;

use App\Models\User;

/**
 * Adds a before() gate hook that short-circuits all policy methods for any
 * user that is NOT an App\Models\User (e.g. ProviderUser from the provider
 * guard). Without this, PHP throws a TypeError when Laravel passes the wrong
 * user type to a method that is strictly typed to User.
 *
 * Policies that have mixed-type methods (e.g. User|ProviderUser) should
 * override before() and return null for those specific abilities so they can
 * proceed to the correctly typed method.
 */
trait DeniesNonAdminUsers
{
    public function before(mixed $user, string $ability): ?bool
    {
        if (! $user instanceof User) {
            return false;
        }

        return null;
    }
}
