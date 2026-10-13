<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\ServiceClassification;
use App\Models\User;
use App\Policies\Concerns\DeniesNonAdminUsers;

/** City-level reference data (brief §90): everyone in the domain reads it, stewards change it. */
readonly class ServiceClassificationPolicy
{
    use DeniesNonAdminUsers;

    public function viewAny(User $user): bool
    {
        return $user->can('classifications.viewAny') || $user->can('service-catalog.viewAny');
    }

    public function create(User $user): bool
    {
        return $user->can('classifications.manage');
    }

    public function update(User $user, ServiceClassification $classification): bool
    {
        return $user->can('classifications.manage');
    }

    public function import(User $user): bool
    {
        return $user->can('classifications.manage');
    }
}
