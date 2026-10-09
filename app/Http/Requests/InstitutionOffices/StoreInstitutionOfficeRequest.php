<?php

declare(strict_types=1);

namespace App\Http\Requests\InstitutionOffices;

use App\Enums\OrganizationRelationshipType;
use App\Enums\OrganizationUnitStatus;
use App\Models\OrganizationUnit;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;
use Illuminate\Validation\Validator;

class StoreInstitutionOfficeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', OrganizationUnit::class) ?? false;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'organization_id' => $this->input('organization_id') ?: $this->input('institution_id'),
            'organization_unit_type_id' => $this->input('organization_unit_type_id') ?: null,
            'parent_unit_id' => $this->input('parent_unit_id') ?: null,
            'code' => $this->input('code') ?: $this->input('office_code') ?: null,
            'functional_reporting_organization_id' => $this->input('functional_reporting_organization_id') ?: null,
            'relationship_type' => $this->input('relationship_type') ?: OrganizationRelationshipType::FunctionalReporting->value,
            'name_am' => $this->input('name_am') ?: null,
            'status' => $this->input('status') ?: OrganizationUnitStatus::Active->value,
        ]);
    }

    public function rules(): array
    {
        return [
            'organization_id' => ['required', 'uuid', 'exists:organizations,id'],
            'organization_unit_type_id' => ['required', 'uuid', Rule::exists('organization_unit_types', 'id')->whereNull('deleted_at')],
            'parent_unit_id' => ['nullable', 'uuid', 'exists:organization_units,id'],
            'functional_reporting_organization_id' => ['nullable', 'uuid', 'exists:organizations,id'],
            'relationship_type' => [
                'nullable',
                Rule::in([
                    OrganizationRelationshipType::FunctionalReporting->value,
                    OrganizationRelationshipType::TechnicalSupervision->value,
                    OrganizationRelationshipType::AdministrativeReporting->value,
                    OrganizationRelationshipType::Coordination->value,
                    OrganizationRelationshipType::Oversight->value,
                    OrganizationRelationshipType::ServiceDelivery->value,
                    OrganizationRelationshipType::BudgetReporting->value,
                    OrganizationRelationshipType::DottedLineReporting->value,
                    OrganizationRelationshipType::Other->value,
                ]),
            ],
            'code' => [
                'nullable',
                'string',
                'max:255',
                Rule::unique('organization_units', 'code')->where('organization_id', $this->input('organization_id')),
            ],
            'name_en' => ['required_without:name_am', 'nullable', 'string', 'max:255'],
            'name_am' => ['nullable', 'string', 'max:255'],
            'status' => ['sometimes', new Enum(OrganizationUnitStatus::class)],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $parentUnitId = $this->string('parent_unit_id')->toString();
                $organizationId = $this->string('organization_id')->toString();

                if ($parentUnitId === '' || $organizationId === '') {
                    return;
                }

                $parentUnit = OrganizationUnit::query()->find($parentUnitId);

                if ($parentUnit !== null && $parentUnit->organization_id !== $organizationId) {
                    $validator->errors()->add(
                        'parent_unit_id',
                        __('organization-units.parent_must_belong_to_same_org'),
                    );
                }
            },
        ];
    }
}
