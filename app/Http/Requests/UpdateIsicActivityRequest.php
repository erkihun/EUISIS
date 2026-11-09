<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\IsicActivity;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateIsicActivityRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var IsicActivity|null $activity */
        $activity = $this->route('isicActivity');

        return $activity !== null && ($this->user()?->can('update', $activity) ?? false);
    }

    public function rules(): array
    {
        /** @var IsicActivity $activity */
        $activity = $this->route('isicActivity');

        return [
            'isic_code' => ['required', 'string', 'max:10', Rule::unique('isic_activities', 'isic_code')->ignore($activity->id)],
            'name_en' => ['nullable', 'string', 'max:255', 'required_without:name_am'],
            'name_am' => ['nullable', 'string', 'max:255', 'required_without:name_en'],
            'level' => ['required', 'in:section,division,group,class'],
            'section_code' => ['nullable', 'string', 'size:1', 'regex:/^[A-Z]$/'],
            'division_code' => ['nullable', 'string', 'size:2', 'digits:2'],
            'group_code' => ['nullable', 'string', 'size:3', 'digits:3'],
            'class_code' => ['nullable', 'string', 'size:4', 'digits:4'],
            'parent_id' => ['nullable', 'exists:isic_activities,id'],
            'is_active' => ['required', 'boolean'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
            'description_en' => ['nullable', 'string'],
            'description_am' => ['nullable', 'string'],
            'metadata' => ['nullable', 'array'],
        ];
    }
}
