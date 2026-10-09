<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\HierarchyVersionStatus;
use App\Enums\OrganizationRelationshipType;
use App\Models\HierarchyVersion;
use App\Models\OrganizationEdge;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Enum;

class StoreOrganizationEdgeRequest extends FormRequest
{
    public function authorize(): bool
    {
        $hierarchyVersion = $this->route('hierarchyVersion');

        return $hierarchyVersion instanceof HierarchyVersion
            && ($this->user()?->can('create', [OrganizationEdge::class, $hierarchyVersion]) ?? false)
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
