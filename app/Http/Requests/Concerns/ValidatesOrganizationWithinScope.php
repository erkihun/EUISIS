<?php

declare(strict_types=1);

namespace App\Http\Requests\Concerns;

use App\Models\OrganizationUnit;
use App\Models\User;
use App\Services\OrganizationScope\OrganizationScopeService;
use Closure;

/**
 * Rejects a submitted `organization_id` that falls outside the actor's
 * manage-scope. This is the create-time counterpart to the scope-aware
 * policies (which cover show/update/delete of existing records): a scoped
 * actor — e.g. an Organizational Admin — must not be able to POST a new record
 * whose organization lies outside their assigned scope, which route-model
 * binding on the parent record could otherwise not prevent.
 *
 * Unrestricted actors (Super Admin / City Admin / unscoped staff) pass.
 */
trait ValidatesOrganizationWithinScope
{
    protected function organizationWithinScopeRule(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            if (blank($value)) {
                return; // nullable organization_id (e.g. citywide position)
            }

            /** @var User|null $actor */
            $actor = $this->user();

            if ($actor === null) {
                return;
            }

            if (! app(OrganizationScopeService::class)->canManageWithinScope($actor, (string) $value)) {
                $fail(__('users.access_denied_outside_scope'));
            }
        };
    }

    /**
     * Rejects an `organization_unit_id` that belongs to a different organization
     * than the one submitted, and one whose organization lies outside the
     * actor's scope. `exists:organization_units,id` alone would happily accept a
     * unit owned by another organization.
     *
     * @param  string  $organizationField  request key holding the organization id
     */
    protected function organizationUnitBelongsToOrganizationRule(string $organizationField = 'organization_id'): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($organizationField): void {
            if (blank($value)) {
                return;
            }

            $unit = OrganizationUnit::query()
                ->whereKey((string) $value)
                ->first(['id', 'organization_id']);

            if ($unit === null) {
                return; // `exists` rule reports the missing unit.
            }

            $submittedOrganizationId = $this->input($organizationField);

            if (filled($submittedOrganizationId) && (string) $unit->organization_id !== (string) $submittedOrganizationId) {
                $fail(__('positions.unit_outside_organization'));

                return;
            }

            /** @var User|null $actor */
            $actor = $this->user();

            if ($actor === null) {
                return;
            }

            // Guard the unit's own organization too: when organization_id is
            // omitted, the unit alone determines where the position lands.
            if (! app(OrganizationScopeService::class)->canManageWithinScope($actor, (string) $unit->organization_id)) {
                $fail(__('users.access_denied_outside_scope'));
            }
        };
    }
}
