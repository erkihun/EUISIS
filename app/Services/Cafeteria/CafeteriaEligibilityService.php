<?php

declare(strict_types=1);

namespace App\Services\Cafeteria;

use App\Enums\EmployeeStatus;
use App\Models\Employee;
use App\Models\IdCard;

class CafeteriaEligibilityService
{
    public function isEmployeeEligible(Employee $employee): bool
    {
        return $employee->status === EmployeeStatus::Active;
    }

    public function isCardActive(IdCard $card): bool
    {
        return in_array($card->status->value, ['active', 'issued'], true);
    }

    /**
     * @return array{eligible: bool, reason: string|null}
     */
    public function check(Employee $employee, ?IdCard $card = null): array
    {
        if (! $this->isEmployeeEligible($employee)) {
            return ['eligible' => false, 'reason' => 'cafeteria.employeeNotEligible'];
        }

        if ($card !== null && ! $this->isCardActive($card)) {
            return ['eligible' => false, 'reason' => 'cafeteria.idCardNotActive'];
        }

        return ['eligible' => true, 'reason' => null];
    }
}
