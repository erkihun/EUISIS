<?php

declare(strict_types=1);

namespace Modules\Entitlements\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Modules\Entitlements\Domain\Models\ServiceType;

class StoreServiceTypeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', ServiceType::class) ?? false;
    }

    public function rules(): array
    {
        return [
            'code' => ['nullable', 'string', 'max:255', 'unique:service_types,code'],
            'name_en' => ['required', 'string', 'max:255'],
            'name_am' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'is_active' => ['required', 'boolean'],
        ];
    }
}
