<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\FieldWorkStatus;
use App\Models\FieldWorkRequest;
use App\Models\User;
use App\Policies\Concerns\DeniesNonAdminUsers;
use App\Services\FieldWork\FieldWorkAccess;

/**
 * Authorization for field work requests, always against the RECORD (the
 * IDOR gate): a request id the user is not entitled to is a 403, whatever
 * else they hold. See FieldWorkAccess for the three visibility routes.
 *
 * Gate::before passes Super Admin through every method here, so the
 * identity rules (requester / supervisor / participant) are enforced again
 * in FieldWorkService.
 */
readonly class FieldWorkRequestPolicy
{
    use DeniesNonAdminUsers;

    public function __construct(private FieldWorkAccess $access) {}

    public function view(User $user, FieldWorkRequest $request): bool
    {
        return $this->access->canView($user, $request);
    }

    public function update(User $user, FieldWorkRequest $request): bool
    {
        return $this->access->isRequester($user, $request)
            && $request->status->isRequesterEditable()
            && $user->can('field_work.edit_own_draft');
    }

    public function submit(User $user, FieldWorkRequest $request): bool
    {
        return $this->access->isRequester($user, $request)
            && $request->status->canTransitionTo(FieldWorkStatus::PendingSupervisorApproval)
            && $user->can('field_work.submit_own');
    }

    public function cancel(User $user, FieldWorkRequest $request): bool
    {
        return $this->access->isRequester($user, $request)
            && $request->status->canTransitionTo(FieldWorkStatus::Cancelled)
            && $request->status !== FieldWorkStatus::InField
            && $user->can('field_work.cancel_own');
    }

    public function approve(User $user, FieldWorkRequest $request): bool
    {
        return $this->access->canDecide($user, $request, 'field_work.approve');
    }

    public function returnForCorrection(User $user, FieldWorkRequest $request): bool
    {
        return $this->access->canDecide($user, $request, 'field_work.return');
    }

    public function reject(User $user, FieldWorkRequest $request): bool
    {
        return $this->access->canDecide($user, $request, 'field_work.reject');
    }

    public function checkIn(User $user, FieldWorkRequest $request): bool
    {
        $participant = $this->access->participantFor($user, $request);

        return $participant !== null
            && $participant->checked_in_at === null
            && in_array($request->status, [FieldWorkStatus::Approved, FieldWorkStatus::InField], true)
            && $user->can('field_work.check_in');
    }

    public function checkOut(User $user, FieldWorkRequest $request): bool
    {
        $participant = $this->access->participantFor($user, $request);

        return $participant !== null
            && $participant->checked_in_at !== null
            && $participant->checked_out_at === null
            && $request->status === FieldWorkStatus::InField
            && $user->can('field_work.check_out');
    }

    public function complete(User $user, FieldWorkRequest $request): bool
    {
        return $this->access->isRequester($user, $request)
            && in_array($request->status, [FieldWorkStatus::Approved, FieldWorkStatus::InField], true)
            && $user->can('field_work.complete');
    }
}
