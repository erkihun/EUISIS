<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ValidatesGrantablePermissions;
use Illuminate\Foundation\Http\FormRequest;
use Spatie\Permission\Models\Role;

class RoleStoreRequest extends FormRequest
{
    use ValidatesGrantablePermissions;

    public function authorize(): bool
    {
        return $this->user()?->can('create', Role::class) ?? false;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255', 'unique:roles,name'],
            'permissions' => ['array', $this->grantablePermissionsRule()],
            'permissions.*' => ['string', 'exists:permissions,name'],
        ];
    }
}
