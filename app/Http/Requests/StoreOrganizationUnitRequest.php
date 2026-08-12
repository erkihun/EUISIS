<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\OrganizationUnitStatus;
use App\Enums\OrganizationUnitType;
use App\Http\Requests\Concerns\ValidatesOrganizationWithinScope;
use App\Models\OrganizationUnit;
use App\Models\OrganizationUnitType as OrganizationUnitTypeModel;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;

class StoreOrganizationUnitRequest extends FormRequest
{
    use ValidatesOrganizationWithinScope;

    public function authorize(): bool
    {
        return $this->user()?->can('create', OrganizationUnit::class) ?? false;
    }

    public function rules(): array
    {
        $organizationId = $this->input('organization_id');

        return [
            'organization_id' => ['required', 'uuid', 'exists:organizations,id', $this->organizationWithinScopeRule()],
            'parent_unit_id' => [
                'nullable',
                'uuid',
                Rule::exists('organization_units', 'id')->where('organization_id', $organizationId)->whereNull('deleted_at'),
            ],
            'organization_unit_type_id' => [
                'nullable',
                'uuid',
                Rule::exists('organization_unit_types', 'id')->whereNull('deleted_at'),
            ],
            'unit_type' => ['nullable', new Enum(OrganizationUnitType::class)],
            'code' => [
                'nullable',
                'string',
                'max:255',
                Rule::unique('organization_units', 'code')->where('organization_id', $organizationId),
            ],
            'name_en' => ['required', 'string', 'max:255'],
            'name_am' => ['nullable', 'string', 'max:255'],
            'description_en' => ['nullable', 'string'],
            'description_am' => ['nullable', 'string'],
            'status' => ['required', new Enum(OrganizationUnitStatus::class)],
            'effective_from' => ['nullable', 'date'],
            'effective_to' => ['nullable', 'date', 'after:effective_from'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
            'metadata' => ['nullable', 'array'],
        ];
    }

    public function validated($key = null, $default = null): array
    {
        $data = parent::validated($key, $default);

        // Sync unit_type string from the selected OrganizationUnitTypeModel code for backward compat
        if (! empty($data['organization_unit_type_id'])) {
            $typeModel = OrganizationUnitTypeModel::find($data['organization_unit_type_id']);
            if ($typeModel !== null && empty($data['unit_type'])) {
                $enumCase = OrganizationUnitType::tryFrom($typeModel->code);
                $data['unit_type'] = $enumCase?->value ?? $typeModel->code;
            }
        }

        // Ensure unit_type has a fallback value if still empty
        if (empty($data['unit_type'])) {
            $data['unit_type'] = 'unit';
        }

        return $data;
    }
}
