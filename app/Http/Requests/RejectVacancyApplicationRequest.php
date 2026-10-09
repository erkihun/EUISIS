<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\VacancyApplication;
use Illuminate\Foundation\Http\FormRequest;

class RejectVacancyApplicationRequest extends FormRequest
{
    public function authorize(): bool
    {
        $application = $this->route('vacancy_application');

        return $application instanceof VacancyApplication
            && ($this->user()?->can('reject', $application) ?? false);
    }

    public function rules(): array
    {
        return [
            'rejection_reason' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
