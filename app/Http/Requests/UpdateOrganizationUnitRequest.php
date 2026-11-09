<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\OrganizationUnitStatus;
use App\Enums\OrganizationUnitType;
use App\Http\Requests\Concerns\ValidatesOrganizationUnitPlacement;
use App\Models\OrganizationUnit;
use App\Models\OrganizationUnitType as OrganizationUnitTypeModel;
use App\Services\OrganizationUnits\OrganizationUnitTreeService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;
use Illuminate\Validation\Validator;

class UpdateOrganizationUnitRequest extends FormRequest
{
    use ValidatesOrganizationUnitPlacement;

    public function messages(): array
    {
        return [
            'organization_id.in' => __('organization-units.organization_cannot_change'),
        ];
    }

    public function after(): array
    {
        /** @var OrganizationUnit $unit */
        $unit = $this->route('organizationUnit');

        return [
            fn (Validator $validator) => $this->rejectInactiveUnitType($validator, $unit->organization_unit_type_id),
            fn (Validator $validator) => $this->rejectDuplicateSiblingNames($validator, $unit->organization_id, $unit->getKey()),
        ];
    }

    public function authorize(): bool
    {
        $unit = $this->route('organizationUnit');

        return $unit instanceof OrganizationUnit
            ? ($this->user()?->can('update', $unit) ?? false)
            : false;
    }

    public function rules(): array
    {
        /** @var OrganizationUnit $unit */
        $unit = $this->route('organizationUnit');
        $organizationId = $this->input('organization_id', $unit->organization_id);

        return [
            // The form shows the organization read-only: moving a unit would leave
            // its sub-units, positions and assignments in the old organization.
            'organization_id' => ['required', 'uuid', Rule::in([$unit->organization_id])],
            'parent_unit_id' => [
                'nullable',
                'uuid',
                Rule::exists('organization_units', 'id')->where('organization_id', $organizationId)->whereNull('deleted_at'),
                function (string $attribute, mixed $value, callable $fail) use ($unit): void {
                    if ($value === null) {
                        return;
                    }
                    if ($value === $unit->getKey()) {
                        $fail(__('organization-units.cannot_be_own_parent'));

                        return;
                    }
                    /** @var OrganizationUnitTreeService $treeService */
                    $treeService = app(OrganizationUnitTreeService::class);
                    if ($treeService->isDescendantOf(OrganizationUnit::find($value), $unit->getKey())) {
                        $fail(__('organization-units.circular_hierarchy_not_allowed'));
                    }
                },
            ],
            'organization_unit_type_id' => [
                'required',
                'uuid',
                Rule::exists('organization_unit_types', 'id')->whereNull('deleted_at'),
            ],
            'unit_type' => ['nullable', new Enum(OrganizationUnitType::class)],
            'code' => [
                'nullable',
                'string',
                'max:255',
                Rule::unique('organization_units', 'code')
                    ->where('organization_id', $organizationId)
                    ->ignore($unit->getKey()),
            ],
            'name_en' => ['required', 'string', 'max:255'],
            'name_am' => ['required', 'string', 'max:255'],
            'description_en' => ['nullable', 'string'],
            'description_am' => ['nullable', 'string'],
            'status' => ['required', new Enum(OrganizationUnitStatus::class)],
            'is_public_feedback_selectable' => ['nullable', 'boolean'],
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

        // Preserve existing unit_type if nothing resolved
        if (empty($data['unit_type'])) {
            /** @var OrganizationUnit $unit */
            $unit = $this->route('organizationUnit');
            $data['unit_type'] = (string) ($unit->unit_type ?? 'unit');
        }

        return $data;
    }
}
