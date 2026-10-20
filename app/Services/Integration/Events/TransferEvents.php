<?php

declare(strict_types=1);

namespace App\Services\Integration\Events;

use App\Models\Employee;
use App\Models\EmployeeAssignment;
use Closure;
use DateTimeInterface;

/**
 * employee.transferred — the one semantic event a completed transfer
 * publishes for the employee aggregate.
 *
 * The transfer still closes one assignment and opens another, so the
 * assignment aggregates get assignment.closed / assignment.created as usual;
 * the generic employee.assignment_changed for the same pointer move is
 * suppressed so a consumer does not receive the employee change twice.
 *
 * Visibility: organization_id = destination, related_organization_id =
 * source. A source-only partner receives a minimised variant without the
 * destination (ChangeVisibility::present) — see
 * NEEDS_POLICY_DECISION_PARTNER_TRANSFER_EVENT_VISIBILITY.
 */
final class TransferEvents
{
    public function __construct(
        private readonly IntegrationEventRecorder $recorder,
        private readonly IntegrationEventContext $context,
    ) {}

    /**
     * Moves the employee's current-assignment pointer without the generic
     * employee.assignment_changed event; record() follows in the same transaction.
     *
     * @param  Closure(): mixed  $movePointer
     */
    public function movePointer(Employee $employee, Closure $movePointer): void
    {
        $this->context->within($movePointer, [['employee.assignment_changed', (string) $employee->getKey()]]);
    }

    public function record(
        Employee $employee,
        EmployeeAssignment $previous,
        EmployeeAssignment $current,
        ?string $transferId,
        string $kind,
        DateTimeInterface|string $effectiveDate,
    ): void {
        $effective = $effectiveDate instanceof DateTimeInterface ? $effectiveDate->format('Y-m-d') : substr((string) $effectiveDate, 0, 10);

        $this->recorder->record('employee.transferred', (string) $employee->getKey(), [
            'employee_uuid' => (string) $employee->getKey(),
            'transfer_uuid' => $transferId,
            'transfer_kind' => $kind,
            'effective_date' => $effective,
            'previous_assignment_uuid' => (string) $previous->getKey(),
            'previous_organization_uuid' => $this->id($previous->organization_id),
            'previous_unit_uuid' => $this->id($previous->organization_unit_id),
            'previous_position_uuid' => $this->id($previous->position_id),
            'assignment_uuid' => (string) $current->getKey(),
            'organization_uuid' => $this->id($current->organization_id),
            'unit_uuid' => $this->id($current->organization_unit_id),
            'position_uuid' => $this->id($current->position_id),
            'visibility' => 'full',
        ], $this->id($current->organization_id), $this->id($previous->organization_id), effectiveAt: $effective);
    }

    private function id(mixed $value): ?string
    {
        return $value === null ? null : (string) $value;
    }
}
