<?php

declare(strict_types=1);

namespace App\Http\Requests\ProviderUsers;

use App\Models\ProviderUser;
use App\Security\Passwords\PasswordPolicy;
use App\Support\ProviderPortal\ProviderUserPermissionCatalog;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Create (POST /provider-users) or edit (PATCH /provider-users/{providerUser})
 * a provider portal account. The provider is chosen once, at creation.
 */
class SaveProviderUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        $account = $this->account();

        return $account !== null
            ? (bool) $this->user()?->can('update', $account)
            : (bool) $this->user()?->can('create', ProviderUser::class);
    }

    protected function prepareForValidation(): void
    {
        $clean = fn (string $key): ?string => filled($this->input($key)) ? trim((string) $this->input($key)) : null;

        $this->merge([
            'email' => $clean('email'),
            'username' => $clean('username'),
            'phone_number' => $clean('phone_number'),
            'service_permissions' => array_values(array_filter((array) $this->input('service_permissions', []), 'is_string')),
        ]);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $account = $this->account();

        $rules = [
            'name' => ['required', 'string', 'max:255'],
            // Deleted accounts keep their sign-in names, so a restore never collides.
            'email' => ['nullable', 'string', 'email', 'max:255', Rule::unique('provider_users', 'email')->ignore($account?->id)],
            // No "@": sign-in treats anything that looks like an email as one.
            'username' => ['nullable', 'string', 'min:3', 'max:100', 'regex:/^[A-Za-z0-9][A-Za-z0-9._-]*$/', Rule::unique('provider_users', 'username')->ignore($account?->id)],
            'phone_number' => ['nullable', 'string', 'max:30', 'regex:/^\+?[0-9][0-9 ().-]{6,28}$/'],
            'provider_role' => ['required', 'string', Rule::in(ProviderUserPermissionCatalog::ROLES)],
            'portal_enabled' => ['required', 'boolean'],
            'service_permissions' => ['array'],
            'service_permissions.*' => ['string', 'distinct', Rule::in(ProviderUserPermissionCatalog::all())],
        ];

        if ($account !== null) {
            return $rules;
        }

        return [
            'provider_id' => ['required', 'uuid', Rule::exists('providers', 'id')->whereNull('deleted_at')],
            ...$rules,
            'status' => ['required', 'string', Rule::in(['active', 'inactive'])],
            // Blank: a generated one-time password. Typed: the central policy.
            'password' => $this->filled('password')
                ? app(PasswordPolicy::class)->rules(null, $this->only(['name', 'email', 'username', 'phone_number']), confirmed: false)
                : ['nullable'],
        ];
    }

    /** @return list<callable> */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if (blank($this->input('email')) && blank($this->input('username'))) {
                    $validator->errors()->add('email', __('provider-users.email_or_username_required'));
                }
            },
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [
            'provider_id' => __('provider-users.attributes.provider'),
            'name' => __('provider-users.attributes.name'),
            'email' => __('provider-users.attributes.email'),
            'username' => __('provider-users.attributes.username'),
            'phone_number' => __('provider-users.attributes.phone_number'),
            'provider_role' => __('provider-users.attributes.role'),
            'service_permissions' => __('provider-users.attributes.permissions'),
            'password' => __('provider-users.attributes.password'),
        ];
    }

    private function account(): ?ProviderUser
    {
        $account = $this->route('providerUser');

        return $account instanceof ProviderUser ? $account : null;
    }
}
