<?php

declare(strict_types=1);

namespace App\Http\Requests\UserOrganizationScopes;

use App\Http\Requests\UserOrganizationScopes\Concerns\ValidatesAssignableOrganization;
use App\Models\User;
use App\Models\UserOrganizationScope;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateUserOrganizationScopeRequest extends FormRequest
{
    use ValidatesAssignableOrganization;

    public function authorize(): bool
    {
        $scope = $this->route('scope');
        $target = $this->route('user');

        return $scope instanceof UserOrganizationScope
            && $target instanceof User
            && (string) $scope->user_id === (string) $target->getKey()
            && ($this->user()?->can('assignOrganizationScope', $target) ?? false)
            ? ($this->user()?->can('update', $scope) ?? false)
            : false;
    }

    public function rules(): array
    {
        $scope = $this->route('scope');
        $currentOrganizationId = $scope instanceof UserOrganizationScope
            ? $scope->organization_id
            : null;

        return [
            'organization_id' => [
                Rule::requiredIf(fn () => $this->input('scope_type') !== 'citywide'),
                'nullable',
                'uuid',
                'exists:organizations,id',
                // Keeping the scope's existing organization is allowed even if it
                // has since been deactivated; switching to a different inactive
                // organization (or one outside the actor's scope) is not.
                $this->assignableOrganizationRule($currentOrganizationId),
            ],
            'scope_type' => ['required', 'string', Rule::in(['self', 'subtree', 'citywide', 'service_provider']), $this->assignableScopeTypeRule()],
            'effective_from' => ['nullable', 'date'],
            'effective_to' => ['nullable', 'date', 'after_or_equal:effective_from'],
            'is_active' => ['boolean'],
        ];
    }
}
