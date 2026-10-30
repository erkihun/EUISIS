<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\ServiceProvider;
use Illuminate\Foundation\Http\FormRequest;

class StoreServiceProviderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', ServiceProvider::class);
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'code' => ['required', 'string', 'max:50', 'unique:service_providers,code'],
            'service_type_id' => ['required', 'uuid', 'exists:service_types,id'],
            'organization_id' => ['nullable', 'uuid', 'exists:organizations,id'],
            'status' => ['required', 'string', 'in:active,inactive,suspended'],
            'is_demo' => ['boolean'],
        ];
    }
}
