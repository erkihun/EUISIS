<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\OrganizationalChangeRequestStatus;
use App\Models\OrganizationalChangeRequest;
use App\Models\User;
use App\Policies\Concerns\DeniesNonAdminUsers;
use App\Services\OrganizationalChange\ChangeRequestScopeService;
use App\Services\OrganizationalChange\ImplementingUnitResolver;

/**
 * Authorization for the change-request workflow.
 *
 * Three separated duties, three separate gates:
 *   requester  -> create / update draft / submit / resubmit / cancel own
 *   reviewer   -> review / request correction / approve / reject
 *   implementer-> view approved / assign / implement / complete
 *
 * Holding one gives none of the others. Nothing here grants master-data
 * permissions: a user who can raise a request still cannot create or edit an
 * organization unit or a position, which OrganizationUnitPolicy and
 * PositionPolicy continue to govern independently.
 */
readonly class OrganizationalChangeRequestPolicy
{
    use DeniesNonAdminUsers;

    public function __construct(
        private ChangeRequestScopeService $scope,
        private ImplementingUnitResolver $implementingUnits,
    ) {}

    public function viewAny(User $user): bool
    {
        return $user->can('organizational-change-requests.view_own')
            || $user->can('organizational-change-requests.view')
            || $user->can('organizational-change-requests.view_approved');
    }

    /**
     * Visibility. This is the IDOR gate: guessing another organization's
     * request id gets a 403, because scope is re-checked against the record
     * rather than against anything the request supplied.
     */
    public function view(User $user, OrganizationalChangeRequest $request): bool
    {
        if ($this->isOwner($user, $request)) {
            return $user->can('organizational-change-requests.view_own')
                || $user->can('organizational-change-requests.view');
        }

        $canSeeOthers = $user->can('organizational-change-requests.view')
            || $user->can('organizational-change-requests.view_approved');

        return $canSeeOthers && $this->inScope($user, $request);
    }

    // ── Requester ───────────────────────────────────────────────────────────

    public function create(User $user): bool
    {
        return $user->can('organizational-change-requests.create');
    }

    public function update(User $user, OrganizationalChangeRequest $request): bool
    {
        return $this->isOwner($user, $request)
            && $request->status->isRequesterEditable()
            && $user->can('organizational-change-requests.update_draft');
    }

    public function submit(User $user, OrganizationalChangeRequest $request): bool
    {
        return $this->isOwner($user, $request)
            && $request->status === OrganizationalChangeRequestStatus::Draft
            && $user->can('organizational-change-requests.submit');
    }

    public function resubmit(User $user, OrganizationalChangeRequest $request): bool
    {
        return $this->isOwner($user, $request)
            && $request->status === OrganizationalChangeRequestStatus::CorrectionRequested
            && $user->can('organizational-change-requests.resubmit');
    }

    public function cancel(User $user, OrganizationalChangeRequest $request): bool
    {
        if (! $request->status->isCancellableByRequester()) {
            return false;
        }

        return $this->isOwner($user, $request)
            && $user->can('organizational-change-requests.cancel_own');
    }

    public function uploadAttachment(User $user, OrganizationalChangeRequest $request): bool
    {
        return $this->isOwner($user, $request)
            && $request->status->isRequesterEditable()
            && $user->can('organizational-change-requests.update_draft');
    }

    // ── Reviewer ────────────────────────────────────────────────────────────

    public function review(User $user, OrganizationalChangeRequest $request): bool
    {
        return $request->status->isAwaitingReview()
            && $user->can('organizational-change-requests.review')
            && $this->inScope($user, $request);
    }

    public function requestCorrection(User $user, OrganizationalChangeRequest $request): bool
    {
        return $request->status->isAwaitingReview()
            && $user->can('organizational-change-requests.request_correction')
            && $this->inScope($user, $request);
    }

    /**
     * Approving your own request is refused unless a distinct permission says
     * otherwise. Reviewers do not hold it; a Super Admin override remains
     * possible and is audited like any other approval.
     */
    public function approve(User $user, OrganizationalChangeRequest $request): bool
    {
        if (! $request->status->isAwaitingReview()) {
            return false;
        }

        if (! $user->can('organizational-change-requests.approve') || ! $this->inScope($user, $request)) {
            return false;
        }

        if ($this->isOwner($user, $request)) {
            return $user->can('organizational-change-requests.approve_own');
        }

        return true;
    }

    public function reject(User $user, OrganizationalChangeRequest $request): bool
    {
        return $request->status->isAwaitingReview()
            && $user->can('organizational-change-requests.reject')
            && $this->inScope($user, $request);
    }

    // ── Implementer ─────────────────────────────────────────────────────────

    public function viewApproved(User $user): bool
    {
        return $user->can('organizational-change-requests.view_approved');
    }

    public function assignImplementation(User $user, OrganizationalChangeRequest $request): bool
    {
        return $user->can('organizational-change-requests.assign_implementation')
            && $this->inScope($user, $request);
    }

    /**
     * Applying the approved change. Requires the implement permission, the
     * organization scope, and — when the request has been assigned to someone
     * — being that person.
     */
    public function implement(User $user, OrganizationalChangeRequest $request): bool
    {
        if (! $request->status->isAwaitingImplementation()) {
            return false;
        }

        return $this->implementingUnits->canImplement($user, $request, $this->scope);
    }

    public function complete(User $user, OrganizationalChangeRequest $request): bool
    {
        return $request->status === OrganizationalChangeRequestStatus::Implemented
            && $user->can('organizational-change-requests.complete')
            && $this->inScope($user, $request);
    }

    public function returnForAmendment(User $user, OrganizationalChangeRequest $request): bool
    {
        return in_array($request->status, [
            OrganizationalChangeRequestStatus::PendingImplementation,
            OrganizationalChangeRequestStatus::ImplementationBlocked,
        ], true)
            && $user->can('organizational-change-requests.implement')
            && $this->inScope($user, $request);
    }

    // ── Helpers ─────────────────────────────────────────────────────────────

    private function isOwner(User $user, OrganizationalChangeRequest $request): bool
    {
        return (int) $request->requested_by === (int) $user->getKey();
    }

    private function inScope(User $user, OrganizationalChangeRequest $request): bool
    {
        return $this->scope->canAccessOrganization($user, $request->organization_id);
    }
}
