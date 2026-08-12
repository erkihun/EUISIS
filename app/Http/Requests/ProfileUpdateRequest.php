<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ProfileUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    protected function prepareForValidation(): void
    {
        // Strip spaces from national_id so XXXX XXXX XXXX XXXX → 16 raw digits
        $rawNid = preg_replace('/\s+/', '', trim((string) ($this->input('national_id') ?? '')));
        $nid = filled($rawNid) ? $rawNid : null;

        // Strip spaces/dashes from phone but keep leading +
        $rawPhone = preg_replace('/[\s\-()]/', '', trim((string) ($this->input('phone_number') ?? '')));
        $phone = filled($rawPhone) ? $rawPhone : null;

        $this->merge([
            'national_id' => $nid,
            'national_id_hash' => $nid !== null ? hash('sha256', $nid) : null,
            'phone_number' => $phone,
            'gender' => filled($this->input('gender')) ? $this->input('gender') : null,
        ]);
    }

    public function rules(): array
    {
        $userId = $this->user()?->id;

        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => [
                'required',
                'string',
                'lowercase',
                'email',
                'max:255',
                Rule::unique(User::class)->ignore($userId),
            ],
            'profile_photo' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:4096'],
            'national_id' => ['nullable', 'string', 'regex:/^\d{16}$/'],
            'national_id_hash' => [
                'nullable', 'string', 'size:64',
                Rule::unique('users', 'national_id_hash')->ignore($userId),
            ],
            'phone_number' => ['nullable', 'string', 'regex:/^\+?[1-9]\d{9,13}$/'],
            'gender' => ['nullable', 'string', Rule::in(['male', 'female'])],
        ];
    }

    public function attributes(): array
    {
        return [
            'profile_photo' => __('profile.profile_photo'),
            'national_id' => __('profile.national_id'),
            'phone_number' => __('profile.phone_number'),
            'gender' => __('profile.gender'),
        ];
    }

    public function messages(): array
    {
        return [
            'profile_photo.image' => __('profile.photo_must_be_image'),
            'profile_photo.max' => __('profile.photo_too_large'),
        ];
    }
}
