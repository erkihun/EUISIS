<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Security\Passwords\PasswordPolicy;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreTransportProviderRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        if ($this->input('assigned_scope_type') === 'citywide') {
            $this->merge(['assigned_organization_id' => null]);
        }
    }

    public function authorize(): bool
    {
        return $this->user()?->can('transport-providers.create') ?? false;
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'provider_code' => ['required', 'string', 'max:50', 'unique:providers,provider_code'],
            'name_en' => ['required', 'string', 'max:255'],
            'name_am' => ['nullable', 'string', 'max:255'],
            'assigned_organization_id' => ['nullable', 'uuid', 'exists:organizations,id'],
            'assigned_scope_type' => ['required', Rule::in(['self', 'subtree', 'citywide'])],
            'contact_person' => ['nullable', 'string', 'max:255'],
            'phone_number' => ['nullable', 'string', 'max:50'],
            'email' => ['nullable', 'email', 'max:255'],
            'address' => ['nullable', 'string', 'max:500'],
            'license_number' => ['nullable', 'string', 'max:255'],
            'registration_number' => ['nullable', 'string', 'max:255'],
            'service_area_description_en' => ['nullable', 'string'],
            'service_area_description_am' => ['nullable', 'string'],
            'status' => ['required', Rule::in(['active', 'inactive', 'suspended'])],
            'create_provider_user' => ['sometimes', 'boolean'],
            'user_name' => ['nullable', 'string', 'max:255'],
            'user_email' => ['nullable', 'email', 'max:255', 'unique:provider_users,email'],
            'username' => ['nullable', 'string', 'max:100', 'alpha_dash', 'unique:provider_users,username'],
            // Blank: a generated one-time password. Typed: the central policy.
            'user_password' => $this->filled('user_password')
                ? app(PasswordPolicy::class)->rules(null, $this->only(['user_name', 'user_email', 'username']), confirmed: false)
                : ['nullable'],
        ];
    }
}
