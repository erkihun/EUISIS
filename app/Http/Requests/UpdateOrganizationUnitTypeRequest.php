<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\OrganizationUnitType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateOrganizationUnitTypeRequest extends FormRequest
{
    public function authorize(): bool
    {
        $type = $this->route('organizationUnitType');

        return $type instanceof OrganizationUnitType
            ? ($this->user()?->can('update', $type) ?? false)
            : false;
    }

    public function rules(): array
    {
        /** @var OrganizationUnitType $type */
        $type = $this->route('organizationUnitType');

        return [
            'code' => ['nullable', 'string', 'max:64', Rule::unique('organization_unit_types', 'code')->ignore($type->getKey())],
            'prefix' => ['nullable', 'string', 'max:64'],
            'name_en' => ['required', 'string', 'max:255'],
            'name_am' => ['nullable', 'string', 'max:255'],
            'description_en' => ['nullable', 'string', 'max:2000'],
            'description_am' => ['nullable', 'string', 'max:2000'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
            'is_active' => ['boolean'],
            'metadata' => ['nullable', 'array'],
        ];
    }
}
