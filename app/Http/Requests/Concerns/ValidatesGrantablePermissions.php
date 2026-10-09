<?php

declare(strict_types=1);

namespace App\Http\Requests\Concerns;

use App\Models\User;
use Closure;

/**
 * Ensures an actor can only attach permissions to a role that the actor
 * personally holds. Without this, anyone with `roles.create`/`roles.update`
 * could mint a role carrying permissions they lack (e.g. security settings)
 * and assign it to themselves — a privilege-escalation path.
 *
 * Super Admin is unrestricted (it holds every permission via Gate::before).
 */
trait ValidatesGrantablePermissions
{
    protected function grantablePermissionsRule(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            /** @var User|null $actor */
            $actor = $this->user();

            if ($actor === null || $actor->hasRole('Super Admin')) {
                return;
            }

            if (! is_array($value)) {
                return;
            }

            foreach ($value as $permission) {
                if (! is_string($permission) || $permission === '') {
                    continue;
                }

                if (! $actor->can($permission)) {
                    $fail(__('roles.cannot_grant_unheld_permission', ['permission' => $permission]));

                    return;
                }
            }
        };
    }
}
