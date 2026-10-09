<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Services\OrganizationScope\OrganizationScopeService;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\In;

class StoreOrganizationUnitStructureCopyRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        if ($user === null) {
            return false;
        }

        // User must be able to create organization units (manage target org)
        return $user->can('organization-units.create');
    }

    public function rules(): array
    {
        $sourceOrgId = $this->input('source_organization_id');
        $targetOrgId = $this->input('target_organization_id');

        return [
            'source_organization_id' => ['required', 'uuid', 'exists:organizations,id'],
            'source_unit_id' => [
                'nullable',
                'uuid',
                Rule::exists('organization_units', 'id')
                    ->where('organization_id', $sourceOrgId)
                    ->whereNull('deleted_at'),
            ],
            'target_organization_id' => ['required', 'uuid', 'exists:organizations,id'],
            'target_parent_unit_id' => [
                'nullable',
                'uuid',
                Rule::exists('organization_units', 'id')
                    ->where('organization_id', $targetOrgId)
                    ->whereNull('deleted_at'),
            ],
            'copy_positions' => ['boolean'],
            'copy_functional_relationships' => ['boolean'],
            'name_prefix' => ['nullable', 'string', 'max:50'],
            'name_suffix' => ['nullable', 'string', 'max:50'],
            'status' => ['required', new In(['draft', 'active'])],
            'effective_from' => ['nullable', 'date'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v): void {
            $user = $this->user();

            if ($user === null) {
                return;
            }

            $scopeService = app(OrganizationScopeService::class);

            // User must be able to view the source org
            $sourceOrgId = $this->input('source_organization_id');
            if ($sourceOrgId && ! $scopeService->canAccessOrganization($user, $sourceOrgId)) {
                $v->errors()->add('source_organization_id', __('You do not have access to the source organization.'));
            }

            // User must be able to manage (access) the target org
            $targetOrgId = $this->input('target_organization_id');
            if ($targetOrgId && ! $scopeService->canAccessOrganization($user, $targetOrgId)) {
                $v->errors()->add('target_organization_id', __('You do not have access to the target organization.'));
            }
        });
    }

    protected function prepareForValidation(): void
    {
        $this->mergeIfMissing([
            'copy_positions' => false,
            'copy_functional_relationships' => false,
        ]);
    }
}
