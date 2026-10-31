<?php

declare(strict_types=1);

namespace Modules\Recruitment\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Modules\Recruitment\Domain\Models\VacancyApplication;

class ScreenVacancyApplicationRequest extends FormRequest
{
    public function authorize(): bool
    {
        $application = $this->route('vacancy_application');

        return $application instanceof VacancyApplication
            && ($this->user()?->can('screen', $application) ?? false);
    }

    public function rules(): array
    {
        return [
            'screening_score' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'screening_notes' => ['nullable', 'string'],
        ];
    }
}
