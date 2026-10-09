<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\Role;
use App\Models\User;
use App\Services\Users\AssignableUserRoleService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class AssignRolesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('assignRoles', $this->route('user')) ?? false;
    }

    public function rules(): array
    {
        return [
            'roles' => ['required', 'array'],
            'roles.*' => ['string', 'exists:roles,name'],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $actor = $this->user();
                $target = $this->route('user');
                $roleNames = $this->input('roles', []);

                if ($actor === null || ! $target instanceof User) {
                    return;
                }

                if (app(AssignableUserRoleService::class)->firstUnassignableRole($actor, $roleNames) !== null) {
                    $validator->errors()->add('roles', __('users.cannot_assign_this_role'));

                    return;
                }

                $roleService = app(AssignableUserRoleService::class);
                $hasScopedRole = Role::query()->whereIn('name', $roleNames)->get()
                    ->contains(fn (Role $role): bool => $roleService->isOrganizationScoped($role));
                if ($hasScopedRole && ! $target->organizationScopes()->active()->exists()) {
                    $validator->errors()->add('roles', __('roles.scoped_role_requires_organization'));
                }
            },
        ];
    }
}
