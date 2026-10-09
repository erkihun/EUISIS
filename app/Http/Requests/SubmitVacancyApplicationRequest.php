<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\VacancyApplication;
use Illuminate\Foundation\Http\FormRequest;

class SubmitVacancyApplicationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('submit', VacancyApplication::class) ?? false;
    }

    public function rules(): array
    {
        return [
            'vacancy_announcement_position_id' => ['required', 'uuid', 'exists:vacancy_announcement_positions,id'],
            'employee_id' => ['required', 'uuid', 'exists:employees,id'],
        ];
    }
}
