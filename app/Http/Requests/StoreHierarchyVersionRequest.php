<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreHierarchyVersionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('hierarchy-versions.create') ?? false;
    }

    public function rules(): array
    {
        return [
            'version_name' => ['required', 'string', 'max:255', Rule::unique('hierarchy_versions', 'version_name')],
            'effective_from' => ['required', 'date'],
            'effective_to' => ['nullable', 'date', 'after:effective_from'],
            'source_document' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ];
    }
}
