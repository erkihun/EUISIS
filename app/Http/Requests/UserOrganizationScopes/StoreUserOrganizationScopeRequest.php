<?php

declare(strict_types=1);

namespace App\Http\Requests\UserOrganizationScopes;

use App\Http\Requests\UserOrganizationScopes\Concerns\ValidatesAssignableOrganization;
use App\Models\User;
use App\Models\UserOrganizationScope;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreUserOrganizationScopeRequest extends FormRequest
{
    use ValidatesAssignableOrganization;

    public function authorize(): bool
    {
        $target = $this->route('user');

        return $target instanceof User
            && ($this->user()?->can('assignOrganizationScope', $target) ?? false)
            && ($this->user()?->can('create', UserOrganizationScope::class) ?? false);
    }

    public function rules(): array
    {
        return [
            'organization_id' => [
                Rule::requiredIf(fn () => $this->input('scope_type') !== 'citywide'),
                'nullable',
                'uuid',
                'exists:organizations,id',
                // A brand-new scope may never point at an inactive organization,
                // nor at one outside the actor's own accessible scope.
                $this->assignableOrganizationRule(),
            ],
            'scope_type' => ['required', 'string', Rule::in(['self', 'subtree', 'citywide', 'service_provider']), $this->assignableScopeTypeRule()],
            'effective_from' => ['nullable', 'date'],
            'effective_to' => ['nullable', 'date', 'after_or_equal:effective_from'],
            'is_active' => ['boolean'],
        ];
    }
}
