<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\ApiClient;
use App\Models\User;

/** API clients (ADR 0009): city-wide integration managers only; a revoked client stays revoked. */
final class ApiClientPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->is_active && $user->isCityWide() && $user->can('integrations.view');
    }

    public function create(User $user): bool
    {
        return $user->is_active && $user->isCityWide() && $user->can('integrations.manage');
    }

    public function update(User $user, ApiClient $client): bool
    {
        return $this->create($user) && $client->revoked_at === null;
    }
}
