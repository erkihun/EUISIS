<?php

declare(strict_types=1);

namespace App\Actions\Transfers;

use App\Actions\Audit\WriteAuditLogAction;
use App\Actions\CodeRules\GenerateCodeAction;
use App\Enums\AuditEventType;
use App\Enums\CodeRuleEntityType;
use App\Enums\EmployeeStatus;
use App\Enums\TransferApplicationStatus;
use App\Models\Employee;
use App\Models\TransferAnnouncement;
use App\Models\TransferAnnouncementPosition;
use App\Models\TransferApplication;
use App\Models\TransferSetting;
use App\Models\User;
use App\Services\Transfers\TransferEligibilityService;
use DomainException;
use Illuminate\Support\Facades\DB;

readonly class CreateTransferApplicationAction
{
    public function __construct(
        private WriteAuditLogAction $writeAuditLogAction,
        private TransferEligibilityService $eligibility,
        private GenerateCodeAction $generateCode,
    ) {}

    public function execute(
        TransferAnnouncement $announcement,
        Employee $employee,
        User $actor,
        array $data = [],
    ): TransferApplication {
        $announcementPosition = $this->resolveAnnouncementPosition($announcement, $data['announcement_position_id'] ?? null);
        $this->ensureCanApply($announcement, $employee, $announcementPosition);

        $settings = TransferSetting::current();
        $assignment = $employee->currentAssignment;

        if ($assignment === null) {
            throw new DomainException('Employee has no current assignment.');
        }

        $eligibilitySnapshot = $this->eligibility->evaluate($employee, $announcement, $settings, $announcementPosition);
        if (! $eligibilitySnapshot['eligible']) {
            throw new DomainException('Employee does not meet the configured transfer eligibility criteria.');
        }

        return DB::transaction(function () use ($announcement, $announcementPosition, $employee, $actor, $data): TransferApplication {
            $announcement = TransferAnnouncement::query()->lockForUpdate()->findOrFail($announcement->id);
            $announcementPosition = $announcementPosition === null ? null : TransferAnnouncementPosition::query()
                ->lockForUpdate()->findOrFail($announcementPosition->id);
            $employee = Employee::query()->lockForUpdate()->with('currentAssignment.position')->findOrFail($employee->id);
            $assignment = $employee->currentAssignment;
            $this->ensureCanApply($announcement, $employee, $announcementPosition);
            $eligibilitySnapshot = $this->eligibility->evaluate($employee, $announcement, TransferSetting::current(), $announcementPosition);
            if (! $eligibilitySnapshot['eligible']) {
                throw new DomainException('Employee does not meet the configured transfer eligibility criteria.');
            }
            $applicationNumber = $this->generateCode->execute(
                CodeRuleEntityType::TransferApplication,
                ['organization_id' => $assignment->organization_id],
                $actor,
                field: 'application_number',
            );
            $application = TransferApplication::query()->create([
                'application_number' => $applicationNumber,
                'announcement_id' => $announcement->id,
                'announcement_position_id' => $announcementPosition?->id,
                'employee_id' => $employee->id,
                'current_assignment_id' => $assignment->id,
                'releasing_organization_id' => $assignment->organization_id,
                'receiving_organization_id' => $announcementPosition?->organization_id ?? $announcement->organization_id,
                'status' => TransferApplicationStatus::Submitted->value,
                'eligibility_snapshot' => $eligibilitySnapshot,
                'source_assignment_snapshot' => [
                    'assignment_id' => $assignment->id,
                    'organization_id' => $assignment->organization_id,
                    'organization_unit_id' => $assignment->organization_unit_id,
                    'position_id' => $assignment->position_id,
                    'grade_level' => $assignment->position?->grade_level,
                ],
                'destination_snapshot' => [
                    'announcement_position_id' => $announcementPosition?->id,
                    'organization_id' => $announcementPosition?->organization_id ?? $announcement->organization_id,
                    'position_id' => $announcementPosition?->position_id ?? $announcement->position_id,
                    'grade_level' => $announcementPosition?->grade_level ?? $announcement->grade_level,
                ],
                'applicant_notes' => $data['applicant_notes'] ?? null,
                'submitted_at' => now(),
            ]);

            $this->writeAuditLogAction->execute(
                AuditEventType::TransferApplicationSubmitted,
                $actor,
                $application,
                $assignment->organization_id,
                newValues: $application->toArray(),
            );

            return $application;
        });
    }

    private function ensureCanApply(TransferAnnouncement $announcement, Employee $employee, ?TransferAnnouncementPosition $announcementPosition = null): void
    {
        if ($employee->status !== EmployeeStatus::Active) {
            throw new DomainException('Only active employees can apply for transfers.');
        }

        if ($employee->currentAssignment === null) {
            throw new DomainException('Employee must have a current active assignment.');
        }

        if (! $announcement->isAcceptingApplications()) {
            throw new DomainException('This announcement is not currently accepting applications.');
        }

        if ($announcementPosition !== null && $announcementPosition->transfer_announcement_id !== $announcement->id) {
            throw new DomainException('The selected destination position does not belong to this announcement.');
        }

        $alreadyApplied = TransferApplication::query()
            ->where('announcement_id', $announcement->id)
            ->where('employee_id', $employee->id)
            ->whereNotIn('status', [
                TransferApplicationStatus::Withdrawn->value,
                TransferApplicationStatus::Cancelled->value,
            ])
            ->exists();

        if ($alreadyApplied) {
            throw new DomainException('You have already applied for this transfer announcement.');
        }

        // Prevent multiple pending transfers if settings disallow it
        $settings = TransferSetting::current();
        if (! $settings->allow_cross_institution) {
            $currentOrgId = $employee->currentAssignment->organization_id;
            if ($currentOrgId === ($announcementPosition?->organization_id ?? $announcement->organization_id)) {
                throw new DomainException('Transfer to the same organization is not permitted.');
            }
        }
    }

    private function resolveAnnouncementPosition(TransferAnnouncement $announcement, mixed $positionId): ?TransferAnnouncementPosition
    {
        $positions = $announcement->positions()->with('position')->get();
        if ($positions->isEmpty()) {
            if ($positionId !== null) {
                throw new DomainException('This legacy announcement does not accept a position selection.');
            }

            return null;
        }
        if (! is_string($positionId) || $positionId === '') {
            if ($positions->count() === 1) {
                return $positions->first();
            }
            throw new DomainException('Select one advertised destination position.');
        }

        $line = $positions->firstWhere('id', $positionId);
        if ($line === null || $line->position === null || ! $line->position->isSelectable()) {
            throw new DomainException('The selected destination position is no longer available for this announcement.');
        }

        return $line;
    }
}
