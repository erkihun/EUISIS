<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\ServiceType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateServiceTypeRequest extends FormRequest
{
    public function authorize(): bool
    {
        $serviceType = $this->route('serviceType');

        return $serviceType instanceof ServiceType
            ? ($this->user()?->can('update', $serviceType) ?? false)
            : false;
    }

    public function rules(): array
    {
        /** @var ServiceType $serviceType */
        $serviceType = $this->route('serviceType');

        return [
            'code' => ['required', 'string', 'max:255', Rule::unique('service_types', 'code')->ignore($serviceType->id)],
            'name_en' => ['required', 'string', 'max:255'],
            'name_am' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'is_active' => ['required', 'boolean'],
        ];
    }
}
