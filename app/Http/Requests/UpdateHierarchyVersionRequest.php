<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\HierarchyVersionStatus;
use App\Models\HierarchyVersion;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateHierarchyVersionRequest extends FormRequest
{
    public function authorize(): bool
    {
        $hierarchyVersion = $this->route('hierarchyVersion');

        return $hierarchyVersion instanceof HierarchyVersion
            && ($this->user()?->can('hierarchy-versions.update') ?? false)
            && $hierarchyVersion->status === HierarchyVersionStatus::Draft;
    }

    public function rules(): array
    {
        /** @var HierarchyVersion|null $hierarchyVersion */
        $hierarchyVersion = $this->route('hierarchyVersion');

        return [
            'version_name' => [
                'required',
                'string',
                'max:255',
                Rule::unique('hierarchy_versions', 'version_name')->ignore($hierarchyVersion?->getKey()),
            ],
            'effective_from' => ['required', 'date'],
            'effective_to' => ['nullable', 'date', 'after:effective_from'],
            'source_document' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ];
    }
}
