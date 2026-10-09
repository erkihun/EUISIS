<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ValidatesGrantablePermissions;
use App\Models\Role;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class RoleUpdateRequest extends FormRequest
{
    use ValidatesGrantablePermissions;

    protected function prepareForValidation(): void
    {
        $role = $this->route('role');
        $scopeType = $role instanceof Role && $role->scope_type !== null
            ? $role->scope_type->value
            : 'scoped';

        $this->merge(['scope_type' => $this->input('scope_type', $scopeType)]);
    }

    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('role')) ?? false;
    }

    public function rules(): array
    {
        $roleId = $this->route('role')?->id;

        return [
            'name' => ['required', 'string', 'max:255', Rule::unique('roles', 'name')->ignore($roleId)],
            'scope_type' => ['required', Rule::in(['scoped', 'global'])],
            'permissions' => ['array', $this->grantablePermissionsRule()],
            'permissions.*' => ['string', 'exists:permissions,name'],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $actor = $this->user();
                $role = $this->route('role');

                if (($role instanceof Role && $role->isGlobal() || $this->input('scope_type') === 'global')
                    && ! ($actor?->hasAnyRole(['Super Admin', 'System Admin']) ?? false)) {
                    $validator->errors()->add('scope_type', __('roles.cannot_assign_global_role'));
                }

                if ($role instanceof Role && $role->isProtected() && ! ($actor?->hasRole('Super Admin') ?? false)) {
                    $validator->errors()->add('scope_type', __('roles.protected_role_scope_change_forbidden'));
                }
            },
        ];
    }
}
