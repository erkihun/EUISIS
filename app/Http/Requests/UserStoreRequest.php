<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\Role;
use App\Models\User;
use App\Services\OrganizationScope\OrganizationScopeService;
use App\Services\Users\AssignableUserRoleService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UserStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', User::class) ?? false;
    }

    protected function prepareForValidation(): void
    {
        $roleService = app(AssignableUserRoleService::class);
        $roleIds = $this->input('role_ids');
        $roleNames = $this->input('roles');

        if (is_array($roleIds)) {
            $this->merge(['roles' => $roleService->roleNamesForIds($roleIds)]);
        } elseif (is_array($roleNames)) {
            // Backward-compatible input normalization. Authorization still
            // runs against the resolved role ids and canonical database names.
            $this->merge(['role_ids' => $roleService->roleIdsForNames($roleNames)]);
        }

        $scopeIds = $this->input('organization_scope_ids');
        $organizationId = $this->input('organization_id');

        if (is_array($scopeIds)) {
            $this->merge(['organization_id' => $scopeIds[0] ?? null]);
        } elseif (filled($organizationId)) {
            $this->merge(['organization_scope_ids' => [$organizationId]]);
        }

        if (! $this->filled('scope_type') && (filled($organizationId) || (is_array($scopeIds) && $scopeIds !== []))) {
            $this->merge(['scope_type' => 'self']);
        }

        if ($this->has('national_id')) {
            $nid = trim((string) $this->input('national_id', '')) ?: null;
            // We validate uniqueness against the deterministic hash column
            // because the plaintext national_id is stored encrypted.
            $this->merge([
                'national_id' => $nid,
                'national_id_hash' => $nid !== null ? hash('sha256', $nid) : null,
            ]);
        }
    }

    public function rules(): array
    {
        $actor = $this->user();
        $scopeService = app(OrganizationScopeService::class);
        $selectedRoles = Role::query()->whereIn('id', $this->input('role_ids', []))->get();
        $requiresScope = $selectedRoles->contains(fn (Role $role): bool => $role->isScoped())
            || ($actor !== null && $scopeService->isScopedOrganizationalAdmin($actor));

        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
            'status' => ['in:active,inactive'],
            'roles' => ['array'],
            'roles.*' => ['string', 'exists:roles,name'],
            'role_ids' => [Rule::requiredIf($requiresScope), 'array', $requiresScope ? 'min:1' : 'nullable'],
            'role_ids.*' => ['integer', 'distinct', 'exists:roles,id'],
            'profile_photo' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:4096'],
            'national_id' => ['nullable', 'string', 'max:100'],
            'national_id_hash' => ['nullable', 'string', 'size:64', 'unique:users,national_id_hash'],
            'phone_number' => ['nullable', 'string', 'max:30', 'regex:/^[+\d\s\-()]+$/'],
            'gender' => ['nullable', 'string', 'in:male,female,other,not_specified'],
            'organization_id' => [
                Rule::requiredIf($requiresScope),
                'nullable',
                'uuid',
                'exists:organizations,id',
                function (string $attribute, mixed $value, \Closure $fail) use ($actor, $scopeService): void {
                    if ($actor !== null && filled($value) && ! $scopeService->canAssignUserToScope($actor, (string) $value)) {
                        $fail(__('users.organization_scope_outside_actor_scope'));
                    }
                },
            ],
            'organization_scope_ids' => [Rule::requiredIf($requiresScope), 'array', $requiresScope ? 'min:1' : 'nullable'],
            'organization_scope_ids.*' => [
                'uuid',
                'distinct',
                'exists:organizations,id',
                function (string $attribute, mixed $value, \Closure $fail) use ($actor, $scopeService): void {
                    if ($actor !== null && ! $scopeService->canAssignUserToScope($actor, (string) $value)) {
                        $fail(__('users.organization_scope_outside_actor_scope'));
                    }
                },
            ],
            'scope_type' => [
                Rule::requiredIf($requiresScope),
                'nullable',
                Rule::in($requiresScope ? ['self', 'subtree'] : ['self', 'subtree', 'citywide', 'service_provider']),
            ],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $actor = $this->user();

                if ($actor === null) {
                    return;
                }

                $unassignable = app(AssignableUserRoleService::class)
                    ->firstUnassignableRole($actor, $this->input('roles', []));

                if ($unassignable !== null) {
                    $validator->errors()->add('role_ids', __('users.cannot_assign_this_role'));
                    $validator->errors()->add('roles', __('users.cannot_assign_this_role'));
                }
            },
        ];
    }

    public function messages(): array
    {
        return [
            'organization_id.required' => __('users.organization_scope_required'),
            'organization_scope_ids.required' => __('users.organization_scope_required'),
            'role_ids.required' => __('users.select_role'),
            'role_ids.min' => __('users.select_role'),
        ];
    }
}
