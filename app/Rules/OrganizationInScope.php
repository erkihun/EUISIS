<?php

declare(strict_types=1);

namespace App\Rules;

use App\Models\User;
use App\Services\OrganizationScope\OrganizationScopeService;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/** The organization id must be one the acting staff user may reach. */
final class OrganizationInScope implements ValidationRule
{
    public function __construct(private readonly ?User $user) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($value === null || $value === '') {
            return;
        }

        if ($this->user === null || ! app(OrganizationScopeService::class)->canAccessOrganization($this->user, (string) $value)) {
            $fail(__('transport.organization_out_of_scope'));
        }
    }
}
