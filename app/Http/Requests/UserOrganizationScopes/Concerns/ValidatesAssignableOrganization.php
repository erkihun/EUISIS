<?php

declare(strict_types=1);

namespace App\Http\Requests\UserOrganizationScopes\Concerns;

use App\Enums\OrganizationStatus;
use App\Models\Organization;
use App\Models\User;
use App\Services\OrganizationScope\OrganizationScopeService;
use Closure;

/**
 * Shared organization rules for assigning a user organization scope.
 *
 * Two guarantees the frontend cannot be trusted with:
 *  1. An INACTIVE organization may never be newly assigned. (An organization
 *     already carried by the scope being edited is allowed to stay, so an
 *     existing assignment doesn't become unsavable if the org is deactivated.)
 *  2. An actor may only grant access to organizations inside their OWN
 *     accessible scope — Super Admin / City Admin are unrestricted, since
 *     accessibleOrganizationIds() returns every organization for them.
 */
trait ValidatesAssignableOrganization
{
    /**
     * Guards the `scope_type` field: an actor who carries any explicit
     * organization-scope record is a *scoped* admin and may never grant a
     * `citywide` scope, which would escalate the target to every organization.
     * Only Super Admin / City Admin (by role) or a genuinely unscoped staff
     * account (the app-wide "unrestricted default") may grant citywide.
     */
    protected function assignableScopeTypeRule(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            if ($value !== 'citywide') {
                return;
            }

            /** @var User|null $actor */
            $actor = $this->user();

            if ($actor === null) {
                return;
            }

            if ($actor->hasRole('Super Admin') || $actor->hasRole('City Admin')) {
                return;
            }

            // Any existing scope record makes the actor explicitly scoped —
            // regardless of how many organizations it happens to resolve to.
            if ($actor->organizationScopes()->exists()) {
                $fail(__('users.organization_scope_citywide_forbidden'));
            }
        };
    }

    protected function assignableOrganizationRule(?string $currentOrganizationId = null): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($currentOrganizationId): void {
            if (blank($value)) {
                return;
            }

            $organization = Organization::query()->find($value);

            if ($organization === null) {
                return; // the `exists` rule already reports this
            }

            $status = $organization->status instanceof \BackedEnum
                ? $organization->status->value
                : (string) $organization->status;

            $isUnchanged = $currentOrganizationId !== null && $currentOrganizationId === (string) $value;

            if ($status !== OrganizationStatus::Active->value && ! $isUnchanged) {
                $fail(__('users.organization_scope_inactive_organization'));

                return;
            }

            /** @var User|null $actor */
            $actor = $this->user();

            if ($actor === null) {
                return;
            }

            $accessible = app(OrganizationScopeService::class)->accessibleOrganizationIds($actor);

            // An empty set means "unrestricted" everywhere else in the app (see the
            // `isNotEmpty()` guards in the Employee/Organization controllers), so only
            // enforce the boundary for actors who actually carry an explicit scope.
            // Super Admin / City Admin receive every organization id, so they pass.
            if ($accessible->isNotEmpty() && ! $accessible->contains($value)) {
                $fail(__('users.organization_scope_outside_actor_scope'));
            }
        };
    }
}
