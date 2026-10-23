<?php

declare(strict_types=1);

namespace App\Backbone\Contracts;

use App\Backbone\Data\EligibilityDecision;
use App\Models\User;
use Illuminate\Http\Request;

/**
 * The service gate: whether an employee may receive a service now. It applies
 * to service delivery only, never to HR administration of the employee.
 * A refusal is audited, as the existing eligibility service does.
 */
interface ServiceEligibility
{
    /** Gate on the employee's current card. */
    public function check(string $employeeUuid, string $serviceType, ?string $providerId = null, ?string $attemptedBy = null): EligibilityDecision;

    /**
     * Gate on a presented card (a scan). Without an employee UUID the card's
     * holder is checked; with one, a card belonging to someone else is refused.
     */
    public function checkCard(
        string $cardRecordUuid,
        string $serviceType,
        ?string $employeeUuid = null,
        ?User $actor = null,
        ?string $providerId = null,
        ?Request $request = null,
        ?string $attemptedBy = null,
    ): EligibilityDecision;
}
