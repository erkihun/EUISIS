<?php

declare(strict_types=1);

namespace App\Services\ReportingLines;

use App\Backbone\Data\SupervisorResolution;
use App\Models\ActingAppointment;
use App\Models\Employee;
use App\Models\SupervisorDelegation;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

/**
 * The page shapes of the reporting-line screens (ADR 0025). Names in both
 * languages, labels resolved server-side, and never more of an employee than
 * the screen needs: number, name, position and unit.
 */
class ReportingLinePresenter
{
    /** @return array<string, mixed> */
    public function resolution(SupervisorResolution $resolution): array
    {
        return $resolution->toArray() + [
            'outcome_label' => $resolution->outcome->label(),
            'basis_label' => $resolution->basis?->label(),
            'occupant_basis_label' => $resolution->occupantBasis?->label(),
        ];
    }

    /**
     * @param  list<SupervisorResolution>  $chain
     * @return list<array<string, mixed>>
     */
    public function chain(array $chain): array
    {
        return array_map(fn (SupervisorResolution $step): array => $this->resolution($step), $chain);
    }

    /** @return array<string, mixed> */
    public function employee(Employee $employee): array
    {
        $assignment = $employee->currentAssignment;

        return [
            'id' => (string) $employee->getKey(),
            'employee_number' => $employee->employee_number,
            'full_name' => $employee->full_name,
            'name_am' => $employee->fullNameAm(),
            'status' => $employee->status instanceof \BackedEnum ? $employee->status->value : (string) $employee->status,
            'position_title_en' => $assignment?->position?->title_en,
            'position_title_am' => $assignment?->position?->title_am,
            'position_id' => $assignment?->position_id,
            'is_unit_head' => (bool) ($assignment?->position?->is_unit_head ?? false),
            'unit_id' => $assignment?->organization_unit_id,
            'unit_name_en' => $assignment?->organizationUnit?->name_en,
            'unit_name_am' => $assignment?->organizationUnit?->name_am,
            'organization_id' => $assignment?->organization_id,
            'organization_name_en' => $assignment?->organization?->name_en,
            'organization_name_am' => $assignment?->organization?->name_am,
        ];
    }

    /**
     * A page of employees with their current placement.
     *
     * @param  Builder<Employee>  $query
     * @return array{data: list<array<string, mixed>>, meta: array{current_page: int, last_page: int, total: int, per_page: int}}
     */
    public function employeePage(Builder $query, int $perPage = 25, string $pageName = 'page'): array
    {
        $page = $query
            ->with(['currentAssignment.position:id,title_en,title_am,is_unit_head', 'currentAssignment.organizationUnit:id,name_en,name_am', 'currentAssignment.organization:id,name_en,name_am'])
            ->orderBy('full_name')
            ->orderBy('id')
            ->paginate($perPage, ['*'], $pageName)
            ->withQueryString();

        return [
            'data' => array_map(fn (Employee $e): array => $this->employee($e), $page->items()),
            'meta' => $this->meta($page),
        ];
    }

    /** @return array{current_page: int, last_page: int, total: int, per_page: int} */
    public function meta(LengthAwarePaginator $page): array
    {
        return ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total(), 'per_page' => $page->perPage()];
    }

    /** @return array<string, mixed> */
    public function actingAppointment(ActingAppointment $appointment): array
    {
        return [
            'id' => (string) $appointment->getKey(),
            'organization_id' => (string) $appointment->organization_id,
            'organization_name_en' => $appointment->organization?->name_en,
            'organization_name_am' => $appointment->organization?->name_am,
            'position_id' => (string) $appointment->position_id,
            'position_title_en' => $appointment->position?->title_en,
            'position_title_am' => $appointment->position?->title_am,
            'unit_name_en' => $appointment->position?->organizationUnit?->name_en,
            'unit_name_am' => $appointment->position?->organizationUnit?->name_am,
            'employee_id' => (string) $appointment->employee_id,
            'employee_number' => $appointment->employee?->employee_number,
            'employee_name' => $appointment->employee?->full_name,
            'employee_name_am' => $appointment->employee?->fullNameAm(),
            'starts_on' => $appointment->starts_on?->toDateString(),
            'ends_on' => $appointment->ends_on?->toDateString(),
            'reason' => $appointment->reason,
            'status' => $appointment->status->value,
            'status_label' => $appointment->status->label(),
            'in_force' => $appointment->isInForceOn(),
            'authorized_by' => $appointment->authorizer?->name,
            'revoked_at' => $appointment->revoked_at?->toIso8601String(),
            'revoked_by' => $appointment->revoker?->name,
            'revocation_reason' => $appointment->revocation_reason,
            'created_at' => $appointment->created_at?->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> */
    public function delegation(SupervisorDelegation $delegation): array
    {
        return [
            'id' => (string) $delegation->getKey(),
            'organization_id' => (string) $delegation->organization_id,
            'organization_name_en' => $delegation->organization?->name_en,
            'organization_name_am' => $delegation->organization?->name_am,
            'delegator_employee_id' => (string) $delegation->delegator_employee_id,
            'delegator_number' => $delegation->delegator?->employee_number,
            'delegator_name' => $delegation->delegator?->full_name,
            'delegator_name_am' => $delegation->delegator?->fullNameAm(),
            'delegate_employee_id' => (string) $delegation->delegate_employee_id,
            'delegate_number' => $delegation->delegate?->employee_number,
            'delegate_name' => $delegation->delegate?->full_name,
            'delegate_name_am' => $delegation->delegate?->fullNameAm(),
            'actions' => $delegation->actions ?? [],
            'action_labels' => array_map(fn (string $a): string => SupervisorActions::label($a), $delegation->actions ?? []),
            'starts_on' => $delegation->starts_on?->toDateString(),
            'ends_on' => $delegation->ends_on?->toDateString(),
            'reason' => $delegation->reason,
            'status' => $delegation->status->value,
            'status_label' => $delegation->status->label(),
            'in_force' => $delegation->isInForceOn(),
            'authorized_by' => $delegation->authorizer?->name,
            'revoked_at' => $delegation->revoked_at?->toIso8601String(),
            'revoked_by' => $delegation->revoker?->name,
            'revocation_reason' => $delegation->revocation_reason,
            'created_at' => $delegation->created_at?->toIso8601String(),
        ];
    }

    /** @return list<array{value: string, label: string}> */
    public function actionOptions(): array
    {
        return array_map(fn (string $a): array => ['value' => $a, 'label' => SupervisorActions::label($a)], SupervisorActions::all());
    }
}
