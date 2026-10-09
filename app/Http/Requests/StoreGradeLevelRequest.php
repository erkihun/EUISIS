<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\GradeLevel;
use Illuminate\Foundation\Http\FormRequest;

class StoreGradeLevelRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', GradeLevel::class) ?? false;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255', 'unique:grade_levels,name'],
        ];
    }
}
