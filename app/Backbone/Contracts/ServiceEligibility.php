<?php

declare(strict_types=1);

namespace App\Backbone\Contracts;

use App\Backbone\Data\EligibilityDecision;

/**
 * The service gate: whether an employee may receive a service now. It applies
 * to service delivery only, never to HR administration of the employee.
 * A refusal is audited, as the existing eligibility service does.
 */
interface ServiceEligibility
{
    public function check(string $employeeUuid, string $serviceType, ?string $providerId = null, ?string $attemptedBy = null): EligibilityDecision;
}
