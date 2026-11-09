<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateGradeLevelRequest extends FormRequest
{
    public function authorize(): bool
    {
        $gradeLevel = $this->route('gradeLevel');

        return $this->user()?->can('update', $gradeLevel) ?? false;
    }

    public function rules(): array
    {
        $gradeLevel = $this->route('gradeLevel');

        return [
            'name' => ['required', 'string', 'max:255', Rule::unique('grade_levels', 'name')->ignore($gradeLevel)],
        ];
    }
}
