<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\HierarchyVersionStatus;
use App\Enums\OrganizationRelationshipType;
use App\Models\HierarchyVersion;
use App\Models\OrganizationEdge;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Enum;

class UpdateOrganizationEdgeRequest extends FormRequest
{
    public function authorize(): bool
    {
        $hierarchyVersion = $this->route('hierarchyVersion');
        $organizationEdge = $this->route('organizationEdge');

        return $hierarchyVersion instanceof HierarchyVersion
            && $organizationEdge instanceof OrganizationEdge
            && ($this->user()?->can('update', $organizationEdge) ?? false)
            && $hierarchyVersion->status === HierarchyVersionStatus::Draft;
    }

    public function rules(): array
    {
        return [
            'parent_organization_id' => ['required', 'exists:organizations,id', 'different:child_organization_id'],
            'child_organization_id' => ['required', 'exists:organizations,id', 'different:parent_organization_id'],
            'relationship_type' => ['required', new Enum(OrganizationRelationshipType::class)],
            'effective_from' => ['nullable', 'date'],
            'effective_to' => ['nullable', 'date', 'after:effective_from'],
        ];
    }
}
