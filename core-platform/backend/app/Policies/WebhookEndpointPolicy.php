<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;
use App\Models\WebhookEndpoint;

/** Webhook endpoints (ADR 0009): city-wide integration managers only. */
final class WebhookEndpointPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->is_active && $user->isCityWide() && $user->can('integrations.view');
    }

    public function create(User $user): bool
    {
        return $user->is_active && $user->isCityWide() && $user->can('integrations.manage');
    }

    public function update(User $user, WebhookEndpoint $webhook): bool
    {
        return $this->create($user);
    }
}
