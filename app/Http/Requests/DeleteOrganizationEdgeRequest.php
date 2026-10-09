<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\HierarchyVersionStatus;
use App\Models\HierarchyVersion;
use App\Models\OrganizationEdge;
use Illuminate\Foundation\Http\FormRequest;

class DeleteOrganizationEdgeRequest extends FormRequest
{
    public function authorize(): bool
    {
        $hierarchyVersion = $this->route('hierarchyVersion');
        $organizationEdge = $this->route('organizationEdge');

        return $hierarchyVersion instanceof HierarchyVersion
            && $organizationEdge instanceof OrganizationEdge
            && ($this->user()?->can('delete', $organizationEdge) ?? false)
            && $hierarchyVersion->status === HierarchyVersionStatus::Draft;
    }

    public function rules(): array
    {
        return [];
    }
}
