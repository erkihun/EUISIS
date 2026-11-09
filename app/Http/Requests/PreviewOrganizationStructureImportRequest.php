<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\Organization;
use Illuminate\Foundation\Http\FormRequest;

class PreviewOrganizationStructureImportRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return $user !== null && $user->can('import', Organization::class);
    }

    protected function prepareForValidation(): void
    {
        // Auto-generation is the default: a blank code column means "generate
        // one from the Code Rules", not "the user forgot".
        $this->merge([
            'auto_generate_codes' => $this->has('auto_generate_codes')
                ? $this->boolean('auto_generate_codes')
                : true,
        ]);
    }

    public function rules(): array
    {
        return [
            'file' => ['required', 'file', 'mimes:xlsx,xls,csv', 'max:10240'],
            'auto_generate_codes' => ['boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'file.mimes' => __('organization-structure-import.errors.invalid_excel_format'),
        ];
    }
}
