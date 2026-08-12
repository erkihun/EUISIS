<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\CafeteriaProvider;
use Illuminate\Foundation\Http\FormRequest;

class StoreCafeteriaProviderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', CafeteriaProvider::class) ?? false;
    }

    public function rules(): array
    {
        return [
            'code' => ['required', 'string', 'max:20', 'unique:cafeteria_providers,code', 'regex:/^[A-Z0-9_-]+$/'],
            'name_en' => ['required', 'string', 'max:255'],
            'name_am' => ['nullable', 'string', 'max:255'],
            'organization_id' => ['required', 'uuid', 'exists:organizations,id'],
            'assigned_scope_type' => ['required', 'string', 'in:self,subtree'],
            'contact_person' => ['nullable', 'string', 'max:255'],
            'phone_number' => ['nullable', 'string', 'max:30'],
            'email' => ['nullable', 'email', 'max:255'],
            'location' => ['nullable', 'string', 'max:500'],
            'is_active' => ['boolean'],
        ];
    }

    public function attributes(): array
    {
        return [
            'code' => __('cafeteria.providerCode'),
            'name_en' => __('cafeteria.nameEn'),
            'name_am' => __('cafeteria.nameAm'),
        ];
    }
}
