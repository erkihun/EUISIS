<?php

declare(strict_types=1);

namespace App\Http\Requests\ReportingLines;

use App\Models\Employee;
use App\Models\SupervisorDelegation;
use App\Services\ReportingLines\SupervisorActions;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A delegation on behalf of a delegator: the signed-in supervisor (My Team,
 * where the delegator is always the user's own employee) or one named by
 * HR (the administration page).
 */
class StoreSupervisorDelegationRequest extends FormRequest
{
    public function authorize(): bool
    {
        $delegator = $this->delegator();

        return $delegator !== null && ($this->user()?->can('createFor', [SupervisorDelegation::class, $delegator]) ?? false);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'delegator_employee_id' => ['sometimes', 'uuid', 'exists:employees,id'],
            'delegate_employee_id' => ['required', 'uuid', 'exists:employees,id'],
            'actions' => ['required', 'array', 'min:1'],
            'actions.*' => ['string', Rule::in(SupervisorActions::all())],
            'starts_on' => ['required', 'date_format:Y-m-d'],
            'ends_on' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:starts_on'],
            'reason' => ['nullable', 'string', 'max:1000'],
        ];
    }

    /** The delegator: the named employee on the administration page, else the user's own. */
    public function delegator(): ?Employee
    {
        $id = $this->route()?->getName() === 'employee.my-team.delegations.store'
            ? $this->user()?->employee_id
            : ($this->input('delegator_employee_id') ?: $this->user()?->employee_id);

        return $id === null ? null : Employee::query()->find($id);
    }
}
