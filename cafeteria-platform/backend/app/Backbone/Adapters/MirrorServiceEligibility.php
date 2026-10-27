<?php

declare(strict_types=1);

namespace App\Backbone\Adapters;

use App\Backbone\Contracts\ServiceEligibility;
use App\Backbone\Data\EligibilityDecision;
use App\Models\Employee;
use App\Models\IdCard;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * The service gate over the mirrored master data: the employee exists and is
 * active, their current card is a valid credential and their placement is in
 * force. The cafeteria's own rules (access, assignment, policy, entitlement,
 * exclusions) follow in the cafeteria services, as in EUISIS.
 */
final class MirrorServiceEligibility implements ServiceEligibility
{
    public function check(string $employeeUuid, string $serviceType, ?string $providerId = null, ?string $attemptedBy = null): EligibilityDecision
    {
        $employee = Str::isUuid($employeeUuid) ? Employee::query()->find($employeeUuid) : null;
        $card = $employee === null ? null : IdCard::query()->where('employee_id', $employee->getKey())->where('is_current', true)->first();

        return $this->decide($employee, $card);
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
        $employee = $employeeUuid !== null && Str::isUuid($employeeUuid) ? Employee::query()->find($employeeUuid) : $card?->employee;

        return $this->decide($employee, $card);
    }

    private function decide(?Employee $employee, ?IdCard $card): EligibilityDecision
    {
        if ($employee === null) {
            return new EligibilityDecision(false, 'employee_not_found', $card?->status, __('cafeteria.employeeNotEligible'));
        }
        if ($card === null) {
            return new EligibilityDecision(false, 'card_not_found', null, __('cafeteria.idCardNotActive'));
        }
        if ($card->employee_id !== $employee->getKey()) {
            return new EligibilityDecision(false, 'card_employee_mismatch', (string) $card->status, __('cafeteria.idCardNotActive'));
        }
        $reason = $card->invalidReason();
        if ($reason !== null) {
            $code = strtolower($reason);
            $message = $reason === 'EMPLOYEE_INACTIVE' || $reason === 'ASSIGNMENT_INACTIVE' ? __('cafeteria.employeeNotEligible') : __('cafeteria.idCardNotActive');

            return new EligibilityDecision(false, $code, (string) $card->status, $message);
        }

        return new EligibilityDecision(true, null, (string) $card->status);
    }
}
