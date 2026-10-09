<?php

declare(strict_types=1);

namespace App\Http\Requests\FieldWork;

use App\Models\FieldWorkType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveFieldWorkTypeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('field_work.manage_types');
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('code'))) {
            $this->merge(['code' => strtoupper(trim($this->input('code')))]);
        }
    }

    public function rules(): array
    {
        $type = $this->route('fieldWorkType');

        return [
            'code' => ['required', 'string', 'max:40', 'regex:/^[A-Z0-9_\-]+$/', Rule::unique('field_work_types', 'code')->ignore($type instanceof FieldWorkType ? $type->id : null)],
            'name_en' => ['required', 'string', 'max:150'],
            'name_am' => ['nullable', 'string', 'max:150'],
            'description_en' => ['nullable', 'string', 'max:2000'],
            'description_am' => ['nullable', 'string', 'max:2000'],
            'is_active' => ['required', 'boolean'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:65000'],
        ];
    }
}
