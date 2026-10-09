<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\VacancyApplication;
use Illuminate\Foundation\Http\FormRequest;

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
