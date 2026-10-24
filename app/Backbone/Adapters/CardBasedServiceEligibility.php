<?php

declare(strict_types=1);

namespace App\Backbone\Adapters;

use App\Backbone\Contracts\ServiceEligibility;
use App\Backbone\Data\EligibilityDecision;
use App\Models\Employee;
use App\Models\IdCard;
use App\Models\User;
use App\Services\EmployeeServiceEligibilityService;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/** Delegates to the existing eligibility service, so every caller gets the same rules and audit. */
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

        return EligibilityDecision::fromArray($this->eligibility->check($employee, $card, $serviceType, null, $providerId, attemptedBy: $attemptedBy));
    }

    public function checkCard(
        string $cardRecordUuid,
        string $serviceType,
        ?string $employeeUuid = null,
        ?User $actor = null,
        ?string $providerId = null,
        ?Request $request = null,
        ?string $attemptedBy = null,
    ): EligibilityDecision {
        $card = Str::isUuid($cardRecordUuid) ? IdCard::query()->find($cardRecordUuid) : null;
        $employee = match (true) {
            $employeeUuid !== null => Str::isUuid($employeeUuid) ? Employee::query()->find($employeeUuid) : null,
            default => $card?->employee,
        };

        return EligibilityDecision::fromArray($this->eligibility->check($employee, $card, $serviceType, $actor, $providerId, $request, attemptedBy: $attemptedBy));
    }
}
