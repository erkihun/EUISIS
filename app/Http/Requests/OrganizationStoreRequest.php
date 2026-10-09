<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\HierarchyVersionStatus;
use App\Enums\OrganizationRelationshipType;
use App\Enums\OrganizationStatus;
use App\Models\HierarchyVersion;
use App\Models\Organization;
use App\Models\OrganizationType;
use App\Services\OrganizationScope\OrganizationScopeService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Enum;
use Illuminate\Validation\Validator;

class OrganizationStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', Organization::class) ?? false;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'name_am' => $this->input('name_am') ?: null,
            'legal_basis_ref' => $this->input('legal_basis_ref') ?: null,
            'effective_from' => $this->input('effective_from') ?: null,
            'effective_to' => $this->input('effective_to') ?: null,
            'parent_organization_id' => $this->input('parent_organization_id') ?: null,
            'hierarchy_version_id' => $this->nullableInput('hierarchy_version_id'),
            'relationship_type' => $this->nullableInput('relationship_type'),
            'branding_primary_color' => $this->input('branding_primary_color') ?: null,
            'branding_secondary_color' => $this->input('branding_secondary_color') ?: null,
        ]);
    }

    public function rules(): array
    {
        $requiresAssignedParent = $this->user() !== null
            && app(OrganizationScopeService::class)->mustCreateUnderAssignedOrganization($this->user());

        return [
            'organization_type_id' => ['required', 'uuid', 'exists:organization_types,id'],
            'code' => ['nullable', 'string', 'max:255', 'unique:organizations,code'],
            'name_en' => ['required', 'string', 'max:255'],
            'name_am' => ['nullable', 'string', 'max:255'],
            'legal_basis_ref' => ['nullable', 'string', 'max:255'],
            'status' => ['required', new Enum(OrganizationStatus::class)],
            'effective_from' => ['nullable', 'date'],
            'effective_to' => ['nullable', 'date', 'after_or_equal:effective_from'],
            'parent_organization_id' => [$requiresAssignedParent ? 'required' : 'nullable', 'uuid', 'exists:organizations,id'],
            'hierarchy_version_id' => ['nullable', 'required_with:parent_organization_id', 'uuid', 'exists:hierarchy_versions,id'],
            'relationship_type' => ['nullable', 'required_with:parent_organization_id', new Enum(OrganizationRelationshipType::class)],
            'logo' => ['nullable', 'file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
            'branding_primary_color' => ['nullable', 'string', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'branding_secondary_color' => ['nullable', 'string', 'regex:/^#[0-9A-Fa-f]{6}$/'],
        ];
    }

    public function messages(): array
    {
        return [
            'parent_organization_id.required' => __('organizations.organizational_admin_child_only'),
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $orgTypeId = $this->string('organization_type_id')->toString();

                if ($orgTypeId !== '') {
                    $childType = OrganizationType::query()->find($orgTypeId);

                    if ($childType !== null && ! $childType->is_active) {
                        $validator->errors()->add('organization_type_id', __('organizations.inactive_organization_type'));
                    }
                }
            },
            function (Validator $validator): void {
                $parentOrganizationId = $this->string('parent_organization_id')->toString();

                $scopeService = app(OrganizationScopeService::class);

                if ($this->user() !== null && $scopeService->mustCreateUnderAssignedOrganization($this->user())) {
                    $canCreateUnderParent = $parentOrganizationId !== ''
                        && $scopeService->canCreateOrganizationUnder(
                            $this->user(),
                            $parentOrganizationId,
                        );

                    if (! $canCreateUnderParent) {
                        $validator->errors()->add(
                            'parent_organization_id',
                            __('organizations.organizational_admin_child_only'),
                        );
                    }
                }

                if ($parentOrganizationId === '') {
                    return;
                }

                $parentOrganization = Organization::query()->find($parentOrganizationId);

                if ($parentOrganization === null) {
                    return;
                }

                if ($parentOrganization->status !== OrganizationStatus::Active) {
                    $validator->errors()->add('parent_organization_id', __('organizations.parent_organization_must_be_active'));
                }

                if (! ($this->user()?->can('createChild', $parentOrganization) ?? false)) {
                    $validator->errors()->add('parent_organization_id', __('organizations.parent_organization_outside_scope'));
                }

                // Validate that child org type allows this parent org type
                $orgTypeId = $this->string('organization_type_id')->toString();

                if ($orgTypeId !== '') {
                    $childType = OrganizationType::query()->find($orgTypeId);
                    $parentType = $parentOrganization->organizationType;

                    if ($childType !== null && $parentType !== null && ! $childType->allowsParentType($parentType)) {
                        $validator->errors()->add('organization_type_id', __('organizations.invalid_parent_organization_type'));
                    }
                }

                $hierarchyVersionId = $this->string('hierarchy_version_id')->toString();

                if ($hierarchyVersionId === '') {
                    return;
                }

                $hierarchyVersion = HierarchyVersion::query()->find($hierarchyVersionId);

                if ($hierarchyVersion === null) {
                    return;
                }

                if ($hierarchyVersion->status !== HierarchyVersionStatus::Draft) {
                    $validator->errors()->add('hierarchy_version_id', __('organizations.draft_hierarchy_version_required'));
                }
            },
        ];
    }

    private function nullableInput(string $key): mixed
    {
        $value = $this->input($key);

        return in_array($value, ['', 'null', 'undefined'], true) ? null : $value;
    }
}
