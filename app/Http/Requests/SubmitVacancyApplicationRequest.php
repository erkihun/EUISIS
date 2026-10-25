<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Backbone\Contracts\EmployeeDirectory;
use App\Models\VacancyApplication;
use Closure;
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
            'employee_id' => ['required', 'uuid', 'exists:employees,id', function (string $attribute, mixed $value, Closure $fail): void {
                // An application is filed for oneself, or by staff for an employee inside
                // their organization scope (Core EmployeeDirectory), never for anyone.
                $user = $this->user();
                if (! is_string($value) || $user === null) {
                    return;
                }
                if ((string) $user->employee_id !== $value && app(EmployeeDirectory::class)->findFor($user, $value) === null) {
                    $fail(__('vacancies.employeeOutOfScope'));
                }
            }],
        ];
    }
}
