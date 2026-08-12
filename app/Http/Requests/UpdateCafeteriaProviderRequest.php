<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\CafeteriaProvider;
use Illuminate\Foundation\Http\FormRequest;

class UpdateCafeteriaProviderRequest extends FormRequest
{
    public function authorize(): bool
    {
        $provider = $this->route('cafeteriaProvider');

        return $provider instanceof CafeteriaProvider
            ? ($this->user()?->can('update', $provider) ?? false)
            : false;
    }

    public function rules(): array
    {
        return [
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
}
