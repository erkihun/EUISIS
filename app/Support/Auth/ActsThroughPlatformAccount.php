<?php

declare(strict_types=1);

namespace App\Support\Auth;

use App\Models\User;

/**
 * A module's own view of a signed-in person that is backed by a platform
 * account (the court's staff member, ADR 0020). The person signs in to EUISIS
 * as that account; the module resolves its own record from it per request.
 * Tests that act as such a record sign in as the account.
 */
interface ActsThroughPlatformAccount
{
    public function platformAccount(): User;
}
