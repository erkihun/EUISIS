<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\HierarchyVersion;
use Illuminate\Foundation\Http\FormRequest;

class HierarchyTreeRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var HierarchyVersion|null $hierarchyVersion */
        $hierarchyVersion = $this->route('hierarchyVersion');

        if ($hierarchyVersion instanceof HierarchyVersion) {
            return $this->user()?->can('viewTree', $hierarchyVersion) ?? false;
        }

        return $this->user()?->can('hierarchy-versions.view') ?? false;
    }

    public function rules(): array
    {
        return [
            'hierarchy_version_id' => ['nullable', 'exists:hierarchy_versions,id'],
            'include_units' => ['nullable', 'boolean'],
            'include_positions' => ['nullable', 'boolean'],
            'include_employees_count' => ['nullable', 'boolean'],
            'include_functional_relationships' => ['nullable', 'boolean'],
            'status' => ['nullable', 'string'],
            'search' => ['nullable', 'string', 'max:255'],
            'include_inactive' => ['nullable', 'boolean'],
        ];
    }
}
