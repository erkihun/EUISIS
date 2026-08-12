<?php

declare(strict_types=1);

namespace App\Http\Requests\Concerns;

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
}
