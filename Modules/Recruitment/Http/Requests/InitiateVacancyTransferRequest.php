<?php

declare(strict_types=1);

namespace Modules\Recruitment\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Modules\Recruitment\Domain\Models\VacancyApplication;

class InitiateVacancyTransferRequest extends FormRequest
{
    public function authorize(): bool
    {
        $application = $this->route('vacancy_application');

        return $application instanceof VacancyApplication
            && ($this->user()?->can('initiateTransfer', $application) ?? false);
    }

    public function rules(): array
    {
        return [
            'effective_date' => ['required', 'date'],
        ];
    }
}
