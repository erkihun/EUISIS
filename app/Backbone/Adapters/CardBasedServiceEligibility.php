<?php

declare(strict_types=1);

namespace App\Backbone\Adapters;

use App\Backbone\Contracts\ServiceEligibility;
use App\Backbone\Data\EligibilityDecision;
use App\Models\Employee;
use App\Models\IdCard;
use App\Services\EmployeeServiceEligibilityService;
use Illuminate\Support\Str;

/** Delegates to the existing eligibility service with the employee's current card, as the v1 API does. */
final class CardBasedServiceEligibility implements ServiceEligibility
{
    public function __construct(private readonly EmployeeServiceEligibilityService $eligibility) {}

    public function check(string $employeeUuid, string $serviceType, ?string $providerId = null, ?string $attemptedBy = null): EligibilityDecision
    {
        $employee = Str::isUuid($employeeUuid) ? Employee::query()->find($employeeUuid) : null;
        $card = $employee === null ? null : IdCard::query()
            ->where('employee_id', $employee->getKey())
            ->where('is_current', true)
            ->first();

        $result = $this->eligibility->check($employee, $card, $serviceType, null, $providerId, attemptedBy: $attemptedBy);

        return new EligibilityDecision($result['eligible'], $result['reason_code'], $result['card_status']);
    }
}
