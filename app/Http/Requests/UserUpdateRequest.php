<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\Role;
use App\Models\User;
use App\Services\Users\AssignableUserRoleService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UserUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('user')) ?? false;
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('national_id')) {
            $nid = trim((string) $this->input('national_id', '')) ?: null;
            $this->merge([
                'national_id' => $nid,
                'national_id_hash' => $nid !== null ? hash('sha256', $nid) : null,
            ]);
        }
    }

    public function rules(): array
    {
        $userId = $this->route('user')?->id;

        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($userId)],
            'password' => ['nullable', 'string', 'min:8', 'confirmed'],
            'status' => ['in:active,inactive'],
            'roles' => ['array'],
            'roles.*' => ['string', 'exists:roles,name'],
            'profile_photo' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:4096'],
            'national_id' => ['nullable', 'string', 'max:100'],
            'national_id_hash' => [
                'nullable', 'string', 'size:64',
                Rule::unique('users', 'national_id_hash')->ignore($userId),
            ],
            'phone_number' => ['nullable', 'string', 'max:30', 'regex:/^[+\d\s\-()]+$/'],
            'gender' => ['nullable', 'string', 'in:male,female,other,not_specified'],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $actor = $this->user();
                $target = $this->route('user');
                $roleNames = $this->input('roles');

                if ($actor === null || ! $target instanceof User || ! is_array($roleNames)) {
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
