<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\Occupation;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateOccupationRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var Occupation|null $occupation */
        $occupation = $this->route('occupation');

        return $occupation !== null && ($this->user()?->can('update', $occupation) ?? false);
    }

    public function rules(): array
    {
        /** @var Occupation $occupation */
        $occupation = $this->route('occupation');

        return [
            'isco_code' => ['required', 'string', 'max:4', 'regex:/^[0-9]{1,4}$/', Rule::unique('occupations', 'isco_code')->ignore($occupation->id)],
            'name_en' => ['nullable', 'string', 'max:255', 'required_without:name_am'],
            'name_am' => ['nullable', 'string', 'max:255', 'required_without:name_en'],
            'skill_specialization' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
        ];
    }

    public function attributes(): array
    {
        return [
            'isco_code' => __('occupations.isco_code'),
            'name_en' => __('occupations.name_en'),
            'name_am' => __('occupations.name_am'),
            'skill_specialization' => __('occupations.skill_specialization'),
            'description' => __('occupations.description'),
        ];
    }

    public function messages(): array
    {
        return [
            'isco_code.required' => __('occupations.isco_code_required'),
            'isco_code.regex' => __('occupations.isco_code_digits'),
            'isco_code.unique' => __('occupations.isco_code_unique'),
        ];
    }
}
