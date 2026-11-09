<?php

declare(strict_types=1);

namespace App\Services\OrganizationalChange;

use App\Enums\OrganizationalChangeRequestType;
use App\Models\OrganizationalChangeRequest;
use App\Models\OrganizationUnit;
use App\Models\Position;
use App\Models\User;
use App\Services\OrganizationScope\OrganizationScopeService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;

/**
 * Server-side scope enforcement for change requests.
 *
 * Nothing here trusts an organization_id that arrived from the browser. Every
 * target — the organization, the parent unit, the position being moved — is
 * re-resolved from the database and checked against the actor's scope before
 * a request is written or acted on.
 */
final readonly class ChangeRequestScopeService
{
    public function __construct(
        private OrganizationScopeService $organizationScope,
    ) {}

    /**
     * Assert the actor may raise a request against this organization.
     *
     * @throws ValidationException
     */
    public function assertCanRequestForOrganization(User $user, ?string $organizationId): string
    {
        if ($organizationId === null || $organizationId === '') {
            throw ValidationException::withMessages([
                'organization_id' => __('organizational-change-requests.errors.organization_required'),
            ]);
        }

        if (! $this->organizationScope->canAccessOrganization($user, $organizationId)) {
            throw ValidationException::withMessages([
                'organization_id' => __('organizational-change-requests.errors.organization_out_of_scope'),
            ]);
        }

        return $organizationId;
    }

    /**
     * Assert a targeted organization unit is inside scope and belongs to the
     * organization the request is filed against.
     *
     * @throws ValidationException
     */
    public function assertUnitInScope(User $user, string $field, ?string $unitId, string $organizationId): ?OrganizationUnit
    {
        if ($unitId === null || $unitId === '') {
            return null;
        }

        $unit = OrganizationUnit::query()->find($unitId);

        if ($unit === null) {
            throw ValidationException::withMessages([
                $field => __('organizational-change-requests.errors.unit_not_found'),
            ]);
        }

        if ($unit->organization_id !== $organizationId) {
            throw ValidationException::withMessages([
                $field => __('organizational-change-requests.errors.unit_other_organization'),
            ]);
        }

        if (! $this->organizationScope->canAccessOrganization($user, $unit->organization_id)) {
            throw ValidationException::withMessages([
                $field => __('organizational-change-requests.errors.unit_out_of_scope'),
            ]);
        }

        return $unit;
    }

    /**
     * Assert a targeted position is inside scope and belongs to the request's
     * organization.
     *
     * @throws ValidationException
     */
    public function assertPositionInScope(User $user, string $field, ?string $positionId, string $organizationId): ?Position
    {
        if ($positionId === null || $positionId === '') {
            return null;
        }

        $position = Position::query()->find($positionId);

        if ($position === null) {
            throw ValidationException::withMessages([
                $field => __('organizational-change-requests.errors.position_not_found'),
            ]);
        }

        if ($position->organization_id !== $organizationId) {
            throw ValidationException::withMessages([
                $field => __('organizational-change-requests.errors.position_other_organization'),
            ]);
        }

        if (! $this->organizationScope->canAccessOrganization($user, $position->organization_id)) {
            throw ValidationException::withMessages([
                $field => __('organizational-change-requests.errors.position_out_of_scope'),
            ]);
        }

        return $position;
    }

    /**
     * Whether the actor holds the type-specific request permission and may
     * exercise it against this organization.
     */
    public function canRequestType(User $user, OrganizationalChangeRequestType $type, ?string $organizationId): bool
    {
        return $this->organizationScope->canExercisePermission($user, $type->requestPermission(), $organizationId);
    }

    /**
     * Limit a query to requests the actor may see.
     *
     * Reviewers and implementers see requests inside their organization scope;
     * a user with only the own-view permission sees only their own. This is
     * what closes the IDOR path on /organizational-change-requests/{id}.
     *
     * @param  Builder<OrganizationalChangeRequest>  $query
     * @return Builder<OrganizationalChangeRequest>
     */
    public function applyVisibilityScope(Builder $query, User $user): Builder
    {
        if ($this->organizationScope->isUnrestricted($user)) {
            return $query;
        }

        $canSeeOthers = $user->can('organizational-change-requests.view')
            || $user->can('organizational-change-requests.view_approved');

        if (! $canSeeOthers) {
            return $query->where('requested_by', $user->getKey());
        }

        $allowed = $this->organizationScope->allowedOrganizationIds($user);

        return $query->where(function (Builder $inner) use ($allowed, $user): void {
            $inner->whereIn('organization_id', $allowed)
                ->orWhere('requested_by', $user->getKey());
        });
    }

    /** Organization ids the actor may file a request against. */
    public function requestableOrganizationIds(User $user): array
    {
        return $this->organizationScope->allowedOrganizationIds($user);
    }

    public function canAccessOrganization(User $user, ?string $organizationId): bool
    {
        return $this->organizationScope->canAccessOrganization($user, $organizationId);
    }

    public function isUnrestricted(User $user): bool
    {
        return $this->organizationScope->isUnrestricted($user);
    }
}
