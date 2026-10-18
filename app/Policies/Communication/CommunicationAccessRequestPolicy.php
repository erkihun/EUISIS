<?php

declare(strict_types=1);

namespace App\Policies\Communication;

use App\Enums\Communication\AccessRequestStatus;
use App\Models\Communication\CommunicationAccessRequest;
use App\Models\User;

/**
 * Break-glass investigation access. Whoever asks never approves (no
 * self-approval, whatever the role); the Super Admin gate bypass defers to
 * this policy for these records.
 * Permissions are read from roles (checkPermissionTo), not through that bypass.
 */
class CommunicationAccessRequestPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->checkPermissionTo('communication.break_glass.request') || $user->checkPermissionTo('communication.break_glass.approve') || $user->checkPermissionTo('communication.audit');
    }

    public function view(User $user, CommunicationAccessRequest $request): bool
    {
        return $request->requested_by === $user->getKey() || $user->checkPermissionTo('communication.break_glass.approve') || $user->checkPermissionTo('communication.audit');
    }

    public function create(User $user): bool
    {
        return $user->checkPermissionTo('communication.break_glass.request');
    }

    public function decide(User $user, CommunicationAccessRequest $request): bool
    {
        return $request->status === AccessRequestStatus::Requested && $request->requested_by !== $user->getKey()
            && $user->checkPermissionTo('communication.break_glass.approve');
    }

    public function revoke(User $user, CommunicationAccessRequest $request): bool
    {
        return in_array($request->status, [AccessRequestStatus::Approved, AccessRequestStatus::Active], true) && $request->revoked_at === null
            && ($user->checkPermissionTo('communication.break_glass.approve') || $request->requested_by === $user->getKey());
    }
}
