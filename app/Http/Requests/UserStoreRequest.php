<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;

class UserStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', User::class) ?? false;
    }

    protected function prepareForValidation(): void
    {
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
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
            'status' => ['in:active,inactive'],
            'roles' => ['array'],
            'roles.*' => ['string', 'exists:roles,name'],
            'profile_photo' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:4096'],
            'national_id' => ['nullable', 'string', 'max:100'],
            'national_id_hash' => ['nullable', 'string', 'size:64', 'unique:users,national_id_hash'],
            'phone_number' => ['nullable', 'string', 'max:30', 'regex:/^[+\d\s\-()]+$/'],
            'gender' => ['nullable', 'string', 'in:male,female,other,not_specified'],
        ];
    }
}
