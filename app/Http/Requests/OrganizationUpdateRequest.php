<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\HierarchyVersionStatus;
use App\Enums\OrganizationStatus;
use App\Http\Requests\Concerns\ValidatesOrganizationProfile;
use App\Models\HierarchyVersion;
use App\Models\Organization;
use App\Models\OrganizationEdge;
use App\Models\OrganizationType;
use App\Rules\PublicContactPhoneNumber;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;
use Illuminate\Validation\Validator;

class OrganizationUpdateRequest extends FormRequest
{
    use ValidatesOrganizationProfile;

    public function authorize(): bool
    {
        $organization = $this->route('organization');

        return $this->user()?->can('update', $organization) ?? false;
    }

    protected function prepareForValidation(): void
    {
        $this->normalizeOrganizationProfile();

        $this->merge([
            'name_am' => $this->input('name_am') ?: null,
            'legal_basis_ref' => $this->input('legal_basis_ref') ?: null,
            'effective_from' => $this->input('effective_from') ?: null,
            'effective_to' => $this->input('effective_to') ?: null,
            'branding_primary_color' => $this->input('branding_primary_color') ?: null,
            'branding_secondary_color' => $this->input('branding_secondary_color') ?: null,
        ]);

        // Only a form that carries the field may change it; leaving it out keeps the number.
        if ($this->has('public_contact_phone')) {
            $this->merge(['public_contact_phone' => $this->input('public_contact_phone') ?: null]);
        }
    }

    public function rules(): array
    {
        $organization = $this->route('organization');
        $organizationId = $organization?->id;

        // Archiving has its own action and a merger needs the body it merged
        // into, so the form keeps those statuses only when they are already set.
        $statuses = [
            OrganizationStatus::Draft,
            OrganizationStatus::Active,
            OrganizationStatus::Inactive,
            OrganizationStatus::Dissolved,
        ];

        if ($organization?->status instanceof OrganizationStatus && ! in_array($organization->status, $statuses, true)) {
            $statuses[] = $organization->status;
        }

        return [
            'organization_type_id' => ['required', 'uuid', 'exists:organization_types,id'],
            'code' => ['required', 'string', 'max:255', Rule::unique('organizations', 'code')->ignore($organizationId)],
            'name_en' => ['required', 'string', 'max:255'],
            'name_am' => ['required', 'string', 'max:255'],
            'legal_basis_ref' => ['nullable', 'string', 'max:255'],
            'status' => ['required', (new Enum(OrganizationStatus::class))->only($statuses)],
            'effective_from' => ['nullable', 'date'],
            'effective_to' => ['nullable', 'date', 'after_or_equal:effective_from'],
            'logo' => ['nullable', 'file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
            'remove_logo' => ['nullable', 'boolean'],
            'branding_primary_color' => ['nullable', 'string', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'branding_secondary_color' => ['nullable', 'string', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'public_contact_phone' => ['sometimes', 'nullable', 'string', 'max:32', new PublicContactPhoneNumber],
            ...$this->organizationProfileRules(),
        ];
    }

    public function after(): array
    {
        return [
            fn (Validator $validator) => $this->rejectDuplicateOrganizationNames($validator, $this->route('organization')?->id),
            fn (Validator $validator) => $this->rejectTypeThatBreaksHierarchy($validator),
            function (Validator $validator): void {
                $organizationTypeId = $this->string('organization_type_id')->toString();

                if ($organizationTypeId === '') {
                    return;
                }

                $organizationType = OrganizationType::query()->find($organizationTypeId);

                if ($organizationType !== null && ! $organizationType->is_active) {
                    $validator->errors()->add('organization_type_id', __('organizations.inactive_organization_type'));
                }
            },
        ];
    }

    /**
     * A new type must still fit every parent and child link the organization
     * has in the published structure and in any draft, the same rule the
     * create form applies to a new link.
     */
    private function rejectTypeThatBreaksHierarchy(Validator $validator): void
    {
        $organization = $this->route('organization');
        $newTypeId = $this->string('organization_type_id')->toString();

        if (! $organization instanceof Organization
            || $newTypeId === ''
            || $newTypeId === $organization->organization_type_id
            || $validator->errors()->has('organization_type_id')) {
            return;
        }

        $newType = OrganizationType::query()->find($newTypeId);

        if ($newType === null) {
            return;
        }

        $versionIds = HierarchyVersion::query()
            ->whereIn('status', [HierarchyVersionStatus::Draft->value, HierarchyVersionStatus::Published->value])
            ->pluck('id');

        $conflicts = OrganizationEdge::query()
            ->whereIn('hierarchy_version_id', $versionIds)
            ->where(fn ($query) => $query
                ->where('child_organization_id', $organization->id)
                ->orWhere('parent_organization_id', $organization->id))
            ->with(['parentOrganization.type', 'childOrganization.type'])
            ->get()
            ->filter(function (OrganizationEdge $edge) use ($organization, $newType): bool {
                if ($edge->child_organization_id === $organization->id) {
                    $parentType = $edge->parentOrganization?->type;

                    return $parentType !== null && ! $newType->allowsParentType($parentType);
                }

                $childType = $edge->childOrganization?->type;

                return $childType !== null && ! $childType->allowsParentType($newType);
            })
            ->map(fn (OrganizationEdge $edge): string => $edge->child_organization_id === $organization->id
                ? (string) $edge->parentOrganization?->name_en
                : (string) $edge->childOrganization?->name_en)
            ->unique()
            ->values();

        if ($conflicts->isNotEmpty()) {
            $validator->errors()->add('organization_type_id', __('organizations.type_conflicts_with_hierarchy', [
                'names' => $conflicts->take(5)->implode(', '),
            ]));
        }
    }
}
