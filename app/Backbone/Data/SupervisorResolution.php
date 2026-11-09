<?php

declare(strict_types=1);

namespace App\Backbone\Data;

use App\Enums\OccupantBasis;
use App\Enums\ReportingBasis;
use App\Enums\ReportingOutcome;

/**
 * The answer to "who supervises this employee on this date" (ADR 0025).
 *
 * Every field that names a record is a UUID; names are carried for display so
 * callers need no second query. `explanation` is a translation key under
 * reporting-lines.explanations with `explanationParams` for its placeholders.
 */
final readonly class SupervisorResolution
{
    /**
     * @param  array<string, string|int|null>  $explanationParams
     * @param  list<string>  $path  position ids walked, subject first, for cycle reports
     */
    public function __construct(
        public ReportingOutcome $outcome,
        public string $employeeUuid,
        public ?string $asOf,
        public ?string $assignmentUuid = null,
        public ?string $subjectPositionUuid = null,
        public ?string $subjectUnitUuid = null,
        public ?string $organizationUuid = null,
        public ?ReportingBasis $basis = null,
        public ?string $supervisorPositionUuid = null,
        public ?string $supervisorPositionTitleEn = null,
        public ?string $supervisorPositionTitleAm = null,
        public ?string $supervisorUnitUuid = null,
        public ?string $supervisorUnitNameEn = null,
        public ?string $supervisorUnitNameAm = null,
        public ?string $supervisorOrganizationUuid = null,
        public ?OccupantBasis $occupantBasis = null,
        public ?string $supervisorEmployeeUuid = null,
        public ?string $supervisorEmployeeNumber = null,
        public ?string $supervisorName = null,
        public ?string $supervisorNameAm = null,
        public ?string $actingAppointmentUuid = null,
        public string $explanation = 'reporting-lines.explanations.unknown',
        public array $explanationParams = [],
        public array $path = [],
    ) {}

    public function isResolved(): bool
    {
        return $this->outcome->isResolved();
    }

    /** The supervising position was found even if nobody holds it. */
    public function hasSupervisorPosition(): bool
    {
        return $this->supervisorPositionUuid !== null;
    }

    public function supervises(string $employeeUuid): bool
    {
        return $this->isResolved() && $this->supervisorEmployeeUuid === $employeeUuid;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'outcome' => $this->outcome->value,
            'is_resolved' => $this->isResolved(),
            'is_legitimate_end' => $this->outcome->isLegitimateEnd(),
            'is_problem' => $this->outcome->isProblem(),
            'employee_id' => $this->employeeUuid,
            'as_of' => $this->asOf,
            'assignment_id' => $this->assignmentUuid,
            'subject_position_id' => $this->subjectPositionUuid,
            'subject_unit_id' => $this->subjectUnitUuid,
            'organization_id' => $this->organizationUuid,
            'basis' => $this->basis?->value,
            'supervisor_position_id' => $this->supervisorPositionUuid,
            'supervisor_position_title_en' => $this->supervisorPositionTitleEn,
            'supervisor_position_title_am' => $this->supervisorPositionTitleAm,
            'supervisor_unit_id' => $this->supervisorUnitUuid,
            'supervisor_unit_name_en' => $this->supervisorUnitNameEn,
            'supervisor_unit_name_am' => $this->supervisorUnitNameAm,
            'supervisor_organization_id' => $this->supervisorOrganizationUuid,
            'occupant_basis' => $this->occupantBasis?->value,
            'supervisor_employee_id' => $this->supervisorEmployeeUuid,
            'supervisor_employee_number' => $this->supervisorEmployeeNumber,
            'supervisor_name' => $this->supervisorName,
            'supervisor_name_am' => $this->supervisorNameAm,
            'acting_appointment_id' => $this->actingAppointmentUuid,
            'explanation' => $this->explanation,
            'explanation_params' => $this->explanationParams,
            'explanation_text' => __($this->explanation, $this->explanationParams),
            'path' => $this->path,
        ];
    }
}
