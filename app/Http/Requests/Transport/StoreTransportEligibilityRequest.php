<?php

declare(strict_types=1);

namespace App\Http\Requests\Transport;

use App\Enums\Transport\TransportEligibilityStatus;
use App\Enums\Transport\TransportEligibilitySubject;
use App\Models\Employee;
use App\Rules\OrganizationInScope;
use App\Services\OrganizationScope\OrganizationScopeService;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Who qualifies: one employee in scope, or an office in scope (self or subtree). */
class StoreTransportEligibilityRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can($this->route('eligibility') ? 'transport-eligibility.update' : 'transport-eligibility.create') ?? false;
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'subject_type' => ['required', Rule::enum(TransportEligibilitySubject::class)],
            'employee_id' => ['required_if:subject_type,employee', 'nullable', 'uuid', 'exists:employees,id', function (string $attribute, mixed $value, Closure $fail): void {
                $employee = Employee::query()->with('currentAssignment')->find($value);
                if ($employee !== null && ! app(OrganizationScopeService::class)->canAccessEmployee($this->user(), $employee)) {
                    $fail(__('transport.employee_out_of_scope'));
                }
            }],
            'organization_id' => ['required_if:subject_type,organization', 'nullable', 'uuid', 'exists:organizations,id', new OrganizationInScope($this->user())],
            'scope_type' => ['required', Rule::in(['self', 'subtree'])],
            'provider_id' => ['nullable', 'uuid', 'exists:providers,id'],
            'transport_contract_id' => ['nullable', 'uuid', 'exists:transport_contracts,id'],
            'status' => ['required', Rule::in(TransportEligibilityStatus::values())],
            'effective_from' => ['required', 'date'],
            'effective_to' => ['nullable', 'date', 'after_or_equal:effective_from'],
            'authorization_reference' => ['required', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
