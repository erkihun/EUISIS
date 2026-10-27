<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\Employee;
use App\Models\EmployeeAssignment;
use App\Models\EmployeeDocument;
use App\Models\Organization;
use App\Models\Position;
use App\Models\User;
use App\Services\AuditWriter;
use App\Services\OrganizationScope;
use App\Services\SeatCapacity;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Moves an employee to another position: the current placement closes the day
 * before the effective date and stays as history, a new current placement opens
 * and the employee's pointer moves. The employee's UUID and number never change.
 * Only an active placement of an employed person can be transferred.
 */
final readonly class TransferEmployee
{
    public function __construct(private SeatCapacity $seats, private OrganizationScope $scope, private AuditWriter $audit) {}

    /**
     * @param  array{assignment_type?: string, reason_code?: string|null, source_document_id?: string|null, source_system?: string, external_reference?: string|null}  $provenance
     *                                                                                                                                                                              where the placement came from (ADR 0010); the person who records it is its approver in this one-step move
     */
    public function execute(Employee $employee, string $positionId, string $effectiveDate, User $actor, ?string $reason = null, array $provenance = []): EmployeeAssignment
    {
        $provenance = $this->provenance($employee, $provenance);

        return DB::transaction(function () use ($employee, $positionId, $effectiveDate, $actor, $reason, $provenance): EmployeeAssignment {
            $employee = Employee::query()->lockForUpdate()->findOrFail($employee->id);
            $current = EmployeeAssignment::query()->lockForUpdate()->find($employee->current_assignment_id);
            $target = Position::query()->lockForUpdate()->find($positionId);
            $effective = Carbon::parse($effectiveDate)->startOfDay();

            $problem = match (true) {
                $effective->isFuture() => ['effective_date' => 'Future transfers require scheduled activation and are not yet supported.'],
                $employee->isSeparated() => ['employee' => 'The employment has ended; it cannot be transferred.'],
                $current === null || (string) $current->employee_id !== (string) $employee->id || ! $current->is_current || $current->assignment_status !== EmployeeAssignment::ACTIVE => ['employee' => 'Only an active placement can be transferred.'],
                $target === null || ! $target->is_active || $target->organization?->status !== Organization::ACTIVE => ['position_id' => 'The target position must exist, be active and belong to an organization.'],
                (string) $target->id === (string) $current->position_id => ['position_id' => 'The employee already holds this position.'],
                ! $this->scope->allows($actor, (string) $current->organization_id) || ! $this->scope->allows($actor, (string) $target->organization_id) => ['position_id' => 'Both placements must be inside your organizations.'],
                $current->effective_from !== null && $effective->lte($current->effective_from) => ['effective_date' => 'The transfer must start after the current placement began.'],
                ! $this->seats->hasFreeSeat((string) $target->id, $effective) => ['position_id' => 'The target position has no free seat.'],
                default => null,
            };
            if ($problem !== null) {
                throw ValidationException::withMessages($problem);
            }

            $current->forceFill([
                'is_current' => false,
                'assignment_status' => EmployeeAssignment::CLOSED,
                'effective_to' => $effective->copy()->subDay()->toDateString(),
            ])->save();

            $next = EmployeeAssignment::query()->create([
                'employee_id' => $employee->id,
                'organization_id' => $target->organization_id,
                'organization_unit_id' => $target->organization_unit_id,
                'position_id' => $target->id,
                'assignment_status' => EmployeeAssignment::ACTIVE,
                'is_current' => true,
                'effective_from' => $effective->toDateString(),
                'reason' => $reason ?? 'transferred',
                'previous_assignment_id' => $current->id,
                'created_by' => $actor->getKey(),
                'approved_by' => $actor->getKey(),
                'approved_at' => now(),
            ] + $provenance);
            $employee->forceFill(['current_assignment_id' => $next->id])->save();

            $this->audit->write('assignment.transferred', $actor, $employee, (string) $target->organization_id, [
                'assignment_id' => $current->id, 'position_id' => $current->position_id,
            ], [
                'assignment_id' => $next->id, 'position_id' => $target->id, 'effective_from' => $next->effective_from?->toDateString(),
                'assignment_type' => $next->assignment_type, 'reason_code' => $next->reason_code, 'source_system' => $next->source_system,
            ], $reason);

            return $next;
        });
    }

    /**
     * Validates the provenance: a known type (transfer by default) and reason
     * code, a supporting document that is this employee's own, current and not
     * rejected, and a reference no other placement from that system carries.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function provenance(Employee $employee, array $input): array
    {
        $type = $input['assignment_type'] ?? 'transfer';
        $code = $input['reason_code'] ?? null;
        $source = $input['source_system'] ?? 'core';
        $document = $input['source_document_id'] ?? null;
        $reference = isset($input['external_reference']) && $input['external_reference'] !== '' ? mb_substr((string) $input['external_reference'], 0, 128) : null;
        $problem = match (true) {
            ! in_array($type, EmployeeAssignment::TYPES, true) || $type === 'initial' => ['assignment_type' => 'Choose how the placement came about.'],
            $code !== null && ! in_array($code, EmployeeAssignment::REASON_CODES, true) => ['reason_code' => 'Choose a reason from the list.'],
            ! in_array($source, EmployeeAssignment::SOURCES, true) || $source === 'legacy' => ['source_system' => 'Unknown source.'],
            $document !== null && ! EmployeeDocument::query()->whereKey($document)->where('employee_id', $employee->id)->where('is_current', true)
                ->whereNotIn('status', [EmployeeDocument::REJECTED])->exists() => ['source_document_id' => 'The supporting document must be a current document of this employee.'],
            $reference !== null && EmployeeAssignment::query()->where('source_system', $source)->where('external_reference', $reference)->exists() => ['external_reference' => 'This reference was already recorded.'],
            default => null,
        };
        if ($problem !== null) {
            throw ValidationException::withMessages($problem);
        }

        return ['assignment_type' => $type, 'reason_code' => $code, 'source_document_id' => $document, 'source_system' => $source, 'external_reference' => $reference];
    }
}
