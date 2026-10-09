<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ValidatesGrantablePermissions;
use App\Models\Role;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class RoleStoreRequest extends FormRequest
{
    use ValidatesGrantablePermissions;

    protected function prepareForValidation(): void
    {
        $this->merge(['scope_type' => $this->input('scope_type', 'scoped')]);
    }

    public function authorize(): bool
    {
        return $this->user()?->can('create', Role::class) ?? false;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255', 'unique:roles,name'],
            'scope_type' => ['required', Rule::in(['scoped', 'global'])],
            'permissions' => ['array', $this->grantablePermissionsRule()],
            'permissions.*' => ['string', 'exists:permissions,name'],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($this->input('scope_type') === 'global'
                    && ! ($this->user()?->hasAnyRole(['Super Admin', 'System Admin']) ?? false)) {
                    $validator->errors()->add('scope_type', __('roles.cannot_assign_global_role'));
                }
            },
        ];
    }
}
