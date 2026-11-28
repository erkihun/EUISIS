<?php

declare(strict_types=1);

namespace Modules\WorkExperience\Application\Services;

use App\Actions\Audit\WriteAuditLogAction;
use App\Enums\AuditEventType;
use App\Models\Employee;
use App\Models\EmployeeAssignment;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Modules\WorkExperience\Domain\Models\WorkExperienceRecord;

/**
 * The office's own lines of an employee's work experience: recorded,
 * corrected and removed here, each change written to the audit trail.
 */
class WorkExperienceRecords
{
    private const AUDITED = ['employee_assignment_id', 'kind', 'employer_name', 'position_title', 'unit_name', 'started_on', 'ended_on', 'monthly_salary', 'note', 'evidence_name'];

    public function __construct(
        private readonly WorkExperienceAccess $access,
        private readonly WorkExperienceTimeline $timeline,
        private readonly WriteAuditLogAction $audit,
    ) {}

    /**
     * Record a new line, or the details of one assignment; or correct an existing record.
     *
     * @param  array<string, mixed>  $data
     */
    public function save(User $actor, Employee $employee, array $data, ?WorkExperienceRecord $record = null, ?UploadedFile $evidence = null): WorkExperienceRecord
    {
        $today = $this->timeline->today();
        $startedOn = (string) $data['started_on'];
        $endedOn = filled($data['ended_on'] ?? null) ? (string) $data['ended_on'] : null;
        if ($startedOn > $today) {
            throw $this->invalid('started_on', 'starts_in_future');
        }
        if ($endedOn !== null && $endedOn < $startedOn) {
            throw $this->invalid('ended_on', 'ends_before_start');
        }
        if ($endedOn !== null && $endedOn > $today) {
            throw $this->invalid('ended_on', 'ends_in_future');
        }

        $assignmentId = $record !== null ? $record->employee_assignment_id : ($data['employee_assignment_id'] ?? null);
        $kind = (string) ($data['kind'] ?? WorkExperienceRecord::KIND_INTERNAL);
        if ($assignmentId !== null) {
            $assignment = EmployeeAssignment::query()->whereKey($assignmentId)->where('employee_id', $employee->id)->first();
            if ($assignment === null) {
                throw $this->invalid('employee_assignment_id', 'assignment_unknown');
            }
            if ($record === null && WorkExperienceRecord::query()->where('employee_assignment_id', $assignmentId)->exists()) {
                throw $this->invalid('employee_assignment_id', 'assignment_recorded');
            }
            // The institution of an assignment is a fact of the system, not of this form.
            $kind = (string) $assignment->organization_id === $this->access->officeOf($employee) ? WorkExperienceRecord::KIND_INTERNAL : WorkExperienceRecord::KIND_EXTERNAL;
        }
        $employer = trim((string) ($data['employer_name'] ?? ''));
        if ($assignmentId === null && $kind === WorkExperienceRecord::KIND_EXTERNAL && $employer === '') {
            throw $this->invalid('employer_name', 'employer_required');
        }

        return DB::transaction(function () use ($actor, $employee, $data, $record, $evidence, $startedOn, $endedOn, $assignmentId, $kind, $employer): WorkExperienceRecord {
            $before = $record?->only(self::AUDITED);
            $record ??= new WorkExperienceRecord(['employee_id' => $employee->id, 'employee_assignment_id' => $assignmentId, 'created_by' => $actor->getKey()]);
            $record->fill([
                'kind' => $kind,
                'employer_name' => $employer === '' ? null : $employer,
                'position_title' => trim((string) $data['position_title']),
                'unit_name' => filled($data['unit_name'] ?? null) ? trim((string) $data['unit_name']) : null,
                'started_on' => $startedOn,
                'ended_on' => $endedOn,
                'monthly_salary' => filled($data['monthly_salary'] ?? null) ? $data['monthly_salary'] : null,
                'note' => filled($data['note'] ?? null) ? trim((string) $data['note']) : null,
                'updated_by' => $actor->getKey(),
            ]);
            $record->save();

            if ($evidence !== null) {
                $path = $evidence->store("work-experience/{$employee->id}", 'local');
                if ($path !== false) {
                    $old = $record->evidence_path;
                    $record->forceFill(['evidence_disk' => 'local', 'evidence_path' => $path, 'evidence_name' => $evidence->getClientOriginalName()])->save();
                    if ($old !== null && $old !== $path) {
                        Storage::disk('local')->delete($old);
                    }
                }
            }

            $this->audit->execute(AuditEventType::WorkExperienceRecordSaved, $actor, $record, $this->access->officeOf($employee), $before, $record->only(self::AUDITED));

            return $record;
        });
    }

    public function delete(User $actor, WorkExperienceRecord $record): void
    {
        $record->loadMissing('employee');
        DB::transaction(function () use ($actor, $record): void {
            $before = $record->only(self::AUDITED);
            $path = $record->evidence_path;
            $disk = $record->evidence_disk ?: 'local';
            $office = $record->employee === null ? null : $this->access->officeOf($record->employee);
            $record->delete();
            $this->audit->execute(AuditEventType::WorkExperienceRecordDeleted, $actor, $record, $office, $before, null);
            if ($path !== null) {
                DB::afterCommit(static fn () => Storage::disk($disk)->delete($path));
            }
        });
    }

    private function invalid(string $field, string $key): ValidationException
    {
        return ValidationException::withMessages([$field => __("work_experience::messages.errors.{$key}")]);
    }
}
